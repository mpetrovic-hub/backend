# Kiwi Backend (mVAS)

Backend plugin for mVAS landing-page and aggregator flows.

## Documentation

Full documentation lives in:

- `docs/README.md`
- `CHANGELOG.md`

## Quick Test

```bash
php tests/run-tests.php
```

## Frontend Tools Auth (Config-Based)

To protect internal frontend tool shortcodes with a single config credential, add these constants to `wp-config.php`:

```php
define('KIWI_FRONTEND_AUTH_USERNAME', 'admin');
define('KIWI_FRONTEND_AUTH_PASSWORD_HASH', '$2y$10$REPLACE_WITH_BCRYPT_HASH');
```

Generate the password hash locally:

```bash
php -r "echo password_hash('your-strong-password', PASSWORD_DEFAULT) . PHP_EOL;"
```

Logout mechanism:
- Append `?kiwi_frontend_auth_action=logout` to a tool page URL (optionally add `&kiwi_frontend_auth_redirect=<urlencoded-target>`).

Scope:
- Protected: plugin frontend tools (`kiwi_hlr_lookup`, `kiwi_dimoco_refunder`, `kiwi_dimoco_blacklister`, `kiwi_landing_pages_gallery`) and HLR export trigger.
- Not protected by this layer: landing-page router traffic, REST callbacks, and WordPress admin.
