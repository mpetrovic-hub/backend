# Retention Architecture

## Purpose

This document defines the stable data-safety contract shared by every retention source, the SQLite archive, the cleanup worker, the external health check, Operational Incidents, and manual recovery. Operational commands and deployment steps live in `../operations/retention-runbook.md`.

## Shared archive and source boundary

Retention uses one configured SQLite archive root and generation file, with a separate archive table and Source-ID receipt evidence for each registered retention source. The shared archive and safety services stay source-neutral. Each source owns only its source table, cutoff column, eligibility rule, retention age, coverage rule, archive mapping, and cleanup-run audit context.

A future retention source must provide a normalized registry contract, a stable primary key, a deterministic archive mapping, source-specific coverage tests, and crash/regression evidence. Aggregator-, country-, flow-, or table-specific behavior must not leak into the shared lock, health, receipt, or delete gates.

The registered runtime sources are deliberately different only at their source boundary:

- `landing_page_sessions` retains 14 complete days by default and requires the existing Main/TK-zone summary coverage gate.
- `landing_handoff_events` retains 21 complete days by default and has `coverage_gate_required=false`. It neither reads landing sessions nor treats the landing summaries as deletion evidence.
- `landing_session_engagements` retains 14 complete WordPress calendar days after `updated_at`, with a minimum of seven. Any live MySQL session with the same `(landing_key, session_token)` protects the mutable engagement from this cleanup's archive and delete. Its original `created_at` day owns its separate Main/TK-zone engagement coverage.

All sources are disabled and dry-run by default, use a minimum explicit retention age of seven days, and share the same frozen-scope, receipt, lock, corruption, cursor, archive, and delete contracts below. Scheduler order is Sessions, Handoffs, then Engagements. The existing Session coverage policy remains unchanged.

## Mutable engagement boundary

`Kiwi_Retention_Engagement_Worker` owns the additional source policy and reuses the shared source/worker locks, generation lock, corruption coordinator, audit repository and SQLite receipts. Candidate counting, full-day coverage, Dry-run eligibility and final counting are checkpointed in the existing cleanup audit, not an extra last-result option. Each call uses the configured 50,000-row / 60-second soft budget; every data page and archive/delete batch contains at most 500 rows. Original archive/delete work starts only after all preparation gates pass. The scope freezes a WordPress-calendar cutoff and maximum source primary key; newly changed rows retain the strict `updated_at < cutoff` protection.

The Cleanup dispatcher and Engagement worker share `Kiwi_Retention_Worker_Support` for their common dependencies, transient locks, stale-run audit handling, run identifiers and final audit helper. This support base defines no source-start or worker flow. Each concrete worker owns its complete source-specific flow; Engagement does not inherit the Session/Handoff cleanup implementation or its growth-snapshot and coverage-gate dependencies. The existing Cleanup entry points continue to delegate Engagement calls using the same configured service instances.

For each affected complete original day, combine live and archived engagement Source IDs and count each ID once. Live MySQL wins; otherwise choose the verified complete snapshot from the newest archive by validated year and numeric generation (2027 before 2026, part_10 before part_2). File mtime and engagement updated_at do not choose the version. Include recently updated, session-protected and previously archived engagements. Older differing engagement snapshots are allowed historical versions and remain unchanged. New snapshots are written only into the current generation frozen for the cleanup run; there is no cross-generation rewrite or configuration switch. Canonical Session pair/day membership comes from live and archived sessions. Main counts a matching pair once; TK additionally applies the existing normalized PID allowlist before combining membership. An orphan engagement adds no canonical session. Sum only `page_loaded_sessions` and the CTA1/2/3 session and click-event metrics from each mode's durable summaries, using the current TK PID-set hash. No dimension-group reconstruction or special equal-timestamp tie policy is required.

Live pair membership and live-session protection keep native MySQL equality under the existing column collations. MySQL collations, unique keys, Session/Summary processes and original identifiers do not change. Only the new archive pair lookup uses SQLite `COLLATE NOCASE` on both `landing_key` and `session_token`, consistently across keyset pages and pair membership. Equivalent ASCII variants count once per pair/day; PID filtering precedes TK membership. No byte-exact pair filter may discard a NOCASE match. NOCASE folds ASCII letters but does not reproduce all MySQL Unicode or trailing-space rules. This documented limitation applies only to derived archive statistics, governed by the existing daily tolerances; it introduces no Unicode normalization, extra key columns, special-case indexes or new character gate. Complete snapshots, receipts and guarded deletes remain byte-exact and NULL-safe.

