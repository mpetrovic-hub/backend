---
type: "query"
date: "2026-07-27T10:22:56.403881+00:00"
question: "Wir brauchen kein Backup vom Backup. Idee: falls die Prüfung ergibt, dass die Archivdatei fehlerhaft ist, soll der Archivierer einfach eine neue Datei anlegen, und mit der weitermachen?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["Kiwi_Retention_Cleanup_Service", "Kiwi_Retention_Sqlite_Archive_Service", "Retention Runbook"]
---

# Q: Wir brauchen kein Backup vom Backup. Idee: falls die Prüfung ergibt, dass die Archivdatei fehlerhaft ist, soll der Archivierer einfach eine neue Datei anlegen, und mit der weitermachen?

## Answer

Expanded from original query via graph vocab: [archive, sqlite, retention, cleanup, batch, path, run, worker]. Ja, ein sicherer Generationenwechsel ist möglich: die fehlerhafte Datei bleibt unverändert in Quarantäne und zukünftige Cleanup-Runs schreiben in eine neue Jahresdatei-Generation. Ein bereits laufender Run darf nicht mitten im Ablauf wechseln, weil sein archive_db_path und seine Batch-Evidence an die alte Datei gebunden sind. Der Wechsel hält zukünftige Retention arbeitsfähig, repariert aber keine bereits nur in der beschädigten Datei vorhandenen Daten.

## Outcome

- Signal: useful

## Source Nodes

- Kiwi_Retention_Cleanup_Service
- Kiwi_Retention_Sqlite_Archive_Service
- Retention Runbook