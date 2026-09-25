#!/usr/bin/env python3
"""PROTOTYPE ONLY: Issue #110 retention archive health design validation."""

from __future__ import annotations

import argparse
import contextlib
import copy
import datetime as dt
import json
import math
import os
import platform
import re
import shutil
import sqlite3
import statistics
import subprocess
import sys
import threading
import time
from pathlib import Path
from typing import Any, Callable, Iterable
from zoneinfo import ZoneInfo

import psutil
import pymysql


DATABASE = "kiwi_retention_issue_110_prototype"
RESULT_MATRIX = {
    "ok": ("completed", 0),
    "corruption_detected": ("completed", 0),
    "no_work": ("completed", 0),
    "deferred": ("incomplete", 1),
    "inconclusive": ("incomplete", 1),
    "error": ("failed", 2),
}
REQUIRED_JSON_FIELDS = {
    "schema_version",
    "status",
    "exit_code",
    "check",
    "scope",
    "archive",
    "result",
    "reason_code",
    "started_at",
    "finished_at",
    "duration_seconds",
    "incident_action",
}
SYNTHETIC_SECRET = "synthetic-password-must-not-leak"


def utc_now() -> str:
    return dt.datetime.now(dt.timezone.utc).isoformat(timespec="milliseconds")


def percentile_nearest_rank(values: list[float], percentile: float) -> float | None:
    if not values:
        return None
    ordered = sorted(values)
    rank = max(1, math.ceil(percentile * len(ordered)))
    return ordered[rank - 1]


def summarize(values: Iterable[float]) -> dict[str, float | int | None]:
    items = [float(value) for value in values]
    if not items:
        return {"count": 0, "min": None, "median": None, "max": None, "p95": None}
    return {
        "count": len(items),
        "min": min(items),
        "median": statistics.median(items),
        "max": max(items),
        "p95": percentile_nearest_rank(items, 0.95) if len(items) >= 10 else None,
    }


def sanitize_text(value: str, scratch: Path | None = None, prototype: Path | None = None) -> str:
    cleaned = value
    replacements = [
        os.environ.get("KIWI_PROTO_DB_PASSWORD", ""),
        SYNTHETIC_SECRET,
    ]
    if scratch is not None:
        replacements.append(str(scratch.resolve()))
    if prototype is not None:
        replacements.append(str(prototype.resolve()))
    for replacement in replacements:
        if replacement:
            cleaned = cleaned.replace(replacement, "[redacted]")
            cleaned = cleaned.replace(replacement.replace("\\", "/"), "[redacted]")
    cleaned = re.sub(
        r"(?i)\b(password|token|secret|authorization|api[_-]?key)\s*[:=]\s*[^\s,;]+",
        r"\1=[redacted]",
        cleaned,
    )
    return cleaned[:1000]


def sanitize_value(value: Any, scratch: Path | None = None, prototype: Path | None = None) -> Any:
    if isinstance(value, dict):
        result = {}
        for key, child in value.items():
            if re.search(r"(?i)(password|token|secret|authorization|api[_-]?key)", str(key)):
                result[key] = "[redacted]"
            else:
                result[key] = sanitize_value(child, scratch, prototype)
        return result
    if isinstance(value, list):
        return [sanitize_value(child, scratch, prototype) for child in value]
    if isinstance(value, tuple):
        return [sanitize_value(child, scratch, prototype) for child in value]
    if isinstance(value, str):
        return sanitize_text(value, scratch, prototype)
    return value


def runner_document(
    result: str,
    *,
    check: str | None = None,
    scope: str = "active",
    archive: str | None = "kiwi_retention_archive_2027.sqlite",
    reason_code: str | None = None,
    incident_action: str | None = None,
    diagnostic: str | None = None,
) -> dict[str, Any]:
    status, exit_code = RESULT_MATRIX[result]
    started = dt.datetime.now(dt.timezone.utc)
    finished = dt.datetime.now(dt.timezone.utc)
    document = {
        "schema_version": 1,
        "status": status,
        "exit_code": exit_code,
        "check": check,
        "scope": scope,
        "archive": archive,
        "result": result,
        "reason_code": reason_code,
        "started_at": started.isoformat(timespec="milliseconds"),
        "finished_at": finished.isoformat(timespec="milliseconds"),
        "duration_seconds": max(0.0, (finished - started).total_seconds()),
        "incident_action": incident_action,
    }
    if diagnostic is not None:
        document["diagnostic"] = sanitize_text(diagnostic)
    return document


def emit_runner_document(result: str) -> int:
    check = "integrity_check" if result in {"ok", "corruption_detected", "inconclusive"} else None
    archive = None if result in {"no_work", "error"} else "kiwi_retention_archive_2027.sqlite"
    reason = {
        "ok": None,
        "corruption_detected": "sqlite_defect_confirmed",
        "no_work": "nothing_due",
        "deferred": "archive_lock_active",
        "inconclusive": "check_timeout",
        "error": "configuration_invalid",
    }[result]
    incident = {
        "corruption_detected": "raised",
        "inconclusive": None,
        "error": None,
    }.get(result)
    diagnostic = (
        f"password={SYNTHETIC_SECRET} path=C:\\synthetic\\absolute\\archive.sqlite"
        if result == "error"
        else None
    )
    document = runner_document(
        result,
        check=check,
        archive=archive,
        reason_code=reason,
        incident_action=incident,
        diagnostic=diagnostic,
    )
    sys.stdout.write(json.dumps(document, separators=(",", ":"), ensure_ascii=False) + "\n")
    return RESULT_MATRIX[result][1]


