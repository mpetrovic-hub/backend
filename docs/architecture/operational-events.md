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

The closed-to-`raised` decision and recovery read/write are serialized by one short, bounded MySQL advisory lock derived from a hash of the `correlation_key`. A producer may attach a current-state predicate to a failure or recovery transition; the predicate receives the latest correlation event, and the predicate plus its accepted lifecycle write run while the lifecycle lock is held. This lets a producer suppress evidence superseded by a later persisted transition without adding a mutable incident row, state file, or routine `resolved` rows while no incident is open. Without such a predicate, an already-open correlation releases the lock before the existing conditional `repeated` append. Lock acquisition, release, predicate, or accepted-write failure is a persistence failure; it is never treated as a successful lifecycle change.

The latest event for a correlation decides whether the incident is open. The repository exposes bounded internal reads for recent events, the latest correlation event, and open incidents.

## Producer contract

Producers call `Kiwi_Operational_Event_Service`; they do not build SQL or implement their own sanitizing. Logging is best effort: a logging failure must not turn a successful business action into a failure.

The first producer is retention cleanup:

- each run newly transitioned to stale/failed writes one idempotent `retention_cleanup_timeout` event;
- all stale runs for the same retention source share a correlation;
- the specific audit run is referenced as `retention_cleanup_run`;
- only a real non-dry-run `completed`/`completed_noop` result whose final audit update persisted can resolve the incident.
- each real `coverage_gate_failed` safety skip writes one idempotent `retention_cleanup_skipped` event only after its final `skipped` audit state persisted;
- coverage-gate skip incidents correlate separately as `retention_cleanup_skip_<source_key>` and carry only compact diagnostics already returned by the gate;
- the first skip raises the incident, later source runs repeat it, and a real persisted `completed`/`completed_noop` run resolves it once;
- disabled, lock-active, dry-run, and sources whose coverage policy is `not_required` do not produce this error incident.

The timeout and coverage-gate skip correlations are intentionally independent. A success may resolve both open incidents, but neither failure type changes the lifecycle or identity of the other.

The NTH `submitMessage` producer is an Aggregator-boundary producer:

- every normalized `mt_submit_failed` writes `event_type=nth_submit_failed`, `area=aggregator`, and `severity=error`;
- all failures for one NTH `service_key` share a stable service-level correlation, while the individual flow reference supplies the idempotency identity;
- the first failure is `raised`, later distinct failed flows are `repeated`, and only a later HTTP `2xx` response with readable XML `resultCode=100` can write `resolved`;
- routine accepted submits write no event when no incident is open;
- context is limited to service key, result code/text, flow reference, and HTTP status. Request payloads, credentials, subscriber references, and session IDs are excluded;
- event persistence is best effort and never changes the NTH business result or triggers another submit.

## Data safety

Credential-like keys are masked centrally before size checks and persistence. This includes authorization/authentication values, API keys, access tokens, generic tokens, client secrets, passwords, secrets, digests, signatures, HMACs, nonces, OTPs/PINs, private/signing/encryption keys, key material, complete PEM private-key blocks, cookie headers, and session-cookie/session-ID fields. Structured sensitive values become `[redacted]`; raw text that cannot be safely isolated becomes `[credential content removed]`.

Producers must still select small diagnostic context rather than sending full payloads, headers, or object dumps. Business identifiers such as MSISDNs may be retained when operationally required.

## Extension boundary

The table and service are shared infrastructure, not Retention-specific storage. Future producers can add normalized areas and event types without schema changes. Adding a producer still requires a focused contract for correlation, idempotency, recovery qualification, and safe context.
