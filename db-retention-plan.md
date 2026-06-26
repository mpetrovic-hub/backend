# Temporary DB Retention Plan

Status: temporary planning document. Do not link this into the permanent docs yet.

Issue 1 audit result: the read-only production audit is recorded in
`docs/operations/db-retention-audit-2026-06-17.md`. The audit recommends a
14-day storage-pressure window for raw landing analytics tables, keeps
click-attribution TTL unchanged, and keeps fraud/provider audit tables on the
preferred 120-day policy unless a later business decision approves a shorter
fallback.

Final planning update, 2026-06-26:

- Start implementation with `wp_kiwi_landing_page_sessions` only (`source_key = landing_page_sessions`).
- Use `enabled=false`, `dry_run=true`, `retention_days=14` as the default for that source.
- Do not solve historical summary backfill in Issue #71; known old missing coverage is accepted as gone.
- Keep coverage gates hard for new/future missing summary coverage.
- Run cleanup through a daily WP-Cron job plus a manually callable runner.
- Keep the Settings page/UI in Issue #80.

This file is the working plan for reducing WordPress database growth after the landing analytics, daily summary, tkzone summary, device normalization, trusted-proxy IP, and premium-SMS fraud changes that landed after the original GitHub issue was written. It is intentionally separate from the existing documentation until the actual retention implementation is approved.

## Immediate problem

The production database is reported at about **2.7 GB** while the current Hostinger plan allows about **3 GB total**. That leaves too little headroom for normal traffic, indexes, WordPress overhead, plugin metadata, and backups/imports.

The concrete goal is therefore:

1. keep durable reporting tables long term;
2. keep enough raw data for debugging, recompute confidence, billing review, and fraud review;
3. prune high-volume raw analytics tables on a predictable schedule;
4. keep fraud/provider audit data under a separate, more conservative policy;
5. choose the final retention windows from a dry-run size audit instead of guessing.

## Non-goals for this planning document

This document does not implement or request:

- new `DELETE`, prune, archive, or migration jobs in this PR;
- changes to `KIWI_CLICK_ATTRIBUTION_TTL_SECONDS` in this PR;
- changes to `KIWI_CLICK_ATTRIBUTION_CLEANUP_LIMIT` in this PR;
- changes to summary refresh windows in this PR;
- changes to provider, aggregator, or country integrations;
- external database archival outside WordPress.

Any future cleanup must be delivered by one or more separate implementation issues.

## Current state to base the plan on

Since the original issue was written, the table contracts changed materially:

- The main statistics UI and CSV read path now uses `wp_kiwi_landing_funnel_daily_summary` instead of rebuilding legacy views for normal reporting.
- The main daily summary is intentionally slim:
  - grouped by canonical landing/session facts and normalized dimensions;
  - includes `pid` and `tksource`;
  - excludes `tkzone`;
  - excludes raw `client_ip` and `client_ip_hash`;
  - excludes hidden-time median;
  - uses handoff event counts and hidden-time min/max only;
  - includes completed sales only through `wp_kiwi_sales.attribution_metric_date`.
- `tkzone` reporting moved to the separate `wp_kiwi_landing_funnel_daily_tkzone_summary` table and is intentionally limited by `KIWI_LANDING_FUNNEL_TKZONE_SUMMARY_PIDS`.
- Landing sessions now persist canonical dimensions directly: provider, flow, country, `pid`, `tksource`, `tkzone`, primary browser language, normalized device/browser buckets, and coarse client-IP buckets.
- Daily summary refreshes should use canonical landing-session columns. Engagement and handoff rows contribute metrics, but they must not repair missing canonical dimensions for the main summary.
- Sales snapshots in `wp_kiwi_sales` are the durable sale fact source and carry attribution/source/device/client-IP snapshot fields.
- Device dimensions now use the shared normalizer and `wp_kiwi_device_model_brand_map`; observed `(unknown)` model map rows are review placeholders and not raw traffic evidence.
- Trusted-proxy IP rollout stores only safe client-IP diagnostics in session raw context; summary output uses coarse IP buckets only.
- Premium-SMS fraud monitoring now stores subscriber-level fraud signals and billing outcomes, including pending/completed/failed billing state and normalized aggregator status. This data is operational evidence, not a derived reporting summary.

## Planning assumptions agreed so far

These were original planning inputs, not implemented behavior. The audit has
since selected the storage-pressure window for the first raw landing cleanup:

