# DB Retention Audit - 2026-06-17

## Scope

This is the read-only production database audit for GitHub Issue `#70`.
It measures database size, table shares, raw-table growth, dry-run retention
cutoffs, and summary coverage before any cleanup implementation.

No rows were changed. The audit used the Hostinger SSH/WP-CLI backend path
`domains/kiwimobile.de/public_html/backend`, verified WordPress prefix `wp_`,
and ran only aggregate `SELECT` statements, `information_schema` reads, and a
read-only `wp eval` to resolve the tkzone summary PID allow-list.

Review follow-up: on `2026-06-17 10:02:04 UTC`, the fraud-signal dry-run was
rerun with the latest relevant evidence timestamp:
`GREATEST(occurred_at, billing_outcome_at, sale_completed_at)`. That avoids
marking a fraud row eligible from the original MO timestamp when later billing
or sale evidence was written after the MO.

Audit clock:

- DB `NOW()`: `2026-06-17 09:49:36`
- DB time zone: `SYSTEM` / `UTC`
- Berlin equivalent: `2026-06-17 11:49:36 CEST`
- Tkzone summary PID allow-list: `106`

## Executive Summary

The active backend WordPress database is `2472.53 MiB` (`2.415 GiB`) in
`information_schema.TABLES`. Against the current Hostinger limit described as
about `3 GB`, that leaves roughly `407 MB` if interpreted as decimal GB, or
roughly `599 MiB` if interpreted as `3 GiB`. This is a storage-pressure state,
not an emergency outage state.

The largest storage contributors are:

1. `wp_kiwi_landing_page_sessions`: `1347.34 MiB`, `54.49%`
2. `wp_kiwi_premium_sms_landing_engagements`: `365.08 MiB`, `14.77%`
3. `wp_kiwi_sms_body_variant_assignments`: `334.94 MiB`, `13.55%`
4. `wp_kiwi_landing_handoff_events`: `211.70 MiB`, `8.56%`
5. `wp_kiwi_landing_funnel_daily_summary`: `93.45 MiB`, `3.78%`

The existing click-attribution TTL cleanup is keeping up: `0` expired rows were
found in `wp_kiwi_click_attributions`.

The raw landing tables should not be pruned yet. Main daily-summary coverage is
missing for raw session days `2026-05-15` through `2026-05-27` and
`2026-06-14` through `2026-06-17`. A later cleanup must either refresh/backfill
the missing summary dates or get explicit approval to accept those missing early
raw dates before deleting raw evidence.

## Size By Table

Sizes are from `information_schema.TABLES`; exact row counts are separate
`COUNT(*)` reads. `information_schema.TABLES.table_rows` was not used as the
row-count source because it is approximate for InnoDB.

| Table | Exact rows | Data MiB | Index MiB | Total MiB | DB share |
| --- | ---: | ---: | ---: | ---: | ---: |
| `wp_kiwi_landing_page_sessions` | 461,914 | 1098.14 | 249.20 | 1347.34 | 54.49% |
| `wp_kiwi_premium_sms_landing_engagements` | 448,779 | 171.73 | 193.34 | 365.08 | 14.77% |
| `wp_kiwi_sms_body_variant_assignments` | 432,517 | 126.67 | 208.27 | 334.94 | 13.55% |
| `wp_kiwi_landing_handoff_events` | 146,267 | 123.66 | 88.05 | 211.70 | 8.56% |
| `wp_kiwi_landing_funnel_daily_summary` | 75,335 | 33.08 | 60.38 | 93.45 | 3.78% |
| `wp_kiwi_click_attributions` | 7,747 | 9.02 | 23.22 | 32.23 | 1.30% |
| `wp_kiwi_sales` | 869 | 27.44 | 1.13 | 28.56 | 1.16% |
| `wp_kiwi_nth_flow_transactions` | 1,026 | 17.50 | 0.45 | 17.95 | 0.73% |
| `wp_kiwi_landing_funnel_daily_tkzone_summary` | 12,280 | 5.02 | 11.13 | 16.14 | 0.65% |
| `wp_kiwi_nth_events` | 4,158 | 8.52 | 1.30 | 9.81 | 0.40% |
| `wp_kiwi_premium_sms_fraud_signals` | 1,334 | 5.52 | 1.02 | 6.53 | 0.26% |
| `wp_kiwi_dimoco_refund_callbacks` | n/a | 0.14 | 0.09 | 0.23 | 0.01% |
| `wp_kiwi_dimoco_blacklist_callbacks` | n/a | 0.08 | 0.14 | 0.22 | 0.01% |
| `wp_kiwi_dimoco_operator_lookup_callbacks` | n/a | 0.09 | 0.13 | 0.22 | 0.01% |
| `wp_kiwi_sms_body_variant_summary` | 126 | 0.05 | 0.13 | 0.17 | 0.01% |
| `wp_kiwi_blacklist_action_queue` | n/a | 0.02 | 0.11 | 0.13 | 0.01% |
| `wp_kiwi_device_model_brand_map` | 229 | 0.06 | 0.03 | 0.09 | 0.00% |
| `wp_kiwi_landing_kpi_summary` | 8 | 0.02 | 0.08 | 0.09 | 0.00% |

