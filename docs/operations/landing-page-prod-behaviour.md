# Landing Page Production Behaviour (Operations)

This runbook describes how landing pages work in runtime, which configuration controls them, and how to operate them safely in production.

This file is intentionally broader than migration only. Migration steps remain as a short appendix.

## Runtime model

Landing pages are discovered from `landing-pages/` and resolved by request path and host metadata.

Each page folder must follow the filesystem contract:

- folder format: `lp<version>-<country>`; optional test/variant suffixes can be appended as `lp<version>-<country>-<variant>`
- required files:
  - `index.html`
  - `styles.css`
  - `integration.php`
- `integration.php` links page routing to service/flow/provider docs
- default media asset folder: `https://backend.kiwimobile.de/wp-content/uploads/assets/`
- optional `asset_base_url` can override that default for `./asset.ext` references in `index.html` and `styles.css`
- hero/LCP images should be preloaded with high fetch priority and explicit image dimensions; when small responsive variants exist, the preload and `<img>` should use matching responsive image candidates

Business logic is centralized in plugin services. Landing-page folders are presentation plus metadata only.

## Gallery shortcode

The plugin exposes a landing-page diagnostics/gallery shortcode:

- shortcode: `[kiwi_landing_pages_gallery]`
- source: filesystem landing pages discovered through the shared config/registry path
- card metadata: `country`, `key`, `flow`, `service_key`, provider, routing mode
- auth/cache behavior: when frontend tool auth is enabled, both the gallery login form and authenticated gallery response send `nocache` headers plus `CDN-Cache-Control: no-store` and `X-LiteSpeed-Cache-Control: no-cache`
- URL behavior:
  - when `hostnames` + `backend_path` exist, cards show absolute HTTPS outside URLs as `https://<hostname><backend_path>`
  - when `hostnames` exist but `backend_path` is missing, cards fall back to `https://<hostname><dedicated_path>`
  - when only `backend_path` exists, cards show the backend path strategy and an inferred current-site URL (explicitly labeled as inferred)
  - cards render one primary URL in the preview URL row (the best explicit outside URL when available)

Discovery validation warnings (for broken folders) are surfaced in the shortcode output while valid entries keep rendering.

## Request resolution and rendering

At runtime, the router:

1. Resolves landing page by `backend_path` or dedicated `hostnames` + `dedicated_path`.
2. Creates/reads a landing session token (`kiwi_landing_session` cookie).
3. Captures click attribution when `clickid` is present, stores it server-side, and sets an opaque tracking token cookie (`kiwi_tracking_token`).
4. Builds primary CTA centrally (provider adapter), then injects `{{KIWI_PRIMARY_CTA_HREF}}` in HTML.
5. Renders filesystem HTML and wires `styles.css`.

For filesystem HTML and CSS, local media references such as `./hero.png` are rewritten at render time to `https://backend.kiwimobile.de/wp-content/uploads/assets/hero.png` by default. This applies to direct HTML `src`/`href` attributes, CSS `url(...)` values, and local responsive candidates in `srcset`/`imagesrcset`. If `asset_base_url` is set in `integration.php`, those local asset references resolve under that configured base URL instead. When readable, `styles.css` is inlined into the rendered HTML and its external stylesheet link is suppressed; if it cannot be read, the router falls back to the external stylesheet URL from the landing-page folder.

Keep the preload candidates in sync with the visible hero `<img>` candidates so the browser does not fetch a duplicate LCP image. External, protocol-relative, `data:`, and root-relative `/...` candidates keep their original browser semantics; only `./...` candidates are rewritten to the effective asset base.

Landing engagement telemetry (`page_loaded`, `cta_click`) is sent via the KPI event endpoint and can carry source context (`pid`, `clickid`/`click_id`, `tksource`, `tkzone`) for fraud-linkage snapshots. CTA engagement payloads use `cta_step` (`cta1`, `cta2`, or `cta3`) to persist step-specific per-session click timestamps/counts without reusing the KPI `step` field.

For click-to-SMS CTAs, the same endpoint also records handoff telemetry for `sms:`/`smsto:` links. These events are diagnostic signals only: they indicate that a browser attempted an SMS handoff, hid the page, returned, or did not hide after the click. They do not prove that the SMS was sent and they do not increment KPI summary counters.

Best-effort User-Agent context is controlled by `KIWI_LANDING_UA_TRACKING_MODE`:

- `disabled`: do not collect or persist UA Client Hints for engagement or handoff payloads, even when a client posts those fields manually
- `onclick`: collect UA Client Hints only around CTA/handoff interaction; this preserves the legacy handoff-near behavior
- `onload`: collect UA Client Hints on page load and persist them with the `page_loaded` engagement when available

The legacy `KIWI_LANDING_HANDOFF_UA_CLIENT_HINTS_ENABLED=false` switch maps to `disabled` when the new mode is not set. Otherwise the default is `onload`, so page-load sessions can be clustered by available device context.

For NTH click-to-SMS flows, CTA construction can append the internal `transaction_id` to the SMS body through centralized adapter logic. The FR SMS-body variant experiment can instead render a stable visible token while keeping the internal `txn_...` correlation id unchanged server-side.

## Multi-domain exposure via proxy/CNAME

Landing pages can be exposed on multiple public domains without changing core plugin routing or attribution behavior.

Recommended setup:

