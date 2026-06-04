# MOBIVAS / LILY MOBILE Greece PSMS Subscription Integration

This folder contains the documentation for the Greece Premium SMS subscription integration implemented through the LILY MOBILE JSON API and the MOBIVAS Greece market/service rules.

Use this folder to understand:
- which API endpoints are used for this setup
- how the user subscription flow works in practice
- which callbacks Kiwi should expect
- which MTs are sent by MOBIVAS/Lily vs by Kiwi as the service provider
- which Greece-specific compliance and operational rules apply
- where the source specifications and supporting material are stored

## Scope

This file is for this concrete Greece subscription setup only.

It should document:
- the Greece subscription activation flow
- the expected MO, MT, DLR, unsubscribe, and HLR behavior for this setup
- the working assumptions currently used by Kiwi
- market-specific compliance constraints that affect implementation
- source-document conflicts or ambiguities that matter for delivery

It should not duplicate the full generic API reference from the Lily Mobile general API summary.

## Integration summary

This integration is a Greece PSMS subscription flow using the LILY MOBILE platform and MOBIVAS market guidance.

At a high level:
1. The user starts the subscription flow from a landing page or by MO keyword.
2. MOBIVAS sends a free call-to-action MT from free short code `54988`.
3. The user must send `OK` to `54988` within 5 minutes.
4. Once that `OK` is received in time, the subscription is considered completed.
5. Kiwi, acting as the service provider (SP), sends the Free Welcome MT.
6. Kiwi also sends the later Billing MTs for the subscription lifecycle.

Current operating interpretation:
- **Web2SMS** is the preferred / recommended flow.
- **Double MO** exists as a fallback pattern, but MOBIVAS marks it as not recommended.
- The confirming `OK` MO is currently treated as a TP-bound MO that Kiwi should receive from Lily, although the API documentation is not perfectly explicit about that in one single sentence.
- The Free Welcome MT and later Billing MTs are handled by **Kiwi / SP**, not automatically by MOBIVAS/Lily.

## Files to read together

- `../../lily-mobile-general-api-mt-platform.md`  
  General Lily Mobile / MT Platform API summary for this repository.

- `README.md`  
  This file. Use it for the concrete Greece subscription flow.

- `../../source/MOBIVAS - ON POINT GUIDE TO GREECE 2022.pdf`  
  Greece market rules, flow diagrams, and compliance guidance.

- `../../source/Mobivas MT Platform Documentation_v1.0.1.docx`  
  API endpoints, payloads, and generic platform behavior.

## Start here

1. Read `../../lily-mobile-general-api-mt-platform.md`
2. Then read this file
3. Then review the original source documents under `source/` when implementing or verifying payload details
4. Check repository credential/config documentation if endpoints, credentials, callbacks, or secrets are involved

## Documentation split

### `../../lily-mobile-general-api-mt-platform.md`
Use the general API file for information that is broadly true for Lily Mobile integrations, for example:
- authentication model
- generic endpoint groups
- generic request/response format
- MO callback shape
- DLR callback shape
- unsubscribe endpoint behavior
- HLR lookup behavior
- platform-wide status codes and response envelope

### `README.md`
Use this file for information that is specific to this Greece subscription setup, for example:
- Web2SMS vs Double-MO flow choice
- the role of free short code `54988`
- who sends the welcome and billing MTs
- Greece-specific message and compliance rules
- implementation assumptions for Kiwi
- unresolved questions that still need confirmation from MOBIVAS/Lily

## Current capability coverage

This setup currently requires documentation for the following capabilities:

- subscription initiation via `subsms`
- outbound MT sending by Kiwi
- inbound MO handling, including the confirming `OK`
- delivery report handling
- explicit unsubscription handling
- HLR lookup or automatic network resolution
- mandatory free compliance MTs such as Welcome / Reminder / STOP confirmation

## Concrete flow for this setup

### Recommended flow: Web2SMS

This is the preferred flow according to MOBIVAS.

