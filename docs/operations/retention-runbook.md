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

Runs use `pending`, `running`, `partial`, `blocked`, `completed`, or `failed` statuses. If a scheduler run sees an existing open worker run for `landing_page_sessions`, it does not create a second cleanup run; it reschedules the worker and records that the active run was rescheduled. A `blocked` receipt run remains unfinished and owns its frozen source scope, but normal scheduler and worker invocations do not retry it; only the bounded repository-owned recovery path may make it resumable again.

The audit heartbeat writes only at job boundaries, never per archived or deleted row. Scheduler phases are `coverage_gate_running`, `snapshot_before_running`, `target_key_freezing`, and `archive_pending`. Worker phases include `archive_running`, `receipt_repair_running`, `receipt_blocked`, `receipt_verified`, `delete_running`, `archive_partial`, `snapshot_after_running`, `finalizing`, `completed`, and `failed`.

## Archive/delete safety contract

The worker archives at most the configured row/time budget per invocation, defaulting to `50,000` rows or `60` seconds.

It reads only:

- `created_at < cutoff_value`
- `id > archive_last_primary_key`
- `id <= target_max_primary_key`

Rows are ordered by primary key. Later old imports below the same cutoff but above the frozen target are left for a later gated run.

The first worker chunk's `archive_db_path` remains the archive generation of record for all resumed chunks in the same cleanup run. The worker and the external health controller share a non-blocking OS `flock` file beside that generation. The worker holds it from the start of SQLite archive work through persisted receipt verification, the bounded MySQL delete, and the following audit update.

Delete remains bound to archive evidence:

- Each chunk writes archive rows and `archive_batch_rows` in one SQLite transaction.
- Prior `archive_batch_rows` for the same `archive_batch_id` are not cleared.
- After commit, the worker reopens the SQLite database and verifies the exact batch identity, receipt IDs, and matching archive rows.
- The verified receipt and archive cursor are persisted before any MySQL delete.
- Only still-present MySQL primary keys from that persisted receipt are deleted.
- The logical delete cursor advances for the complete verified receipt. This safely reconciles a crash after a partial or complete MySQL delete but before its audit update.
- A missing or mismatched receipt gets exactly one idempotent archive repair attempt. If the re-read still fails, deletion remains blocked, the same unfinished run enters `status=blocked` / `worker_phase=receipt_blocked`, and a central `retention_archive_receipt_invalid` Operational Incident is raised. Normal cron calls neither create an overlapping run nor repeat the repair.
- The next single event is scheduled after the configured delay when more rows remain.

The crash-safe sequence covers failures before/after SQLite commit, before/after receipt-audit persistence, during/after MySQL delete, and after delete-audit persistence. A later worker always starts from the two persisted cursors; it never treats an in-memory list as the delete gate.

Global SQLite `quick_check` and `integrity_check` are not run inside WordPress WP-Cron or web requests. After the final receipt/delete/audit chunk, the worker captures the `after_cleanup` snapshot and marks the run `completed`; archive-wide health remains the external controller's responsibility.

On archive or receipt failure, destructive progress stops. A lock-active worker invocation is not a failure; it does no work and reschedules. When an audit write after a delete cannot be confirmed, the worker leaves the receipt cursor resumable instead of terminally discarding the recovery state.

A failed `before_cleanup` snapshot is fail-closed: no worker is scheduled and the cleanup run is marked failed with `snapshot_before_failed`. If the `after_cleanup` snapshot fails after archive/delete already completed successfully, the run stays `completed` and records `error_code=snapshot_after_failed` as an operational warning; completed destructive work is not misreported as failed.

## External SQLite archive health controller

The repository-owned runner is `tools/database/kiwi-retention-archive-health.php`. It is an external WP-CLI surface and must be invoked with `--require` before WordPress loads. Run these commands from the WordPress document root:

```bash
wp --require=wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health preflight
wp --require=wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health scheduled
wp --require=wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health status
wp --require=wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health diagnose --archive=kiwi_retention_archive_2026.sqlite --check=quick
wp --require=wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health diagnose --archive=kiwi_retention_archive_2026.sqlite --check=integrity
```

`status` reads state and archive discovery only. `diagnose` accepts an exact discovered archive basename and `--check=quick|integrity`; it does not accept arbitrary paths. `preflight` verifies PDO SQLite, process supervision, the shared non-blocking lock, atomic state exchange, a scratch SQLite check, and child cleanup.

Every command prints exactly one compact JSON line. Important fields are `status`, `exit_code`, `check`, `scope`, `archive`, `result`, `reason_code`, timestamps, `duration_seconds`, and `incident_action`. Exit mapping:

- `ok`, `corruption_detected`, or `no_work`: `0`
- `deferred` or `inconclusive`: `1`
- runner, state, input, or child errors: `2`

Example success and retry results:

```json
{"schema_version":1,"status":"completed","exit_code":0,"check":"quick_check","scope":"daily","archive":"kiwi_retention_archive_2026.sqlite","result":"ok","reason_code":"sqlite_check_ok","started_at":"2026-07-29T03:30:00+02:00","finished_at":"2026-07-29T03:30:01+02:00","duration_seconds":0.751,"incident_action":null,"child_running":false}
{"schema_version":1,"status":"incomplete","exit_code":1,"check":"integrity_check","scope":"daily","archive":"kiwi_retention_archive_2026.sqlite","result":"inconclusive","reason_code":"health_child_timeout","started_at":"2026-07-29T04:00:00+02:00","finished_at":"2026-07-29T04:10:00+02:00","duration_seconds":600.0,"incident_action":null,"child_running":false}

The public `check` values are `quick_check`, `integrity_check`, or `null`; the shorter `quick` and `integrity` names remain internal PRAGMA selectors. `archive` is the relative archive filename or `null`, and `incident_action` is `raised`, `repeated`, `resolved`, or `null`.
```

