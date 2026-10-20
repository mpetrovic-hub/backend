# Kiwi Backend (mVAS)

Backend plugin for mVAS landing-page and aggregator flows.

## Quick test

```bash
php tests/run-tests.php
```

The standalone `tests/retention-engagement-tests.php` also requires real PDO
MySQL/SQLite and a private disposable MariaDB 11.8.9 instance. Set
`KIWI_RETENTION_TEST_CREDENTIALS_FILE` to a local JSON file containing `dsn`,
`username`, and `password`; enable both drivers with `PHPRC`. It creates and
drops only its own randomly named `codex_test_72_*` database. It never connects
to Production. Run `php tests/retention-engagement-tests.php`.
Use `php tests/retention-engagement-tests.php --tk-zero-day` for the focused
TK empty-day, missing-source and concurrent-resume regression scenarios.

## Documentation

- Documentation index: `docs/INDEX.md`
- Agent instructions: `AGENTS.md`
- Changelog: `CHANGELOG.md`
- Runtime and configuration docs: `docs/operations/INDEX.md`

