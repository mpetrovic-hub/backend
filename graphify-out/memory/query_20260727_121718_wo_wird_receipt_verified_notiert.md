---
type: "query"
date: "2026-07-27T12:17:18.269728+00:00"
question: "Wo wird receipt_verified notiert?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["Kiwi_Retention_Cleanup_Run_Repository", "Kiwi_Retention_Cleanup_Service", "Kiwi_Retention_Sqlite_Archive_Service"]
---

# Q: Wo wird receipt_verified notiert?

## Answer

Expanded from original query via graph vocab: [retention, cleanup, run, audit, archive, batch, worker, repository]. Der Zwischenstand wird in der bereits vorhandenen MySQL-Auditzeile des Cleanup-Runs in wp_kiwi_retention_cleanup_runs notiert: worker_phase=receipt_verified zusammen mit archive_batch_id, archive_db_path, kumulierten Counts und Archivcursor. Die vollständige ID-Liste bleibt als eigentliche Quittung in SQLite archive_batch_rows und wird nicht in MySQL dupliziert. Dafür ist keine neue Tabelle und voraussichtlich keine neue Spalte erforderlich.

## Outcome

- Signal: useful

## Source Nodes

- Kiwi_Retention_Cleanup_Run_Repository
- Kiwi_Retention_Cleanup_Service
- Kiwi_Retention_Sqlite_Archive_Service