## Raw And Audit Table Growth

Approximate storage growth is calculated as:

`table_total_bytes / exact_rows * average_rows_per_day`

This approximates table plus index bytes per row. It does not mean MySQL will
return the same bytes to the Hostinger quota immediately after deletes.

| Table | Cutoff basis | Oldest | Newest | Rows/day over full span | Rows/day last 30d | Approx MiB/day last 30d |
| --- | --- | --- | --- | ---: | ---: | ---: |
| `wp_kiwi_click_attributions` | `created_at` / `expires_at` | `2026-05-26 22:25:22` | `2026-06-17 11:47:52` | 336.83 | 258.23 | 1.07 |
| `wp_kiwi_landing_page_sessions` | `created_at` | `2026-05-15 00:00:02` | `2026-06-17 11:48:34` | 13,585.71 | 14,686.23 | 42.84 |
| `wp_kiwi_premium_sms_landing_engagements` | `created_at` | `2026-04-23 12:51:39` | `2026-06-17 11:48:35` | 8,013.91 | 10,679.67 | 8.69 |
| `wp_kiwi_landing_handoff_events` | `created_at` | `2026-05-07 23:02:36` | `2026-06-17 11:48:32` | 3,482.60 | 4,746.23 | 6.87 |
| `wp_kiwi_sms_body_variant_assignments` | `created_at` | `2026-05-08 08:57:57` | `2026-06-17 11:47:52` | 10,549.20 | 9,932.53 | 7.69 |
| `wp_kiwi_premium_sms_fraud_signals` | latest of `occurred_at`, `billing_outcome_at`, `sale_completed_at` | `2026-05-06 17:11:21` | `2026-06-17 11:56:44` | 31.02 | 43.67 | 0.21 |
| `wp_kiwi_nth_events` | `occurred_at` | `2026-04-09 14:33:56` | `2026-06-17 11:32:16` | 59.40 | 135.83 | 0.32 |
| `wp_kiwi_nth_flow_transactions` | terminal `updated_at` | `2026-04-09 16:04:17` | `2026-06-17 11:32:17` | 14.61 | 33.43 | 0.58 |

NTH transaction state:

- Rows total: `1,026`
- Terminal rows: `1,023`
- Non-terminal rows: `3`
- Non-terminal rows must not be eligible for retention cleanup.

## Dry-Run Retention Cutoffs

Cutoffs use DB `CURDATE()` (`2026-06-17`) and count rows where the cutoff
timestamp is older than start-of-day minus the retention window. Values are
`eligible rows (approx MiB)`.

