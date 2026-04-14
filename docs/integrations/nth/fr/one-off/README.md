# NTH FR One-off

## Purpose

This document is the canonical repository reference for the **France (`FR`) NTH Premium SMS one-off flow**.

It defines the concrete France-specific setup for:

- provider: `NTH`
- country: `FR`
- flow: `one-off`
- service type: `Premium SMS`
- service program: `PSMS One-off – WebPayment (MT billing)`

Use this file to understand the **FR-specific integration behavior, routing, compliance requirements, callback expectations, and unresolved setup items** for this flow.

---

## Scope and Ownership

This document **owns** the following for the FR one-off flow:

- concrete FR service configuration
- shortcode / business number mapping
- keyword and price setup
- operator / NWC mappings
- MT billing behavior
- encrypted MSISDN and session constraints
- Orange-specific billing behavior
- landing-page compliance requirements that are specific to this FR service program
- callback scope and unresolved callback endpoint questions

This document does **not** own:

- generic NTH API behavior that applies across markets
- HTML / CSS implementation of individual landing pages
- credentials or secrets
- deployment procedures
- rollout / release operations

Use the following related documents for those areas:

- `../../general-api-premium-sms.md` for generic NTH Premium SMS behavior
- `../../../operations/credentials-and-environments.md` for credentials and environment handling
- `/architecture/landing-page-system.md` for landing-page architecture and folder conventions

---

## Source References

Primary source documents:

- `../../source/PM-CPAPFRSP(5)-V10.0.pdf`
- `../../source/SDF_84072_Jplay.xls`

Additional setup clarification:

- direct feedback from NTH regarding `MO Delivery URL` and `Notification URL`

When generic NTH behavior and FR-specific rules overlap, treat this file as the source of truth for **FR one-off specifics**, and use `../../general-api-premium-sms.md` for the generic behavior around them.

---

## Canonical Configuration

### Service identity

- provider: `NTH`
- country: `FR`
- flow: `one-off`
- service type: `Premium SMS`
- service program: `PSMS One-off – WebPayment (MT billing)`

### Core service configuration

- business number / shortcode: `84072`
- keyword: `Jplay*`
- billing type: `MT billing`
- end user cost: `4.5 EUR`
- tariff factor: `100`
- `price` parameter: `450`
- MT submission URL: `https://premium.mobile-gw.com:9443`
- encoding: `UTF-8`

### Connection and credential notes

- country: `France`
- business number: `84072`
- account username: `kiwimob`
- account password: configured, do not store in docs

Credentials must be managed outside this file. Do not add passwords, tokens, or secrets here.

### Callback documentation status

The source spreadsheet includes these callback-related fields:

- `MO Delivery URL`
- `Notification URL`

Both values are blank in the spreadsheet source, but this repository now has an implemented NTH callback endpoint:

- `POST /wp-json/kiwi-backend/v1/nth-callback`

Both callback commands for this FR one-off flow are expected on that endpoint:

- `deliverMessage` (MO callback)
- `deliverReport` (MT delivery report callback)

---

## Service Model Summary

This setup is a **one-off** Premium SMS flow. It is **not a subscription flow**.

At a high level:

1. the user discovers the service on a landing page
2. the user sends an MO keyword to the shortcode
3. our system sends a premium MT content message
4. NTH sends an MT delivery report back to our system

Important implementation boundary:

- the premium charge is applied on the **outbound MT**
- the inbound MO starts the flow, but is not itself the billed message in this setup

---

## Operator / NWC Mapping

Use the following `NWC` values for MT submission according to the operator:

- `Bouygues Telecom` → `20820`
- `CORIOLIS TELECOM SAS` → `20827`
- `Free Mobile (Iliad)` → `20815`
- `NRJ` → `20826`
- `Orange` → `20801`
- `SFR (Altice)` → `20810`

These mappings are required for correct operator routing in this FR service.

---

## Billing Rules

The configured billing model is:

- billing type: `MT billing`

Meaning for implementation:

- the premium charge is performed on the outbound MT message
- the `price` parameter is required for the billed MT submission
- configured `price` value: `450`