- keep each landing page `backend_path` stable and unique (for example `/lp/fr/myjoyplay5`)
- for backend-path-only test variants, leave `hostnames` empty to avoid taking over a dedicated-host root route
- point each public domain/hostname to the same backend WordPress runtime via DNS + reverse proxy/CNAME
- keep `hostnames` metadata populated in `integration.php` for diagnostics visibility and optional dedicated-host routing

Proxy/edge requirements:

- terminate TLS with a valid certificate for each public hostname
- preserve original `Host` and forward standard `X-Forwarded-*` headers
- configure `KIWI_TRUSTED_PROXY_CIDRS` with only the direct reverse-proxy or edge CIDRs whose forwarded headers may be trusted; the default empty configuration ignores forwarded IP headers
- start with exact direct proxy IPs when possible; for example, production exports on 2026-05-31 showed `REMOTE_ADDR=2a02:4780:79:a1e9::1`, so prefer `['2a02:4780:79:a1e9::1']` over broadly trusting `2a02:4780:79::/48` unless the whole range is confirmed as controlled edge infrastructure
- forward request paths unchanged (no path rewrites for landing routes)
- avoid exposing non-canonical backend origin hosts to end users when possible

Tracking/cookie note:

- click/session implementation remains unchanged
- `kiwi_landing_session` and attribution token cookies are host-scoped in current implementation
- attribution works as expected when one user journey stays on one public hostname
- avoid mid-flow redirects between different root domains unless an explicit cross-domain handoff design is introduced

## Conversion and attribution behavior

High-level flow:

1. Landing captures attribution context server-side and stores canonical session dimensions on `wp_kiwi_landing_page_sessions`.
2. Provider callbacks are normalized at provider boundary.
3. Confirmed conversions are resolved against attribution state using stable references (transaction/message/session/external refs).
4. Successful one-off sales are persisted in `wp_kiwi_sales`.
5. After attribution matching, the sale row is enriched with a durable snapshot of service, landing/session, source, device, metric date, and landing-session client-IP context.
6. Affiliate postback dispatch is triggered only for confirmed conversions and only once after `postback_sent_at` is set.
7. When a matching sale exists, outbound postback includes `custom_field1=<operator_name>` sourced from `wp_kiwi_sales.operator_name`.

Important boundary:

- Incoming provider callback validation is provider-specific.
- Outgoing affiliate secret/signature applies only to outbound postbacks.
- Client IP stored on sales must come from the resolved landing-session context in `wp_kiwi_landing_page_sessions`; provider/aggregator callback `REMOTE_ADDR` is not a user-IP source.
- `Kiwi_Client_Ip_Resolver` accepts forwarded headers only when the direct peer matches `KIWI_TRUSTED_PROXY_CIDRS`. Without that explicit trust, `X-Forwarded-For`, `Forwarded`, and `X-Real-IP` are ignored to avoid spoofed client-IP buckets. If a trusted proxy request has no usable forwarded client candidate, the IP bucket remains `(unknown)` instead of bucketing the proxy itself. Temporary diagnostics can be enabled with `KIWI_CLIENT_IP_RESOLUTION_DEBUG`; the debug context stores only header names/counts and resolution reasons, never raw forwarded header values or candidate IPs. It can also report unsupported client-IP header names such as `CF-Connecting-IP` or `True-Client-IP` when present, but those headers are not trusted or parsed by this resolver.
- Traffic and campaign dimensions stored on landing sessions come from landing metadata, service context, query parameters, and `HTTP_ACCEPT_LANGUAGE`; `country` is the campaign/service country, not a Geo-IP lookup.

## Landing-page KPI tracking

The landing-page system supports a generic KPI funnel for optimization analysis.

### Event model

- `clicks`
  - incremented in the central landing-page router when a landing page is rendered
- `cta1`, `cta2`, `cta3`
  - incremented through the KPI event endpoint into one summary row per landing page
- Landing engagement events
  - stored per landing/session for `page_loaded` and `cta_click`
  - `cta_click` can include `cta_step=cta1|cta2|cta3` for step-specific engagement columns
  - do not mutate `wp_kiwi_landing_kpi_summary`; KPI step counters still require a separate `step` payload
- SMS handoff events
  - stored separately for `sms:`/`smsto:` CTA diagnostics
  - supported events: `sms_handoff_attempted`, `sms_handoff_hidden`, `sms_handoff_returned`, `sms_handoff_no_hide`
  - do not mutate `wp_kiwi_landing_kpi_summary`
- SMS body variant events
  - stored separately for the visible SMS-body experiment
  - assignment, CTA1, handoff, and conversion counters are aggregated by landing/service/variant/seed
  - do not mutate `wp_kiwi_landing_kpi_summary`
- `conv`
  - incremented once on first confirmed conversion match in attribution resolver
  - duplicate callbacks do not increment `conv` again once conversion was already confirmed

Storage model:

- `wp_kiwi_landing_kpi_summary`
  - one row per `landing_key`
  - counters: `clicks`, `cta1`, `cta2`, `cta3`, `conv`
  - precomputed rates: `cta1_cr`, `cta2_cr`, `cta3_cr`, `conv_cr`

- `wp_kiwi_landing_handoff_events`
  - one row per landing/session/handoff/event type
  - records SMS handoff diagnostics, including scheme, recipient, body presence, transaction-token presence, elapsed time, visibility state, source snapshots, and optional UA Client Hints

