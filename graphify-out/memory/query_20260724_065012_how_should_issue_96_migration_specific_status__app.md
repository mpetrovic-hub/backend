---
type: "query"
date: "2026-07-24T06:50:12.772070+00:00"
question: "How should Issue 96 migration-specific status, apply, and rollback integrate into the existing WP-CLI database runner?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["Kiwi_Database_Command", "Kiwi_Database_Deployment_Service", "Database Migrations"]
---

# Q: How should Issue 96 migration-specific status, apply, and rollback integrate into the existing WP-CLI database runner?

## Answer

Expanded from original query via graph vocab: database, cli, command, migration, status, apply, rollback, deployment, runner. The existing Kiwi_Database_Command exposes only the generic status and apply operations, backed by Kiwi_Database_Deployment_Service. The Issue 96 rename should therefore use a separate versioned migration artifact and an explicit migration-specific WP-CLI command path with status, apply, and rollback, while the generic kiwi database status and apply commands remain unchanged. This keeps the reviewed one-time rename and controlled reverse path out of generic canonical schema reconciliation and leaves a focused namespace for later migration artifacts.

## Outcome

- Signal: useful

## Source Nodes

- Kiwi_Database_Command
- Kiwi_Database_Deployment_Service
- Database Migrations