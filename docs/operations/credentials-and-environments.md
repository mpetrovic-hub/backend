# Credentials and Environments

This file documents where aggregator credentials and environment-specific integration settings are managed in this repository.

It is intentionally short and central.  
Aggregator-specific details belong in the corresponding integration docs.

## Current setup

Aggregator credentials and related integration settings are currently stored in:
- `wp-config.php`

Landing-page definitions are no longer stored in `wp-config.php`.

Landing-page source of truth is now:
- `landing-pages/<landing-key>/integration.php`
- `landing-pages/<landing-key>/index.html`
- `landing-pages/<landing-key>/styles.css`

Configuration access inside the codebase is currently handled through:
- `class-config.php`

## Scope

Use this file to document:
- where credentials are stored
- how the code reads configuration
- which integration settings are environment-dependent
- where credential-related changes must also be reflected

Do not use this file for:
- detailed aggregator API documentation
- country- or flow-specific business rules
- storing real secret values

## Rules

- Never store real secrets, passwords, API keys, tokens, or shared secrets in repository documentation.
- Keep aggregator-specific credential details in the relevant integration docs.
- Update this file only when the overall configuration approach changes.
- If the configuration access pattern changes, document the new access path here.

## Current aggregator note

At the moment, aggregator-related configuration is managed centrally via `wp-config.php`, with access through `class-config.php`.

Landing-page loading is filesystem-driven and managed by the landing-page registry in application code.

Examples of configuration types that may exist there:
- usernames
- passwords / shared secrets
- base URLs
- callback URLs
- affiliate postback URL templates
- affiliate postback signing secrets
- debug flags
- merchant identifiers
- order / service identifiers
- service-specific configuration arrays
- callback debug logging toggles

## Affiliate postback and attribution configuration

The click attribution and affiliate postback capability uses configuration from `wp-config.php` through `Kiwi_Config`.

Expected keys:

- `KIWI_AFFILIATE_POSTBACK_URL_TEMPLATE`
  - outbound affiliate postback URL template
  - supports placeholders such as `{clickid}` / `{{clickid}}`, `{operator_name}` / `{{operator_name}}`, `{sub7}` / `{{sub7}}`, and optional `{hash}` / `{secure}` (`{{hash}}` / `{{secure}}` also supported)
  - example (full placeholder set): `https://offers-kiwimobile.affise.com/postback?clickid={clickid}&click_id={click_id}&sale_reference={sale_reference}&service_key={service_key}&provider_key={provider_key}&operator_name={operator_name}&sub7={sub7}&secure={secure}&hash={hash}&goal=sale`

- `KIWI_AFFILIATE_POSTBACK_SECRET`
  - shared secret for outgoing affiliate postback signing/checksum generation
  - this is **not** used for incoming aggregator callbacks

- `KIWI_AFFILIATE_POSTBACK_SIGNATURE_ALGORITHM`
- `KIWI_AFFILIATE_POSTBACK_SIGNATURE_BASE`
- `KIWI_AFFILIATE_POSTBACK_SIGNATURE_PARAMETER`
- `KIWI_AFFILIATE_POSTBACK_TIMEOUT_SECONDS`

- `KIWI_CLICK_ATTRIBUTION_COOKIE_NAME`
- `KIWI_CLICK_ATTRIBUTION_CLICK_ID_KEYS`
- `KIWI_CLICK_ATTRIBUTION_TTL_SECONDS`
- `KIWI_CLICK_ATTRIBUTION_CLEANUP_LIMIT`

Runtime enrichment note:

- when `operator_name` is available during conversion resolution, outbound postbacks include `sub7=<operator_name>`
- if the template already defines a `sub7` query parameter, that value is used; otherwise `sub7` is appended automatically
- no additional credential key is required for this enrichment

Supported postback parameters/placeholders:

- `clickid` / `click_id`
  - affiliate click identifier from attribution capture; URL-encoded before dispatch
- `sale_reference`
  - internal sale/correlation reference resolved during conversion handling
- `service_key`
  - internal service identifier (for example flow/service mapping key)
- `provider_key`
  - upstream provider identifier (for example `nth`, `dimoco`, `lily`)
- `operator_name`
  - resolved operator label when available in normalized conversion/sales context
- `sub7`
  - alias for `operator_name` used by Affise-style reporting dimensions
- `secure` / `hash`
  - signature/checksum value generated from configured signature algorithm/base/secret
- signature parameter fallback (`KIWI_AFFILIATE_POSTBACK_SIGNATURE_PARAMETER`)
  - when a signature is available and template does not include `{secure}` or `{hash}`, dispatcher appends this query parameter automatically

