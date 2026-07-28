---
type: "query"
date: "2026-07-24T10:51:33.543537+00:00"
question: "Do the current repository database deployment tests exercise a real MySQL rename migration or only simulated wpdb behavior?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["database-deployment-tests.php", "Kiwi_Test_Database_Deployment_Wpdb", "Database Migrations"]
---

# Q: Do the current repository database deployment tests exercise a real MySQL rename migration or only simulated wpdb behavior?

## Answer

Expanded from original query via graph vocab: database, migration, rename, test, tests, mysql, fixture, wpdb, apply, rollback. The current database deployment test suite uses the in-memory Kiwi_Test_Database_Deployment_Wpdb fake and synthetic schema steps. No repository-owned real MySQL or MariaDB integration harness for RENAME TABLE was found. The Issue 96 plan should therefore consider a disposable non-Production MySQL rehearsal in addition to unit tests, covering the real rename, data and AUTO_INCREMENT preservation, version transition, repeat status/apply behavior, and controlled rollback without touching Production.

## Outcome

- Signal: useful

## Source Nodes

- database-deployment-tests.php
- Kiwi_Test_Database_Deployment_Wpdb
- Database Migrations