---
type: "query"
date: "2026-07-26T10:52:14.686837+00:00"
question: "Plan Issue #110: Move SQLite integrity checks out of retention WP-Cron while preserving archive-before-delete safety."
contributor: "graphify"
outcome: "useful"
source_nodes: ["Kiwi_Retention_Cleanup_Service", "Kiwi_Retention_Sqlite_Archive_Service", "Retention Runbook", "Kiwi_Plugin"]
---

# Q: Plan Issue #110: Move SQLite integrity checks out of retention WP-Cron while preserving archive-before-delete safety.

## Answer

Expanded from original planning request via graph vocab: [retention, sqlite, archive, audit, integrity, cleanup, delete, evidence, primary, worker]. Verified against source: archive_primary_key_chunk commits archive rows plus archive_batch_rows, then runs global quick_check; run_worker persists MySQL progress only after that check and delete, and performs final integrity_check in the worker. The durable plan must move global checks to an external process and redesign the audit/delete sequence around persisted SQLite primary-key evidence.

## Outcome

- Signal: useful

## Source Nodes

- Kiwi_Retention_Cleanup_Service
- Kiwi_Retention_Sqlite_Archive_Service
- Retention Runbook
- Kiwi_Plugin