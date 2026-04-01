# NTH FR One-off

This document describes the France-specific NTH Premium SMS setup for the `one-off` flow in this repository.

It complements:
- `../../general-api-premium-sms.md`
- `../../../operations/credentials-and-environments.md`

This file is specific to:
- country: `FR`
- flow: `one-off`
- service type: `Premium SMS`
- service program: `PSMS One-off – WebPayment (MT billing)`

## Source

Primary sources:
- `../../source/PM-CPAPFRSP(5)-V10.0.pdf`
- `../../source/SDF_84072_Jplay.xls`

Use `../../general-api-premium-sms.md` for generic NTH Premium SMS behavior.  
Use this file for the concrete France one-off setup.

## Scope

This setup is for a French one-time payment flow where:
- the user discovers the service on a web page
- the user sends an MO keyword to a short code
- the service provider sends premium MT content back
- NTH forwards MT delivery reports to the service provider

This is **not** a subscription flow.

## Service program summary

The FR service program describes the flow as:

1. web page discovery
2. MO keyword
3. MT content message
4. message delivery report

This means:
- the user starts from a landing page
- the user sends a keyword SMS to the short code
- the premium charge is applied on the outbound MT message
- NTH forwards the delivery report afterwards

## Current setup data

The current SDF provides the following connection and service data.

### Connection data
- country: `France`
- business number: `84072`
- account username: `kiwimob`
- account password: configured, do not store in docs
- MT submission URL: `https://premium.mobile-gw.com:9443`
- encoding: `UTF-8`

### Service data
- country: `France`
- business number: `84072`
- billing type: `MT billing`
- keyword: `Jplay*`
- end user cost: `4.5 EUR`
- tariff factor: `100`
- price parameter: `450`

### Operator / routing data
Use the following `NWC` values for MT submission according to the operator:

- `Bouygues Telecom` → `20820`
- `CORIOLIS TELECOM SAS` → `20827`
- `Free Mobile (Iliad)` → `20815`
- `NRJ` → `20826`
- `Orange` → `20801`
- `SFR (Altice)` → `20810`

## Missing / blank values in current SDF

The current spreadsheet does not provide values for:
- `MO Delivery URL`
- `Notification URL`

Treat these as:
- `not provided in current SDF`

Do not invent or assume endpoint values.

## Billing model

The spreadsheet defines this setup as:
- billing type: `MT billing`

Meaning for implementation:
- the premium charge is performed on the MT message
- the `price` parameter is required for the billed MT submission
- current configured `price` value: `450`

Derived from the setup:
- tariff factor: `100`
- end user cost: `4.5 EUR`
- therefore price parameter: `450`

## Important FR-specific rules

According to the FR service program:

- operators in France do **not** send the real MSISDN
- instead, an **encrypted MSISDN** is forwarded to the service provider
- the encrypted MSISDN is valid for **2 months**
- the user session is valid for **24 hours**

Orange-specific note:
- billing on Orange is performed through **money reservation on the MO message**
- payment is confirmed with the premium MT message
- if no premium MT message is sent within **24 hours**, the money is returned to the end user account

## Click-to-SMS note

The FR one-off flow is a web-initiated MO keyword flow.

The service program does not explicitly use the term `Click2SMS`, but it describes a landing page UX that is clearly compatible with a click-to-SMS implementation:

- the user discovers the service on a web page
- the landing page contains a **clickable button** with a call to action
- the button brings the user to the **messaging** function
- the landing page must also show the **keyword** and **short code**
- the user then sends the MO keyword to the short code

Therefore, for repository documentation, this setup can be described as:

- `web-initiated MO keyword flow`
- `click-to-SMS style one-off flow`

Important distinction:
- documented by the service program: the landing page must drive the user into the messaging action and the user sends the MO keyword to the short code
- not explicitly documented as a formal term: the label `Click2SMS` itself

## Advertising and landing-page rules

The FR service program contains mandatory compliance requirements.

### Price point
The documented one-time service price point is:
- `4,50 €`

There is also a documented limit of:
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

