# Click Attribution and Conversion Postbacks

## Purpose

Define a reusable, provider-agnostic capability for:

- capturing incoming affiliate click identifiers on landing entry
- persisting attribution state server-side with expiry
- matching later confirmed conversions to stored click attribution
- dispatching affiliate postbacks (Affise-style) safely and idempotently

This capability is shared infrastructure. Provider callback payload formats remain inside provider adapters/routes.

## Internal Capability Boundary

### Shared attribution components

- `Kiwi_Tracking_Capture_Service`
  - reads click-id style query params
  - sanitizes input
  - stores server-side attribution state
  - sets only an opaque tracking token cookie

- `Kiwi_Click_Attribution_Repository`
  - owns temporary attribution storage
  - stores conversion/postback audit fields
  - supports reference binding and expiry cleanup

- `Kiwi_Conversion_Attribution_Resolver`
  - accepts normalized conversion signals
  - resolves attribution by stable references
  - enforces confirmed-only postback dispatch
  - enforces idempotent postback behavior

- `Kiwi_Sms_Body_Variant_Service`
  - assigns visible SMS-body variants for enabled click-to-SMS landings
  - keeps the internal `transaction_id` stable while rendering alternate customer-facing tokens
  - resolves assigned visible tokens back to the internal transaction id for MO correlation

- `Kiwi_Affiliate_Postback_Dispatcher`
  - builds outbound postback URLs from templates
  - URL-encodes values
  - supports optional secret-based signature/checksum
  - performs outbound HTTP dispatch

### Provider integration boundary

Provider-specific callback handling (payload parsing, command/status semantics, provider auth/validation) remains in provider layers.

Provider adapters should forward only normalized fields into attribution resolver calls:

- `provider_key`
- `service_key`
- `transaction_id` (internal attribution transaction identifier)
- conversion confirmation signal (`confirmed`)
- timestamp (`occurred_at`)
- stable references (`transaction_ref`, `message_ref`, `sale_reference`, `external_ref`, `session_ref`)

No provider-specific callback shape should leak into shared attribution code.

## Storage Model

`wp_kiwi_click_attributions` stores:

- capture data (`tracking_token`, `transaction_id`, `click_id`, landing/provider/service context, optional traffic-source fields `pid`, `tksource`, `tkzone`)
- correlation references (`session_ref`, `transaction_ref`, `message_ref`, `external_ref`, `sale_reference`)
- conversion status (`captured`, `bound`, `confirmed`, postback states)
- outbound postback audit (`postback_sent_at`, response code/body, attempts, errors)
- retention fields (`expires_at`)

`wp_kiwi_premium_sms_landing_engagements` stores landing-session engagement evidence used by MO fraud monitoring, including:

- context (`service_key`, `provider_key`, `flow_key`, `landing_key`, `session_token`)
- engagement timestamps (`page_loaded_at`, `first_cta_click_at`, `last_cta_click_at`) and generic CTA click count
- step-specific CTA1/CTA2/CTA3 timestamps and counts (`first_cta1_click_at`, `last_cta1_click_at`, `cta1_click_count`, and matching CTA2/CTA3 columns), populated only from valid engagement `cta_step` values
- source context snapshots (`pid`, `click_id`, `tksource`, `tkzone`)
- raw UA context (`ua_ch_supported`, `ua_ch_mobile`, `ua_ch_platform`, `ua_ch_platform_version`, `ua_ch_model`, browser-brand lists, `user_agent`) when the landing UA tracking mode allows it

The legacy generic CTA columns stay populated as a compatibility layer for existing fraud checks and current traffic-source statistics views. The step-specific columns are additive storage for future daily summary/statistics work; they do not change provider callback contracts.

`wp_kiwi_landing_handoff_events` stores click-to-SMS handoff diagnostics, including:

- context (`service_key`, `provider_key`, `flow_key`, `landing_key`, `session_token`)
- handoff identity (`handoff_id`, event type) and SMS metadata (`sms`/`smsto`, recipient, body/transaction-token presence)
- browser transition hints (`elapsed_ms`, `visibility_state`) and source context snapshots (`pid`, `click_id`, `tksource`, `tkzone`)
- optional UA Client Hints captured according to the shared landing UA tracking mode (`platform`, `platformVersion`, `model`, browser brands, mobile flag)

`wp_kiwi_sms_body_variant_assignments` stores visible SMS-body experiment assignments, including:

- context (`service_key`, `provider_key`, `flow_key`, `landing_key`, `session_token`)
- internal `transaction_id`, visible token, variant key, seed, and rendered SMS body
- one-time markers for CTA1, handoff, and conversion events

