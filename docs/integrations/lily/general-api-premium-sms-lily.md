# Lily Mobile General API — MT Platform (JSON)

This document summarizes the generic Lily Mobile MT Platform JSON API for use in this repository.

It is based on the attached Lily Mobile Word documentation together with the embedded request/response screenshots. It is intended to be the practical integration summary for what appears to be generally true for this API, while explicitly calling out places where the screenshots and the body text do not fully agree.

## Source

Primary sources:
- `JSON - MT Platform Documentation_v1.1.docx`
- Embedded request/response screenshots contained in that document
- `MOBIVAS - ON POINT GUIDE TO GREECE 2022.pdf` (for Greek service-flow clarification, especially the subscription flow on pages 5-6)

Reference style/source template:
- `general-api-premium-sms.md`

## Scope

This file covers:
- platform purpose and communication model
- JSON transport conventions
- MT submission
- TP-bound MO posting
- delivery reports
- unsubscription
- HLR lookup
- SMS subscription initiation
- sample request/response payloads extracted from the screenshots
- an implementation-oriented explanation of how the "subscribe a new user" flow appears to work
- a clarified Greece subscription flow model using the Mobivas guide's Web2SMS / Double-MO notes

This file does not cover:
- commercial terms
- account provisioning details outside the API surface
- operator-specific pricing
- service-specific legal wording
- the exact wording/content of the Free Welcome MT and Billing MTs handled by the service provider
- any undocumented success callback for completed subscriptions

## Product overview

The Lily Mobile platform exposes a JSON-over-HTTP API for third-party clients. At a high level, the platform is designed to:
- receive MT submissions from the third party and deliver them toward the operator / end user
- forward inbound MO messages from operators to the third party
- forward delivery reports to the third party
- perform HLR lookups
- support subscription management

The documentation describes all gateway communication as JSON and states that the platform uses HTTP/HTTPS connectivity with UTF-8 as the default POST encoding.

## Terminology

Important terms used in the Lily Mobile documentation:

- **MT**  
  Mobile Terminated SMS sent from the third party toward the mobile user.

- **MO**  
  Mobile Originated SMS sent by the mobile user and received via the operator.

- **TPBound**  
  Third-party-bound traffic. In this document, that means messages received by Lily from operators and forwarded onward to the third party.

- **DLR**  
  Delivery report for a previously submitted MT.

- **HLR lookup**  
  Network resolution for a given MSISDN. The documentation states this is mandatory for Greek traffic because prefix-based operator detection is not reliable due to number portability.

- **Premium rate (PMT) / F2U**  
  The gateway can send premium-rate or free-to-user messages depending on charging setup.

## Prerequisites

According to the documentation, a third party needs:
- provisioned account credentials (`username` and `password`)
- target URLs / callback URLs configured by Lily Mobile
- service configuration such as service IDs, cost IDs, short codes, and PIN IDs
- an application capable of sending and receiving HTTP POST requests
- agreement / onboarding completion with Lily Mobile before connectivity is activated

## Transport and payload format

The Lily Mobile API is JSON-based over HTTP.

Generic request characteristics:
- requests are sent with HTTP `POST`
- payloads are JSON
- credentials are supplied in the URL path, not inside the JSON body
- request header should use `Content-Type: application/json`
- default text encoding is UTF-8

Generic response characteristics:
- API calls return a common JSON envelope:
  - `status`
  - `messages`
  - `payload`

Observed common response shape:
```json
{
  "status": "OK",
  "messages": [],
  "payload": {}
}
```

## Communication model

At a high level, the integration appears to work like this:

1. The third party sends MT or control requests to Lily Mobile.
2. Lily Mobile validates and accepts or rejects the request synchronously.
3. Lily Mobile later forwards inbound MO traffic and DLR traffic to the third party.
4. The third party must acknowledge TP-bound callbacks with an HTTP response.
5. If Lily does not receive the callback response in time, it retries on a fixed schedule.

### Callback acknowledgement and retry behavior

For TP-bound MO posts, the third party must expose an endpoint that accepts Lily's HTTP POST requests and confirms receipt.

Documented retry behavior:
- timeout window for the third-party response: `1000ms`
- retry interval: every `5 minutes`
- maximum attempts: `5`

## Network IDs

The documentation uses the following `NetworkId` values:

- `265` = Cosmote
- `268` = Vodafone
- `635` = Wind / NOVA

