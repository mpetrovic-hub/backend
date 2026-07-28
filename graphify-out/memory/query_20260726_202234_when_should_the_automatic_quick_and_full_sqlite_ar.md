---
type: "query"
date: "2026-07-26T20:22:34.057802+00:00"
question: "When should the automatic quick and full SQLite archive health checks run relative to the retention worker?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["Kiwi_Plugin", "Kiwi_Retention_Cleanup_Service", "Kiwi_Retention_Sqlite_Archive_Service", "Retention Runbook"]
---

# Q: When should the automatic quick and full SQLite archive health checks run relative to the retention worker?

## Answer

Expanded via graph vocab: [archive, external, integrity, quick, daily, schedule, scheduler, worker, lock, reschedule]. Verified current behavior: the daily WP-Cron hook is not a stable wall-clock maintenance window, and worker chunks reschedule independently. Therefore the external schedule must use a shared archive lock and explicit deferred/retry behavior rather than relying only on clock separation.

## Outcome

- Signal: useful

## Source Nodes

- Kiwi_Plugin
- Kiwi_Retention_Cleanup_Service
- Kiwi_Retention_Sqlite_Archive_Service
- Retention Runbook