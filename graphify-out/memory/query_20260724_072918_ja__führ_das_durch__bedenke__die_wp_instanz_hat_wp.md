---
type: "query"
date: "2026-07-24T07:29:18.147247+00:00"
question: "Ja, führ das durch. Bedenke: die WP Instanz hat WP-CLI-Version 2.12."
contributor: "graphify"
outcome: "useful"
source_nodes: ["Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service", "Kiwi_Retention_Coverage_Gate", "Kiwi_Database_Command"]
---

# Q: Ja, führ das durch. Bedenke: die WP Instanz hat WP-CLI-Version 2.12.

## Answer

Expanded from original query via vocab: [landing, funnel, daily, summary, refresh, aggregation, metric, date, range, validate]. Production WP-CLI 2.12.0 passed the existing external runner status gate. A one-time repository-owned external CLI command, loaded with --require and halted after plugins_loaded before init, rebuilt only Main daily summaries for 2026-07-06 through 2026-07-13 using the deployed transactional aggregation service. It inserted 96,494 dimension rows and the retention coverage gate passed through 2026-07-13 with no blockers. Independent postflight confirmed the main summary now starts 2026-07-06, the shared refresh lock is cleared, and normal hourly cron remains scheduled. The temporary executable was removed from Production after validation; the local artifact remains for traceability.

## Outcome

- Signal: useful

## Source Nodes

- Kiwi_Landing_Funnel_Daily_Summary_Aggregation_Service
- Kiwi_Retention_Coverage_Gate
- Kiwi_Database_Command