`wp_kiwi_sms_body_variant_summary` stores aggregated experiment metrics by landing/service/variant/seed, including:

- counters (`assignments`, `cta1`, `handoff_attempted`, `handoff_hidden`, `handoff_no_hide`, `handoff_returned`, `conv`)
- rates (`cta1_cr`, `handoff_hidden_cr`, `conv_cr`, `conv_per_cta1_cr`, `conv_per_hidden_cr`)

`wp_kiwi_premium_sms_fraud_signals` stores per-MO fraud snapshots, including:

- volume metrics (`count_1h`, `count_24h`, `count_total`)
- soft-flag outcome and reason
- source context snapshots (`pid`, `click_id`, `tksource`, `tkzone`)

`wp_kiwi_sales` is the durable confirmed-sale fact table. Confirmed sales are enriched from attribution, landing-session, and engagement context so reporting does not depend on temporary attribution rows after their TTL cleanup. Snapshot fields include:

- service and flow dimensions (`service_key`, `provider_key`, `flow_key`, `country`)
- landing/session/source dimensions (`landing_key`, `session_ref`, `click_id`, `pid`, `tksource`, `tkzone`)
- normalized device dimensions (`device_brand`, `android_version`, `browser`)
- reporting date (`attribution_metric_date`), preferring attribution/engagement/session date and falling back to sale completion date
- landing-session client-IP context (`client_ip`, `client_ip_version`, `client_ip_prefix`, optional `client_ip_hash`)
- `context_json.attribution_snapshot`, preserving provider `transaction`/`report_event` data while adding the source rows and normalization/debug details used for the snapshot

## Fraud Monitoring Propagation (Shared Premium SMS)

The shared attribution layer now feeds downstream fraud-monitoring context:

1. Landing entry capture stores `click_id` (required) and optional `pid`, `tksource`, and `tkzone` in `wp_kiwi_click_attributions`.
2. Landing KPI engagement events (`page_loaded`, `cta_click`) resolve and persist `pid`/`click_id`/`tksource`/`tkzone` into `wp_kiwi_premium_sms_landing_engagements`.
3. SMS-body variant assignment stores the visible token shown in the user SMS app while preserving the internal `transaction_id`.
4. Landing handoff events (`sms_handoff_*`) preserve click-to-SMS transition evidence for operations analysis without altering KPI counters; optional UA Client Hints are best-effort and controlled by `KIWI_LANDING_UA_TRACKING_MODE`.
5. Inbound MO fraud evaluation resolves attribution + engagement linkage and snapshots `pid`/`click_id`/`tksource`/`tkzone` into `wp_kiwi_premium_sms_fraud_signals`.

This keeps provider payload parsing at the boundary while giving the shared fraud capability stable traffic-source dimensions.

## Traffic-Source Funnel Statistics

The shared statistics report is exposed through the protected `[kiwi_statistics]` shortcode. Its primary UI and CSV export path reads from the persistent `wp_kiwi_landing_funnel_daily_summary` table so larger date ranges can be filtered and pivoted without rebuilding the old transition views on every request. The report supports date-range filters plus `service_key`, `landing_key`, `tksource`, `tkzone`, `device_brand`, `android_version`, and `browser` filters.

The shortcode and CSV export share one internal statistics-read contract and one column list. The current columns are the summary dimensions (`metric_date`, landing/service/provider/flow/country/source/device buckets) plus daily metrics for sessions, page loads, CTA1/CTA2/CTA3 sessions and click events, handoff attempts/successes/fails/rate, hidden-time min/median/max, sales, and `sales_amount_minor`. The daily summary does not store sale ID or transaction ID drilldown lists; those legacy CSV columns are intentionally not emitted by the summary read path.

The plugin-managed `wp_kiwi_v_load_to_cta_by_tksource_tkzone` view remains available as a legacy/debug source instead of a primary Statistics UI dependency. It reads the generic legacy CTA engagement fields and groups by `service_key`, `tksource`, and `tkzone`.

The view is deliberately built from normalized internal tables only:

- `wp_kiwi_premium_sms_landing_engagements` for sessions, load events, CTA sessions, click counts, and load-to-CTA deltas
- `wp_kiwi_sales` for completed sales, amount totals, and durable service/source dimensions, using `completed_at` as the sales metric timestamp and cutoff field
- `wp_kiwi_click_attributions` only as a legacy fallback for completed-sale dimensions when old sale rows do not yet have a snapshot

