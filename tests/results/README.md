# Sessions recovery validation

`retention-sessions-recovery-validation.json` records the executed synthetic MariaDB/SQLite recovery scenarios, tested runtime hashes and repository regression count. It is evidence for the reviewed implementation, not a production execution record.

With PHP CLI (PDO MySQL/SQLite), MariaDB server/client binaries and an unprivileged shell:

```bash
bash tests/run-retention-sessions-recovery-integration.sh /tmp
php tests/run-tests.php
```

The integration shell runner creates its own private database server with TCP disabled, checks its sandbox marker/socket, and stops the server on exit. It does not connect to an existing MySQL server or WordPress installation. The resulting private directory retains a machine-readable report. `PHP_BIN` and `MARIADB_BASE_DIR` can select existing local binaries. The supervised health child uses `PHP_BINARY`; its PHP configuration must also provide PDO SQLite.

The recorded cloud run used PHP 8.4.24 and MariaDB 11.8.6 extracted only into `/workspace/scratch/retention-runtime`, without a system install. The XML extension needed by the existing repository suite was also extracted there. Full WordPress/WP-CLI deployment integration and the production data volume were not tested.
