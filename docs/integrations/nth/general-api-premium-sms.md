# NTH General API — Premium SMS

This document summarizes the generic NTH Premium SMS HTTP API for use in this repository.

It is intentionally not country-specific and not flow-specific.  
Use this file for everything that is generally true for NTH Premium SMS integrations across markets and services. Country-, operator-, shortcode-, and service-program-specific behavior belongs in dedicated files such as:
- `fr/<flow>/README.md`
- `at/<flow>/README.md`
- `gr/<flow>/README.md` :contentReference[oaicite:0]{index=0}

## Source

Primary source:
- NTH Developers: Premium SMS  
- Market/service-specific “Service Program” documents provided by NTH during setup

This summary is based on the public NTH Premium SMS developer documentation. NTH explicitly recommends implementing against the generic API together with the relevant Service Program documents for the specific market/service. :contentReference[oaicite:1]{index=1}

## Scope

This file covers:
- terminology
- prerequisites
- generic request/response format
- generic communication model
- available Premium SMS operations
- generic callback/report behavior
- PIN and session concepts

This file does not cover:
- country-specific service rules
- shortcode/operator mappings per market
- concrete prices
- specific retry windows or legal/regulatory wording
- service-program-specific flow rules :contentReference[oaicite:2]{index=2}

## Product overview

NTH Premium SMS HTTP API allows authorized customers (service providers) to exchange messages with the NTH SMS Gateway, send and receive SMS-related traffic, and use additional flow functions such as delivery reports, event notifications, PIN validation, and session control. The API is intended for third-party integrations with NTH. :contentReference[oaicite:3]{index=3}

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
  Supporting service normally required and enabled by default for web-initiated services, used by SMS Gateway to resolve the operator for an MSISDN. :contentReference[oaicite:4]{index=4}

## Prerequisites

According to NTH, a customer needs the following to use Premium SMS HTTP API:

- Customer Account credentials (username and password)
- Service data such as short codes, operator codes, and prices
- SMS Gateway endpoint URL
- Service Program document(s)
- Internet-connected application capable of sending/receiving HTTP requests
- IP whitelist information for servers that will call NTH :contentReference[oaicite:5]{index=5}

## Request format

Premium SMS HTTP API is implemented as a standard HTTP service.

Generic request characteristics:
- operations are sent as HTTP `POST`
- parameters are sent in the request body
- content type should be `application/x-www-form-urlencoded`
- parameter query should be URL-encoded
- default text encoding is UTF-8, though other encodings may be configurable per customer :contentReference[oaicite:6]{index=6}

## Response format

Customer applications send HTTP POST requests and receive XML responses from NTH.

For callbacks from NTH to the customer:
- the customer should respond only with HTTP status `200`
- no response body payload is expected for callback acknowledgements :contentReference[oaicite:7]{index=7}

## Communication model

The generic Premium SMS communication model is based on command-style HTTP operations between the customer application and NTH SMS Gateway.

At a high level:
1. Customer sends an operation request to SMS Gateway.
2. SMS Gateway processes the command.
3. NTH returns an XML response for the request.
4. Depending on the operation and service flow, NTH may later send callbacks such as delivery reports or event notifications to the customer endpoint. :contentReference[oaicite:8]{index=8}

## Functional overview

The public NTH Premium SMS API documentation lists these supported functions and commands:

- MO SMS delivery → `deliverMessage`
- MT SMS submission → `submitMessage`
- MT SMS delivery reports → `deliverReport`
- Event notifications → `deliverEvent`
- PIN validation → `validatePin`
- Session initiation → `initSession`
- Session termination → `closeSession` :contentReference[oaicite:9]{index=9}

---

# Operations

## deliverMessage

Purpose:
- delivery of inbound MO SMS messages from SMS Gateway to the customer application

Generic behavior:
- end user sends an MO SMS to a business number
- mobile operator delivers the MO to SMS Gateway
- SMS Gateway stores and forwards it to the customer application via `deliverMessage`
- the forwarding endpoint is configured during account/service setup :contentReference[oaicite:10]{index=10}

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
- HTTP `200` with XML response means the request was received and processed at protocol level; other HTTP response codes indicate NTH-side processing error and allow retry on the customer side :contentReference[oaicite:11]{index=11}

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
- default delivery-report configuration may be overridden in MT submission request parameters :contentReference[oaicite:12]{index=12}

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

NTH states that these notifications are usually informational, and customer action is typically not required except for special cases documented elsewhere. :contentReference[oaicite:13]{index=13}

Use this operation for:
- audit visibility
- session lifecycle observation
- tracking hidden operator/NTH-side flow transitions

## validatePin

Purpose:
- validate a PIN entered by the end user on a web page

Generic behavior:
- in some markets/operators, services can be initiated on web by entering MSISDN
- NTH or the MNO sends a randomly generated PIN to the user by SMS
- the user enters the PIN on the portal
- customer validates the PIN by sending `validatePin` to SMS Gateway

NTH notes that this can be used for one-time and session-based flows such as subscriptions or chat. :contentReference[oaicite:14]{index=14}

