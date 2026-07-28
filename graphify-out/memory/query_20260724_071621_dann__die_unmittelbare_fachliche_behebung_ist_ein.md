---
type: "query"
date: "2026-07-24T07:16:21.320320+00:00"
question: "Dann: die unmittelbare fachliche Behebung ist ein extern ausgeführter und verifizierter Restore/Backfill der fehlenden Main-Daily-Summaries. Wie machen wir das am einfachsten direkt in einer Session ohne GitHub?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service", "Kiwi_Retention_Coverage_Gate"]
---

# Q: Dann: die unmittelbare fachliche Behebung ist ein extern ausgeführter und verifizierter Restore/Backfill der fehlenden Main-Daily-Summaries. Wie machen wir das am einfachsten direkt in einer Session ohne GitHub?

## Answer

Expanded from original query via vocab: [landing, funnel, daily, summary, refresh, aggregation, metric, date, range, validate]. Production inspection confirms that only main-summary dates 2026-07-06 through 2026-07-13 are missing; raw source rows and TK-zone summaries exist. The current hourly cron only rotates over 2026-07-17 through 2026-07-24, and there is no approved CLI backfill command. The safe direct approach is a repository-owned, migration-specific external WP-CLI command that calls the deployed idempotent main-summary refresh service for exactly 2026-07-06..2026-07-13, uses the existing refresh lock, preflights zero target rows and source counts, then validates totals and the retention coverage gate. Do not use direct SQL, wp eval, or normal cron manipulation.

## Outcome

- Signal: useful

## Source Nodes

- Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service
- Kiwi_Retention_Coverage_Gate