Derived billing values:

- tariff factor: `100`
- end user cost: `4.5 EUR`
- therefore `price` parameter: `450`

### Mandatory content-message rule

For this FR one-off setup, the MT content message must:

- include pricing information
- clearly indicate that the service is **not a subscription**

The service program requires wording equivalent to:

- `Ceci n'est pas un abonnement`

Implementation rule:

- do not treat this flow as recurring access unless a different FR service program explicitly requires it

Current repository default MT text (when `mt_message_template` is not configured in `KIWI_NTH_SERVICES`):

- `MyJoyplay kiwi mobile GmbH 4,5€ + prix SMS(ce n'est pas un abonnement) https://mcontentfr.joy-play.com Problème? plainte.<shortcode>@allopass.com`

---

## FR-Specific Technical Rules

The France service program defines the following important technical constraints:

- operators in France do **not** forward the real MSISDN
- an **encrypted MSISDN** is forwarded instead
- encrypted MSISDN validity: **2 months**
- user session validity: **24 hours**

These rules affect at minimum:

- MO callback parsing
- user/session correlation
- one-off purchase tracking
- any logic that expects a stable user identifier

### Orange-specific rule

Orange has a market-specific billing caveat:

- billing uses **money reservation on the MO message**
- payment is confirmed only with the premium MT message
- if no premium MT is sent within **24 hours**, the money is returned to the end user account

Implementation note:

- do not model Orange behavior as a standard immediate confirmed charge on MO
- take extra care with MT timing and delivery handling for Orange traffic

---

## Landing-Page Compliance Requirements

This flow is web-initiated and depends on a compliant landing page.

### Flow characterization

The FR one-off setup can be described as:

- `web-initiated MO keyword flow`
- `click-to-SMS style one-off flow`

Important distinction:

- the service program describes a landing page that drives the user into the device messaging action
- the program does **not** explicitly require the formal label `Click2SMS`

### Price point

The documented one-time service price point is:

- `4,50 €`

The service program also documents a limit of:

- `50€` total daily per user per short code on premium services

### Advertising requirements

The web page must provide:

- service name and short description
- service price in the exact FR format
- customer care hotline and email in French
- clearly visible pricing information

Required price wording examples:

- `[price incl. VAT] EUROS par SMS + prix d’un SMS`
- `[price incl. VAT] € par SMS + prix d’un SMS`

### Landing-page content requirements

The service program lists detailed landing-page requirements, including:

- commercial name and logo of the service
- short description of the service
- image of what the user will access
- detailed service description, content count / limits / duration
- clickable call-to-action button in the middle of the page
- keyword and shortcode
- price point plus price of an SMS
- reminder text about service, price, activation, T&C acceptance, customer care, and provider details
- SMS logo
- clickable Terms and Conditions

Approved button wording examples include:

- `VALIDER LE PAIEMENT`
- `CONFIRMER LE PAIEMENT`
- `ACHETER`
- `PAYER`
- `CONTINUER ET PAYER`
- `CONTINUER ET ACHETER`

### Customer care details

The earlier FR documentation specifies these complaint/contact conventions:

- customer care email format: `plainte.XXXXX@allopass.com`
- customer care number: `+33 1 71 25 55 55`

For this setup:

- shortcode: `84072`
- complaint email pattern: `plainte.84072@allopass.com`

---

## Relationship to Landing Pages

This integration document may be referenced by multiple landing-page folders.

Examples:

- `/landing-pages/lp2-fr/integration.php`
- `/landing-pages/lp5-fr/integration.php`

Both may reference this file through the landing-page metadata, for example:

```php
<?php

return [
    'key' => 'lp2-fr',
    'country' => 'FR',
    'flow' => 'nth-fr-one-off',
    'provider' => 'nth',
    'business_number' => '84072',
    'keyword' => 'Jplay*',
    'documentation' => '/integrations/nth-fr-one-off.md',
];
```

Rules:

- this file defines the **integration behavior**
- landing-page folders define the **HTML, CSS, and local metadata**
- do not duplicate landing-page markup or page-specific styling into this document
- do not duplicate generic NTH API behavior here when it already exists in the general NTH API documentation

