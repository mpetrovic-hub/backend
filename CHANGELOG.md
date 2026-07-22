# Changelog

Changes are listed by date (newest first). Only medium-impact or higher updates are included.

2026-07-22:
- [Database Deployments] Removed automatic schema and historical one-time migrations from normal WordPress runtime. Added an explicit WP-CLI `status`/`apply` deployment gate with real schema postconditions, legacy-structure blocking, exclusive apply locking, verified static seeds, and schema-version persistence only after complete success.

2026-07-20:
- [Operational Events] Added the append-only `wp_kiwi_operational_events` foundation with centralized credential masking, incident lifecycle/correlation, idempotent writes, bounded reads, Retention stale/recovery events, and a daily batched 180-day cleanup.

2026-07-15:
- [DB Retention Cleanup] Added 30-minute stale-run detection and job-boundary audit heartbeats for scheduler/worker phases. Before-cleanup snapshot failures now fail closed; after-cleanup snapshot failures remain visible as completed-run warnings after successful archive/delete work.

2026-07-13:
- [Landing Pages] Completed the filesystem-only landing-page migration: removed retired configuration/template fallbacks, kept the registry-root override, and updated runtime documentation and regression coverage. Rollback is now deployment-based.

2026-07-07:
- [Auth Cache] Extended the frontend tool auth gate to mark protected tool responses as LiteSpeed no-cache via `litespeed_control_set_nocache` and purge auth redirect targets with `litespeed_purge_url`, preventing stale cached login pages from persisting after timeout re-login.

2026-07-06:
- [Landing Analytics] Added a safe default-off/dry-run worker for compacting old `wp_kiwi_landing_page_sessions.raw_context` rows into `landing_session_raw_context_compact_v1`. The worker uses bounded set-based SQL, persists its last result in `wp_options`, and leaves existing SQLite retention archives untouched.

2026-07-02:
- [DB Retention Cleanup] Split landing-page-session retention cleanup into a bounded scheduler/worker flow. The daily scheduler now freezes a gated `target_max_primary_key`, writes pending worker state to `wp_kiwi_retention_cleanup_runs`, and schedules `kiwi_retention_cleanup_worker`; the worker archives/deletes chunks by archived primary-key evidence, resumes by persisted cursors, and records partial/completed/failed worker phases.
- [DB Retention Coverage Gate] Made the landing-page-session coverage gate use the intended selective deep-compare policy: hard light totals still run for every candidate date, while dimension deep compares are limited to the retention edge, the first hard blocker, and up to two CTA-warning dates. TK-zone light totals now restrict handoff aggregation to the current PID allow-list's landing sessions before joining handoff events, keeping the read-only production gate path well below cron timeout limits.
- [DB Retention Archive] Optimized SQLite archiving for landing-page-session retention by wrapping archive-row and archive-batch-row inserts in one transaction, reusing prepared statements, and using a single batch archive timestamp. Production-like measurement against the 135,322-row backlog cut the archive step from roughly 112.6 seconds to roughly 24.1 seconds without weakening archive-before-delete.

2026-06-30:
- [DB Retention Coverage Gate] Reworked the landing-page-session retention coverage gate to use date-bounded Main/TK-zone checks with `passed`, `partial`, and `failed` outcomes. Partial coverage now archives/deletes only up to the effective verified cutoff, persists requested/effective diagnostics in `gate_results_json`, and treats small CTA diffs plus sales diffs according to the documented warning policy.

2026-06-29:
- [Landing Funnel Daily Summary Refresh] Split the combined landing-funnel daily summary cron into independent Main and TK-zone refresh jobs with separate hooks, locks, last-result options, and log prefixes. The legacy combined hook is unscheduled during normal bootstrap.
- [Landing Funnel Daily Summary Refresh] Optimized the Main summary handoff aggregation by joining handoff events directly to canonical landing sessions with cross-midnight latest-landing anti-join protection, while keeping sales facts on durable `attribution_metric_date` snapshots and preserving the retention coverage gate's fail-closed comparison contract.

