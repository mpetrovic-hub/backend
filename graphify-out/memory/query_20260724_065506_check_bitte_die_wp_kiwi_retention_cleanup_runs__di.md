---
type: "query"
date: "2026-07-24T06:55:06.335319+00:00"
question: "check bitte die wp_kiwi_retention_cleanup_runs, die laufen seit dem 21.7. nicht mehr (skipped). Dazu kommt, das dies nicht in der wp_kiwi_operational_events geloggt wurde, sollte es aber schon, oder?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["Kiwi_Retention_Cleanup_Service", "Operational Events"]
---

# Q: check bitte die wp_kiwi_retention_cleanup_runs, die laufen seit dem 21.7. nicht mehr (skipped). Dazu kommt, das dies nicht in der wp_kiwi_operational_events geloggt wurde, sollte es aber schon, oder?

## Answer

Expanded from original query via vocab: [retention, cleanup, run, status, operational, event, timeout, scheduler, worker, stale, audit]. Production inspection shows the scheduler is active; coverage-gate failures return skipped and do not call Kiwi_Operational_Event_Service. The gate is blocked because main daily-summary rows before 2026-07-14 are absent while raw landing sessions exist. The current producer only emits retention_cleanup_timeout after a stale unfinished run is transitioned to failed. A dedicated retention coverage-gate incident producer is needed for raised/repeated/resolved visibility.

## Outcome

- Signal: useful

## Source Nodes

- Kiwi_Retention_Cleanup_Service
- Operational Events