- `wp_kiwi_sms_body_variant_assignments`
  - one row per internal `transaction_id`
  - stores the visible token rendered in the SMS body, variant key, seed, landing/session/source context, and one-time event markers

- `wp_kiwi_sms_body_variant_summary`
  - one row per `landing_key`, `service_key`, `variant_key`, and `seed`
  - counters: `assignments`, `cta1`, `handoff_attempted`, `handoff_hidden`, `handoff_no_hide`, `handoff_returned`, `conv`
  - precomputed rates: `cta1_cr`, `handoff_hidden_cr`, `conv_cr`, `conv_per_cta1_cr`, `conv_per_hidden_cr`

### Per-landing selector mapping in `integration.php`

Each filesystem landing page can map KPI steps to selectors:

```php
'kpi_cta_steps' => [
    'cta1' => 'class="cta"',
    'cta2' => '.mobile_number_input',
    'cta3' => '#confirm-button',
],
```

Notes:
- v1 step keys are limited to `cta1`, `cta2`, and `cta3`
- selector values can be CSS selectors
- shorthand `class="cta"` is normalized to `.cta`
- if no mapping is provided, router defaults to `cta1 => .cta`

### REST endpoints

- `POST /wp-json/kiwi-backend/v1/landing-kpi/event`
  - increments CTA summary counters (`cta1`/`cta2`/`cta3`)
  - records engagement events (`page_loaded`, `cta_click`)
  - accepts `cta_step=cta1|cta2|cta3` only for `cta_click` engagement storage; this is distinct from the KPI `step` field
  - records SMS handoff diagnostics without changing summary counters
- `GET /wp-json/kiwi-backend/v1/landing-kpi/report`
  - returns per-landing KPI rows with counts and rates
  - supports optional filters:
    - `days` (accepted for compatibility; summary output is all-time)
    - `landing_key`

## Key tables and what they mean

- `wp_kiwi_landing_page_sessions`
  - canonical landing-session facts captured by the server-side router
  - stores `provider_key`, `flow_key`, campaign/service `country`, `pid`, `tksource`, `tkzone`, normalized primary `browser_language`, normalized device buckets (`device_brand`, `os`, `os_version`, `browser`), and coarse client-IP buckets (`client_ip_version`, `client_ip_prefix`)
  - stores `pid`, `tksource`, and `tkzone` directly from query parameters even when no `click_id` is present
  - `page_loaded` UA Client Hints can enrich matching session rows without letting `(unknown)` overwrite known device values
  - keeps raw request context separately in `query_params`/`raw_context`; reporting should use the first-class dimension columns

- `wp_kiwi_device_model_brand_map`
  - optional exact model-to-brand map used by the shared device-context normalizer before heuristic brand rules
  - stores normalized `model_key` values with a sanitized `brand`, plus source/notes for auditability
  - default seed rows are installed by schema migration; a daily harvester can add frequently observed unknown `ua_ch_model` keys as `(unknown)` review placeholders when they meet `KIWI_DEVICE_MODEL_BRAND_HARVEST_MIN_DAILY_SESSIONS`
  - `(unknown)` map entries do not stop the normalizer from trying safe built-in heuristics

- `wp_kiwi_click_attributions`
  - temporary server-side attribution state
  - click ID, internal `transaction_id`, refs, postback audit, TTL expiry

- `wp_kiwi_nth_events`
  - normalized inbound/outbound NTH event log with dedupe

- `wp_kiwi_nth_flow_transactions`
  - FR one-off flow transaction lifecycle and external references

- `wp_kiwi_sales`
  - durable confirmed sale records
  - includes `transaction_id`, service/landing/session/source snapshots (`service_key`, `landing_key`, `session_ref`, `click_id`, `pid`, `tksource`, `tkzone`), normalized device buckets (`device_brand`, `os`, `os_version`, `browser`), `attribution_metric_date`, and landing-session IP snapshot fields (`client_ip`, `client_ip_version`, `client_ip_prefix`, `client_ip_hash`)
  - copies `client_ip_version` and `client_ip_prefix` only from stored landing-session buckets; pre-migration sessions with missing or `(unknown)` buckets are not re-derived from legacy `remote_ip`
  - keeps existing provider context in `context_json` and adds `context_json.attribution_snapshot` with source/debug rows used to build the snapshot

- `wp_kiwi_premium_sms_landing_engagements`
  - landing-session engagement evidence (`page_loaded_at`, first/last generic CTA click, generic click count)
  - step-specific CTA evidence (`first_cta1_click_at`, `last_cta1_click_at`, `cta1_click_count`, and the matching CTA2/CTA3 columns)
  - legacy generic CTA columns remain populated for backward compatibility with fraud checks and existing statistics views
  - source snapshots (`pid`, `click_id`, `tksource`, `tkzone`)
  - raw UA context (`ua_ch_*`, `user_agent`) when the UA tracking mode allows it; normalized device/browser buckets are not stored here

- `wp_kiwi_landing_handoff_events`
  - click-to-SMS handoff evidence (`sms_handoff_*`)
  - source snapshots (`pid`, `click_id`, `tksource`, `tkzone`), handoff details (`sms`, `smsto`, recipient/body metadata), and optional UA Client Hints (`platform`, `platformVersion`, `model`, browser brands)

- `wp_kiwi_sms_body_variant_assignments`
  - visible SMS token assignments for the FR click-to-SMS experiment
  - maps non-`txn_` visible tokens back to the internal `transaction_id`

