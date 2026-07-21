# Dimoco Index

## Read when

- Work touches Dimoco operator lookup, refunds, blocklist actions, subscription lifecycle, Dimoco callbacks, or Dimoco request mapping.

## Source of truth for

- Dimoco documentation navigation.
- Which Dimoco docs are aggregator-wide vs setup-specific.

## Not here

- Full Dimoco action catalog: see `general-api-dimoco.md`.
- Austria subscription setup details: see `at/subscription/at-subscription-dimoco-api.md`.
- Secrets or real credentials.

## Start here

1. `general-api-dimoco.md`
2. `at/subscription/at-subscription-dimoco-api.md` when the AT subscription setup matters
3. `../../operations/credentials-and-environments.md` and `../../operations/configuration-reference.md` for config ownership and constants
4. `../../architecture/capability-matrix.md` for capability status

## Current docs

| File | Purpose |
|---|---|
| `general-api-dimoco.md` | Aggregator-wide Dimoco API reference and repository mapping notes. |
| `at/subscription/at-subscription-dimoco-api.md` | Austria subscription flow, identify/operator lookup/start/renew/close/refund/blocklist notes. |

## Update rules

- Update `general-api-dimoco.md` for broadly true Dimoco behavior.
- Update the country/flow doc for local setup behavior.
- Keep Aggregator payload mapping at the integration boundary; do not copy external payload shapes into shared capability docs unless they explain the boundary.
