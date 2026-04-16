# Changelog

All notable changes to this project will be documented in this file.

## [v0.1.3 beta] - 2026-04-08

### Added
- Schema migration gating in `Kiwi_Plugin` to keep `dbDelta` work off normal request paths.
- Cleanup throttling lock for click attribution cleanup.
- Test coverage for schema gate and cleanup throttle behavior.
- WordPress option/transient runtime keys:
  - `kiwi_backend_db_schema_version`
  - `kiwi_click_attribution_cleanup_lock`

### Changed
- Existing `ensure_*_table` methods now delegate to a schema-version gate.
- `cleanup_expired_click_attributions()` now skips execution while lock is active.

### Database impact
- No schema definition changes (no new tables, columns, or indexes).
- Runtime writes:
  - updates `kiwi_backend_db_schema_version` in `wp_options`
  - writes `kiwi_click_attribution_cleanup_lock` transient
