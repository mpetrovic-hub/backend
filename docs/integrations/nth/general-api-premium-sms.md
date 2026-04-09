# NTH General API — Premium SMS

This document summarizes the generic NTH Premium SMS HTTP API for use in this repository.

It is intentionally not country-specific and not flow-specific.  
Use this file for everything that is generally true for NTH Premium SMS integrations across markets and services. Country-, operator-, shortcode-, and service-program-specific behavior belongs in dedicated files such as:
- `fr/<flow>/README.md`
- `at/<flow>/README.md`
- `gr/<flow>/README.md`

## Source

Primary sources:
- NTH Premium SMS developer documentation
- NTH setup / service-program documents provided during onboarding
- Additional clarifications received from NTH regarding webhook payloads and callback usage

This summary is based on the generic NTH Premium SMS HTTP API together with extra callback and request/response examples collected separately. NTH recommends implementing the generic API together with the relevant Service Program document for the specific market and service.

## Scope

This file covers:
- terminology
- prerequisites
- generic request/response format
- generic communication model
- available Premium SMS operations
- generic callback/report behavior
- sample payload shapes confirmed by NTH
- PIN and session concepts

This file does not cover:
- country-specific service rules
- shortcode/operator mappings per market
- concrete prices
- specific retry windows or legal/regulatory wording unless explicitly stated by NTH
- service-program-specific flow rules

## Product overview

NTH Premium SMS HTTP API allows authorized customers (service providers) to exchange messages with the NTH SMS Gateway, send and receive SMS-related traffic, and use additional flow functions such as delivery reports, event notifications, PIN validation, and session control. The API is intended for third-party integrations with NTH.

## Terminology

Important generic terms used by NTH:

- **SMS Gateway**  
  NTH system that interconnects mobile network operators and service providers for SMS delivery over Internet messaging interfaces.

- **End user**  
  Owner of a mobile device and/or consumer of the service.

- **Customer / Service Provider**  
  NTH business partner integrating with SMS Gateway.

- **MNO**  
  Mobile network operator.

- **Service program**  
  NTH document describing the exact service flow and rules for a specific market and setup.

- **MO**  
  Mobile Originated message, sent from the end user device.

- **MT**  
  Mobile Terminated message, sent to the end user device.

- **Customer Account**  
  Main administrative/authentication element used to access SMS Gateway.

- **Opt-in**  
  Explicit user request to consume a premium mobile service.

- **Web initiated services**  
  Services where the opt-in starts on a web page.

- **Number Lookup service**  
  Supporting service normally required and enabled by default for web-initiated services, used by SMS Gateway to resolve the operator for an MSISDN.

## Prerequisites

According to NTH, a customer needs the following to use the Premium SMS HTTP API:

- Customer Account credentials (`username` and `password`)
- Service data such as short codes, operator codes, and prices
- SMS Gateway endpoint URL
- Service Program document(s)
- Internet-connected application capable of sending and receiving HTTP requests
- IP whitelist information for servers that will call NTH

## Transport and payload format

Premium SMS HTTP API is implemented as a standard HTTP service.

Generic request characteristics:
- operations are sent as HTTP `POST`
- parameters are sent in the request body
- content type should be `application/x-www-form-urlencoded`
- the parameter string should be URL-encoded
- default text encoding is UTF-8, though other encodings may be configurable per customer

Generic response characteristics:
- synchronous responses from NTH are XML
- customer applications send HTTP POST requests and receive XML responses from NTH
- callbacks from NTH to the customer are also HTTP `POST` with `application/x-www-form-urlencoded`

For callbacks from NTH to the customer:
- the customer should acknowledge successfully with HTTP status `200`
- no response body payload is expected for callback acknowledgements

## Communication model

The generic Premium SMS communication model is based on command-style HTTP operations between the customer application and NTH SMS Gateway.

At a high level:
1. Customer sends an operation request to SMS Gateway.
2. SMS Gateway processes the command.
3. NTH returns an XML response for the request.
4. Depending on the operation and service flow, NTH may later send callbacks such as delivery reports or event notifications to the customer endpoint.

## Functional overview

The public NTH Premium SMS API documentation lists these supported functions and commands:

- MO SMS delivery → `deliverMessage`
- MT SMS submission → `submitMessage`
- MT SMS delivery reports → `deliverReport`
- Event notifications → `deliverEvent`
- PIN validation → `validatePin`
- Session initiation → `initSession`
- Session termination → `closeSession`

