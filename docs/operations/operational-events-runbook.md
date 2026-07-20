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

## Cleanup

- Daily hook: `kiwi_operational_event_cleanup_daily`.
- Follow-up worker hook: `kiwi_operational_event_cleanup_worker`.
- Default age: 180 days by `created_at`.
- Default batch: 5,000 rows.
- A full batch schedules one worker about 60 seconds later; a short batch ends the chain.
- A transient lock prevents concurrent cleanup chains.

Cleanup has its own correlation, `operational_events_cleanup`. Failures raise/repeat an event when the table remains writable. If the table itself cannot accept the event, one generic PHP `error_log` line is emitted without raw database errors, credential values, recursive event writes, or a tight retry loop. The next regular run retries and its first success resolves the incident.

## Retention producer

Retention stale detection writes `event_type=retention_cleanup_timeout`, `area=retention`, and `severity=error`. The event references the affected run. `pending` and `partial` runs are not stale candidates under the current retention state contract.

A recovery requires a real non-dry retention run with a persisted final audit state and `completed`/`completed_noop`. Disabled, skipped, pending, partial, rescheduled, scheduler-start, and dry-run results do not resolve the incident.

## Smoke validation

1. Write a test failure with a unique correlation and idempotency key through `Kiwi_Operational_Event_Service`.
2. Repeat it with a different idempotency key and confirm `raised`, then `repeated`.
3. Write a qualified recovery and confirm exactly one `resolved` row.
4. Confirm `get_open_incidents()` no longer returns the correlation.
5. Include a long raw error and structured credential-like keys; confirm limits and `[redacted]` values while an allowed test MSISDN remains.
6. Insert an old disposable test row and run the cleanup service; confirm only rows older than the cutoff are removed.

Do not use real credentials, tokens, raw subscriber data, or production-impacting retention changes in a smoke test.
