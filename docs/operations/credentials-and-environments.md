# Credentials and Environments

This file documents where aggregator credentials and environment-specific integration settings are managed in this repository.

It is intentionally short and central.  
Aggregator-specific details belong in the corresponding integration docs.

## Current setup

Aggregator credentials and related integration settings are currently stored in:
- `wp-config.php`

Configuration access inside the codebase is currently handled through:
- `class-config.php`

## Scope

Use this file to document:
- where credentials are stored
- how the code reads configuration
- which integration settings are environment-dependent
- where credential-related changes must also be reflected

Do not use this file for:
- detailed aggregator API documentation
- country- or flow-specific business rules
- storing real secret values

## Rules

- Never store real secrets, passwords, API keys, tokens, or shared secrets in repository documentation.
- Keep aggregator-specific credential details in the relevant integration docs.
- Update this file only when the overall configuration approach changes.
- If the configuration access pattern changes, document the new access path here.

## Current aggregator note

At the moment, aggregator-related configuration is managed centrally via `wp-config.php`, with access through `class-config.php`.

Examples of configuration types that may exist there:
- usernames
- passwords / shared secrets
- base URLs
- callback URLs
- debug flags
- merchant identifiers
- order / service identifiers
- service-specific configuration arrays

## Environment note

If different environments use different credential sets or endpoints, document the overall pattern here.

Examples:
- local
- staging
- production

Do not store actual environment secret values in this file.

## Where to document details

For aggregator-specific configuration details, see:
- `docs/integrations/dimoco/README.md`
- `docs/integrations/nth/README.md`

For country- or flow-specific configuration usage, see the relevant files under:
- `docs/integrations/<aggregator>/<country-code>/<flow-name>/`

## Agent support for new setups

When a new aggregator, country setup, or flow is added, the agent may propose how the required configuration should be structured in `wp-config.php`.

This may include:
- suggested constant names
- suggested array structure
- separation of global vs aggregator-specific vs country-/flow-specific values
- suggestions for how configuration should be accessed through the existing configuration layer

The agent must not assume that configuration has already been added manually.

The agent should:
- explain which new configuration values are needed
- suggest where they should be placed in `wp-config.php`
- mention whether changes in `class-config.php` or related access code are likely required
- wait for manual implementation of the actual config values where necessary

The agent must not invent or insert real secret values into documentation or code.

## Purpose

This file should make it easy to answer:
- Where are credentials stored?
- How does the code access them?
- Where should credential-related documentation be updated?
- Which file explains the detailed setup for a specific aggregator?