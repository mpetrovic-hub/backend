---
type: "query"
date: "2026-07-26T19:59:40.755571+00:00"
question: "Which check fixes the Issue #110 retention blocker: the immediate receipt evidence check or the later external SQLite integrity check?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["Kiwi_Retention_Cleanup_Service", "Kiwi_Retention_Sqlite_Archive_Service", "Retention Runbook"]
---

# Q: Which check fixes the Issue #110 retention blocker: the immediate receipt evidence check or the later external SQLite integrity check?

## Answer

Expanded via graph vocab: [mysql, sqlite, archive, quick, integrity, evidence, delete, retention, worker]. Verified distinction: the 1.2 GB annual archive is SQLite, not MySQL. The runtime blocker is fixed by removing the global SQLite quick_check/integrity_check from the WP-Cron worker and using small persisted batch primary-key evidence plus matching counts as the immediate delete gate. The separate external command retains whole-file health assurance later; it does not unblock each worker batch.

## Outcome

- Signal: useful

## Source Nodes

- Kiwi_Retention_Cleanup_Service
- Kiwi_Retention_Sqlite_Archive_Service
- Retention Runbook