- `wp_kiwi_sms_body_variant_summary`
  - aggregated SMS body variant metrics for SQL-based experiment analysis

- `wp_kiwi_premium_sms_fraud_signals`
  - MO fraud snapshots per subscriber identity, with session refs retained as metadata
  - per-service volume counts, billing outcome/sale/aggregator-status snapshots, soft-flag reasons, source snapshots (`pid`, `click_id`, `tksource`, `tkzone`)

- `wp_kiwi_v_load_to_cta_by_tksource_tkzone`
  - plugin-managed legacy/debug view for the traffic-source funnel report
  - normalizes landing engagement and completed-sale facts by `service_key`, `tksource`, and `tkzone`
  - assigns completed-sale facts to reporting windows by `wp_kiwi_sales.completed_at`
  - prefers durable `wp_kiwi_sales` snapshot fields for completed-sale dimensions and falls back to temporary attribution rows only for legacy sales without snapshots
  - uses `2026-05-12 20:00:00` as the default lower bound for reliable `tksource`/`tkzone` data

- `wp_kiwi_v_one_for_all`
  - plugin-managed analytics view for pivot/export work outside the shortcode UI
  - groups by `landing_key`, `service_key`, `tksource`, `tkzone`, computed `device_brand`, computed `android_version`, and computed `browser`
  - exposes sessions, landing-page loads, page-loaded sessions, CTA sessions/clicks, handoff attempts/successes/fails/rate, hidden-time aggregates, and completed sales
  - computes session-row device/browser dimensions in SQL from raw UA context; `device_brand` only uses known manufacturer rules and sends unknown model strings to `(unknown)`
  - new sale rows also persist matching normalized buckets for durable sale analysis
  - counts completed sales by `wp_kiwi_sales.landing_key/session_ref` first, falling back to attribution joins for legacy rows

- `wp_kiwi_landing_funnel_daily_summary`
  - plugin-managed persistent table for daily funnel aggregates
  - groups by `metric_date`, `landing_key`, `service_key`, `provider_key`, `flow_key`, `country`, `pid`, `tksource`, `device_brand`, `os`, `os_version`, `browser`, `client_ip_version`, and coarse `client_ip_prefix`
  - derives session service/provider/flow/country/source/device dimensions and IP buckets from deduplicated `wp_kiwi_landing_page_sessions`; engagement and handoff rows join directly to those canonical sessions and do not create fallback summary sessions, repair missing session dimensions, or parse user-agent fields in SQL
  - completed-sales rows reuse durable `wp_kiwi_sales` snapshot columns and are included only by `attribution_metric_date`
  - stores IPv4 prefixes as `/24`, IPv6 prefixes as `/48`, and missing or invalid IP values as `(unknown)`; it does not store raw `client_ip` or `client_ip_hash`
  - stores a stable `dimension_hash` with a unique key on `metric_date + dimension_hash`; its basis excludes `tkzone` and includes only the main summary dimensions listed above
  - exposes distinct canonical sessions, page-loaded sessions, CTA1/CTA2/CTA3 session and event counts, handoff attempts/successes/fails/rate, hidden-time min/max, sales, and `sales_amount_minor`
  - counts distinct `landing_key + session_token` sessions from landing-session rows only; engagement-only and handoff-only rows do not create main summary sessions
  - aggregates completed sales from durable `wp_kiwi_sales` snapshot columns, using `attribution_metric_date` only; old records without that snapshot date require repair/backfill before they appear in the main daily summary
  - writes missing supported dimensions to `(unknown)` buckets so attributable rows stay visible
  - is refreshed by bounded date-range recompute: the requested window is internally split into one transaction per `metric_date`, with only that day deleted and reinserted
  - is refreshed hourly by WP-Cron hook `kiwi_landing_funnel_daily_main_summary_refresh`, using transient lock `kiwi_landing_funnel_daily_main_summary_refresh_lock` to prevent concurrent Main runs
  - stores the last non-lock refresh or failure result in WordPress option `kiwi_landing_funnel_daily_main_summary_refresh_last_result`; lock skips are stored separately in `kiwi_landing_funnel_daily_main_summary_refresh_lock_skip_last_result` so a stale/concurrent lock does not move the refresh cursor. Results include the processed `metric_date`, rolling-window bounds, counts, and compact `daily_results` entries for per-day diagnostics
  - reports failing chunks with the metric date and step, for example `2026-05-23 delete: ...` or `2026-05-23 insert aggregate rows: ...`
  - keeps same-session cross-midnight handoff attempts and hidden/success events together by scanning next-day handoff rows, joining events at or after the canonical first landing, and rejecting cross-midnight reused-token events when a later landing day owns the handoff
  - does not run an automatic historical raw-source full backfill when the hash/dimension contract changes; the slim main-summary schema migration consolidates existing rows by the new main dimensions before removing retired columns, and keeps those columns if consolidation fails so the rollup can be retried
  - uses composite indexes on the raw landing-session, engagement, handoff, and sales snapshot tables to keep day chunks inside production database limits
  - is the primary read source for the protected `[kiwi_statistics]` shortcode and its CSV export; raw-table cleanup still remains separate