1. The user lands on the LP and enters the MSISDN.
2. The user must check the required tickbox with the predefined Greece text.
3. MOBIVAS sends a **free call-to-action MT** from `54988`.
4. The user clicks the confirmation button and is taken to a page that opens the SMS app with a prefilled `OK` reply to `54988`.
5. The user sends `OK` to `54988` within 5 minutes.
6. The subscription is completed.
7. Kiwi sends the **Free Welcome MT**.
8. Kiwi later sends the recurring **Billing MTs** according to the approved service schedule.

Implementation notes:
- A countdown timer on the confirmation page is strongly recommended by MOBIVAS.
- The subscription is considered valid only if the free call-to-action MT was received before the user sent `OK`, and the `OK` was sent within 5 minutes.
- All MTs to the end user must be in Greek.

### Fallback flow: Double MO

MOBIVAS documents a Double-MO flow, but marks it as **not recommended**.

1. The user sends the service keyword to the billing short code.
2. In parallel, the user receives a **free call-to-action MT** from `54988`.
3. The user replies with `OK` to `54988` within 5 minutes.
4. The subscription is completed.
5. Kiwi sends the **Free Welcome MT**.
6. Kiwi later sends the recurring **Billing MTs**.

Use this only if the business setup specifically requires it.

## Endpoint usage for this setup

### 1) Subscription initiation
Use the SMS subscription endpoint:

`POST /sms/subsms/{username}/{password}`

JSON fields:
- `MSISDN`
- `PINId`
- `NetworkId` (optional)

Behavior:
- If `NetworkId` is omitted, Lily Mobile may automatically perform HLR to resolve the network.
- The synchronous API response confirms request acceptance, not final user subscription.
- Final subscription depends on the user sending `OK` within the allowed window.

### 2) Outbound MT sending
Use the MT send endpoint provisioned for the account.

Documented source material shows an inconsistency between:
- `/sms/{username}/{password}` in the text description
- `/sms/sendsms/{username}/{password}` in the example screenshots

For this integration, use the endpoint confirmed during provisioning/testing and keep the general API file updated if a definitive path is confirmed.

Typical MT payload fields:
- `Receiver`
- `Text`
- `TransactionId`
- `ServiceId`
- `CostId`
- `NetworkId`

### 3) MO callback handling
Kiwi should expose a TP-bound callback endpoint to receive inbound MO traffic from Lily.

Documented fields:
- `Sender`
- `Text`
- `NetworkId`
- `Shortcode`
- `DateTimeSent`

For this setup, Kiwi should be prepared to receive:
- normal user MO traffic
- stop keywords such as `STOP`, `STOPALL`, `STOP ALL`, `STOP IVR`
- likely the subscription-confirming `OK` sent to `54988`

### 4) Delivery reports
Kiwi should expose a DLR endpoint to receive MT delivery reports.

Documented fields:
- `TransactionId`
- `StatusId`
- `NetworkId`
- `DateTimeDelivered`

Use the general API document for status code interpretation.

### 5) Unsubscription
Use the unsubscription endpoint:

`POST /sms/unsubscribe/{username}/{password}`

Documented source material contains a payload inconsistency:
- the table describes `MSISDN` + `Keyword`
- the screenshot example appears to use `MSISDN` + `ServiceId`

For this integration, confirm the exact payload shape with the live/provisioned setup and keep this README updated when confirmed.

Important:
- Using the endpoint does **not** replace the requirement to send a free STOP confirmation MT.
- Kiwi must still send the required free unsubscription confirmation MT.

### 6) HLR lookup
Use the dedicated HLR endpoint when needed:

`POST /hlr/v2/{username}/{password}`

Payload:
- `MSISDN`

For Greece, HLR/network resolution matters because number portability is significant. Lily also states that if `NetworkId` is missing in the subscription initiation request, HLR may be performed automatically.

## Working assumptions for Kiwi

The current working assumptions for implementation are:

