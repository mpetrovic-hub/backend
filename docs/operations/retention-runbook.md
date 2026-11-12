# Retention Runbook

## Read when

- Work touches raw landing-session or landing-handoff cleanup, retention coverage policy, SQLite archive/delete, retention WP-Cron, or landing-session raw-context compaction.

## Source of truth for

- Landing raw retention coverage-policy behavior.
- Archive/delete worker behavior.
- Raw-context compaction behavior.

## Not here

- Daily summary analytics details: see `landing-funnel-analytics.md`.
- Config constant list: see `configuration-reference.md`.
- Temporary dated DB audit plans.

## Landing raw retention coverage policy

The `landing_page_sessions` retention cleanup uses a fail-closed coverage gate before archive/delete work starts. The gate checks raw candidate days chronologically and compares date-bounded light totals against the durable Main and TK-zone daily summaries instead of rebuilding the full historical summary contract in one query.

`landing_handoff_events` deliberately has no analytics coverage gate. Its `coverage_gate_required=false` registry policy passes the complete requested cutoff directly into the shared retention flow. Handoff cleanup never queries `wp_kiwi_landing_page_sessions` or either landing summary and never emits a synthetic `coverage_gate_failed` outcome. This is a source policy only; it does not weaken the shared archive-before-delete safety contract.

Gate statuses:

- `passed`: every raw candidate day before the requested cutoff is covered; cleanup may use the requested cutoff.
- `partial`: at least one contiguous early date range is covered or explicitly accepted, but a later date has a hard blocker; cleanup may only use `effective_cutoff_value`, the start of the day after the last verified date.
- `failed`: no safe cleanup range exists or a query/schema/summary read failed; cleanup must not archive or delete rows.

Hard blockers are exact mismatches for canonical sessions, page-loaded sessions, handoff attempts/successes/fails, and hidden-time min/max where applicable. CTA session/click mismatches are tolerated only up to `max(5 events, 0.1%)`; larger CTA diffs block the affected date. Sales and sales amount diffs are warning-only for this source because confirmed sales live in `wp_kiwi_sales` and are not deleted by landing-session raw cleanup.

The gate intentionally does not run the expensive dimension-level deep compare for every non-accepted candidate date. Hard light totals run for every candidate date. Deep compare runs only for the current retention edge date, the first hard-blocked date for diagnosis, and at most two CTA-warning dates. Sales-only warning dates are not deep-checked.

Audit details are stored on `wp_kiwi_retention_cleanup_runs.gate_results_json`, including coverage mode, requested/effective cutoffs, verified date, candidate dates, deep-checked dates, totals-only dates, skipped deep dates, deep-compare reasons, blocked dates, warning dates, and compact per-summary details.

After a real Session run has durably finalized as `skipped` with `error_code=coverage_gate_failed`, Retention writes a best-effort Operational Incident `retention_cleanup_skipped`. The incident correlates by `retention_cleanup_skip_<source_key>`, references the audit run, and summarizes only existing gate output: stable reason/source, gate status, available cutoffs, first blocked date/cause, verified-through date, and at most three blocking error codes. It does not run another coverage or production query. A later new skip run is `repeated`; the first later real, non-Dry-Run `completed`/`completed_noop` audit closes the open incident once. Event persistence failure never weakens or reclassifies the fail-closed skip.

Disabled Retention, lock-active invocations, Dry-Runs, and `landing_handoff_events` do not create this error incident. Handoff's explicit `coverage_gate_required=false` policy cannot synthesize `coverage_gate_failed`.

## Scheduler and worker

The daily retention cron is only a scheduler. The active recurring hook is `kiwi_retention_cleanup_scheduler_daily`; the legacy unbounded `kiwi_retention_cleanup_daily` hook is cleared during normal scheduling. Each daily pass enumerates `landing_page_sessions`, then `landing_handoff_events`, then `landing_session_engagements` in stable registry order. Every single-event `kiwi_retention_cleanup_worker` job carries its source key so pending jobs are deduplicated per source. An older argumentless worker invocation remains compatible and processes `landing_page_sessions` only.

The scheduler:

1. Marks non-resumable unfinished runs with an `updated_at` heartbeat older than 30 minutes as `failed` with `error_code=cron_timeout_suspected` before looking for an active run. Receipt-safe archive/delete phases remain open so a later worker can reconcile them from persisted SQLite evidence. An existing failed `worker_phase` is retained; an empty phase becomes `stale_unknown`. Each run newly transitioned by this call also writes an idempotent shared operational event; see `operational-events-runbook.md`.
2. Applies the source coverage policy: the Session source runs its gate, while Handoff records `not_required` and keeps the requested cutoff.
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

Runs use `pending`, `running`, `partial`, `blocked`, `completed`, or `failed` statuses. If a scheduler run sees an existing open worker run for the same source, it does not create a second cleanup run; it reschedules that source's worker and records that the active run was rescheduled. An open-run lookup error fails closed and never means that a new run may be created. A `blocked` receipt run remains unfinished and owns its frozen source scope. Normal scheduler and worker invocations may idempotently retry a missing central Receipt Incident, but they never repeat the receipt repair or resume archive/delete work; only the bounded repository-owned recovery path may make the run resumable again.