- `wp_kiwi_landing_funnel_daily_tkzone_summary`
  - plugin-managed companion table for daily zone analysis
  - keeps `tkzone` out of the broad main summary while preserving zone-level sessions, CTA, handoff, and sales metrics for diagnostics and optimization work
  - stores a hash of the configured TK-zone PID allow-list on refreshed rows; normal reads and filter options only use rows whose hash matches the current configuration, so rows built for older allow-lists are not mixed into current TK-zone reporting
  - is refreshed hourly by separate WP-Cron hook `kiwi_landing_funnel_daily_tkzone_summary_refresh`, using transient lock `kiwi_landing_funnel_daily_tkzone_summary_refresh_lock`, non-lock result option `kiwi_landing_funnel_daily_tkzone_summary_refresh_last_result`, and lock-skip option `kiwi_landing_funnel_daily_tkzone_summary_refresh_lock_skip_last_result`
  - the legacy combined hook `kiwi_landing_funnel_daily_summary_refresh` is cleared during bootstrap; do not re-enable it for normal production refreshes

## Landing raw retention coverage gate

The `landing_page_sessions` retention cleanup uses a fail-closed coverage gate before archive/delete work starts. The gate checks raw candidate days chronologically and compares date-bounded light totals against the durable Main and TK-zone daily summaries instead of rebuilding the full historical summary contract in one query.

Gate statuses:

- `passed`: every raw candidate day before the requested cutoff is covered; cleanup may use the requested cutoff.
- `partial`: at least one contiguous early date range is covered or explicitly accepted, but a later date has a hard blocker; cleanup may only use `effective_cutoff_value`, the start of the day after the last verified date.
- `failed`: no safe cleanup range exists or a query/schema/summary read failed; cleanup must not archive or delete rows.

Hard blockers are exact mismatches for canonical sessions, page-loaded sessions, handoff attempts/successes/fails, and hidden-time min/max where applicable. CTA session/click mismatches are tolerated only up to `max(5 events, 0.1%)`; larger CTA diffs block the affected date. Sales and sales amount diffs are warning-only for this source because confirmed sales live in `wp_kiwi_sales` and are not deleted by landing-session raw cleanup.

The gate intentionally does not run the expensive dimension-level deep compare for every non-accepted candidate date. That is the production safety/performance contract for raw-session retention: hard light totals run for every candidate date, while deep compare runs only for the current retention edge date, the first hard-blocked date for diagnosis, and at most two CTA-warning dates. Sales-only warning dates are not deep-checked because `wp_kiwi_sales` is not deleted by this cleanup. Older dates with exact hard totals can therefore be listed as totals-only by design.

Audit details are stored on `wp_kiwi_retention_cleanup_runs.gate_results_json`, including `coverage_mode`, requested/effective cutoffs, verified date, candidate dates, deep-checked dates, totals-only dates, skipped deep dates, deep-compare reasons, blocked dates, warning dates, and compact per-summary details. Final cleanup run rows and growth snapshots use the effective cleanup cutoff actually used for archive/delete. TK-zone light totals restrict handoff aggregation to the candidate PID-allow-listed landing sessions before joining handoff events, avoiding broad two-day handoff materialization for unrelated traffic.

When cleanup proceeds, the daily retention cron is only a scheduler. The active recurring hook is `kiwi_retention_cleanup_scheduler_daily`; the legacy unbounded `kiwi_retention_cleanup_daily` hook is cleared during normal scheduling. The scheduler runs the coverage gate, captures the `before_cleanup` growth snapshot, freezes `target_max_primary_key` for rows with `created_at < cutoff_value`, writes a pending run to `wp_kiwi_retention_cleanup_runs`, and schedules the single-event worker hook `kiwi_retention_cleanup_worker`. It does not archive or delete the full backlog in the daily cron request.

Worker state is stored on `wp_kiwi_retention_cleanup_runs` with `worker_phase`, `target_max_primary_key`, `archive_last_primary_key`, `delete_last_primary_key`, `worker_runs`, `worker_last_started_at`, and `worker_last_finished_at`. Active runs use `pending`, `running`, `partial`, `completed`, or `failed` statuses so operators can distinguish a queued worker from partial progress and terminal failures. If a scheduler run sees an existing open worker run for `landing_page_sessions`, it does not create a second cleanup run; it reschedules the worker and records that the active run was rescheduled.

The worker archives at most the configured row/time budget per invocation, defaulting to `50,000` rows or `60` seconds. It reads only `created_at < cutoff_value`, `id > archive_last_primary_key`, and `id <= target_max_primary_key`, ordered by primary key. Later old imports below the same cutoff but above the frozen target are intentionally left for a later gated run. The first worker chunk's `archive_db_path` remains the archive file of record for all resumed chunks in the same cleanup run. After each archive chunk, SQLite `quick_check` must return `ok` before any MySQL delete is attempted.

Delete remains bound to archive evidence. Each chunk writes archive rows and `archive_batch_rows` in one SQLite transaction without clearing prior `archive_batch_rows` for the same `archive_batch_id`; only the primary keys returned for that archived chunk are deleted from MySQL. The worker persists progress after every successful chunk and schedules the next single event after the configured delay when more rows remain. On archive, quick-check, delete, final `integrity_check`, or audit persistence failure, the run is marked `failed` and no automatic retry counter advances destructive work. A lock-active worker invocation is not a failure; it does no work and reschedules.

After the final chunk, the worker runs SQLite `integrity_check`, captures the `after_cleanup` snapshot, and marks the run `completed`. The SQLite writer keeps archive rows and `archive_batch_rows` in one transaction and reuses prepared insert statements across the batch, so the archive remains idempotent by source primary key without paying per-row autocommit overhead.