### Landing page requirements
The service program lists detailed landing-page requirements, including:
- commercial name and logo of the service
- short description of the service
- image of what the user will access
- detailed service description, content count / limits / duration
- clickable call-to-action button in the middle of the page
- keyword and short code
- price point plus price of an SMS
- reminder text about service, price, activation, T&C acceptance, customer care, provider details
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
The earlier FR document also specifies these complaint/contact conventions:
- customer care email format: `plainte.XXXXX@allopass.com`
- customer care number: `+33 1 71 25 55 55`

For this setup:
- short code = `84072`
- complaint email pattern:
  - `plainte.84072@allopass.com`

## Content message rules

For this FR one-off setup, the MT content message must include:
- pricing information
- a clear indication that the service is **not a subscription**

The newer FR service program explicitly requires wording equivalent to:
- `"Ceci n'est pas un abonnement"`

Implementation note:
- do not treat this flow as recurring access unless a different FR service program explicitly says so

## Callback endpoints

The current SDF contains two callback-related fields:
- `MO Delivery URL`
- `Notification URL`

Both are currently blank in the uploaded spreadsheet, so no concrete endpoint values are documented yet.

### MO Delivery URL

Purpose:
- endpoint where NTH forwards incoming **MO** messages to our system

Meaning:
- **MO** = Mobile Originated
- this is the message sent by the end user to the business number / short code

In this FR one-off flow, this is the callback path that would receive the user’s keyword SMS after it is forwarded by NTH.

### MO Delivery URL request shape

From the public NTH Premium SMS documentation, the following can be derived reliably for MO forwarding:
- NTH forwards inbound MO messages via the `deliverMessage` callback
- the callback is sent as HTTP `POST`
- parameters are sent in the request body
- content type is `application/x-www-form-urlencoded`
- request data is URL-encoded
- default encoding is UTF-8

For this FR one-off flow, the callback is expected to carry inbound message data relevant for processing the one-off request, for example:
- keyword or message text
- encrypted MSISDN
- short code / business number
- operator-related routing information

The public generic NTH documentation does not expose a complete concrete example payload for `deliverMessage`, and the current FR setup file does not provide the endpoint value. Exact parameter names and sample payload values must therefore be confirmed from:
- live traffic
- additional NTH setup material
- current implementation in the codebase

### Notification URL

Purpose:
- endpoint where NTH sends asynchronous technical notifications to our system

Meaning:
- this is typically used for reports or event-like callbacks rather than the original user message itself

In this FR one-off flow, this most likely includes MT delivery-related notifications such as message delivery reports.

Typical payload relevance:
- MT delivery status
- message/report identifiers
- delivery outcome
- operator/network context

### Repository note

Because both fields are blank in the current SDF:
- do not assume concrete endpoint values
- verify in code or in operational setup where MO callbacks and delivery/report notifications are currently handled
- keep MO message handling separate from technical notification handling where possible

## Step-by-step flow

This section describes the FR one-off flow from both perspectives:
- user interaction
- technical backend / gateway processing

This setup is a **one-off** Premium SMS flow, not a subscription flow.

### 1. Web page discovery

#### User interaction
The user discovers the service on a landing page. The page contains a clickable call-to-action button that brings the user to the messaging function. The landing page also shows the keyword and short code needed for activation.

#### Technical flow
The landing page must include the FR-required compliance elements, including:
- service name and logo
- short description
- service details
- clickable button / call to action
- keyword and short code
- visible pricing
- assistance / customer care details
- SMS logo
- clickable Terms and Conditions

For this setup:
- business number / short code: `84072`
- keyword: `Jplay*`

### 2. MO keyword submission

#### User interaction
The user taps the CTA, is brought into the device messaging flow, and sends an SMS with the keyword to the short code.

#### Technical flow
This is the **MO** message:
- MO = Mobile Originated
- the message is sent by the end user to the short code

NTH / the operator receives the MO and forwards it towards the service provider side. In France, the real MSISDN is not forwarded. Instead, an **encrypted MSISDN** is used. The encrypted MSISDN is valid for 2 months, while the user session is valid for 24 hours.

### 3. MO delivery to our system

#### User interaction
At this point the user has only sent the SMS and is waiting for the service response.

#### Technical flow
NTH forwards the inbound MO to our system via the **MO Delivery URL**.

