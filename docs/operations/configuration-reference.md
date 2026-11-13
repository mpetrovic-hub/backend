# Configuration Reference

## Read when

- Work touches `wp-config.php` constants, feature flags, debug toggles, operational limits, postback templates, trusted proxies, or cron windows.

## Source of truth for

- Non-secret runtime constants and operational switches.
- Expected defaults and behavior notes for config keys.

## Not here

- Real credentials or secret values: see `credentials-and-environments.md` for ownership only.
- Provider API payloads: see `../integrations/INDEX.md`.
- Landing runtime procedures: see `landing-page-runtime.md`.

## Configuration location

Configuration is currently stored in `wp-config.php` and read through the shared configuration layer, currently `class-config.php`.

The separate VPS archive-health runner reads strict JSON from `/etc/kiwi-archive-health.conf`; it does not load WordPress or source a shell file. Its settings and ownership are described below.

Do not store real secret values in repository docs.

## Landing-page loading

- `KIWI_LANDING_PAGES_ROOT`
  - optional filesystem root override
  - default: `<plugin-root>/landing-pages`

Filesystem discovery is always active. No configuration-based alternative loader or landing-page definition source is supported.

## Attribution and postbacks

- `KIWI_CLICK_ATTRIBUTION_COOKIE_NAME`
- `KIWI_CLICK_ATTRIBUTION_CLICK_ID_KEYS`
- `KIWI_CLICK_ATTRIBUTION_TTL_SECONDS`
  - default: `172800` seconds
  - minimum: `60`
- `KIWI_CLICK_ATTRIBUTION_CLEANUP_LIMIT`
  - default: `500`
  - minimum: `1`
- `KIWI_AFFILIATE_POSTBACK_URL_TEMPLATE`
  - outbound affiliate postback URL template
  - supports placeholders such as `{clickid}`, `{click_id}`, `{sale_reference}`, `{service_key}`, `{provider_key}`, `{operator_name}`, `{custom_field1}`, `{sub7}`, `{secure}`, and `{hash}` plus double-brace variants
- `KIWI_AFFILIATE_POSTBACK_SECRET`
  - shared secret for outgoing affiliate postback signing/checksum generation
  - not used for incoming aggregator callbacks
- `KIWI_AFFILIATE_POSTBACK_SIGNATURE_PARAMETER`
- `KIWI_AFFILIATE_POSTBACK_SIGNATURE_ALGORITHM`
- `KIWI_AFFILIATE_POSTBACK_SIGNATURE_BASE`
- `KIWI_AFFILIATE_POSTBACK_TIMEOUT_SECONDS`

Postback placeholder behavior:

- `custom_field1` is populated from `operator_name` when available.
- `sub7` is a legacy alias for `operator_name`.
- `secure` / `hash` is generated from the configured signature algorithm/base/secret.
- If a signature exists and the template does not include `{secure}` or `{hash}`, the dispatcher can append the configured signature parameter.

## Landing telemetry, SMS variants, and analytics

- `KIWI_SMS_BODY_VARIANT_EXPERIMENT_ENABLED`
  - enables the SMS-body variant experiment
  - default: `true`
- `KIWI_SMS_BODY_VARIANT_EXPERIMENT_COUNTRIES`
  - country allowlist for the experiment
  - default: `['FR']`
- `KIWI_LANDING_UA_TRACKING_MODE`
  - values: `disabled`, `onclick`, `onload`
  - default: `onload`
  - `onload` increases page-load REST/DB write volume but enables device/OS/browser clustering for non-click sessions
- `KIWI_LANDING_HANDOFF_UA_CLIENT_HINTS_ENABLED`
  - legacy compatibility switch
  - when set to `false` and `KIWI_LANDING_UA_TRACKING_MODE` is unset, maps to `disabled`
- `KIWI_LANDING_FUNNEL_SUMMARY_REFRESH_DAYS`
  - lookback days included in split Main and TK-zone WP-Cron rolling refresh windows in addition to today
  - default: `7`
  - minimum configured value: `0`
  - hourly WP-Cron refreshes apply an effective one-day minimum so post-midnight handoff completions can update yesterday's bucket