## Operations

## MT submission (`sendsms`)

Purpose:
- send an MT SMS from the third party through Lily Mobile

Documented endpoint in the body text:
```text
https://api.lilymobile.gr/sms/{username}/{password}
```

Observed endpoint in the embedded valid/invalid examples:
```text
https://api.lilymobile.gr/sms/sendsms/{username}/{password}
```

Because the screenshot examples are concrete and the generic text omits `sendsms`, this summary treats `/sms/sendsms/{username}/{password}` as the more likely practical endpoint, but this should be confirmed with Lily before production rollout.

Required JSON fields from the documentation:
- `Receiver` -> receiver MSISDN
- `Text` -> SMS body, max 160 characters
- `TransactionId` -> tracking ID for DLR correlation, max 50 characters
- `ServiceId` -> service identifier
- `CostId` -> identifier corresponding to the SMS short code
- `NetworkId` -> operator/network identifier

Observed example request:
```json
{
  "Receiver": "30XXXXXXXXXX",
  "Text": "billing_test",
  "NetworkId": 268,
  "TransactionId": "billing_test_vdafone_01",
  "ServiceId": 1,
  "CostId": 1,
  "DateTimeSent": "2023-09-18 13:34"
}
```

Observed successful response:
```json
{
  "status": "OK",
  "messages": [
    "SMS Successfully submitted"
  ],
  "payload": {}
}
```

Observed invalid request example (missing `TransactionId`):
```json
{
  "Receiver": "30XXXXXXXXXX",
  "Text": "billing_test",
  "NetworkId": 268,
  "ServiceId": 1,
  "CostId": 1,
  "DateTimeSent": "2023-09-18 13:34"
}
```

Observed invalid response:
```json
{
  "status": "ERROR",
  "messages": [
    "Missing mandatory parameter TransactionId"
  ],
  "payload": {}
}
```

Notes:
- The body text does not list `DateTimeSent` in the MT send field table, but the screenshot example includes it.
- The synchronous response only confirms gateway acceptance. It does **not** replace a delivery report.

## MO posting (`TPBound`)

Purpose:
- forward inbound MO SMS traffic from the operator / gateway to the third party

Documented payload fields:
- `Sender` -> sender MSISDN
- `Text` -> inbound SMS body
- `NetworkId` -> operator/network ID
- `Shortcode` -> shortcode the user texted
- `DateTimeSent` -> send timestamp in `yyyy-MM-dd HH:mm:ss:fff`

Documented TP-bound callback shape:
```json
{
  "Sender": "3069XXXXXXXX",
  "Text": "keyword or free text",
  "NetworkId": 265,
  "Shortcode": "54XXX",
  "DateTimeSent": "2026-04-13 12:34:56:123"
}
```

Special handling rule documented by Lily:
- inbound messages with body `STOP`, `STOPALL`, `STOP ALL`, `STOP IVR`, or `OK` must be processed
- if the MSISDN is subscribed to any of the third party's services, it must be unsubscribed
- if the MSISDN is not subscribed, the message should be ignored

This `OK` rule conflicts with the subscription section, which also says `OK` is the confirmation keyword used to complete a new subscription. See the dedicated flow analysis below.

## Delivery reports (`DLR`)

Purpose:
- notify the third party about delivery status changes for a previously submitted MT

Documented payload fields:
- `TransactionId`
- `StatusId`
- `NetworkId`
- `DateTimeDelivered`

Observed example callback body from the screenshot:
```json
{
  "TransactionId": "97f82db8-7117-4f77-ac42-45aa05e77457",
  "StatusId": 5,
  "NetworkId": 265,
  "DateTimeDelivered": "2019-09-03 12:36:45"
}
```

Use `TransactionId` to correlate the DLR to the original MT submission.

## MSISDN unsubscription

Purpose:
- notify Lily Mobile that a subscription has been terminated

Documented endpoint:
```text
https://api.lilymobile.gr/sms/unsubscribe/{username}/{password}
```

Documented request fields in the body text:
- `MSISDN`
- `Keyword*`

Observed request example from the screenshot:
```json
{
  "MSISDN": "306906854036",
  "ServiceId": 6
}
```

Observed response example:
```json
{
  "status": "Ok",
  "messages": [
    "1 subscription(s) deleted."
  ],
  "payload": {}
}
```

Important note from the documentation:
- even when this endpoint is used, the third party must also send a free MT unsubscription message