| Table | Basis | 7d | 14d | 30d | 60d | 90d | 120d | 180d |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| `wp_kiwi_click_attributions` | `expires_at` | 0 (0.00) | 0 (0.00) | 0 (0.00) | 0 (0.00) | 0 (0.00) | 0 (0.00) | 0 (0.00) |
| `wp_kiwi_landing_page_sessions` | `created_at` | 382,514 (1115.74) | 342,490 (999.00) | 21,327 (62.21) | 0 (0.00) | 0 (0.00) | 0 (0.00) | 0 (0.00) |
| `wp_kiwi_premium_sms_landing_engagements` | `created_at` | 389,425 (316.80) | 360,433 (293.21) | 128,389 (104.44) | 0 (0.00) | 0 (0.00) | 0 (0.00) | 0 (0.00) |
| `wp_kiwi_landing_handoff_events` | `created_at` | 54,983 (79.58) | 24,400 (35.32) | 3,882 (5.62) | 0 (0.00) | 0 (0.00) | 0 (0.00) | 0 (0.00) |
| `wp_kiwi_sms_body_variant_assignments` | `created_at` | 402,434 (311.64) | 380,348 (294.54) | 134,541 (104.19) | 0 (0.00) | 0 (0.00) | 0 (0.00) | 0 (0.00) |
| `wp_kiwi_premium_sms_fraud_signals` | latest evidence timestamp | 601 (2.94) | 154 (0.75) | 24 (0.12) | 0 (0.00) | 0 (0.00) | 0 (0.00) | 0 (0.00) |
| `wp_kiwi_nth_events` | `occurred_at` | 1,306 (3.08) | 328 (0.77) | 83 (0.20) | 22 (0.05) | 0 (0.00) | 0 (0.00) | 0 (0.00) |
| `wp_kiwi_nth_flow_transactions` | terminal `updated_at` | 313 (5.48) | 80 (1.40) | 20 (0.35) | 4 (0.07) | 0 (0.00) | 0 (0.00) | 0 (0.00) |

Landing raw table totals:

- 30-day landing raw cleanup would reclaim about `172.27 MiB`.
- 14-day landing raw cleanup would reclaim about `1327.53 MiB`.
- 7-day landing raw cleanup would reclaim about `1512.12 MiB`.

The extra gain from 7 days over 14 days is only about `184.59 MiB`, so 7 days
is not justified as the default recommendation.

## Click Attribution TTL Backlog

`wp_kiwi_click_attributions` uses the existing 48-hour TTL (`expires_at`).
The audit found:

- Rows total: `7,747`
- Expired rows: `0`
- Expired share: `0.00%`

No TTL-cleanup backlog was found. No TTL configuration change is recommended
from this audit.

## Summary Coverage

Main summary:

- Raw session days: `34`
- Covered days: `17`
- Missing days: `17`
- Raw session range: `2026-05-15` through `2026-06-17`
- Summary range: `2026-05-28` through `2026-06-13`
- Missing raw session dates:
  - `2026-05-15` through `2026-05-27`
  - `2026-06-14` through `2026-06-17`

Tkzone summary for allow-listed PID `106`:

- Raw PID days: `20`
- Covered days: `15`
- Missing days: `5`
- Raw PID range: `2026-05-29` through `2026-06-17`
- Summary range: `2026-05-29` through `2026-06-12`
- Missing raw PID dates:
  - `2026-06-13` through `2026-06-17`

Cleanup implication:

- Any landing raw cleanup must check coverage only for dates it would delete.
- A 14-day cleanup on `2026-06-17` would delete rows before `2026-06-03`.
  Main summary is missing `2026-05-15` through `2026-05-27`, so the cleanup
  must be blocked until those dates are refreshed/backfilled or explicitly
  accepted as non-recoverable historical raw dates.
- The current tkzone missing dates are recent and are not part of the
  `2026-06-17` 14-day eligible range, but the tkzone refresh lag must be fixed
  before those dates become eligible in a later rolling cleanup.

## Recommendations

| Table | Recommendation | Choice | Reason |
| --- | --- | --- | --- |
| `wp_kiwi_landing_funnel_daily_summary` | Keep indefinitely | preferred | Primary durable main reporting source. |
| `wp_kiwi_landing_funnel_daily_tkzone_summary` | Keep indefinitely | preferred | Durable tkzone reporting source. |
| `wp_kiwi_sales` | Keep indefinitely | preferred | Confirmed-sale fact and payout/revenue evidence. |
| `wp_kiwi_landing_kpi_summary` | Keep indefinitely | preferred | Small aggregate table. |
| `wp_kiwi_sms_body_variant_summary` | Keep indefinitely | preferred | Small aggregate experiment summary. |
| `wp_kiwi_device_model_brand_map` | Keep indefinitely | preferred | Reference/configuration table. |
| `wp_kiwi_click_attributions` | Keep existing 48-hour TTL | preferred | TTL cleanup has no expired backlog. |
| `wp_kiwi_landing_page_sessions` | Keep 14 complete days | storage-pressure | 30 days would reclaim only about `62.21 MiB` from the largest table; 14 days reclaims about `999.00 MiB` while preserving more debug history than 7 days. |
| `wp_kiwi_premium_sms_landing_engagements` | Keep 14 complete days | storage-pressure | 14 days reclaims about `293.21 MiB`; 7 days adds only about `23.59 MiB` more. |
| `wp_kiwi_landing_handoff_events` | Keep 14 complete days | storage-pressure | 14 days reclaims about `35.32 MiB`; 7 days adds about `44.26 MiB`, but handoff diagnostics are lower volume and still useful for recent debugging. |
| `wp_kiwi_sms_body_variant_assignments` | Keep 90 complete days | preferred | Assignment correlation is operationally useful and the table is not old enough for 60/90-day reclaim. Revisit 60 days only if landing raw cleanup cannot land quickly. |
| `wp_kiwi_premium_sms_fraud_signals` | Keep 120 complete days after latest evidence timestamp | preferred | Small table and operational fraud/billing evidence. Eligibility must use the latest of `occurred_at`, `billing_outcome_at`, and `sale_completed_at`, not the original MO timestamp alone. Do not go below 90 days without business approval. |
| `wp_kiwi_nth_events` | Keep 120 complete days | preferred | Small provider audit/reconciliation table. Do not go below 90 days without business approval. |
| `wp_kiwi_nth_flow_transactions` | Keep 120 days after terminal `updated_at`; never prune non-terminal rows | preferred | Small lifecycle table; 3 non-terminal rows remain ineligible regardless of age. |