The audit heartbeat writes only at job boundaries, never per archived or deleted row. Scheduler phases include `coverage_gate_running` for Sessions, `coverage_gate_not_required` for Handoffs, `snapshot_before_running`, `target_key_freezing`, and `archive_pending`. Worker phases include `archive_running`, `archive_corruption_blocked`, `receipt_repair_running`, `receipt_blocked`, `receipt_verified`, `delete_running`, `archive_partial`, `snapshot_after_running`, `finalizing`, `completed`, and `failed`.

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

## Engagement retention and external read-index preparation

Disabled new starts persist their terminal `skipped` audit, completion time and `cleanup_disabled` reason together in one guarded INSERT. There is no follow-up finalization write or worker that could strand this audit and later misclassify it as a cron timeout. Failed insertion retains the source-start retry. Existing active runs keep their frozen settings even if new starts are subsequently disabled.

Transient Engagement audit lookup failures return a scheduled retry without changing durable run state. The daily scheduler applies this before and after the Source lock and when stale-run verification is unavailable; it never creates a new run while the existing audit is unknown. Its deferred job retains an internal source-start intent and the original trigger, so recovery can finish the requested start even when no run had yet been created. Repeated source errors retain that intent; successful source scheduling switches to ordinary workers. A normal worker with no open run stops and cannot create a successor after completion. A retry without an existing run rechecks current enablement; disabling prevents a new active start. An already existing run keeps its frozen settings and is resumed under the existing contract. The existing Worker hook carries the optional internal context, with no new schedule or operator switch. The worker applies the safe retry contract to its initial lookup, locked recheck and recovery after an already committed delete; an unavailable audit never releases more archive/delete work.

`landing_session_engagements` follows the existing Session/Handoff sources, disabled and Dry-run by default under `kiwi_retention_settings.landing_session_engagements`. Keep 14 complete WordPress calendar days after `updated_at`, with a minimum of seven. This is the earliest age release, not a guaranteed deletion date. Any matching live MySQL session protects the row, regardless of its date. Complete snapshots include every CTA, source/attribution, UA and soft-flag field. The exact 2-% / five-CTA-event daily rules and fixed historical exception are in `../architecture/retention-architecture.md`; the existing Session gate is unchanged.

`kiwi_retention_cleanup_runs.gate_results_json` stores the preparation/coverage/archive/after phase, bounded cursors and pending source-ID/hash page, `counts` for before/after observations, age candidates, recent-update protection, session/change deferrals, releases and already-absent receipt rows, and `daily_results` for Main/TK metrics. Dry-run runs preparation, full-day coverage, a final bounded eligibility recheck and final counting, without original archive rows/receipts or deletes. Failed preparation never starts archive/delete.

The Engagement worker owns the durable day, budget and pending-page checkpoint. Its canonical selectors only read; day and pair helpers return progress for the coverage loop to save. Receipt readback takes priority over another source scan. Pending-page construction and the final live-row recheck return results for the archive loop to save at its existing boundaries. The verified SQLite receipt remains available on retry; MySQL delete and its audit cursor commit together.

An absent TK-zone summary requires the independent empty-day proof defined in `../architecture/retention-architecture.md` (Mutable engagement boundary). Do not manufacture historical zero summaries; Main and the existing Session cleanup retain their normal gates.

Inspect `daily_results[date].modes.tkzone.empty_day_proof` alongside `coverage_present=false`: `verified` confirms the complete proof, `blocked_origin`/`reason` explain rejection and `restarts` records archive growth. Partial progress remains in the current day's `tk_zero` checkpoint; source/query failures require investigation before retry.

`counts.rows_before` observes rows up to the run's original `target_max_primary_key`. On entering final counting, a separate `after_target` freezes the then-current maximum source ID; `counts.rows_after` observes rows up to that later bound, including rows created between the two target captures. Both are bounded, resumable observations rather than a single transactionally consistent snapshot. Their difference is not a deletion count; use the independently committed `deleted_rows` audit value. The cleanup target itself retains the original maximum ID, so newly created rows are not archived or deleted by that run. The after-count target and independent receipt/archive/delete cursors remain in the audit. There is no additional last-result option.

Conditional checkpoint writes prevent stale workers from replacing newer durable cursors/counts. Committed receipt pages can resume in smaller prefixes after a budget reduction. A failure during final counting or audit finalization keeps the run open with its committed delete counts; retry resumes it instead of reporting that destructive work never occurred.

Engagement version selection is fixed: count each Source-ID once, using MySQL if present, otherwise the newest verified archive year/numeric generation. The reader uses this rule automatically for complete-day coverage; no new operator setting is needed. New snapshots/receipts go only into the run's frozen current generation. Older differing Engagement snapshots remain stored as historical versions. Missing/invalid newest receipt evidence, technical or corruption errors remain blockers; no silent older-version fallback. Immutable Session copies retain their complete conflict check, and the current full snapshot/receipt/delete guard stays byte-exact. Resumed receipts must still match the original pending snapshot hash. Both committed receipts and uncommitted pending snapshot pages obey a smaller retry budget; a daily session lookup also resumes with a one-row budget. Each conditional audit write retains its own written checkpoint rather than adopting a newer worker's subsequent readback.