- Kiwi is the **SP** in the MOBIVAS documents.
- MOBIVAS/Lily sends the **free call-to-action MT** from `54988`.
- Kiwi receives the confirming `OK` as an MO callback from Lily.
- Kiwi sends the **Free Welcome MT** after successful confirmation.
- Kiwi sends the recurring **Billing MTs**.
- Kiwi must handle STOP-style MO commands and must send the mandatory free STOP confirmation MT.
- Kiwi should store enough state to link:
  - subscription initiation request
  - pending confirmation window
  - inbound `OK`
  - welcome MT
  - recurring billing MTs
  - unsubscribe lifecycle

These assumptions are consistent with the current source set, but any production go-live should still confirm the final callback details with MOBIVAS/Lily.

## Suggested internal state model

A practical internal state model for this setup is:

- `pending_confirmation`  
  `subsms` accepted, waiting for user `OK`

- `subscribed_active`  
  valid `OK` received in time, welcome MT sent or queued, billing active

- `unsubscribe_pending`  
  stop request received and unsubscribe endpoint called, waiting for local completion tasks if any

- `unsubscribed`  
  user is no longer subscribed, free STOP confirmation MT sent

Recommended state transitions:
1. `subsms` accepted -> `pending_confirmation`
2. valid inbound `OK` matched to the pending request -> `subscribed_active`
3. STOP-like MO or internal unsubscribe action -> `unsubscribe_pending`
4. unsubscribe processed and confirmation MT sent -> `unsubscribed`

## Greece-specific compliance and operational rules

The following rules materially affect implementation:

- All MTs to the end user must be in **Greek**
- All SMS MTs are managed by the **SP**
- Web2SMS is strongly recommended for subscription services
- A **Free Welcome SMS-MT** is mandatory
- A **Free Monthly Reminder SMS** is mandatory
- A **Free STOP confirmation SMS-MT** is mandatory
- A landing-page tickbox with predefined wording must be used for subscription services
- The standardized **InfoBar** must be present on LPs
- Monthly subscription cap is `24.96 EUR gross / MSISDN`
- STOP commands include `STOP`, `STOP KEYWORD`, and Greek / Latin variations such as `ΣΤΟΠ`
- Cross-selling is not allowed within the subscription model

Do not treat these as optional product details. They are flow-shaping requirements for the Greece setup.

## What should be documented here over time

Keep this file updated when any of the following become clearer:

- the exact confirmed MT send endpoint path
- whether the `OK` confirmation MO is always forwarded in TP-bound callbacks
- whether any additional subscription-success callback exists
- the exact unsubscribe payload shape used in production
- service-specific shortcode / keyword / price configuration
- billing schedule and reminder schedule details
- message templates used in production
- any operator-specific deviations

## Source material

Store or reference the original documents for this integration under `source/`, for example:
- API specifications
- Greece market guides
- onboarding emails/notes
- callback examples
- approved message templates
- compliance wording and InfoBar assets

Prefer keeping a Markdown summary in this folder even when the original source files are available.

## Rules for updates

Update this file when:
- the Greece subscription flow is clarified
- callback handling changes
- the welcome/billing/stop MT ownership changes
- payload examples are confirmed or corrected
- new compliance constraints are discovered
- the implementation model in code changes

Update the general API file when the change affects Lily Mobile broadly rather than only this Greece setup.

## Credentials and configuration

Document configuration without storing real secret values.

At minimum, document:
- Lily username/password ownership and environment usage
- callback endpoint locations
- service identifiers such as `PINId`, `ServiceId`, `CostId`
- short code mappings
- network ID handling
- any feature flags for HLR / reminder / stop flow behavior

Do not store production secrets in this file.

## Intended audience

This file should help:
- planners deciding how to model the Greece subscription flow
- builders implementing or extending the integration
- reviewers checking whether code behavior matches the documented flow
- operators investigating delivery, subscription, or unsubscribe issues

## Goal

This file should provide a clear bridge between:
- the Lily Mobile API documentation
- the MOBIVAS Greece flow/compliance documents
- Kiwi’s actual implementation assumptions for this subscription setup