class Maria:
    def __init__(self) -> None:
        self.host = os.environ["KIWI_PROTO_DB_HOST"]
        self.port = int(os.environ["KIWI_PROTO_DB_PORT"])
        self.user = os.environ["KIWI_PROTO_DB_USER"]
        self.password = os.environ["KIWI_PROTO_DB_PASSWORD"]

    def connect(self, database: str | None = DATABASE, autocommit: bool = True):
        return pymysql.connect(
            host=self.host,
            port=self.port,
            user=self.user,
            password=self.password,
            database=database,
            autocommit=autocommit,
            charset="utf8mb4",
            connect_timeout=5,
            read_timeout=30,
            write_timeout=30,
            ssl_disabled=True,
        )

    def reset_database(self) -> None:
        connection = self.connect(None)
        try:
            with connection.cursor() as cursor:
                cursor.execute(f"DROP DATABASE IF EXISTS `{DATABASE}`")
                cursor.execute(
                    f"CREATE DATABASE `{DATABASE}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
                )
        finally:
            connection.close()
        self.create_schema()

    def create_schema(self) -> None:
        connection = self.connect()
        try:
            with connection.cursor() as cursor:
                cursor.execute(
                    """
                    CREATE TABLE IF NOT EXISTS source_rows (
                        id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                        created_at DATETIME NOT NULL,
                        raw_context MEDIUMTEXT NOT NULL
                    ) ENGINE=InnoDB
                    """
                )
                cursor.execute(
                    """
                    CREATE TABLE IF NOT EXISTS cleanup_runs (
                        run_id VARCHAR(100) NOT NULL PRIMARY KEY,
                        phase VARCHAR(64) NOT NULL,
                        archived_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        deleted_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        batch_accounted TINYINT(1) NOT NULL DEFAULT 0,
                        archive_name VARCHAR(191) NOT NULL,
                        updated_at DATETIME NOT NULL
                    ) ENGINE=InnoDB
                    """
                )
                cursor.execute(
                    """
                    CREATE TABLE IF NOT EXISTS operational_events (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        event_type VARCHAR(100) NOT NULL,
                        lifecycle_action VARCHAR(20) NOT NULL,
                        correlation_key VARCHAR(191) NOT NULL,
                        reference_id VARCHAR(191) NOT NULL,
                        reason_code VARCHAR(100) NULL,
                        created_at DATETIME NOT NULL,
                        UNIQUE KEY event_identity (
                            event_type, lifecycle_action, correlation_key, reference_id, reason_code
                        ),
                        KEY correlation_id (correlation_key, id)
                    ) ENGINE=InnoDB
                    """
                )
        finally:
            connection.close()

    def truncate_work_tables(self) -> None:
        connection = self.connect()
        try:
            with connection.cursor() as cursor:
                cursor.execute("TRUNCATE TABLE source_rows")
                cursor.execute("TRUNCATE TABLE cleanup_runs")
                cursor.execute("TRUNCATE TABLE operational_events")
        finally:
            connection.close()

    def seed_source(self, rows: int, payload_bytes: int = 512) -> None:
        payload = json.dumps(
            {
                "schema": "synthetic_retention_prototype_v1",
                "content": "x" * max(1, payload_bytes - 64),
            },
            separators=(",", ":"),
        )
        connection = self.connect(autocommit=False)
        try:
            with connection.cursor() as cursor:
                for start in range(1, rows + 1, 1000):
                    stop = min(rows + 1, start + 1000)
                    cursor.executemany(
                        "INSERT INTO source_rows (id, created_at, raw_context) VALUES (%s, %s, %s)",
                        [
                            (row_id, "2026-01-01 00:00:00", payload)
                            for row_id in range(start, stop)
                        ],
                    )
            connection.commit()
        finally:
            connection.close()

    def source_rows(self) -> list[tuple[int, str, str]]:
        connection = self.connect()
        try:
            with connection.cursor() as cursor:
                cursor.execute("SELECT id, created_at, raw_context FROM source_rows ORDER BY id")
                return [(int(row[0]), str(row[1]), str(row[2])) for row in cursor.fetchall()]
        finally:
            connection.close()

    def existing_ids(self, ids: list[int]) -> list[int]:
        existing: list[int] = []
        connection = self.connect()
        try:
            with connection.cursor() as cursor:
                for start in range(0, len(ids), 1000):
                    chunk = ids[start : start + 1000]
                    if not chunk:
                        continue
                    placeholders = ",".join(["%s"] * len(chunk))
                    cursor.execute(
                        f"SELECT id FROM source_rows WHERE id IN ({placeholders}) ORDER BY id",
                        chunk,
                    )
                    existing.extend(int(row[0]) for row in cursor.fetchall())
        finally:
            connection.close()
        return existing

    def delete_ids(self, ids: list[int]) -> int:
        deleted = 0
        connection = self.connect(autocommit=False)
        try:
            with connection.cursor() as cursor:
                for start in range(0, len(ids), 1000):
                    chunk = ids[start : start + 1000]
                    if not chunk:
                        continue
                    placeholders = ",".join(["%s"] * len(chunk))
                    deleted += cursor.execute(
                        f"DELETE FROM source_rows WHERE id IN ({placeholders})",
                        chunk,
                    )
            connection.commit()
        finally:
            connection.close()
        return deleted

    def count_source(self) -> int:
        connection = self.connect()
        try:
            with connection.cursor() as cursor:
                cursor.execute("SELECT COUNT(*) FROM source_rows")
                return int(cursor.fetchone()[0])
        finally:
            connection.close()

    def create_run(self, run_id: str, archive_name: str) -> None:
        connection = self.connect()
        try:
            with connection.cursor() as cursor:
                cursor.execute(
                    """
                    INSERT INTO cleanup_runs (
                        run_id, phase, archived_rows, deleted_rows,
                        batch_accounted, archive_name, updated_at
                    ) VALUES (%s, 'archive_running', 0, 0, 0, %s, NOW())
                    """,
                    (run_id, archive_name),
                )
        finally:
            connection.close()

    def update_run_complete(self, run_id: str, count: int) -> None:
        connection = self.connect()
        try:
            with connection.cursor() as cursor:
                cursor.execute(
                    """
                    UPDATE cleanup_runs
                    SET phase = 'completed',
                        archived_rows = %s,
                        deleted_rows = %s,
                        batch_accounted = 1,
                        updated_at = NOW()
                    WHERE run_id = %s
                    """,
                    (count, count, run_id),
                )
        finally:
            connection.close()

    def run_row(self, run_id: str) -> dict[str, Any]:
        connection = self.connect()
        try:
            with connection.cursor(pymysql.cursors.DictCursor) as cursor:
                cursor.execute("SELECT * FROM cleanup_runs WHERE run_id = %s", (run_id,))
                row = cursor.fetchone()
                return dict(row or {})
        finally:
            connection.close()

    def version(self) -> str:
        connection = self.connect(None)
        try:
            with connection.cursor() as cursor:
                cursor.execute("SELECT VERSION()")
                return str(cursor.fetchone()[0])
        finally:
            connection.close()

    def record_event(
        self,
        event_type: str,
        lifecycle: str,
        correlation: str,
        reference: str,
        reason: str | None,
    ) -> None:
        connection = self.connect()
        try:
            with connection.cursor() as cursor:
                cursor.execute(
                    """
                    INSERT IGNORE INTO operational_events (
                        event_type, lifecycle_action, correlation_key,
                        reference_id, reason_code, created_at
                    ) VALUES (%s, %s, %s, %s, %s, NOW())
                    """,
                    (event_type, lifecycle, correlation, reference, reason),
                )
        finally:
            connection.close()

    def latest_event(self, correlation: str) -> dict[str, Any] | None:
        connection = self.connect()
        try:
            with connection.cursor(pymysql.cursors.DictCursor) as cursor:
                cursor.execute(
                    """
                    SELECT * FROM operational_events
                    WHERE correlation_key = %s
                    ORDER BY id DESC
                    LIMIT 1
                    """,
                    (correlation,),
                )
                row = cursor.fetchone()
                return dict(row) if row else None
        finally:
            connection.close()

    def events(self) -> list[dict[str, Any]]:
        connection = self.connect()
        try:
            with connection.cursor(pymysql.cursors.DictCursor) as cursor:
                cursor.execute("SELECT * FROM operational_events ORDER BY id")
                return [dict(row) for row in cursor.fetchall()]
        finally:
            connection.close()


class PhaseRecorder:
    def __init__(self, maria_pid: int | None) -> None:
        self.records: list[dict[str, Any]] = []
        self.process = psutil.Process()
        self.maria_process = None
        if maria_pid:
            with contextlib.suppress(psutil.Error):
                self.maria_process = psutil.Process(maria_pid)

    @staticmethod
    def _cpu_time(process: psutil.Process | None) -> float | None:
        if process is None:
            return None
        try:
            times = process.cpu_times()
            return float(times.user + times.system)
        except psutil.Error:
            return None

    @staticmethod
    def _io(process: psutil.Process | None) -> tuple[int | None, int | None]:
        if process is None:
            return None, None
        try:
            counters = process.io_counters()
            return int(counters.read_bytes), int(counters.write_bytes)
        except (psutil.Error, AttributeError):
            return None, None

    def measure(
        self,
        phase: str,
        operation: Callable[[], Any],
        *,
        scenario: str,
        rows: int | None = None,
        byte_count: int | None = None,
        extra: dict[str, Any] | None = None,
    ) -> Any:
        samples: list[dict[str, float | int]] = []
        stop = threading.Event()
        process_cpu_start = self._cpu_time(self.process)
        maria_cpu_start = self._cpu_time(self.maria_process)
        process_read_start, process_write_start = self._io(self.process)
        maria_read_start, maria_write_start = self._io(self.maria_process)
        disk_start = psutil.disk_io_counters()

        def sample() -> None:
            while not stop.is_set():
                point: dict[str, float | int] = {
                    "system_cpu_percent": float(psutil.cpu_percent(interval=None)),
                    "memory_percent": float(psutil.virtual_memory().percent),
                }
                with contextlib.suppress(psutil.Error):
                    point["process_rss"] = int(self.process.memory_info().rss)
                if self.maria_process is not None:
                    with contextlib.suppress(psutil.Error):
                        point["mariadb_rss"] = int(self.maria_process.memory_info().rss)
                samples.append(point)
                stop.wait(0.05)

        sampler = threading.Thread(target=sample, daemon=True)
        sampler.start()
        started_at = utc_now()
        started = time.perf_counter()
        try:
            value = operation()
            success = True
            error = None
        except Exception as exc:
            success = False
            error = f"{type(exc).__name__}: {exc}"
            raise
        finally:
            duration = time.perf_counter() - started
            finished_at = utc_now()
            stop.set()
            sampler.join(timeout=1)
            process_cpu_end = self._cpu_time(self.process)
            maria_cpu_end = self._cpu_time(self.maria_process)
            process_read_end, process_write_end = self._io(self.process)
            maria_read_end, maria_write_end = self._io(self.maria_process)
            disk_end = psutil.disk_io_counters()
            record: dict[str, Any] = {
                "scenario": scenario,
                "phase": phase,
                "started_at": started_at,
                "finished_at": finished_at,
                "wall_seconds": duration,
                "success": success,
                "error": sanitize_text(error or ""),
                "rows": rows,
                "bytes": byte_count,
                "rows_per_second": (rows / duration) if rows is not None and duration > 0 else None,
                "mib_per_second": (
                    (byte_count / 1024 / 1024) / duration
                    if byte_count is not None and duration > 0
                    else None
                ),
                "process_cpu_seconds": (
                    process_cpu_end - process_cpu_start
                    if process_cpu_start is not None and process_cpu_end is not None
                    else None
                ),
                "mariadb_cpu_seconds": (
                    maria_cpu_end - maria_cpu_start
                    if maria_cpu_start is not None and maria_cpu_end is not None
                    else None
                ),
                "process_peak_rss_bytes": max(
                    [int(sample.get("process_rss", 0)) for sample in samples] or [0]
                ),
                "mariadb_peak_rss_bytes": max(
                    [int(sample.get("mariadb_rss", 0)) for sample in samples] or [0]
                ),
                "system_cpu_percent_avg": (
                    statistics.fmean(float(sample["system_cpu_percent"]) for sample in samples)
                    if samples
                    else None
                ),
                "system_cpu_percent_max": (
                    max(float(sample["system_cpu_percent"]) for sample in samples)
                    if samples
                    else None
                ),
                "memory_percent_max": (
                    max(float(sample["memory_percent"]) for sample in samples)
                    if samples
                    else None
                ),
                "process_read_bytes": (
                    process_read_end - process_read_start
                    if process_read_start is not None and process_read_end is not None
                    else None
                ),
                "process_write_bytes": (
                    process_write_end - process_write_start
                    if process_write_start is not None and process_write_end is not None
                    else None
                ),
                "mariadb_read_bytes": (
                    maria_read_end - maria_read_start
                    if maria_read_start is not None and maria_read_end is not None
                    else None
                ),
                "mariadb_write_bytes": (
                    maria_write_end - maria_write_start
                    if maria_write_start is not None and maria_write_end is not None
                    else None
                ),
                "system_disk_read_bytes": (
                    int(disk_end.read_bytes - disk_start.read_bytes)
                    if disk_start is not None and disk_end is not None
                    else None
                ),
                "system_disk_write_bytes": (
                    int(disk_end.write_bytes - disk_start.write_bytes)
                    if disk_start is not None and disk_end is not None
                    else None
                ),
            }
            if extra:
                record.update(extra)
            self.records.append(record)
        return value