Implementation note:
- the screenshot uses `ServiceId`, while the text table says `Keyword`.
- this is an API documentation inconsistency and should be verified with Lily before implementation.
- if you are designing the adapter defensively, support the provider-confirmed variant only and do not assume both are valid without confirmation.

## HLR lookup

Purpose:
- resolve the current operator/network for an MSISDN

Documented endpoint:
```text
https://api.lilymobile.gr/hlr/v2/{username}/{password}
```

Documented request field:
- `MSISDN`

Observed request example:
```json
{
  "MSISDN": "306906854036"
}
```

Observed response example:
```json
{
  "status": "OK",
  "messages": [],
  "payload": {
    "hlrStatus": "SUCCESS",
    "operator": "WIND",
    "msisdn": "306906854036"
  }
}
```

Documented HLR response values in the appendix:
- `OK`
- `DATA MISSING`
- `CALL BARRED`
- `ABSENT SUBSCRIBER`
- `UNKNOWN SUBSCRIBER`
- `UNEXPECTED DATA VALUE`
- `FACILITY NOT SUPPORTED`
- `TELESERVICE NOT PROVISIONED`
- `HLR REJECT`
- `HLR ABORTED`
- `HLR LOCAL CANCEL`
- `REQUEST THROTTLED`
- `IMSI LOOKUP BLOCKED`
- `SERVICE FAILURE`

Implementation note:
- the screenshot shows `hlrStatus: "SUCCESS"`, while the appendix lists `OK` and other textual response values.
- this looks like another documentation mismatch or a difference between transport-level and business-level wording.

## SMS subscription initiation (`subsms`)

Purpose:
- initiate a new SMS-based subscription flow for an MSISDN

Documented endpoint:
```text
https://api.lilymobile.gr/sms/subsms/{username}/{password}
```

Documented request fields:
- `MSISDN`
- `PINId`
- `NetworkId*`

Documented behavior:
- if `NetworkId` is omitted, Lily automatically performs an HLR lookup
- the user must reply with keyword `OK` within 5 minutes
- when the user replies in time, the MSISDN will be subscribed to the service

Greek market flow clarification from the Mobivas guide:
- the confirmation MO is sent to the free short code `54632`
- the user first receives a **FREE call-to-action MT** from `54632`, handled by **MOBIVAS**
- in the recommended **Web2SMS** flow, the landing page opens a prefilled SMS app so the user can send `OK` to `54632`
- in the fallback **Double MO** flow, the user first sends the service keyword to the billing short code, then confirms by replying `OK` to `54632`
- in both cases, the subscription is completed only if the `OK` reaches `54632` within 5 minutes of the call-to-action MT

Operational clarification from the user:
- after successful confirmation, the **Free Welcome MT** is sent by the **service provider**
- the later **Billing MTs** are also handled by the **service provider**
- the working assumption is that Kiwi also receives the user's confirming `OK` MO TP-bound from Lily, even though the flow docs do not say this in one explicit sentence

Observed valid request example:
```json
{
  "MSISDN": "306906854036",
  "PINId": 38,
  "NetworkId": 635
}
```

Observed valid response example:
```json
{
  "status": "OK",
  "messages": [
    ""
  ],
  "payload": {}
}
```

Observed invalid request example (missing `PINId`):
```json
{
  "MSISDN": "306906854036",
  "NetworkId": 635
}
```

Observed invalid response:
```json
{
  "status": "ERROR",
  "messages": [
    "Missing mandatory parameter PINId"
  ],
  "payload": {}
}
```

## DLR status codes

The appendix lists the following `StatusId` values:

| Code | Description |
|---|---|
| `0` | no error |
| `2` | Delivered to operator, when no DLR is requested |
| `3` | Message delivered to operator |
| `5` | Message delivered to handset |
| `6` | Failure at operator |
| `7` | Failed at gateway |
| `10` | Message length invalid |
| `11` | User does not exist in SDP |
| `12` | Originator insufficient credit |
| `13` | User(s) are barred |
| `15` | Invalid source address |
| `16` | Message expired at gateway |
| `17` | Invalid expiry time |
| `19` | Originator insufficient credit |
| `13` | Gateway barred |
| `25` | Generic failure |
| `26` | Expired |
| `27` | Unknown status |
| `208` | Invalid destination address |
| `209` | Invalid type ID |
| `210` | Invalid service ID |
| `211` | Invalid cost ID |
| `213` | Invalid network ID |
| `214` | Invalid priority |
| `217` | Invalid delivery |
| `266` | HLR lookup failed |
| `279` | 20 euro cap |
| `280` | 40 euro cap voda |
| `281` | 20 euro cap will be exceeded |

