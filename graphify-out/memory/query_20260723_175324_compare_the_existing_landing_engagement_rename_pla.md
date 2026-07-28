---
type: "query"
date: "2026-07-23T17:53:24.526812+00:00"
question: "Compare the existing landing-engagement rename plan with the new external schema deployment design from Issue 103 and tools/database."
contributor: "graphify"
outcome: "useful"
source_nodes: ["Kiwi_Database_Deployment_Service", "Kiwi_Premium_Sms_Landing_Engagement_Repository", "Database Migrations", "schema-contract.php"]
---

# Q: Compare the existing landing-engagement rename plan with the new external schema deployment design from Issue 103 and tools/database.

## Answer

Expanded from original query via graph vocab: [database, deployment, engagement, landing, migration, rename, schema, session, summary, table]. The graph connected the existing engagement repository and its direct consumers to the new external database deployment service and schema contract. Current file verification confirms that the old runtime-migration plan must be replaced by a migration-specific external hard cutover, while generic apply must fail closed on the legacy table.

## Outcome

- Signal: useful

## Source Nodes

- Kiwi_Database_Deployment_Service
- Kiwi_Premium_Sms_Landing_Engagement_Repository
- Database Migrations
- schema-contract.php