- Landing/session, CTA/engagement, and handoff raw evidence originally targeted
  **30 days**, but the current implementation default is **14 days** for raw
  landing sources because of storage pressure.
- Billing and fraud evidence should remain available for **90-120 days**.
- If the size audit proves that the preferred windows still leave the database too close to the 3 GB Hostinger limit, the implementation issue must come back with a narrower recommendation instead of silently using shorter windows.

## Candidate retention policy

The table below is a decision matrix. The preferred window is the current planning target; the storage-pressure and emergency windows are fallback candidates that require evidence from Issue 1.

| Table | Preferred planning window | Storage-pressure fallback | Emergency fallback | Cutoff column | Decision basis | Cleanup issue |
| --- | ---: | ---: | ---: | --- | --- | --- |
| `wp_kiwi_landing_funnel_daily_summary` | keep indefinitely | keep indefinitely | keep indefinitely | n/a | Primary durable main reporting source. | none |
| `wp_kiwi_landing_funnel_daily_tkzone_summary` | keep indefinitely | keep indefinitely | keep indefinitely | n/a | Primary durable `tkzone` reporting source. | none |
| `wp_kiwi_sales` | keep indefinitely | keep indefinitely | keep indefinitely | n/a | Durable confirmed-sale fact source and payout/revenue evidence. | none |
| `wp_kiwi_landing_kpi_summary` | keep indefinitely | keep indefinitely | keep indefinitely | n/a | Small aggregate table. | none |
| `wp_kiwi_sms_body_variant_summary` | keep indefinitely | keep indefinitely | keep indefinitely | n/a | Small aggregate experiment table. | none |
| `wp_kiwi_device_model_brand_map` | keep indefinitely | keep indefinitely | keep indefinitely | n/a | Configuration/reference table, not traffic raw data. | none |
| `wp_kiwi_click_attributions` | existing 48h TTL | existing 48h TTL | existing 48h TTL plus cleanup-backlog fix | `expires_at` | Already short-lived and already has bounded cleanup. Audit only needs to confirm no backlog. | none unless audit finds backlog |
| `wp_kiwi_landing_page_sessions` | **14 days** | n/a | **7 days** only with explicit emergency approval | `created_at` | Audit selected the storage-pressure window; implement first as Issue #71 pilot. | Issue 2 / #71 |
| `wp_kiwi_premium_sms_landing_engagements` | **14 days** | n/a | **7 days** only with explicit emergency approval | `created_at` / row timestamp used by repository | Same raw landing default, but implement later. | Issue 2 / #72 |
| `wp_kiwi_landing_handoff_events` | **14 days** | n/a | **7 days** only with explicit emergency approval | `created_at` | Same raw landing default, but implement later. | Issue 2 / #73 |
| `wp_kiwi_sms_body_variant_assignments` | **90 days** | **60 days** | **30 days** | `created_at` | Assignment-level token correlation may be needed longer than raw analytics. Choose from size audit and support needs. | Issue 3 |
| `wp_kiwi_premium_sms_fraud_signals` | **120 days** | **90 days** | not below 90 days without explicit business approval | `occurred_at` or latest billing outcome timestamp | Operational fraud/billing evidence. Choose from support, payout-dispute, chargeback, and fraud-review windows. | Issue 3 |
| `wp_kiwi_nth_events` | **120 days** | **90 days** | not below 90 days without explicit business approval | event timestamp | Provider event audit/reconciliation data. Choose from provider support and reconciliation needs. | Issue 3 |
| `wp_kiwi_nth_flow_transactions` | **120 days after terminal state** | **90 days after terminal state** | never delete non-terminal rows | terminal/completed/updated timestamp | Transaction lifecycle and support/reconciliation data. Only terminal rows are eligible. | Issue 3 |

### Specific answer: when should `wp_kiwi_landing_page_sessions` be cut?

The final default for `wp_kiwi_landing_page_sessions` is **14 complete days**.

Rule:

- keep the most recent **14 complete days**;
- delete only rows with `created_at < start_of_today - 14 days`;
- never delete today's rows or yesterday's rows during the first rollout;
- do not backfill historical missing summaries in Issue #71;
- accept the known old missing summary coverage from `2026-05-15` through
  `2026-05-27` as historical raw evidence that is no longer recoverable;