For each day, metric and mode, Page-loads pass exactly when `difference <= 0.02 * max(abs(raw), abs(summary))`; CTA pass when `difference <= max(5, 0.02 * max(abs(raw), abs(summary)))`. Bounds are inclusive and do not round up to admit an extra event. Missing daily coverage fails even for zero/zero. Higher deviations, query errors, unreadable archives, missing exact read indexes, invalid receipts and contradictory complete immutable Session snapshots block release. A faulty newest engagement version is never hidden by falling back to an older one. The audit retains each raw value, summary value, absolute/percentage difference, allowance and decision. The fixed historical exception covers only engagement `created_at >= '2026-04-23 00:00:00' AND created_at < '2026-07-06 00:00:00'`; it bypasses statistical proof only. Age, session, snapshot, receipt and corruption protections still apply.

`Kiwi_Retention_Archive_Reader` validates canonical paths inside the configured root, source/table identity and Source-ID receipt evidence. It reads immutable SQLite under the existing non-waiting generation lock and corruption gate, refusing non-empty WAL/journal state. Filenames do not establish contained data years. Bounded keyset pages verify complete engagement snapshots/receipts and compare conflicting immutable Session copies across all mapped fields, not just the five counting fields. The Session covering index is `(landing_key COLLATE NOCASE ASC, session_token COLLATE NOCASE ASC, created_at COLLATE BINARY ASC, _source_pk COLLATE BINARY ASC, pid COLLATE BINARY ASC)`. The Engagement day index is `(created_at COLLATE BINARY ASC, _source_pk COLLATE BINARY ASC)`. Both are non-UNIQUE and non-partial. Pair lookups constrain the original day and continue after `(created_at, _source_pk)` with at most 500 rows. Existing archives are prepared only by the external artifact; indexes on a genuinely new source table belong to its initial archive schema. Runtime rejects wrong definitions without repairing or scanning around them.

Before archive commit, re-read current eligibility and session protection. Mutable re-archive replaces every mapped source field of the same `_source_pk` and binds `_archive_batch_id` to the current Source-ID receipt. The first `_archived_at` and old receipt/batch history remain intact. Missing or mismatched current receipt evidence blocks reading even when an older receipt survives. Snapshot, receipt and bounded-page insert/duplicate counter increments commit together; replaying an existing receipt adds no counts. Receipt readback classifies at most 500 IDs against same-source/table receipt history using the existing batch/Source-ID primary key, without recounting the growing complete run. A bounded pending page stores only source IDs, hashes, cursor and counts in the existing audit before SQLite work. Reopen and verify the current receipt and complete stored snapshots before delete, including the original pending snapshot hash on every resumed receipt. A receipt for an older payload cannot release a newer version even when that newer archive and live row agree. The delete additionally requires the current complete row to match every archived field, NULL-safely and byte-exactly for text, retain the age bound, and atomically lack a matching live session. Changed or newly protected rows stay in MySQL and are audited.

The Engagement source and cleanup audit require InnoDB, checked by the external archive-preparation artifact. Delete and its independent audit cursor/counts commit in one short MySQL transaction. Failed audit persistence rolls back delete while retaining the SQLite receipt. Interrupted pending pages resume exactly once, including session/change deferrals; a smaller follow-up budget verifies and consumes the pending receipt prefix without abandoning or recounting the remainder. Worker state is reread after acquiring the worker lock and under the generation lock. All Engagement audit progress/finalization writes compare the previously observed checkpoint atomically against the existing worker number, gate JSON, receipt/delete cursors, counts and frozen path/batch; stale writes are rejected without new schema. Lock deferral does not write old progress. After uncertain commit acknowledgement, re-read durable audit state instead of writing an older cursor back. Post-delete counting/finalization faults retain the open checkpoint and actual delete counts for retry. A committed receipt stays available for recovery and is never replaced by a health outcome.

`upsert_event()` idempotently creates or locks the existing unique Landing/Session key, reads under `FOR UPDATE`, applies the existing event/evaluator rules, updates and commits. Failure rolls back. It accesses no archive in the click/Sale path. A waiting writer can recreate a missing row after cleanup instead of losing an update against a vanished ID.

Both committed receipts and uncommitted pending snapshots resume in prefixes bounded by the current worker budget. A resumed daily session lookup verifies the engagement signature and continues its saved cursor, so even a one-row budget makes progress without duplicate counting. After an audit write, the worker retains exactly the checkpoint it wrote; it never adopts a later readback while keeping older local state.

