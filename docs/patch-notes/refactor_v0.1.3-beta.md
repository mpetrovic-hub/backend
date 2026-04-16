# v0.1.3 beta

Release date: 2026-04-08

## Summary

This beta release improves request-path performance for the WordPress plugin runtime by moving schema checks behind a version gate and throttling recurring cleanup work.

## Changes

1. Schema migration gating (hot-path optimization)
- Added schema version gating in `Kiwi_Plugin`.
- Existing table migration calls (`create_table()` / `dbDelta`) now run only when the stored schema version differs.
- Added/used option key: `kiwi_backend_db_schema_version`.

2. Click attribution cleanup throttling
- Added transient lock guard so click-attribution cleanup does not run on every request.
- Added lock key: `kiwi_click_attribution_cleanup_lock`.
- Lock TTL: 300 seconds.

3. Test coverage updates
- Added tests for:
  - one-time schema migration execution when schema is outdated
  - skipping schema migrations when versions match
  - cleanup throttling behavior with active lock
- Added test stubs for `get_option` and `update_option`.

## Database and table impact

- No table schema definitions were changed in this release.
- No new columns, indexes, or tables were introduced.
- Runtime side effects:
  - writes/updates one WordPress option (`kiwi_backend_db_schema_version`)
  - writes one transient lock (`kiwi_click_attribution_cleanup_lock`)

## Notes

- Hook names/order were intentionally preserved to avoid integration regressions.
- Future schema changes require bumping the internal schema version constant to trigger migrations.