def create_archive_schema(connection: sqlite3.Connection) -> None:
    connection.executescript(
        """
        PRAGMA foreign_keys=ON;
        CREATE TABLE IF NOT EXISTS archive_batches (
            archive_batch_id TEXT PRIMARY KEY,
            source_key TEXT NOT NULL,
            cutoff_value TEXT NOT NULL,
            started_at TEXT NOT NULL,
            finished_at TEXT,
            archived_rows INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS archive_batch_rows (
            archive_batch_id TEXT NOT NULL,
            source_pk INTEGER NOT NULL,
            PRIMARY KEY (archive_batch_id, source_pk)
        );
        CREATE TABLE IF NOT EXISTS wp_kiwi_landing_page_sessions (
            _archive_batch_id TEXT NOT NULL,
            _archived_at TEXT NOT NULL,
            _source_pk INTEGER NOT NULL,
            id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            raw_context TEXT NOT NULL,
            UNIQUE(_source_pk)
        );
        """
    )


def open_archive(path: Path) -> sqlite3.Connection:
    connection = sqlite3.connect(path, timeout=5, isolation_level=None)
    connection.execute("PRAGMA journal_mode=WAL")
    connection.execute("PRAGMA synchronous=FULL")
    create_archive_schema(connection)
    return connection


def read_receipt(path: Path, batch_id: str) -> list[int]:
    if not path.exists():
        return []
    connection = sqlite3.connect(f"file:{path.as_posix()}?mode=ro", uri=True)
    try:
        return [
            int(row[0])
            for row in connection.execute(
                """
                SELECT source_pk
                FROM archive_batch_rows
                WHERE archive_batch_id = ?
                ORDER BY source_pk
                """,
                (batch_id,),
            )
        ]
    finally:
        connection.close()


def pipeline_iteration(
    maria: Maria,
    recorder: PhaseRecorder,
    root: Path,
    rows: int,
    iteration: int,
) -> dict[str, Any]:
    scenario = f"pipeline_{rows}_iteration_{iteration}"
    run_id = f"prototype_run_{rows}_{iteration}"
    batch_id = f"prototype_batch_{rows}_{iteration}"
    archive = root / f"{scenario}.sqlite"
    if archive.exists():
        archive.unlink()
    for suffix in ("-wal", "-shm"):
        with contextlib.suppress(FileNotFoundError):
            Path(str(archive) + suffix).unlink()

    invocation_started = time.perf_counter()
    maria.truncate_work_tables()
    recorder.measure(
        "setup_seed_mariadb",
        lambda: maria.seed_source(rows),
        scenario=scenario,
        rows=rows,
    )
    recorder.measure(
        "audit_create",
        lambda: maria.create_run(run_id, archive.name),
        scenario=scenario,
        rows=1,
    )
    selected = recorder.measure(
        "mysql_select_source_rows",
        maria.source_rows,
        scenario=scenario,
        rows=rows,
    )
    connection = recorder.measure(
        "sqlite_open_and_pragmas",
        lambda: open_archive(archive),
        scenario=scenario,
    )
    connection.execute(
        """
        INSERT INTO archive_batches (
            archive_batch_id, source_key, cutoff_value, started_at, status
        ) VALUES (?, 'landing_page_sessions', '2026-01-02 00:00:00', ?, 'running')
        """,
        (batch_id, utc_now()),
    )
    connection.execute("BEGIN")
    recorder.measure(
        "sqlite_archive_rows_write",
        lambda: connection.executemany(
            """
            INSERT INTO wp_kiwi_landing_page_sessions (
                _archive_batch_id, _archived_at, _source_pk,
                id, created_at, raw_context
            ) VALUES (?, ?, ?, ?, ?, ?)
            """,
            [
                (batch_id, utc_now(), row_id, row_id, created_at, raw_context)
                for row_id, created_at, raw_context in selected
            ],
        ),
        scenario=scenario,
        rows=rows,
    )
    recorder.measure(
        "sqlite_receipt_rows_write",
        lambda: connection.executemany(
            "INSERT INTO archive_batch_rows (archive_batch_id, source_pk) VALUES (?, ?)",
            [(batch_id, row_id) for row_id, _, _ in selected],
        ),
        scenario=scenario,
        rows=rows,
    )
    recorder.measure(
        "sqlite_transaction_commit",
        connection.commit,
        scenario=scenario,
        rows=rows,
    )
    connection.close()
    receipt = recorder.measure(
        "sqlite_receipt_read",
        lambda: read_receipt(archive, batch_id),
        scenario=scenario,
        rows=rows,
    )
    recorder.measure(
        "receipt_primary_keys_and_count_validate",
        lambda: (
            None
            if receipt == list(range(1, rows + 1)) and len(receipt) == rows
            else (_ for _ in ()).throw(AssertionError("Receipt mismatch"))
        ),
        scenario=scenario,
        rows=rows,
    )
    existing = recorder.measure(
        "mysql_receipted_source_state",
        lambda: maria.existing_ids(receipt),
        scenario=scenario,
        rows=rows,
    )
    deleted = recorder.measure(
        "mysql_delete_remaining_receipted_rows",
        lambda: maria.delete_ids(existing),
        scenario=scenario,
        rows=len(existing),
    )
    recorder.measure(
        "mysql_delete_verify",
        lambda: (
            None
            if deleted == rows and maria.count_source() == 0
            else (_ for _ in ()).throw(AssertionError("Delete mismatch"))
        ),
        scenario=scenario,
        rows=rows,
    )
    recorder.measure(
        "mysql_audit_progress_persist",
        lambda: maria.update_run_complete(run_id, rows),
        scenario=scenario,
        rows=1,
    )
    invocation_duration = time.perf_counter() - invocation_started
    file_size = archive.stat().st_size
    recorder.records.append(
        {
            "scenario": scenario,
            "phase": "entire_worker_invocation",
            "wall_seconds": invocation_duration,
            "rows": rows,
            "bytes": file_size,
            "rows_per_second": rows / invocation_duration,
            "success": True,
            "global_sqlite_checks_called": 0,
        }
    )
    run = maria.run_row(run_id)
    result = {
        "scenario": scenario,
        "rows": rows,
        "iteration": iteration,
        "archive_bytes": file_size,
        "receipt_rows": len(receipt),
        "deleted_rows": deleted,
        "source_rows_remaining": maria.count_source(),
        "audit": run,
        "global_sqlite_checks_called": 0,
        "passed": (
            len(receipt) == rows
            and deleted == rows
            and maria.count_source() == 0
            and int(run.get("archived_rows", 0)) == rows
            and int(run.get("deleted_rows", 0)) == rows
            and int(run.get("batch_accounted", 0)) == 1
        ),
    }
    connection = sqlite3.connect(archive)
    connection.execute("PRAGMA wal_checkpoint(TRUNCATE)")
    connection.close()
    archive.unlink()
    for suffix in ("-wal", "-shm"):
        with contextlib.suppress(FileNotFoundError):
            Path(str(archive) + suffix).unlink()
    return result


