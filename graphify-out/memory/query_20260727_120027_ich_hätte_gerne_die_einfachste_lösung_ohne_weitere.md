---
type: "query"
date: "2026-07-27T12:00:27.632268+00:00"
question: "Ich hätte gerne die einfachste Lösung ohne weitere Tabelle. Können wir nicht einfach einmal pro Jahr einen integrity_check auf alle Archive nacheinander ausführen?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["Kiwi_Retention_Sqlite_Archive_Service", "Kiwi_Retention_Cleanup_Service", "Retention Runbook"]
---

# Q: Ich hätte gerne die einfachste Lösung ohne weitere Tabelle. Können wir nicht einfach einmal pro Jahr einen integrity_check auf alle Archive nacheinander ausführen?

## Answer

Expanded from original query via graph vocab: [archive, file, path, sqlite, integrity, check, current, time]. Ja, der normale Jahreswechsel braucht weder closed-Status noch Vorgängerbeziehung. Das aktuelle Jahr und die Generation können aus Dateinamen abgeleitet werden; ein kleiner Quarantäne-Marker kann einen Defekt innerhalb des laufenden Jahres kennzeichnen. Ein jährlicher Gesamtlauf kann alle gefundenen SQLite-Archive dateiweise und seriell prüfen. PRAGMA integrity_check prüft dabei jeweils eine ganze Datei und lässt sich nicht ohne Bedeutungsverlust intern in Daten-Chunks zerlegen.

## Outcome

- Signal: useful

## Source Nodes

- Kiwi_Retention_Sqlite_Archive_Service
- Kiwi_Retention_Cleanup_Service
- Retention Runbook