Implementation note:
- code `13` appears twice in the appendix with two different descriptions (`User(s) are barred` and `Gateway barred`).
- that duplication is likely a documentation typo and should be clarified with Lily if your implementation depends on an exact code map.

## Common response envelope

The documentation says all Lily API calls use the same envelope:

- `status` -> request success/failure info
- `messages` -> array of informational/error messages
- `payload` -> operation-specific response payload, when applicable

Examples seen in the screenshots:
- success with message text
- success with empty `messages`
- success with empty-string message
- error with one validation message

That means consumers should not assume:
- `messages` is always empty on success
- `messages` always contains useful human-readable content
- `payload` always contains business data

Instead, callers should parse the response defensively.

---

# Subscribe a new user — how this appears to work

Below is the most plausible end-to-end interpretation of the subscription flow based on the document text and examples.

## Direct interpretation of the documented flow

1. The third party decides to initiate a new subscription for an MSISDN.
2. The third party calls:
   ```text
   POST https://api.lilymobile.gr/sms/subsms/{username}/{password}
   ```
3. The request body contains:
   - `MSISDN`
   - `PINId`
   - optionally `NetworkId`
4. If `NetworkId` is omitted, Lily performs an HLR lookup automatically.
5. Lily accepts the initiation request synchronously and returns the standard JSON response envelope.
6. Lily / Mobivas then triggers the subscription confirmation step toward the user by sending a **FREE call-to-action MT** from the free short code `54632`.
7. The user must reply with `OK` to `54632` within 5 minutes.
8. If the user replies with `OK` in time, the MSISDN is considered subscribed.
9. After successful subscription, the service provider is responsible for sending the Free Welcome MT and subsequent Billing MTs.

## Greece flow interpretation using the Mobivas guide

The Mobivas guide makes the flow materially clearer than the API doc alone:

### Recommended Web2SMS flow

1. User submits their MSISDN on the landing page and checks the mandatory tick box.
2. In parallel, the user receives a **FREE call-to-action MT** from `54632`, handled by **LILY**.
3. The user is redirected to a page with a button that opens the SMS app prefilled to send `OK` to `54632`.
4. If the user sends `OK` to `54632` within 5 minutes, the subscription is completed.
5. After completion, the **Free Welcome MT** and later **Billing MTs** are handled by the **service provider**.

### Fallback Double-MO flow

1. User sends the service keyword to the billing short code.
2. In parallel, the user receives a **FREE call-to-action MT** from `54632`, handled by **LILY**.
3. If the user replies with `OK` to `54632` within 5 minutes, the subscription is completed.
4. After completion, the **Free Welcome MT** and later **Billing MTs** are handled by the **service provider**.

The guide also says a subscription is valid only if the free call-to-action MT from `54632` is received before the end user sends the confirming MO within the 5-minute window.

## What the document leaves implicit

The combined documentation still does **not** explicitly say:
- what the exact API-level trigger is between `/sms/subsms` and the free `54632` call-to-action MT
- whether the third party receives a dedicated "subscription completed" callback
- whether the user's confirming `OK` is forwarded to the third party in addition to being consumed by Lily / Mobivas

Additional clarification provided outside the Word document indicates:
- the **Free Welcome MT** is sent by the **service provider** (not automatically by Lily)
- the recurring or follow-up **Billing MTs** are also handled by the **service provider**

So the safest interpretation is that Lily / Mobivas handle the **subscription initiation and confirmation window**, while the service provider handles the actual post-subscription MT traffic.

## The main ambiguity: `OK` is used in two different ways

The documentation contains a significant internal tension:

- In the subscription section, `OK` is the confirmation keyword that completes the opt-in.
- In the TP-bound MO section, incoming `OK` is listed among stop/unsubscribe-style messages that the third party must process and treat as an unsubscribe trigger when the user is already subscribed.

My best interpretation is now slightly stronger after the Mobivas guide and user clarification:

### Most likely operational behavior