The repository applies `from`, optional `to`, `service_key`, and `tksource` filters before grouping by `service_key`, `tksource`, and `tkzone`. The protected shortcode renders these as native date/time controls plus service/source dropdowns whose options are derived from distinct values in the view. The default lower bound is `2026-05-12 20:00:00`, because traffic-source fields were not reliable before that point. Median load-to-CTA uses database window functions; if the view or median query cannot be read on a target MySQL/MariaDB version, the shortcode shows an admin-facing error instead of failing the page.

The same repository also creates `wp_kiwi_v_one_for_all` for broader landing-funnel analysis. That view keeps provider integrations out of the analytics contract and joins only normalized internal tables:

- `wp_kiwi_landing_page_sessions` for landing-page loads and classic user-agent fallback
- `wp_kiwi_premium_sms_landing_engagements` for page-loaded/CTA sessions, source snapshots, and raw UA Client Hints
- `wp_kiwi_landing_handoff_events` for handoff attempts, hidden/no-hide outcomes, and hidden-time aggregates
- `wp_kiwi_sales` for completed-sale counts by durable landing/session snapshots, with `wp_kiwi_click_attributions` as a fallback for legacy rows

`device_brand`, `android_version`, and `browser` are computed in the view from raw UA fields for session rows and are also persisted on new sale snapshots for durable sale analysis. The view exposes `landing_key`, `service_key`, `tksource`, `tkzone`, those device/browser dimensions, session/load/CTA counters, handoff attempts/successes/fails/rate, hidden-time aggregates, and completed sales.

`wp_kiwi_landing_funnel_daily_summary` is the persistent target model for daily landing-funnel analytics. It is populated by `Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service` from the same normalized internal tables, not from provider payloads. The summary groups by `metric_date`, landing/service/provider/flow/country/source dimensions, and normalized device/browser buckets. `metric_date` is derived from session/traffic timestamps for landing, engagement, and handoff facts; completed sales use `wp_kiwi_sales.attribution_metric_date` with `DATE(completed_at)` only as a fallback. Sales dimensions come from durable `wp_kiwi_sales` snapshot columns and do not depend on temporary click-attribution rows.

The daily summary intentionally differs from the transition views:

- it stores a stable `dimension_hash` with a unique key on `metric_date + dimension_hash`
- `sessions` counts distinct `landing_key + session_token` from landing sessions plus engagement-only fallback sessions
- CTA metrics use the step-specific CTA1/CTA2/CTA3 engagement columns
- handoff metrics are deduplicated by handoff id and include hidden-time min/median/max
- sales without snapshot attribution are retained in `(unknown)` dimension buckets instead of being dropped
- refreshes are date-range bounded and replace the target date window so repeated runs are idempotent

WP-Cron runs the same bounded refresh contract hourly through `kiwi_landing_funnel_daily_summary_refresh`. The rolling window defaults to seven lookback days plus today via `KIWI_LANDING_FUNNEL_SUMMARY_REFRESH_DAYS`, uses a transient lock to avoid concurrent recomputes, and stores the latest result in `kiwi_landing_funnel_daily_summary_refresh_last_result`.

Raw-table cleanup behavior is not switched to the daily summary. The existing views remain schema-managed for legacy/debug analysis, while the shortcode and CSV export consume the persistent table.

## Retention and Cleanup

This issue only defines the raw-analytics retention plan. It does not add DELETE jobs, change schema, change refresh windows, or shorten any existing TTL. Later cleanup work needs a separate issue after the validation gates below have production evidence.

Durable sources after the summary rollout:

- `wp_kiwi_sales` is the durable confirmed-sale fact table and must be retained long term.
- `wp_kiwi_landing_funnel_daily_summary` is the durable reporting aggregate and must be retained long term or very long term.
- Raw analytics tables remain operational/debug/recompute sources until the daily summary, sales snapshots, backup/restore, and active read paths are proven sufficient for the requested history window.