---

# Operations

## deliverMessage

Purpose:
- delivery of inbound MO SMS messages from SMS Gateway to the customer application

Generic behavior:
- end user sends an MO SMS to a business number
- mobile operator delivers the MO to SMS Gateway
- SMS Gateway stores and forwards it to the customer application via `deliverMessage`
- the forwarding endpoint is configured during account or service setup

Confirmed webhook transport:
- HTTP method: `POST`
- content type: `application/x-www-form-urlencoded`

Confirmed sample callback body:
```text
command=deliverMessage&messageId=12345&msisdn=00414411112222&businessNumber=9292&keyword=START&content=START+sms+service&operatorCode=22801&sessionId=9292CHA1571000000000&time=2021-01-01+12%3A00%3A00
```

Observed generic parameters in the MO delivery callback:
- `command=deliverMessage`
- `messageId`
- `msisdn`
- `businessNumber`
- `keyword`
- `content`
- `operatorCode`
- `sessionId`
- `time`

NTH clarified that the same parameter set is typically sent, while the values vary by market and service.

Use this operation for:
- keyword-based inbound flows
- premium SMS opt-in flows that begin with MO traffic
- inbound user interaction processing

## submitMessage

Purpose:
- submit an outbound MT SMS from the customer application to NTH SMS Gateway

Generic behavior:
- customer sends `submitMessage`
- SMS Gateway stores the message and forwards it to the operator’s SMSC
- NTH handles the billing of the message according to the price specified in the request
- NTH recommends a TCP/IP socket/read timeout of at least 180 seconds for this request
- if SMS Gateway does not respond within timeout, customer should resend the HTTP request
- HTTP `200` with XML response means the request was received and processed at protocol level; other HTTP response codes indicate NTH-side processing error and allow retry on the customer side

Confirmed request characteristics:
- HTTP method: `POST`
- content type: `application/x-www-form-urlencoded`
- by default, NTH assumes the submitted MT is a text message

Sample request body:
```text
command=submitMessage&username=user1&password=pass1&msisdn=00414411112222&businessNumber=9292&content=MT+message+text&price=100&sessionId=9292CHA1571000000000
```

Sample XML response:
```xml
<res>
    <resultCode>100</resultCode>
    <resultText>OK</resultText>
    <messageId>12345</messageId>
    <messageRef>CUST_REF_12345</messageRef>
    <sessionId>9292CHA1571000000000</sessionId>
    <operatorCode>22801</operatorCode>
</res>
```

Observed response fields for successful MT submission:
- `resultCode`
- `resultText`
- `messageId`
- `messageRef`
- `sessionId`
- `operatorCode`

Use this operation for:
- MT charging
- outbound premium SMS delivery
- initiating standard SMS-based service flows

## deliverReport

Purpose:
- delivery and billing state reporting for MT messages back to the customer

Generic behavior:
- NTH sends delivery reports about MT messages via `deliverReport`
- during account setup the customer provides the default report endpoint
- the customer also defines which report levels should be sent
- documented report levels include:
  - delivered
  - delivery failed
  - intermediate
- default delivery-report configuration may be overridden in MT submission request parameters

Confirmed webhook transport:
- HTTP method: `POST`
- content type: `application/x-www-form-urlencoded`

Confirmed sample callback body:
```text
command=deliverReport&messageId=12345&messageRef=CUST_REF_12345&msisdn=00414411112222&businessNumber=9292&messageStatus=2&messageStatusText=Delivery+successful&time=2021-01-01+12%3A00%3A00&sessionId=9292CHA1571000000000
```

Observed generic parameters in the delivery report callback:
- `command=deliverReport`
- `messageId`
- `messageRef`
- `msisdn`
- `businessNumber`
- `messageStatus`
- `messageStatusText`
- `time`
- `sessionId`

NTH explicitly described the Notification URL as the endpoint used to receive delivery reports for outbound MT messages whenever the MT status changes.

Use this operation for:
- delivery monitoring
- billing outcome tracking
- customer-side MT status synchronization

## deliverEvent

Purpose:
- notify the customer about service-flow events that are not otherwise directly visible

Examples listed by NTH include:
- NTH sends opt-in confirmation request to the end user
- end user confirms the opt-in confirmation request
- session is activated
- session is closed

