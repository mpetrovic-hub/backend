---
type: "query"
date: "2026-07-27T13:14:43.071737+00:00"
question: "Warum waren die letzten drei Retention-Runs completed, obwohl der globale quick_check auf der grossen Archivdatei den früheren Run unterbrochen hatte und sonst nichts geändert wurde?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["Kiwi_Retention_Sqlite_Archive_Service", "Kiwi_Retention_Cleanup_Service", "Retention Runbook"]
---

# Q: Warum waren die letzten drei Retention-Runs completed, obwohl der globale quick_check auf der grossen Archivdatei den früheren Run unterbrochen hatte und sonst nichts geändert wurde?

## Answer

Expanded from original query via graph vocab: [retention, cleanup, worker, archive, sqlite, quick, integrity, check, run, time]. Read-only production verification on 2026-07-27 confirms the deployed code is unchanged and still runs global quick_check per chunk plus final integrity_check; the archive is 1,331,638,272 bytes. Runs 41 and 42 completed with 14,793/16,779 rows and worker durations 55/57 seconds, while the comparable externally resumed final worker for run 40 needed 272 seconds. Therefore the defect is not fixed: global scan duration is variable and only the recent concrete runs finished in time. Cache/I/O/server load is a plausible but not separately measured explanation because per-check timings are not audited.

## Outcome

- Signal: useful

## Source Nodes

- Kiwi_Retention_Sqlite_Archive_Service
- Kiwi_Retention_Cleanup_Service
- Retention Runbook