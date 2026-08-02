# Retention Runbook

## Read when

- Work touches raw landing-session cleanup, retention coverage checks, SQLite archive/delete, retention WP-Cron, or landing-session raw-context compaction.

## Source of truth for

- Landing raw retention coverage gate behavior.
- Archive/delete worker behavior.
- Raw-context compaction behavior.

## Not here

- Daily summary analytics details: see `landing-funnel-analytics.md`.
- Config constant list: see `configuration-reference.md`.
- Temporary dated DB audit plans.

## Landing raw retention coverage gate

The `landing_page_sessions` retention cleanup uses a fail-closed coverage gate before archive/delete work starts. The gate checks raw candidate days chronologically and compares date-bounded light totals against the durable Main and TK-zone daily summaries instead of rebuilding the full historical summary contract in one query.

Gate statuses:

- `passed`: every raw candidate day before the requested cutoff is covered; cleanup may use the requested cutoff.
- `partial`: at least one contiguous early date range is covered or explicitly accepted, but a later date has a hard blocker; cleanup may only use `effective_cutoff_value`, the start of the day after the last verified date.
- `failed`: no safe cleanup range exists or a query/schema/summary read failed; cleanup must not archive or delete rows.

Hard blockers are exact mismatches for canonical sessions, page-loaded sessions, handoff attempts/successes/fails, and hidden-time min/max where applicable. CTA session/click mismatches are tolerated only up to `max(5 events, 0.1%)`; larger CTA diffs block the affected date. Sales and sales amount diffs are warning-only for this source because confirmed sales live in `wp_kiwi_sales` and are not deleted by landing-session raw cleanup.

The gate intentionally does not run the expensive dimension-level deep compare for every non-accepted candidate date. Hard light totals run for every candidate date. Deep compare runs only for the current retention edge date, the first hard-blocked date for diagnosis, and at most two CTA-warning dates. Sales-only warning dates are not deep-checked.

Audit details are stored on `wp_kiwi_retention_cleanup_runs.gate_results_json`, including coverage mode, requested/effective cutoffs, verified date, candidate dates, deep-checked dates, totals-only dates, skipped deep dates, deep-compare reasons, blocked dates, warning dates, and compact per-summary details.

## Scheduler and worker

The daily retention cron is only a scheduler. The active recurring hook is `kiwi_retention_cleanup_scheduler_daily`; the legacy unbounded `kiwi_retention_cleanup_daily` hook is cleared during normal scheduling.

The scheduler:

1. Marks non-resumable unfinished runs with an `updated_at` heartbeat older than 30 minutes as `failed` with `error_code=cron_timeout_suspected` before looking for an active run. Receipt-safe archive/delete phases remain open so a later worker can reconcile them from persisted SQLite evidence. An existing failed `worker_phase` is retained; an empty phase becomes `stale_unknown`. Each run newly transitioned by this call also writes an idempotent shared operational event; see `operational-events-runbook.md`.
2. Runs the coverage gate.
3. Captures the `before_cleanup` growth snapshot.
4. Freezes `target_max_primary_key` for rows with `created_at < cutoff_value`.
5. Writes a pending run to `wp_kiwi_retention_cleanup_runs`.
6. Schedules the single-event worker hook `kiwi_retention_cleanup_worker`.

The scheduler does not archive or delete the full backlog in the daily cron request.

The first later real, non-dry, successfully audit-persisted `completed` or `completed_noop` run resolves the shared retention operational incident. Disabled, skipped, pending, partial, and rescheduled states do not resolve it.

Worker state is stored on `wp_kiwi_retention_cleanup_runs` with:

- `worker_phase`
- `target_max_primary_key`
- `archive_last_primary_key`
- `delete_last_primary_key`
- `worker_runs`
- `worker_last_started_at`
- `worker_last_finished_at`

Runs use `pending`, `running`, `partial`, `blocked`, `completed`, or `failed` statuses. If a scheduler run sees an existing open worker run for `landing_page_sessions`, it does not create a second cleanup run; it reschedules the worker and records that the active run was rescheduled. An open-run lookup error fails closed and never means that a new run may be created. A `blocked` receipt run remains unfinished and owns its frozen source scope. Normal scheduler and worker invocations may idempotently retry a missing central Receipt Incident, but they never repeat the receipt repair or resume archive/delete work; only the bounded repository-owned recovery path may make the run resumable again.