## Landing-session raw-context compaction

Old `wp_kiwi_landing_page_sessions.raw_context` rows can be compacted before they reach retention archive/delete age. This reduces future SQLite archive size because the retention archive keeps copying the existing source `raw_context` column; existing archive files are not rewritten.

Runtime state:

- settings option: `kiwi_landing_session_raw_context_compaction_settings`
- last-result option: `kiwi_landing_session_raw_context_compaction_last_result`
- daily scheduler hook: `kiwi_landing_session_raw_context_compaction_daily`
- worker hook: `kiwi_landing_session_raw_context_compaction_worker`
- transient lock: `kiwi_landing_session_raw_context_compaction_lock`

Default settings are safe for deployment: `enabled=false`, `dry_run=true`, `age_days=7`, `row_limit=20000`, `time_limit_seconds=60`, `reschedule_delay_seconds=60`, and `lock_ttl_seconds=300`. `age_days` is clamped to at least `3` complete days and at most the configured `landing_page_sessions` retention age, so compaction remains older than fresh operational evidence and still happens before normal retention eligibility.

The compact JSON schema is versioned with:

```json
{
  "schema": "landing_session_raw_context_compact_v1",
  "landing_page": {},
  "client_ip_resolution": {}
}
```

Only these `landing_page` fields are retained: `key`, `country`, `flow`, `provider`, `locale`, `service_type`, `business_number`, `keyword`, `service_key`, `shortcode`, `price_label`, `kpi_cta_steps`, `render_mode`, `folder_name`, and `cta_href`.

Only these `client_ip_resolution` fields are retained: `source`, `peer_trusted`, `trusted_proxy_configured`, `forwarded_headers_present`, `other_client_ip_headers_present`, `forwarded_candidate_count`, and `resolution_reason`.

The worker uses a temporary table and set-based `INSERT ... SELECT` plus `UPDATE ... JOIN` instead of PHP row-by-row JSON rewriting. It skips and counts empty `raw_context`, invalid JSON, and rows already carrying `schema=landing_session_raw_context_compact_v1`. The last result records success, dry-run state, cutoff, age, row/time limits, eligible and processed counts, skip counts, before/after byte estimates, saved bytes, lock skips, remaining-work flag, and error details.

Activation procedure:

1. Keep `enabled=false` until the dry-run result is reviewed.
2. Set `enabled=true` while leaving `dry_run=true`; trigger `kiwi_landing_session_raw_context_compaction_worker` and review `eligible_rows`, `bytes_before`, `bytes_after`, and `saving_bytes`.
3. For a controlled active run, set `dry_run=false`, use the default `row_limit=20000`, and validate a sample of older rows plus newer rows that must remain unchanged.
4. Return to dry-run or disabled if the compact evidence is not acceptable.

Production sandbox measurements from planning showed about `59.8%` logical `raw_context` byte savings on the `2026-07-02` sample and about `59.7%` on the then-current eligible backlog. This does not promise immediate physical MySQL file shrink: InnoDB may only reuse freed space internally unless a separate maintenance plan such as `OPTIMIZE TABLE` is explicitly approved.

## Configuration switches

### Landing-page loading

- `KIWI_LANDING_PAGES_ROOT`
  - optional filesystem root override (default: `<plugin-root>/landing-pages`)

- `KIWI_LANDING_PAGES_FILESYSTEM_ENABLED`
  - enable filesystem discovery (default: `true`)

- `KIWI_LANDING_PAGES_LEGACY_FALLBACK_ENABLED`
  - allow legacy `KIWI_LANDING_PAGES` fallback when key is missing in filesystem registry (default: `false`)
  - set to `true` only as a temporary rollback/migration switch while investigating a missing filesystem route

### Attribution and postbacks

- `KIWI_CLICK_ATTRIBUTION_COOKIE_NAME`
- `KIWI_CLICK_ATTRIBUTION_CLICK_ID_KEYS`
- `KIWI_CLICK_ATTRIBUTION_TTL_SECONDS`
- `KIWI_CLICK_ATTRIBUTION_CLEANUP_LIMIT`
- `KIWI_SMS_BODY_VARIANT_EXPERIMENT_ENABLED`
  - enables the SMS-body variant experiment (default: `true`)
- `KIWI_SMS_BODY_VARIANT_EXPERIMENT_COUNTRIES`
  - country allowlist for the experiment (default: `['FR']`)
- `KIWI_LANDING_UA_TRACKING_MODE`
  - generic UA tracking mode for landing telemetry: `disabled`, `onclick`, or `onload` (default: `onload`)
  - `onload` increases page-load REST/DB write volume but enables device/OS/browser clustering for non-click sessions
- `KIWI_LANDING_HANDOFF_UA_CLIENT_HINTS_ENABLED`
  - legacy compatibility switch; when set to `false` and `KIWI_LANDING_UA_TRACKING_MODE` is unset, it maps to `disabled`
- `KIWI_LANDING_FUNNEL_SUMMARY_REFRESH_DAYS`
  - number of lookback days recalculated by the daily summary rolling refresh in addition to today (default: `7`, minimum: `0`)
  - the configured range remains valid for operations, but each split WP-Cron invocation processes only one due metric date to avoid one large multi-day request
  - hourly WP-Cron refreshes apply an effective one-day minimum even when this is configured as `0`, so post-midnight hidden/success handoff events can update yesterday's first-handoff bucket
