# Dimoco AT Subscription

This document describes the Austria-specific Dimoco subscription integration used in this repository.

It complements:
- `../../general-api.md`
- `../../../operations/credentials-and-environments.md`

This file is specific to:
- country: `AT`
- service type: `Subscription`
- flow types: `CLICK` and `WEB TAN`

## Source

Primary source:
- `../../source/2025 AT kiwi mobile StarBabes_115512_&_Getstronger_115510 integration guide.pdf`

Use `../../general-api.md` for generic Dimoco pay:smart behavior.  
Use this file for Austria-specific setup and flow behavior.

## Scope

This setup describes an Austria subscription integration where:
- the MNO hosts the payment pages
- the MNO handles TAN sending and verification
- the initial payment is charged immediately
- content is delivered after successful payment

Supported flow variants:
- `CLICK`
- `WEB TAN`

## Services covered

This document currently covers the following AT services:

- `GETSTRONGER`
  - order: `115510`
  - tariff: `4.50 EUR`
  - periodicity: `1 per week`

- `STAR.BABES2GO`
  - order: `115512`
  - tariff: `4.90 EUR`
  - periodicity: `1 per week`

## Credentials and configuration

Dimoco credentials for this AT setup include:

- merchant: `200309`
- orders:
  - `115510` for `GETSTRONGER`
  - `115512` for `STAR.BABES2GO`

The shared secret / password exists for this setup but must not be stored in this documentation.

In this repository, actual configuration values are managed in:
- `wp-config.php`

Configuration access in code is handled through:
- `class-config.php`

## General setup

- payment API: `pay:smart`
- service type: `Subscription`
- pay:periodic: enabled
- renewals: triggered by DIMOCO
- service number: `0800 100 336`

## Hosting responsibilities

### Pages hosted by MNO
- click payment page
- TAN entry page

### Pages hosted by integrator / merchant
- landing-page or MSISDN entry page
- outcome page / content page

### Messages hosted by MNO / DIMOCO
- TAN MT: MNO
- Welcome MT: MNO
- FAGG MT: DIMOCO
- Stop confirmation MT: DIMOCO

## Server-to-server callback setup

This setup expects server-to-server callbacks for subscription lifecycle events.

### Close notification URL
Used for Dimoco `close-subscription` notifications.

Can be provided:
- as a static endpoint
- or dynamically via `close_notification_url_callback` during `start-subscription`

### Renewal notification URL
Used for Dimoco `renew-subscription` notifications when renewals are handled by DIMOCO. Note: in our case renewals in Austria are handled by Dimoco

Can be provided:
- as a static endpoint
- or dynamically via `manage_subscription_url_callback` during `start-subscription`

## Static message texts

The source guide documents these DIMOCO-managed message templates:

### FAGG MT
`Vielen Dank für die Anmeldung zu {service_name} für {amout} EUR/{frequency}. Rechtliche Infos: {contract_confirmation_url} - Zugang zum Dienst: {content_access_url} Hilfe: {hotline}`

### Stop confirmation MT
`Der Dienst von {merchant_name} - {service_name} wurde beendet.`

## Flow overview

This AT setup uses two subscription entry variants:

### 1. CLICK flow
Use when the user is browsing on a mobile device via mobile web.

Characteristics:
- user is typically identified first
- Dimoco returns `channel = wap`
- user confirms on the MNO-hosted payment page
- payment is executed immediately
- subscription result is returned via callback

### 2. WEB TAN flow
Use when the user is on Wi-Fi or desktop.

Characteristics:
- user enters MSISDN on integrator page
- operator is identified
- user is redirected to the MNO flow
- MNO sends TAN to the user
- user enters TAN on the MNO page
- payment is executed immediately
- subscription result is returned via callback

## Identification flow

The AT guide documents an identify-based flow used to decide whether the user can continue in CLICK or WEB TAN mode.

### Minimum request
- `action = identify`
- `merchant`
- `order`
- `digest`
- `request_id`
- `url_callback`
- `url_return`
- `service_name`