The audit heartbeat writes only at job boundaries, never per archived or deleted row. Scheduler phases are `coverage_gate_running`, `snapshot_before_running`, `target_key_freezing`, and `archive_pending`. Worker phases include `archive_running`, `archive_corruption_blocked`, `receipt_repair_running`, `receipt_blocked`, `receipt_verified`, `delete_running`, `archive_partial`, `snapshot_after_running`, `finalizing`, `completed`, and `failed`.

## Archive/delete safety contract

The worker archives at most the configured row/time budget per invocation, defaulting to `50,000` rows or `60` seconds.

It reads only:

- `created_at < cutoff_value`
- `id > archive_last_primary_key`
- `id <= target_max_primary_key`

Rows are ordered by primary key. Later old imports below the same cutoff but above the frozen target are left for a later gated run.

The first worker chunk's `archive_db_path` remains the archive generation of record for all resumed chunks in the same cleanup run. The worker and the read-only health child use the same exclusive, non-blocking OS `flock` file beside that generation. The worker holds it from the start of SQLite archive work through persisted receipt verification, the bounded MySQL delete, and the following audit update. It inspects the corruption safety gate after acquiring the lock, before every SQLite write or receipt repair, and immediately before every MySQL delete. A generation-specific `.lock.write-blocked` sentinel or unreadable matching corruption-Incident state stops destructive work. Manual recovery clears the sentinel before resolving the Incident, so cleanup never observes a false-safe transition.

Delete remains bound to archive evidence:

- Each chunk writes archive rows and `archive_batch_rows` in one SQLite transaction.
- Prior `archive_batch_rows` for the same `archive_batch_id` are not cleared.
- After commit, the worker reopens the SQLite database and verifies the exact batch identity, receipt IDs, and matching archive rows.
- If receipt rows commit but later batch finalization fails, the same run remains open with `pending_verification`; the next worker invocation re-verifies that durable evidence before recording archive counts or deleting source rows.
- The verified receipt and archive cursor are persisted before any MySQL delete.
- Only still-present MySQL primary keys from that persisted receipt are deleted.
- The logical delete cursor advances for the complete verified receipt. This safely reconciles a crash after a partial or complete MySQL delete but before its audit update.
- A missing or mismatched receipt gets exactly one idempotent archive repair attempt. If that repair commits its receipt rows but later batch finalization fails, the worker re-reads the committed receipt before deciding whether to block. If the re-read still fails, deletion remains blocked, the same unfinished run enters `status=blocked` / `worker_phase=receipt_blocked`, and a central `retention_archive_receipt_invalid` Operational Incident is raised. A transient Incident-write failure is retried idempotently while the run stays blocked. Normal cron calls neither create an overlapping run nor repeat the repair.
- The next single event is scheduled after the configured delay when more rows remain.

The crash-safe sequence covers failures before/after SQLite commit, before/after receipt-audit persistence, during/after MySQL delete, and after delete-audit persistence. A later worker always starts from the two persisted cursors; it never treats an in-memory list as the delete gate.

Global SQLite `quick_check` and `integrity_check` are not run inside WordPress WP-Cron or web requests. After the final receipt/delete/audit chunk, the worker captures the `after_cleanup` snapshot and marks the run `completed`; archive-wide health remains the external controller's responsibility.

On archive or receipt failure, destructive progress stops. A lock-active worker invocation is not a failure; it does no work and reschedules. When an audit write after a delete cannot be confirmed, the worker leaves the receipt cursor resumable instead of terminally discarding the recovery state.

A failed `before_cleanup` snapshot is fail-closed: no worker is scheduled and the cleanup run is marked failed with `snapshot_before_failed`. If the `after_cleanup` snapshot fails after archive/delete already completed successfully, the run stays `completed` and records `error_code=snapshot_after_failed` as an operational warning; completed destructive work is not misreported as failed.

## External SQLite archive health controller

The repository-owned runner is `tools/database/kiwi-retention-archive-health.php`. It is an external WP-CLI surface and must be invoked with `--require` before WordPress loads. It has exactly three public modes:

```bash
wp --require=wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health check --check=quick
wp --require=wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health check --check=integrity
wp --require=wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health diagnose --archive=kiwi_retention_archive_2026.sqlite --check=quick
wp --require=wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health diagnose --archive=kiwi_retention_archive_2026.sqlite --check=integrity
wp --require=wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health unblock --archive=kiwi_retention_archive_2026.sqlite --confirm
wp --require=wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health unblock --archive=kiwi_retention_archive_2026.sqlite --replacement=kiwi_retention_archive_2026_part_2.sqlite --confirm
```

`check` selects the frozen archive of the oldest open cleanup run, otherwise the newest discovered archive, and performs the explicitly requested mode. It is the only mode intended for automatic scheduling. `diagnose` accepts an exact discovered archive basename and is strictly read-only: path-like input is rejected rather than reduced to its basename, and the command neither raises or resolves Incidents nor changes the write-block sentinel. `unblock` applies the same strict basename rule, always requires `--confirm`, always completes an explicit read-only `integrity_check`, and persists a corruption gate if that verification itself proves corruption. Only after a healthy result does it change recovery state. A matching corruption sentinel or open Corruption Incident must already exist on `A` and is revalidated under `A`'s acquired generation lock; a healthy archive cannot enter recovery or terminalize resumable runs. Without `--replacement`, it verifies and re-enables the same archive. With `--replacement=B`, `B` must be distinct from `A` and must be the generation the cleanup resolver would select; this permits a frozen prior-year A to use the active current-year B after rollover. The command writes a distinct replacement-transition marker on `B` bound to source `A`, terminalizes unfinished cleanup runs still bound to `A`, clears `A`'s corruption sentinel, and resolves `A`'s Corruption Incident as its last recovery action. Workers re-read their audit row after acquiring the generation lock, and normal audit updates cannot change a row whose `finished_at` is already set; stale workers therefore stop without touching SQLite or MySQL. `B` remains blocked if Incident resolution fails; its transition marker is removed only after the resolution succeeds and is never treated by Health as corruption evidence for `B`. The source binding permits only the matching final-clear retry if `A`'s own gate is already resolved. No mode creates a replacement archive.

Every command prints exactly one compact JSON line. Mandatory fields are `schema_version`, `command`, `result`, `reason_code`, `archive`, `check`, `started_at`, `finished_at`, and `duration_seconds`. Optional fields are `incident_action`, `write_blocked`, and `child_running`. Filesystem paths, exception text, database credentials, and a duplicate JSON `exit_code` are never emitted. Process exit mapping:

- definitive success (`ok`, `unblocked`): `0`
- completed corruption, lock deferral, or inconclusive timeout: `1`
- invalid input, bootstrap, discovery, persistence, or read-only child error: `2`

Example success and retry results:

```json
{"schema_version":1,"command":"check","result":"ok","reason_code":"sqlite_check_ok","archive":"kiwi_retention_archive_2026.sqlite","check":"quick","started_at":"2026-08-01T03:30:00+02:00","finished_at":"2026-08-01T03:30:01+02:00","duration_seconds":0.751,"incident_action":"resolved"}
{"schema_version":1,"command":"check","result":"inconclusive","reason_code":"health_child_timeout","archive":"kiwi_retention_archive_2026.sqlite","check":"integrity","started_at":"2026-08-01T04:00:00+02:00","finished_at":"2026-08-01T04:10:00+02:00","duration_seconds":600.0,"incident_action":"raised","child_running":true}
```

The parent process starts one dedicated read-only SQLite child and enforces `KIWI_RETENTION_ARCHIVE_HEALTH_TIMEOUT_SECONDS` (default `600` seconds; accepted range `30..3600`). The child acquires the same exclusive, non-waiting generation lock used by retention cleanup before it opens SQLite and holds the lock until it exits. A cleanup lock therefore returns `archive_lock_active`, and a surviving timed-out child continues to prevent SQLite writes and MySQL deletion after the parent returns. A timeout or exception is inconclusive and never proof of corruption. Only a completed PRAGMA whose result is not exactly `ok` can set the generation-specific write-block sentinel and raise `retention_archive_corruption_detected`. The sentinel is written before the Incident. If that filesystem write fails, the child retains the lock and requests a parent handoff; the parent persists the Corruption Incident and acknowledges it before the child releases. Failure of both gates returns an error and never success. Cleanup fails closed if either safety source is unreadable or disagrees.

