# Capability Matrix

This file tracks which business capabilities are currently available, documented, planned, or known per aggregator, country, and flow.

Use this file as an inventory, not as a design document.

For architecture and coding rules, see:
- `../../AGENTS.md`

For aggregator-specific details, see:
- `../integrations/README.md`

## Status meanings

- `implemented` = capability exists in the codebase
- `documented` = capability is documented, but implementation status may still need confirmation
- `planned` = intended but not yet implemented
- `unknown` = not yet confirmed

## Current matrix

| Aggregator | Capability | Country | Flow | Status | Notes |
|---|---|---|---|---|---|
| Dimoco | add-blacklist | AT | blocklist | implemented | AT subscription guide documents add/remove/check blocklist actions |
| Dimoco | add-blacklist | generic | add-blocklist | implemented | Existing backend capability; external Dimoco action name is `add-blocklist` |
| Dimoco | check-blacklist | generic | check-blocklist | documented | Supported by Dimoco API docs; implementation status in repo should be confirmed |
| Dimoco | close-subscription | AT | subscription | documented | AT subscription guide documents unsubscribe flow |
| Dimoco | identify | AT | subscription pre-step | documented | AT subscription guide documents identify as routing step for CLICK vs WEB TAN |
| Dimoco | operator-lookup | AT | subscription pre-step | implemented | AT subscription guide documents async and sync operator lookup variants |
| Dimoco | operator-lookup | generic / multi-country | API action | implemented | Existing backend capability; currently known via Dimoco |
| Dimoco | prompt | AT | free SMS MT | documented | AT subscription guide documents prompt for free MT sending |
| Dimoco | refund | AT / PL / service-specific config exists | API action | implemented | Existing backend capability; generic Dimoco refund support documented |
| Dimoco | remove-blacklist | generic | remove-blocklist | documented | Supported by Dimoco API docs; implementation status in repo should be confirmed |
| Dimoco | renew-subscription | AT | subscription | documented | AT subscription guide documents renewals handled by DIMOCO |
| Dimoco | subscription | AT | subscription | documented | AT subscription flow documented for Getstronger and StarBabes2Go |
| Lily | operator-lookup | generic / GR | API action | implemented | Existing backend capability; currently known via Lily |
| NTH | close-session | generic | session-based services | documented | Generic NTH Premium SMS function |
| NTH | deliverEvent | generic | event callback | documented | Generic NTH Premium SMS callback / operation |
| NTH | deliverMessage | generic | MO delivery | documented | Generic NTH Premium SMS callback / operation |
| NTH | delivery-report handling | FR | one-off | documented | FR setup documents MT delivery report callback |
| NTH | init-session | generic | web-initiated services | documented | Generic NTH Premium SMS function; use depends on service program |
| NTH | MO handling | FR | one-off | documented | FR setup expects MO keyword flow with encrypted MSISDN |
| NTH | MT billing | FR | one-off | documented | FR setup uses premium MT submission with `price=450` |
| NTH | number lookup | generic | web-initiated services | documented | Mentioned in generic NTH Premium SMS docs as normally required for web-initiated services |
| NTH | one-off payment | FR | one-off / web-initiated MO keyword / click-to-SMS style | documented | FR Premium SMS setup documented; MT billing |
| NTH | submitMessage | generic | MT submission | documented | Generic NTH Premium SMS operation |
| NTH | validate-pin | generic | web-initiated services | documented | Generic NTH Premium SMS function |

## Notes

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

The FR one-off setup is:
- Premium SMS
- one-time payment
- MT billing
- web-initiated MO keyword flow
- compatible with click-to-SMS style landing-page UX

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