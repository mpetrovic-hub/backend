# Landing Funnel Analytics

## Read when

- Work touches `[kiwi_statistics]`, landing KPI events, funnel summaries, TK-zone summaries, handoff analytics, SMS-body variants, device/source dimensions, or analytics exports.

## Source of truth for

- Landing KPI event and storage behavior.
- Traffic-source funnel statistics.
- Main and TK-zone daily summary behavior.
- Analytics table/view meanings.

## Not here

- Landing-page routing/runtime: see `landing-page-runtime.md`.
- Click attribution architecture and affiliate postback boundary: see `../architecture/click-attribution-and-postbacks.md`.
- Raw retention archive/delete behavior: see `retention-runbook.md`.
- Config constant list: see `configuration-reference.md`.

## Landing-page KPI tracking

The landing-page system supports a generic KPI funnel for optimization analysis.

Event model:

- `clicks`: incremented in the central landing-page router when a landing page is rendered.
- `cta1`, `cta2`, `cta3`: incremented through the KPI event endpoint into one summary row per landing page.
- Landing engagement events: stored per landing/session for `page_loaded` and `cta_click`; `cta_click` can include `cta_step=cta1|cta2|cta3` for step-specific engagement columns.
- SMS handoff events: stored separately for `sms:`/`smsto:` CTA diagnostics; supported events are `sms_handoff_attempted`, `sms_handoff_hidden`, `sms_handoff_returned`, and `sms_handoff_no_hide`.
- SMS body variant events: assignment, CTA1, handoff, and conversion counters are aggregated by landing/service/variant/seed.
- `conv`: incremented once on first confirmed conversion match in attribution resolver.

Engagement, handoff, and SMS-body variant events do not mutate `wp_kiwi_landing_kpi_summary` unless explicitly listed above.

### Per-landing selector mapping

Each filesystem landing page can map KPI steps to selectors in `integration.php`:

```php
'kpi_cta_steps' => [
    'cta1' => 'class="cta"',
    'cta2' => '.mobile_number_input',
    'cta3' => '#confirm-button',
],
```

Rules:

- v1 step keys are limited to `cta1`, `cta2`, and `cta3`.
- selector values can be CSS selectors.
- shorthand `class="cta"` is normalized to `.cta`.
- if no mapping is provided, router defaults to `cta1 => .cta`.

### REST endpoints

- `POST /wp-json/kiwi-backend/v1/landing-kpi/event`
  - increments CTA summary counters
  - records engagement events
  - accepts `cta_step=cta1|cta2|cta3` only for `cta_click` engagement storage
  - records SMS handoff diagnostics without changing summary counters
- `GET /wp-json/kiwi-backend/v1/landing-kpi/report`
  - returns per-landing KPI rows with counts and rates
  - supports optional `days` and `landing_key` filters; `days` is accepted for compatibility while summary output is all-time

## Statistics UI and CSV

The protected `[kiwi_statistics]` shortcode reads primarily from `wp_kiwi_landing_funnel_daily_summary` so larger date ranges can be filtered and pivoted without rebuilding legacy transition views on every request.

Supported normal filters:

- date range
- `service_key`
- `landing_key`
- `tksource`
- `device_brand`
- `os`
- `os_version`
- `browser`

`tkzone` is intentionally excluded from the main summary UI/CSV path. It remains available through the TK-zone daily summary and legacy/debug views.

Coarse IP dimensions remain output columns and can still be used by internal diagnostic URL filters. They are not offered as normal dropdown filters because `client_ip_prefix` is cardinality-sensitive.

The main summary UI/CSV does not emit sale ID lists, transaction ID lists, `tkzone`, hidden-time median, raw `client_ip`, or `client_ip_hash`.

## Key tables and views

- `wp_kiwi_landing_page_sessions`: canonical landing-session facts captured by the server-side router, including provider/flow/country, source parameters, normalized browser language, normalized device buckets, and coarse client-IP buckets.
- `wp_kiwi_device_model_brand_map`: optional exact model-to-brand mapping used before heuristic brand rules; `(unknown)` entries do not stop safe built-in heuristics.
- `wp_kiwi_click_attributions`: temporary server-side attribution state with click ID, internal `transaction_id`, refs, postback audit, and TTL expiry.
- `wp_kiwi_sales`: durable confirmed sale records with service/landing/session/source/device/IP snapshots and `attribution_metric_date`.
- `wp_kiwi_premium_sms_landing_engagements`: landing-session engagement evidence, step-specific CTA evidence, source snapshots, and optional raw UA context.
- `wp_kiwi_landing_handoff_events`: click-to-SMS handoff evidence with source snapshots and optional UA Client Hints.
- `wp_kiwi_sms_body_variant_assignments`: visible SMS token assignments for the FR click-to-SMS experiment.
- `wp_kiwi_sms_body_variant_summary`: aggregated SMS body variant metrics.
- `wp_kiwi_premium_sms_fraud_signals`: MO fraud snapshots per subscriber identity with source and billing/sale/aggregator-status snapshots.
- `wp_kiwi_v_load_to_cta_by_tksource_tkzone`: plugin-managed legacy/debug view for traffic-source funnel analysis, using `2026-05-12 20:00:00` as the default lower bound for reliable `tksource`/`tkzone` data.
- `wp_kiwi_v_one_for_all`: plugin-managed analytics view for pivot/export work outside the shortcode UI.

## Main daily summary

`wp_kiwi_landing_funnel_daily_summary` is the persistent target model for daily landing-funnel analytics.

It groups by:

- `metric_date`
- `landing_key`
- `service_key`
- `provider_key`
- `flow_key`
- `country`
- `pid`
- `tksource`
- `device_brand`
- `os`
- `os_version`
- `browser`
- `client_ip_version`
- `client_ip_prefix`

Main rules:

- `dimension_hash` is unique with `metric_date`.
- `tkzone` is deliberately outside the main summary.
- Sessions come from distinct canonical `landing_key + session_token` rows only.
- Engagement and handoff rows join to canonical sessions and do not create fallback sessions or repair missing dimensions from later event rows.
- CTA metrics use step-specific CTA1/CTA2/CTA3 engagement columns.
- Handoff metrics use event counts under the unique `(landing_key, session_token, handoff_id, event_type)` contract and include hidden-time min/max only.
- Completed sales use durable `wp_kiwi_sales` snapshot columns and are included only when `attribution_metric_date` falls into the refreshed day.
- Sales without `attribution_metric_date` are excluded until repaired or backfilled.
- IPv4 is bucketed as `/24`, IPv6 as `/48`, and missing or invalid values use `(unknown)`.
- Raw `client_ip` and `client_ip_hash` are not stored or exported by the main summary.
- Same-session cross-midnight handoff attempts and hidden/success events are kept together by scanning next-day handoff rows and rejecting reused tokens owned by a later landing day.

Refresh behavior:

- The public contract is date-range bounded, but processing is split into independent `metric_date` day chunks.
- Each day chunk deletes and reinserts only its target `metric_date`.
- Repeated runs are idempotent.
- WP-Cron hook `kiwi_landing_funnel_daily_main_summary_refresh` refreshes one due metric date per invocation.
- Transient lock `kiwi_landing_funnel_daily_main_summary_refresh_lock` prevents concurrent Main runs.
- Last non-lock result is stored in `kiwi_landing_funnel_daily_main_summary_refresh_last_result`.
- Lock skips are stored in `kiwi_landing_funnel_daily_main_summary_refresh_lock_skip_last_result`.

## TK-zone daily summary

`wp_kiwi_landing_funnel_daily_tkzone_summary` is the plugin-managed companion table for zone analysis.

Rules:

- It preserves `tkzone`-level sessions, CTA, handoff, and sales metrics for diagnostics and optimization work.
- It stores the configured PID allow-list hash on refreshed rows.
- The PID allow-list is configured by `KIWI_LANDING_FUNNEL_TKZONE_SUMMARY_PIDS` and defaults to `['106']`.
- Normal reads and filter options require the current hash so rows generated for older PID sets are not mixed into current reports.
- WP-Cron hook `kiwi_landing_funnel_daily_tkzone_summary_refresh` runs separately from the Main summary.
- Transient lock `kiwi_landing_funnel_daily_tkzone_summary_refresh_lock` prevents concurrent TK-zone runs.
- Last non-lock and lock-skip results are stored separately.
- The legacy combined hook `kiwi_landing_funnel_daily_summary_refresh` is cleared during bootstrap and should not be re-enabled for normal production refreshes.

## Device, source, and IP handling

`wp_kiwi_landing_page_sessions` is the canonical source for server-known session dimensions. It stores provider, flow, campaign/service country, `pid`, `tksource`, `tkzone`, normalized primary `browser_language`, normalized device buckets, and coarse IP buckets on first server request.

Source parameters are stored even when no `click_id` is present. `browser_language` stores only the primary language tag such as `fr`, `de`, `en`, or `ar`; empty or invalid headers use `(unknown)`.

Client IPs are resolved by `Kiwi_Client_Ip_Resolver`: forwarded headers are accepted only when the direct peer matches explicit trusted proxy configuration. If the direct peer is trusted but no usable forwarded client candidate is present, the resolver keeps `(unknown)` instead of treating the proxy address as the client.

When `page_loaded` UA Client Hints arrive later, the landing KPI endpoint can enrich matching session rows without allowing `(unknown)` values to overwrite known buckets.

The device model harvester can add frequently observed unknown `ua_ch_model` values into `wp_kiwi_device_model_brand_map` as `(unknown)` review placeholders after they meet `KIWI_DEVICE_MODEL_BRAND_HARVEST_MIN_DAILY_SESSIONS`. Those placeholders preserve auditability but do not block built-in heuristic brand rules.

## Operational checks

When validating analytics behavior:

1. Verify `[kiwi_statistics]` reads `wp_kiwi_landing_funnel_daily_summary`.
2. Verify default lower bound `2026-05-12` where legacy traffic-source reliability matters.
3. Verify date, service, landing, TK-source, device-brand, OS, OS-version, and browser filters.
4. Verify CSV output omits `tkzone`, hidden-time median, raw `client_ip`, and `client_ip_hash`.
5. Verify CTA1/CTA2/CTA3 engagement columns increase only for matching `cta_step` payloads while legacy generic CTA count still increases for every valid `cta_click`.
6. Verify `wp_kiwi_v_one_for_all` remains available for pivot/debug analysis by device/source dimensions.
7. Verify split summary hooks are scheduled and the legacy combined hook is not scheduled.
8. For a controlled date range, compare the daily summary against raw landing/session, engagement, handoff, and sales rows.
9. Include engagement-only and handoff-only rows without matching landing sessions; they must not create main summary sessions.
10. Include sales without `attribution_metric_date`; they must not appear in the main daily summary until repaired/backfilled.
11. Verify IPv4, IPv6, and invalid/missing-IP buckets come from stored landing-session columns.
12. Run the same refresh twice and confirm row counts and totals remain unchanged.
