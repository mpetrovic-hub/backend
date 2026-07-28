---
type: "query"
date: "2026-07-26T11:39:54.168474+00:00"
question: "How does the new tools/database premise constrain the external retention archive health checker for Issue #110?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["Database Migrations", "Kiwi_Database_Command", "Kiwi_Database_Deployment_Service", "schema-contract.php"]
---

# Q: How does the new tools/database premise constrain the external retention archive health checker for Issue #110?

## Answer

Expanded via graph vocab: [database, deployment, external, runner, require, lifecycle, runtime, status, apply, schema]. Verified against current files: tools/database is an explicit non-runtime WP-CLI boundary. kiwi-database.php loads through --require, executes on plugins_loaded before init, emits JSON, and halts with a meaningful exit code. The schema status/apply command remains schema-specific; Issue #110 should reuse its bootstrap contract in a dedicated read-only retention command. Dated backfill and recovery runners are untracked local artifacts, not an existing public command contract.

## Outcome

- Signal: useful

## Source Nodes

- Database Migrations
- Kiwi_Database_Command
- Kiwi_Database_Deployment_Service
- schema-contract.php