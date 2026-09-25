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

Run the tool from the WordPress installation with Kiwi Backend active. The
global `--require` option loads the repository-owned command before WordPress:

```bash
wp --require=wp-content/plugins/backend/tools/database/kiwi-database.php kiwi database status
wp --require=wp-content/plugins/backend/tools/database/kiwi-database.php kiwi database apply
```

The command bootstrap uses WP-CLI core APIs available in WP-CLI 2.12. It
registers the selected operation on `plugins_loaded`, loads WordPress exactly
once, verifies that `plugins_loaded` has fired and `init` has not, and exits
before normal `init` hooks can schedule cron work, clean up rows, or perform
other runtime side effects.

Do not replace the command with `eval-file`, `wp eval`, a temporary MU-plugin,
or direct SQL. Missing WP-CLI APIs, a missing lifecycle hook, unloaded Kiwi
classes, or execution after `init` all stop before a schema operation.

`status` is strictly read-only. It queries real `information_schema` postconditions for all managed tables, columns, indexes, and views; verifies required device-model seed rows; and compares `kiwi_backend_db_schema_version` with the target version. It exits `0` only when the complete schema is ready. Drift produces JSON and a non-zero exit.

`apply` is explicitly mutating. It:

- obtains one database-scoped MySQL advisory lock and rejects a concurrent apply;
- refuses a newer or unrecognized installed schema version so an older deployment artifact cannot downgrade version evidence;
- refuses known legacy columns or tables that require a reviewed, migration-specific external artifact;
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
- `legacy_column` or `legacy_table` means stop. The generic runner intentionally does not transform, rename, or delete that data. Use the reviewed migration-specific external artifact for that exact state.
- Re-run `status` after every approved operation. Do not open the site until it is green.

## SMS allocation-version preparation (Issue #122)

