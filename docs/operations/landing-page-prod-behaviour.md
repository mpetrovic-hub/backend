# Landing Page Production Behaviour (Operations)

This runbook describes how landing pages work in runtime, which configuration controls them, and how to operate them safely in production.

This file is intentionally broader than migration only. Migration steps remain as a short appendix.

## Runtime model

Landing pages are discovered from `landing-pages/` and resolved by request path and host metadata.

Each page folder must follow the filesystem contract:

- folder format: `lp<version>-<country>`; optional test/variant suffixes can be appended as `lp<version>-<country>-<variant>`
- required files:
  - `index.html`
  - `styles.css`
  - `integration.php`
- `integration.php` links page routing to service/flow/provider docs
- default media asset folder: `https://backend.kiwimobile.de/wp-content/uploads/assets/`
- optional `asset_base_url` can override that default for `./asset.ext` references in `index.html` and `styles.css`
- hero/LCP images should be preloaded with high fetch priority and explicit image dimensions; when small responsive variants exist, the preload and `<img>` should use matching responsive image candidates

Business logic is centralized in plugin services. Landing-page folders are presentation plus metadata only.

## Gallery shortcode

The plugin exposes a landing-page diagnostics/gallery shortcode:

- shortcode: `[kiwi_landing_pages_gallery]`
- source: filesystem landing pages discovered through the shared config/registry path
- card metadata: `country`, `key`, `flow`, `service_key`, provider, routing mode
- auth/cache behavior: when frontend tool auth is enabled, both the gallery login form and authenticated gallery response send `nocache` headers plus `CDN-Cache-Control: no-store` and `X-LiteSpeed-Cache-Control: no-cache`
- URL behavior:
  - when `hostnames` + `backend_path` exist, cards show absolute HTTPS outside URLs as `https://<hostname><backend_path>`
  - when `hostnames` exist but `backend_path` is missing, cards fall back to `https://<hostname><dedicated_path>`
  - when only `backend_path` exists, cards show the backend path strategy and an inferred current-site URL (explicitly labeled as inferred)
  - cards render one primary URL in the preview URL row (the best explicit outside URL when available)

Discovery validation warnings (for broken folders) are surfaced in the shortcode output while valid entries keep rendering.

## Request resolution and rendering

At runtime, the router:

1. Resolves landing page by `backend_path` or dedicated `hostnames` + `dedicated_path`.
2. Creates/reads a landing session token (`kiwi_landing_session` cookie).
3. Captures click attribution when `clickid` is present, stores it server-side, and sets an opaque tracking token cookie (`kiwi_tracking_token`).
4. Builds primary CTA centrally (provider adapter), then injects `{{KIWI_PRIMARY_CTA_HREF}}` in HTML.
5. Renders filesystem HTML and wires `styles.css`.

For filesystem HTML and CSS, local media references such as `./hero.png` are rewritten at render time to `https://backend.kiwimobile.de/wp-content/uploads/assets/hero.png` by default. This applies to direct HTML `src`/`href` attributes, CSS `url(...)` values, and local responsive candidates in `srcset`/`imagesrcset`. If `asset_base_url` is set in `integration.php`, those local asset references resolve under that configured base URL instead. When readable, `styles.css` is inlined into the rendered HTML and its external stylesheet link is suppressed; if it cannot be read, the router falls back to the external stylesheet URL from the landing-page folder.

Keep the preload candidates in sync with the visible hero `<img>` candidates so the browser does not fetch a duplicate LCP image. External, protocol-relative, `data:`, and root-relative `/...` candidates keep their original browser semantics; only `./...` candidates are rewritten to the effective asset base.

Landing engagement telemetry (`page_loaded`, `cta_click`) is sent via the KPI event endpoint and can carry source context (`pid`, `clickid`/`click_id`, `tksource`, `tkzone`) for fraud-linkage snapshots. CTA engagement payloads use `cta_step` (`cta1`, `cta2`, or `cta3`) to persist step-specific per-session click timestamps/counts without reusing the KPI `step` field.