### Expected behavior
1. Integrator calls Dimoco.
2. Dimoco responds synchronously with `status = 3` and a redirect URL.
3. Integrator redirects the user.
4. Dimoco performs identification.
5. Dimoco sends an asynchronous callback to `url_callback`.
6. Integrator responds with HTTP `200`.
7. Dimoco redirects the user to `url_return`.
8. Result routing:
   - `channel = wap` → continue with CLICK subscription flow
   - `channel = web` → continue with WEB TAN subscription flow

## Operator lookup

The AT guide documents both asynchronous and synchronous operator lookup variants.

### Operator lookup (async)

#### Minimum request
- `action = operator-lookup`
- `merchant`
- `order`
- `digest`
- `request_id`
- `url_callback`
- `service_name`
- `msisdn`

#### Expected behavior
1. User enters MSISDN on integrator page.
2. Integrator calls Dimoco.
3. Dimoco returns synchronous `status = 5` (`pending`).
4. Dimoco identifies the MNO.
5. Dimoco sends callback to `url_callback`.
6. Integrator responds with HTTP `200`.

#### Relevant callback interpretation
- `action_result status = 0` and valid `operator` → operator detected
- `action_result status = 1` → operator detection failed

### Operator lookup (sync)

#### Minimum request
- `action = operator-lookup`
- `merchant`
- `order`
- `digest`
- `request_id`
- `url_callback`
- `service_name`
- `msisdn`

#### Expected behavior
1. User enters MSISDN on integrator page.
2. Integrator calls Dimoco.
3. Dimoco identifies the MNO.
4. Dimoco returns synchronous response:
   - `action_result status = 0` and valid `operator` → operator detected
   - `action_result status = 1` → operator detection failed

## Subscription flow

## CLICK subscription flow

Use when the user is already in mobile web and the setup resolves to CLICK.

### Minimum request
- `action = start-subscription`
- `merchant`
- `order`
- `digest`
- `request_id`
- `amount`
- `url_callback`
- `url_return`
- `service_name`
- `transaction` = transaction id from previous identify

Optional:
- `prompt_merchant_args`
- `prompt_product_args`
- other UX-improving parameters supported by Dimoco

### Expected behavior
1. Integrator has already completed identify or operator lookup.
2. Integrator calls `start-subscription`.
3. Dimoco returns synchronous `status = 3` and redirect URL.
4. Integrator redirects the user.
5. User confirms subscription on the MNO-hosted page.
6. Dimoco bills the requested amount.
7. Dimoco sends asynchronous callback with payment and subscription outcome.
8. Integrator responds with HTTP `200`.
9. Dimoco redirects the user to `url_return`.
10. Integrator shows outcome / content / further instructions.

### Success condition
- `action_result status = 0`
- `billed_amount = amount`

### Failure condition
- `action_result status = 1`
- `billed_amount = 0`
- error details present

## WEB TAN subscription flow

Use when the user is on Wi-Fi or desktop and must confirm via TAN.

### Minimum request
- `action = start-subscription`
- `merchant`
- `order`
- `digest`
- `request_id`
- `amount`
- `url_callback`
- `service_name`
- `msisdn`
- `operator`

Optional:
- `prompt_merchant_args`
- `prompt_product_args`
- other UX-improving parameters supported by Dimoco

### Expected behavior
1. User enters MSISDN on integrator page.
2. Integrator completes identify or operator lookup.
3. Integrator calls `start-subscription`.
4. Dimoco returns synchronous `status = 3` and redirect URL.
5. Integrator redirects the user.
6. MNO sends TAN to the user.
7. User enters TAN on the MNO page and confirms.
8. Dimoco bills the requested amount.
9. Dimoco sends asynchronous callback with payment and subscription outcome.
10. Integrator responds with HTTP `200`.
11. Dimoco redirects the user to `url_return`.
12. Integrator shows outcome / content / further instructions.

### Success condition
- `action_result status = 0`
- `billed_amount = amount`

### Failure condition
- `action_result status = 1`
- `billed_amount = 0`
- error details present

## Renewal flow

Renewals in this AT setup are handled by DIMOCO through `pay:periodic`.

### Expected behavior
1. DIMOCO triggers the renewal billing attempt on schedule.
2. DIMOCO sends asynchronous callback to `manage_subscription_url_callback`.
3. Integrator responds with HTTP `200`.
4. Integrator confirms renewal result to merchant.
5. Merchant prolongs user access.