An Engagement source-audit outage retains the requested source-start intent and normalized original trigger in the existing Worker cron job. Only that explicit internal context re-enters `run_source()`, which resumes an existing run or checks current configuration for a new start. Existing runs retain their frozen settings; current enablement controls new starts. Repeated source errors retain the context; a successful source step queues ordinary workers. Start retries and ordinary worker jobs deduplicate separately, so an already queued ordinary worker cannot discard the start intent. The existing hook accepts the optional second argument; old jobs and other sources keep their existing dispatch. An ordinary worker with no open run stops, including after completion. No new hook, schedule, configuration, audit schema or last-result option is introduced.

Engagement run creation also takes a non-waiting MySQL named lock scoped to the database, audit table and source before its final open-run lookup. The guarded INSERT requires that same connection to still own the lock; a reconnect or lost lease inserts nothing, and a stale insert ID cannot count as success. Parallel starts resume one audit run. Target-bound reads, audit insertion and lock failures preserve the explicit source retry. An advisory source transient without a durable run also retains that retry, including after a terminated creator; waiting attempts neither clear nor extend another creator's transient. Once it clears or expires, the original start can proceed under the named lock. An already stored run queues its ordinary worker directly. The lock is released when source preparation exits; other sources retain their existing creation path.

For immutable Sessions, a live MySQL row and any archived copy of the same Source-ID must have byte-matching complete mapped snapshots, just as copies across archive generations must. The Cleanup reads the full live row by primary key and compares duplicate copies, including fields outside the five counting fields. A mismatch blocks coverage; a matching live/archive duplicate counts once. Pair lookups and their covering index retain the five counting fields, and the mutable Engagement version policy remains separate.

## Archive-before-delete contract

For every source and every cleanup chunk:

1. freeze the eligible source scope and stable target primary key;
2. commit archive rows and the exact Source-ID receipt in one SQLite transaction;
3. reopen and verify that persisted receipt and its matching archive rows;
4. persist the archive cursor and verified receipt state;
5. recheck the corruption safety gate;
6. delete only receipt-backed source rows that still exist;
7. persist the independent delete cursor and audit state.

Receipt commit, partial MySQL delete, or later finalization failure remains resumable under the same cleanup run and separate archive/delete cursors. A health outcome never substitutes for receipt evidence.

## Lean archive-health capability

Archive health has one purpose: run an explicitly selected SQLite read-only check under the same generation lock used by cleanup and translate that result into a compact operational outcome. It does not own a calendar, retry campaign, annual campaign, notification policy, archive lifecycle, or replacement workflow.

The public WP-CLI surface has exactly three modes:

- `check`: automatically schedulable check of the active archive; it may maintain availability and corruption Incidents and the corruption write block.
- `diagnose`: explicit read-only check of one discovered archive; it never changes safety state or Incidents.
- `unblock`: explicitly confirmed manual recovery; it runs `integrity_check` before changing any safety state.

## Components and boundaries

- `Kiwi_Retention_Archive_Health_Service` is a thin facade over the controller.
- `Kiwi_Retention_Archive_Health_Controller` resolves the target, interprets supervised results, and coordinates Operational Incidents and manual recovery.
- `Kiwi_Retention_Archive_Check_Supervisor` owns the bounded child process and accepts corruption only from a completed non-`ok` PRAGMA result.
- `Kiwi_Retention_Archive_Health_Read_Brake` proves the child-only native brake through the actual PDO/SQLite path before opening the full target. Two complete synthetic fixture scans at 700/350 covered 4-KiB read units per second share the later target PID. Exact file/VFS identity, counters and elapsed time must agree; the normal target rate is then locked at 700. Missing proof or a lost binding is a technical error, never corruption evidence.
- `Kiwi_Retention_Corruption_Safety_Gate_Coordinator` is the single fail-closed contract shared with cleanup for the generation write-block sentinel and corruption Incident.
- `Kiwi_Retention_Archive_Lock` provides one exclusive, non-waiting per-generation OS lock for both the read-only child and the cleanup worker.
- `Kiwi_Retention_Archive_Name` provides the shared strict archive filename and generation contract.
- `Kiwi_Retention_Archive_Health_Bootstrap_Recorder` emits only compact sanitized output when normal bootstrap is unavailable. It owns no state file, receipt, Incident spool, or database fallback.

External scheduling owns frequency, retries, alerts, and escalation. The runtime never manufactures a replacement archive or silently chooses another generation after corruption.

