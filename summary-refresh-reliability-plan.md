# Landing Summary Refresh Reliability Plan

Working draft for fixing the landing funnel daily summary refresh jobs after the
retention rollout exposed that the current combined WP-Cron job does not finish
reliably.

## Current production evidence

- `kiwi_landing_funnel_daily_summary_refresh` is scheduled hourly.
- The stored `kiwi_landing_funnel_daily_summary_refresh_last_result` is stale
  since `2026-06-12 08:12:49`.
- `wp_kiwi_landing_funnel_daily_summary` is still being partly refreshed.
  On `2026-06-29`, rows existed through metric date `2026-06-26`.
- `wp_kiwi_landing_funnel_daily_tkzone_summary` stopped at metric date
  `2026-06-13`, despite ongoing PID `106` TK traffic.
- Example current TK raw row: `wp_kiwi_landing_page_sessions.id = 726752`
  on `2026-06-29`, `pid = 106`, with `tksource` and `tkzone` populated.
- The current rolling refresh range on `2026-06-29` was `2026-06-22` through
  `2026-06-29`.
- The main summary appeared to process roughly five days before stopping:
  `2026-06-22` through `2026-06-26`.
- Per-day main refresh timestamps suggest roughly `18-26s` per metric day in
  production, so an eight-day combined main + TK-zone run can hit hosting or
  request runtime limits.

## Likely failure mode

The combined refresh job runs in this order:

1. Main landing funnel daily summary refresh.
2. TK-zone landing funnel daily summary refresh.
3. Combined result persistence and log entry.

If the request is killed after the main refresh has processed some days, the
database can contain partial main summary updates while:

- TK-zone summary never runs;
- `last_result` is not updated;
- logs may not show a clean failure;
- downstream retention gates see missing TK-zone coverage.

The root issue is probably not that TK traffic stopped. PID `106` raw rows still
exist daily. The operational problem is that the combined summary refresh is too
large and too coupled for one WP-Cron request.

## Goals

- Make main summary refresh and TK-zone summary refresh independently reliable.
- Ensure one stuck or slow summary path cannot starve the other.
- Persist useful run status even for partial progress or failures.
- Keep retention cleanup dependent on completed summary coverage, not on a
  fragile combined job.
- Avoid broad rewrites of the summary SQL unless measurement proves they are
  needed.

## Draft implementation direction

1. Split the combined cron into two dedicated hooks:
   - `kiwi_landing_funnel_daily_main_summary_refresh`
   - `kiwi_landing_funnel_daily_tkzone_summary_refresh`
2. Give each job its own lock:
   - main summary lock;
   - TK-zone summary lock.
3. Give each job its own last-result option:
   - `kiwi_landing_funnel_daily_main_summary_refresh_last_result`
   - `kiwi_landing_funnel_daily_tkzone_summary_refresh_last_result`
4. Keep the old combined hook only as a migration/compatibility concern:
   - unschedule it once the split hooks are active, or
   - make it a lightweight dispatcher only if that is safer.
5. Process smaller work units:
   - Prefer one metric day per cron invocation, or a small configurable max
     such as one or two days.
   - Persist after each metric date, not only after the full rolling range.
6. Stagger schedules:
   - Main summary first.
   - TK-zone summary a few minutes later.
   - Retention later, e.g. `01:00` or `02:00`, after both summary jobs had time
     to catch up.
7. Keep rolling refresh behavior, but make catch-up explicit:
   - Use the configured rolling window to decide eligible dates.
   - Track or infer which date still needs refresh.
   - Avoid retrying the entire eight-day range in one request.
8. Add log tags that clearly distinguish:
   - main summary refresh;
   - TK-zone summary refresh;
   - skipped due to lock;
   - per-day success/failure;
   - full catch-up complete.

## Operational recovery after deployment

After the split-job fix is deployed:

1. Backfill main summary for missing or stale dates, likely `2026-06-27` onward.
2. Backfill TK-zone summary from `2026-06-14` onward.
3. Re-run the retention coverage gate for the current cutoff.
4. Verify `wp_kiwi_retention_cleanup_runs` shows a completed run before trusting
   automatic deletion.
