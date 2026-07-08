# Click Attribution and Conversion Postbacks

## Read when

- Work touches affiliate click capture, attribution matching, confirmed conversion handling, or outbound affiliate postbacks.
- You need the shared boundary between provider callbacks and reusable attribution/postback logic.

## Source of truth for

- Shared attribution and postback capability design.
- Normalized conversion signal boundary.
- Postback idempotency and reliability rules.

## Not here

- Landing KPI, Statistics UI, daily summaries, and analytics tables: see `../operations/landing-funnel-analytics.md`.
- Retention runbooks and cleanup workers: see `../operations/retention-runbook.md`.
- Config constant reference: see `../operations/configuration-reference.md`.
- Provider-specific callback payloads: see `../integrations/INDEX.md`.

## Purpose

This document defines a reusable, provider-agnostic capability for:

- capturing incoming affiliate click identifiers on landing entry
- persisting attribution state server-side with expiry
- matching later confirmed conversions to stored click attribution
- dispatching affiliate postbacks safely and idempotently

Provider callback payload formats remain inside provider adapters/routes.

## Internal capability boundary

Shared attribution components:

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

## Provider integration boundary

Provider-specific callback handling remains in provider layers:

- payload parsing
- provider auth/validation
- command/status semantics
- provider retries or quirks
- provider-specific error translation

Provider adapters should forward only normalized fields into attribution resolver calls:

- `provider_key`
- `service_key`
- `transaction_id`
- conversion confirmation signal (`confirmed`)
- timestamp (`occurred_at`)
- stable references: `transaction_ref`, `message_ref`, `sale_reference`, `external_ref`, `session_ref`

No provider-specific callback shape should leak into shared attribution code.

## Storage boundary

`wp_kiwi_click_attributions` is temporary attribution state. It stores:

- `tracking_token`
- internal `transaction_id`
- affiliate click/source fields such as `click_id`, `pid`, `tksource`, `tkzone`
- landing/provider/service context
- correlation references
- conversion status
- outbound postback audit fields
- `expires_at`

Durable sales and analytics snapshots are owned by the sales and analytics capabilities, documented in `../operations/landing-funnel-analytics.md`.

## Fraud monitoring propagation

The shared attribution layer feeds downstream Premium SMS fraud-monitoring context without taking ownership of provider payloads:

1. Landing entry capture stores `click_id` and optional source fields.
2. Landing KPI engagement events resolve and persist source context into engagement rows.
3. SMS-body variant assignment can render a visible token while preserving internal `transaction_id`.
4. Landing handoff events preserve click-to-SMS transition evidence.
5. Inbound MO fraud evaluation snapshots source context into fraud signals.
6. Billing attempts and terminal reports update fraud snapshots with outcome, sale linkage, and normalized aggregator status.

Detailed analytics storage for those rows lives in `../operations/landing-funnel-analytics.md`.

## Tracking-first S2S postback contract

End-to-end sequence:

1. User lands with affiliate query params, for example `clickid`.
2. `Kiwi_Tracking_Capture_Service` stores attribution server-side and sets an opaque cookie.
3. Provider callbacks arrive later with provider-specific references, not the original affiliate `clickid`.
4. Provider layer normalizes callback fields and forwards stable refs to `Kiwi_Conversion_Attribution_Resolver`.
5. Resolver matches attribution, enforces confirmed-only dispatch, and calls `Kiwi_Affiliate_Postback_Dispatcher`.
6. Dispatcher builds the outbound URL template, applies placeholders/signature, and sends an S2S HTTP request.
7. Postback audit fields are persisted for retry/idempotency behavior.

Example S2S URL template:

`https://offers-kiwimobile.affise.com/postback?clickid={clickid}&secure={secure}&goal=sale&status=1`

## Placeholder mapping

- `clickid` / `click_id`: mandatory affiliate click identifier captured at landing entry.
- `secure`: mandatory hash password generated on offer or advertiser level.
- `goal`: conversion goal number or goal value; use `sale` for successful sale postbacks.
- `status`: optional conversion status; `1` approved, `2` pending, `3` declined, `5` hold.
- `sum`: optional conversion revenue.
- `ip`: optional visitor IP address, subject to privacy handling.
- `referrer`: optional referrer or traffic/deeplink information.
- `comment`: optional comment.
- `fbclid`: optional Facebook click ID.
- `device_type`: optional device type.
- `aimp_id`: optional Affise impression ID.
- `promo_code`: optional promo code.
- `user_id`: optional user ID.
- `custom_field1` through `custom_field15`: optional Affise parameters; `custom_field1` is the current operator reporting dimension and is populated from normalized `operator_name` when available.
- `action_id`: optional unique conversion ID in the advertiser system; use only when the integration intentionally wants Affise conversion uniqueness based on `action_id`, `goal`, and offer instead of click ID.

## Dispatch behavior rules

- All substituted values are URL-encoded before request dispatch.
- Dispatcher emits only parameters present in the configured postback template, plus configured automatic signature/operator behavior.
- Affise sale postback templates must include `secure={secure}`.
- Successful dispatch is transport-level HTTP `2xx`.
- Idempotency boundary is `postback_sent_at`: once set, duplicate callbacks must not emit another postback.
- Only `confirmed` conversions trigger postback dispatch.
- Failed postbacks are retained in audit fields for retry visibility.
- Expired temporary attribution rows are cleaned in bounded batches.
- Visible SMS-body tokens are lookup aliases only; they must resolve back to the unchanged internal `transaction_id`.

## Current wiring example: NTH FR one-off

- Landing entry capture runs in `Kiwi_Landing_Page_Router`.
- Filesystem landings can use `{{KIWI_PRIMARY_CTA_HREF}}` so CTA assembly stays in centralized flow logic.
- NTH callback normalization and confirmation logic remain in NTH service/normalizer.
- NTH resolves pending attribution rows by service/reference and reuses the shared `transaction_id` as the provider reference root.
- NTH MO adapter may extract `transaction_id` from keyword-suffixed MO content at the provider boundary.
- When the FR SMS-body variant experiment is active, NTH MO handling resolves assigned visible tokens through `wp_kiwi_sms_body_variant_assignments`.
- NTH service passes normalized conversion signals into `Kiwi_Conversion_Attribution_Resolver`.
- Resolver enriches matched sale rows with shared attribution snapshots without leaking provider callback payload shapes into sales writes.

This is an integration example, not an NTH-only architecture. Additional providers should reuse the same shared resolver/dispatcher capability.

