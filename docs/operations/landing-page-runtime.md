# Landing Page Runtime Runbook

## Read when

- You need to operate, validate, debug, or roll back landing-page runtime behavior.
- Work touches routing, rendering, the gallery shortcode, CTA rendering, or multi-domain exposure.

## Source of truth for

- Production landing-page runtime behavior.
- Operational validation and troubleshooting.
- Filesystem-only loading and deployment rollback.

## Not here

- Filesystem landing-page architecture and metadata contract: see `../architecture/landing-page-architecture.md`.
- Landing KPI, Statistics UI, funnel summaries, and analytics tables: see `landing-funnel-analytics.md`.
- Retention cleanup and raw-context compaction: see `retention-runbook.md`.
- Config constants: see `configuration-reference.md`.

## Runtime model

Landing pages are discovered from `landing-pages/` and resolved by request path and host metadata.

Each page folder must follow the filesystem contract:

- folder format: `lp<version>-<country>`; optional test/variant suffixes can be appended as `lp<version>-<country>-<variant>`
- required files: `index.html`, `styles.css`, `integration.php`
- `integration.php` links page routing to service/flow/provider docs
- default media asset folder: `https://backend.kiwimobile.de/wp-content/uploads/assets/`
- optional `asset_base_url` can override that default for `./asset.ext` references in `index.html` and `styles.css`
- hero/LCP images should be preloaded with high fetch priority and explicit image dimensions; responsive preload and visible image candidates should match

Business logic is centralized in plugin services. Landing-page folders are presentation plus metadata only.

## Gallery shortcode

The plugin exposes a landing-page diagnostics/gallery shortcode:

- shortcode: `[kiwi_landing_pages_gallery]`
- source: filesystem landing pages discovered through the shared config/registry path
- card metadata: `country`, `key`, `flow`, `service_key`, provider, routing mode
- auth/cache behavior: when frontend tool auth is enabled, login and authenticated gallery responses send no-cache headers including `CDN-Cache-Control: no-store` and `X-LiteSpeed-Cache-Control: no-cache`
- URL behavior:
  - `hostnames` plus `backend_path`: show absolute HTTPS outside URLs as `https://<hostname><backend_path>`
  - `hostnames` without `backend_path`: fall back to `https://<hostname><dedicated_path>`
  - only `backend_path`: show backend-path strategy and an inferred current-site URL, explicitly labeled as inferred
  - cards render one primary preview URL, preferring the best explicit outside URL

Discovery validation warnings are surfaced in the shortcode output while valid entries keep rendering.

## Request resolution and rendering

At runtime, the router:

1. Resolves landing page by `backend_path` or dedicated `hostnames` plus `dedicated_path`.
2. Creates or reads a landing session token from the `kiwi_landing_session` cookie.
3. Captures click attribution when `clickid` is present, stores it server-side, and sets an opaque `kiwi_tracking_token` cookie.
4. Builds the primary CTA centrally through provider/flow logic, then injects `{{KIWI_PRIMARY_CTA_HREF}}` in HTML.
5. Renders filesystem HTML and wires `styles.css`.

For filesystem HTML and CSS, local media references such as `./hero.png` are rewritten to the configured asset base. This applies to direct HTML `src`/`href`, CSS `url(...)`, and local responsive candidates in `srcset` or `imagesrcset`. External, protocol-relative, `data:`, and root-relative `/...` candidates keep their original browser semantics.

When readable, `styles.css` is inlined into the rendered HTML and the external stylesheet link is suppressed. If it cannot be read, the router falls back to the external stylesheet URL from the landing-page folder.

Landing engagement telemetry and KPI details are documented in `landing-funnel-analytics.md`.

For NTH click-to-SMS flows, CTA construction can append the internal `transaction_id` to the SMS body through centralized adapter logic. The FR SMS-body variant experiment can render a stable visible token while keeping the internal `txn_...` correlation id unchanged server-side.

## Multi-domain exposure via proxy/CNAME

Landing pages can be exposed on multiple public domains without changing core plugin routing or attribution behavior.

Recommended setup:

- keep each landing page `backend_path` stable and unique, for example `/lp/fr/myjoyplay5`
- for backend-path-only test variants, leave `hostnames` empty to avoid taking over a dedicated-host root route
- point each public domain/hostname to the same backend WordPress runtime via DNS plus reverse proxy/CNAME
- keep `hostnames` metadata populated in `integration.php` for diagnostics visibility and optional dedicated-host routing

Proxy/edge requirements:

