# Integrations Index

Aggregator integrations connect Kiwi backend capabilities to external provider APIs.

## Read when

- Work touches an aggregator, MNO/operator setup, country, flow, callback, billing, refund, blacklist, HLR/operator lookup, Premium SMS, or carrier billing.
- You need to separate reusable internal capability design from provider-specific behavior.

## Source of truth for

- Integration documentation layout.
- Which integration docs to read for provider work.
- The distinction between aggregator-wide API docs and country/flow docs.

## Not here

- Reusable architecture rules: see `../architecture/INDEX.md`.
- Runtime operations and config constants: see `../operations/INDEX.md`.
- Real credentials or secrets.

## Read path

1. Start with the aggregator `INDEX.md`.
2. Read the aggregator-wide API document.
3. Read the concrete country/flow document.
4. If capability coverage matters, check `../architecture/capability-matrix.md`.
5. If auth, callbacks, endpoint URLs, or environment settings matter, check `../operations/credentials-and-environments.md` and `../operations/configuration-reference.md`.

## Current aggregators

| Aggregator | Index | General API docs | Concrete setup docs |
|---|---|---|---|
| Dimoco | `dimoco/INDEX.md` | `dimoco/general-api-dimoco.md` | `dimoco/at/subscription/at-subscription-dimoco-api.md` |
| NTH | `nth/INDEX.md` | `nth/general-api-premium-sms-nth.md` | `nth/fr/one-off/fr-one-off-nth-api.md` |
| Lily | `lily/INDEX.md` | `lily/general-api-premium-sms-lily.md` | `lily/gr/subscription/gr-subscription-lily-api.md` |

## Documentation layers

- Aggregator index: quick map, supported capability links, known limitations.
- General API doc: authentication model, endpoint families, callback patterns, status/error conventions, request/response mapping that applies across setups.
- Country/flow doc: concrete market rules, shortcode/business number, flow behavior, payload examples, callback details, compliance requirements, unresolved setup questions.
- `source/`: original provider documents, emails, PDFs, examples, or raw source material.

## Rules

- Put reusable aggregator-wide information in the general API doc.
- Put local country/flow exceptions in the concrete setup doc.
- Do not duplicate AGENTS.md engineering workflow text here.
- Keep original source material near the relevant aggregator and summarize it in Markdown when it is operationally relevant.