| Table | Data class and current purpose | Durable source after rollout | Retention category | Cleanup gate and validation evidence | Early-cleanup risk |
| --- | --- | --- | --- | --- | --- |
| `wp_kiwi_click_attributions` | Temporary attribution, reference binding, conversion/postback audit, and legacy completed-sale dimension fallback. | `wp_kiwi_sales` for confirmed-sale dimensions and `wp_kiwi_landing_funnel_daily_summary` for reporting totals. | Short-lived operational table with the existing `expires_at`/TTL cleanup unchanged. | Do not shorten TTL while callbacks can still bind, postback audit is needed, or old sales still rely on attribution fallback. Validate sampled sales have durable snapshot fields and current statistics/reporting questions do not depend on old attribution rows. | Lost conversion matching, missing postback audit, or dropped legacy sale dimensions. |
| `wp_kiwi_landing_page_sessions` | Raw landing loads, session fallback, classic user-agent fallback, and landing-session client-IP snapshot source. | Daily summary for load/session/device aggregates; `wp_kiwi_sales` for sale-linked landing/session/source/device/IP snapshots. | Medium-term debug and recompute source. | Keep until the bounded daily summary refresh has been compared against raw sessions for the chosen period, the support/recompute lookback is agreed, and IP/device questions can be answered from summary or sales snapshots. | Old periods cannot be fully recomputed from raw landing sessions; sale IP/device investigations lose source rows. |
| `wp_kiwi_premium_sms_landing_engagements` | Raw page-load, CTA1/CTA2/CTA3, source, UA, and engagement evidence used by fraud/debug analysis and summary recompute. | Daily summary for CTA/session aggregates; fraud/sales tables for downstream snapshots where applicable. | Medium-term debug, recompute, and fraud-support source. | Keep until CTA metrics and source/device buckets match the daily summary in production samples, `(unknown)` buckets are understood, and fraud/operations lookback needs are covered. | Per-session engagement evidence and fraud/debug context disappear before operators can investigate anomalies. |
| `wp_kiwi_landing_handoff_events` | Raw click-to-SMS handoff diagnostics, transition hints, source snapshots, and optional UA context. | Daily summary for handoff counts, rates, and hidden-time aggregates. | Medium-term debug and recompute source. | Keep until handoff metrics in the daily summary match raw samples and no open support/debug case needs per-handoff browser transition details. | Click-to-SMS delivery issues become harder to diagnose and old handoff aggregates cannot be rebuilt. |
| `wp_kiwi_sales` | Durable confirmed-sale records with provider-neutral attribution snapshots. | Itself. | Permanent or very long term. | Retention changes require accounting/reporting approval plus backup/restore validation; this table is not part of raw cleanup. | Sales reporting, reconciliation, and attribution history are lost. |
| `wp_kiwi_landing_funnel_daily_summary` | Persistent daily landing/source/device funnel aggregate. | Itself. | Permanent or very long term. | Retention changes require reporting approval, backup/restore validation, and confirmation that raw history is no longer needed for recompute. | Long-range reporting breaks once raw rows are trimmed. |

Validation required before any future raw cleanup issue:

1. Pick a controlled production or staging date range and run the bounded summary refresh for that range twice; the second run must keep row counts and totals stable.
2. Compare raw session, page-load, CTA1/CTA2/CTA3, handoff, and sales counts to `wp_kiwi_landing_funnel_daily_summary` by the dimensions that matter operationally: landing, service, source, device/browser, and metric date.
3. Compare sampled confirmed sales in `wp_kiwi_sales` against attribution, engagement, and landing-session source rows, especially `landing_key`, `session_ref`, `pid`, `tksource`, `tkzone`, device/browser fields, client-IP snapshot fields, and `attribution_metric_date`.
4. Review `(unknown)` buckets and legacy/debug view usage. Any reporting, CSV, pivot, fraud, or support question that still needs raw rows is a cleanup blocker.
5. Confirm backup/restore expectations for `wp_kiwi_sales` and `wp_kiwi_landing_funnel_daily_summary`, and capture evidence in the cleanup issue or PR before enabling deletes.

Existing click-attribution cleanup behavior remains unchanged:

- row expiry timestamp: `expires_at`
- TTL config key: `KIWI_CLICK_ATTRIBUTION_TTL_SECONDS`
  - default: `172800` seconds (48 hours)
  - minimum enforced value: `60` seconds
- cleanup batch size config key: `KIWI_CLICK_ATTRIBUTION_CLEANUP_LIMIT`
  - default: `500`
  - minimum enforced value: `1`

Cleanup execution behavior:

- cleanup is triggered on WordPress `init`
- cleanup execution is throttled by a transient lock
- lock TTL is `300` seconds (about 5 minutes)
- each cleanup run deletes expired rows in bounded batches (up to configured cleanup limit)

Cookie retention note:

- the opaque tracking token cookie uses the same configured attribution TTL window

## Security and Data Handling

- raw `clickid` is never stored in cookies
- cookie contains only opaque `tracking_token`
- affiliate postback secret is configuration-driven, never hardcoded
- incoming aggregator callbacks are not modeled around a fake shared secret
- callback trust/validation remains provider-specific
- sale `client_ip` is copied only from landing/session context, never from provider callback request metadata
- raw sale IP is personal data; broad analysis should prefer `client_ip_prefix` or `client_ip_hash` where possible

