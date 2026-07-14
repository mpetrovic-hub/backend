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

- `KIWI_RETENTION_WORKER_ROW_LIMIT`
  - maximum landing-page-session archive rows per worker invocation
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
