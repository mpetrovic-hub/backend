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

1. Runs the coverage gate.
2. Captures the `before_cleanup` growth snapshot.
3. Freezes `target_max_primary_key` for rows with `created_at < cutoff_value`.
4. Writes a pending run to `wp_kiwi_retention_cleanup_runs`.
5. Schedules the single-event worker hook `kiwi_retention_cleanup_worker`.

The scheduler does not archive or delete the full backlog in the daily cron request.

Worker state is stored on `wp_kiwi_retention_cleanup_runs` with:

- `worker_phase`
- `target_max_primary_key`
- `archive_last_primary_key`
- `delete_last_primary_key`
- `worker_runs`
- `worker_last_started_at`
- `worker_last_finished_at`

Active runs use `pending`, `running`, `partial`, `completed`, or `failed` statuses. If a scheduler run sees an existing open worker run for `landing_page_sessions`, it does not create a second cleanup run; it reschedules the worker and records that the active run was rescheduled.

## Archive/delete safety contract

The worker archives at most the configured row/time budget per invocation, defaulting to `50,000` rows or `60` seconds.

It reads only:

- `created_at < cutoff_value`
- `id > archive_last_primary_key`
- `id <= target_max_primary_key`

Rows are ordered by primary key. Later old imports below the same cutoff but above the frozen target are left for a later gated run.

The first worker chunk's `archive_db_path` remains the archive file of record for all resumed chunks in the same cleanup run. After each archive chunk, SQLite `quick_check` must return `ok` before any MySQL delete is attempted.

Delete remains bound to archive evidence:

- Each chunk writes archive rows and `archive_batch_rows` in one SQLite transaction.
- Prior `archive_batch_rows` for the same `archive_batch_id` are not cleared.
- Only primary keys returned for the archived chunk are deleted from MySQL.
- Progress is persisted after every successful chunk.
- The next single event is scheduled after the configured delay when more rows remain.

On archive, quick-check, delete, final `integrity_check`, or audit persistence failure, the run is marked `failed` and no automatic retry counter advances destructive work. A lock-active worker invocation is not a failure; it does no work and reschedules.

After the final chunk, the worker runs SQLite `integrity_check`, captures the `after_cleanup` snapshot, and marks the run `completed`.

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
7. Confirm failed archive, quick-check, delete, integrity-check, or audit persistence stops destructive progress.
8. For compaction, dry-run first and compare eligible rows plus before/after byte estimates before enabling active mutation.