## Reliability Rules

- only `confirmed` conversions trigger postback dispatch
- duplicate callbacks must not emit duplicate postbacks once `postback_sent_at` is set
- failed postbacks are retained in audit fields for retry visibility
- expired temporary rows are cleaned in bounded batches
- each attribution row gets an internal `transaction_id` so provider callbacks can be correlated through stable server-side references
- visible SMS-body tokens are lookup aliases only; they must resolve back to the unchanged internal `transaction_id`

## Tracking-first S2S Postback Contract

This section describes the operational S2S tracking contract used to move from landing click capture to outbound affiliate postback dispatch.

### End-to-end S2S sequence

1. User lands with affiliate query params (for example `clickid`).
2. `Kiwi_Tracking_Capture_Service` stores attribution server-side and sets an opaque `tracking_token` cookie.
3. Provider callbacks arrive later with provider-specific references (not with the original affiliate `clickid`).
4. Provider layer normalizes callback fields and forwards stable refs to `Kiwi_Conversion_Attribution_Resolver`.
5. Resolver matches attribution, enforces `confirmed`-only dispatch, and calls `Kiwi_Affiliate_Postback_Dispatcher`.
6. Dispatcher builds the outbound URL template, applies placeholders/signature, and sends an S2S HTTP request.
7. Postback audit fields (`postback_sent_at`, attempts, response/error fields) are persisted for retry/idempotency behavior.

### Full S2S URL template example

`https://offers-kiwimobile.affise.com/postback?clickid={clickid}&secure={secure}&goal=sale&status=1`

### Placeholder mapping (source of truth)

- `clickid` / `click_id`
  - mandatory affiliate click identifier captured at landing entry
- `secure`
  - mandatory hash password generated on offer or advertiser level
- `goal`
  - conversion goal number or goal value; use `sale` for successful sale postbacks
- `status`
  - optional conversion status; `1` approved, `2` pending, `3` declined, `5` hold
- `sum`
  - optional conversion revenue
- `ip`
  - optional visitor IP address, subject to Affise/GDPR handling
- `referrer`
  - optional referrer or additional traffic/deeplink information
- `comment`
  - optional comment
- `fbclid`
  - optional Facebook click ID
- `device_type`
  - optional device type, for example `mobile`, `tablet`, or `desktop`
- `aimp_id`
  - optional Affise impression ID
- `promo_code`
  - optional promo code
- `user_id`
  - optional user ID
- `custom_field1` through `custom_field15`
  - optional additional Affise parameters
  - `custom_field1` is the current operator reporting dimension and is populated from normalized `operator_name` when available
- `action_id`
  - optional unique conversion ID in the advertiser system
  - not part of the default sale postback template
  - use only when the integration intentionally wants Affise conversion uniqueness based on `action_id`, `goal`, and offer instead of click ID

### Dispatch behavior rules

- all substituted values are URL-encoded before request dispatch
- dispatcher emits only parameters present in the configured Affise postback template
- Affise sale postback templates must include `secure={secure}`
- successful dispatch is transport-level HTTP `2xx`
- idempotency boundary is `postback_sent_at`: once set, duplicate callback deliveries must not emit another postback

## Current Wiring Example (NTH FR one-off)

- landing entry capture runs in `Kiwi_Landing_Page_Router`
- filesystem landings can use `{{KIWI_PRIMARY_CTA_HREF}}` so CTA assembly stays in centralized flow logic
- NTH callback normalization and confirmation logic remain in NTH service/normalizer
- NTH resolves pending attribution rows by service/reference and reuses the shared `transaction_id` as the provider reference root
- NTH MO adapter may extract `transaction_id` from keyword-suffixed MO content (for example `JPLAY txn_xxx`) at the provider boundary
- when the FR SMS-body variant experiment is active, NTH MO handling first keeps direct `txn_...` parsing, then resolves assigned visible tokens such as `JPLAY abc...` or `JPLAY ArcadeHeroabc...` through `wp_kiwi_sms_body_variant_assignments`
- NTH service passes normalized conversion signals into `Kiwi_Conversion_Attribution_Resolver`
- resolver enriches the matched sale row with shared attribution snapshots (source/session/device/IP dimensions) without leaking provider callback payload shapes into sales writes

This is an integration example, not an NTH-only architecture. Additional providers should reuse the same shared resolver/dispatcher capability.