Engagement's first archive batch commits source schema, batch identity, full snapshots and receipts together. A new generation becomes visible only after that complete transaction commits and all SQLite connections close. Initial publication requires PHP `link()` and same-directory hard-link support; unavailable support or an already existing target fails closed before any MySQL delete. Temporary `.sqlite.initial-<random>` files are outside archive discovery. Normal failures remove their own temporary file; a terminated process can leave an undiscovered orphan. Do not promote such a file or repair an incomplete existing archive through runtime. Existing generations are updated transactionally in place. The exact initial-publication and retry boundary is in `../architecture/retention-architecture.md`.

Existing archives need explicitly prepared read indexes before enabling the source. Select one canonical archive at a time, with Kiwi Backend active, through the repository artifact:

```bash
wp --require=wp-content/plugins/backend/tools/database/migrations/retention-archive-read-indexes.php kiwi retention archive-read-indexes status --archive=kiwi_retention_archive_2026.sqlite
# apply is mutating: obtain the concrete archive/deployment authorization first
nice -n 15 ionice -c 3 wp --require=wp-content/plugins/backend/tools/database/migrations/retention-archive-read-indexes.php kiwi retention archive-read-indexes apply --archive=kiwi_retention_archive_2026.sqlite
wp --require=wp-content/plugins/backend/tools/database/migrations/retention-archive-read-indexes.php kiwi retention archive-read-indexes status --archive=kiwi_retention_archive_2026.sqlite
```

The artifact runs after `plugins_loaded` and exits before `init`. `status` changes no archive data/schema. Both modes also verify that the Engagement source and cleanup audit use InnoDB, hold the generation lock and respect corruption and immutable-read/WAL gates. Definitions are checked in full: column order, ASC, NOCASE only on both Session pair keys, BINARY on the remaining fields, non-UNIQUE and non-partial. `status` reports missing or mismatched definitions and `replacement_required`. Explicit external `apply` creates missing indexes or replaces only the named approved read index, atomically under the generation lock; an old same-named BINARY pair index must be replaced. Payloads, receipts and the existing source-PK unique index remain intact. Repeating `apply` is a no-op after successful preparation. Free space must cover at least the archive size plus 64 MiB; the small cache and disabled mmap limit memory use. Check restore readiness, space reserve and acceptable lock duration first. Low process priority does not guarantee Hostinger Account/LVE IOPS. Global health checks remain external. Missing or wrong indexes never cause a runtime migration or unbounded fallback scan.

MySQL pair matching and live-session protection retain the existing collations and keys. The archive lookup uses NOCASE for both pair keys with identical comparisons on every `(created_at, _source_pk)` page; original key values stay unchanged. The ASCII-only NOCASE approximation and its Unicode/trailing-space limits are documented in the Retention architecture. Daily tolerances apply to derived statistics, while complete snapshot/receipt/delete verification remains byte-exact, including case-only payload changes.

Activation is a later operator action after a verified successful merge and reviewed release:

1. Obtain one-time fachliche Fraud-/Billing-Freigabe for the 14-day period. Every reduction needs renewed approval.
2. Obtain deployment and external preparation authorization for the named release and actual archives. Deploy with Engagement disabled; complete `status → apply → status` and the existing restore/schema/health gates.
3. Authorize and inspect a Production Dry-run. Check completed preparation, full-day Main/TK proof, historical exception, candidates, deferrals and releases; prove no original archive/delete occurred.
4. Obtain separate explicit authorization to activate the concrete Engagement configuration. This approves the future regularly automatic mechanism once, not each later run.
5. Inspect the first active run's archive, receipt, delete, deferral and cursor evidence. No synthetic Sale/Billing requests are required.

Local tests and a Planner Report do not authorize activation or a shorter period. The historical exception waives statistics proof only. Archive-boundary errors retain the original run and pending receipt for recovery. Follow the recorded post-merge acceptance and let the user decide completion.

## External SQLite archive health controller

