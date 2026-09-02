# Known-Good FR Test Vector (NTH One-off)

Use this as a fast debugging baseline for the FR one-off flow.

Captured from a successful end-to-end run on **2026-04-15**:

- MO callback accepted
- MT `submitMessage` accepted (`resultCode=100`)
- MT `deliverReport` intermediate (`messageStatus=1`)
- MT `deliverReport` final (`messageStatus=2`)
- sale recorded and affiliate postback sent

Note:

- This trace is intentionally exact in flow shape and key correlation fields.
- Sensitive credentials are redacted.

## 1) MO Callback (`deliverMessage`)

```json
{
  "messageId": "35459579",
  "businessNumber": "84072",
  "time": "2026-04-15 15:01:15",
  "sessionId": "84072FRS1776258075057",
  "msisdn": "1000000111043765",
  "operatorCode": "20820",
  "keyword": "Jplay",
  "command": "deliverMessage",
  "content": "Jplay txn_405b9dd2f5294d06"
}
```

## 2) MT Submit (`submitMessage`)

Request payload (outbound from our backend to NTH):

```json
{
  "command": "submitMessage",
  "username": "[redacted]",
  "password": "[redacted]",
  "msisdn": "1000000111043765",
  "businessNumber": "84072",
  "content": "MyJoyplay kiwi mobile GmbH 4,5€ + prix SMS(ce n'est pas un abonnement) https://mcontentfr.joy-play.com Problème? Plainte.XXXXX@allopass.com",
  "price": "450",
  "nwc": "20820",
  "encoding": "UTF-8",
  "messageRef": "txn_405b9dd2f5294d06-80e4826a59e4",
  "sessionId": "84072FRS1776258075057"
}
```

NTH response body:

```xml
<res>
  <resultCode>100</resultCode>
  <resultText>OK</resultText>
  <messageId>170852021</messageId>
  <messageRef>txn_405b9dd2f5294d06-80e4826a59e4</messageRef>
  <sessionId>84072FRS1776258075057</sessionId>
</res>
```

## 3) MT Delivery Report (Intermediate)

```json
{
  "messageStatus": "1",
  "messageStatusText": "Submitted",
  "messageRef": "txn_405b9dd2f5294d06-80e4826a59e4",
  "messageId": "170852021",
  "businessNumber": "84072",
  "time": "2026-04-15 15:01:16",
  "sessionId": "84072FRS1776258075057",
  "msisdn": "1000000111043765",
  "command": "deliverReport"
}
```

Expected behavior: tracked as non-terminal/intermediate, no sale confirmation yet.

## 4) MT Delivery Report (Final)

```json
{
  "messageStatus": "2",
  "messageStatusText": "Delivered",
  "messageRef": "txn_405b9dd2f5294d06-80e4826a59e4",
  "messageId": "170852021",
  "businessNumber": "84072",
  "time": "2026-04-15 15:01:20",
  "sessionId": "84072FRS1776258075057",
  "msisdn": "1000000111043765",
  "command": "deliverReport"
}
```

Expected behavior: terminal confirmed delivery, sale recorded, attribution resolved, Affise postback emitted once.

## 5) Rejected MT Submit Counterexample

An HTTP success alone is not an accepted MT submit. For example:

```xml
<res>
  <resultCode>400</resultCode>
  <resultText>Authorization failed</resultText>
  <messageRef>txn_example-redacted</messageRef>
</res>
```

Expected behavior:

- the submit event and flow transaction are stored as terminal `mt_submit_failed`;
- `resultCode` and `resultText` remain inspectable in the normalized event/transaction snapshot;
- no pending guard remains for a later, independent MO;
- no delivery, sale, or affiliate-postback consequence is inferred;
- the service-level NTH submit incident is raised/repeated in the Operational Event log;
- no automatic replay is attempted.

## 6) Manual Handling of Historical False-Pending Rows

Use this checklist after deployment and before resuming affected traffic. It is deliberately read-only until a separate correction is approved.

1. Restrict candidates to `service_key=nth_fr_one_off_jplay` and 21–24 July 2026, then identify non-terminal or `mt_submitted` transactions whose protected NTH evidence shows a `resultCode` other than `100`.
2. For each candidate, compare only the flow reference, result code/text, any `deliverReport`, and any recorded sale. Do not copy complete request/response payloads or subscriber identifiers into tickets.
3. Record the proposed handling and why no automatic replay is allowed.
4. If a status correction is necessary, obtain separate approval for the exact target rows, target status, responsible person, execution time, verification, and rollback. This code change performs no historical mutation.
5. After an approved manual correction, record bounded references, time, owner, and verification result in the Issue.