- keep coverage gates hard for any other new/future missing summary coverage;
- export/archive the full eligible set with `created_at < new_cutoff` before deleting, not only the newly eligible daily slice;
- make the archive import idempotent/deduplicated by original primary key or another stable unique key;
- delete only primary keys proven by the archive batch membership table, streamed in batches from SQLite rather than kept in PHP memory;
- require tkzone summary rows to carry the current configured PID-set hash before accepting them as coverage evidence;
- backfill blank legacy tkzone summary PID-set hashes during schema migration without rebuilding historical summaries;
- run deletes in small batches, ordered by the primary key, with a dry-run count first.

## Required issues to create

### Issue 1: DB size audit, growth analysis, and retention recommendation

Purpose: measure before deleting anything and produce the data needed to choose the final retention window per table.

Tasks:

- Report current database size.
- Report table size, index size, total table+index size, row count, and percentage share of total database volume per relevant table.
- Report oldest/newest timestamp per raw table.
- Estimate average row growth per day for each relevant table, based on daily row counts over the available history.
- Estimate average storage growth per day for each relevant table where possible, using table size, row counts, and date spread as an approximation if exact daily bytes are unavailable.
- Estimate reclaimable rows and approximate storage for these cutoffs:
  - 7 days;
  - 14 days;
  - 30 days;
  - 60 days;
  - 90 days;
  - 120 days;
  - 180 days.
- Verify `wp_kiwi_click_attributions` cleanup is keeping up with its existing TTL.
- Verify main summary coverage by `metric_date` for raw session dates.
- Verify tkzone summary coverage by `metric_date` for raw `pid` values included in the configured tkzone allow-list.
- Recommend a retention window for each table, using the preferred planning windows above unless the size/growth report proves they are unsafe for the Hostinger limit.
- Output a report only. No deletes.

Acceptance criteria:

- A table-size report identifies the largest contributors to the 2.7 GB database.
- Each relevant table includes row count, data size, index size, total size, and percentage share of total DB volume.
- Each relevant table includes average rows/day growth and, where possible, approximate storage/day growth.
- A dry-run retention report shows how many rows and approximately how much storage would be removed by each candidate cutoff.
- The report recommends a concrete retention window per table, explaining whether it chose the preferred, storage-pressure, or emergency window.
- No rows are changed.

### Issue 2: Raw landing analytics retention cleanup

Purpose: implement the first storage-saving cleanup pilot for the largest analytics raw table.

Scope:

- `wp_kiwi_landing_page_sessions`

Out of scope for Issue #71:

- `wp_kiwi_premium_sms_landing_engagements`
- `wp_kiwi_landing_handoff_events`
- the Settings page/UI from Issue #80

Preferred target:

- keep 14 complete days for landing sessions.

Fallbacks:

- allow a configurable emergency fallback to 7 complete days only with explicit approval.

Required safeguards:

- dry-run/report mode;
- explicit enabled flag, default off;
- configurable retention days with minimum 7 and default 30;
- backup/export-before-delete for the full eligible cutoff set, using the relevant cutoff column with `< new_cutoff`;
- deduplicated archive import so repeated or overlapping exports cannot create duplicate archived rows;
- batch deletes with a small limit;
- daily WP-Cron hook plus a manually callable runner;
- transient or option lock to prevent concurrent cleanup;
- per-table counts before and after;
- accept the known old missing summary coverage from `2026-05-15` through
  `2026-05-27`;
- do not delete if any other new/future summary coverage check fails;
- persist cleanup attempts in `wp_kiwi_retention_cleanup_runs`;
- do not add `kiwi_retention_cleanup_last_result`;
- never delete durable summary or sales rows.

Acceptance criteria:

- With cleanup disabled, nothing is deleted.
- Dry-run mode reports eligible row counts without deleting.
- Enabled cleanup deletes only rows older than the retention cutoff.
- Cleanup records the accepted historical coverage gap instead of backfilling it.
- Cleanup refuses to delete when non-accepted main summary coverage is missing for the cutoff period.
- Cleanup refuses to delete for tkzone-allow-listed raw rows when non-accepted tkzone summary coverage is missing for the cutoff period or was built for a different PID allow-list.
- Tests cover cutoff boundaries, dry-run behavior, disabled behavior, summary-coverage failure, batch limiting, and idempotency.

### Issue 3: Fraud/provider audit retention policy and cleanup

Purpose: define and implement a separate policy for operational/audit tables that should not be pruned just because analytics summaries exist.

Scope candidates:

