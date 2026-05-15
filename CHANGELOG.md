# Changelog

Changes are listed by date (newest first). Only medium-impact or higher updates are included.

2026-05-14:
- [No Changes] No medium-impact or higher commits landed in repository history on 2026-05-14.

2026-05-13:
- [LP6 V2] Added the `lp6-fr-v2` France one-off Premium SMS landing-page variant with dedicated markup, NTH integration metadata, responsive hero assets, CTA tracking, service disclosures, and cookie popup behavior. Follow-up styling tightened the cookie popup sizing, typography, spacing, and mobile layout.

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