- `KIWI_LANDING_FUNNEL_TKZONE_SUMMARY_PIDS`
  - PID allow-list for the TK-zone companion summary
  - accepts an array or comma/whitespace-separated string
  - default: `['106']`
  - values are sanitized, deduplicated, and hashed onto refreshed TK-zone rows so stale allow-list results stay out of current reads
- `KIWI_DEVICE_MODEL_BRAND_HARVEST_MIN_DAILY_SESSIONS`
  - minimum daily distinct-session threshold before the device model harvester records an observed unknown model for review
  - default: `5`
  - minimum: `1`

## Retention worker

Registered source defaults are stored in the `kiwi_retention_settings` option:

- `landing_page_sessions`: `enabled=false`, `dry_run=true`, `retention_days=14`, minimum `7`; Main/TK-zone summary coverage is required.
- `landing_handoff_events`: `enabled=false`, `dry_run=true`, `retention_days=21`, minimum `7`; analytics summary coverage is deliberately not required.
- `landing_session_engagements`: `enabled=false`, `dry_run=true`, `retention_days=14`, minimum `7`; age uses `updated_at` and the WordPress calendar, while its own Main/TK-zone gate uses `created_at`. Live sessions protect the engagement. See `retention-runbook.md` for external read-index preparation and the separate one-time Fraud/Billing and activation approvals; reducing the period requires renewed approval.

Saved per-source settings are not overwritten when defaults change. Every source still uses the shared bounded archive-before-delete worker and must be dry-run validated before separate Production activation.

- `KIWI_RETENTION_ARCHIVE_ROOT`
  - protected filesystem root for SQLite retention archives, per-generation locks, and corruption write-block sentinels
  - keep outside public content and writable only by the deployment account
- `KIWI_RETENTION_ARCHIVE_HEALTH_TIMEOUT_SECONDS`
  - supervision budget for the retained Hostinger `diagnose`/confirmed `unblock` read-only child and its corruption-gate handoff; independent of the VPS phase budgets below
  - default and maximum: `7200` (120 minutes)
  - accepted range: `30` to `7200`
  - existing explicit values such as `600` or `3600` remain explicit; adjust them deliberately during an authorized rollout
  - the supervisor never kills a running child at this boundary; its generation lock remains held until the child exits
  - a timeout is inconclusive and never evidence of corruption
- `KIWI_RETENTION_ARCHIVE_HEALTH_SOURCE_BYTES_PER_SECOND`
  - source-side SSH export read rate; default `4194304` bytes/s (4 MiB/s)
  - positive integer, enforced before each source read; SHA-256 uses the same pass
- `KIWI_RETENTION_ARCHIVE_HEALTH_COPY_SECONDS`
  - source-side SSH export wall-clock budget; default `2700` (45 minutes)
  - positive finite number; also bounds stalled output and computes capacity as source rate multiplied by time
- `KIWI_RETENTION_ARCHIVE_HEALTH_CONTROL_SECONDS`
  - small SSH control-call I/O deadline; default `30` seconds, positive finite number
  - source rate/time constants are trusted local Production configuration; the remote caller cannot supply them
  - synchronize deliberately with the approved VPS `/etc/kiwi-archive-health.conf` values during deployment; never raise limits automatically
  - source export does not change the separate manual diagnosis/recovery native-read-brake configuration
- `KIWI_RETENTION_WORKER_ROW_LIMIT`
  - maximum source rows archived per worker invocation
  - default: `50000`
  - minimum: `1`
- `KIWI_RETENTION_WORKER_TIME_LIMIT_SECONDS`
  - soft per-invocation worker time budget
  - default: `60`
  - minimum: `1`
- `KIWI_RETENTION_WORKER_RESCHEDULE_DELAY_SECONDS`
  - delay before scheduling next worker event after partial progress or lock skip
  - default: `60`
  - minimum: `1`