---

## Callback Contract for This Flow

This repository handles both callback commands for this FR setup on one endpoint:

- `POST /wp-json/kiwi-backend/v1/nth-callback`

### MO callback (`deliverMessage`)

Purpose:
- NTH forwards inbound **MO** traffic to our system.

Transport:
- HTTP method: `POST`
- content type: `application/x-www-form-urlencoded`

Sample payload shape:
```x-www-form-urlencoded
command=deliverMessage&messageId=12345&msisdn=00414411112222&businessNumber=9292&keyword=START&content=START+sms+service&operatorCode=22801&sessionId=9292CHA1571000000000&time=2021-01-01+12%3A00%3A00
```

For this flow, NTH accepts keyword suffixes (configured as `Jplay*`), for example:

```text
JPLAY txn_abcd1234
```

The callback parser accepts both whitespace and `+` separators in MO content when extracting internal transaction identifiers (for example `JPLAY+txn_abcd1234`).

The full MO content is forwarded to backend callback handling and can be used to recover internal correlation identifiers.

FR-specific note:
- for France, `msisdn` must be interpreted according to FR service rules
- the forwarded user identifier is an **encrypted MSISDN**, not the real MSISDN

### Notification callback (`deliverReport`)

Purpose:
- NTH forwards MT delivery reports to our system.

Transport:
- HTTP method: `POST`
- content type: `application/x-www-form-urlencoded`

Sample payload shape:
```x-www-form-urlencoded
command=deliverReport&messageId=12345&messageRef=CUST_REF_12345&msisdn=00414411112222&businessNumber=9292&messageStatus=2&messageStatusText=Delivery+successful&time=2021-01-01+12%3A00%3A00&sessionId=9292CHA1571000000000
```

### Callback scope

NTH explicitly clarified that for this **one-time** service setup:

- `deliverMessage` is sent for MO messages
- `deliverReport` is sent for MT delivery reports
- no other callbacks are expected for this setup

Therefore:

- do not implement `deliverEvent` handling for this FR one-off flow unless later setup documentation explicitly requires it
- do not assume PIN/session callback flows for this service

### Attribution and affiliate postback boundary

For this setup, callback payload parsing and callback-type resolution stay in the NTH integration layer.

Outbound affiliate postbacks are handled by shared attribution capability after conversion normalization:

- callbacks are normalized to internal conversion semantics first
- only confirmed successful terminal conversions are eligible for postback dispatch
- attribution rows carry an internal server-generated `transaction_id` captured at landing entry
- NTH outbound `reference` values are derived from that `transaction_id` (with a uniqueness suffix) when a pending attribution row is found
- if no transaction id can be resolved from attribution or MO content, the fallback NTH flow reference still uses a generated `txn_...` root (instead of provider-only prefixes) to keep shared sales correlation consistent
- successful one-off sales persist this correlation root into `wp_kiwi_sales.transaction_id`
- resolver correlation uses normalized stable references (`flow_reference`, message/reference IDs, session/external refs)
- duplicate callback deliveries must not emit duplicate postbacks once a postback is marked sent

Important:

- NTH callbacks are not assumed to include a generic affiliate shared secret
- postback signing secrets apply to outgoing affiliate postbacks only

### Repository rule for unresolved callback URLs

The source spreadsheet callback URL fields are blank, but the repository callback endpoint is now known and implemented:

- `POST /wp-json/kiwi-backend/v1/nth-callback`

---

## Runtime Flow Summary

This section describes the end-to-end FR one-off flow from a repository implementation perspective.

### 1. Landing-page discovery and CTA

The user discovers the service on a landing page.

The page must include the FR-required compliance elements, including:

- service name and logo
- short description
- service details
- clickable CTA
- keyword and shortcode
- visible pricing
- assistance / customer care details
- SMS logo
- clickable Terms and Conditions

For this setup:

- shortcode: `84072`
- keyword: `Jplay*`

### 2. MO keyword submission