2026-06-17:
- [DB Retention Audit] Added a read-only production database size, growth, dry-run retention, and summary-coverage report for the cleanup planning sequence. The report identifies raw landing analytics as the main storage pressure, recommends a 14-day storage-pressure window for landing raw tables, confirms no click-attribution TTL backlog, and documents summary coverage gaps that must block later cleanup until resolved or accepted.

2026-06-08:
- [Premium SMS Fraud / NTH FR One-off] Replaced the old blanket MO-based 24h duplicate block with a pending-MT guard plus configurable `completed_sale_cooldown_days` after completed one-off sales. Terminal failed MT reports such as NTH `-9 Delivery failed` can now retry, while fraud snapshots are subscriber-only and include billing outcome, sale correlation, and normalized aggregator status fields.

2026-06-01:
- [Landing Device Dimensions] Seeded the exact device model-to-brand map from the UA device research notes, added a daily observed-unknown model harvester with a configurable distinct-session threshold, and cached per-request exact model lookups so repeated model keys avoid duplicate database reads. Observed `(unknown)` mappings remain review placeholders and do not block safe normalizer heuristics.
- [Landing Funnel Daily Tkzone Summary Refresh] Changed the tkzone daily summary refresh to join engagement rows directly by canonical `landing_key` and `session_token` instead of materializing an `engagement_sessions` CTE. Production read-only timing on a large day dropped from roughly 109 seconds to roughly 3 seconds with identical aggregate totals. Tkzone refreshes now also use the `KIWI_LANDING_FUNNEL_TKZONE_SUMMARY_PIDS` allow-list, defaulting to pid `106`, so affiliate traffic without zones is excluded by design.

2026-05-31:
- [Landing Funnel Daily Summary Refresh] Changed the main daily summary refresh to join engagement rows directly by canonical `landing_key` and `session_token` instead of materializing an `engagement_sessions` CTE. On production-sized day chunks this lets MariaDB use the existing landing-session engagement lookup and avoids the slow derived-table join that could exceed `max_statement_time`.

2026-05-30:
- [Landing Funnel Daily Summary] Slimmed the main `wp_kiwi_landing_funnel_daily_summary` contract around canonical landing-session facts. The main summary no longer stores or filters by `tkzone`, no longer calculates `median_hidden_seconds`, counts handoff metrics with the handoff event uniqueness contract, assigns next-day handoff carryover to the latest matching landing-session day before the handoff event, and includes completed sales only through `wp_kiwi_sales.attribution_metric_date`; schema migration rolls existing main-summary rows up to the new slim `dimension_hash` basis before dropping retired columns, and leaves those columns in place if consolidation fails so the rollup can be retried safely.
- [Landing Analytics] Added a trusted-proxy client-IP resolver controlled by `KIWI_TRUSTED_PROXY_CIDRS`. Landing sessions now persist coarse `client_ip_version` and `client_ip_prefix` buckets from the resolved client IP, sales snapshots copy only those stored buckets, and daily summary refreshes read stored session buckets instead of parsing raw IPs in SQL. Trusted proxy requests without a usable forwarded client and legacy sessions without stored buckets remain `(unknown)` for sales and summary IP dimensions. The Statistics table and CSV still expose only coarse buckets, while normal IP dropdown filters were removed.
- [Landing Analytics] Added temporary `KIWI_CLIENT_IP_RESOLUTION_DEBUG` diagnostics for trusted-proxy rollout. During rollout this is enabled by default and can be disabled with `KIWI_CLIENT_IP_RESOLUTION_DEBUG=false`; landing-session raw context records only safe metadata such as supported/unsupported client-IP header names, candidate counts, proxy-trust state, and resolution reason. Raw forwarded header values and candidate IPs are not stored.