5. Confirm the SQLite archive file exists under
   `/home/u367252972/kiwi-backend-archives/db-retention/sqlite/`.

## Open questions

- Should the split jobs run hourly, daily, or both with catch-up behavior?
- Should each cron invocation process exactly one date, or a small max like two
  dates while staying below runtime limits?
- Should TK-zone refresh depend on main summary freshness, or remain fully
  independent from raw tables?
- Do we need a durable summary-refresh-runs table, or are separate last-result
  options plus logs enough for now?
- Should retention automatically skip when either summary job has stale
  last-result metadata, in addition to the existing coverage gate?
- Should the old combined hook be unscheduled immediately on deploy, or left as
  a dispatcher for one release?

## Immediate next checks

- Measure single-day main refresh runtime for recent heavy days.
- Measure single-day TK-zone refresh runtime for recent heavy days.
- Inspect query plans for the current handoff attribution joins if a single-day
  runtime is still too high.
- Decide the exact schedule offsets relative to retention.

## Production timing sample on 2026-06-29

Measured by running real single-day refreshes through the deployed services on
production. These runs are idempotent but do rewrite the selected summary dates.

Main summary:

| Metric date | Runtime | Deleted | Inserted |
| --- | ---: | ---: | ---: |
| `2026-06-22` | `27.163s` | 7,555 | 7,555 |
| `2026-06-26` | `29.267s` | 7,098 | 7,098 |
| `2026-06-27` | `30.228s` | 0 | 7,402 |
| `2026-06-28` | `16.832s` | 0 | 6,728 |
| `2026-06-29` | `2.405s` | 0 | 2,818 |

TK-zone summary for PID set `[106]`:

| Metric date | Runtime | Deleted | Inserted |
| --- | ---: | ---: | ---: |
| `2026-06-22` | `8.978s` | 0 | 782 |
| `2026-06-26` | `5.749s` | 0 | 806 |
| `2026-06-27` | `6.815s` | 0 | 919 |
| `2026-06-28` | `5.788s` | 0 | 1,183 |
| `2026-06-29` | `0.655s` | 0 | 679 |

Takeaway: the optimized engagement join is present, but one full rolling window
is still too much for a single combined WP-Cron request. A typical complete
recent day costs roughly 17-30 seconds for main plus 6-9 seconds for TK-zone.
Eight days in one combined run can therefore exceed a 120-second hosting/runtime
limit before result persistence.

## Main summary optimization finding

Follow-up read-only diagnostics on `2026-06-27` showed that the old slow
`engagement_sessions` CTE is not the current bottleneck. The deployed code
already uses the optimized direct engagement join from PR #62.

Measured CTE stages for the current main query:

| Stage | Runtime | Notes |
| --- | ---: | --- |
| `landing_loads` | `0.387s` | 8,338 canonical sessions |
| `handoff_origin_events` | `0.468s` | 25,918 handoff-origin rows |
| `handoff_by_session` | `0.510s` | 9,885 grouped handoff rows |
| `session_facts` | `19.000s` | bottleneck |
| `all_facts` | `26.204s` | includes slow `session_facts` |
| `aggregated` | `28.859s` | 7,402 output rows |

The likely bottleneck is the same optimizer pattern as the old engagement issue:
`handoff_by_session` is a materialized CTE and then joined back to
`landing_loads` without a useful join index.

A read-only prototype that removes the materialized `handoff_by_session` join
and instead joins `wp_kiwi_landing_handoff_events` directly to each canonical
landing session, while preserving latest-landing-before-event attribution with
an anti-join, measured much faster:

| Query shape | Runtime | Rows | Sessions | Handoff attempts | Sales |
| --- | ---: | ---: | ---: | ---: | ---: |
| Current full aggregate SELECT | `28.588s` | 7,402 | 8,338 | not measured in that run | not measured in that run |
| Prototype direct handoff join aggregate SELECT | `2.027s` | 7,402 | 8,338 | 7,297 | 53 |

This is promising but must be implemented with regression tests for
cross-midnight token reuse, next-day handoff carryover, and session-level
handoff counts before replacing the production query.

## Logging

Cron-job "overflows" / breaks like this should be logged somewhere, so that maybe an automation agent can check these logs on a daily level and alert me if something goes wrong