The user taps the CTA, opens the device messaging function, and sends the keyword SMS to the shortcode.

This is the **MO** message:

- MO = Mobile Originated
- the message is sent by the end user to the shortcode

In France, the forwarded user identifier is an **encrypted MSISDN**, not the real MSISDN.

### 3. MO callback to our system

NTH forwards the inbound MO to our system through:

- `POST /wp-json/kiwi-backend/v1/nth-callback`

using the `deliverMessage` callback command.

Implementation focus points:

- encrypted MSISDN handling
- keyword parsing
- shortcode validation
- operator data handling
- session tracking within the 24-hour validity window

The callback endpoint is shared with delivery reports and dispatches by callback command.

### 4. Internal processing

Our backend processes the inbound MO and determines the corresponding one-off content or access logic.

This setup uses:

- billing type: `MT billing`
- end user cost: `4.5 EUR`
- tariff factor: `100`
- `price` parameter: `450`

This means the premium charge is applied on the outbound MT message.

### 5. MT content submission

Our backend sends the premium MT message to NTH using:

- MT submission URL: `https://premium.mobile-gw.com:9443`
- encoding: `UTF-8`

The correct `NWC` value must be selected according to the operator.

### 6. MT content delivery

The user receives the premium MT content message.

The FR service program requires that the MT content message includes:

- pricing information
- explicit wording that the service is **not a subscription**

### 7. Delivery report callback

NTH forwards the MT delivery report to our system through:

- `POST /wp-json/kiwi-backend/v1/nth-callback`

using the `deliverReport` callback command.

This callback should be used to:

- reconcile outbound MT submissions
- persist final or intermediate delivery state
- link report data to the original one-off message or purchase attempt

The endpoint is shared with MO callbacks and dispatches by callback command.

### 8. Callback acknowledgement

Our system acknowledges the callback from NTH with HTTP `200`.

At that point, the technical one-off flow is complete.

---

## Repository-Specific Mapping

Use this file for:

- business number / shortcode mapping
- keyword and price setup
- FR-specific operator / NWC values
- FR compliance requirements
- encrypted MSISDN and session constraints
- Orange-specific billing caveat
- click-to-SMS style landing-page behavior
- FR-specific callback expectations for the one-off service

Use `../../general-api-premium-sms.md` for:

- generic NTH Premium SMS concepts
- generic operation meanings
- generic request / response format
- generic callback / report behavior
- generic request / response examples for operations outside this flow

---

## Implementation Checklist

When implementing or reviewing this FR one-off flow, verify at minimum:

- correct business number / shortcode: `84072`
- correct keyword handling: `Jplay*`
- correct MT submission URL
- correct UTF-8 encoding
- correct `price` parameter value: `450`
- correct NWC value per operator
- correct handling of encrypted MSISDN
- correct handling of 24-hour session validity
- correct FR landing-page wording and CTA behavior
- correct FR content-message wording
- correct explicit “not a subscription” wording
- correct `deliverMessage` callback parsing
- correct `deliverReport` callback parsing
- correct delivery-report handling
- correct Orange-specific timing behavior where applicable

---

## Open Questions

Use this section for unresolved setup details.

Currently open based on the source material:

- Confirm NTH has configured both callback commands (`deliverMessage`, `deliverReport`) to the repository endpoint: `/wp-json/kiwi-backend/v1/nth-callback`.
- Is there any additional operator-specific handling beyond the documented Orange reservation rule?

---

## Summary

This file defines the repository reference for the **NTH France one-off Premium SMS setup**.

The key implementation facts are:

- shortcode: `84072`
- keyword: `Jplay*`
- billing type: `MT billing`
- end user cost: `4.5 EUR`
- `price` parameter: `450`
- MT submission URL: `https://premium.mobile-gw.com:9443`
- encrypted MSISDN is used instead of the real MSISDN
- encrypted MSISDN validity: `2 months`
- session validity: `24 hours`
- Orange uses MO-side reservation and confirms billing with premium MT
- the MT content message must clearly state that the service is **not a subscription**
- callback endpoint URLs remain unresolved unless confirmed elsewhere