The repository ships one source/manifest/library/fixture bundle under `tools/database/archive-health-read-brake/`. The supervisor sets `LD_PRELOAD` only in its child's inherited environment snapshot; parent PHP and web requests remain unchanged. Foreign preload configuration is rejected. The Linux x86_64 library paces each covered 4-KiB section of `pread`/`pread64` through one monotonic ledger, requires random read advice, denies mappings of the bound files, and rejects an unsupported read path. There is no unbraked fallback or public rate switch. Non-empty WAL/journal gates run before the fixture proof and again before target opening. The 30-second fixture deadline is separate from the `30..7200` supervision budget; neither a supervisor timeout nor a technical brake failure permits archive writes or MySQL deletes while the child owns the generation lock.

## Result and safety transitions

A definitive `ok` check resolves the shared availability Incident. A lock deferral, timeout, discovery failure, bootstrap failure, or other non-definitive scheduled check raises or repeats that Incident when the normal controller and Operational Event service are available. Each controller operation carries an internal microsecond ordering value only in existing event context. Under the Availability lifecycle lock, a non-definitive result is suppressed if the latest persisted `resolved` belongs to a later-started check; conversely, a definitive recovery is suppressed if the latest open failure belongs to a later-started check. Without such a later recovery, including a completed timeout whose child has ended, Availability still opens normally. Immediately before an `archive_lock_active` transition, the controller additionally probes the generation lock once without waiting: if the lock is still active, it persists the failure; if the lock has become available, it briefly acquires and releases it and suppresses only the stale Availability transition. The command remains one-shot and never waits or reruns SQLite. These outcomes do not prove corruption. If a definitive corruption result established a durable corruption gate but Availability resolution failed, the next gated controller call retries only that idempotent resolution effect without rerunning PRAGMA.

Only this evidence proves corruption:

1. the child acquired the exclusive generation lock;
2. it opened the exact archive read-only;
3. the requested PRAGMA completed;
4. its result was not exactly `ok`.

The corruption transition is fail-closed: persist the generation-specific write-block while the exclusive child lock is still held, then raise the corruption Incident. If sentinel persistence fails, the child keeps the generation lock while the parent persists the Corruption Incident and releases only after the parent acknowledges that durable fallback gate. If both gate writes fail, the command returns non-zero and never reports success. Cleanup evaluates both durable gates after lock acquisition, before every SQLite write or receipt repair, and immediately before every MySQL delete. An unreadable or contradictory gate blocks destructive work. A later call may idempotently add one missing gate without rerunning PRAGMA. Parent reconciliation preserves the gate evidence reported by its child. If both gates later disappear, only a later persisted `resolved` from verified manual `unblock` may supersede that old result; missing gates without this proof are restored fail-closed. A sentinel-only `unblock` therefore first reconciles the Corruption Incident and cannot report success if that audit write fails.

## Manual recovery

Recovery is an operator decision outside normal runtime. `unblock` requires explicit confirmation and completes a full read-only `integrity_check` first.

- Repaired in place: verify archive `A`, clear `A`'s sentinel, then resolve its corruption Incident.
- Explicit replacement: require an existing corruption gate on `A`, require explicitly selected `B` to be the distinct generation the cleanup resolver will actually select, verify `B`, and require any independent corruption gate on `B` to be cleared through `B`'s own confirmed recovery first. Persist a distinct replacement-transition block on `B` bound to source `A` before terminalizing unfinished cleanup runs bound to `A`. The source gate is revalidated after acquiring `A`'s generation lock; only an already persisted transition marker bound to `A` permits a final-clear retry after `A`'s gate has resolved. This active-generation rule also permits a frozen prior-year `A` to move to the current active `B` after year rollover without special calendar state. Workers re-read their run under `A`'s generation lock and all ordinary audit updates reject terminal rows, so a worker that loaded stale state cannot resurrect or continue a replaced run. Clear `A`'s sentinel and resolve `A`'s Incident as its final recovery action; `B` remains blocked until that resolution succeeds, and only then is the transition block removed. Health never reconciles this transition marker into corruption evidence for `B`; its source binding permits an idempotent final-clear retry without authorizing replacement of an unrelated healthy archive.

This order preserves a continuous safety gate. Replacement transfers no cursor or receipt and creates no automatic successor. A later normal scheduler may create a fresh run against the explicitly available archive generation. Existing rows already deleted into a corrupt archive cannot be reconstructed by the health controller.

## Deliberate exclusions

The capability deliberately contains no central state reducer, state JSON, daily/weekly policy, bounded retry planner, annual snapshot, quarantine marker, automatic successor generation, automatic repair, notification transport, or mutation from `diagnose`. Legacy artifacts may remain on disk during migration, but current code neither reads nor writes them.
