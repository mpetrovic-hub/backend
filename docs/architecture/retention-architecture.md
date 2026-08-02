# Retention Architecture

## Purpose

This document defines the stable data-safety contract shared by every retention source, the SQLite archive, the cleanup worker, the external health check, Operational Incidents, and manual recovery. Operational commands and deployment steps live in `../operations/retention-runbook.md`.

## Shared archive and source boundary

Retention uses one configured SQLite archive root and generation file, with a separate archive table and Source-ID receipt evidence for each registered retention source. The shared archive and safety services stay source-neutral. Each source owns only its source table, cutoff column, eligibility rule, retention age, coverage rule, archive mapping, and cleanup-run audit context.

A future retention source must provide a normalized registry contract, a stable primary key, a deterministic archive mapping, source-specific coverage tests, and crash/regression evidence. Aggregator-, country-, flow-, or table-specific behavior must not leak into the shared lock, health, receipt, or delete gates.

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
- `Kiwi_Retention_Corruption_Safety_Gate_Coordinator` is the single fail-closed contract shared with cleanup for the generation write-block sentinel and corruption Incident.
- `Kiwi_Retention_Archive_Lock` provides one exclusive, non-waiting per-generation OS lock for both the read-only child and the cleanup worker.
- `Kiwi_Retention_Archive_Name` provides the shared strict archive filename and generation contract.
- `Kiwi_Retention_Archive_Health_Bootstrap_Recorder` emits only compact sanitized output when normal bootstrap is unavailable. It owns no state file, receipt, Incident spool, or database fallback.

External scheduling owns frequency, retries, alerts, and escalation. The runtime never manufactures a replacement archive or silently chooses another generation after corruption.

## Result and safety transitions

A definitive `ok` check resolves the shared availability Incident. A lock deferral, timeout, discovery failure, bootstrap failure, or other non-definitive scheduled check raises or repeats that Incident when the normal controller and Operational Event service are available. These outcomes do not prove corruption. If a definitive corruption result established a durable corruption gate but Availability resolution failed, the next gated controller call retries only that idempotent resolution effect without rerunning PRAGMA.

Only this evidence proves corruption:

1. the child acquired the exclusive generation lock;
2. it opened the exact archive read-only;
3. the requested PRAGMA completed;
4. its result was not exactly `ok`.

The corruption transition is fail-closed: persist the generation-specific write-block while the exclusive child lock is still held, then raise the corruption Incident. If sentinel persistence fails, the child keeps the generation lock while the parent persists the Corruption Incident and releases only after the parent acknowledges that durable fallback gate. If both gate writes fail, the command returns non-zero and never reports success. Cleanup evaluates both durable gates after lock acquisition, before every SQLite write or receipt repair, and immediately before every MySQL delete. An unreadable or contradictory gate blocks destructive work. A later call may idempotently add one missing gate without rerunning PRAGMA.

## Manual recovery

Recovery is an operator decision outside normal runtime. `unblock` requires explicit confirmation and completes a full read-only `integrity_check` first.

- Repaired in place: verify archive `A`, clear `A`'s sentinel, then resolve its corruption Incident.
- Explicit replacement: require an existing corruption gate on `A`, require explicitly selected `B` to be the distinct generation the cleanup resolver will actually select, verify `B`, and persist a distinct replacement-transition block on `B` bound to source `A` before terminalizing unfinished cleanup runs bound to `A`. This active-generation rule also permits a frozen prior-year `A` to move to the current active `B` after year rollover without special calendar state. Workers re-read their run under `A`'s generation lock and all ordinary audit updates reject terminal rows, so a worker that loaded stale state cannot resurrect or continue a replaced run. Clear `A`'s sentinel and resolve `A`'s Incident as its final recovery action; `B` remains blocked until that resolution succeeds, and only then is the transition block removed. Health never reconciles this transition marker into corruption evidence for `B`; its source binding permits an idempotent final-clear retry without authorizing replacement of an unrelated healthy archive.

This order preserves a continuous safety gate. Replacement transfers no cursor or receipt and creates no automatic successor. A later normal scheduler may create a fresh run against the explicitly available archive generation. Existing rows already deleted into a corrupt archive cannot be reconstructed by the health controller.

## Deliberate exclusions

The capability deliberately contains no central state reducer, state JSON, daily/weekly policy, bounded retry planner, annual snapshot, quarantine marker, automatic successor generation, automatic repair, notification transport, or mutation from `diagnose`. Legacy artifacts may remain on disk during migration, but current code neither reads nor writes them.
