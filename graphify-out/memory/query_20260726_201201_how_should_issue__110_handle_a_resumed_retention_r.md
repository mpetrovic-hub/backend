---
type: "query"
date: "2026-07-26T20:12:01.472055+00:00"
question: "How should Issue #110 handle a resumed retention run when persisted SQLite batch evidence does not match?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["Kiwi_Retention_Cleanup_Service", "Kiwi_Operational_Event_Service", "Retention Runbook"]
---

# Q: How should Issue #110 handle a resumed retention run when persisted SQLite batch evidence does not match?

## Answer

Expanded via graph vocab: [archive, batch, evidence, delete, failed, recovery, source]. The existing sources support fail-closed deletion and operational incidents; prior production recovery establishes a separate bounded external recovery boundary. Recommended plan: keep the original frozen run authoritative, retain all source rows, block overlapping cleanup, attempt only deterministic evidence reconciliation, and require a bounded external recovery with postflight when exact proof cannot be restored automatically.

## Outcome

- Signal: useful

## Source Nodes

- Kiwi_Retention_Cleanup_Service
- Kiwi_Operational_Event_Service
- Retention Runbook