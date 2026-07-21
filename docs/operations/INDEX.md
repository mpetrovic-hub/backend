# Operations Index

Operations docs describe production behavior, runbooks, configuration, and troubleshooting.

## Read when

- You need to validate, operate, debug, configure, or roll back a feature.
- A change touches production constants, WP-Cron hooks, endpoint exposure, retention, analytics refresh, or deployment behavior.

## Source of truth for

- Runtime behavior and operational procedures.
- Non-secret configuration reference.
- Secret/environment ownership rules.

## Not here

- Aggregator API details: see `../integrations/INDEX.md`.
- Reusable capability architecture: see `../architecture/INDEX.md`.
- Real secrets or credentials.

## Docs

| File | Source of truth for |
|---|---|
| `landing-page-runtime.md` | Filesystem-only landing-page routing, rendering, gallery, multi-domain exposure, runtime checks, troubleshooting, and deployment rollback. |
| `landing-funnel-analytics.md` | Landing KPI, Statistics UI, daily funnel summaries, TK-zone summaries, analytics storage/read behavior. |
| `premium-sms-fraud-monitoring.md` | Premium SMS fraud monitor UI, MO/engagement soft flags, hidden filters, and block/observe behavior. |
| `retention-runbook.md` | Landing-session raw retention coverage gate, archive/delete worker, raw-context compaction. |
| `operational-events-runbook.md` | Operational-event reads, open incidents, cleanup, producer checks, and troubleshooting. |
| `configuration-reference.md` | Non-secret constants and operational switches. |
| `credentials-and-environments.md` | Where secrets and environment-specific integration settings are owned. |
| `vps-endpoint-runbook.md` | VPS/domain endpoint procedures. |

## Maintenance notes

- Keep constants in `configuration-reference.md` unless they are secret ownership notes.
- Keep detailed analytics storage behavior in `landing-funnel-analytics.md`.
- Keep fraud monitor UI and signal interpretation in `premium-sms-fraud-monitoring.md`.
- Keep destructive cleanup behavior in `retention-runbook.md`.