Install at most one external scheduled invocation. The check mode is explicit; this example runs the cheaper read-only check once per day:

```cron
CRON_TZ=UTC
30 1 * * * cd /ABSOLUTE/WORDPRESS_ROOT && /ABSOLUTE/WP_CLI --path=/ABSOLUTE/WORDPRESS_ROOT --require=/ABSOLUTE/WORDPRESS_ROOT/wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health check --check=quick
```

`/ABSOLUTE/WORDPRESS_ROOT` and `/ABSOLUTE/WP_CLI` are deployment placeholders because repository code cannot safely infer Hostinger account paths. The scheduler or its surrounding platform owns notification and retry policy; the controller contains no day/week calendar, retry campaign, annual campaign, or central JSON state reducer.

Any non-definitive scheduled `check` raises or repeats one sanitized `retention_archive_health_unavailable` Operational Incident. The next definitive check resolves that availability correlation. If corruption gates are already durable but that resolution write failed, the next controller call retries the resolution before returning the existing blocked result and does not rerun SQLite. If bootstrap reaches the normal controller and Operational Event service, it records the same Availability correlation. The dependency-independent fallback emits only sanitized JSON and never writes controller state, SQLite receipts, an Incident spool, or a direct database fallback. The operator decides when to retry from the compact exit code and `reason_code`.

Production activation is a separate operational step, not part of the code PR:

1. Disable the former three-slot `scheduled` jobs before deploying this change.
2. Back up the archive root, including any legacy state or quarantine files. Do not delete them during deployment.
3. Inventory legacy `.quarantine.json` markers before code activation. If any exist, pause the retention scheduler/worker until each marked generation has either been re-confirmed by `check` into the new `.lock.write-blocked` plus Corruption-Incident gates or has been repaired/replaced and released through confirmed `unblock`.
4. Deploy reviewed code and run both `diagnose` modes against the current archive.
5. Exercise the explicit `check --check=quick` command and retain its one-line result.
6. Install the single explicit `check` schedule only after those reads are definitive and every legacy corruption marker has a reviewed disposition.
7. After a separate backup/restore check and verified gate migration, legacy `kiwi_retention_archive_health_state.json`, `.quarantine.json`, and bootstrap-deferral artifacts may be moved out of the active archive root. Current code neither reads nor writes them.

Confirmed corruption does not rename, quarantine, repair, copy, replace, or switch an archive automatically. Preserve the archive and its `.lock.write-blocked` sentinel, investigate read-only, and choose one reviewed action:

- repaired archive `A`: repair outside the runtime, back it up, then run `unblock --archive=A --confirm`; the command performs its own full `integrity_check` before clearing the block;
- replacement archive `B`: build and validate `B` outside the runtime, then run `unblock --archive=A --replacement=B --confirm`; the command accepts only the distinct active generation the cleanup resolver would select, including the current-year generation after rollover, verifies `B` before changing run state, and keeps `B` durably blocked until `A`'s final Incident resolution succeeds.

If the read-only child survives a timeout, inspect the process table and the compact output. Do not kill an unrelated PHP/WP-CLI process or remove the lock file; the OS lock disappears only when its owning process exits. A second health check and retention cleanup must remain deferred while that process owns the generation lock.

Emergency stop and restart:

1. Stop the single external archive-health check job. Do not disable the retention worker, delete archives or sentinels, or change database rows.
2. Preserve the one-line JSON output, related Operational Incident, affected archive name, and process/lock evidence.
3. After reviewed code/config remediation, run both explicit `diagnose` modes and one explicit `check`.
4. Re-enable the one scheduled check only when the read-only checks are definitive and no unreviewed corruption block is being bypassed.

## Landing-session raw-context compaction

Old `wp_kiwi_landing_page_sessions.raw_context` rows can be compacted before they reach retention archive/delete age. This reduces future SQLite archive size because the retention archive keeps copying the existing source `raw_context` column; existing archive files are not rewritten.

Runtime state:

- settings option: `kiwi_landing_session_raw_context_compaction_settings`
- last-result option: `kiwi_landing_session_raw_context_compaction_last_result`
- daily scheduler hook: `kiwi_landing_session_raw_context_compaction_daily`
- worker hook: `kiwi_landing_session_raw_context_compaction_worker`
- transient lock: `kiwi_landing_session_raw_context_compaction_lock`

