# Landing Page Migration

This runbook describes how to migrate landing pages from legacy `KIWI_LANDING_PAGES` config to the filesystem registry.

## Target state

- Landing pages live under `landing-pages/`.
- Each landing page has one folder named `lp<version>-<country>`.
- Required files:
  - `index.html`
  - `styles.css`
  - `integration.php`
- `integration.php` links the page to `flow`, `provider`, and `/integrations/...` documentation.

## Bootstrap toggles

The migration is controlled through thin bootstrap constants:

- `KIWI_LANDING_PAGES_ROOT`  
  Optional override for the landing-pages root directory. Defaults to `<plugin-root>/landing-pages`.

- `KIWI_LANDING_PAGES_FILESYSTEM_ENABLED`  
  Enables filesystem loading. Defaults to `true`.

- `KIWI_LANDING_PAGES_LEGACY_FALLBACK_ENABLED`  
  Enables legacy `KIWI_LANDING_PAGES` fallback for unmigrated pages. Defaults to `true`.

## Resolution order

At runtime:

1. Discover and validate filesystem landing pages.
2. Register valid entries keyed by folder name.
3. Add legacy config entries only when a key does not exist in filesystem registry.

This guarantees filesystem pages are primary and legacy entries are fallback-only.

## Migration steps

1. Pick one legacy landing page.
2. Create folder `landing-pages/lp<version>-<country>/`.
3. Add `index.html`, `styles.css`, and `integration.php`.
4. Keep business logic in application services (do not move provider/billing/callback logic into page files).
5. Set route metadata in `integration.php` for compatibility:
   - `backend_path`
   - `dedicated_path` (if needed)
   - `hostnames` (if needed)
6. Set `documentation` to an existing file under `/integrations/...`.
7. Deploy with legacy fallback still enabled.
8. Verify production parity.
9. Remove the migrated key from `KIWI_LANDING_PAGES`.
10. Repeat until no legacy keys remain.

## Validation behavior

- In debug mode (`KIWI_DEBUG=true`), invalid landing pages fail loudly with a detailed exception.
- In production, invalid entries are skipped and logged with actionable messages.

## Cutover rule

Legacy fallback should remain enabled only until all active landing pages have filesystem folders and parity is verified.

After full migration:

1. Disable `KIWI_LANDING_PAGES_LEGACY_FALLBACK_ENABLED`.
2. Remove remaining `KIWI_LANDING_PAGES` landing-page definitions.
3. Keep only thin landing-page bootstrap constants in `wp-config.php`.