- `KIWI_RETENTION_WORKER_ROW_LIMIT`
  - maximum landing-page-session archive rows processed by one retention worker invocation (default: `50000`, minimum: `1`)
- `KIWI_RETENTION_WORKER_TIME_LIMIT_SECONDS`
  - soft per-invocation worker time budget checked while writing archive rows (default: `60`, minimum: `1`)
- `KIWI_RETENTION_WORKER_RESCHEDULE_DELAY_SECONDS`
  - delay before scheduling the next worker event after partial progress or lock skip (default: `60`, minimum: `1`)
- `KIWI_RETENTION_WORKER_LOCK_TTL_SECONDS`
  - transient lock TTL for the retention worker hook (default: `300`, minimum: `60`)
- `KIWI_TRUSTED_PROXY_CIDRS`
  - explicit allowlist for direct reverse proxies whose forwarded client-IP headers may be trusted
  - accepts an array or comma/whitespace-separated string of exact IPs and CIDRs
  - default: empty, which means forwarded IP headers are ignored
  - for the observed Hostinger edge peer, start with `['2a02:4780:79:a1e9::1']` and widen only after infrastructure confirmation
- `KIWI_CLIENT_IP_RESOLUTION_DEBUG`
  - temporary landing-session diagnostics for trusted-proxy rollout
  - temporary default: `true` while validating the Hostinger/proxy rollout; set the constant to `false` to disable
  - when enabled, `raw_context.client_ip_resolution` includes trusted-proxy config presence, supported forwarded header names, unsupported client-IP header names, candidate count, and resolution reason
  - remove the temporary default-on behavior after rollout; it is intentionally data-sparse but still operational debug metadata
- `KIWI_AFFILIATE_POSTBACK_URL_TEMPLATE`
- `KIWI_AFFILIATE_POSTBACK_SECRET`
- `KIWI_AFFILIATE_POSTBACK_SIGNATURE_PARAMETER`
- `KIWI_AFFILIATE_POSTBACK_SIGNATURE_ALGORITHM`
- `KIWI_AFFILIATE_POSTBACK_SIGNATURE_BASE`

### NTH callback observability

- `KIWI_NTH_CALLBACK_LOGGING_ENABLED`
- `KIWI_NTH_CALLBACK_PAYLOAD_LOGGING_ENABLED`

## Operational checks

When validating a landing-page flow in production or staging, verify:

1. Routing resolves expected landing key for both backend path and dedicated hostname path.
2. CTA contains expected keyword and transaction token behavior.
3. `clickid` capture creates/updates one row in `wp_kiwi_click_attributions` for the active tracking token.
4. NTH callback logs show `incoming` and `handled` traces.
5. Confirmed `deliverReport` results in sale persistence (`wp_kiwi_sales`).
6. Affiliate postback is sent once for confirmed conversions and retried only when `postback_sent_at` is empty.
7. `backend_path` routes resolve correctly on every public hostname that proxies to the backend runtime.
8. User journey stays on one public hostname and does not redirect to a backend origin hostname.
9. Fraud tool (`[kiwi_premium_sms_fraud]`) shows expected MO/engagement rows, source fields (`pid`, `click_id`, `tksource`, `tkzone`), and engagement delta (`Load -> First CTA`) where both timestamps exist.
10. UA tracking mode behaves as configured: `disabled` stores no UA context, `onclick` stores it only near CTA/handoff events, and `onload` stores it on `page_loaded` when browser hints are available.
11. Create a landing-session sample with `pid`, `tksource`, `tkzone`, and a non-default `Accept-Language`; verify `wp_kiwi_landing_page_sessions` stores provider, flow, country, the source parameters, and normalized `browser_language` even without `click_id`.
12. Create a landing-session sample without source parameters and with an invalid/empty `Accept-Language`; verify source columns stay empty while `browser_language` stores `(unknown)`.
13. Statistics tool (`[kiwi_statistics]`) reads `wp_kiwi_landing_funnel_daily_summary`, defaults to `2026-05-12`, supports date, service, landing, TK-source, device-brand, OS, OS-version, and browser dropdown filters, and exports rows to CSV without `tkzone`, hidden-time median, raw `client_ip`, or `client_ip_hash`. IP-version and IP-prefix remain coarse output columns but are not normal dropdown filters. The separate `wp_kiwi_landing_funnel_daily_tkzone_summary` table and legacy `wp_kiwi_v_load_to_cta_by_tksource_tkzone` view should still exist for zone/debug analysis.
14. CTA1/CTA2/CTA3 engagement columns increase only for matching `cta_step` payloads while legacy `cta_click_count` still increases for every valid `cta_click`.
15. `wp_kiwi_v_one_for_all` can be queried/pivoted by `device_brand`, `android_version`, `browser`, `tksource`, and `tkzone`; completed sales should still count when their durable `landing_key/session_ref` snapshot is present.
16. Confirm WP-Cron has scheduled `kiwi_landing_funnel_daily_main_summary_refresh`, `kiwi_landing_funnel_daily_tkzone_summary_refresh`, and `kiwi_retention_cleanup_scheduler_daily`; the legacy `kiwi_landing_funnel_daily_summary_refresh` and `kiwi_retention_cleanup_daily` hooks should not remain scheduled. After a gated retention scheduler run, confirm `kiwi_retention_cleanup_worker` is scheduled as a single event and `wp_kiwi_retention_cleanup_runs` shows `pending` or `partial` worker state with a frozen `target_max_primary_key`. Manually trigger the split summary hooks in staging with `KIWI_LANDING_FUNNEL_SUMMARY_REFRESH_DAYS=0`, then with the default lookback, and verify each invocation processes one metric date. With `0`, the stored rolling-window bounds should still show yesterday through today as the effective carryover window.
17. Check `kiwi_landing_funnel_daily_main_summary_refresh_last_result` and `kiwi_landing_funnel_daily_tkzone_summary_refresh_last_result` after success and failure cases, then check `kiwi_landing_funnel_daily_main_summary_refresh_lock_skip_last_result` and `kiwi_landing_funnel_daily_tkzone_summary_refresh_lock_skip_last_result` after simulated lock cases; errors should also be visible through `[kiwi-landing-main-summary-refresh]` and `[kiwi-landing-tkzone-summary-refresh]` log prefixes. If WordPress does not expose a concrete database error, the refresh result should still include a fallback prepare/query diagnostic naming the failed metric date and summary step.
18. For a controlled date range, run the landing funnel daily summary refresh and compare `wp_kiwi_landing_funnel_daily_summary` against raw landing/session, engagement, handoff, and sales rows. Include engagement-only and handoff-only rows without a matching landing session; they must not create main summary sessions. Include sessions whose landing rows have missing provider/source/device dimensions but whose engagement or handoff rows contain them; the summary should keep those session dimensions in `(unknown)` instead of repairing from later event rows. Include sales without `attribution_metric_date`; they should not appear in the main daily summary until repaired/backfilled. Include IPv4, IPv6, and missing/invalid-IP samples to confirm `client_ip_version` and `/24` or `/48` `client_ip_prefix` buckets came from stored landing-session columns. A second refresh for the same date range should keep row counts and totals unchanged, and normal success logs should not contain large SQL dumps.
19. If the gallery/statistics tools are auth-protected, verify the response still carries the no-cache headers through CDN/LiteSpeed or any reverse proxy layer.
20. For a test sale with attribution, verify `wp_kiwi_sales.client_ip` equals the landing-session IP and not the provider callback source IP, and verify `client_ip_version`/`client_ip_prefix` were copied from the landing-session buckets. A pre-migration landing session with only `remote_ip` and no stored buckets should keep sales IP dimensions in `(unknown)`. Prefer `client_ip_prefix`/`client_ip_hash` for broad analysis, and use the daily summary/Statistics export when only coarse `client_ip_version` and `client_ip_prefix` reporting is needed.
21. Test client-IP resolution with and without trusted proxy configuration: without `KIWI_TRUSTED_PROXY_CIDRS`, spoofed forwarded headers must be ignored; with a trusted direct peer, the forwarded chain should resolve to the first non-trusted client candidate.

