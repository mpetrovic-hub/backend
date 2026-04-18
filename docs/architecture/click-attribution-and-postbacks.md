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

- capture data (`tracking_token`, `transaction_id`, `click_id`, landing/provider/service context)
- correlation references (`session_ref`, `transaction_ref`, `message_ref`, `external_ref`, `sale_reference`)
- conversion status (`captured`, `bound`, `confirmed`, postback states)
- outbound postback audit (`postback_sent_at`, response code/body, attempts, errors)
- retention fields (`expires_at`)

`wp_kiwi_sales` can be enriched from attribution context on confirmed conversion, including:

- `pid` (captured from landing query params when present, sanitized before persistence)

## Retention and Cleanup

Attribution rows are intentionally temporary and use explicit expiry.

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

## Reliability Rules

- only `confirmed` conversions trigger postback dispatch
- duplicate callbacks must not emit duplicate postbacks once `postback_sent_at` is set
- failed postbacks are retained in audit fields for retry visibility
- expired temporary rows are cleaned in bounded batches
- each attribution row gets an internal `transaction_id` so provider callbacks can be correlated through stable server-side references

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

`https://offers-kiwimobile.affise.com/postback?clickid={clickid}&click_id={click_id}&sale_reference={sale_reference}&service_key={service_key}&provider_key={provider_key}&operator_name={operator_name}&sub7={sub7}&secure={secure}&hash={hash}&goal=sale`

### Placeholder mapping (source of truth)

- `clickid` / `click_id`
  - affiliate click identifier captured at landing entry
- `sale_reference`
  - internal sale/correlation reference resolved during conversion handling
- `service_key`
  - internal service identifier from normalized conversion context
- `provider_key`
  - provider identifier from normalized conversion context
- `operator_name`
  - resolved operator label when available
- `sub7`
  - alias of `operator_name` for affiliate reporting dimensions
- `secure` / `hash`
  - signature/checksum generated from configured signature algorithm/base/secret

### Dispatch behavior rules

- all substituted values are URL-encoded before request dispatch
- if signature exists and template has neither `{secure}` nor `{hash}`, dispatcher appends configured signature parameter automatically
- if `operator_name` exists and template has no `sub7` parameter, dispatcher appends `sub7=<operator_name>` automatically
- successful dispatch is transport-level HTTP `2xx`
- idempotency boundary is `postback_sent_at`: once set, duplicate callback deliveries must not emit another postback

## Current Wiring Example (NTH FR one-off)

- landing entry capture runs in `Kiwi_Landing_Page_Router`
- filesystem landings can use `{{KIWI_PRIMARY_CTA_HREF}}` so CTA assembly stays in centralized flow logic
- NTH callback normalization and confirmation logic remain in NTH service/normalizer
- NTH resolves pending attribution rows by service/reference and reuses the shared `transaction_id` as the provider reference root
- NTH MO adapter may extract `transaction_id` from keyword-suffixed MO content (for example `JPLAY txn_xxx`) at the provider boundary
- NTH service passes normalized conversion signals into `Kiwi_Conversion_Attribution_Resolver`
- resolver may enrich the matched sale row with shared attribution metadata (for example `pid`) without leaking provider callback fields into sales writes

This is an integration example, not an NTH-only architecture. Additional providers should reuse the same shared resolver/dispatcher capability.
