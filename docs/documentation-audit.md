# Documentation Audit

This audit is docs-only. It does not claim that implementation status is current against code.

## Read when

- You are continuing documentation cleanup.
- You need to know why the current documentation structure changed.

## Source of truth for

- Current documentation ownership decisions.
- Known redundancy and staleness risks found during the docs-only audit.

## Not here

- Code freshness validation.
- Capability implementation evidence.
- Temporary root-level plan files.

## Findings

| File | Current role | Canonical owner after cleanup | Issues found | Follow-up |
|---|---|---|---|---|
| `docs/INDEX.md` | Agent-facing docs map | Docs navigation | Was previously a README-style mixed index for agents and humans. | Keep concise; root `README.md` remains human-facing. |
| `docs/architecture/capability-matrix.md` | Capability inventory | Capability status inventory | May be stale; status values need code/test evidence. | Separate freshness phase. |
| `docs/architecture/click-attribution-and-postbacks.md` | Shared attribution architecture | Attribution/postback boundary | Also contained funnel analytics and retention details. | Move analytics/retention details to operations docs. |
| `docs/architecture/landing-page-architecture.md` | Landing-page contract | Filesystem page architecture | Contains some stale example paths and operational references. | Keep contract-focused and link to runtime runbook. |
| `docs/operations/landing-page-runtime.md` | Runtime runbook | Landing runtime and troubleshooting | Previously bundled KPI, analytics summary, retention, and config reference. | Split to analytics, retention, and config docs. |
| `docs/operations/credentials-and-environments.md` | Secret/environment ownership | Credential and environment ownership | Also contained non-secret config reference details. | Move constants to `configuration-reference.md`. |
| `docs/integrations/*/INDEX.md` | Aggregator navigation | Aggregator start points | README files were agent indices rather than human README docs. | Keep short and link to canonical docs. |
| `docs/integrations/lily/gr/subscription/gr-subscription-lily-api.md` | Greece setup doc | Lily Greece subscription setup | Was stored as Lily README despite being setup-specific. | Keep Lily index separate. |

## Ignored non-canonical files

Root-level temporary plan/audit files are not official documentation sources for agents. Treat dated audits and implementation plans as scratch context unless their durable facts have been moved into canonical docs.

If a temporary plan contains durable knowledge, move that fact into the relevant canonical doc before referencing it.
