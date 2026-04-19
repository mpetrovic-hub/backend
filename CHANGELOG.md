# Changelog

Changes are listed by date (newest first). Only medium-impact or higher updates are included.

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