NTH states that these notifications are usually informational, and customer action is typically not required except for special cases documented elsewhere.

Confirmed sample callback body:
```text
command=deliverEvent&event=optin_msg_sent&msisdn=00414411112222&businessNumber=9292&operatorCode=22801&keyword=GAME&sessionId=9292CHA1571000000000&time=2021-01-01+12%3A00%3A00&price=0&content=Reply+with+YES+to+confirm+purchase&messageId=12345
```

Observed generic parameters in the event callback sample:
- `command=deliverEvent`
- `event`
- `msisdn`
- `businessNumber`
- `operatorCode`
- `keyword`
- `sessionId`
- `time`
- `price`
- `content`
- `messageId`

Use this operation for:
- audit visibility
- session lifecycle observation
- tracking hidden operator or NTH-side flow transitions

## validatePin

Purpose:
- validate a PIN entered by the end user on a web page

Generic behavior:
- in some markets and operators, services can be initiated on web by entering MSISDN
- NTH or the MNO sends a randomly generated PIN to the user by SMS
- the user enters the PIN on the portal
- customer validates the PIN by sending `validatePin` to SMS Gateway

NTH notes that this can be used for one-time and session-based flows such as subscriptions or chat.

Sample request body:
```text
command=validatePin&username=user1&password=pass1&sessionId=9292CHA1571000000000&pin=1234
```

Sample XML response:
```xml
<res>
    <resultCode>100</resultCode>
    <resultText>OK</resultText>
    <sessionId>9292CHA1571000000000</sessionId>
</res>
```

Another documented XML response example includes `sessionState`:
```xml
<res>
    <resultCode>100</resultCode>
    <resultText>OK</resultText>
    <sessionId>9292CHA1571000000000</sessionId>
    <sessionState>OPENING</sessionState>
</res>
```

Use this operation for:
- web opt-in flows
- double opt-in confirmation
- PIN or TAN based service authorization

## initSession

Purpose:
- initiate a session for web-initiated services as an alternative to starting with an initial MT SMS

NTH states:
- the standard method for web-initiated payment flow is to submit an initial MT SMS
- `initSession` is an alternative used in some markets
- usage is described in the relevant Service Program documents and communicated during service setup

Sample request body:
```text
command=initSession&username=user1&password=pass1&msisdn=00414411112222&businessNumber=9292&keyword=GAME
```

Sample XML response:
```xml
<res>
    <resultCode>100</resultCode>
    <resultText>OK</resultText>
    <sessionId>9292CHA1571000000000</sessionId>
    <sessionState>OPENING</sessionState>
</res>
```

Use this operation for:
- market-specific web session activation flows where NTH instructs to use session initialization instead of an initial MT

## closeSession

Purpose:
- terminate an existing session-based activation on NTH side

Generic behavior:
- NTH maintains records for each session-based activation
- if the session is discontinued on the customer side, the customer should notify NTH with `closeSession`
- closing the session in SMS Gateway may allow future activation for the same user when an old session record would otherwise block it

Sample request body:
```text
command=closeSession&username=user1&password=pass1&sessionId=9292CHA1571000000000
```

Sample XML response:
```xml
<res>
    <resultCode>100</resultCode>
    <resultText>OK</resultText>
    <sessionId>9292CHA1571000000000</sessionId>
    <sessionState>CLOSING</sessionState>
</res>
```

Use this operation for:
- subscription or session cleanup
- consistency between customer-side access state and NTH-side session state

---



# Generic flow concepts

## MO-based service usage

A standard Premium SMS usage pattern is:
1. user sends MO SMS to business number
2. operator delivers MO to SMS Gateway
3. SMS Gateway forwards the MO to customer via `deliverMessage`

## MT-based service usage

A standard outbound and billing pattern is:
1. customer sends `submitMessage`
2. SMS Gateway forwards MT to the operator
3. NTH handles billing
4. NTH reports delivery or billing state via `deliverReport`

## Web-initiated services

For web-initiated services:
- Number Lookup is normally required and enabled by default
- the gateway resolves the operator for the submitted MSISDN
- some markets use PIN validation
- some markets may use `initSession` instead of initial MT submission
- the exact details depend on the relevant Service Program document

## Session-based services

