---
type: "query"
date: "2026-07-27T11:54:17.065906+00:00"
question: "Das bräuchten wir gesetzt den Fall, dass entweder ein altes Archiv kaputt ist und ein neues angelegt werden muss, oder das Jahr zu Ende ist und eine neue angefangen wird. Wie ist der Jahr-ist-zu-Ende-Prozess aktuell aufgesetzt?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["Kiwi_Retention_Sqlite_Archive_Service", "Kiwi_Retention_Cleanup_Service", "Retention Runbook"]
---

# Q: Das bräuchten wir gesetzt den Fall, dass entweder ein altes Archiv kaputt ist und ein neues angelegt werden muss, oder das Jahr zu Ende ist und eine neue angefangen wird. Wie ist der Jahr-ist-zu-Ende-Prozess aktuell aufgesetzt?

## Answer

Expanded from original query via graph vocab: [archive, current, time, path, retention, run, sqlite, worker]. Aktuell gibt es keinen expliziten Jahresabschluss. Beim ersten Chunk ohne persistierten archive_db_path leitet build_archive_db_path den Dateinamen aus dem aktuellen WordPress-/MySQL-Jahr ab; SQLite erstellt die neue Jahresdatei und das Schema beim ersten Schreiben. Ein bereits laufender Run behält seinen zuerst persistierten Pfad auch über den Jahreswechsel. Es gibt keinen closed-Status, keinen speziell an den Jahreswechsel gebundenen Abschlusscheck und keine Vorgänger-/Nachfolgerbeziehung.

## Outcome

- Signal: useful

## Source Nodes

- Kiwi_Retention_Sqlite_Archive_Service
- Kiwi_Retention_Cleanup_Service
- Retention Runbook