- **During the subscription-confirmation window** created by `/sms/subsms`, Lily / Mobivas treat `OK` to `54632` as a confirmation response for that pending subscription request.
- The user is expected to receive a **FREE call-to-action MT** from `54632` before sending that `OK`.
- **Once the user is successfully subscribed**, the service provider becomes responsible for the **Free Welcome MT** and later **Billing MTs**.
- It is also plausible that the confirming `OK` is still forwarded TP-bound to the service provider, because the API doc says inbound `OK` messages must be processed by the third party, and operationally the user expects Kiwi to see that MO.
- **Outside that pending confirmation context**, an inbound `OK` forwarded TP-bound may be intended as an unsubscribe / termination signal, just like the `STOP` variants.

That interpretation reconciles the API doc, the Mobivas market guide, and the current operational understanding, but the documentation still does not say it in one fully explicit API sentence.

## Recommended implementation stance

Until Lily confirms the exact behavior, the safest approach is:

1. Treat `/sms/subsms` as a **subscription-initiation** call, not immediate subscription creation.
2. Treat the synchronous API response as **request accepted**, not **user subscribed**.
3. Assume the subscription becomes active **only after the user receives the free `54632` call-to-action MT and sends `OK` back within 5 minutes**.
4. After successful confirmation, have the **service provider** send the **Free Welcome MT** and then manage any later **Billing MTs** according to the service program.
5. Use as the current working assumption that the confirming `OK` MO is also delivered TP-bound to Kiwi, and verify this in logs / test traffic rather than treating it as mere speculation.
6. Ask Lily / Mobivas to confirm:
   - whether `OK` in an active pending opt-in window is intercepted by Lily only, or also forwarded TP-bound
   - whether the callback to Kiwi uses the normal MO-posting payload when the user sends the confirming `OK`
   - whether there is any dedicated callback or report for successful subscription activation beyond that MO
   - whether the unsubscribe endpoint expects `Keyword` or `ServiceId`

## Practical flow model for an adapter

If you are implementing this in an integration layer, the state machine would likely look like this:

1. `PENDING_SUBSCRIPTION_REQUEST`
   - created after successful `/sms/subsms` response

2. `CALL2ACTION_MT_SENT`
   - Lily / Mobivas send the free confirmation MT from `54632`

3. `WAITING_FOR_USER_OK`
   - user must reply with `OK` to `54632` within 5 minutes

4. `CONFIRMING_OK_RECEIVED`
   - working assumption: Lily / Mobivas consume the `OK` for subscription completion and Kiwi also receives the same MO TP-bound

5. `SUBSCRIBED`
   - transition when Lily accepts the user's `OK` as confirmation

6. `WELCOME_MT_PENDING`
   - the service provider should send the Free Welcome MT

7. `BILLING_ACTIVE`
   - the service provider sends Billing MTs according to the service rules

8. `EXPIRED_OR_FAILED`
   - transition if no valid `OK` arrives within 5 minutes, or the initiation request was rejected

9. `UNSUBSCRIBED`
   - transition after STOP-like MO handling and/or explicit unsubscription API usage

That is an implementation interpretation, not an explicitly documented Lily state model.

---

# Implementation notes and inconsistencies to confirm with Lily

## 1) MT send endpoint path

Body text says:
```text
/sms/{username}/{password}
```

Screenshot says:
```text
/sms/sendsms/{username}/{password}
```

## 2) Unsubscribe request shape

Body text says:
- `MSISDN`
- `Keyword`

Screenshot shows:
- `MSISDN`
- `ServiceId`

## 3) HLR response wording

Appendix lists textual outcomes such as `OK`, `DATA MISSING`, etc.

Screenshot shows:
```json
"hlrStatus": "SUCCESS"
```

## 4) Meaning of inbound `OK`

The documents use `OK` both:
- as the confirmation keyword for new subscriptions sent to free SC `54632`
- and as a stop-like keyword that should trigger unsubscription handling

Current working assumption:
- the subscription-confirmation `OK` is both used by Lily / Mobivas to finalize the pending subscription and forwarded TP-bound to Kiwi as a normal MO callback

## 5) Subscription completion callback

No explicit success callback for "user is now subscribed" is documented.

---

# Suggested repository usage

Use this file as the general Lily Mobile JSON API reference.

Service- or market-specific details should live in dedicated files for:
- service IDs
- cost IDs
- short codes
- PIN ID mapping
- legal or compliance message text
- callback endpoint routing
- exact subscription state handling once Lily confirms the ambiguities above
