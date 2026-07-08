# Lily Index

## Read when

- Work touches Lily Mobile, MOBIVAS Greece, Premium SMS subscriptions, Lily MT/MO/DLR callbacks, HLR lookup, or Greece-specific subscription compliance.

## Source of truth for

- Lily documentation navigation.
- Which Lily/MOBIVAS facts are generic API behavior vs Greece subscription behavior.

## Not here

- Full Lily MT Platform API details: see `general-api-premium-sms-lily.md`.
- Greece subscription setup details: see `gr/subscription/gr-subscription-lily-api.md`.
- Real credentials or secrets.

## Start here

1. `general-api-premium-sms-lily.md`
2. `gr/subscription/gr-subscription-lily-api.md`
3. `source/Update_Nova_2026-06-19.md` when NOVA/WIND response behavior matters
4. `../../operations/credentials-and-environments.md` and `../../operations/configuration-reference.md` for config ownership
5. `../../architecture/capability-matrix.md` for capability status

## Current docs

| File | Purpose |
|---|---|
| `general-api-premium-sms-lily.md` | Lily Mobile MT Platform API summary and repository interpretation notes. |
| `gr/subscription/gr-subscription-lily-api.md` | Concrete Greece Premium SMS subscription setup and MOBIVAS compliance assumptions. |
| `source/Update_Nova_2026-06-19.md` | Provider update about NOVA/WIND response behavior. |

## Update rules

- Update the general API doc for Lily-wide endpoint, auth, callback, envelope, or status behavior.
- Update the Greece subscription doc for Web2SMS/Double-MO, shortcode, compliance, ownership of MTs, or setup-specific assumptions.

