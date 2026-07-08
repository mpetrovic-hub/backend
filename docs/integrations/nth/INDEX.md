# NTH Index

## Read when

- Work touches NTH Premium SMS, FR one-off flow, NTH callbacks, MT submission, delivery reports, or NTH-specific status mapping.

## Source of truth for

- NTH documentation navigation.
- Which NTH facts are aggregator-wide vs country/flow-specific.

## Not here

- Full Premium SMS operation details: see `general-api-premium-sms-nth.md`.
- France one-off setup: see `fr/one-off/fr-one-off-nth-api.md`.
- Real credentials or secrets.

## Start here

1. `general-api-premium-sms-nth.md`
2. `fr/one-off/fr-one-off-nth-api.md`
3. `fr/one-off/known-good-fr-test-vector.md` when callback/submit payload examples matter
4. `../../architecture/capability-matrix.md` for capability status
5. `../../operations/configuration-reference.md` for constants

## Current docs

| File | Purpose |
|---|---|
| `general-api-premium-sms-nth.md` | Aggregator-wide NTH Premium SMS API behavior and repository guidance. |
| `fr/one-off/fr-one-off-nth-api.md` | Concrete France one-off Premium SMS setup. |
| `fr/one-off/known-good-fr-test-vector.md` | Known-good callback and submit examples for the FR setup. |
| `source/premium-sms-APU-documentation.md` | Source placeholder/summary. |

## Update rules

- Update the general API doc for behavior that applies across NTH Premium SMS setups.
- Update the country/flow doc for shortcode, price, operator, compliance, or callback behavior specific to one setup.
- Keep click attribution and postback behavior in shared architecture docs; NTH docs should only describe how this setup wires into that shared capability.