For click-to-SMS CTAs, the same endpoint also records handoff telemetry for `sms:`/`smsto:` links. These events are diagnostic signals only: they indicate that a browser attempted an SMS handoff, hid the page, returned, or did not hide after the click. They do not prove that the SMS was sent and they do not increment KPI summary counters.

Best-effort User-Agent context is controlled by `KIWI_LANDING_UA_TRACKING_MODE`:

- `disabled`: do not collect or persist UA Client Hints for engagement or handoff payloads, even when a client posts those fields manually
- `onclick`: collect UA Client Hints only around CTA/handoff interaction; this preserves the legacy handoff-near behavior
- `onload`: collect UA Client Hints on page load and persist them with the `page_loaded` engagement when available

The legacy `KIWI_LANDING_HANDOFF_UA_CLIENT_HINTS_ENABLED=false` switch maps to `disabled` when the new mode is not set. Otherwise the default is `onload`, so page-load sessions can be clustered by available device context.

For NTH click-to-SMS flows, CTA construction can append the internal `transaction_id` to the SMS body through centralized adapter logic. The FR SMS-body variant experiment can instead render a stable visible token while keeping the internal `txn_...` correlation id unchanged server-side.

## Multi-domain exposure via proxy/CNAME

Landing pages can be exposed on multiple public domains without changing core plugin routing or attribution behavior.

Recommended setup:

- keep each landing page `backend_path` stable and unique (for example `/lp/fr/myjoyplay5`)
- for backend-path-only test variants, leave `hostnames` empty to avoid taking over a dedicated-host root route
- point each public domain/hostname to the same backend WordPress runtime via DNS + reverse proxy/CNAME
- keep `hostnames` metadata populated in `integration.php` for diagnostics visibility and optional dedicated-host routing

Proxy/edge requirements:

- terminate TLS with a valid certificate for each public hostname
- preserve original `Host` and forward standard `X-Forwarded-*` headers
- forward request paths unchanged (no path rewrites for landing routes)
- avoid exposing non-canonical backend origin hosts to end users when possible

Tracking/cookie note:

- click/session implementation remains unchanged
- `kiwi_landing_session` and attribution token cookies are host-scoped in current implementation
- attribution works as expected when one user journey stays on one public hostname
- avoid mid-flow redirects between different root domains unless an explicit cross-domain handoff design is introduced

## Conversion and attribution behavior

High-level flow:

1. Landing captures attribution context server-side.
2. Provider callbacks are normalized at provider boundary.
3. Confirmed conversions are resolved against attribution state using stable references (transaction/message/session/external refs).
4. Successful one-off sales are persisted in `wp_kiwi_sales`.
5. After attribution matching, the sale row is enriched with a durable snapshot of service, landing/session, source, device, metric date, and landing-session client-IP context.
6. Affiliate postback dispatch is triggered only for confirmed conversions and only once after `postback_sent_at` is set.
7. When a matching sale exists, outbound postback includes `custom_field1=<operator_name>` sourced from `wp_kiwi_sales.operator_name`.

Important boundary:

- Incoming provider callback validation is provider-specific.
- Outgoing affiliate secret/signature applies only to outbound postbacks.
- Client IP stored on sales must come from `wp_kiwi_landing_page_sessions.remote_ip`; provider/aggregator callback `REMOTE_ADDR` is not a user-IP source.

## Landing-page KPI tracking

The landing-page system supports a generic KPI funnel for optimization analysis.

### Event model

- `clicks`
  - incremented in the central landing-page router when a landing page is rendered
- `cta1`, `cta2`, `cta3`
  - incremented through the KPI event endpoint into one summary row per landing page
- Landing engagement events
  - stored per landing/session for `page_loaded` and `cta_click`
  - `cta_click` can include `cta_step=cta1|cta2|cta3` for step-specific engagement columns
  - do not mutate `wp_kiwi_landing_kpi_summary`; KPI step counters still require a separate `step` payload
- SMS handoff events
  - stored separately for `sms:`/`smsto:` CTA diagnostics
  - supported events: `sms_handoff_attempted`, `sms_handoff_hidden`, `sms_handoff_returned`, `sms_handoff_no_hide`
  - do not mutate `wp_kiwi_landing_kpi_summary`
