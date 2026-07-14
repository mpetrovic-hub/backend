# Credentials and Environments

## Read when

- Work touches real credentials, secret ownership, environment-specific provider settings, endpoint ownership, or where operational settings are managed.

## Source of truth for

- Where credentials and environment-specific integration settings live.
- Rules for documenting secret-related setup without exposing values.
- Which docs to update when configuration ownership changes.

## Not here

- Non-secret constant reference: see `configuration-reference.md`.
- Aggregator API details: see `../integrations/INDEX.md`.
- Country or flow business rules.
- Real secret values.

## Current setup

Aggregator credentials and related environment-specific integration settings are currently stored in `wp-config.php`.

Landing-page definitions are no longer stored in `wp-config.php`. Their source of truth is:

- `landing-pages/<landing-key>/integration.php`
- `landing-pages/<landing-key>/index.html`
- `landing-pages/<landing-key>/styles.css`

Configuration access inside the codebase is currently handled through the shared configuration layer, currently `class-config.php`.

## Rules

- Never store real secrets, passwords, API keys, tokens, certificates, or shared secret values in repository documentation.
- Keep concrete aggregator credential ownership notes in the relevant integration docs when needed.
- Keep the full non-secret constant list in `configuration-reference.md`.
- Update this file only when the overall credential/environment ownership pattern changes.
- If configuration access changes, document the new access path here.

## Configuration ownership

Examples of settings that may be owned by environment configuration:

- usernames
- passwords or shared secrets
- base URLs
- callback URLs
- affiliate postback URL templates and signing secrets
- debug flags
- merchant identifiers
- order or service identifiers
- service-specific configuration arrays
- callback debug logging toggles

Do not document actual values. Document names, ownership, and where to configure them.

## Environment pattern

If local, staging, and production use different credential sets or endpoints, document the pattern here without values.

Recommended shape:

- environment name
- responsible system or owner
- where the secret is configured
- which integration or flow consumes it
- whether code changes are required to read it

## Landing-page multi-domain operations

For exposing landing pages on multiple public domains:

- no additional plugin constants are required for the current proxy/CNAME rollout
- domain onboarding is handled at infrastructure level: DNS, certificates, reverse proxy
- landing routing metadata remains in each `landing-pages/<landing-key>/integration.php`
- keep full user journeys on one public hostname because current attribution/session cookies are host-scoped
- if a future flow requires cross-root-domain redirects with shared attribution continuity, treat that as a separate capability design

Runtime details live in `landing-page-runtime.md`.

## Where to document details

- Aggregator navigation: `../integrations/INDEX.md`
- Dimoco docs: `../integrations/dimoco/INDEX.md`
- NTH docs: `../integrations/nth/INDEX.md`
- Lily docs: `../integrations/lily/INDEX.md`
- Non-secret constants: `configuration-reference.md`
- Landing runtime behavior: `landing-page-runtime.md`

## Agent support for new setups

When a new aggregator, country setup, flow, or landing page is added, agents may propose required configuration structure but must not invent or insert real values.

The proposal should state:

- which new configuration names are needed
- whether each value is secret or non-secret
- where the values should be configured
- whether code changes are required in the configuration access layer
- which integration and operations docs must be updated

For landing pages specifically:

- create `landing-pages/lp<version>-<country>/`
- provide `integration.php`, `index.html`, and `styles.css`
- link `documentation` to the relevant integration doc
- use `KIWI_LANDING_PAGES_ROOT` only when the deployment needs a non-default filesystem root
- do not define landing pages or alternate loaders in `wp-config.php`