## Follow-Up Gates For Cleanup Issues

Issue 2, raw landing analytics cleanup:

- Implement 14 complete days as the storage-pressure retention target.
- Keep cleanup disabled by default until explicitly enabled.
- Run a dry-run first.
- Block deletion when main summary coverage is missing for any raw session date
  that would be deleted.
- Check tkzone coverage for allow-listed PIDs before deleting allow-listed raw
  rows for dates that would be deleted.
- Delete in small batches ordered by primary key.
- Persist last cleanup result and counts before/after.

Issue 3, fraud/provider audit cleanup:

- Keep separate from landing analytics cleanup.
- Use 120 days for fraud signals and NTH audit tables unless a later audit or
  business decision approves a 90-day storage-pressure fallback.
- For fraud signals, use the latest relevant evidence timestamp:
  `GREATEST(occurred_at, billing_outcome_at, sale_completed_at)`.
- For NTH flow transactions, count only `is_terminal = 1` rows as eligible.
- Never delete non-terminal transactions.

## Methodology Appendix

Representative query patterns:

```sql
SELECT
    SUM(data_length + index_length) AS total_bytes
FROM information_schema.TABLES
WHERE table_schema = DATABASE()
  AND table_type = 'BASE TABLE';
```

```sql
SELECT
    table_name,
    data_length,
    index_length,
    data_length + index_length AS total_bytes
FROM information_schema.TABLES
WHERE table_schema = DATABASE()
  AND table_type = 'BASE TABLE'
  AND table_name LIKE 'wp_kiwi\\_%' ESCAPE '\\';
```

```sql
SELECT COUNT(*) AS exact_rows
FROM wp_kiwi_landing_page_sessions;
```

```sql
SELECT
    COUNT(*) AS rows_total,
    MIN(created_at) AS oldest_created_at,
    MAX(created_at) AS newest_created_at,
    COUNT(DISTINCT DATE(created_at)) AS active_days
FROM wp_kiwi_landing_page_sessions;
```

```sql
SELECT
    SUM(created_at < DATE_SUB(CURDATE(), INTERVAL 14 DAY)) AS eligible_rows
FROM wp_kiwi_landing_page_sessions;
```

```sql
SELECT
    SUM(
        GREATEST(
            occurred_at,
            COALESCE(billing_outcome_at, '1000-01-01 00:00:00'),
            COALESCE(sale_completed_at, '1000-01-01 00:00:00')
        ) < DATE_SUB(CURDATE(), INTERVAL 120 DAY)
    ) AS eligible_fraud_rows
FROM wp_kiwi_premium_sms_fraud_signals;
```

```sql
SELECT
    COUNT(*) AS raw_session_days,
    SUM(s.metric_date IS NOT NULL) AS covered_days,
    SUM(s.metric_date IS NULL) AS missing_days
FROM (
    SELECT DISTINCT DATE(created_at) AS metric_date
    FROM wp_kiwi_landing_page_sessions
) r
LEFT JOIN (
    SELECT DISTINCT metric_date
    FROM wp_kiwi_landing_funnel_daily_summary
) s ON s.metric_date = r.metric_date;
```