Schema target `2026-09-21-1` adds `allocation_version VARCHAR(50) NOT NULL
DEFAULT 'legacy'` to assignments and summaries. The ordered unique summary
identity is `(landing_key, service_key, variant_key, seed, allocation_version)`.
The general deployment contract explicitly opts these two column definitions
and the summary key into detail verification; other metadata (including the
engagement migration's detailed contract) is not implicitly enabled. Unexpected
additional unique summary indexes also block readiness. Generic `apply` refuses
drift on existing SMS tables: it must not use `dbDelta` to perform this migration.
A new installation receives the canonical target tables directly.

The separate entrypoint is
`tools/database/migrations/sms-body-variant-allocation-version.php`; its service
is `tools/database/migrations/class-sms-body-variant-allocation-version-migration-service.php`.
Both use the actual WordPress prefix. Neither is loaded by application runtime.

Before a Production attempt, the human approves the exact release and target,
downloads a current complete Hostinger database backup, and confirms its protected
location. Check that both SMS tables can be restored in an isolated local database.
Record the current schema and consistent baseline facts/counters; keep historical
summary discrepancies separate from this migration. Do not send synthetic sales,
SMS, callbacks, or affiliate requests to Production.

Keep the existing application active during preparation. Stage the reviewed
`tools/database/` artifact tree separately from the active plugin (including its
schema contract, deployment service and historical migration dependencies).
The existing plugin remains active and supplies its existing repository classes;
only the staged artifact supplies the new schema inspection/migration code.
From the WordPress root, substituting the reviewed absolute staging path:

```bash
wp --require=/path/to/staged/tools/database/migrations/sms-body-variant-allocation-version.php kiwi database migration sms-body-variant-allocation-version check
wp --require=/path/to/staged/tools/database/migrations/sms-body-variant-allocation-version.php kiwi database migration sms-body-variant-allocation-version apply
wp --require=/path/to/staged/tools/database/kiwi-database.php kiwi database status
```

`check` is read-only and validates the SMS migration state. It does not assert
whole-installation readiness. Supported source version is `2026-07-23-1`:

- both columns absent and the original four-part unique key;
- assignment column present, summary column absent, original key;
- both columns present with the original key;
- both columns and the complete five-part target key already present.

Existing version columns must have the exact target definition. Before a
remaining structural step, existing version values must be exactly `legacy`.
Complete target structures accept later generations; repeated apply never resets
them. Empty versions, unexpected columns/index definitions, unknown partial
states and unrecognized/newer schema versions fail closed. Published target
version with an incomplete structure is also rejected.

`apply` holds the existing non-waiting deployment advisory lock and sets
`SET SESSION lock_wait_timeout=0` on its dedicated short-lived CLI connection.
It adds each missing column with `ALGORITHM=INSTANT`, then replaces the summary
key in one `ALTER TABLE ... DROP INDEX ... ADD UNIQUE KEY ... ALGORITHM=INPLACE,
LOCK=NONE`. There is no interval without a unique key, fallback to a more blocking
algorithm, automatic retry, application write pause, table clearing, or backfill.
A busy metadata lock or unsupported algorithm returns failure immediately.
Per-step results report what succeeded without row data or raw database errors.

On any failure, keep compatible application code active and preserve successful
steps. Do not remove added columns, restore a backup, kill another database
session, or activate the new application. Inspect the reported state and cause
read-only. A human must explicitly authorize each later `apply`; it rechecks
state and skips completed steps. A failed final verification may leave all
structural steps complete while the source version remains installed. Resolve
the reported cause before an authorized continuation. Version persistence occurs
only after target checks and the full existing deployment schema/seed/legacy gate
pass. Run the general `status` check and verify data preservation before enabling
the reviewed version-aware application.

Record the exact application commit as the prepared return release for #126.
This release still creates only `legacy` assignments with the original four
categories, words and weights. It can read stored `download_phrase` bodies and
count later-generation events. After #126 ever produces a new generation, never
return to the original version-blind repository. That repository can update both
generations through its old four-part filter. Return only to this verified #122
application release while retaining the database and all stored generations.

After separately authorized activation, observe real new assignments and events
read-only for an initial 15 minutes. Compare related facts and all seven counters
without mistaking in-flight writes or existing historical differences for new
losses. Inspect operational errors. Missing event types remain "not yet observed",
not passed. Record commit, schema result and sanitized observations; the human
accepts the rollout before #126 may merge/activate. Further observation after one
or two days requires an explicitly assigned task; this code schedules none.

Code return and data restoration are distinct, separately authorized decisions.
Unexpected data loss requires a concrete recovery proposal accounting for all
legitimate writes since the backup, never an automatic full restore. Conditional
failure actions that did not occur are recorded as not required. Existing
Operational Event failure/recovery responsibilities below still apply.

### Second step: activate the FR NTH allocation (Issue #126)

This is an application-only release on the prepared #122 schema. Do not run a
new migration or enable it merely because the columns exist. Before merging or
activating #126, verify the completed, checked and human-accepted Production
rollout evidence for #122. Record the reviewed #126 commit and the available
version-aware #122 return release. The tested return source is
`c5ca693c2845264d17aad064b2a2fcf066126e11` (merged PR #128), whose application
and database-tool sources match reviewed commit
`dc873b22688ded803af1bdc3374aa50bde122d0b`.

Before an explicitly authorized rollout:

1. Verify test evidence covers the exact release and its return path. Ensure no
   original version-blind application writers remain.
2. Have the human download and confirm a current complete Hostinger backup;
   verify isolated local restoration of both SMS tables without exposing data.
3. Compare the eight entries, categories, weights and `fr_sms_v2` in
   `includes/providers/nth/config/fr-one-off-sms-body-variants.php` with the
   approved configuration in [landing-funnel-analytics.md](landing-funnel-analytics.md#sms-assignment-generations).
4. Run the general `kiwi database status` target check using the reviewed
   deployment entrypoint. Require the complete target structure before enabling
   the release; #126 performs no DDL.
5. Deploy only the explicitly approved application release and confirm its
   active commit. Observe real traffic read-only for an initial 15 minutes:
   eligible new assignments use `fr_sms_v2` and only approved entries, historical
   contents/generations stay unchanged, and events use the stored generation.
6. Check operational errors and consistent facts/counters, allowing for
   in-flight writes. Record missing event types as "not yet observed", not
   passed or failed. Record sanitized evidence and obtain human acceptance.

No synthetic sales, SMS, purchases or callback replays may be sent to Production.
The code does not schedule follow-up monitoring. A read-only technical check
after one or two days needs a separately assigned task; the later lp5-fr/lp6-fr
business evaluation is neither a PR gate nor a new reporting implementation.

If code return is needed, report the cause and obtain explicit approval unless
that exact return was already authorized. Deploy only the prepared version-aware
#122 release; keep the database and every assignment from both generations.
Verify stored bodies/tokens (especially `AccederMaintenant`), late events, and
new legacy assignments using the original distribution. Never delete a
generation, reconstruct old bodies from the current list, or use the original
version-blind application. Unexpected data problems stop deployment and require
a separately approved recovery proposal preserving legitimate post-backup writes.
Return and data-restoration actions not needed are recorded as not required.

### Validation boundary

Project tests cover version-separated counters/rates/filters, duplicate and late
events, original selection, stored future bodies/tokens, migration transitions,
metadata drift, opt-in general detail checks and the command's early WP-CLI
lifecycle through test doubles. Existing engagement migration tests remain intact.
The temporary MariaDB 11.8.9 rehearsal executes actual migration/repository SQL
against synthetic InnoDB tables: all supported stages, preservation, repeated
apply with multiple generations, concurrent old writers and legacy-only mixed
writers, real metadata/advisory locks, explicit continuation, negative metadata
cases and blocked final verification. It also loads the original repository with
the new external migration. The rehearsal limits the deployment contract to the
SMS tables and simulates WordPress options; it is not a full WordPress, WP-CLI,
HTTP, OPcache, Hostinger-load or NTH end-to-end test. Production traffic observation
and backup/restore verification remain separate authorized deployment work.

The #126 project tests cover all 100 deterministic positions, exact entries and
SMS forms, stored historical assignments, generic configuration handling, and
positive/negative adapter boundaries and switches. A separate temporary
MariaDB 11.8.9 rehearsal loads actual #122 and #126 service/adapter/repository
sources in separate PHP processes, followed by an actual #122 code return on
the unchanged database. It verifies both full-table snapshots before new writes,
stored tokens/bodies including all eight download-phrase fixtures, all seven
counters, five existing rates, individual/combined filters, late/duplicate
events, and exact original selection for new assignments after return. It uses
synthetic data and a PDO-backed wpdb test adapter; no full WordPress installation,
NTH HTTP callback, Production traffic or deployment is claimed by these checks.

## Landing-session engagement table rename

Issue #96 changes the shared landing-session engagement table from
`wp_kiwi_premium_sms_landing_engagements` to
`wp_kiwi_landing_session_engagements`. The expected predecessor schema version
is exactly `2026-07-20-1`; the target version is `2026-07-23-1`.

The generic `kiwi database apply` never performs this rename. When the old
table exists, or when only the target table exists while the predecessor
version is still installed, it returns non-zero with
`legacy_migration_required` before schema mutation. New installations are
different: the generic runner creates the new canonical table directly.

The historical artifact is loaded separately from the WordPress root:

```bash
wp --require=wp-content/plugins/backend/tools/database/migrations/landing-session-engagements.php kiwi database migration landing-session-engagements check
wp --require=wp-content/plugins/backend/tools/database/migrations/landing-session-engagements.php kiwi database migration landing-session-engagements apply
wp --require=wp-content/plugins/backend/tools/database/migrations/landing-session-engagements.php kiwi database migration landing-session-engagements rollback
```

`check` is read-only. It exits `0` only for these complete states:

- `success=true`, `state=pending`, `installed_version=2026-07-20-1`, old base table only, and an exact schema snapshot;
- `success=true`, `state=applied`, `installed_version=2026-07-23-1`, new base table only, and an exact schema snapshot.

Its snapshot reports sanitized evidence only: row count, minimum/maximum ID,
`AUTO_INCREMENT`, column/index counts, and hashes of complete column/index
metadata. The precondition compares column type, nullability, default, extra
attributes against the explicit canonical contract. Its physical column order
must be either the canonical contract order or the one complete documented
historical order of the verified `2026-07-20-1` predecessor. This is a narrow
compatibility rule, not an order-insensitive comparison: a partial, arbitrary,
or third reordered schema remains a non-zero stop gate. Index uniqueness,
ordered columns, prefix lengths, and type remain exact. Both table names,
neither name, a view or other wrong object type, any metadata drift, an
inspection failure, or a table/version mismatch are non-zero stop gates without
mutation.

For nullable columns, the unquoted `NULL` marker that MariaDB exposes through
WordPress `information_schema` results is normalized to an absent default. A
quoted default value such as `'NULL'` remains a literal value and therefore
continues to be detected as metadata drift.

`apply` requires the exact `pending` state and explicit User/Operator approval.
It obtains the same database-scoped advisory lock as the generic runner,
captures the complete predecessor snapshot, executes one atomic `RENAME TABLE`,
compares the complete post-rename snapshot, rebuilds both managed analytics
views against the target table, verifies that both views are executable, and
writes `2026-07-23-1` last. Repeated `apply` in the complete `applied` state is
a successful no-op.

`rollback` is not automatic. It requires explicit approval, continued
maintenance, no new target-code writes, and the complete `applied` state. It
performs the reverse atomic rename, verifies the old snapshot, rebuilds and
validates both managed analytics views against the predecessor table, and
restores `2026-07-20-1` last. Repeated `rollback` in the complete `pending`
state is a successful no-op. A partial state remains visible and blocked; do
not repair it with direct SQL or an improvised version update.

### Production cutover gates

The Implementer does not run these Production actions. The later authorized
Deployment Codex/Operator must:

1. Deploy only the exact merge commit that passed the post-merge scratch rehearsal described below.
2. Confirm the Production target, compatible predecessor release, WP-CLI 2.12, and the exact pause/resume procedure.
3. Have the User download and confirm a current Hostinger database backup.
4. Enable full maintenance, then separately pause WP-Cron, schedulers, workers, and all other writing WP-CLI processes.
5. Run migration `check`; require exit `0`, `state=pending`, version `2026-07-20-1`, the expected schema, and a plausible non-empty snapshot unless the User explicitly accepts loss of historical engagement/soft-flag data.
6. Obtain explicit User/Operator approval, then run migration `apply` once.
7. Require exit `0`, `state=applied`, `mutated=true`, version `2026-07-23-1`, and an unchanged row/ID/`AUTO_INCREMENT`/column/index snapshot.
8. Run generic `kiwi database status`; require exit `0`, `ready=true`, target version `2026-07-23-1`, and no drift.
9. Smoke-test the engagement write/read path, Main and TK-zone summaries, Device Model Harvest, the landing-session Retention Coverage Gate, managed views, Sales Attribution, and relevant Premium-SMS fraud/MO reads.
10. Keep maintenance active on any non-zero result or unproven postcondition. Use approved `rollback` only before new writes, and restore the matching predecessor code as part of the same controlled recovery.
11. Resume controlled jobs/smokes first, then public traffic, and monitor briefly.

The database lock does not replace maintenance. The artifact creates no backup,
does not manage traffic/jobs, does not accept data loss, and does not write
Operational Events. If a qualified failure occurs, preserve the sanitized
original JSON/exit and record failure/recovery later through
`Kiwi_Operational_Event_Service` under the existing operational-event contract.

### Required post-merge scratch rehearsal

Before Production, the Deployment Codex must rehearse the exact merge commit in
an isolated temporary Git worktree and disposable environment. A correction
commit invalidates earlier evidence and requires the full rehearsal again.

Prerequisites:

- exact commit resolved from `origin/main` and recorded with `git rev-parse`;
- disposable WordPress installation with the plugin active;
- WP-CLI 2.12;
- disposable MariaDB database with no Production connection or data;
- the exact predecessor release or a reviewed fixture that creates the complete `2026-07-20-1` schema;
- synthetic engagement rows only, including known IDs and an explicitly advanced `AUTO_INCREMENT`.

When the confirmed historical predecessor is used, the fixture must reproduce
its complete documented physical column order. The rehearsal must also include
an arbitrary third full order and prove that `check` rejects it without a
mutation; it must not treat column order as generally interchangeable.

Run every command with an explicit scratch WordPress path and the runner from
the exact worktree, for example:

```bash
wp --path=<scratch-wordpress> --require=<exact-worktree>/tools/database/migrations/landing-session-engagements.php kiwi database migration landing-session-engagements check
wp --path=<scratch-wordpress> --require=<exact-worktree>/tools/database/migrations/landing-session-engagements.php kiwi database migration landing-session-engagements apply
wp --path=<scratch-wordpress> --require=<exact-worktree>/tools/database/kiwi-database.php kiwi database status
wp --path=<scratch-wordpress> --require=<exact-worktree>/tools/database/migrations/landing-session-engagements.php kiwi database migration landing-session-engagements apply
```

The success database must prove `pending` check, real apply, generic green
status, repeated-apply no-op, identical row/min-ID/max-ID/`AUTO_INCREMENT` and
column/index hashes, plus the listed consumer smokes. A separate fresh scratch
database must prove real rollback, repeated-rollback no-op, and non-zero
`conflict`, `missing`, `version_mismatch`, `schema_mismatch`, query-error, and
lock/postcondition failure cases. Preserve sanitized commands, exits, JSON,
snapshots, environment versions, exact commit, and smoke results.

Finally deactivate/remove the scratch plugin, drop only the explicitly named
disposable databases, remove the temporary worktree, and verify those scratch
artifacts are gone. This rehearsal proves the implemented PHP/WP-CLI path; it
does not prove Hostinger permissions, Production maintenance, backup, or
Production smokes.

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
