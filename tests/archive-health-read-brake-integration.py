#!/usr/bin/env python3
"""Real Linux/PDO subprocess checks. No production paths or customer data."""
import base64
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time

from archive_health_brake_php import resolve_php_binary

REPO = Path(__file__).resolve().parent.parent
HELPER = REPO / "tools/database/class-retention-archive-health-read-brake.php"
CHILD = REPO / "tools/database/kiwi-retention-archive-health.php"
FIXTURE = REPO / "tools/database/archive-health-read-brake/fixtures/kiwi_retention_archive_2000.sqlite"
PHP = resolve_php_binary()
assert os.name == "posix" and PHP and FIXTURE.exists(), "Linux, PDO SQLite, and the built bundle are required"
results = []


def environment(archive, rate=700, helper=HELPER):
    command = [PHP, "-r", "require $argv[1];echo json_encode(Kiwi_Retention_Archive_Health_Read_Brake::child_environment($argv[2],(int)$argv[3]));", str(helper), str(archive), str(rate)]
    # The environment snapshot is captured internally, never printed in test evidence.
    return json.loads(subprocess.check_output(command, text=True))


def run_child(root, archive, changes=None, rate=700, payload_rate=None, child=CHILD):
    env = environment(archive, rate, child.with_name(HELPER.name))
    for key, value in (changes or {}).items():
        if value is None:
            env.pop(key, None)
        else:
            env[key] = str(value)
    payload = {
        "archive_path": str(archive), "check": "quick",
        "readiness_path": str(root / (".kiwi_retention_health_child_" + "a" * 32 + ".ready")),
        "corruption_handoff_timeout_seconds": 7200,
        "health_read_units_per_second": rate if payload_rate is None else payload_rate,
    }
    started = time.monotonic()
    process = subprocess.run([PHP, str(child), "--kiwi-retention-health-child", base64.b64encode(json.dumps(payload).encode()).decode()], env=env, capture_output=True, text=True, timeout=45)
    output = json.loads(process.stdout) if process.stdout.strip() else None
    return process, output, time.monotonic() - started


def record(name, check):
    check()
    results.append(name)
    print("PASS " + name, flush=True)