- `wp_kiwi_premium_sms_fraud_signals`
- `wp_kiwi_sms_body_variant_assignments`
- `wp_kiwi_nth_events`
- `wp_kiwi_nth_flow_transactions`

Preferred target:

- keep fraud signals for 120 days;
- keep NTH events for 120 days;
- keep NTH flow transactions for 120 days after terminal state;
- keep SMS body variant assignments for 90 days.

Fallbacks:

- allow 90 days for fraud signals and NTH events if Issue 1 shows 120 days is unsafe for the host limit;
- allow 90 days after terminal state for NTH flow transactions if Issue 1 shows 120 days is unsafe for the host limit;
- allow 60 days for SMS body variant assignments if Issue 1 shows 90 days is unsafe for the host limit;
- do not go below 90 days for fraud/provider audit tables without explicit business approval.

Acceptance criteria:

- Confirms which timestamps represent safe cutoff points.
- Confirms which transaction statuses are terminal.
- Confirms support, payout-dispute, chargeback, and reconciliation windows.
- Implements cleanup separately from landing analytics cleanup.
- Includes dry-run mode, disabled-by-default mode, batch limits, locking, and persisted last result.

### Issue 4: Move final retention policy into permanent docs

Purpose: after Issues 1-3 are implemented and validated in production, move this temporary plan into the permanent architecture/operations documentation.

Acceptance criteria:

- Permanent docs describe actual implemented behavior, not just intent.
- `db-retention-plan.md` is removed or replaced with a short pointer to the permanent docs.
- Changelog notes the implemented retention behavior and operational controls.

### Issue 5: Retention settings page and growth tracking

Purpose: give operators a safe UI for choosing retention windows and enough
historical growth evidence to understand the storage impact of each setting.

Scope:

- add a protected retention settings/readout page;
- expose per-table retention days, cleanup enablement, dry-run status, and last
  cleanup status;
- track daily table growth before cleanup so the UI can show estimated
  MiB/day growth and retention-size projections even after tables are pruned.

Growth tracking contract:

- add a small durable MySQL metadata table
  `wp_kiwi_retention_table_growth_snapshots`;
- write a `before_cleanup` snapshot for every retention-managed table before any
  archive/delete work starts;
- write an `after_cleanup` snapshot after archive/delete completes or after the
  run is skipped/fails in a state where post-run measurement is meaningful;
- store only metadata, not raw subscriber/session payloads;
- include table name, snapshot phase, snapshot date/time, effective retention
  days, cutoff column/value, row count, data size bytes, index size bytes, total
  size bytes, min/max cutoff values, eligible rows, archived rows, deleted rows,
  cleanup run id, and archive batch id where available.

Cleanup run contract:

- add a separate durable MySQL table `wp_kiwi_retention_cleanup_runs`;
- write one row for every cleanup attempt, including skipped and failed runs;
- store run id, table, status, started/finished timestamps, retention days,
  cutoff column/value, eligible rows, archived rows, archive duplicate rows,
  deleted rows, delete batch count, gate statuses, archive batch id, error code,
  and error message;
- do not add an extra WordPress option such as
  `kiwi_retention_cleanup_last_result`; the cleanup-runs table is the source of
  truth for the Settings page.

Growth calculation:

- primary UI metric is observed net growth before cleanup;
- per-day observed growth is calculated as
  `today.before_cleanup.total_size_bytes - previous.after_cleanup.total_size_bytes`;
- 7-day, 30-day, and all-time averages are calculated from those observed daily
  growth values;
- row-count-derived bytes/row estimates may be stored or used as diagnostics,
  but they are not the primary UI growth metric.

Acceptance criteria:

- Settings page shows current retention days and cleanup enabled/dry-run state
  per managed table.
- Settings page shows last cleanup status per table from
  `wp_kiwi_retention_cleanup_runs`.
- Settings page shows 7-day, 30-day, and all-time observed average daily growth
  where enough snapshots exist.
- Settings page shows retention-size projections using observed average growth
  and the selected retention window.
- Growth snapshots are recorded before cleanup, so active pruning does not hide
  true daily growth.
- Failed/skipped cleanup attempts are visible with status, error code, error
  message, and deleted rows.
- Tests cover snapshot writing order, growth averaging, cleanup-run persistence,
  settings readout behavior, and the explicit absence of a last-result
  WordPress option.

## Validation gates before deleting raw analytics rows

A later cleanup implementation may delete rows only after these gates are documented for the proposed date range and table set.

