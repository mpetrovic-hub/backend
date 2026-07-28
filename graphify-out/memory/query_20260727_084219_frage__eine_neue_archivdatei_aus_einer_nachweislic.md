---
type: "query"
date: "2026-07-27T08:42:19.094141+00:00"
question: "Frage: Eine neue Archivdatei aus einer nachweislich gesunden Sicherung erstellen. - woher soll die nachweislich gesunden Sicherung herkommen?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["Kiwi_Retention_Sqlite_Archive_Service", "Retention Runbook"]
---

# Q: Frage: Eine neue Archivdatei aus einer nachweislich gesunden Sicherung erstellen. - woher soll die nachweislich gesunden Sicherung herkommen?

## Answer

Expanded from original query via graph vocab: [archive, sqlite, integrity, recovery, retention]. Im aktuellen Repository existiert keine verifizierte Retention-Archiv-Sicherung. Eine mögliche Quelle sind Hostinger-Dateibackups, doch der benutzerdefinierte Archivpfad ausserhalb von public_html, die SQLite-Konsistenz und ein erfolgreicher Test-Restore sind noch nicht verifiziert. Erst eine separat restaurierte Kopie mit erfolgreichem integrity_check und Archivbeleg-Abgleich gilt als bekannte gute Sicherung.

## Outcome

- Signal: useful

## Source Nodes

- Kiwi_Retention_Sqlite_Archive_Service
- Retention Runbook