- `KIWI_RETENTION_WORKER_LOCK_TTL_SECONDS`
  - transient lock TTL for the retention worker hook
  - default: `300`
  - minimum: `60`

## VPS archive-health configuration

The complete [configuration example](../../tools/database/archive-health-vps/kiwi-archive-health.conf.example) is the required schema: unknown, duplicate, missing or wrongly typed keys are rejected. The file is root-owned, mode `0640`, group `kiwi-archive-health`. It is separate from WordPress configuration. Changes are deliberate deployment changes, never automatic limit increases.

| Key | Initial value | Meaning |
|---|---:|---|
| `source_bytes_per_second` | `4194304` | Source read rate used to calculate admissible copy capacity; synchronize with Production's source-rate constant. |
| `copy_seconds` | `2700` | 45-minute source-copy deadline; synchronize with Production's copy constant. |
| `copy_verification_seconds` | `5400` | 90 minutes for copy plus verification of the stored file, including the copy time. |
| `check_seconds` | `900` | Complete read-only SQLite `quick_check` budget. |
| `result_seconds` | `300` | Total result/confirmation/status/one-identical-resend budget. |
| `connect_seconds` | `15` | SSH connection timeout. |
| `control_seconds` | `30` | Each small SSH call and bounded local service-start handoff; synchronize the Production control constant. |
| `cpu_quota_percent` | `50` | Combined maximum of half one CPU core for the entire service. |
| `cpu_weight` | `10` | Relative CPU priority for that same service. |
| `read_bytes_per_second` | `16777216` | Combined read cap on the actual destination device. |
| `write_bytes_per_second` | `8388608` | Combined write cap on that device. |
| `buffer_bytes` | `1073741824` | Additional free space before copying. |
| `reserve_bytes` | `20000000000` | Reserve before copy, stored verification and SQLite. |
| `max_archive_bytes` | `0` | No independent weekly size ceiling. A positive value can narrow the separately authorized functional check to `6000000000` bytes using ordinary settings. |

`ssh_host`, `ssh_user` and `ssh_port` identify the separately verified restricted endpoint; no real values belong in the repository. `identity_file` names the dedicated user's private regular key file (owner `kiwi-archive-health`, mode `0600`); `known_hosts_file` names the independently pinned root-owned regular host-key file (mode `0640`, group `kiwi-archive-health`). Symlinks, hardlinks and unsafe ownership/permissions are rejected. SSH uses only the four fixed protocol actions, an empty client config, pinned identity/host key, batch authentication, no agent, compression, control master, TTY or forwarding, and a sanitized environment.

The defaults allow a mathematical copy capacity of `11324620800` bytes (`4194304 × 2700`), without promising that size finishes in time. There is no 6.5-GB weekly ceiling. Initial free space must cover actual target bytes plus buffer plus reserve; the later two observations require the reserve alone. The configured source limit is also enforced independently by Production.