### 1. Reporting read-path inventory

Confirm all user-facing and operational read paths for the target period:

- main `[kiwi_statistics]` UI;
- Statistics CSV export;
- tkzone summary read path;
- legacy/debug views;
- fraud-monitor UI;
- any ad-hoc SQL or operational dashboards used by support.

The inventory must mark each path as reading from durable summary/snapshot tables, raw tables, provider audit tables, or legacy views.

### 2. Summary-vs-raw comparison

For a controlled production period, compare raw facts against durable summaries:

- sessions from `wp_kiwi_landing_page_sessions`;
- page-loaded sessions and CTA1/CTA2/CTA3 sessions/events from `wp_kiwi_premium_sms_landing_engagements`;
- handoff attempts/successes/fails and hidden-time min/max from `wp_kiwi_landing_handoff_events`;
- completed sales and `sales_amount_minor` from `wp_kiwi_sales`;
- main summary dimensions in `wp_kiwi_landing_funnel_daily_summary`;
- tkzone summary dimensions in `wp_kiwi_landing_funnel_daily_tkzone_summary`.

Expected differences must be explained, especially where the main summary intentionally excludes `tkzone`, raw IP, hidden-time median, sale drilldown IDs, engagement-only fallback sessions, and sales without `attribution_metric_date`.

### 3. Sales snapshot completeness

Before pruning raw attribution/session/engagement context, verify that recent and historical confirmed sales have enough durable snapshot fields:

- `landing_key`;
- `session_ref`;
- `click_id`;
- `pid`;
- `tksource`;
- `tkzone`;
- `device_brand`;
- `os`;
- `os_version`;
- `browser`;
- `attribution_metric_date`;
- `client_ip_version`;
- `client_ip_prefix`.

Rows missing critical snapshot fields must be repaired, accepted as `(unknown)` with written rationale, or excluded from cleanup scope.

### 4. `(unknown)` bucket review

Review `(unknown)` rates before cleanup:

- main summary dimensions;
- tkzone summary source dimensions;
- sales snapshot dimensions;
- normalized device/browser buckets;
- client-IP bucket fields.

High `(unknown)` rates are cleanup blockers when raw rows are still needed to diagnose or repair the missing dimensions.

### 5. Fraud and billing-review gate

Do not treat successful analytics summary validation as approval to prune fraud evidence.

Before pruning `wp_kiwi_premium_sms_landing_engagements`, `wp_kiwi_premium_sms_fraud_signals`, NTH events, or transaction lifecycle rows, separately validate:

- pending-MT retry behavior;
- completed-sale cooldown behavior;
- terminal failed billing reports;
- subscriber-level fraud snapshots;
- normalized aggregator status fields;
- fraud-monitor UI filters and exports;
- payout dispute, chargeback, and customer-support windows.

### 6. Recompute and rollback gate

Confirm the operational answer to these questions:

- How far back must the main daily summary be recomputable from raw rows?
- How far back must the tkzone daily summary be recomputable from raw rows?
- Are database backups retained long enough to restore raw evidence after cleanup?
- What is the rollback plan if cleanup removes rows that are still needed?
- Who signs off on the final retention window per table?

## Current recommendation

Current implementation order:

1. Finish/formalize the audit issue if needed.
2. Implement Issue #71 as the pilot for `wp_kiwi_landing_page_sessions` only.
3. Reuse the infrastructure for `wp_kiwi_premium_sms_landing_engagements` and `wp_kiwi_landing_handoff_events`.
4. Implement the Settings page/growth readout in Issue #80 after cleanup/run/snapshot contracts exist.
5. Implement fraud/provider audit cleanup issues and then move final policy into permanent docs.

Until those issues are complete:

- keep `wp_kiwi_sales`, `wp_kiwi_landing_funnel_daily_summary`, and `wp_kiwi_landing_funnel_daily_tkzone_summary` as long-lived reporting sources;
- leave the existing `wp_kiwi_click_attributions` TTL cleanup unchanged;
- do not manually prune `wp_kiwi_landing_page_sessions`, `wp_kiwi_premium_sms_landing_engagements`, or `wp_kiwi_landing_handoff_events` without the Issue 1 audit and Issue 2 safeguards;
- treat `wp_kiwi_premium_sms_fraud_signals`, `wp_kiwi_nth_events`, and `wp_kiwi_nth_flow_transactions` as separate operational/audit retention topics, not as simple analytics raw tables.