Default settings are safe for deployment:

- `enabled=false`
- `dry_run=true`
- `age_days=7`
- `row_limit=20000`
- `time_limit_seconds=60`
- `reschedule_delay_seconds=60`
- `lock_ttl_seconds=300`

`age_days` is clamped to at least `3` complete days and at most the configured `landing_page_sessions` retention age.

The compact JSON schema is:

```json
{
  "schema": "landing_session_raw_context_compact_v1",
  "landing_page": {},
  "client_ip_resolution": {}
}
```

Retained `landing_page` fields:

`key`, `country`, `flow`, `provider`, `locale`, `service_type`, `business_number`, `keyword`, `service_key`, `shortcode`, `price_label`, `kpi_cta_steps`, `render_mode`, `folder_name`, `cta_href`.

Retained `client_ip_resolution` fields:

`source`, `peer_trusted`, `trusted_proxy_configured`, `forwarded_headers_present`, `other_client_ip_headers_present`, `forwarded_candidate_count`, `resolution_reason`.

The worker uses a temporary table and set-based `INSERT ... SELECT` plus `UPDATE ... JOIN`. It skips and counts empty `raw_context`, invalid JSON, and rows already carrying `schema=landing_session_raw_context_compact_v1`.

The last result records success, dry-run state, cutoff, age, row/time limits, eligible and processed counts, skip counts, before/after byte estimates, saved bytes, lock skips, remaining-work flag, and error details.

`enabled` is the master switch. With `enabled=false`, the worker exits as a disabled no-op and stores `error_code=compaction_disabled`, even if `dry_run=true`. A measurement-only dry run requires `enabled=true` and `dry_run=true`.

Activation procedure:

1. Keep `enabled=false` until the dry-run result is reviewed.
2. Set `enabled=true` while leaving `dry_run=true`; trigger `kiwi_landing_session_raw_context_compaction_worker` and review `eligible_rows`, `bytes_before`, `bytes_after`, and `saving_bytes`.
3. For a controlled active run, set `dry_run=false`, use the default `row_limit=20000`, and validate a sample of older rows plus newer rows that must remain unchanged.
4. Return to dry-run or disabled if the compact evidence is not acceptable.

On 2026-07-08, production was set to `enabled=true`, `dry_run=true` for a manual measurement run. With cutoff `2026-07-01 00:00:00`, the worker reported `67,353` eligible rows, processed the first `20,000` row chunk in dry-run mode, estimated `40,338,708` bytes before and `16,300,642` bytes after, and wrote `0` compacted rows as expected.

Planning sandbox measurements showed about `59.8%` logical `raw_context` byte savings on the `2026-07-02` sample and about `59.7%` on the then-current eligible backlog. This does not promise immediate physical MySQL file shrink: InnoDB may only reuse freed space internally unless a separate maintenance plan such as `OPTIMIZE TABLE` is explicitly approved.

## Operational checks

When validating retention behavior:

1. Confirm WP-Cron has scheduled `kiwi_retention_cleanup_scheduler_daily`.
2. Confirm legacy `kiwi_retention_cleanup_daily` is not scheduled.
3. After a gated scheduler run, confirm `kiwi_retention_cleanup_worker` is scheduled as a single event.
4. Confirm `wp_kiwi_retention_cleanup_runs` shows `pending` or `partial` worker state with frozen `target_max_primary_key`.
5. Confirm cleanup uses the effective cutoff returned by the coverage gate.
6. Confirm archive evidence exists before MySQL delete.
7. Confirm every MySQL delete is preceded by a persisted exact SQLite receipt and that receipt/delete cursors match after completion.
8. Confirm receipt-safe archive/delete phases remain resumable while non-resumable stale runs are marked `failed`.
9. Run both explicit archive-health `diagnose` modes, then verify the single external `check` invocation and one-line JSON/process-exit monitoring.
10. Confirm every non-definitive scheduled check raises or repeats one availability Incident, only a completed non-`ok` PRAGMA sets the corruption block, and no archive is replaced automatically.
11. For compaction, dry-run first and compare eligible rows plus before/after byte estimates before enabling active mutation.