Do not store real values for these secrets in repository docs.

## NTH callback logging toggles

To debug callback delivery to:
- `POST /wp-json/kiwi-backend/v1/nth-callback`

and outgoing NTH `submitMessage` transport attempts:
- submit request logs use prefix `[kiwi-nth-submit] outgoing`
- submit response logs use prefix `[kiwi-nth-submit] response` (or `response_error`)

you can enable:

- `KIWI_NTH_CALLBACK_LOGGING_ENABLED`
  - enables route-level callback logs (request hit, resolution, handling result)
- `KIWI_NTH_CALLBACK_PAYLOAD_LOGGING_ENABLED`
  - includes sanitized callback payload values in logs
  - sensitive keys (for example `password`, `secret`, `token`, `digest`, `signature`, `auth`) are redacted

If these constants are not defined:
- callback logging defaults to `KIWI_DEBUG`
- payload logging defaults to callback-logging setting

## Premium SMS fraud monitoring toggles

Premium-SMS fraud monitoring supports MO volume signals and landing-engagement signals.

Optional `wp-config.php` keys:

- `KIWI_PREMIUM_SMS_FRAUD_THRESHOLD_1H`
  - soft-flag MO identity when count within 1 hour reaches this value
  - default: `3`
- `KIWI_PREMIUM_SMS_FRAUD_THRESHOLD_24H`
  - soft-flag MO identity when count within 24 hours reaches this value
  - default: `6`
- `KIWI_PREMIUM_SMS_FRAUD_MO_ENGAGEMENT_MODE`
  - `observe` (default) or `block`
  - `observe`: record/report signals only
  - `block`: allow NTH one-off integration to block MT submission on suspicious MO engagement signals
- `KIWI_PREMIUM_SMS_FRAUD_MO_REQUIRE_PAGE_LOADED`
  - require `page_loaded` landing telemetry for engagement checks
  - default: `true`
- `KIWI_PREMIUM_SMS_FRAUD_MO_REQUIRE_CTA_CLICK`
  - require `cta_click` landing telemetry for engagement checks
  - default: `true`
- `KIWI_PREMIUM_SMS_FRAUD_MO_MIN_SECONDS_AFTER_LOAD`
  - suspicious-speed threshold for `MO occurred_at - page_loaded_at`
  - default: `1`

## Environment note

If different environments use different credential sets or endpoints, document the overall pattern here.

Examples:
- local
- staging
- production

Do not store actual environment secret values in this file.

## Landing-page multi-domain operations

For exposing landing pages on multiple public domains:

- no additional plugin constants are required for v1 proxy/CNAME rollout
- domain onboarding is handled at infrastructure level (DNS, certificates, reverse proxy)
- landing routing metadata remains in each `landing-pages/<landing-key>/integration.php` (`backend_path`, optional `hostnames`/`dedicated_path`)
- keep full user journeys on one public hostname; current attribution/session cookies are host-scoped
- if a future flow requires cross-root-domain redirects with shared attribution continuity, treat that as a separate capability design (not part of current baseline)

## Where to document details

For aggregator-specific configuration details, see:
- `docs/integrations/dimoco/README.md`
- `docs/integrations/nth/README.md`

For country- or flow-specific configuration usage, see the relevant files under:
- `docs/integrations/<aggregator>/<country-code>/<flow-name>/`

## Agent support for new setups

When a new aggregator, country setup, or flow is added, the agent may propose how the required configuration should be structured in `wp-config.php`.

When a new landing page is added, it must be created under `landing-pages/` and must not be added to `wp-config.php`.

This may include:
- suggested constant names
- suggested array structure
- separation of global vs aggregator-specific vs country-/flow-specific values
- suggestions for how configuration should be accessed through the existing configuration layer

The agent must not assume that configuration has already been added manually.

The agent should:
- explain which new configuration values are needed
- suggest where they should be placed in `wp-config.php`
- mention whether changes in `class-config.php` or related access code are likely required
- wait for manual implementation of the actual config values where necessary

For landing pages specifically, the agent should:
- create a new `landing-pages/lp<version>-<country>/` folder
- provide `integration.php`, `index.html`, and `styles.css`
- link `documentation` to `/integrations/...`
- avoid adding new `KIWI_LANDING_PAGES` entries

The agent must not invent or insert real secret values into documentation or code.

## Purpose

This file should make it easy to answer:
- Where are credentials stored?
- How does the code access them?
- Where should credential-related documentation be updated?
- Which file explains the detailed setup for a specific aggregator?