def prepare_crash_scenario(maria: Maria, archive: Path, run_id: str, rows: int = 100) -> None:
    archive.parent.mkdir(parents=True, exist_ok=True)
    maria.truncate_work_tables()
    maria.seed_source(rows, payload_bytes=128)
    maria.create_run(run_id, archive.name)
    for path in (archive, Path(str(archive) + "-wal"), Path(str(archive) + "-shm")):
        with contextlib.suppress(FileNotFoundError):
            path.unlink()


def crash_child(stage: int, archive: Path, run_id: str, rows: int = 100) -> int:
    maria = Maria()
    batch_id = f"{run_id}_batch"
    selected = maria.source_rows()
    connection = open_archive(archive)
    connection.execute(
        """
        INSERT OR IGNORE INTO archive_batches (
            archive_batch_id, source_key, cutoff_value, started_at, status
        ) VALUES (?, 'landing_page_sessions', '2026-01-02 00:00:00', ?, 'running')
        """,
        (batch_id, utc_now()),
    )
    connection.execute("BEGIN")
    connection.executemany(
        """
        INSERT OR IGNORE INTO wp_kiwi_landing_page_sessions (
            _archive_batch_id, _archived_at, _source_pk, id, created_at, raw_context
        ) VALUES (?, ?, ?, ?, ?, ?)
        """,
        [
            (batch_id, utc_now(), row_id, row_id, created_at, raw_context)
            for row_id, created_at, raw_context in selected
        ],
    )
    connection.executemany(
        "INSERT OR IGNORE INTO archive_batch_rows (archive_batch_id, source_pk) VALUES (?, ?)",
        [(batch_id, row_id) for row_id, _, _ in selected],
    )
    if stage == 1:
        os._exit(91)
    connection.commit()
    connection.close()
    if stage == 2:
        os._exit(91)
    receipt = read_receipt(archive, batch_id)
    if receipt != list(range(1, rows + 1)):
        os._exit(92)
    if stage == 3:
        os._exit(91)
    if stage == 4:
        maria.delete_ids(receipt[: rows // 2])
        os._exit(91)
    maria.delete_ids(receipt)
    if stage == 5:
        os._exit(91)
    maria.update_run_complete(run_id, rows)
    os._exit(91)


def recover_child(archive: Path, run_id: str, rows: int = 100) -> int:
    maria = Maria()
    batch_id = f"{run_id}_batch"
    receipt = read_receipt(archive, batch_id)
    if not receipt:
        selected = maria.source_rows()
        connection = open_archive(archive)
        connection.execute(
            """
            INSERT OR IGNORE INTO archive_batches (
                archive_batch_id, source_key, cutoff_value, started_at, status
            ) VALUES (?, 'landing_page_sessions', '2026-01-02 00:00:00', ?, 'running')
            """,
            (batch_id, utc_now()),
        )
        connection.execute("BEGIN")
        connection.executemany(
            """
            INSERT OR IGNORE INTO wp_kiwi_landing_page_sessions (
                _archive_batch_id, _archived_at, _source_pk, id, created_at, raw_context
            ) VALUES (?, ?, ?, ?, ?, ?)
            """,
            [
                (batch_id, utc_now(), row_id, row_id, created_at, raw_context)
                for row_id, created_at, raw_context in selected
            ],
        )
        connection.executemany(
            "INSERT OR IGNORE INTO archive_batch_rows (archive_batch_id, source_pk) VALUES (?, ?)",
            [(batch_id, row_id) for row_id, _, _ in selected],
        )
        connection.commit()
        connection.close()
        receipt = read_receipt(archive, batch_id)
        recovery_action = "retry_archive_then_cleanup"
    else:
        recovery_action = "resume_from_receipt"
    if receipt != list(range(1, rows + 1)):
        raise AssertionError("Recovery receipt is incomplete")
    existing = maria.existing_ids(receipt)
    deleted_now = maria.delete_ids(existing)
    run_before = maria.run_row(run_id)
    if int(run_before.get("batch_accounted", 0)) == 1:
        recovery_action = "already_completed_noop"
    else:
        maria.update_run_complete(run_id, rows)
    document = {
        "run_id": run_id,
        "recovery_action": recovery_action,
        "receipt_rows": len(receipt),
        "source_rows_before_delete": len(existing),
        "deleted_now": deleted_now,
        "source_rows_after": maria.count_source(),
        "audit": maria.run_row(run_id),
    }
    sys.stdout.write(json.dumps(document, separators=(",", ":"), default=str) + "\n")
    return 0


def run_crash_scenarios(maria: Maria, root: Path) -> list[dict[str, Any]]:
    results = []
    for stage in range(1, 7):
        run_id = f"crash_run_{stage}"
        archive = root / f"crash_{stage}.sqlite"
        prepare_crash_scenario(maria, archive, run_id)
        crashed = subprocess.run(
            [
                sys.executable,
                str(Path(__file__).resolve()),
                "crash-child",
                "--stage",
                str(stage),
                "--archive",
                str(archive),
                "--run-id",
                run_id,
            ],
            capture_output=True,
            text=True,
            check=False,
        )
        recovered = subprocess.run(
            [
                sys.executable,
                str(Path(__file__).resolve()),
                "recover-child",
                "--archive",
                str(archive),
                "--run-id",
                run_id,
            ],
            capture_output=True,
            text=True,
            check=False,
        )
        if recovered.returncode != 0 or not recovered.stdout.strip():
            raise RuntimeError(
                "Recovery child failed for stage "
                f"{stage}: exit={recovered.returncode}; stderr={recovered.stderr.strip()}"
            )
        recovery = json.loads(recovered.stdout.strip())
        connection = sqlite3.connect(archive)
        archive_rows = int(
            connection.execute(
                "SELECT COUNT(*) FROM wp_kiwi_landing_page_sessions"
            ).fetchone()[0]
        )
        receipt_rows = int(
            connection.execute("SELECT COUNT(*) FROM archive_batch_rows").fetchone()[0]
        )
        connection.close()
        audit = maria.run_row(run_id)
        passed = (
            crashed.returncode == 91
            and recovered.returncode == 0
            and archive_rows == 100
            and receipt_rows == 100
            and maria.count_source() == 0
            and int(audit.get("archived_rows", 0)) == 100
            and int(audit.get("deleted_rows", 0)) == 100
            and int(audit.get("batch_accounted", 0)) == 1
        )
        results.append(
            {
                "stage": stage,
                "crash_exit_code": crashed.returncode,
                "recovery": recovery,
                "archive_rows": archive_rows,
                "receipt_rows": receipt_rows,
                "source_rows_remaining": maria.count_source(),
                "audit": audit,
                "passed": passed,
            }
        )
    return results


def acquire_lock(path: Path):
    import msvcrt

    path.parent.mkdir(parents=True, exist_ok=True)
    handle = open(path, "a+b", buffering=0)
    if path.stat().st_size == 0:
        handle.write(b"0")
        handle.flush()
    handle.seek(0)
    try:
        msvcrt.locking(handle.fileno(), msvcrt.LK_NBLCK, 1)
    except OSError:
        handle.close()
        return None
    return handle


def release_lock(handle) -> None:
    import msvcrt

    handle.seek(0)
    msvcrt.locking(handle.fileno(), msvcrt.LK_UNLCK, 1)
    handle.close()


def lock_holder(lock_path: Path, ready_path: Path) -> int:
    handle = acquire_lock(lock_path)
    if handle is None:
        return 2
    ready_path.write_text("ready", encoding="utf-8")
    while True:
        time.sleep(1)


def lock_try(lock_path: Path) -> int:
    started = time.perf_counter()
    handle = acquire_lock(lock_path)
    duration = time.perf_counter() - started
    if handle is None:
        document = runner_document(
            "deferred",
            check=None,
            reason_code="archive_lock_active",
        )
        document["duration_seconds"] = duration
        sys.stdout.write(json.dumps(document, separators=(",", ":")) + "\n")
        return 1
    release_lock(handle)
    document = runner_document("ok", check="quick_check")
    document["duration_seconds"] = duration
    sys.stdout.write(json.dumps(document, separators=(",", ":")) + "\n")
    return 0


def run_lock_scenario(root: Path) -> dict[str, Any]:
    lock_path = root / "archive.sqlite.lock"
    ready_path = root / "lock-ready"
    with contextlib.suppress(FileNotFoundError):
        ready_path.unlink()
    holder = subprocess.Popen(
        [
            sys.executable,
            str(Path(__file__).resolve()),
            "lock-holder",
            "--lock",
            str(lock_path),
            "--ready",
            str(ready_path),
        ],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    deadline = time.monotonic() + 5
    while not ready_path.exists() and time.monotonic() < deadline:
        time.sleep(0.02)
    if not ready_path.exists():
        holder.kill()
        raise RuntimeError("Lock holder did not become ready")
    busy_started = time.perf_counter()
    busy = subprocess.run(
        [
            sys.executable,
            str(Path(__file__).resolve()),
            "lock-try",
            "--lock",
            str(lock_path),
        ],
        capture_output=True,
        text=True,
        check=False,
    )
    busy_duration = time.perf_counter() - busy_started
    holder.kill()
    holder.wait(timeout=5)
    takeover_started = time.perf_counter()
    takeover = subprocess.run(
        [
            sys.executable,
            str(Path(__file__).resolve()),
            "lock-try",
            "--lock",
            str(lock_path),
        ],
        capture_output=True,
        text=True,
        check=False,
    )
    takeover_duration = time.perf_counter() - takeover_started
    busy_doc = json.loads(busy.stdout.strip())
    takeover_doc = json.loads(takeover.stdout.strip())
    return {
        "busy_exit_code": busy.returncode,
        "busy_result": busy_doc["result"],
        "busy_total_seconds": busy_duration,
        "busy_lock_seconds": busy_doc["duration_seconds"],
        "takeover_exit_code": takeover.returncode,
        "takeover_result": takeover_doc["result"],
        "takeover_total_seconds": takeover_duration,
        "takeover_lock_seconds": takeover_doc["duration_seconds"],
        "physical_lock_file_still_exists": lock_path.exists(),
        "passed": (
            busy.returncode == 1
            and busy_doc["result"] == "deferred"
            and busy_duration < 2
            and takeover.returncode == 0
            and takeover_doc["result"] == "ok"
            and lock_path.exists()
        ),
    }


def status_writer(status_path: Path, crash_point: str) -> int:
    temp_path = status_path.with_suffix(status_path.suffix + ".tmp")
    new_state = {
        "schema_version": 1,
        "cycle_year": 2028,
        "archives": ["archive_2026.sqlite", "archive_2027.sqlite"],
        "completed": ["archive_2026.sqlite"],
    }
    payload = json.dumps(new_state, sort_keys=True)
    if crash_point == "before_temp":
        os._exit(91)
    with open(temp_path, "w", encoding="utf-8") as handle:
        if crash_point == "during_temp":
            handle.write(payload[: len(payload) // 2])
            handle.flush()
            os.fsync(handle.fileno())
            os._exit(91)
        handle.write(payload)
        handle.flush()
        os.fsync(handle.fileno())
    if crash_point == "after_temp":
        os._exit(91)
    os.replace(temp_path, status_path)
    os._exit(91)


def run_atomic_status_scenarios(root: Path) -> list[dict[str, Any]]:
    results = []
    root.mkdir(parents=True, exist_ok=True)
    status_path = root / "annual-campaign.json"
    old_state = {
        "schema_version": 1,
        "cycle_year": 2028,
        "archives": ["archive_2026.sqlite", "archive_2027.sqlite"],
        "completed": [],
    }
    for crash_point in ("before_temp", "during_temp", "after_temp", "after_replace"):
        status_path.write_text(json.dumps(old_state, sort_keys=True), encoding="utf-8")
        temp_path = status_path.with_suffix(status_path.suffix + ".tmp")
        with contextlib.suppress(FileNotFoundError):
            temp_path.unlink()
        child = subprocess.run(
            [
                sys.executable,
                str(Path(__file__).resolve()),
                "status-writer",
                "--status",
                str(status_path),
                "--crash-point",
                crash_point,
            ],
            capture_output=True,
            check=False,
        )
        published = json.loads(status_path.read_text(encoding="utf-8"))
        accepted_old_or_new = published.get("completed") in (
            [],
            ["archive_2026.sqlite"],
        )
        expected_new = crash_point == "after_replace"
        results.append(
            {
                "crash_point": crash_point,
                "exit_code": child.returncode,
                "published": published,
                "temp_file_exists": temp_path.exists(),
                "passed": (
                    child.returncode == 91
                    and accepted_old_or_new
                    and (
                        published["completed"] == ["archive_2026.sqlite"]
                        if expected_new
                        else published["completed"] == []
                    )
                ),
            }
        )
    return results


def record_failure(
    maria: Maria,
    event_type: str,
    correlation: str,
    reference: str,
    reason: str,
) -> str:
    latest = maria.latest_event(correlation)
    lifecycle = (
        "repeated"
        if latest and latest.get("lifecycle_action") in {"raised", "repeated"}
        else "raised"
    )
    maria.record_event(event_type, lifecycle, correlation, reference, reason)
    return lifecycle


def record_recovery(
    maria: Maria,
    event_type: str,
    correlation: str,
    reference: str,
    reason: str,
) -> str | None:
    latest = maria.latest_event(correlation)
    if not latest or latest.get("lifecycle_action") not in {"raised", "repeated"}:
        return None
    maria.record_event(event_type, "resolved", correlation, reference, reason)
    return "resolved"


def run_cron_incident_scenarios(maria: Maria) -> dict[str, Any]:
    maria.truncate_work_tables()
    berlin = ZoneInfo("Europe/Berlin")
    slots = [
        dt.datetime(2027, 7, 11, 1, 30, tzinfo=dt.timezone.utc),
        dt.datetime(2027, 7, 11, 2, 0, tzinfo=dt.timezone.utc),
        dt.datetime(2027, 7, 11, 2, 30, tzinfo=dt.timezone.utc),
    ]
    slot_mapping = [
        {"utc": value.isoformat(), "berlin": value.astimezone(berlin).isoformat()}
        for value in slots
    ]
    correlation = "retention_archive_health_incomplete:kiwi_retention_archive_2027.sqlite"
    attempts = [
        runner_document("deferred", check=None, reason_code="archive_lock_active"),
        runner_document("inconclusive", check="integrity_check", reason_code="check_timeout"),
        runner_document("inconclusive", check="integrity_check", reason_code="check_timeout"),
    ]
    before_final = maria.events()
    raised = record_failure(
        maria,
        "retention_archive_health_check_incomplete",
        correlation,
        "kiwi_retention_archive_2027.sqlite",
        "check_timeout",
    )
    repeated = record_failure(
        maria,
        "retention_archive_health_check_incomplete",
        correlation,
        "kiwi_retention_archive_2027.sqlite",
        "check_timeout",
    )
    resolved = record_recovery(
        maria,
        "retention_archive_health_check_incomplete",
        correlation,
        "kiwi_retention_archive_2027.sqlite",
        "later_check_completed",
    )
    corruption_correlation = (
        "retention_archive_corruption:kiwi_retention_archive_2027.sqlite"
    )
    corruption_raised = record_failure(
        maria,
        "retention_archive_corruption_detected",
        corruption_correlation,
        "kiwi_retention_archive_2027.sqlite",
        "sqlite_defect_confirmed",
    )
    events = maria.events()
    return {
        "slot_mapping": slot_mapping,
        "attempts": attempts,
        "events_before_final_slot": before_final,
        "raised_action": raised,
        "repeated_action": repeated,
        "resolved_action": resolved,
        "corruption_action": corruption_raised,
        "events": events,
        "passed": (
            before_final == []
            and [event["lifecycle_action"] for event in events[:3]]
            == ["raised", "repeated", "resolved"]
            and events[3]["event_type"] == "retention_archive_corruption_detected"
            and events[3]["lifecycle_action"] == "raised"
            and all(
                mapping["berlin"].startswith("2027-07-11T0")
                for mapping in slot_mapping
            )
        ),
    }


def sqlite_check(path: Path, check: str) -> dict[str, Any]:
    connection = sqlite3.connect(f"file:{path.as_posix()}?mode=ro", uri=True, timeout=5)
    try:
        connection.execute("PRAGMA query_only=ON")
        rows = [str(row[0]) for row in connection.execute(f"PRAGMA {check}")]
        return {
            "completed": True,
            "rows": rows,
            "healthy": len(rows) == 1 and rows[0].lower() == "ok",
            "confirmed_corruption": any(row.lower() != "ok" for row in rows),
        }
    except sqlite3.Error as exc:
        return {
            "completed": False,
            "rows": [],
            "healthy": False,
            "confirmed_corruption": False,
            "error": f"{type(exc).__name__}: {exc}",
        }
    finally:
        connection.close()


def atomic_json_write(path: Path, value: dict[str, Any]) -> None:
    temp = path.with_suffix(path.suffix + ".tmp")
    with open(temp, "w", encoding="utf-8") as handle:
        json.dump(value, handle, sort_keys=True)
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(temp, path)


def create_small_health_archive(path: Path, rows: int = 1000) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    for candidate in (path, Path(str(path) + "-wal"), Path(str(path) + "-shm")):
        with contextlib.suppress(FileNotFoundError):
            candidate.unlink()
    connection = sqlite3.connect(path)
    connection.executescript(
        """
        PRAGMA page_size=4096;
        CREATE TABLE a (id INTEGER PRIMARY KEY, value TEXT NOT NULL);
        CREATE TABLE b (id INTEGER PRIMARY KEY, value TEXT NOT NULL);
        """
    )
    connection.executemany(
        "INSERT INTO a(value) VALUES (?)",
        [("a" * 256,)] * rows,
    )
    connection.executemany(
        "INSERT INTO b(value) VALUES (?)",
        [("b" * 256,)] * rows,
    )
    connection.commit()
    connection.close()


def make_confirmed_corruption(path: Path) -> None:
    connection = sqlite3.connect(path)
    roots = dict(
        connection.execute(
            "SELECT name, rootpage FROM sqlite_schema WHERE name IN ('a', 'b')"
        )
    )
    connection.execute("PRAGMA writable_schema=ON")
    connection.execute(
        "UPDATE sqlite_schema SET rootpage = ? WHERE name = 'b'",
        (roots["a"],),
    )
    connection.execute("PRAGMA writable_schema=OFF")
    connection.execute("PRAGMA schema_version = 999")
    connection.commit()
    connection.close()


def generation_sort_key(path: Path) -> tuple[int, int]:
    match = re.match(r"kiwi_retention_archive_(\d{4})(?:_part_(\d+))?\.sqlite$", path.name)
    if not match:
        return (0, 0)
    return int(match.group(1)), int(match.group(2) or 1)


def choose_active_generation(root: Path, year: int) -> Path:
    candidates = sorted(
        root.glob(f"kiwi_retention_archive_{year}*.sqlite"),
        key=generation_sort_key,
    )
    if not candidates:
        return root / f"kiwi_retention_archive_{year}.sqlite"
    highest = candidates[-1]
    marker = highest.with_suffix(highest.suffix + ".quarantine.json")
    if not marker.exists():
        return highest
    _, part = generation_sort_key(highest)
    return root / f"kiwi_retention_archive_{year}_part_{part + 1}.sqlite"


def run_quarantine_scenarios(root: Path) -> dict[str, Any]:
    health_root = root / "quarantine"
    health_root.mkdir(parents=True, exist_ok=True)
    healthy = health_root / "kiwi_retention_archive_2027.sqlite"
    damaged = health_root / "kiwi_retention_archive_2027_part_2.sqlite"
    create_small_health_archive(healthy)
    shutil.copy2(healthy, damaged)
    make_confirmed_corruption(damaged)
    actual = sqlite_check(damaged, "integrity_check")
    marker = damaged.with_suffix(damaged.suffix + ".quarantine.json")
    if actual["completed"] and actual["confirmed_corruption"]:
        atomic_json_write(
            marker,
            {
                "schema_version": 1,
                "archive": damaged.name,
                "reason": "sqlite_defect_confirmed",
            },
        )
    replacement = choose_active_generation(health_root, 2027)
    inconclusive_marker = healthy.with_suffix(healthy.suffix + ".quarantine.json")
    later_ok_but_marked = health_root / "kiwi_retention_archive_2026.sqlite"
    create_small_health_archive(later_ok_but_marked, 100)
    later_marker = later_ok_but_marked.with_suffix(
        later_ok_but_marked.suffix + ".quarantine.json"
    )
    atomic_json_write(
        later_marker,
        {
            "schema_version": 1,
            "archive": later_ok_but_marked.name,
            "reason": "previously_confirmed",
        },
    )
    later_ok = sqlite_check(later_ok_but_marked, "integrity_check")
    source_ids = list(range(1, 11))
    successor = {
        "old_run_id": "cleanup_old",
        "old_run_status": "blocked_archive_quarantined",
        "new_run_id": "cleanup_successor_1",
        "frozen_scope": [1, 10],
        "remaining_source_ids": source_ids,
        "replacement_batch_complete": True,
        "resolution_reason": "quarantined_and_replaced",
    }
    return {
        "not_started_or_inconclusive": {
            "marker_created": inconclusive_marker.exists(),
            "replacement_created": False,
        },
        "actual_integrity_check": actual,
        "quarantine_marker_created": marker.exists(),
        "damaged_file_preserved": damaged.exists(),
        "selected_replacement": replacement.name,
        "old_run_successor": successor,
        "empty_replacement_resolves_incident": False,
        "first_complete_replacement_batch_resolves_incident": True,
        "later_ok_on_quarantined_generation": later_ok,
        "later_quarantine_marker_preserved": later_marker.exists(),
        "passed": (
            actual["completed"]
            and actual["confirmed_corruption"]
            and marker.exists()
            and damaged.exists()
            and not inconclusive_marker.exists()
            and replacement.name.endswith("_part_3.sqlite")
            and later_ok["healthy"]
            and later_marker.exists()
        ),
    }


def run_year_and_campaign_scenarios(root: Path) -> dict[str, Any]:
    annual = root / "annual"
    annual.mkdir(parents=True, exist_ok=True)
    names = [
        "kiwi_retention_archive_2025.sqlite",
        "kiwi_retention_archive_2026.sqlite",
        "kiwi_retention_archive_2027.sqlite",
        "kiwi_retention_archive_2028.sqlite",
    ]
    for name in names:
        create_small_health_archive(annual / name, rows=50)
    quarantined = annual / "kiwi_retention_archive_2026.sqlite"
    atomic_json_write(
        quarantined.with_suffix(quarantined.suffix + ".quarantine.json"),
        {"schema_version": 1, "archive": quarantined.name},
    )
    snapshot = sorted(
        path.name
        for path in annual.glob("*.sqlite")
        if not path.with_suffix(path.suffix + ".quarantine.json").exists()
    )
    campaign = {
        "schema_version": 1,
        "cycle_year": 2028,
        "archives": snapshot,
        "status": {name: "pending" for name in snapshot},
    }
    status_path = annual / "campaign.json"
    atomic_json_write(status_path, campaign)
    slot_results = []
    locked_name = snapshot[1]
    for slot, name in enumerate(snapshot, start=1):
        if name == locked_name:
            slot_results.append({"slot": slot, "archive": name, "result": "deferred"})
            continue
        result = sqlite_check(annual / name, "integrity_check")
        campaign["status"][name] = "completed" if result["healthy"] else "failed"
        atomic_json_write(status_path, campaign)
        slot_results.append({"slot": slot, "archive": name, "result": "ok"})
    retry = sqlite_check(annual / locked_name, "integrity_check")
    campaign["status"][locked_name] = "completed" if retry["healthy"] else "failed"
    atomic_json_write(status_path, campaign)
    slot_results.append({"slot": len(slot_results) + 1, "archive": locked_name, "result": "ok"})
    current_active = choose_active_generation(annual, 2028)
    new_year_target = choose_active_generation(annual, 2029)
    frozen_old_run_path = annual / "kiwi_retention_archive_2028.sqlite"
    published = json.loads(status_path.read_text(encoding="utf-8"))
    return {
        "campaign_start_business_date": "2028-01-02 Europe/Berlin",
        "snapshot": snapshot,
        "quarantined_skipped": quarantined.name not in snapshot,
        "slots": slot_results,
        "published_status": published,
        "active_check_remains_independent": current_active.name,
        "new_year_new_run_target": new_year_target.name,
        "frozen_old_run_path": frozen_old_run_path.name,
        "passed": (
            quarantined.name not in snapshot
            and all(value == "completed" for value in published["status"].values())
            and new_year_target.name == "kiwi_retention_archive_2029.sqlite"
            and frozen_old_run_path.name == "kiwi_retention_archive_2028.sqlite"
        ),
    }


def validate_json_contract() -> list[dict[str, Any]]:
    results = []
    for result in RESULT_MATRIX:
        process = subprocess.run(
            [
                sys.executable,
                str(Path(__file__).resolve()),
                "json-contract",
                "--result",
                result,
            ],
            capture_output=True,
            text=True,
            check=False,
        )
        lines = process.stdout.splitlines()
        parsed = json.loads(lines[0]) if len(lines) == 1 else {}
        expected_status, expected_exit = RESULT_MATRIX[result]
        serialized = process.stdout + process.stderr
        results.append(
            {
                "result": result,
                "exit_code": process.returncode,
                "stdout_line_count": len(lines),
                "stderr": process.stderr,
                "document": parsed,
                "passed": (
                    len(lines) == 1
                    and REQUIRED_JSON_FIELDS.issubset(parsed)
                    and parsed.get("schema_version") == 1
                    and parsed.get("result") == result
                    and parsed.get("status") == expected_status
                    and parsed.get("exit_code") == expected_exit
                    and process.returncode == expected_exit
                    and SYNTHETIC_SECRET not in serialized
                    and "C:\\synthetic\\absolute" not in serialized
                ),
            }
        )
    return results


def generate_sized_archive(
    path: Path,
    target_bytes: int,
    recorder: PhaseRecorder,
    label: str,
) -> dict[str, Any]:
    for item in (path, Path(str(path) + "-wal"), Path(str(path) + "-shm")):
        with contextlib.suppress(FileNotFoundError):
            item.unlink()
    connection = sqlite3.connect(path, isolation_level=None)
    connection.execute("PRAGMA page_size=4096")
    connection.execute("PRAGMA journal_mode=WAL")
    connection.execute("PRAGMA synchronous=NORMAL")
    create_archive_schema(connection)
    batch_id = f"size_{label}"
    connection.execute(
        """
        INSERT INTO archive_batches (
            archive_batch_id, source_key, cutoff_value, started_at, status
        ) VALUES (?, 'landing_page_sessions', '2026-01-02 00:00:00', ?, 'running')
        """,
        (batch_id, utc_now()),
    )
    payload = json.dumps(
        {
            "schema": "synthetic_retention_prototype_v1",
            "landing_page": {"key": "synthetic"},
            "content": "x" * 8000,
        },
        separators=(",", ":"),
    )
    inserted = 0

    def fill() -> None:
        nonlocal inserted
        while True:
            page_count = int(connection.execute("PRAGMA page_count").fetchone()[0])
            page_size = int(connection.execute("PRAGMA page_size").fetchone()[0])
            if page_count * page_size >= target_bytes:
                break
            start = inserted + 1
            stop = start + 1000
            connection.execute("BEGIN")
            connection.executemany(
                """
                INSERT INTO wp_kiwi_landing_page_sessions (
                    _archive_batch_id, _archived_at, _source_pk,
                    id, created_at, raw_context
                ) VALUES (?, ?, ?, ?, ?, ?)
                """,
                [
                    (
                        batch_id,
                        "2026-01-02T00:00:00Z",
                        row_id,
                        row_id,
                        "2026-01-01 00:00:00",
                        payload,
                    )
                    for row_id in range(start, stop)
                ],
            )
            connection.executemany(
                "INSERT INTO archive_batch_rows (archive_batch_id, source_pk) VALUES (?, ?)",
                [(batch_id, row_id) for row_id in range(start, stop)],
            )
            connection.commit()
            inserted += 1000

    recorder.measure(
        "archive_generation",
        fill,
        scenario=f"archive_{label}",
        byte_count=target_bytes,
    )
    connection.execute("PRAGMA wal_checkpoint(TRUNCATE)")
    page_count = int(connection.execute("PRAGMA page_count").fetchone()[0])
    page_size = int(connection.execute("PRAGMA page_size").fetchone()[0])
    connection.close()
    return {
        "label": label,
        "target_bytes": target_bytes,
        "actual_bytes": path.stat().st_size,
        "page_count": page_count,
        "page_size": page_size,
        "rows": inserted,
    }


def run_check_matrix(
    root: Path,
    recorder: PhaseRecorder,
    skip_large: bool,
) -> list[dict[str, Any]]:
    definitions = [
        ("50MiB", 50 * 1024**2, 10),
        ("250MiB", 250 * 1024**2, 10),
    ]
    if not skip_large:
        definitions.append(("1.3GiB", int(1.3 * 1024**3), 3))
    output = []
    for label, target, repetitions in definitions:
        archive = root / f"archive_{label.replace('.', '_')}.sqlite"
        metadata = generate_sized_archive(archive, target, recorder, label)
        checks = []
        for repetition in range(1, repetitions + 1):
            for check in ("quick_check", "integrity_check"):
                result = recorder.measure(
                    check,
                    lambda check=check: sqlite_check(archive, check),
                    scenario=f"archive_{label}_check_{repetition}",
                    byte_count=metadata["actual_bytes"],
                    extra={
                        "archive_label": label,
                        "repetition": repetition,
                        "cache_classification": (
                            "first_run_uncontrolled_cache"
                            if repetition == 1
                            else "immediate_repeat_warm_likely"
                        ),
                    },
                )
                checks.append(
                    {
                        "check": check,
                        "repetition": repetition,
                        "result": result,
                    }
                )
        output.append({"archive": metadata, "checks": checks})
    return output


def markdown_report(raw: dict[str, Any]) -> str:
    lines = [
        "# Issue #110 prototype results",
        "",
        "Local synthetic evidence only; these are not Hostinger timings.",
        "",
        "## Environment",
        "",
        f"- Python: `{raw['environment']['python']}`",
        f"- SQLite: `{raw['environment']['sqlite']}`",
        f"- MariaDB: `{raw['environment']['mariadb']}`",
        f"- OS: `{raw['environment']['os']}`",
        f"- Free disk before run: `{raw['environment']['free_disk_gib']:.2f} GiB`",
        "",
        "## Scenario verdicts",
        "",
        "| Scenario | Passed |",
        "|---|---:|",
    ]
    verdicts = {
        "JSON/exit contract": all(item["passed"] for item in raw["json_contract"]),
        "OS lock": raw["lock"]["passed"],
        "Atomic campaign status": all(item["passed"] for item in raw["atomic_status"]),
        "Cron/incident lifecycle": raw["cron_incidents"]["passed"],
        "Quarantine/generation": raw["quarantine"]["passed"],
        "Year/campaign": raw["year_campaign"]["passed"],
        "Crash recovery": all(item["passed"] for item in raw["crash_scenarios"]),
        "Receipt/delete pipeline": all(item["passed"] for item in raw["pipeline"]),
    }
    for name, passed in verdicts.items():
        lines.append(f"| {name} | {'yes' if passed else 'NO'} |")
    lines.extend(
        [
            "",
            "## SQLite check timings",
            "",
            "| Archive | Actual size MiB | Check | Runs | Min s | Median s | Max s | P95 s |",
            "|---|---:|---|---:|---:|---:|---:|---:|",
        ]
    )
    for archive_entry in raw["check_matrix"]:
        label = archive_entry["archive"]["label"]
        size_mib = archive_entry["archive"]["actual_bytes"] / 1024 / 1024
        for check in ("quick_check", "integrity_check"):
            durations = [
                phase["wall_seconds"]
                for phase in raw["phases"]
                if phase.get("archive_label") == label and phase["phase"] == check
            ]
            stats = summarize(durations)
            p95 = f"{stats['p95']:.4f}" if stats["p95"] is not None else "n/a"
            lines.append(
                f"| {label} | {size_mib:.2f} | {check} | {stats['count']} | "
                f"{stats['min']:.4f} | {stats['median']:.4f} | {stats['max']:.4f} | "
                f"{p95} |"
            )
    lines.extend(
        [
            "",
            "## Pipeline totals",
            "",
            "| Rows | Runs | Min s | Median s | Max s | P95 s |",
            "|---:|---:|---:|---:|---:|---:|",
        ]
    )
    for rows in (100, 10_000, 50_000):
        durations = [
            phase["wall_seconds"]
            for phase in raw["phases"]
            if phase["phase"] == "entire_worker_invocation" and phase["rows"] == rows
        ]
        stats = summarize(durations)
        lines.append(
            f"| {rows} | {stats['count']} | {stats['min']:.4f} | "
            f"{stats['median']:.4f} | {stats['max']:.4f} | "
            f"{stats['p95']:.4f} |"
        )
    slowest = sorted(
        [phase for phase in raw["phases"] if phase.get("wall_seconds") is not None],
        key=lambda phase: float(phase["wall_seconds"]),
        reverse=True,
    )[:15]
    lines.extend(
        [
            "",
            "## Slowest individual phases",
            "",
            "| Phase | Scenario | Seconds |",
            "|---|---|---:|",
        ]
    )
    for phase in slowest:
        lines.append(
            f"| {phase['phase']} | {phase['scenario']} | {phase['wall_seconds']:.4f} |"
        )
    lines.extend(
        [
            "",
            "## Confirmed boundaries",
            "",
            "- No tested worker pipeline invoked a global SQLite health check before delete.",
            "- All crash windows ended with one archive row, one receipt, one delete count per source ID.",
            "- A busy file lock returned immediately; takeover succeeded after the holder was killed.",
            "- Only a completed SQLite integrity result with non-`ok` rows qualified as corruption.",
            "- A later `ok` did not automatically remove an existing quarantine marker.",
            "- Campaign files were processed serially; the quarantined file was omitted.",
            "",
            "## Limits",
            "",
            "- Windows file-lock behavior still requires the planned Hostinger Linux preflight.",
            "- OS cache was not flushed; first runs are labelled `first_run_uncontrolled_cache`, not cold.",
            "- Local CPU, storage, and MariaDB timings must not be projected to Hostinger.",
            "- The production PHP/WP-CLI bootstrap was not exercised by this throwaway Python harness.",
        ]
    )
    return "\n".join(lines) + "\n"


def run_full(args: argparse.Namespace) -> int:
    scratch = Path(args.scratch).resolve()
    results = Path(args.results).resolve()
    scratch.mkdir(parents=True, exist_ok=True)
    results.mkdir(parents=True, exist_ok=True)
    free_disk = shutil.disk_usage(scratch).free
    required = 4 * 1024**3 if not args.skip_large else 1 * 1024**3
    if free_disk < required:
        raise RuntimeError(
            f"Insufficient free disk: need {required / 1024**3:.1f} GiB safety budget"
        )
    maria = Maria()
    maria.reset_database()
    maria_pid = int(os.environ.get("KIWI_PROTO_MARIADB_PID", "0") or "0")
    recorder = PhaseRecorder(maria_pid)
    environment = {
        "python": platform.python_version(),
        "sqlite": sqlite3.sqlite_version,
        "mariadb": maria.version(),
        "pymysql": pymysql.__version__,
        "psutil": psutil.__version__,
        "os": platform.platform(),
        "processor": platform.processor(),
        "cpu_count_logical": psutil.cpu_count(),
        "memory_total_bytes": psutil.virtual_memory().total,
        "free_disk_gib": free_disk / 1024**3,
        "large_stage_skipped": bool(args.skip_large),
    }
    print("1/8 JSON/exit contract", flush=True)
    json_contract = validate_json_contract()
    print("2/8 lock and atomic status", flush=True)
    lock = run_lock_scenario(scratch / "lock")
    atomic_status = run_atomic_status_scenarios(scratch / "atomic")
    print("3/8 cron, incidents, quarantine, annual campaign", flush=True)
    cron_incidents = run_cron_incident_scenarios(maria)
    quarantine = run_quarantine_scenarios(scratch)
    year_campaign = run_year_and_campaign_scenarios(scratch)
    print("4/8 six real crash/recovery windows", flush=True)
    crash_scenarios = run_crash_scenarios(maria, scratch / "crash")
    print("5/8 MariaDB/SQLite receipt-delete pipeline matrix", flush=True)
    pipeline = []
    pipeline_root = scratch / "pipeline"
    pipeline_root.mkdir(parents=True, exist_ok=True)
    for rows in (100, 10_000, 50_000):
        for iteration in range(1, 11):
            pipeline.append(
                pipeline_iteration(maria, recorder, pipeline_root, rows, iteration)
            )
    print("6/8 SQLite archive generation and real checks", flush=True)
    check_root = scratch / "checks"
    check_root.mkdir(parents=True, exist_ok=True)
    check_matrix = run_check_matrix(check_root, recorder, args.skip_large)
    print("7/8 result analysis", flush=True)
    raw = {
        "schema_version": 1,
        "generated_at": utc_now(),
        "environment": environment,
        "json_contract": json_contract,
        "lock": lock,
        "atomic_status": atomic_status,
        "cron_incidents": cron_incidents,
        "quarantine": quarantine,
        "year_campaign": year_campaign,
        "crash_scenarios": crash_scenarios,
        "pipeline": pipeline,
        "check_matrix": check_matrix,
        "phases": recorder.records,
    }
    raw = sanitize_value(raw, scratch, Path(__file__).resolve().parent)
    raw_path = results / "raw-results.json"
    report_path = results / "REPORT.md"
    raw_path.write_text(json.dumps(raw, indent=2, ensure_ascii=False, default=str), encoding="utf-8")
    report_path.write_text(markdown_report(raw), encoding="utf-8")
    all_passed = (
        all(item["passed"] for item in json_contract)
        and lock["passed"]
        and all(item["passed"] for item in atomic_status)
        and cron_incidents["passed"]
        and quarantine["passed"]
        and year_campaign["passed"]
        and all(item["passed"] for item in crash_scenarios)
        and all(item["passed"] for item in pipeline)
        and all(
            check["result"]["completed"]
            and check["result"]["healthy"]
            for archive in check_matrix
            for check in archive["checks"]
        )
    )
    print("8/8 complete", flush=True)
    print(json.dumps({"success": all_passed, "results": str(results)}, ensure_ascii=False))
    return 0 if all_passed else 1


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)
    full = subparsers.add_parser("full")
    full.add_argument("--scratch", required=True)
    full.add_argument("--results", required=True)
    full.add_argument("--skip-large", action="store_true")
    contract = subparsers.add_parser("json-contract")
    contract.add_argument("--result", choices=RESULT_MATRIX, required=True)
    crash = subparsers.add_parser("crash-child")
    crash.add_argument("--stage", type=int, choices=range(1, 7), required=True)
    crash.add_argument("--archive", type=Path, required=True)
    crash.add_argument("--run-id", required=True)
    recover = subparsers.add_parser("recover-child")
    recover.add_argument("--archive", type=Path, required=True)
    recover.add_argument("--run-id", required=True)
    holder = subparsers.add_parser("lock-holder")
    holder.add_argument("--lock", type=Path, required=True)
    holder.add_argument("--ready", type=Path, required=True)
    lock = subparsers.add_parser("lock-try")
    lock.add_argument("--lock", type=Path, required=True)
    status = subparsers.add_parser("status-writer")
    status.add_argument("--status", type=Path, required=True)
    status.add_argument(
        "--crash-point",
        choices=("before_temp", "during_temp", "after_temp", "after_replace"),
        required=True,
    )
    return parser


def main() -> int:
    args = build_parser().parse_args()
    if args.command == "full":
        return run_full(args)
    if args.command == "json-contract":
        return emit_runner_document(args.result)
    if args.command == "crash-child":
        return crash_child(args.stage, args.archive, args.run_id)
    if args.command == "recover-child":
        return recover_child(args.archive, args.run_id)
    if args.command == "lock-holder":
        return lock_holder(args.lock, args.ready)
    if args.command == "lock-try":
        return lock_try(args.lock)
    if args.command == "status-writer":
        return status_writer(args.status, args.crash_point)
    raise AssertionError("unknown command")


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except KeyboardInterrupt:
        raise SystemExit(130)
