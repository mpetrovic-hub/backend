# Landing Page Production Behaviour (Operations)

This runbook describes how landing pages work in runtime, which configuration controls them, and how to operate them safely in production.

This file is intentionally broader than migration only. Migration steps remain as a short appendix.

## Runtime model

Landing pages are discovered from `landing-pages/` and resolved by request path and host metadata.

Each page folder must follow the filesystem contract:

- folder format: `lp<version>-<country>`
- required files:
  - `index.html`
  - `styles.css`
  - `integration.php`
- `integration.php` links page routing to service/flow/provider docs
- default media asset folder: `https://backend.kiwimobile.de/wp-content/uploads/assets/`
- optional `asset_base_url` can override that default for `./asset.ext` references in `index.html` and `styles.css`

Business logic is centralized in plugin services. Landing-page folders are presentation plus metadata only.

## Gallery shortcode

The plugin exposes a landing-page diagnostics/gallery shortcode:

- shortcode: `[kiwi_landing_pages_gallery]`
- source: filesystem landing pages discovered through the shared config/registry path
- card metadata: `country`, `key`, `flow`, `service_key`, provider, routing mode
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

For filesystem HTML and CSS, local media references such as `./hero.png` are rewritten at render time to `https://backend.kiwimobile.de/wp-content/uploads/assets/hero.png` by default. If `asset_base_url` is set in `integration.php`, those local asset references resolve under that configured base URL instead. When readable, `styles.css` is inlined into the rendered HTML and its external stylesheet link is suppressed; if it cannot be read, the router falls back to the external stylesheet URL from the landing-page folder.

Landing engagement telemetry (`page_loaded`, `cta_click`) is sent via the KPI event endpoint and can carry source context (`pid`, `clickid`/`click_id`) for fraud-linkage snapshots.

For NTH click-to-SMS flows, CTA construction can append the internal `transaction_id` to the SMS body through centralized adapter logic.

## Multi-domain exposure via proxy/CNAME

Landing pages can be exposed on multiple public domains without changing core plugin routing or attribution behavior.

Recommended setup:

- keep each landing page `backend_path` stable and unique (for example `/lp/fr/myjoyplay5`)
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
5. Affiliate postback dispatch is triggered only for confirmed conversions and only once after `postback_sent_at` is set.
6. When a matching sale exists, outbound postback includes `sub7=<operator_name>` sourced from `wp_kiwi_sales.operator_name`.

Important boundary:

- Incoming provider callback validation is provider-specific.
- Outgoing affiliate secret/signature applies only to outbound postbacks.

## Landing-page KPI tracking

The landing-page system supports a generic KPI funnel for optimization analysis.

### Event model

- `clicks`
  - incremented in the central landing-page router when a landing page is rendered
- `cta1`, `cta2`, `cta3`
  - incremented through the KPI event endpoint into one summary row per landing page
- `conv`
  - incremented once on first confirmed conversion match in attribution resolver
  - duplicate callbacks do not increment `conv` again once conversion was already confirmed

Storage model:

- `wp_kiwi_landing_kpi_summary`
  - one row per `landing_key`
  - counters: `clicks`, `cta1`, `cta2`, `cta3`, `conv`
  - precomputed rates: `cta1_cr`, `cta2_cr`, `cta3_cr`, `conv_cr`

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
- step keys must follow `cta1`, `cta2`, `cta3`, ...
- selector values can be CSS selectors
- shorthand `class="cta"` is normalized to `.cta`
- if no mapping is provided, router defaults to `cta1 => .cta`

### REST endpoints

- `POST /wp-json/kiwi-backend/v1/landing-kpi/event`
  - increments CTA summary counters (`cta1`/`cta2`/`cta3`)
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
  - confirmed sale records (including `transaction_id`)

- `wp_kiwi_premium_sms_landing_engagements`
  - landing-session engagement evidence (`page_loaded_at`, first/last CTA click, click count)
  - source snapshots (`pid`, `click_id`)

- `wp_kiwi_premium_sms_fraud_signals`
  - MO fraud snapshots per identity (`subscriber`/`session`)
  - per-service volume counts, soft-flag reasons, source snapshots (`pid`, `click_id`)

## Configuration switches

### Landing-page loading

- `KIWI_LANDING_PAGES_ROOT`
  - optional filesystem root override (default: `<plugin-root>/landing-pages`)

- `KIWI_LANDING_PAGES_FILESYSTEM_ENABLED`
  - enable filesystem discovery (default: `true`)

- `KIWI_LANDING_PAGES_LEGACY_FALLBACK_ENABLED`
  - allow legacy `KIWI_LANDING_PAGES` fallback when key is missing in filesystem registry (default: `true`)

### Attribution and postbacks

- `KIWI_CLICK_ATTRIBUTION_COOKIE_NAME`
- `KIWI_CLICK_ATTRIBUTION_CLICK_ID_KEYS`
- `KIWI_CLICK_ATTRIBUTION_TTL_SECONDS`
- `KIWI_CLICK_ATTRIBUTION_CLEANUP_LIMIT`
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
9. Fraud tool (`[kiwi_premium_sms_fraud]`) shows expected MO/engagement rows, source fields (`pid`, `click_id`), and engagement delta (`Load -> First CTA`) where both timestamps exist.

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

## Appendix: legacy migration steps

Use only if migrating remaining legacy `KIWI_LANDING_PAGES` entries.

1. Create filesystem folder `landing-pages/lp<version>-<country>/`.
2. Add `index.html`, `styles.css`, `integration.php`.
3. Set routing metadata in `integration.php` (`backend_path`, optional `hostnames`/`dedicated_path`).
4. Deploy with `KIWI_LANDING_PAGES_LEGACY_FALLBACK_ENABLED=true`.
5. Verify parity.
6. Remove migrated key from `KIWI_LANDING_PAGES`.
7. Repeat until no legacy keys remain.
8. Disable legacy fallback when fully migrated.

In debug mode (`KIWI_DEBUG=true`), invalid landing pages fail loudly. In production, invalid entries are skipped and logged.
