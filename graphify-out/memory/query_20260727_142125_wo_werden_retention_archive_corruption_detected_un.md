---
type: "query"
date: "2026-07-27T14:21:25.722518+00:00"
question: "Wo werden retention_archive_corruption_detected und resolution_reason genau geschrieben?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["Kiwi_Operational_Event_Service", "Operational Events", "Operational Events Runbook"]
---

# Q: Wo werden retention_archive_corruption_detected und resolution_reason genau geschrieben?

## Answer

Expanded from original query via graph vocab: [operational, event, events, lifecycle, action, context, record, failure, recovery, service, repository]. Beide werden in der bestehenden Tabelle wp_kiwi_operational_events erfasst. event_type speichert retention_archive_corruption_detected, lifecycle_action speichert raised/repeated/resolved, und resolution_reason wird mangels eigener Spalte im kleinen strukturierten context_json der resolved-Zeile gespeichert. Raised und resolved sind append-only Zeilen mit derselben correlation_key; die ursprüngliche Defektzeile wird nicht überschrieben.

## Outcome

- Signal: useful

## Source Nodes

- Kiwi_Operational_Event_Service
- Operational Events
- Operational Events Runbook