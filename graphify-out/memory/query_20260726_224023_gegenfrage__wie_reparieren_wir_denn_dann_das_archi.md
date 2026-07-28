---
type: "query"
date: "2026-07-26T22:40:23.601915+00:00"
question: "Gegenfrage: wie reparieren wir denn dann das Archiv?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["Kiwi_Retention_Sqlite_Archive_Service", "Retention Runbook", "Kiwi_Retention_Cleanup_Service"]
---

# Q: Gegenfrage: wie reparieren wir denn dann das Archiv?

## Answer

Expanded from original query via graph vocab: [archive, sqlite, integrity, recovery, batch, source, mysql, audit, retention]. Der aktuelle Code erkennt SQLite-Fehler, implementiert aber keinen verlässlichen Reparatur- oder Backup-Restore-Prozess. archive_batch_rows enthält IDs und keine zweite Kopie der Nutzdaten. Belastbare Wiederherstellung erfordert eine unabhängige bekannte gute Sicherung oder eine zweite vollständige Kopie noch nicht gesicherter Batchdaten; SQLite .recover ist nur Best-Effort-Salvage. Reparatur erfolgt in einer neuen Datei, danach integrity_check und Abgleich der Batch-Evidence, erst dann kontrollierter Austausch und Aufhebung der Sperre.

## Outcome

- Signal: useful

## Source Nodes

- Kiwi_Retention_Sqlite_Archive_Service
- Retention Runbook
- Kiwi_Retention_Cleanup_Service