## Troubleshooting quick map

- Callback rejected with `service_key_unresolved`
  - usually missing/wrong `service_key` and no unique shortcode+keyword match

- No sale in `wp_kiwi_sales`
  - confirm `deliverReport` status mapping is terminal success for your payload
  - confirm references correlate to an existing flow transaction

- Missing transaction linkage
  - verify MO content carries expected `txn_...` token and parser delimiters
  - verify submit flow stores and reuses provider references consistently

- Duplicate callback confusion
  - dedupe in events is expected
  - conversion path can re-attempt postback only while `postback_sent_at` is empty

## Appendix: legacy retirement and rollback

The legacy `KIWI_LANDING_PAGES` fallback is in retirement. Phase 1 keeps the code path available but disables it by default. Phase 2 should remove the legacy template loading path and `templates/landing-pages` code only after staging confirms there are no remaining runtime-only legacy routes.

### Staging verification before Phase 2

1. Open active filesystem landing routes including `lp4-fr`, `lp5-fr`, `lp5-fr-v2`, `lp6-fr`, and `lp6-fr-v2` through their backend paths.
2. Verify any public hostname plus backend-path routing used in production.
3. Confirm CTA output, SMS target/body, price disclosure, and local asset rendering.
4. Check WordPress debug logs for missing landing keys, missing templates, warnings, or errors.
5. Confirm no required route depends on `KIWI_LANDING_PAGES` or `templates/landing-pages/<template>.php`.

### Temporary rollback

If staging or production shows an unmigrated legacy-only route, temporarily define:

```php
define('KIWI_LANDING_PAGES_LEGACY_FALLBACK_ENABLED', true);
```

Use this only to restore service while the route is migrated into a filesystem folder. Remove the flag again after the filesystem route is verified.

### Migrating a discovered legacy route

Use only if a remaining legacy `KIWI_LANDING_PAGES` entry is found.

1. Create filesystem folder `landing-pages/lp<version>-<country>/`.
2. Add `index.html`, `styles.css`, `integration.php`.
3. Set routing metadata in `integration.php` (`backend_path`, optional `hostnames`/`dedicated_path`).
4. Temporarily deploy with `KIWI_LANDING_PAGES_LEGACY_FALLBACK_ENABLED=true` only if rollback coverage is needed during migration.
5. Verify parity.
6. Remove migrated key from `KIWI_LANDING_PAGES`.
7. Repeat until no legacy keys remain.
8. Remove the temporary fallback flag so default-off behavior is restored.

In debug mode (`KIWI_DEBUG=true`), invalid landing pages fail loudly. In production, invalid entries are skipped and logged.
