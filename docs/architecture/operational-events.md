# Operational Events

## Read when

- A shared runtime capability needs to report an operational failure or recovery.
- Work changes event identity, incident lifecycle, sanitizing, or read contracts.

## Source of truth for

- The append-only operational-event model.
- Producer boundaries, correlation, idempotency, and security rules.

## Not here

- Runtime queries and cleanup procedures: see `../operations/operational-events-runbook.md`.
- Specialized audit-table contracts such as retention cleanup runs.
- Admin UI or REST API behavior; neither exists for this capability yet.

## Storage contract

`wp_kiwi_operational_events` stores immutable event rows. An incident is reconstructed from rows sharing a `correlation_key`; rows are never updated to close an incident.

Required event fields:

- `occurred_at`: business event time
- `created_at`: persistence time
- `area`: normalized extensible key, for example `retention`, `cron`, or `aggregator`
- `severity`: `info`, `warning`, `error`, or `critical`
- `event_type`: normalized extensible event key
- `lifecycle_action`: `raised`, `repeated`, or `resolved`
- `correlation_key`: stable identity of the continuing incident
- `reference_type` and `reference_id`: affected object identity
- `message`: readable summary, limited to 500 characters
- `raw_error_text`: optional sanitized error detail, limited to 4,000 characters
- `context_json`: optional small structured diagnostics, limited to 16 KB

`idempotency_key` is optional and unique. Producers should provide it whenever a stable run or request identifier exists.

## Lifecycle

- The first failure while no incident is open writes `raised`.
- Later failures with the same correlation write separate `repeated` rows.
- The first qualified later success writes `resolved`.
- Later routine successes write no event.
- A later failure after `resolved` starts a new `raised` lifecycle on the same correlation.

Recovery idempotency is derived from the open correlation row, so concurrent attempts to resolve the same observed incident cannot persist duplicate `resolved` rows.

The latest event for a correlation decides whether the incident is open. The repository exposes bounded internal reads for recent events, the latest correlation event, and open incidents.

## Producer contract

Producers call `Kiwi_Operational_Event_Service`; they do not build SQL or implement their own sanitizing. Logging is best effort: a logging failure must not turn a successful business action into a failure.

The first producer is retention cleanup:

- each run newly transitioned to stale/failed writes one idempotent `retention_cleanup_timeout` event;
- all stale runs for the same retention source share a correlation;
- the specific audit run is referenced as `retention_cleanup_run`;
- only a real non-dry-run `completed`/`completed_noop` result whose final audit update persisted can resolve the incident.

## Data safety

Credential-like keys are masked centrally before size checks and persistence. This includes Authorization values, API keys, access tokens, generic tokens, client secrets, passwords, secrets, private/signing/encryption keys, key material, and complete PEM private-key blocks. Structured sensitive values become `[redacted]`; raw text that cannot be safely isolated becomes `[credential content removed]`.

Producers must still select small diagnostic context rather than sending full payloads, headers, or object dumps. Business identifiers such as MSISDNs may be retained when operationally required.

## Extension boundary

The table and service are shared infrastructure, not Retention-specific storage. Future producers can add normalized areas and event types without schema changes. Adding a producer still requires a focused contract for correlation, idempotency, recovery qualification, and safe context.