Systemd cannot substitute this JSON into its resource directives. During an authorized deployment, run the [offline renderer](../../tools/database/archive-health-vps/render_resources.py) as the dedicated user, review its output, and install that output as the service's resource drop-in. The renderer only prints configuration. The prepared unit contains the initial defaults; any unapplied change is refused by the runner's actual cgroup/device readback. Never use a runtime privileged setter or weaken the check. Details are in the [VPS runbook](retention-runbook.md#vps-archive-health-runner).

## Operational events

- `KIWI_OPERATIONAL_EVENTS_RETENTION_DAYS`
  - event age retained by the daily cleanup, based on `created_at`
  - default: `180`
  - accepted range: `1` to `3650`
- `KIWI_OPERATIONAL_EVENTS_CLEANUP_BATCH_SIZE`
  - maximum rows deleted by one cleanup invocation
  - default: `5000`
  - accepted range: `1` to `50000`

See `operational-events-runbook.md` for the daily hook, follow-up worker, lock, and recovery behavior.

## Trusted proxy client-IP resolution

- `KIWI_TRUSTED_PROXY_CIDRS`
  - explicit allowlist for direct reverse proxies whose `X-Forwarded-For`, `Forwarded`, or `X-Real-IP` headers may be trusted
  - accepts an array or comma/whitespace-separated string of exact IPs and CIDRs
  - default: empty, so forwarded client-IP headers are ignored and the direct peer is used
- `KIWI_CLIENT_IP_RESOLUTION_DEBUG`
  - temporary landing-session diagnostics for trusted-proxy rollout
  - stores only supported forwarded header names, unsupported client-IP header names, counts, and resolution reason
  - temporary default: `true` while validating the Hostinger/proxy rollout; set to `false` to disable

Only add proxy IPs or CIDRs controlled by the deployment edge. Do not document real customer IPs.

## Temporary database connection warning diagnostic

This strictly temporary diagnostic adds restricted execution context next to the
existing `mysqli_real_connect(): (HY000/2002): Operation not permitted` warning.
It does not retry a connection, change WordPress, or create database traffic.

- `KIWI_DB_CONNECT_DIAGNOSTICS_ENABLED`
  - explicit opt-in; only the boolean value `true` enables the diagnostic
  - no default; leaving it undefined keeps the diagnostic inactive
- `KIWI_DB_CONNECT_DIAGNOSTICS_EXPIRES_AT_UTC`
  - mandatory UTC expiry in the exact format `YYYY-MM-DDTHH:MM:SSZ`
  - the diagnostic is active only before this instant; at and after it no error handler is registered
- existing PHP error handler
  - if one is already registered, this diagnostic remains inactive; PHP does
    not expose that handler's error-level mask, so replacing or forwarding to
    it could change its behaviour

After review and separate production approval, add the following short-lived
block in production `wp-config.php` immediately before the existing
`require_once ABSPATH . 'wp-settings.php';` line. Set the timestamp to exactly
seven calendar days after activation.

```php
define('KIWI_DB_CONNECT_DIAGNOSTICS_ENABLED', true);
define('KIWI_DB_CONNECT_DIAGNOSTICS_EXPIRES_AT_UTC', 'YYYY-MM-DDTHH:MM:SSZ');

require_once __DIR__ . '/wp-content/plugins/backend/tools/database/diagnostics/class-db-connect-warning-context-diagnostic.php';
Kiwi_Db_Connect_Warning_Context_Diagnostic::register();
```

The diagnostic writes the `[kiwi-db-connect-diagnostic]` prefix and schema-2
JSON with UTC time, PHP process ID, PHP-SAPI, execution context, entry-script
basename from server-controlled `SCRIPT_FILENAME` only, and for normal web
requests a fixed route classification: `site_root`, `wp-login`, `wp-admin`,
`wp-admin-admin-ajax`, `wp-json`, or `other_web_route`.

For the temporary Issue #130 investigation, web entries may additionally carry
`request_method` (a valid HTTP token in uppercase), `request_path`, and
`rest_route`. `request_path` is never an arbitrary raw request path: it is
emitted only for `/`, `/index.php`, or one of the fixed, static backend REST
paths below `/wp-json`. `rest_route` is emitted only for one exact scalar
selector whose route is one of those same fixed backend routes. Unknown,
dynamic, duplicate, mixed scalar/array, empty and array-valued route inputs
are omitted; the fixed `web_route` class remains available for coarse grouping.
The allowlist is `/kiwi-backend/v1/landing-kpi/event`,
`/kiwi-backend/v1/landing-kpi/report`, `/kiwi-backend/v1/nth-callback`, and
`/kiwi-backend/v1/dimoco-callback`.

This is deliberately not a full raw URL: host, scheme, arbitrary path
segments and all other query values remain excluded.

It must not contain IP addresses, headers, request bodies, cookies,
credentials, database contents, CLI arguments, `SCRIPT_NAME`, request-derived
`PHP_SELF`, or query values other than the explicitly allowed `rest_route`.
If `SCRIPT_FILENAME` is unavailable, `entry_script` is `null`. `wp-cron` and
`cli` are classified without their request or command arguments.

This section, the `wp-config.php` block, the helper file, and its tests are a
single temporary unit. After the seven-day observation period, remove them in
this safe order:

1. Remove the two constants and the `require_once`/`register()` block from the
   production `wp-config.php` first; do not deploy the helper deletion yet.
2. Run an approved read-only WordPress bootstrap check and confirm that the
   removed block no longer loads the diagnostic; do not generate a test warning.
3. Only then deploy the dedicated Issue #130 cleanup commit that deletes the
   helper, its tests, and this temporary documentation.
4. Verify that production no longer has the `wp-config.php` block or helper
   file, then close the Issue #130 follow-up checkbox.

## NTH callback observability

- `KIWI_NTH_CALLBACK_LOGGING_ENABLED`
  - enables route-level callback logs
- `KIWI_NTH_CALLBACK_PAYLOAD_LOGGING_ENABLED`
  - includes sanitized callback payload values
  - sensitive keys such as `password`, `secret`, `token`, `digest`, `signature`, and `auth` are redacted

If not defined, callback logging defaults to `KIWI_DEBUG`; payload logging defaults to callback-logging setting.

NTH FR one-off service array policy values:

- `session_validity_hours`
  - pending/correlation window for non-terminal MT attempts
  - default in adapter: `24`
- `completed_sale_cooldown_days`
  - completed one-off sale cooldown before another billed MT attempt for the same subscriber/service/shortcode/keyword
  - default in adapter: `7`
  - `0` disables this cooldown

## Frontend tools auth

To protect internal frontend tool shortcodes with a single config credential, define:

```php
define('KIWI_FRONTEND_AUTH_USERNAME', 'admin');
define('KIWI_FRONTEND_AUTH_PASSWORD_HASH', '$2y$10$REPLACE_WITH_BCRYPT_HASH');
```

Generate the password hash locally:

```bash
php -r "echo password_hash('your-strong-password', PASSWORD_DEFAULT) . PHP_EOL;"
```

Logout mechanism:

- append `?kiwi_frontend_auth_action=logout` to a tool page URL
- optionally add `&kiwi_frontend_auth_redirect=<urlencoded-target>`

Protected by this layer:

- `kiwi_hlr_lookup`
- `kiwi_dimoco_refunder`
- `kiwi_dimoco_blacklister`
- `kiwi_landing_pages_gallery`
- `kiwi_premium_sms_fraud`
- `kiwi_statistics`
- HLR/statistics export triggers

Not protected by this layer:

- landing-page router traffic
- REST callbacks
- WordPress admin

Protected tool responses and login forms send no-cache headers, including `CDN-Cache-Control: no-store` and `X-LiteSpeed-Cache-Control: no-cache`. Protected tool responses also call LiteSpeed's `litespeed_control_set_nocache` hook, and successful auth redirects purge the target URL with `litespeed_purge_url`.

## Premium SMS fraud monitoring

- `KIWI_PREMIUM_SMS_FRAUD_THRESHOLD_1H`
  - soft-flag MO identity when count within 1 hour reaches this value
  - default: `3`
- `KIWI_PREMIUM_SMS_FRAUD_THRESHOLD_24H`
  - soft-flag MO identity when count within 24 hours reaches this value
  - default: `6`
- `KIWI_PREMIUM_SMS_FRAUD_MO_ENGAGEMENT_MODE`
  - values: `observe`, `block`
  - default: `observe`
- `KIWI_PREMIUM_SMS_FRAUD_MO_REQUIRE_PAGE_LOADED`
  - require `page_loaded` landing telemetry for engagement checks
  - default: `true`
- `KIWI_PREMIUM_SMS_FRAUD_MO_REQUIRE_CTA_CLICK`
  - require `cta_click` landing telemetry for engagement checks
  - default: `true`
- `KIWI_PREMIUM_SMS_FRAUD_MO_MIN_SECONDS_AFTER_LOAD`
  - suspicious-speed threshold for `MO occurred_at - page_loaded_at`
  - default: `1`