- terminate TLS with a valid certificate for each public hostname
- preserve original `Host` and forward standard `X-Forwarded-*` headers
- configure `KIWI_TRUSTED_PROXY_CIDRS` only for direct reverse-proxy or edge CIDRs whose forwarded headers may be trusted
- start with exact direct proxy IPs when possible; for the observed Hostinger edge peer, prefer `['2a02:4780:79:a1e9::1']` over broadly trusting `2a02:4780:79::/48` unless the whole range is confirmed as controlled edge infrastructure
- forward request paths unchanged
- avoid exposing non-canonical backend origin hosts to end users when possible

Tracking/cookie note:

- click/session implementation remains unchanged
- `kiwi_landing_session` and attribution token cookies are host-scoped in current implementation
- attribution works as expected when one user journey stays on one public hostname
- avoid mid-flow redirects between different root domains unless an explicit cross-domain handoff design is introduced

## Conversion and attribution behavior

High-level flow:

1. Landing captures attribution context server-side and stores canonical session dimensions on `wp_kiwi_landing_page_sessions`.
2. Provider callbacks are normalized at the provider boundary.
3. Confirmed conversions are resolved against attribution state using stable references.
4. Successful one-off sales are persisted in `wp_kiwi_sales`.
5. After attribution matching, the sale row is enriched with a durable snapshot of service, landing/session, source, device, metric date, and landing-session client-IP context.
6. Affiliate postback dispatch is triggered only for confirmed conversions and only once after `postback_sent_at` is set.
7. When a matching sale exists, outbound postback includes `custom_field1=<operator_name>` sourced from `wp_kiwi_sales.operator_name`.

Important boundary:

- Incoming provider callback validation is provider-specific.
- Outgoing affiliate secret/signature applies only to outbound postbacks.
- Client IP stored on sales must come from resolved landing-session context, not provider callback request metadata.
- Traffic and campaign dimensions stored on landing sessions come from landing metadata, service context, query parameters, and `HTTP_ACCEPT_LANGUAGE`; `country` is the campaign/service country, not a Geo-IP lookup.

For the shared attribution architecture, see `../architecture/click-attribution-and-postbacks.md`.

## Operational checks

When validating a landing-page flow in production or staging, verify:

1. Routing resolves expected landing key for both backend path and dedicated hostname path.
2. CTA contains expected keyword and transaction token behavior.
3. `clickid` capture creates or updates one row in `wp_kiwi_click_attributions` for the active tracking token.
4. Provider callback logs show expected incoming and handled traces.
5. Confirmed provider success results in sale persistence in `wp_kiwi_sales`.
6. Affiliate postback is sent once for confirmed conversions and retried only while `postback_sent_at` is empty.
7. `backend_path` routes resolve correctly on every public hostname that proxies to the backend runtime.
8. User journey stays on one public hostname and does not redirect to a backend origin hostname.
9. Fraud/statistics/gallery tools remain no-cache when protected by frontend auth.
10. Client-IP resolution behaves as configured: without trusted proxies, spoofed forwarded headers are ignored; with a trusted direct peer, the forwarded chain resolves to the first non-trusted client candidate.

Analytics-specific checks live in `landing-funnel-analytics.md`; retention checks live in `retention-runbook.md`.

## Troubleshooting quick map

- Callback rejected with `service_key_unresolved`: usually missing/wrong `service_key` and no unique shortcode plus keyword match.
- No sale in `wp_kiwi_sales`: confirm terminal success status mapping and reference correlation.
- Missing transaction linkage: verify MO content carries the expected `txn_...` token and parser delimiters.
- Duplicate callback confusion: event dedupe is expected; conversion can re-attempt postback only while `postback_sent_at` is empty.
- Broken page route: check folder naming, required files, `integration.php`, `backend_path`, and `hostnames`.

## Filesystem-only loading and rollback

Landing pages are discovered only from validated folders under `landing-pages/*`. The registry cannot be disabled through configuration, and the router has no alternate template-loading path.

### Deployment verification

1. Open active filesystem landing routes including `lp4-fr`, `lp5-fr`, `lp5-fr-v2`, `lp6-fr`, and `lp6-fr-v2` through their backend paths.
2. Verify any public hostname plus backend-path routing used in production.
3. Confirm CTA output, SMS target/body, price disclosure, and local asset rendering.
4. Check WordPress debug logs for missing landing keys, unreadable filesystem files, warnings, or errors.

### Rollback

There is no configuration switch for an alternate loader. If a deployment introduces a loader or renderer regression, deploy the previous application version, then correct the filesystem folder or registry code before redeploying.

### Adding a landing route

1. Create filesystem folder `landing-pages/lp<version>-<country>/`.
2. Add `index.html`, `styles.css`, `integration.php`.
3. Set routing metadata in `integration.php` (`backend_path`, optional `hostnames`/`dedicated_path`).
4. Run registry and router tests.
5. Verify the route and rendering on staging before production deployment.

In debug mode (`KIWI_DEBUG=true`), invalid landing pages fail loudly. In production, invalid entries are skipped and logged.
