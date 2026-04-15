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