- SMS body variant events
  - stored separately for the visible SMS-body experiment
  - assignment, CTA1, handoff, and conversion counters are aggregated by landing/service/variant/seed
  - do not mutate `wp_kiwi_landing_kpi_summary`
- `conv`
  - incremented once on first confirmed conversion match in attribution resolver
  - duplicate callbacks do not increment `conv` again once conversion was already confirmed

Storage model:

- `wp_kiwi_landing_kpi_summary`
  - one row per `landing_key`
  - counters: `clicks`, `cta1`, `cta2`, `cta3`, `conv`
  - precomputed rates: `cta1_cr`, `cta2_cr`, `cta3_cr`, `conv_cr`

- `wp_kiwi_landing_handoff_events`
  - one row per landing/session/handoff/event type
  - records SMS handoff diagnostics, including scheme, recipient, body presence, transaction-token presence, elapsed time, visibility state, source snapshots, and optional UA Client Hints

- `wp_kiwi_sms_body_variant_assignments`
  - one row per internal `transaction_id`
  - stores the visible token rendered in the SMS body, variant key, seed, landing/session/source context, and one-time event markers

- `wp_kiwi_sms_body_variant_summary`
  - one row per `landing_key`, `service_key`, `variant_key`, and `seed`
  - counters: `assignments`, `cta1`, `handoff_attempted`, `handoff_hidden`, `handoff_no_hide`, `handoff_returned`, `conv`
  - precomputed rates: `cta1_cr`, `handoff_hidden_cr`, `conv_cr`, `conv_per_cta1_cr`, `conv_per_hidden_cr`

### Per-landing selector mapping in `integration.php`

Each filesystem landing page can map KPI steps to selectors:

```php
'kpi_cta_steps' => [
    'cta1' => 'class="cta"',
    'cta2' => '.mobile_number_input',
    'cta3' => '#confirm-button',
],
```

Notes:
- v1 step keys are limited to `cta1`, `cta2`, and `cta3`
- selector values can be CSS selectors
- shorthand `class="cta"` is normalized to `.cta`
- if no mapping is provided, router defaults to `cta1 => .cta`

### REST endpoints

- `POST /wp-json/kiwi-backend/v1/landing-kpi/event`
  - increments CTA summary counters (`cta1`/`cta2`/`cta3`)
  - records engagement events (`page_loaded`, `cta_click`)
  - accepts `cta_step=cta1|cta2|cta3` only for `cta_click` engagement storage; this is distinct from the KPI `step` field
  - records SMS handoff diagnostics without changing summary counters
- `GET /wp-json/kiwi-backend/v1/landing-kpi/report`
  - returns per-landing KPI rows with counts and rates
  - supports optional filters:
    - `days` (accepted for compatibility; summary output is all-time)
    - `landing_key`

## Key tables and what they mean

- `wp_kiwi_click_attributions`
  - temporary server-side attribution state
  - click ID, internal `transaction_id`, refs, postback audit, TTL expiry

- `wp_kiwi_nth_events`
  - normalized inbound/outbound NTH event log with dedupe

- `wp_kiwi_nth_flow_transactions`
  - FR one-off flow transaction lifecycle and external references

- `wp_kiwi_sales`
  - durable confirmed sale records
  - includes `transaction_id`, service/landing/session/source snapshots (`service_key`, `landing_key`, `session_ref`, `click_id`, `pid`, `tksource`, `tkzone`), normalized device buckets (`device_brand`, `android_version`, `browser`), `attribution_metric_date`, and landing-session IP snapshot fields (`client_ip`, `client_ip_version`, `client_ip_prefix`, `client_ip_hash`)
  - keeps existing provider context in `context_json` and adds `context_json.attribution_snapshot` with source/debug rows used to build the snapshot