2026-05-29:
- [Landing Device Dimensions] Added a shared device-context normalizer plus exact model-to-brand map table for landing analytics. Landing sessions, sales snapshots, daily summary rows, Statistics filters, and CSV export now use normalized `device_brand`, `os`, `os_version`, and `browser` buckets instead of the legacy `android_version` summary dimension.
- [Landing Analytics] Added canonical landing-session dimensions on `wp_kiwi_landing_page_sessions` for provider, flow, country, `pid`, `tksource`, `tkzone`, and normalized `browser_language`. Landing-session rows now preserve source parameters even without `click_id`, and the daily summary uses those session columns instead of repairing dimensions from engagement or handoff events.

2026-05-28:
- [Landing Funnel Daily Summary] Added coarse IP dimensions to `wp_kiwi_landing_funnel_daily_summary`: `client_ip_version` and `/24` or `/48` `client_ip_prefix` buckets are now part of aggregation, filtering, Statistics UI, and CSV export while raw `client_ip` and `client_ip_hash` remain excluded from summary reporting.

2026-05-27:
- [Landing Funnel Daily Summary Refresh] Changed the production refresh from one multi-day aggregate query to per-day chunks with per-day transactions, date+step failure diagnostics, source-table composite indexes, and index-friendly completed-sales date filters. Handoff grouping keeps adjacent cross-midnight attempted/hidden events attributed to the first handoff date, and hourly refreshes always include a prior-day carryover even when the configured lookback is zero. The default seven-day rolling window remains supported.
- [Landing Funnel Daily Summary Refresh] Fixed prepared SQL wildcard escaping in the daily summary aggregation refresh so device/browser LIKE patterns no longer break `$wpdb->prepare()`. Empty database error details during refresh delete/insert failures now persist a diagnostic fallback error for operations.

2026-05-26:
- [Statistics Daily Summary Read Path] Switched the protected Statistics UI and CSV export to read from `wp_kiwi_landing_funnel_daily_summary`, adding date, landing/source/device filters plus CTA1/CTA2/CTA3, handoff, and sales summary columns while keeping legacy statistics views available for debug. Added summary-table indexes for the device/browser filters used by the new read path.
- [Landing Funnel Daily Summary Refresh] Added an hourly WP-Cron rolling refresh for the daily funnel summary with a default seven-day lookback plus today, transient locking, persisted last-run status, and visible success/failure logging.
- [Landing Funnel Daily Summary] Added the schema-managed `wp_kiwi_landing_funnel_daily_summary` table plus a bounded recompute service for daily landing/source/device funnel aggregates. The summary counts distinct landing/session traffic with engagement fallback, step-specific CTA1/CTA2/CTA3 engagement, handoff diagnostics, and completed sales from durable `wp_kiwi_sales` snapshots with `(unknown)` buckets for missing dimensions.

2026-05-22:
- [Landing Analytics] Added additive CTA1/CTA2/CTA3 engagement columns and `cta_step` capture so per-session landing engagement can separate multi-step CTA clicks while continuing to maintain the legacy generic CTA fields for existing fraud and statistics consumers.
- [Sales Attribution Snapshot] Added durable `wp_kiwi_sales` attribution snapshots for confirmed sales, including service/landing/session/source fields, normalized device dimensions, metric date, and landing-session client-IP prefix/hash context. Statistics views now prefer sales snapshot dimensions and only fall back to temporary attribution rows for legacy records.

2026-05-21:
- [Affise Operator] Switched Affise operator reporting from `sub7` to `custom_field1`, mapping the normalized `operator_name` into templates or appending it when absent. Updated attribution/postback documentation, environment guidance, and regression coverage for the dispatcher and persisted-sale enrichment flow.

2026-05-20:
- [Landing Analytics] Added a generic landing UA tracking mode (`disabled`, `onclick`, `onload`), engagement-table UA Client Hints persistence, and a plugin-managed `kiwi_v_one_for_all` analytics view for device/source funnel pivots. Follow-up changes made `onload` the default mode so page-load sessions can be clustered by available device context.

