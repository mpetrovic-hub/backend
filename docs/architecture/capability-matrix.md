# Capability Matrix

## Read when

- You need documented capability coverage by aggregator, country, or flow.
- You are deciding whether a change should extend an existing capability.

## Source of truth for

- Capability inventory and status labels.

## Not here

- Capability design details; use the relevant architecture doc.
- Provider API behavior; use `../integrations/INDEX.md`.
- Freshness proof. Verify `implemented` status against code/tests before changing this file.

This file tracks which business capabilities are currently available, documented, planned, or known per aggregator, country, and flow.

Use this file as an inventory, not as a design document.

For architecture and coding rules, see:
- `../../AGENTS.md`

For aggregator-specific details, see:
- `../integrations/INDEX.md`

## Status meanings

- `implemented` = capability exists in the codebase
- `documented` = capability is documented, but implementation status may still need confirmation
- `planned` = intended but not yet implemented
- `unknown` = not yet confirmed

## Current matrix

| Aggregator | Capability | Country | Flow | Status | Notes |
|---|---|---|---|---|---|
| Shared/Core | filesystem landing-page discovery + routing | generic | landing pages | implemented | Filesystem registry + router resolution by backend path or dedicated host/path |
| Shared/Core | click attribution capture (server-side) | generic | landing entry | implemented | `wp_kiwi_click_attributions` with opaque tracking token cookie and server-side refs |
| Shared/Core | conversion attribution resolver + affiliate postback dispatch | generic | confirmed conversions | implemented | Confirmed-only dispatch, idempotent postbacks, retry on failed postback until `postback_sent_at` is set |
| Shared/Core | landing KPI summary tracking | generic | clicks / cta1..ctaN / conv | implemented | `wp_kiwi_landing_kpi_summary` + KPI REST endpoints |
| Shared/Core | landing funnel daily summary analytics | generic | landing / engagement / handoff / sales reporting | implemented | Slim main `wp_kiwi_landing_funnel_daily_summary` from canonical landing sessions plus separate `wp_kiwi_landing_funnel_daily_tkzone_summary`; date-range recompute, hourly rolling WP-Cron refresh, step-specific CTA metrics, handoff diagnostics, durable sales/device/coarse-IP snapshot dimensions, and `(unknown)` buckets |
| Shared/Core | SMS body variant experiment | FR | click-to-SMS | implemented | `wp_kiwi_sms_body_variant_assignments` + summary table; stable visible tokens map back to internal `transaction_id` |
| Shared/Core | sales persistence + enrichment | generic | confirmed sales | implemented | `wp_kiwi_sales` with transaction correlation, durable attribution/source/device snapshots, and landing-session IP prefix/hash context |
| Shared/Core | premium-SMS inbound MO fraud monitoring (volume + engagement) | generic | premium-SMS inbound MO | implemented | `wp_kiwi_premium_sms_fraud_signals` + `wp_kiwi_premium_sms_landing_engagements`; subscriber identity rows with session refs retained as metadata, per-service 1h/24h snapshot counts, billing outcome/sale/aggregator-status snapshots, persisted landing-engagement UI soft-flag snapshots (`missing_load`, `click_before_load`, `fast_click`), MO engagement soft-flag checks (`missing_page_loaded`, `missing_cta_click`, fast MO), unknown engagement links recorded as audit context only, source context snapshots (`pid`, `click_id`), default observe mode with optional block integration |
| Shared/Core | premium-SMS completed-sale cooldown | generic | premium-SMS one-off billing attempts | implemented | Shared completed-sale lookup on `wp_kiwi_sales`; adapters can block new billing attempts after a completed one-off sale for configurable `completed_sale_cooldown_days`, while terminal failed attempts remain retryable |
| Shared/Core | operational event log foundation | generic | runtime operations | implemented | Append-only `wp_kiwi_operational_events`, shared sanitizing/lifecycle service, bounded incident reads, retention stale/recovery producer, and daily batched cleanup |
| Dimoco | operator-lookup | generic / multi-country | API action | implemented | Existing backend capability routed through Dimoco where configured |
| Dimoco | refund | generic | API action | implemented | Existing backend capability with callback persistence |
| Dimoco | add-blacklist | generic | add-blocklist | implemented | Existing backend capability; external action name is `add-blocklist` |
| Dimoco | check-blacklist | generic | check-blocklist | documented | Supported by Dimoco API docs; no confirmed implementation path in repository |
| Dimoco | remove-blacklist | generic | remove-blocklist | documented | Supported by Dimoco API docs; no confirmed implementation path in repository |
| Dimoco | identify | AT | subscription pre-step | documented | AT subscription guide documents identify routing step |
| Dimoco | subscription lifecycle (start / renew / close) | AT | subscription | documented | AT subscription guide documents start/renew/close semantics |
| Dimoco | prompt | AT | free SMS MT | documented | AT subscription guide documents prompt for free MT |
| Lily | operator-lookup | generic / GR | API action | implemented | Existing backend capability via Lily provider |
| NTH | MO callback handling (`deliverMessage`) | FR | one-off | implemented | Implemented callback parsing/normalization and MO-driven flow start |
| NTH | MT submission (`submitMessage`) | FR | one-off | implemented | MT submission implemented with strict payload contract, `messageRef`, and `sessionId` propagation |
| NTH | MT delivery-report handling (`deliverReport`) | FR | one-off | implemented | Intermediate/final status mapping and confirmed-conversion handling implemented |
| NTH | one-off sale persistence | FR | one-off | implemented | Confirmed terminal reports persist sales into `wp_kiwi_sales` |
| NTH | click attribution + affiliate postback | FR | one-off | implemented | Shared attribution capability wired end-to-end for NTH FR one-off |
| NTH | operator name normalization from `operatorCode` | FR | one-off | implemented | Resolver maps operator code to readable operator name from service mapping when available |
| NTH | init-session | generic | web-initiated services | documented | Generic NTH Premium SMS operation; not confirmed as implemented in this repo |
| NTH | number lookup | generic | web-initiated services | documented | Generic NTH Premium SMS operation; not confirmed as implemented in this repo |
| NTH | validate-pin | generic | web-initiated services | documented | Generic NTH Premium SMS operation; not confirmed as implemented in this repo |
| NTH | close-session | generic | session-based services | documented | Generic NTH Premium SMS operation; not confirmed as implemented in this repo |
| NTH | deliverEvent | generic | event callback | documented | Generic NTH callback type; not used by current FR one-off implementation |