- `wp_kiwi_premium_sms_landing_engagements`
  - landing-session engagement evidence (`page_loaded_at`, first/last generic CTA click, generic click count)
  - step-specific CTA evidence (`first_cta1_click_at`, `last_cta1_click_at`, `cta1_click_count`, and the matching CTA2/CTA3 columns)
  - legacy generic CTA columns remain populated for backward compatibility with fraud checks and existing statistics views
  - source snapshots (`pid`, `click_id`, `tksource`, `tkzone`)
  - raw UA context (`ua_ch_*`, `user_agent`) when the UA tracking mode allows it; normalized device/browser buckets are not stored here

- `wp_kiwi_landing_handoff_events`
  - click-to-SMS handoff evidence (`sms_handoff_*`)
  - source snapshots (`pid`, `click_id`, `tksource`, `tkzone`), handoff details (`sms`, `smsto`, recipient/body metadata), and optional UA Client Hints (`platform`, `platformVersion`, `model`, browser brands)

- `wp_kiwi_sms_body_variant_assignments`
  - visible SMS token assignments for the FR click-to-SMS experiment
  - maps non-`txn_` visible tokens back to the internal `transaction_id`

- `wp_kiwi_sms_body_variant_summary`
  - aggregated SMS body variant metrics for SQL-based experiment analysis

- `wp_kiwi_premium_sms_fraud_signals`
  - MO fraud snapshots per identity (`subscriber`/`session`)
  - per-service volume counts, soft-flag reasons, source snapshots (`pid`, `click_id`, `tksource`, `tkzone`)

- `wp_kiwi_v_load_to_cta_by_tksource_tkzone`
  - plugin-managed view for the `[kiwi_statistics]` traffic-source funnel report
  - normalizes landing engagement and completed-sale facts by `service_key`, `tksource`, and `tkzone`
  - assigns completed-sale facts to reporting windows by `wp_kiwi_sales.completed_at`
  - prefers durable `wp_kiwi_sales` snapshot fields for completed-sale dimensions and falls back to temporary attribution rows only for legacy sales without snapshots
  - uses `2026-05-12 20:00:00` as the default lower bound for reliable `tksource`/`tkzone` data

- `wp_kiwi_v_one_for_all`
  - plugin-managed analytics view for pivot/export work outside the shortcode UI
  - groups by `landing_key`, `service_key`, `tksource`, `tkzone`, computed `device_brand`, computed `android_version`, and computed `browser`
  - exposes sessions, landing-page loads, page-loaded sessions, CTA sessions/clicks, handoff attempts/successes/fails/rate, hidden-time aggregates, and completed sales
  - computes session-row device/browser dimensions in SQL from raw UA context; new sale rows also persist matching normalized buckets for durable sale analysis
  - counts completed sales by `wp_kiwi_sales.landing_key/session_ref` first, falling back to attribution joins for legacy rows

- `wp_kiwi_landing_funnel_daily_summary`
  - plugin-managed persistent table for daily funnel aggregates
  - groups by `metric_date`, `landing_key`, `service_key`, `provider_key`, `flow_key`, `country`, `pid`, `tksource`, `tkzone`, `device_brand`, `android_version`, and `browser`
  - stores a stable `dimension_hash` with a unique key on `metric_date + dimension_hash`
  - exposes distinct sessions, page-loaded sessions, CTA1/CTA2/CTA3 session and event counts, handoff attempts/successes/fails/rate, hidden-time min/median/max, sales, and `sales_amount_minor`
  - counts distinct `landing_key + session_token` sessions from landing-session rows and engagement-only fallback rows; handoff-only rows can contribute handoff metrics without inflating `sessions`
  - aggregates completed sales from durable `wp_kiwi_sales` snapshot columns, using `attribution_metric_date` with `DATE(completed_at)` fallback for old records
  - writes missing dimensions to `(unknown)` buckets so unattributed sales remain visible
  - is refreshed by bounded date-range recompute: the target `metric_date` window is deleted and reinserted, so rerunning the same range is idempotent
  - is refreshed hourly by WP-Cron hook `kiwi_landing_funnel_daily_summary_refresh`, using a transient lock to prevent concurrent runs
  - stores the last refresh or lock-skip result in WordPress option `kiwi_landing_funnel_daily_summary_refresh_last_result`
  - is not yet wired to the statistics shortcode, CSV export, or raw-table cleanup

## Configuration switches