### Success condition
- `action_result status = 0`
- `billed_amount = amount`

### Failure condition
- `action_result status = 1`
- `billed_amount = 0`
- retry or rescheduling may happen according to retry policy

## Unsubscription flow

## Merchant-side unsubscribe

Use when the user unsubscribes via the merchant portal.

### Minimum request
- `action = close-subscription`
- `merchant`
- `order`
- `digest`
- `request_id`
- `url_callback`
- `service_name`
- `subscription`

### Expected behavior
1. User confirms unsubscribe on merchant portal.
2. Integrator calls Dimoco.
3. Dimoco unsubscribes the user.
4. Dimoco sends stop confirmation message.
5. Dimoco sends callback to `url_callback`.
6. Integrator responds with HTTP `200`.
7. After the prepaid grace period, merchant blocks access.

## DIMOCO / MNO portal unsubscribe

Use when the user unsubscribes via the DIMOCO or MNO care portal.

### Expected behavior
1. User confirms unsubscribe on DIMOCO / MNO portal.
2. Dimoco unsubscribes the user.
3. Dimoco sends stop confirmation message.
4. Dimoco sends callback to `close_notification_url_callback`.
5. Integrator responds with HTTP `200`.
6. After the prepaid grace period, merchant blocks access.

## Refund flow

### Minimum request
- `action = refund`
- `merchant`
- `order`
- `digest`
- `request_id`
- `url_callback`
- `service_name`
- `transaction` = transaction id of the original payment

### Expected behavior
1. Integrator calls Dimoco.
2. Dimoco returns synchronous pending response.
3. Dimoco performs refund attempt.
4. Dimoco sends callback to `url_callback`.
5. Integrator responds with HTTP `200`.
6. Integrator confirms refund result to merchant.

### Success condition
- `action_result status = 0`
- `billed_amount = amount`

### Failure condition
- `action_result status = 1`
- `billed_amount = 0`

## Additional features

## Free SMS MT sending

The AT guide documents free outbound SMS sending via action `prompt`.

### Minimum request
- `action = prompt`
- `merchant`
- `order`
- `digest`
- `request_id`
- `url_callback`
- `service_name`
- `msisdn`
- `operator`
- `subject = content`
- `channel = sms`
- `prompt_content_args = {"text":{"de":"Free MT text"}}`

### Expected behavior
1. Integrator calls Dimoco.
2. Dimoco returns synchronous `status = 5` (`pending`).
3. Dimoco sends the MT message.
4. Dimoco sends callback with delivery result.
5. Integrator responds with HTTP `200`.

## Blocklist actions

The AT guide explicitly lists support for:
- `add-blocklist`
- `remove-blocklist`
- `check-blocklist`

### Minimum request
- `action`
- `merchant`
- `order`
- `digest`
- `request_id`
- `url_callback`
- `msisdn`
- `operator`
- `blocklist_scope` for add/remove, optional for check

### Expected behavior
1. Integrator calls Dimoco.
2. Dimoco returns synchronous `status = 5` (`pending`).
3. Dimoco performs the requested blocklist operation.
4. Dimoco sends callback with the outcome.
5. Integrator responds with HTTP `200`.

### Callback interpretation
- `action_result status = 0` → success
- `action_result status = 1` → failure

## Repository-specific implementation notes

Use this AT file for:
- which orders belong to the Austria subscription setup
- which flow variants are enabled
- which callbacks must exist
- which business outcome rules apply to AT subscription processing

Use `../../general-api.md` for:
- generic digest rules
- generic callback format
- generic Dimoco action definitions
- generic result and status code documentation

## Implementation checklist

When changing AT subscription handling, verify at minimum:
- correct order selection (`115510` vs `115512` vs `115511`)
- correct merchant id usage (`200309` vs `200416`)
- reuse of existing digest logic
- correct callback endpoint wiring
- correct CLICK vs WEB TAN routing
- correct success detection via `action_result status` and `billed_amount`
- correct handling of renewal callbacks
- correct handling of close-subscription notifications

## Open questions

Use this section for repository-specific findings such as:
- how the current code selects service/order by landing page or service key
- whether sync operator lookup is used in production or only async
- how AT callback payloads are normalized into internal models
- whether refund and blocklist actions are wired to the same shared callback handler