Production keeps the repository-owned WP-CLI entry `tools/database/kiwi-retention-archive-health.php`, invoked with `--require` before WordPress loads. Regular verification uses the [controlled VPS runner](#vps-archive-health-runner). The former Production `check` now returns exit `2` and a clear `kiwi-retention-archive-health run --manual` instruction; it performs no scan, changes no safety state or Incidents and never starts the VPS implicitly. The retained Production commands include:

```bash
wp --require=wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health diagnose --archive=kiwi_retention_archive_2026.sqlite --check=quick
wp --require=wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health diagnose --archive=kiwi_retention_archive_2026.sqlite --check=integrity
wp --require=wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health unblock --archive=kiwi_retention_archive_2026.sqlite --confirm
wp --require=wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health unblock --archive=kiwi_retention_archive_2026.sqlite --replacement=kiwi_retention_archive_2026_part_2.sqlite --confirm
wp --require=wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health status
wp --require=wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health status --attempt=<32-lowercase-hex-ID>
wp --require=wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health abort --attempt=<32-lowercase-hex-ID> --confirm
wp --require=wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health freshness
wp --require=wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health freshness --activate --confirm
```

Before enabling code that depends on Pending, a separately approved deployment must inspect the actually configured options table and confirm InnoDB. Prepare **only a missing** `kiwi_retention_archive_health_state` row externally, as raw compact JSON with autoload disabled:

```json
{"format_version":1,"revision":0,"activated_at":null,"active_attempt_id":null,"last_confirmed_attempt_id":null,"last_freshness_completed_at":null,"last_attempt_sequence":0}
```

Use the actual `$wpdb->options`/configured prefix, a parameterized insert and a direct database readback; confirm the exact JSON and non-autoload value. Do not serialize the JSON, replace an existing row, reset revision/attempt pointers, or mark activation/freshness as completed during preparation. Existing state requires inspection and preservation. Runtime performs no preparation or migration and fails closed if the row is missing, incompatible, contradictory or unreadable. Attempt options are created only by a confirmed start, stay at most 16 KiB and are not pruned automatically. This document describes preparation; it does not authorize a production database change.

`status` reads activation, the active attempt, the last accepted archive finding, the last completed daily execution, the current target's actual OS/Pending/corruption protections and open Retention Operational Incidents. `status --attempt=ID` also returns that exact historical attempt. It does not scan the archive, initialize state, expire Pending, reconcile gates or mark a daily run as executed. A null daily-completion value means no completed daily run has been recorded. State times are UTC; the freshness calendar is Europe/Berlin.

An open attempt pauses generation-bound Session, Handoff and Engagement archive writes/receipt repair/deletes, including resumed deletes. Normal Pending means wait for its result or perform the separate confirmed abort when abandoning that attempt. `abort` first stores invalidation, then secures the existing Availability error, then closes the attempt and removes Pending. Missing confirmation, invalid ID, uncertain storage or an already stored corruption result cannot release it. A lost successful acknowledgement can be checked with `status --attempt=ID`; repeating the same completed abort preserves its original closure time. A technical closure records `health_run_failed` (or existing `health_child_timeout`) with sanitized phase/message context.

Source-process death and elapsed time do not clear Pending. Safe abort does not release a still living source's OS lock or clear any corruption protection. `unblock`, including replacement, refuses any open attempt: finish its result or safely abort it first, then follow the existing separately confirmed full read-only integrity verification. `diagnose` remains read-only.

### Independent daily freshness and manual control

The following commands prepare the approved operating procedure from [Spec #157, supplement 8](https://github.com/mpetrovic-hub/backend/issues/157#issuecomment-6798187776); this code change does not deploy them, create or execute a cron, or activate Production. The absolute paths were recorded read-only on 11 November 2026 and must be confirmed for the separately approved deployment. State must already be externally provisioned. Initial `freshness --activate --confirm` stores the original activation and completes one daily check; repeating it never restarts the eight-day window. Ordinary `freshness` before activation fails without initializing state.

First activation, after that separate approval:

```sh
/usr/bin/php -d log_errors=1 -d display_errors=0 -d error_log=/home/u367252972/domains/kiwimobile.de/public_html/backend/wp-content/debug.log /usr/local/bin/wp --path=/home/u367252972/domains/kiwimobile.de/public_html/backend --require=/home/u367252972/domains/kiwimobile.de/public_html/backend/wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health freshness --activate --confirm
```

Prepare exactly one independent Hostinger cron, daily **05:00 UTC**, schedule `0 5 * * *` (06:00 Berlin winter / 07:00 summer), with this complete command:

```sh
/usr/bin/php -d log_errors=1 -d display_errors=0 -d error_log=/home/u367252972/domains/kiwimobile.de/public_html/backend/wp-content/debug.log /usr/local/bin/wp --path=/home/u367252972/domains/kiwimobile.de/public_html/backend --require=/home/u367252972/domains/kiwimobile.de/public_html/backend/wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health freshness
```

The daily job reads stored health data and performs no archive transfer, SQLite scan or weekly retry. Day zero is the Berlin date of initial activation or the last fully accepted VPS `ok`/corruption finding. At date + 8 it records `retention_archive_health_unavailable/error` with `health_check_overdue` and the message `Seit mindestens acht Kalendertagen liegt kein bestätigter Archivbefund vor.` It retains the shared Availability lifecycle; overdue alone does not block retention. A completed daily job returns exit `0`, with JSON `result=ok` or `result=overdue`. That exit confirms the monitor execution, not archive health. Technical failure returns exit `2`, writes a short sanitized PHP diagnostic and cannot acknowledge a daily completion. If only the final commit/readback acknowledgement was lost, inspect the actual stored status; never compensate by resetting the timestamp or activation.

Manual operating control uses the same full bootstrap:

```sh
/usr/bin/php -d log_errors=1 -d display_errors=0 -d error_log=/home/u367252972/domains/kiwimobile.de/public_html/backend/wp-content/debug.log /usr/local/bin/wp --path=/home/u367252972/domains/kiwimobile.de/public_html/backend --require=/home/u367252972/domains/kiwimobile.de/public_html/backend/wp-content/plugins/backend/tools/database/kiwi-retention-archive-health.php kiwi retention archive-health status
```

Read `state.activated_at`, `last_confirmed_attempt` (ID, outcome and original `accepted_at`), `state.last_freshness_completed_at`, `state.active_attempt_id`, target protections and `open_incidents` separately. Compare the daily completion with the last expected 05:00 UTC execution; a missing or old timestamp is not evidence of a successful monitor even when no errors are listed. Check that exactly one daily cron exists with the command above, and independently inspect the VPS weekly timer. An absent weekly finding and an absent daily completion are distinct gaps. If the daily invocation failed, inspect the existing `/home/u367252972/domains/kiwimobile.de/public_html/backend/wp-content/debug.log` for `Archive health freshness did not complete:` and the command's JSON reason. Resolve the underlying cause before retrying; preserve activation and historical findings. Open errors use the latest event per correlation with `raised`/`repeated` and severity `error`/`critical`; a subsequent matching `resolved` closes that error without erasing history. Accepted corruption can be fresh while remaining critically blocked.

The daily monitor remains independent while weekly operation is disabled or a functional check fails. It sends no automatic email, creates no Odin issue, and adds no notifier or supplementary test/week schedule. Actual deployment, activation and later operating acceptance remain separate tasks.

### Existing local diagnosis and recovery

`diagnose` accepts an exact discovered archive basename and is strictly read-only: path-like input is rejected, and it neither raises/resolves Incidents nor changes safety state. `unblock` applies the same basename rule, always requires `--confirm`, refuses open Pending, and completes its own read-only `integrity_check` before recovery effects. A matching corruption sentinel or open Corruption Incident must already exist on source `A` and is revalidated under its generation lock. A healthy archive cannot enter recovery or terminalize resumable runs.

Without `--replacement`, confirmed recovery verifies and re-enables `A`. With `--replacement=B`, `B` must be distinct, must be the generation cleanup would actually select, and must have no independent Corruption gate; recover B separately first if necessary. This permits a frozen prior-year A to use the active current-year B. After verifying B, recovery writes its replacement-transition marker bound to A, terminalizes unfinished runs bound to A, clears A's corruption sentinel, then resolves A's Corruption Incident. B remains blocked until that resolution succeeds. The transition marker permits only its matching final-clear retry and is never corruption evidence for B. Workers re-read their audit row under the generation lock and cannot continue or resurrect a terminalized run. No command creates a replacement archive.

Diagnosis/recovery prints one compact JSON line containing `schema_version`, `command`, `result`, `reason_code`, `archive`, `check`, `started_at`, `finished_at`, and `duration_seconds`, with optional `incident_action`, `write_blocked` and `child_running`. The retired `check` adds its operator instruction as `message`. No filesystem paths, raw exceptions or credentials are emitted. Exit `0` means definitive success (`ok`, `unblocked`); exit `1` means completed corruption, lock deferral or inconclusive timeout; exit `2` means invalid input, retired regular start, bootstrap, discovery, persistence or read-only child failure. The newer status/result/abort protocols retain their own explicit schemas and acceptance meanings described above and below.

The retained supervisor starts one dedicated child with `KIWI_RETENTION_ARCHIVE_HEALTH_TIMEOUT_SECONDS` (default/maximum `7200` seconds, accepted range `30..7200`, including corruption handoff). The child acquires the same exclusive non-waiting generation lock as cleanup before opening the exact SQLite file using an immutable read-only URI. It rechecks replacement/corruption protection under the lock. Diagnosis remains read-only; only confirmed recovery may persist corruption protection from its completed verification. Non-empty WAL or rollback journal fails closed with `sqlite_wal_not_empty` or `sqlite_rollback_journal_not_empty` before PRAGMA execution. Checks create no SQLite sidecars.

The supervisor never terminates a started child at its timeout boundary, including before readiness: it returns `health_child_timeout`, `child_running=true`, and allows the child to finish its lock/gate protocol. A surviving child still prevents archive writes and MySQL deletion. Timeout and exception are inconclusive, never corruption evidence. Only completed non-`ok` verification can create a corruption gate. The child persists its sentinel before the Incident; if sentinel persistence fails, it retains the lock until the parent acknowledges a durable Corruption Incident. When both gates fail, the command fails and the child retains protection while attempting the sentinel. Reconciliation rechecks both sources under the generation lock, so an older corrupt recovery result cannot reopen verified later recovery. A sentinel-only recovery must first reconcile its Incident and cannot clear the sentinel if that audit fails. Unreadable/contradictory protection remains fail-closed.

The supervisor still requires the [native read-brake bundle](../../tools/database/archive-health-read-brake/README.md) in the child only. Before a full target PRAGMA, that same child proves two complete synthetic fixture checks at 700/350 covered 4-KiB read units/s through its actual PDO/SQLite/VFS path, then locks the target rate at 700. Missing/incompatible/ineffective loading, unsupported reads or lost binding is a technical failure; no unbraked fallback exists. Run at most one full manual Hostinger archive check at a time across generations: separate generation locks do not combine read budgets. This local pacing proof is not a Hostinger Account/LVE bound.

Confirmed corruption does not rename, quarantine, repair, copy, replace or switch an archive automatically. Preserve its archive and safety evidence. Repair or build replacement data outside runtime under the separately approved procedure, then use the exact confirmed `unblock` command; its full integrity verification remains mandatory. If a child survives timeout, inspect its process and lock evidence. Never remove a lock file, kill unrelated PHP processes or erase Pending/sentinels to force progress.

### Regular Hostinger check retirement evidence

Repository retirement for [#165](https://github.com/mpetrovic-hub/backend/issues/165) is the code evidence for the [post-deployment checklist](https://github.com/mpetrovic-hub/backend/issues/157#issuecomment-6798187776). The former controller `check` orchestration is replaced with a bounded refusal, including quick/integrity/absent/unsupported modes. Its check-only scheduled-bootstrap Availability fallback is removed. The shared PHP entry and generic sanitized bootstrap output remain for status, SSH, freshness, diagnosis and recovery. Old regular starts cannot scan the canonical archive, resolve an Incident, clear Pending or invoke a fallback VPS run.

The old `/home/u367252972/bin/kiwi-retention-archive-health-cron.sh` is a host-owned wrapper; no tracked repository copy exists. Its historical purpose was the old Production `check --check=quick`. No host script was deleted by this code change. The cron deletion/process/lock observation from **9 November 2026** is historical, not a current deployment check. During the separately authorized deployment, inspect the actual cron inventory, wrapper callers/dependencies, old parent/child processes and source lock. Remove only confirmed unused, old-automatic-check-only host artifacts. Never recreate the old cron or restore it as a fallback.

Retained artifacts are the shared PHP entry, supervisor and bootstrap recorder; the complete native source/library/manifest/fixture/build/evidence bundle; generation locks, Pending state, corruption/replacement gates, retention protection and event administration. Canonical archives, receipts, operating evidence and legacy quarantine/state files are not obsolete start scripts. Inventory any legacy markers separately and preserve them until their protection and disposition have been explicitly reviewed; this retirement introduces no automatic migration or deletion.

Local synthetic regressions verify refused starts, unchanged durable protection, the new result/failure lifecycle and retained diagnosis/recovery/native behavior. Their success does not verify host non-use or activate operation. Installation, the bounded functional check and weekly activation remain separately approved steps. Regular runs use `kiwi-retention-archive-health run --scheduled` or an explicit `run --manual`, through the same controlled VPS service. The independent daily freshness cron remains separate.

For an authorized stop or rollback, suspend weekly starts and inspect the exact active service/source processes, locks, Pending and accepted result before intervention. Preserve output, copy/evidence and Incidents. Restore only a consistent reviewed release that understands all effective protection, including Pending; older code must not bypass those gates. Keep source/helper/supervisor/native library/manifest/fixture together and verify their pinned checksums and PHP/PDO compatibility. No global preload or runtime compilation is permitted. Follow the [VPS recovery procedure](#vps-archive-health-runner) for uncertain attempts. Resume only after the cause is resolved and the separately required functional/activation approval is satisfied; do not install the retired Hostinger schedule or add an unrequested full archive/load test for retirement.

## Restricted SSH export access

The repository prepares access artifacts only; it does not create users/keys, connect to either host or change authorized keys. Deployment, the bounded functional check and weekly activation remain separately approved steps. The full request and stream contract is in the [architecture](../architecture/retention-architecture.md#restricted-ssh-export-protocol).

During a later authorized deployment:

1. Use a dedicated nonprivileged VPS user `kiwi-archive-health` and a dedicated key. Its private key and pinned host-key file must be outside webroots and accessible only to that user and administrators. Do not grant this user general administrator or unrestricted Production access.
2. Independently confirm the Hostinger host-key fingerprint, then install the exact verified key in the user's configured `known_hosts`. A fresh `ssh-keyscan` alone is not an independent confirmation. Use [ssh_config.example](../../tools/database/archive-health-access/ssh_config.example): strict host-key checking, dedicated identity, batch authentication, no compression, no TTY/forwarding and a 15-second connect budget. Replace all placeholders with verified deployment values.
3. Install the [fixed forced-command template](../../tools/database/archive-health-access/production-forced-command.sh.example) at a protected absolute Production path, with the verified WordPress root and PHP/WP-CLI paths. Only the deployment owner may modify it. It runs the existing health preload and `kiwi retention archive-health ssh`, before normal WordPress `init`. It never evaluates the requested command. No SSH client environment variables that can alter shell/PHP loading may be accepted by the server; the wrapper also clears loader override variables.
4. Use the [authorized-key template](../../tools/database/archive-health-access/authorized_keys.example) to restrict this key to the verified VPS egress IP and that exact forced command. `restrict` disables PTY, forwarding and user startup scripts. Check these restrictions through the separately authorized deployment procedure; a template is not proof of a configured key.
5. Verify compatible deployed code and externally prepared options state first. All three Retention writers must enforce Pending before enabling export. Synchronize the approved source rate/time values with the VPS configuration; remote requests cannot raise them. Keep the weekly timer off until its separate activation approval.

For this access, the SSH remote command is exactly one word (`status`, `export`, `result` or `fail`), with one bounded versioned JSON object on stdin and stdin closed after it. A status request is `{"schema_version":1,"attempt_id":null}`. Specific status replaces null with the exact ID. The ordinary operator `status`, confirmed `abort`, `diagnose` and `unblock` commands remain separate; they cannot be reached through this key. Use only the installed fixed connection options, independently pinned host key and dedicated identity.

The source paces reads at 4 MiB/s, computes SHA-256 during those same reads and holds the real generation lock through the entire stream. The default transfer deadline is 45 minutes. Failed targets, size changes, held locks, protection gates and relevant sidecars produce no valid snapshot. The receiver must verify the saved copy, framing and successful SSH exit before any SQLite check. Production's stored `transfer_complete` proves its own source pass and does not replace receiver verification or the completed `quick_check` result.

For a controlled copy error, `fail` uses the common safe closure of that exact attempt; it does not clear corruption or replace any archive. Failed persistence leaves Pending. A crashed or uncertain predecessor remains protected for manual status/process/file-use reconciliation; no timeout, serial invocation or cleanup job automatically frees it. Stop/reap related receiver processes before removing their copy, and require a confirmed failure/result closure first. A source process can still hold its OS lock after a safe technical closure until that actual process ends.

The one-time activation-failure message from the approved plan is supported unchanged: `{"schema_version":1,"attempt_id":null,"phase":"activation","reason_code":"health_run_failed","message":"Wochenbetrieb nicht aktiviert: <konkreter technischer Grund>"}` via SSH action `fail`. It creates only the existing Availability event and preserves the accepted result, freshness and any Pending/corruption protections. No additional archive is fetched. Use it for a concrete technical activation failure after an accepted test-ok. Waiting for the operator's separate activation approval is not a technical failure. If the connection or event store is unavailable, retain the actual command diagnostic; do not claim an event was saved.

## Result acknowledgement and recovery

The `result` reply confirms stored processing, with the exact attempt ID, generation, fingerprint, outcome, original acceptance time and remaining protection. Exit 0 with `outcome=corruption` is a confirmed corruption finding and keeps the affected generation protected. Only an explicit accepted `outcome=ok` proves that complete copy check was healthy; existing independent protection can still prevent retention.

After a missing or truncated reply, query the same attempt through restricted `status` or the operator's `status --attempt=<ID>`. Confirm `status=closed`, the exact submitted generation/bytes/SHA-256/transfer proof/outcome/fingerprint and nonnull original `accepted_at`, as defined in the [result contract](../architecture/retention-architecture.md#accepted-external-results-and-acknowledgements). An open outcome is still incomplete processing. A newer current target or confirmed pointer does not replace this attempt-specific check. Redelivering the identical result confirms an already completed acceptance without new timestamps, events or gate changes.

Failed required state/event persistence keeps the attempt Pending; a lost final commit reply may instead be resolved by reading its actual closed record. Do not edit options, remove sentinels or force a new attempt to clear uncertainty. A stored corruption finding cannot be replaced by `fail` or `abort`. Follow the existing explicit repair/replacement procedure for its protection. Manual recovery requires no open attempt anywhere, including another generation; its final effects serialize against new starts without holding the database lock over the full integrity scan. Old accepted corruption redelivery cannot undo completed manual recovery.

## VPS archive-health runner

The [prepared runner and artifacts](../../tools/database/archive-health-vps/) implement the separate VPS copy check. Their presence does not install permissions, configure either host, run a live check or activate a timer. The approved deployment, bounded functional check and weekly activation remain separate operations. The existing Production status/abort/diagnosis/recovery commands remain available.

During an authorized deployment, install `kiwi_retention_archive_health.py` as root-owned `/usr/local/bin/kiwi-retention-archive-health` (mode `0755`), with Python 3.11+ and its SQLite support. Use the dedicated nonprivileged user/group `kiwi-archive-health`, with no general sudo group. Install the reviewed service/timer, strict JSON configuration, exact sudoers rule, tmpfiles entries and logrotate configuration from that directory in their ordinary system locations. The service/config/policy files must remain root-controlled. Validate actual unit and sudoers syntax before applying anything. The exact sudo permission permits only `/usr/bin/env -i PATH=/usr/bin:/bin /usr/bin/systemctl --no-block --job-mode=fail start kiwi-retention-archive-health.service`; no wildcard, arbitrary arguments, `SETENV`, Polkit manage-units rule or privileged runtime resource setter is needed. `env -i` applies even when the user invokes the delegated command directly.

The data directory is `/var/lib/kiwi-archive-health`, the log directory `/var/log/kiwi-archive-health`, both owned by the dedicated user with mode `0700`. Its key is normally `/etc/kiwi-archive-health/id_ed25519`, mode `0600`; the root-owned pinned host keys are normally `/etc/kiwi-archive-health/known_hosts`, mode `0640`, group `kiwi-archive-health`. The containing credentials directory is root-owned `0750`, group `kiwi-archive-health`. The strict common settings and initial values are in the [configuration reference](configuration-reference.md#vps-archive-health-configuration). Run the offline `render_resources.py` as this user from the reviewed artifact directory, inspect its output, then install it as `/etc/systemd/system/kiwi-retention-archive-health.service.d/resources.conf`. This is a preparation step and does not activate a service. Apply resource changes only during authorized deployment and inspect the actual cgroup-v2 values/device before functional acceptance. The runner rejects unsupported storage topology and unapplied limits.

The operator invokes the following as `kiwi-archive-health`; both entries dispatch into the same service:

```sh
kiwi-retention-archive-health run --manual
kiwi-retention-archive-health run --scheduled
```

Outside the service, JSON `result=started` and exit `0` mean the service claimed this request, not that the archive is healthy. Inspect the private log and Production `status --attempt=<ID>` for completion. Within the service, `result=accepted` is a stored completed finding; inspect `outcome`, because accepted corruption is not healthy. Exit `2` is confirmed technical failure; exit `3` is unconfirmed processing. The timer directly runs the same controller as scheduled. Its calendar is Wednesday 04:00 Europe/Berlin, without catch-up (`Persistent=false`) or restart (`Restart=no`). There is no weekly marker; a serial extra invocation faces the same ordinary protections.

The `.dispatch.lock`, `.run.lock` and bounded `.start-request.json` files are private control records. A handoff identifies its live requester by boot/PID/start time and its requested run; caller-supplied service environment variables confer no trust. It is acknowledged once and removed by that caller. A stale/malformed handoff or an unrelated/leftover file prevents a new full run. Before copying the service reads actual resources, local predecessors, Production status/current target/size/locks/Pending/protection and actual destination free space. It checks reserve again only before stored-file verification and before SQLite. All copy/check helpers share the service's combined CPU and I/O budget.

Each attempt owns `<ID>/archive.sqlite.partial`, `request.json`, and later `archive.sqlite` (read-only) and `result.json`. No contents or external program diagnostics enter the log. Complete transfer proof and successful SSH exit precede verification of the stored bytes; only then is the verified file renamed for a complete read-only `quick_check`. After safe acceptance of either `ok` or corruption, or a confirmed technical closure with all related local processes ended, known temporary files and the empty attempt directory are removed. An unlink failure only writes a short warning in `/var/log/kiwi-archive-health/run.log`; it neither changes an accepted result nor creates another Incident. Normal Linux logrotate rotates at `1048576` bytes and keeps four older versions. No custom rotation or age-based archive deletion runs.

If startup resources/space/predecessors are unsuitable inside the service, it immediately submits the existing `fail(null)` Availability message without starting an attempt, when the restricted control path works. If the service cannot start or the exact start permission is unavailable, the outside dispatcher has no controlled SSH path: it reports **unconfirmed** locally and does not claim a saved Production error. Similarly, an unavailable SSH reply is not proof of persistence. There is no fallback SSH execution outside the service or additional reporting system.

An archive can grow between status and export. A complete matching source rejection that explicitly proves no attempt-storage call was made is handled as a prestart failure: `fail(null)` secures Availability, then the unused local files are removed. Lost or malformed rejection replies remain uncertain and keep their evidence; an absent attempt row alone never authorizes cleanup or a new full copy.

For an uncertain or interrupted run, prevent another scheduled start during the separately authorized intervention, then inspect the exact service's process/cgroup state, local lock ownership, Production `status --attempt=<ID>` and related source lock/process state. Do not delete or invalidate based on age or a missing process alone. If status proves the exact saved acceptance, preserve its outcome/gates and verify no process still uses the local copy before removing only that attempt's files. If the attempt must be abandoned, first stop/reap its related local processes, use the existing confirmed Production `abort --attempt=<ID> --confirm`, and read back its safe closure. A source process can continue to hold its actual lock until it exits. Only after closure and file-use verification remove the exact abandoned copy/evidence and stale handoff, never shared locks, unrelated files, canonical archives or corruption sentinels. Then a new explicit manual run gets a new ID. Do not edit options or use `fail` to overwrite stored corruption.

If manual repair/replacement loses its database connection during final effects, it refuses completion and retains/restores protection. Inspect the existing generation locks and gates before retrying. If both the file gate and Incident persistence are unavailable, the current recovery process keeps its generation lock while restoring protection; do not kill that process or remove its lock just to clear a wait. Restore the existing storage/database paths, confirm protection and Pending state, then use the ordinary explicit recovery command.

Synthetic local tests exercise actual small SQLite/PHP/MariaDB results, parser tampering/truncation, process deadlines, loss of acknowledgements and cleanup. Offline unit/calendar/policy checks do not prove live cgroup enforcement, host access, actual storage capacity, DST execution or production behavior. Those checks remain on the separate post-deployment checklist; no activation acceptance is inferred from local test success.

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
3. After a scheduler run, confirm at most one source-keyed `kiwi_retention_cleanup_worker` event is pending for each source that has work.
4. Confirm `wp_kiwi_retention_cleanup_runs` shows `pending` or `partial` worker state with frozen `target_max_primary_key`.
5. Confirm Sessions use the effective cutoff returned by their coverage gate and Handoffs use the full requested 21-day cutoff with `gate_status=not_required`.
6. For a finalized Session `coverage_gate_failed` run, confirm one matching `retention_cleanup_skipped` raised/repeated event references the audit run and contains bounded gate diagnostics; never trigger a failure deliberately in production.
7. Confirm archive evidence exists before MySQL delete.
8. Confirm every MySQL delete is preceded by a persisted exact SQLite receipt and that receipt/delete cursors match after completion.
9. Confirm receipt-safe archive/delete phases remain resumable while non-resumable stale runs are marked `failed`.
10. Run both explicit archive-health `diagnose` modes, then verify the single external `check` invocation and one-line JSON/process-exit monitoring.
11. Confirm every non-definitive scheduled check raises or repeats one availability Incident unless a one-shot `archive_lock_active` freshness probe proves that specific lock evidence stale; only a completed non-`ok` PRAGMA sets the corruption block, and no archive is replaced automatically.
12. For compaction, dry-run first and compare eligible rows plus before/after byte estimates before enabling active mutation.
