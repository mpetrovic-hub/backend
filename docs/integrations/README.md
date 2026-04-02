# Integrations

This folder contains the integration documentation for external aggregators used by the mVAS backend.

Use the term **aggregator** consistently for external partners such as Dimoco, NTH, Lily, and others.

This folder is meant to help engineers and agents quickly find:
- which aggregators are integrated
- what is generally true for one aggregator
- what is specific to one country or flow
- which capability is affected
- where original source specifications are stored

## Structure

Each aggregator should have its own folder:

- `integrations/<aggregator>/README.md`
- `integrations/<aggregator>/general-api.md`
- `integrations/<aggregator>/<country-code>/<flow-name>/README.md`
- `integrations/<aggregator>/source/`

Example:
- `integrations/dimoco/README.md`
- `integrations/dimoco/general-api.md`
- `integrations/dimoco/de/subscription/README.md`
- `integrations/dimoco/source/`

## Documentation layers

Integration documentation is split into clear layers.

### 1. Aggregator overview
File:
- `integrations/<aggregator>/README.md`

Purpose:
- quick overview of the aggregator
- supported capabilities
- important terminology
- links to general API docs and country/flow docs
- major known limitations or special cases

### 2. General aggregator API documentation
File:
- `integrations/<aggregator>/general-api.md`

Purpose:
- information that is generally true for this aggregator across multiple countries or flows
- authentication model
- common endpoints or endpoint groups
- callback/webhook behavior
- error handling patterns
- request/response conventions
- mapping notes between aggregator API concepts and internal concepts

This file should contain reusable aggregator-wide information.

### 3. Country- and flow-specific documentation
File pattern:
- `integrations/<aggregator>/<country-code>/<flow-name>/README.md`

Purpose:
- country-specific rules
- flow-specific behavior
- setup-specific endpoint usage
- request/response examples for that exact case
- callback details for that setup
- sandbox notes
- operational assumptions and limitations

This is where local exceptions and concrete integration details belong.

### 4. Source material
Folder:
- `integrations/<aggregator>/source/`

Purpose:
- original PDFs
- OpenAPI files
- onboarding documents
- callback examples
- aggregator error code lists
- test environment notes

Keep the original source material if available, but add Markdown summaries so the information is easier to navigate inside the repository.

## General rules

- Put reusable aggregator-wide information into `general-api.md`.
- Put setup-specific details into the relevant country/flow document.
- Do not mix multiple countries or unrelated flows into one file unless there is a very strong reason.
- Do not store real credentials or secrets in integration docs.
- If credentials are required, document their names and usage in `docs/operations/credentials-and-environments.md`.

## Country and flow organization

Use country and flow folders when aggregator behavior differs by market or subscription flow.

Example patterns:
- `integrations/dimoco/de/subscription/README.md`
- `integrations/dimoco/at/subscription/README.md`
- `integrations/nth/de/pin/README.md`

Recommended country codes:
- use stable short codes such as `de`, `at`, `ch`, etc.

Recommended flow names:
- use short descriptive names such as:
  - `subscription`
  - `pin`
  - `refund`
  - `operator-lookup`
  - `blacklist`

If one country has multiple variants of the same flow, use an additional subfolder or document that clearly names the variant.

Example:
- `integrations/dimoco/de/subscription/standard/README.md`
- `integrations/dimoco/de/subscription/direct-carrier-billing/README.md`

## What to document per aggregator

Each aggregator folder should make it easy to find:
- supported capabilities
- authentication requirements
- endpoint information
- callback/webhook behavior
- error handling
- country-specific differences
- flow-specific differences
- sandbox/test notes
- mapping between aggregator models and internal models
- known quirks and constraints

## How engineers and agents should use this folder

### Planner
Start with:
1. `integrations/<aggregator>/README.md`
2. `integrations/<aggregator>/general-api.md`
3. the relevant country/flow file

Use these docs to determine:
- what is generic vs setup-specific
- whether an existing pattern already exists
- whether the new change should extend an existing capability

### Builder
Check:
- the aggregator overview
- the general API doc
- the relevant country/flow document
- credential/environment docs if auth or callbacks are involved

Update integration docs when:
- a new country or flow is added
- endpoint usage changes
- callback behavior changes
- mapping rules change
- new assumptions or limitations are introduced

### Reviewer
Compare code changes against:
- aggregator overview
- general API documentation
- relevant country/flow documentation
- credential/environment documentation where applicable

Look especially for:
- undocumented setup-specific assumptions
- aggregator-specific logic leaking into shared code
- missing updates to integration docs
- mismatches between implemented behavior and documented behavior

## Goal

This folder should make it easy to answer:
- Which aggregator is involved?
- What is generally true for this aggregator?
- What is specific to this country or flow?
- Which existing integration pattern should be reused?
- Which docs must be updated when the integration changes?