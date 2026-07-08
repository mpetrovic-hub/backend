# Premium SMS Fraud Monitoring

## Read when

- Work touches `[kiwi_premium_sms_fraud]`, Premium SMS MO fraud signals, landing-engagement soft flags, suspicious MO blocking, or fraud-monitor filters.

## Source of truth for

- Premium SMS fraud monitor behavior.
- MO volume and landing-engagement soft-flag interpretation.
- Operational filters and validation checks.

## Not here

- Attribution capture and propagation architecture: see `../architecture/click-attribution-and-postbacks.md`.
- Landing KPI and daily analytics summaries: see `landing-funnel-analytics.md`.
- Fraud-related config constants: see `configuration-reference.md`.
- Provider callback payload contracts: see `../integrations/INDEX.md`.

## Capability model

Premium SMS fraud monitoring combines:

- MO volume signals per subscriber identity.
- Landing-engagement linkage from attribution, page load, CTA click, source context, and handoff evidence.
- Billing outcome, sale correlation, and normalized aggregator status snapshots.
- Soft flags that are visible in the protected fraud monitor UI.

The current shared default is observe mode. In block mode, supported adapters may block suspicious MO-driven billing attempts when engagement rules indicate a soft flag.

## Stored signals

`wp_kiwi_premium_sms_fraud_signals` stores per-MO snapshots:

- subscriber identity context
- per-service volume counts such as 1h, 24h, and total counts
- billing outcome and billing transaction context
- sale correlation context
- normalized aggregator status
- `pid`, `click_id`, `tksource`, and `tkzone` source snapshots
- soft-flag state and reason

`wp_kiwi_premium_sms_landing_engagements` stores landing engagement evidence used by fraud monitoring:

- page load and CTA timestamps/counts
- CTA1/CTA2/CTA3 step-specific evidence
- source snapshots
- optional UA context according to the landing UA tracking mode
- persisted landing-engagement soft flags such as `missing_load`, `click_before_load`, and `fast_click`

## Soft-flag interpretation

MO engagement rules can flag:

- missing required page load evidence
- missing required CTA click evidence
- MO too soon after page load, for example `mo_too_fast_after_load<1s`

Landing engagement rows can flag UI anomalies:

- `missing_load`
- `click_before_load`
- `fast_click`

`unknown_link` is link-audit context only. It records unresolved attribution or engagement linkage, but it is not treated as a soft-flag reason and should not appear in flagged-only fraud-monitor views as a standalone reason.

## Filters

The protected shortcode is `[kiwi_premium_sms_fraud]`.

Visible filters include service/source/identity/flag filters used by the UI. The backend also accepts a hidden `kiwi_fraud_flow_key` request filter so operators can restrict results to one flow without exposing a visible flow selector.

Source-context filters:

- `kiwi_fraud_pid`
- `kiwi_fraud_tksource`
- `kiwi_fraud_tkzone`

## Operational checks

When validating fraud monitoring:

1. Confirm `[kiwi_premium_sms_fraud]` is protected by frontend tool auth when auth is enabled.
2. Trigger an MO with matching attribution and engagement evidence; verify source snapshots render.
3. Trigger an unresolved attribution/engagement case; verify `unknown_link` is retained as audit context but not as a soft-flag reason.
4. Trigger a fast MO after page load; verify the expected `mo_too_fast_after_load<Ns` soft flag appears.
5. Verify `flagged only` filters remove unknown-link-only rows.
6. Verify hidden `kiwi_fraud_flow_key` filtering changes backend results without adding a visible UI field.
7. In block mode, verify only engagement soft flags can block supported adapter billing attempts; observe mode must record/report without blocking.

