# Changelog Coverage Audit

This audit checks whether durable knowledge from `CHANGELOG.md` is represented in the canonical docs. It intentionally does not copy every historical change into docs.

## Read when

- You are checking whether Changelog entries require documentation updates.
- You are preparing a broad documentation freshness pass.

## Source of truth for

- Current Changelog-to-docs coverage decisions.
- Known durable documentation gaps found during the coverage pass.

## Not here

- Feature implementation evidence.
- Complete release notes.
- Temporary root-level plans or dated audits.

## Coverage decisions

| Changelog cluster | Documentation status | Canonical doc |
|---|---|---|
| Frontend tool auth cache and LiteSpeed no-cache behavior | Covered | `operations/configuration-reference.md`, `operations/landing-page-runtime.md` |
| Landing-session raw-context compaction | Covered | `operations/retention-runbook.md` |
| Retention scheduler/worker, coverage gate, SQLite archive safety | Covered | `operations/retention-runbook.md` |
| Main/TK-zone daily summary refresh split, locks, result options | Covered | `operations/landing-funnel-analytics.md` |
| Main daily summary slim dimension contract and `attribution_metric_date` sales inclusion | Covered | `operations/landing-funnel-analytics.md` |
| Trusted proxy client-IP buckets and debug metadata | Covered | `operations/configuration-reference.md`, `operations/landing-funnel-analytics.md`, `operations/landing-page-runtime.md` |
| TK-zone PID allow-list | Added in this pass | `operations/configuration-reference.md`, `operations/landing-funnel-analytics.md` |
| Device model-to-brand harvester threshold | Added in this pass | `operations/configuration-reference.md`, `operations/landing-funnel-analytics.md` |
| Premium SMS fraud monitor, `unknown_link`, hidden flow filter, observe/block behavior | Added in this pass | `operations/premium-sms-fraud-monitoring.md` |
| NTH FR completed-sale cooldown and pending-MT guard | Covered | `integrations/nth/fr/one-off/fr-one-off-nth-api.md`, `operations/configuration-reference.md`, `architecture/capability-matrix.md` |
| Affise `custom_field1`, secure/hash placeholders, S2S dispatch behavior | Covered | `architecture/click-attribution-and-postbacks.md`, `operations/configuration-reference.md` |
| Lily HLR transport vs business success | Added in this pass | `integrations/lily/general-api-premium-sms-lily.md` |
| Landing-page filesystem fallback, variants, asset rewrite, hero/LCP guidance | Covered | `architecture/landing-page-architecture.md`, `operations/landing-page-runtime.md` |
| VPS/public-host endpoint guidance | Covered | `operations/vps-endpoint-runbook.md`, `operations/landing-page-runtime.md` |
| DIMOCO callback/refund/blacklist operational details | Covered enough for current source split | `integrations/dimoco/general-api-dimoco.md`, `integrations/dimoco/at/subscription/at-subscription-dimoco-api.md` |
| Removed submodule and CI reviewer workflow changes | No additional docs needed | Historical/repository maintenance detail only unless revived |
| Landing-page copy/style-only changes for LP variants | No additional docs needed | Source files are the current truth |
| Dated DB retention planning audit | No additional docs needed | Durable retention behavior is in `operations/retention-runbook.md` |

## Open follow-ups

- A code-evidence pass should still verify `architecture/capability-matrix.md` statuses.
- A provider-by-provider pass should verify current DIMOCO, NTH, and Lily docs against code paths and tests, not only Changelog entries.

