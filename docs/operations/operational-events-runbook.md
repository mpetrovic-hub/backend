# Operational Events Runbook

## Read when

- Investigating operational warnings, errors, critical events, or recoveries.
- Validating the event cleanup cron or a producer integration.

## Source of truth for

- Internal operational-event queries and incident interpretation.
- Cleanup scheduling, retention, failure fallback, and smoke checks.

## Not here

- Stable event-model design: see `../architecture/operational-events.md`.
- A UI or REST API; the current read surface is internal repository methods and SQL.

## Reading events

The repository provides bounded prepared-query methods:

- `get_recent()` filters by area, severity, event type, lifecycle, correlation, or reference.
- `find_latest_by_correlation_key()` returns the latest lifecycle row.
- `get_open_incidents()` returns correlations whose latest row is `raised` or `repeated`.

Example SQL for open `error`/`critical` incidents in one area:

```sql
SELECT latest.*
FROM wp_kiwi_operational_events latest
WHERE latest.lifecycle_action IN ('raised', 'repeated')
  AND latest.area = 'retention'
  AND latest.severity IN ('error', 'critical')
  AND NOT EXISTS (
      SELECT 1
      FROM wp_kiwi_operational_events newer
      WHERE newer.correlation_key = latest.correlation_key
        AND (
            newer.occurred_at > latest.occurred_at
            OR (newer.occurred_at = latest.occurred_at AND newer.id > latest.id)
        )
  )
ORDER BY latest.occurred_at DESC, latest.id DESC
LIMIT 100;
```

Interpret `raised`, `repeated`, and `resolved` as an append-only timeline. Do not update rows manually to close an incident.

Lifecycle creation and recovery use a correlation-scoped MySQL advisory lock with a five-second acquisition bound. It protects only the short latest-row decision and required insert; it does not serialize retention archive PRAGMA work or replace the non-waiting per-generation archive lock. A lock acquisition or release failure means the requested event transition did not complete reliably and must be handled as an Operational Event persistence failure.

## Cleanup

- Daily hook: `kiwi_operational_event_cleanup_daily`.
- Follow-up worker hook: `kiwi_operational_event_cleanup_worker`.
- Default age: 180 days by `created_at`.
- Default batch: 5,000 rows.
- A full batch schedules one worker about 60 seconds later; a short batch ends the chain.
- A transient lock prevents concurrent cleanup chains.
- Before deleting old rows, cleanup refreshes every open `retention_archive_corruption_detected` Incident with a bounded, idempotent `repeated` row. The append is conditional on that correlation still being open, so a concurrent confirmed recovery cannot be reopened. A full bounded result page is treated as possible truncation and stops cleanup. If the read or any required refresh cannot be persisted, cleanup deletes no events. This keeps the Incident fallback gate durable until confirmed recovery even when its original rows exceed the normal retention age.

Cleanup has its own correlation, `operational_events_cleanup`. Failures raise/repeat an event when the table remains writable. If the table itself cannot accept the event, one generic PHP `error_log` line is emitted without raw database errors, credential values, recursive event writes, or a tight retry loop. The next regular run retries and its first success resolves the incident.

## Retention producer

Retention stale detection writes `event_type=retention_cleanup_timeout`, `area=retention`, and `severity=error`. The event references the affected run. `pending` and `partial` runs are not stale candidates under the current retention state contract.

A recovery requires a real non-dry retention run with a persisted final audit state and `completed`/`completed_noop`. Disabled, skipped, pending, partial, rescheduled, scheduler-start, and dry-run results do not resolve the incident.

A real Session cleanup that finalizes as `skipped` with `error_code=coverage_gate_failed` also writes `event_type=retention_cleanup_skipped`, `area=retention`, and `severity=error`. Its correlation is `retention_cleanup_skip_<source_key>` and remains separate from `retention_<source_key>` timeout incidents. The event references the finalized cleanup run and includes `reason_code`, `source_key`, gate status, valid cutoffs when available, the first blocked date/cause, verified-through date, and at most three blocking error codes. These values come from the already computed gate result; the producer performs no extra production query for message enrichment.

The first confirmed gate skip is `raised`; later new run IDs for the same source are `repeated`. A real persisted `completed`/`completed_noop` run writes the one `resolved` transition if that skip correlation is open. Event persistence is best effort and never changes a safe Retention skip into a technical cleanup failure. Failed final audit persistence creates no skip incident. Disabled, lock-active, dry-run, and `coverage_gate_required=false` Handoff paths create no skip error event.

## NTH submit producer

NTH `submitMessage` rejections use `event_type=nth_submit_failed`, `area=aggregator`, and one correlation per NTH service. Inspect the latest row for that correlation to determine whether the incident is open.

- `raised`: first failed submit after no open incident;
- `repeated`: another distinct failed flow for the same service;
- `resolved`: the first submit accepted with HTTP `2xx` and readable XML `resultCode=100` that is processed after the open failure;
- no row: routine accepted submit while no incident is open.

For this producer, local processing order is authoritative. Request, flow, and session timestamps do not retroactively reorder the service-level incident. For example, if a routine success is processed first, a delayed failure second, and another success third, the failure writes `raised` and the final success writes `resolved`.

The compact context contains only `service_key`, `result_code`, `result_text`, `flow_reference`, and `http_status`. It intentionally excludes subscriber references, session IDs, credentials, headers, and full request/response payloads. Credential-like result text may be centrally masked.

Before any historical false-pending correction, follow the read-only candidate and per-flow verification checklist in `../integrations/nth/fr/one-off/known-good-fr-test-vector.md`. Do not replay or update historical rows without separate row-level approval and rollback evidence.

## Smoke validation

1. Write a test failure with a unique correlation and idempotency key through `Kiwi_Operational_Event_Service`.
2. Repeat it with a different idempotency key and confirm `raised`, then `repeated`.
3. Write a qualified recovery and confirm exactly one `resolved` row.
4. Confirm `get_open_incidents()` no longer returns the correlation.
5. Include a long raw error and structured credential-like keys; confirm limits and `[redacted]` values while an allowed test MSISDN remains.
6. Insert an old disposable test row and run the cleanup service; confirm only rows older than the cutoff are removed.
7. In a safe test environment, simulate two finalized Session `coverage_gate_failed` runs and one qualified success; confirm `raised`, `repeated`, and one `resolved` under the same skip correlation. Confirm disabled, lock-active, Dry-Run, and Handoff paths write no skip error event.
8. Simulate two NTH business rejections and one accepted `resultCode=100` response for the same service; confirm `raised`, `repeated`, then one `resolved`, with only the approved compact context and no false pending transaction after either rejection. Also process a routine success, a delayed failure, and another success in that order while supplying deliberately out-of-order flow timestamps; confirm `raised`, then `resolved`, according to local processing order.

Do not use real credentials, tokens, raw subscriber data, or production-impacting retention changes in a smoke test.
