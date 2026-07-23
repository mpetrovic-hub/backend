# Documentation Index

This is the agent-facing map for repository documentation. Human quick-start information stays in the root `README.md`.

## Read when

- You need to choose the smallest relevant documentation set for a task.
- You are updating docs and need to know the canonical owner for a topic.
- You are preparing an implementation plan and need the right supporting references.

## Source of truth for

- Documentation navigation.
- Documentation ownership by topic.
- Rules for avoiding duplicated docs.

## Not here

- Runtime setup: use `../README.md`.
- Coding workflow rules: use `../AGENTS.md`.
- Temporary implementation plans and dated audits at repository root.

## Start routing

| Task | Read first | Then read |
|---|---|---|
| Aggregator, country, flow, callback, billing | `integrations/INDEX.md` | Relevant Aggregator `INDEX.md`, general API doc, country/flow doc |
| Capability coverage | `architecture/capability-matrix.md` | Supporting integration or architecture doc |
| Landing-page folder contract, discovery, metadata | `architecture/landing-page-architecture.md` | `operations/landing-page-runtime.md` for production behavior |
| Landing runtime, routing, gallery, multi-domain exposure, troubleshooting | `operations/landing-page-runtime.md` | `operations/configuration-reference.md` when config is involved |
| Click attribution and affiliate postbacks | `architecture/click-attribution-and-postbacks.md` | `operations/configuration-reference.md` for constants |
| Landing KPI, Statistics UI, daily summary, TK-zone summary | `operations/landing-funnel-analytics.md` | `architecture/click-attribution-and-postbacks.md` for attribution boundary |
| Raw retention, cleanup worker, raw-context compaction | `operations/retention-runbook.md` | `operations/landing-funnel-analytics.md` for summary coverage context |
| Operational events, open incidents, event cleanup | `operations/operational-events-runbook.md` | `architecture/operational-events.md` for the stable producer contract |
| Premium SMS fraud monitoring | `operations/premium-sms-fraud-monitoring.md` | `architecture/click-attribution-and-postbacks.md` for attribution propagation |
| Secrets, environments, non-secret constants | `operations/credentials-and-environments.md` | `operations/configuration-reference.md` |
| Edge/VPS endpoint operations | `operations/vps-endpoint-runbook.md` | `operations/landing-page-runtime.md` |
| Documentation maintenance | This `INDEX.md` | `../CHANGELOG.md` for durable behavior changes |
| Domain vocabulary | `../GLOSSARY.md` | Relevant topic doc |

## Documentation areas

- `architecture/INDEX.md`: stable system contracts, boundaries, cross-cutting architecture.
- `operations/INDEX.md`: production behavior, runbooks, configuration, troubleshooting.
- `integrations/INDEX.md`: external Aggregator docs and Aggregator-specific setup references.

## Documentation maintenance rules

- Keep one canonical source for each detailed fact.
- In related docs, summarize in one or two lines and link to the canonical source.
- Do not cite temporary root-level plan or audit files as official documentation.
- Do not add real credentials or secrets.
- Do not promote a capability to `implemented` in `architecture/capability-matrix.md` without code or test evidence.