This callback is expected to carry the inbound message data relevant for processing the one-off request, for example:
- the keyword / message text
- the encrypted MSISDN
- the short code / business number
- operator-related routing information

The exact endpoint value is not present in the current SDF and must be confirmed from live setup or code.

### 4. Internal processing of the MO

#### User interaction
No additional user action is required yet.

#### Technical flow
Our backend processes the inbound MO and determines the corresponding one-off content or access logic.

This FR setup uses:
- billing type: `MT billing`
- end user cost: `4.5 EUR`
- tariff factor: `100`
- price parameter: `450`

This means the premium charge is applied on the outbound MT message, not on the inbound MO itself.

### 5. MT content submission

#### User interaction
The user waits for the reply message containing the content or access information.

#### Technical flow
Our backend sends a premium MT message to NTH using the configured MT submission endpoint.

Current setup:
- MT Submission URL: `https://premium.mobile-gw.com:9443`
- encoding: `UTF-8`

The premium MT message is the billed message in this setup. The service provider sends the content message, for example containing a payment code or access information.

The correct `NWC` value must be used according to the operator.

### 6. Delivery of the MT content message

#### User interaction
The user receives the premium MT message with the service content, code, or access details.

#### Technical flow
NTH forwards the MT message through the operator network to the user handset.

The FR service program requires that the MT content message includes:
- pricing information
- a clear indication that the service is **not a subscription**

### 7. User consumes the purchased content

#### User interaction
The user uses the content received via SMS, for example by entering an access code on the web page.

#### Technical flow
The backend or content platform validates and grants the one-off access according to the service design.

### 8. MT delivery status generation

#### User interaction
Usually no visible action happens for the user here.

#### Technical flow
After the MT has been processed for delivery, a delivery state is generated for that message.

### 9. Delivery report callback

#### User interaction
Usually no visible action happens for the user here.

#### Technical flow
NTH receives the MT message delivery notification and forwards it to the service provider.

This callback is expected to arrive via the **Notification URL**.

The current SDF does not provide a concrete Notification URL value, so the actual endpoint must be confirmed from:
- live configuration
- codebase
- operational setup documentation

### 10. Callback acknowledgement

#### User interaction
No further user interaction is required.

#### Technical flow
Our system acknowledges the callback from NTH with HTTP `200`.

After that, the technical one-off flow is complete.

## FR-specific behavior notes

Important FR-specific details for this flow:
- this is a **one-off** flow, not a subscription flow
- France uses **encrypted MSISDN** instead of real MSISDN in the forwarded MO flow
- encrypted MSISDN validity: **2 months**
- session validity: **24 hours**
- on **Orange**, billing is performed through reservation on the MO and confirmed with the premium MT; if no premium MT is sent within 24 hours, the money is returned to the end user account

## Repository-specific mapping

Use this file for:
- business number / shortcode mapping
- keyword and price setup
- FR-specific operator/NWC values
- FR compliance requirements
- encrypted MSISDN and session constraints
- Orange-specific billing caveat
- click-to-SMS style landing-page behavior

Use `../../general-api-premium-sms.md` for:
- generic NTH Premium SMS concepts
- generic operation meanings
- generic request/response format
- generic report / callback behavior

## Implementation checklist

When implementing or reviewing this FR one-off flow, verify at minimum:
- correct business number: `84072`
- correct keyword handling: `Jplay*`
- correct MT submission URL
- correct UTF-8 encoding
- correct `price` parameter value: `450`
- correct NWC value per operator
- correct handling of encrypted MSISDN
- correct handling of 24h session validity
- correct FR landing-page wording and CTA behavior
- correct FR content-message wording
- correct explicit “not a subscription” wording
- correct delivery-report handling
- correct Orange-specific timing behavior where applicable

## Open questions

Use this section for unresolved setup details.

Currently open based on the uploaded files:
- Is `MO Delivery URL` intentionally handled outside the SDF, or still missing?
- Is `Notification URL` intentionally handled outside the SDF, or still missing?
- Which endpoint in the current codebase receives NTH delivery reports for short code `84072`?
- Which endpoint in the current codebase receives MO callbacks for short code `84072`?
- Is there any additional operator-specific handling beyond the documented Orange reservation rule?