### Landing-page loading

- `KIWI_LANDING_PAGES_ROOT`
  - optional filesystem root override (default: `<plugin-root>/landing-pages`)

- `KIWI_LANDING_PAGES_FILESYSTEM_ENABLED`
  - enable filesystem discovery (default: `true`)

- `KIWI_LANDING_PAGES_LEGACY_FALLBACK_ENABLED`
  - allow legacy `KIWI_LANDING_PAGES` fallback when key is missing in filesystem registry (default: `false`)
  - set to `true` only as a temporary rollback/migration switch while investigating a missing filesystem route

### Attribution and postbacks

- `KIWI_CLICK_ATTRIBUTION_COOKIE_NAME`
- `KIWI_CLICK_ATTRIBUTION_CLICK_ID_KEYS`
- `KIWI_CLICK_ATTRIBUTION_TTL_SECONDS`
- `KIWI_CLICK_ATTRIBUTION_CLEANUP_LIMIT`
- `KIWI_SMS_BODY_VARIANT_EXPERIMENT_ENABLED`
  - enables the SMS-body variant experiment (default: `true`)
- `KIWI_SMS_BODY_VARIANT_EXPERIMENT_COUNTRIES`
  - country allowlist for the experiment (default: `['FR']`)
- `KIWI_LANDING_UA_TRACKING_MODE`
  - generic UA tracking mode for landing telemetry: `disabled`, `onclick`, or `onload` (default: `onload`)
  - `onload` increases page-load REST/DB write volume but enables device/OS/browser clustering for non-click sessions
- `KIWI_LANDING_HANDOFF_UA_CLIENT_HINTS_ENABLED`
  - legacy compatibility switch; when set to `false` and `KIWI_LANDING_UA_TRACKING_MODE` is unset, it maps to `disabled`
- `KIWI_LANDING_FUNNEL_SUMMARY_REFRESH_DAYS`
  - number of lookback days recalculated by the daily summary rolling refresh in addition to today (default: `7`, minimum: `0`)
- `KIWI_AFFILIATE_POSTBACK_URL_TEMPLATE`
- `KIWI_AFFILIATE_POSTBACK_SECRET`
- `KIWI_AFFILIATE_POSTBACK_SIGNATURE_PARAMETER`
- `KIWI_AFFILIATE_POSTBACK_SIGNATURE_ALGORITHM`
- `KIWI_AFFILIATE_POSTBACK_SIGNATURE_BASE`

### NTH callback observability

- `KIWI_NTH_CALLBACK_LOGGING_ENABLED`
- `KIWI_NTH_CALLBACK_PAYLOAD_LOGGING_ENABLED`

## Operational checks

When validating a landing-page flow in production or staging, verify:

1. Routing resolves expected landing key for both backend path and dedicated hostname path.
2. CTA contains expected keyword and transaction token behavior.
3. `clickid` capture creates/updates one row in `wp_kiwi_click_attributions` for the active tracking token.
4. NTH callback logs show `incoming` and `handled` traces.
5. Confirmed `deliverReport` results in sale persistence (`wp_kiwi_sales`).
6. Affiliate postback is sent once for confirmed conversions and retried only when `postback_sent_at` is empty.
7. `backend_path` routes resolve correctly on every public hostname that proxies to the backend runtime.
8. User journey stays on one public hostname and does not redirect to a backend origin hostname.
9. Fraud tool (`[kiwi_premium_sms_fraud]`) shows expected MO/engagement rows, source fields (`pid`, `click_id`, `tksource`, `tkzone`), and engagement delta (`Load -> First CTA`) where both timestamps exist.
10. UA tracking mode behaves as configured: `disabled` stores no UA context, `onclick` stores it only near CTA/handoff events, and `onload` stores it on `page_loaded` when browser hints are available.
11. Statistics tool (`[kiwi_statistics]`) loads the `wp_kiwi_v_load_to_cta_by_tksource_tkzone` view, defaults to `2026-05-12 20:00:00`, keeps rows with `cta_sessions = 0` visible, preserves wall-clock seconds in native datetime filters, populates service/TK-source dropdowns from existing view data, and shows completed sales/rates from sales snapshots even after temporary attribution rows expire.
12. CTA1/CTA2/CTA3 engagement columns increase only for matching `cta_step` payloads while legacy `cta_click_count` still increases for every valid `cta_click`.
13. `wp_kiwi_v_one_for_all` can be queried/pivoted by `device_brand`, `android_version`, `browser`, `tksource`, and `tkzone`; completed sales should still count when their durable `landing_key/session_ref` snapshot is present.
14. Confirm WP-Cron has scheduled `kiwi_landing_funnel_daily_summary_refresh`; manually trigger it in staging and verify the default window covers today plus the configured lookback days.
15. Check `kiwi_landing_funnel_daily_summary_refresh_last_result` after success, failure, and simulated lock cases; errors should also be visible through the `[kiwi-landing-funnel-daily-summary-refresh]` log prefix.
16. For a controlled date range, run the landing funnel daily summary refresh and compare `wp_kiwi_landing_funnel_daily_summary` against raw landing/session, engagement, handoff, and sales rows. A second refresh for the same date range should keep row counts and totals unchanged.
17. If the gallery/statistics tools are auth-protected, verify the response still carries the no-cache headers through CDN/LiteSpeed or any reverse proxy layer.
18. For a test sale with attribution, verify `wp_kiwi_sales.client_ip` equals the landing-session IP and not the provider callback source IP; prefer `client_ip_prefix`/`client_ip_hash` for broad analysis or export.

## Troubleshooting quick map

- Callback rejected with `service_key_unresolved`
  - usually missing/wrong `service_key` and no unique shortcode+keyword match

- No sale in `wp_kiwi_sales`
  - confirm `deliverReport` status mapping is terminal success for your payload
  - confirm references correlate to an existing flow transaction

- Missing transaction linkage
  - verify MO content carries expected `txn_...` token and parser delimiters
  - verify submit flow stores and reuses provider references consistently

- Duplicate callback confusion
  - dedupe in events is expected
  - conversion path can re-attempt postback only while `postback_sent_at` is empty

## Appendix: legacy retirement and rollback

The legacy `KIWI_LANDING_PAGES` fallback is in retirement. Phase 1 keeps the code path available but disables it by default. Phase 2 should remove the legacy template loading path and `templates/landing-pages` code only after staging confirms there are no remaining runtime-only legacy routes.

### Staging verification before Phase 2

1. Open active filesystem landing routes including `lp4-fr`, `lp5-fr`, `lp5-fr-v2`, `lp6-fr`, and `lp6-fr-v2` through their backend paths.
2. Verify any public hostname plus backend-path routing used in production.
3. Confirm CTA output, SMS target/body, price disclosure, and local asset rendering.
4. Check WordPress debug logs for missing landing keys, missing templates, warnings, or errors.
5. Confirm no required route depends on `KIWI_LANDING_PAGES` or `templates/landing-pages/<template>.php`.

### Temporary rollback

If staging or production shows an unmigrated legacy-only route, temporarily define:

```php
define('KIWI_LANDING_PAGES_LEGACY_FALLBACK_ENABLED', true);
```

Use this only to restore service while the route is migrated into a filesystem folder. Remove the flag again after the filesystem route is verified.

### Migrating a discovered legacy route

Use only if a remaining legacy `KIWI_LANDING_PAGES` entry is found.

1. Create filesystem folder `landing-pages/lp<version>-<country>/`.
2. Add `index.html`, `styles.css`, `integration.php`.
3. Set routing metadata in `integration.php` (`backend_path`, optional `hostnames`/`dedicated_path`).
4. Temporarily deploy with `KIWI_LANDING_PAGES_LEGACY_FALLBACK_ENABLED=true` only if rollback coverage is needed during migration.
5. Verify parity.
6. Remove migrated key from `KIWI_LANDING_PAGES`.
7. Repeat until no legacy keys remain.
8. Remove the temporary fallback flag so default-off behavior is restored.

In debug mode (`KIWI_DEBUG=true`), invalid landing pages fail loudly. In production, invalid entries are skipped and logged.