Use this operation for:
- web opt-in flows
- double opt-in confirmation
- PIN/TAN-based service authorization

## initSession

Purpose:
- initiate a session for web-initiated services as an alternative to starting with an initial MT SMS

NTH states:
- the standard method for web-initiated payment flow is to submit an initial MT SMS
- `initSession` is an alternative used in some markets
- usage is described in the relevant Service Program documents and communicated during service setup :contentReference[oaicite:15]{index=15}

Use this operation for:
- market-specific web session activation flows where NTH instructs to use session initialization instead of initial MT

## closeSession

Purpose:
- terminate an existing session-based activation on NTH side

Generic behavior:
- NTH maintains records for each session-based activation
- if the session is discontinued on the customer side, the customer should notify NTH with `closeSession`
- closing the session in SMS Gateway may allow future activation for the same user when an old session record would otherwise block it :contentReference[oaicite:16]{index=16}

Use this operation for:
- subscription/session cleanup
- consistency between customer-side access state and NTH-side session state

---

# Generic flow concepts

## MO-based service usage

A standard Premium SMS usage pattern is:
1. user sends MO SMS to business number
2. operator delivers MO to SMS Gateway
3. SMS Gateway forwards the MO to customer via `deliverMessage` :contentReference[oaicite:17]{index=17}

## MT-based service usage

A standard outbound/billing pattern is:
1. customer sends `submitMessage`
2. SMS Gateway forwards MT to the operator
3. NTH handles billing
4. NTH reports delivery/billing state via `deliverReport` :contentReference[oaicite:18]{index=18}

## Web-initiated services

For web-initiated services:
- Number Lookup is normally required and enabled by default
- the gateway resolves the operator for the submitted MSISDN
- some markets use PIN validation
- some markets may use `initSession` instead of initial MT submission
- the exact details depend on the relevant Service Program document :contentReference[oaicite:19]{index=19}

## Session-based services

For subscription/chat/session flows:
- NTH maintains session records
- events such as activation/closure may be reported via `deliverEvent`
- explicit customer-side session closure should be communicated via `closeSession` when applicable :contentReference[oaicite:20]{index=20}

---

# Delivery reports and events

## Delivery reports

Delivery reports communicate MT message state back to the customer. The report destination and reporting level are configured during setup, and some behavior may be overridden per request. :contentReference[oaicite:21]{index=21}

## Event notifications

Event notifications expose flow events that happen within NTH/operator processing but are not directly visible from the original request path. These are useful for monitoring and state reconciliation. :contentReference[oaicite:22]{index=22}

## Callback acknowledgement rule

For callbacks such as reports/events forwarded to the customer application, the customer should acknowledge successfully with HTTP `200` only, without response payload. :contentReference[oaicite:23]{index=23}

---

# Operational notes

## Timeouts and retries

For `submitMessage`, NTH recommends a request timeout of at least 180 seconds. If the SMS Gateway does not respond within that timeout, the customer should resend the request. Also, any non-200 HTTP status from NTH indicates a processing error on the NTH side and allows retry by the customer. :contentReference[oaicite:24]{index=24}

## Encoding

Default text encoding is UTF-8. Other encodings may be configurable for the customer, but UTF-8 should be treated as the default integration assumption unless setup docs specify otherwise. :contentReference[oaicite:25]{index=25}

## IP whitelisting

NTH requires the customer to provide the IP addresses of servers that will send HTTP requests so access restrictions can be configured. :contentReference[oaicite:26]{index=26}

---

# What belongs in country / flow docs instead

Put the following into files such as `at/<flow>/README.md`, `fr/<flow>/README.md`, etc.:

- short codes
- operator codes
- pricing
- keywords
- legal message wording
- exact opt-in / double opt-in rules
- PIN/TAN usage rules for that market
- whether flow is MO-based, web-based, session-based, or hybrid
- whether Number Lookup is required
- whether `initSession` is used
- callback/event combinations actually expected in that market
- retry and scheduling rules communicated in the Service Program document :contentReference[oaicite:27]{index=27}

# Configuration notes for this repository

This file documents the generic NTH Premium SMS API only.

Repository-specific configuration such as credentials, endpoints, usernames, passwords, and environment wiring should be documented without secret values in:
- `docs/operations/credentials-and-environments.md`

Country-/service-specific credential usage should be referenced from:
- `docs/integrations/nth/<country>/<flow>/README.md`

## Integration guidance for this repository

When implementing or reviewing NTH Premium SMS integrations in this repository:

- keep NTH request/response structures inside the NTH integration layer
- keep generic Premium SMS concepts separate from country/service specifics
- do not assume one market’s Service Program rules apply to another
- treat Service Program documents as required companions to this generic API summary
- document per-market flow decisions in country/flow files, not here :contentReference[oaicite:28]{index=28}

## Current repository relevance

This file is the generic base for NTH Premium SMS integrations.  
Specific implementations such as subscriptions, MO keyword flows, PIN validation flows, or message-report handling should be documented further in country/flow-specific files once the relevant NTH market/service documents are available. :contentReference[oaicite:29]{index=29}