2026-05-19:
- [Statistics UI] Improved the protected statistics report with compact table styling, selectable service/source filters, and datetime-local controls that preserve wall-clock seconds. Added repository support and regression coverage for filter options, malformed datetime rejection, and datetime normalization.
- [Submodule Removal] Removed the `external/codex-control` submodule and cleared the repository submodule metadata from `.gitmodules`. This returns the control repository linkage to a non-submodule state while keeping the backend tree free of the nested dependency.

2026-05-18:
- [Handoff Dedupe] Fixed landing handoff event persistence to use duplicate-safe inserts that return the stored row instead of surfacing duplicate-key `wpdb::insert` errors. Added DB-backed regression coverage for duplicate handoff events while preserving a single stored row.

2026-05-17:
- [Statistics] Added a protected `[kiwi_statistics]` traffic-source funnel report backed by a plugin-managed `kiwi_v_load_to_cta_by_tksource_tkzone` view, with timeframe/source filters, median Load-to-CTA metrics, completed-sales rates, CSV export, tests, and operations/architecture documentation.

2026-05-16:
- [Statistics Windowing] Fixed the traffic-source funnel statistics view so completed-sale metrics are timestamped and filtered by `wp_kiwi_sales.completed_at` instead of `created_at`. This keeps delayed callback/retry conversions in the reporting window where the sale actually completed.

2026-05-15:
- [Legacy Fallback] Disabled legacy `KIWI_LANDING_PAGES` fallback by default so active landing routes resolve from filesystem entries unless the rollback switch is explicitly enabled. Updated production behavior docs and regression coverage for active filesystem routes and explicit legacy rollback.
- [UA Hints] Added optional UA Client Hints telemetry for click-to-SMS handoff events, including schema migration coverage, REST sanitization, tracker injection, and a server-side disable switch. Follow-up coverage ensures disabled telemetry clears stored UA hint fields and raw context.
- [LP6 V2] Refined the `lp6-fr-v2` cookie popup layout, typography, spacing, and selection control, replacing the static selection marker with an actual checkbox input and checked-state styling.

2026-05-14:
- [No Changes] No medium-impact or higher commits landed in repository history on 2026-05-14.

2026-05-13:
- [LP6 V2] Added the `lp6-fr-v2` France one-off Premium SMS landing-page variant with dedicated markup, NTH integration metadata, responsive hero assets, CTA tracking, service disclosures, and cookie popup behavior. Follow-up styling tightened the cookie popup sizing, typography, spacing, and mobile layout.

2026-05-12:
- [Schema Version] Bumped the plugin DB schema version to `2026-05-12-1` so existing installs rerun `dbDelta` migrations for the `tksource`/`tkzone` columns introduced with traffic-source tracking.

2026-05-11:
- [Affise Params] Updated the click-attribution/postback architecture contract to use the real Affise sale template (`clickid`, `secure`, `goal`, `status`) and documented supported optional parameters with `action_id` uniqueness guidance. Clarified dispatcher behavior so only template-declared parameters are emitted and `secure={secure}` is required in Affise sale templates.

2026-05-10:
- [Logging Cleanup] Removed temporary DIMOCO/NTH debug `error_log` instrumentation from callback handling, digest generation, client request/response paths, and blacklist batch polling to reduce production log noise while keeping behavior intact. Deleted obsolete debug-only callback/patch artifacts that were no longer part of the active integration path.

2026-05-09:
- [Rate Accuracy] Fixed SMS-body variant summary updates so event increments no longer distort derived conversion-rate fields during the same write. Added repository regression coverage with a `wpdb`-backed test double to verify persisted counters and recalculated rates remain consistent.