## Notes

### Shared/Core
Current known repository capabilities:
- filesystem landing-page system with centralized routing and CTA injection
- shared click attribution and conversion correlation
- outbound affiliate postback dispatch with idempotency and signing options
- landing KPI summary tracking
- persistent landing funnel daily summary analytics, including a slim main summary, separate tkzone daily summary, and coarse IP version/prefix dimensions without raw IP or IP-hash reporting
- SMS body variant assignments and SQL-based summary metrics for click-to-SMS experiments
- shared sales persistence/enrichment with durable attribution snapshots for confirmed sales
- premium-SMS inbound MO fraud monitoring with volume, billing-outcome, sale-correlation, aggregator-status, and landing-engagement signals, including source-context snapshots (`pid`, `click_id`)
- premium-SMS completed-sale cooldown based on durable `wp_kiwi_sales` facts
- append-only operational events with correlation, idempotency, credential masking, open-incident reads, retention integration, and bounded cleanup

### Dimoco
Current known repository capabilities:
- operator-lookup
- refund
- add-blacklist

Current documented country-specific setup:
- `AT / subscription`

### Lily
Current known repository capability:
- operator-lookup

Further country / flow documentation has not yet been added.

### NTH
Current documented setup:
- `FR / one-off`

The FR one-off setup is implemented as:
- Premium SMS
- one-time payment
- MT billing
- web-initiated MO keyword flow
- compatible with click-to-SMS style landing-page UX
- includes shared click attribution and affiliate postback dispatch on confirmed conversion callbacks

## Maintenance rules

Update this file when:
- a new aggregator is added
- a new country setup is documented
- a new flow is implemented
- a capability changes from `documented` to `implemented`
- a capability is deprecated or removed

Before making changes to this file, the agent must:
1. summarize the proposed change
2. explain why the matrix needs to be updated
3. point to the supporting code and/or documentation
4. wait for explicit user approval before editing the matrix

This approval step is especially required when:
- adding a new aggregator
- adding a new country or flow
- changing a status value
- changing the meaning or naming of a capability
- inferring implementation status from incomplete evidence

When uncertain:
- prefer `documented` or `unknown` over `implemented`

Do not infer support for a country or flow unless it is:
- implemented in code, or
- explicitly documented in the relevant integration docs
