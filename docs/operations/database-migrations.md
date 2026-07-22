# Database Migrations

## Read when

- Deploying code that adds, changes, or removes a database object.
- Setting up a new installation or recovering an old database backup.
- Investigating schema drift or a failed external schema apply.

## Source of truth for

- The external database `status` and `apply` deployment gate.
- Schema-first release ordering and destructive two-phase changes.
- Failure, Operational Event, restore, and rollback responsibilities.

## Not here

- Runtime analytics refreshes: see `landing-funnel-analytics.md`.
- Operational Event storage and lifecycle: see `../architecture/operational-events.md`.
- Credentials or environment secrets: see `credentials-and-environments.md`.

## Hard runtime boundary

Normal website, REST, admin, AJAX, WP-Cron, and plugin-worker execution does not create or alter tables or views and does not run historical one-time data transformations. `includes/bootstrap.php` does not load the external database runner.

Schema work is an explicit deployment operation. Runtime code must not compensate for a skipped deployment gate with `dbDelta()`, DDL, retries, background migrations, or feature-specific schema readiness checks.

## Roles and authorization

The Implementer changes repository code, tests, and documentation only. An Implementer run does not authorize Production access, `apply`, historical incident writes, or recovery writes.

The Deployment Codex/Operator owns the later, explicitly authorized rollout:

1. Confirm the exact reviewed commit or release and target environment.
2. Keep a new installation, incompatible restore, or dependent feature in maintenance/not enabled.
3. Run read-only `status` and retain its JSON result.
4. Run mutating `apply` only when authorized and required.
5. Run `status` again and complete feature-specific smoke checks.
6. Record failure or recovery through `Kiwi_Operational_Event_Service` when required.

## Runner

Run the tool from the WordPress installation with Kiwi Backend active so WP-CLI loads the plugin classes first:

```bash
wp eval-file wp-content/plugins/kiwi-backend/tools/database/kiwi-database.php status
wp eval-file wp-content/plugins/kiwi-backend/tools/database/kiwi-database.php apply
```

`status` is strictly read-only. It queries real `information_schema` postconditions for all managed tables, columns, indexes, and views; verifies required device-model seed rows; and compares `kiwi_backend_db_schema_version` with the target version. It exits `0` only when the complete schema is ready. Drift produces JSON and a non-zero exit.

`apply` is explicitly mutating. It:

- obtains one database-scoped MySQL advisory lock and rejects a concurrent apply;
- refuses a newer or unrecognized installed schema version so an older deployment artifact cannot downgrade version evidence;
- refuses known legacy columns that require a reviewed, migration-specific external artifact;
- applies the canonical repository table and view definitions;
- verifies every schema step against real postconditions;
- applies and verifies required static seeds;
- persists `kiwi_backend_db_schema_version` only after final verification;
- returns a non-zero exit for command, seed, lock, or postcondition failure.

The generic runner never drops legacy columns, rebuilds active data through a temporary table, deletes an active summary before restore, or performs an unreviewed one-time data transformation.

## Deployment ordering

### Additive change

1. Deploy or make available the reviewed schema-capable release without enabling dependent behavior.
2. Run `status`.
3. Run authorized `apply` when drift is expected.
4. Require a green post-apply `status`.
5. Deploy or enable the dependent application behavior.

### Destructive change

1. Deploy compatible application code that no longer requires the old object.
2. Verify that code in Production.
3. Use a separate reviewed, versioned external migration artifact in a later controlled change.
4. Verify schema and application postconditions before considering the cleanup complete.

Never combine dependent-code activation and irreversible cleanup into one unverified step.

## New installation

Keep the site unavailable until the external bootstrap succeeds:

1. Install the reviewed plugin files without exposing the site.
2. Run `apply` to create canonical tables/views and required seeds.
3. Run `status`; require exit `0` and `ready=true`.
4. Run relevant smoke checks.
5. Only then expose the site or dependent features.

## Restore of an old backup

An old backup may contain missing objects or legacy columns. Keep the site in maintenance and run `status` first.

- Missing additive objects can be handled by the reviewed generic `apply`.
- `legacy_column` means stop. The generic runner intentionally does not transform or delete that data. Prepare and review a migration-specific external artifact.
- Re-run `status` after every approved operation. Do not open the site until it is green.

## Failure handling

Any non-zero command exit stops the rollout. Capture the sanitized JSON fields `phase`, `error_code`, `error_message`, and `drift`; do not copy credentials, full SQL payloads, raw MSISDNs, or subscriber identifiers into logs or comments.

The target schema version remains unchanged when a command or postcondition fails. Additive objects already created by an interrupted apply may remain and are recovery state, not permission to continue. Diagnose them with `status`; do not improvise drops, restores, or direct Production SQL.

The runner does not automatically write Operational Events. The Deployment Codex/Operator evaluates the failed command and records it through the existing service in WP-CLI context. Example shape:

```php
$events = new Kiwi_Operational_Event_Service();
$ok = $events->record_failure([
    'area' => 'database',
    'severity' => 'critical',
    'event_type' => 'schema_migration_failed',
    'correlation_key' => 'schema_migration:slim_landing_funnel_daily_summary:v1',
    'idempotency_key' => 'schema_migration:slim_landing_funnel_daily_summary:v1:<attempt-id>',
    'reference_type' => 'schema_migration',
    'reference_id' => 'slim_landing_funnel_daily_summary:v1',
    'message' => 'External schema migration failed.',
    'raw_error_text' => '<sanitized database error>',
    'context' => ['phase' => '<runner phase>'],
]);
```

Logging failure must not hide the original migration failure. Use `record_recovery()` only after the external operation, post-apply `status`, and relevant Production smoke checks all pass.

## Issue #103 historical event

The historical Production incident is a one-time Deployment Codex/Operator action, not part of implementation or plugin runtime:

- `occurred_at=2026-07-21 07:43:58 UTC`
- `severity=critical`
- `event_type=schema_migration_failed`
- `correlation_key=schema_migration:slim_landing_funnel_daily_summary:v1`
- `reference_type=schema_migration`
- `reference_id=slim_landing_funnel_daily_summary:v1`
- context phase `restore_after_delete`
- no credentials, full SQL payloads, raw MSISDNs, or subscriber identifiers

After the reviewed fix is deployed and Production verification is complete, record the matching recovery once through `record_recovery()`.

## Rollback boundary

Application rollback may leave additive tables, columns, or indexes in place. Do not automatically drop them. A schema version is evidence that the complete target postconditions passed, not a substitute for those checks.

Destructive schema changes are not automatically reversible. Their reviewed migration artifact must define its own backup, validation, and recovery boundary before Production authorization. The generic runner provides no archive/restore mechanism.

## Required handoff evidence

Record:

- exact commit/release and environment;
- pre-apply `status` exit and drift summary;
- authorized `apply` exit and phase;
- post-apply `status` exit;
- relevant smoke checks, including Main and TK-zone summary behavior when affected;
- Operational Event result when a failure or qualified recovery occurred;
- any remaining rollout checklist items.

Do not mark the GitHub Issue complete automatically; the user decides completion after the rollout evidence is reviewed.