2026-05-08:
- [SMS Variants] Added the FR click-to-SMS SMS-body variant experiment with stable visible-token assignment mapped back to internal `transaction_id`, including new assignment/summary persistence and adapter/service wiring. Extended KPI and conversion attribution handling plus docs so assignment, handoff, and conversion events are recorded and analyzable by variant.

2026-05-07:
- [Control Submodule] Replaced the in-repo `codex-control` directory with an `external/codex-control` submodule and updated prompt content through subsequent same-day commits. This keeps prompt governance versioned at the integration boundary while preserving repository wiring via `.gitmodules`.
- [Review Workflow] Added a dedicated `codex-reviewer-dispatcher` GitHub Actions workflow and refined planner/implementer dispatcher instructions, naming, and prompt-posting behavior. These changes standardize multi-role Codex orchestration in CI-facing automation flows.

2026-05-04:
- [LP5 Variant] Introduced the new `lp5-fr_v2` landing-page variant by replacing the previous LP4 preload-test assets with dedicated LP5 v2 HTML/CSS/integration files. Follow-up commits normalized the variant directory naming to `lp5-fr-v2` and refined its stylesheet presentation.

2026-05-03:
- [LP6 Polish] Refined the `lp6-fr` landing-page presentation by emphasizing key SMS action words in the hero heading and tightening hero/image spacing. Also adjusted LP6 typography and visual balance to improve above-the-fold clarity.

2026-05-02:
- [No Changes] No medium-impact or higher commits landed in repository history on 2026-05-02.

2026-04-30:
- [LP2 Refresh] Updated `lp2-fr` landing-page content and presentation by switching hero/logo assets, tightening layout styling, and aligning premium-SMS disclosure/contact details with current FR copy. Follow-up integration updates normalized host routing defaults for LP2/LP6 to use the shared `your.joy-play.com` hostname.

2026-04-29:
- [No Changes] No medium-impact or higher commits landed in repository history on 2026-04-29.

2026-04-28:
- [Asset Rewrite] Extended filesystem landing-page rendering to rewrite local `./...` candidates in `srcset` and `imagesrcset` through the effective asset base URL while preserving non-local candidates, with regression coverage for router and gallery preview paths. Updated LP3/LP4 hero markup and docs to align responsive preload and image candidate handling with the renderer behavior.
- [Fraud Signals] Changed Premium SMS MO engagement evaluation so `unknown_link` is recorded as link-audit context and no longer treated as an engagement soft-flag reason. Updated fraud monitor/shortcode behavior and tests so flagged views exclude unknown-link-only rows while keeping genuine soft-flag reasons visible.

2026-04-26:
- [No Changes] No medium-impact or higher commits landed in repository history on 2026-04-26.

2026-04-25:
- [LP Variant] Added the `lp4-fr-img-preload-test` landing-page variant and expanded landing-page registry naming support to accept optional `-<variant>` suffixes, with docs updated for variant path/hostname behavior. Added regression coverage to ensure suffix-based filesystem entries are discovered and parsed correctly.
- [Auth Cache] Hardened frontend tool authentication responses by sending explicit no-cache headers on tool access checks and login-form rendering in `Kiwi_Frontend_Auth_Gate`. This prevents stale cached auth pages and aligns behavior across origin and CDN cache layers.

2026-04-24:
- [Hero LCP] Improved Joyplay LP4 hero-image loading behavior by adding explicit intrinsic dimensions and `fetchpriority="high"` on the main visual in `landing-pages/lp4-fr/index.html`. This reduces layout uncertainty and prioritizes hero delivery for faster Largest Contentful Paint.

2026-04-23:
- [Fraud Monitor] Wired the hidden `kiwi_fraud_flow_key` request filter through the Premium SMS fraud shortcode so backend filtering applies consistently to fraud-signal and landing-engagement rows, with regression coverage. The shortcode UI remains unchanged and intentionally does not expose a visible flow field.

