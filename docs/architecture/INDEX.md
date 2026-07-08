# Architecture Index

Architecture docs describe stable internal contracts and boundaries. They are not runbooks.

## Read when

- You need to understand or change a reusable capability.
- You are deciding where provider-specific behavior should stop and shared domain logic should begin.
- You need capability status or landing-page system contracts.

## Source of truth for

- Cross-cutting business capability design.
- Stable internal boundaries.
- Capability coverage inventory.

## Not here

- Production operations: see `../operations/INDEX.md`.
- Provider payload details: see `../integrations/INDEX.md`.
- Temporary implementation plans.

## Docs

| File | Source of truth for |
|---|---|
| `capability-matrix.md` | Inventory of documented and implemented capabilities. |
| `click-attribution-and-postbacks.md` | Shared attribution capture, conversion matching, and affiliate postback boundary. |
| `landing-page-architecture.md` | Filesystem landing-page contract, discovery model, metadata contract, and migration principles. |

## Maintenance notes

- Keep provider-specific payload examples in integration docs.
- Keep operational commands, config values, and troubleshooting in operations docs.
- Treat `capability-matrix.md` as an inventory. Freshness must be verified against code before changing implementation status.