The parent process starts a dedicated read-only SQLite child, enforces `KIWI_RETENTION_ARCHIVE_HEALTH_TIMEOUT_SECONDS` (default `600` seconds; accepted range `30..3600`), and kills and reaps only that child on timeout. The controller and child acquire separate shared generation locks; the child records lock readiness before opening SQLite and retains its lock until exit. Retention writers require the exclusive generation lock, so a child that survives the kill deadline still prevents concurrent archive writes after the controller returns. If the child still reports running after the force-kill/reap deadline, the controller reports `child_running=true` without entering blocking `proc_close()`. Override that setting only after a measured Hostinger `preflight` plus `diagnose` run shows that the current archive cannot finish safely within the default. A timeout is `inconclusive`, never proof of corruption. `KIWI_RETENTION_ARCHIVE_ROOT` continues to define the archive root and must remain a writable, protected filesystem location outside public content.

The external scheduler provides three UTC slots daily:

```cron
CRON_TZ=UTC
30 1 * * * cd /ABSOLUTE/WORDPRESS_ROOT && /ABSOLUTE/WP_CLI --path=/ABSOLUTE/WORDPRESS_ROOT --require=/ABSOLUTE/WORDPRESS_ROOT/wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health scheduled
0 2 * * * cd /ABSOLUTE/WORDPRESS_ROOT && /ABSOLUTE/WP_CLI --path=/ABSOLUTE/WORDPRESS_ROOT --require=/ABSOLUTE/WORDPRESS_ROOT/wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health scheduled
30 2 * * * cd /ABSOLUTE/WORDPRESS_ROOT && /ABSOLUTE/WP_CLI --path=/ABSOLUTE/WORDPRESS_ROOT --require=/ABSOLUTE/WORDPRESS_ROOT/wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health scheduled
```

`/ABSOLUTE/WORDPRESS_ROOT` and `/ABSOLUTE/WP_CLI` are deliberate deployment placeholders because repository code cannot safely infer Hostinger account paths. Replace them with the absolute paths proven by Production `preflight`, then store the resulting full commands in the Issue evidence.

The controller interprets calendar dates and weekdays in `Europe/Berlin`: Monday through Saturday use `quick_check`; Sunday uses `integrity_check`. The daily target is the frozen `archive_db_path` of the oldest open cleanup run when one exists, even across a calendar-year rollover; otherwise it is the latest current-year generation. A failed archive-directory scan, active-run lookup, invalid frozen path, or missing frozen archive after persisted archive/delete progress fails closed instead of checking a guessed fallback or recording `no_work`. Target-resolution failures count toward the three daily attempts and raise the same central incomplete-check incident on the third slot. A missing path before the first persisted archive write remains `no_work`. The second and third slots retry incomplete/deferred work. After the third incomplete attempt, it raises `retention_archive_health_check_incomplete`; the next Berlin calendar day resets only that retry budget and checks the persisted overdue archive and mode before initializing the new day's target. A later complete daily or annual result for that archive resolves the incident. From January 2 onward, free slots after the daily check process one file from the annual integrity campaign snapshot. If a file in that frozen snapshot later becomes unavailable, it remains pending and the controller fails with `annual_archive_unavailable`; the file is never recorded as skipped.

Production activation is a separate operational step, not part of the code PR: deploy the reviewed code, run `preflight`, run both explicit `diagnose` modes against the current archive, and only then install or enable all three external cron entries. Alert on non-zero command exits and retain the compact JSON line as evidence. Review `retention_archive_health_check_incomplete` within one working day and `retention_archive_corruption_detected` within three working days.

Controller state is atomically replaced at `<KIWI_RETENTION_ARCHIVE_ROOT>/sqlite/kiwi_retention_archive_health_state.json`. Invalid or contradictory JSON fails closed. The controller and worker use the same per-generation `.lock` file; a held lock returns `deferred` without waiting.

A completed corruption result first raises `retention_archive_corruption_detected`, then writes a `.quarantine.json` marker beside the affected generation. The marker receives `controller_recorded_at` only after the controller has durably recorded the corruption. On restart, an unacknowledged predecessor marker is reconciled before a newly active successor can be selected. Incomplete or timed-out checks never quarantine. The worker never falls back to an older archive. If its persisted generation is quarantined, it atomically closes the old cleanup run and creates a deterministic `_part_N` successor for remaining MySQL rows, using the existing cleanup-run schema. Transient failures while resolving the successor path or counting remaining MySQL rows keep the original run open and reschedule the transition. The append-only transition event is mandatory and is retried idempotently before the successor may delete source rows. The first successful successor receipt/delete/audit batch resolves the corruption incident with `resolution_reason=quarantined_and_replaced`. Existing rows already deleted into the corrupt archive cannot be reconstructed automatically and remain an operator-review concern.

Emergency stop and restart:

1. Stop only the three external archive-health cron entries. Do not disable the retention worker, delete archive/state/quarantine files, or change database rows.
2. Preserve the one-line JSON output, `status` result, related Operational Incident, and affected archive name for diagnosis.
3. After reviewed code/config remediation, run `preflight`, `status`, and both explicit `diagnose` modes.
4. Re-enable the three external cron entries only when those checks are green. Do not reset the controller state; it is the retry and annual-campaign audit trail.

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
9. Run archive-health `preflight`, then verify the three external UTC slots and one-line JSON/exit-code monitoring.
10. Confirm incomplete checks raise an incident only after the third slot and only completed corruption writes quarantine state.
11. For compaction, dry-run first and compare eligible rows plus before/after byte estimates before enabling active mutation.