2026-04-22:
- [Fraud Docs] Updated architecture and operations documentation to describe premium-SMS fraud monitoring as a combined volume-and-engagement capability with source-context snapshots (`pid`, `click_id`). Added explicit propagation notes from attribution through landing engagement telemetry into fraud snapshots and updated production validation guidance for the fraud shortcode.

2026-04-21:
- [Fraud Monitor] Added Premium SMS fraud-monitoring foundations with a dedicated signal repository/service, shortcode UI, and plugin/config wiring, then extended the shortcode with service dropdown filtering and all-services loading. Follow-up commits added click/page-load derived fraud metrics and exposed `signal`, `click_id`, and `delta` fields with regression coverage.
- [PID Tracking] Extended tracking attribution to persist `pid` through capture, KPI routes, repositories, and fraud/engagement services so monitoring outputs retain partner context end-to-end. Included test updates across the touched service and shortcode paths.

2026-04-19:
- [Gallery Routing] Updated landing-pages gallery URL derivation to align with proxy/public-host routing by preferring `https://<hostname><backend_path>` outside URLs over dedicated-root host links.
- [Test Coverage] Added regression coverage for NTH sales persistence-failure handling and DIMOCO blacklist batch lookup callback gating around authoritative `request_id` behavior.

2026-04-18:
- Hardened Lily HLR success handling by separating transport success (`http_success`, any HTTP 2xx) from business success (`status=OK` and `hlrStatus` in `OK|SUCCESS`).
- Preserved richer failure diagnostics for Lily HLR outcomes by keeping `status_code`, provider `messages`, and raw response body context in non-success cases.
- Added end-to-end Lily client+parser tests covering `200 + OK + SUCCESS`, `2xx + OK`, `2xx + bad/empty body`, and `non-2xx` behavior to prevent regressions.

2026-04-17:
- Updated FR landing-page integration configs for VPS/public-host deployment across LP2/LP3/LP4/LP5, including dedicated-path handling for LP5.
- Added VPS endpoint runbook documentation and linked it from the docs index to standardize production endpoint verification.

2026-04-16:
- Added a frontend auth gate for internal tool shortcodes (HLR lookup, DIMOCO refunder/blacklister, landing-pages gallery), including login/logout flow and regression tests.
- Expanded multi-domain DNS/CNAME routing guidance and test coverage for host-agnostic backend-path resolution behind proxy setups.
- Fixed NTH operator-name resolution fallback by using `operator_code` when no mapped operator name is available.
- Added `pid` persistence into sales attribution flow (including sanitization, repository write path, and tests), enabling durable partner-ID retention.
- Stabilized LP5 integration metadata syntax for multi-host deployment so configuration remains parse-safe.

2026-04-15:
- Added the new `lp5-fr` landing-page variant with HTML, styles, and integration metadata.
- Hardened NTH FR submit-message contract by propagating `session_id` to `sessionId`, requiring it in strict template validation, and blocking MT submission when missing.
- Improved DIMOCO callback resolution for shared-secret ambiguity by accepting safely verifiable callbacks, preserving resolution metadata, and expanding tests.

2026-04-14:
- Fixed DIMOCO refund callback handling when order ID is missing by adding digest-based service fallback resolution with repository-routing test coverage.
- Added async refunder callback polling by `request_id` to surface callback results after submit instead of relying only on transaction-id lookup.
- Added postback enrichment with `sub7=operator_name` and resolver/dispatcher persistence to improve attribution payload quality.
- Refactored landing KPI reporting to use summary-table aggregation flow and updated KPI routing/service tests accordingly.
- Corrected NTH FR one-off behavior by fixing operator-code fallback and aligning default MT text output.

2026-04-13:
- Introduced landing KPI tracking foundations (router hooks, KPI REST routes, repositories/services, and end-to-end tests).
- Added `lp4-fr` landing page assets and integration wiring.
- Fixed local landing-page asset path resolution in the router with explicit regression coverage.
- Added landing-page variant-agent implementation and related integration documentation/tests.
