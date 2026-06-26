# GLOSSARY.md

## Purpose
TBD

## GLOSSARY / BUSINESS-LANGUAGE
Key domain terms:
- mVAS = mobile value added services
- MSISDN = subscriber phone number
- Aggregator = partner/provider integrating with one or more MNOs
- MNO = mobile network operator
- Carrier = mobile network operator
- Operator = mobile network operator
- Billing = payments of an end-user. Can have different periodicity, common are: weekly, daily, monthly etc.
- Flow = The set of user actions required to make a sale, from landing page to successful billing confirmation
- PIN = A user-entered confirmation code used to validate an mVAS billing action
- PIN-flow = A flow where the user confirms the mVAS purchase/subscription by submitting a code on a PIN entry page
- Click-flow = A flow where the user confirms the billing action directly on the landing/payment page through one or more clicks, without separate PIN or SMS verification
- Web2sms-flow = A flow where the user usually enters their MSISDN first and clicks a submit-button, receives an optin-SMS that they have to answer/confirm
- Click2sms-flow = A flow where the user usually presses a button on the landing-page, that opens a pre-filled message via sms://<number>?body=<text>
- Carrier-Billing = When users are billed directly via their MNO via API, usually the case for click- or PIN-flows
- Premium-SMS = When users are billed via paid SMS, usually the case for Web2sms-flows or Click2sms-flows
- One-off = When a user pays only once. In general the opposite of "subscription"