with tempfile.TemporaryDirectory(prefix="kiwi-brake-integration-") as directory:
    root = Path(directory)
    archive = root / "kiwi_retention_archive_2026.sqlite"
    shutil.copyfile(FIXTURE, archive)
    original = hashlib.sha256(archive.read_bytes()).hexdigest()

    def healthy():
        process, output, elapsed = run_child(root, archive)
        assert process.returncode == 0 and not process.stderr and output == {"result": "ok", "reason_code": "sqlite_check_ok", "check_completed": True}, (process.returncode, output)
        assert elapsed > 6 and hashlib.sha256(archive.read_bytes()).hexdigest() == original
        assert not archive.with_name(archive.name + "-wal").exists()
        assert not archive.with_name(archive.name + "-shm").exists()
    record("real child guard and complete check preserve bytes and sidecars", healthy)

    cases = [
        ("missing preload", {"LD_PRELOAD": None}),
        ("ignored missing library", {"LD_PRELOAD": str(root / "missing.so")}),
        ("lost required mode", {"KIWI_HEALTH_BRAKE_REQUIRED": None}),
        ("invalid rate", {"KIWI_HEALTH_READS_PER_SECOND": "900"}),
        ("missing rate", {"KIWI_HEALTH_READS_PER_SECOND": None}),
        ("wrong build", {"KIWI_HEALTH_BRAKE_BUILD_ID": "0" * 64}),
        ("disabled random advice", {"KIWI_HEALTH_RANDOM_ADVICE": "0"}),
        ("lost fixture identity", {"KIWI_HEALTH_BRAKE_FIXTURE": str(root / "absent.sqlite")}),
        ("wrong target inode", {"KIWI_HEALTH_BRAKE_TARGET": str(FIXTURE)}),
        ("immediate binding disabled", {"LD_BIND_NOW": "0"}),
    ]
    for name, changes in cases:
        def negative(changes=changes):
            process, output, elapsed = run_child(root, archive, changes)
            assert process.returncode != 0 and (output is None or output.get("result") == "error"), (output, elapsed)
            assert output is None or output.get("check_completed") is False
            assert not archive.with_name(archive.name + ".lock.write-blocked").exists()
            assert hashlib.sha256(archive.read_bytes()).hexdigest() == original
        record(name + " fails before target PRAGMA", negative)

    def missing_payload():
        process, output, _ = run_child(root, archive, payload_rate="700")
        assert process.returncode == 2 and output["reason_code"] == "health_brake_configuration_invalid"
    record("noninteger private rate is rejected", missing_payload)

    def nonempty_wal():
        wal = Path(str(archive) + "-wal")
        wal.write_bytes(b"uncheckpointed fixture")
        try:
            process, output, elapsed = run_child(root, archive)
            assert process.returncode == 2 and output["reason_code"] == "sqlite_wal_not_empty" and elapsed < 2
        finally:
            wal.unlink()
    record("WAL fails before both guard and full PRAGMA without corruption", nonempty_wal)

    for define in ["KIWI_NO_CALLBACK", "KIWI_COUNTERS_WITHOUT_PACING"]:
        def ineffective(define=define):
            package = root / define
            tools = package / "tools/database"
            tools.mkdir(parents=True)
            for name in ["kiwi-retention-archive-health.php", "class-retention-archive-health-read-brake.php", "class-retention-archive-health-bootstrap-recorder.php"]:
                shutil.copyfile(REPO / "tools/database" / name, tools / name)
            services = package / "includes/services"
            services.mkdir(parents=True)
            for name in ["class-retention-archive-name.php", "class-retention-archive-write-block.php"]:
                shutil.copyfile(REPO / "includes/services" / name, services / name)
            module = tools / "archive-health-read-brake"
            shutil.copytree(REPO / "tools/database/archive-health-read-brake", module)
            subprocess.run(["gcc","-shared","-fPIC","-D"+define,"-o",str(module / "limit-archive-reads.so"),str(REPO / "tests/fixtures/archive-health-brake-no-pacing.c"),"-Wl,--no-as-needed","-lsqlite3"],check=True)
            manifest = json.loads((module / "manifest.json").read_text())
            manifest["library_sha256"] = hashlib.sha256((module / "limit-archive-reads.so").read_bytes()).hexdigest()
            (module / "manifest.json").write_text(json.dumps(manifest))
            process, output, elapsed = run_child(root,archive,child=tools / CHILD.name)
            assert process.returncode == 2 and output["reason_code"] == "health_brake_probe_failed" and elapsed < 2
            assert not archive.with_name(archive.name + ".lock.write-blocked").exists()
        record("loaded " + define + " is rejected before target scan", ineffective)

    native_probe = REPO / "tests/fixtures/archive-health-brake-native-probe.py"
    fault_module = root / 'faults.so'
    subprocess.run(['gcc','-shared','-fPIC','-Wall','-Wextra','-Werror','-o',str(fault_module),str(REPO/'tests/fixtures/archive-health-brake-faults.c'),'-ldl'],check=True)
    for action in ["reads", "mmap", "fd_reuse", "fadvise", "clock", "sleep", "proc_fd", "sleep_eintr", "pread_eintr", "pread_short", "fork_pid"]:
        def native_case(action=action):
            env = environment(archive)
            if action not in ['reads','mmap','fd_reuse','fork_pid']:
                env['LD_PRELOAD'] += ':' + str(fault_module)
            process = subprocess.run(["python3",str(native_probe),action,"--kiwi-retention-health-child"],env=env,capture_output=True,text=True,timeout=45)
            assert process.returncode == 0, process.stderr[-1200:]
            output = json.loads(process.stdout)
            assert output["result"] == "PASS"
            if action == "reads":
                reference = FIXTURE.read_bytes()
                assert output["large_sha256"] == hashlib.sha256(reference[:1024*1024]).hexdigest()
                assert output["unaligned_sha256"] == hashlib.sha256(reference[4094:4094+8192]).hexdigest()
        record("native " + action, native_case)

    probe = REPO / "tests/fixtures/retention-health-brake-probe.php"
    for action in ["check", "change_rate", "lose_configuration", "replace_target"]:
        def probe_case(action=action):
            env = environment(archive)
            replacement = root / 'replacement.sqlite'
            if action == 'replace_target':
                shutil.copyfile(FIXTURE, replacement)
            payload = base64.b64encode(json.dumps({"archive_path":str(archive), "rate":700, "action":action, "replacement_path":str(replacement)}).encode()).decode()
            process = subprocess.run([PHP, str(probe), "--probe", "--kiwi-retention-health-child", payload], env=env, capture_output=True, text=True, timeout=45)
            output = json.loads(process.stdout)
            if action == "check":
                assert process.returncode == 0 and output["rows"] == ["ok"]
                phases = output["phases"]
                assert len(phases) == 2 and phases[0]["units"] == phases[1]["units"] >= 1003
                assert phases[0]["after"]["pid"] == phases[1]["after"]["pid"]
                assert phases[1]["duration_ns"] >= phases[0]["duration_ns"] * 1.35
            else:
                assert process.returncode == 2 and output["result"] == "error" and not output["check_completed"]
            assert hashlib.sha256(archive.read_bytes()).hexdigest() == original
        record("same PID probe / " + action, probe_case)

print(json.dumps({"status":"PASS", "checks":len(results), "cases":results}))