For subscription, chat, or session flows:
- NTH maintains session records
- events such as activation or closure may be reported via `deliverEvent`
- explicit customer-side session closure should be communicated via `closeSession` when applicable

---

# Callback payload summary

## MO delivery callback (`deliverMessage`)

```text
command=deliverMessage&messageId=12345&msisdn=00414411112222&businessNumber=9292&keyword=START&content=START+sms+service&operatorCode=22801&sessionId=9292CHA1571000000000&time=2021-01-01+12%3A00%3A00
```

## MT delivery report callback (`deliverReport`)

```text
command=deliverReport&messageId=12345&messageRef=CUST_REF_12345&msisdn=00414411112222&businessNumber=9292&messageStatus=2&messageStatusText=Delivery+successful&time=2021-01-01+12%3A00%3A00&sessionId=9292CHA1571000000000
```

## Event callback (`deliverEvent`)

```text
command=deliverEvent&event=optin_msg_sent&msisdn=00414411112222&businessNumber=9292&operatorCode=22801&keyword=GAME&sessionId=9292CHA1571000000000&time=2021-01-01+12%3A00%3A00&price=0&content=Reply+with+YES+to+confirm+purchase&messageId=12345
```

## Callback acknowledgement rule

For callbacks such as reports and events forwarded to the customer application, the customer should acknowledge successfully with HTTP `200` only, without a response payload.

---

# Service-specific clarification from NTH

For one specific one-time service setup described in the supplemental notes:
- only `deliverMessage` is expected for MO messages
- only `deliverReport` is expected for MT messages
- no other callbacks are expected for that setup

Treat this as service-specific guidance, not as a universal rule for all NTH Premium SMS integrations. Other services or markets may still use `deliverEvent`, PIN validation, or session operations.

---

# Operational notes

## Timeouts and retries

For `submitMessage`, NTH recommends a request timeout of at least 180 seconds. If the SMS Gateway does not respond within that timeout, the customer should resend the request. Also, any non-200 HTTP status from NTH indicates a processing error on the NTH side and allows retry by the customer.

## Encoding

Default text encoding is UTF-8. Other encodings may be configurable for the customer, but UTF-8 should be treated as the default integration assumption unless setup documents specify otherwise.

## IP whitelisting

NTH requires the customer to provide the IP addresses of servers that will send HTTP requests so access restrictions can be configured.

---

# What belongs in country / flow docs instead

Put the following into files such as `at/<flow>/README.md`, `fr/<flow>/README.md`, etc.:

- short codes
- operator codes
- pricing
- keywords
- legal message wording
- exact opt-in or double opt-in rules
- PIN or TAN usage rules for that market
- whether the flow is MO-based, web-based, session-based, or hybrid
- whether Number Lookup is required
- whether `initSession` is used
- callback and event combinations actually expected in that market
- retry and scheduling rules communicated in the Service Program document

# Configuration notes for this repository

This file documents the generic NTH Premium SMS API only.

Repository-specific configuration such as credentials, endpoints, usernames, passwords, and environment wiring should be documented without secret values in:
- `docs/operations/credentials-and-environments.md`

### Current callback endpoint model in this repository

NTH callbacks are handled through a single aggregator endpoint:

- `POST /wp-json/kiwi-backend/v1/nth-callback`

Dispatch behavior:
- payloads with `command=deliverMessage` are treated as MO callbacks
- payloads with `command=deliverReport` are treated as notification callbacks

Service resolution behavior:
- `service_key` request parameter is accepted when provided
- otherwise, service resolution is attempted from callback payload data (shortcode/business number plus keyword) against configured `KIWI_NTH_SERVICES`

Legacy per-service callback routes are no longer used for NTH.

Country- and service-specific credential usage should be referenced from:
- `docs/integrations/nth/<country>/<flow>/README.md`

## Integration guidance for this repository

When implementing or reviewing NTH Premium SMS integrations in this repository:

- keep NTH request and response structures inside the NTH integration layer
- keep generic Premium SMS concepts separate from country or service specifics
- do not assume one market’s Service Program rules apply to another
- treat Service Program documents as required companions to this generic API summary
- document per-market flow decisions in country or flow files, not here

## Current repository relevance

This file is the generic base for NTH Premium SMS integrations.  
Specific implementations such as subscriptions, MO keyword flows, PIN validation flows, or message-report handling should be documented further in country- and flow-specific files once the relevant NTH market and service documents are available.
