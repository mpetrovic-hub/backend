# Documentation

This folder contains supporting reference documentation for the mVAS backend.

`AGENTS.md` remains the main repository-level guide for how Codex agents should plan, implement, and review changes.  
The `docs/` folder provides the supporting business, integration, operational, and architecture references those agents and engineers can consult while working.

## What belongs here

Use this folder for documentation that should be easy to find, version in Git, and maintain alongside the codebase.

This includes:
- aggregator integration documentation
- aggregator API summaries
- country- and flow-specific integration specs
- capability coverage across aggregators
- credential and environment documentation without real secret values

Do **not** store real credentials or secrets in this repository.

## Current structure

- `integrations/README.md`  
  Entry point for all aggregator integration documentation.

- `integrations/dimoco/README.md`  
  Overview of the Dimoco integration docs.

- `integrations/dimoco/general-api-dimoco.md`  
  General Dimoco API notes that apply across multiple countries or flows.

- `integrations/dimoco/countries/<country-code>/<flow-name>/README.md`  
  Country- and flow-specific Dimoco integration documentation.

- `integrations/nth/README.md`  
  Overview of the NTH integration docs.

- `integrations/nth/general-api.md`  
  General NTH API notes that apply across multiple countries or flows.

- `integrations/nth/countries/<country-code>/<flow-name>/README.md`  
  Country- and flow-specific NTH integration documentation.

- `architecture/capability-matrix.md`  
  Overview of which capabilities exist for which aggregator, country, or flow.

- `architecture/landing-page-architecture.md`  
  Source-of-truth architecture for filesystem-based landing-page discovery and routing.

- `architecture/click-attribution-and-postbacks.md`
  Reusable server-side click attribution, conversion correlation, and outbound affiliate postback architecture.

- `operations/credentials-and-environments.md`  
  Documentation of credential names, ownership, environments, and secret locations without storing actual secret values.

- `operations/landing-page-prod-behaviour.md`  
  Runtime landing-page logic and operations runbook, including a legacy migration appendix.

## How integration docs are organized

Aggregator documentation is split into two levels:

### 1. General aggregator API documentation
These files describe what is broadly true for one aggregator, such as:
- authentication approach
- common endpoints or endpoint groups
- callback or webhook patterns
- error handling conventions
- global quirks or constraints
- high-level mapping to internal concepts

Examples:
- `integrations/dimoco/general-api-dimoco.md`
- `integrations/nth/general-api.md`

### 2. Country- and flow-specific integration documentation
These files describe what is specific to one concrete integration case, such as:
- country-specific rules
- flow-specific steps
- endpoint usage for that setup
- request/response examples for that flow
- callback details for that country/flow
- sandbox or testing notes
- setup-specific assumptions or limitations

Examples:
- `integrations/dimoco/de/subscription/README.md`
- `integrations/nth/<country-code>/<flow-name>/README.md`

Keep reusable aggregator-wide information in `general-api.md`.  
Keep local exceptions and concrete implementation details in the relevant country/flow document.

## Capability overview

Use `architecture/capability-matrix.md` to document which capabilities are currently supported and where.

This should answer questions such as:
- Which aggregators support operator lookup?
- Which capability exists only for one aggregator today?
- Which country/flow combinations already exist?
- Where can a new implementation extend an existing pattern?

## Credentials and environments

Use `operations/credentials-and-environments.md` to document:
- required credential names
- which environments use them
- which aggregator or flow they belong to
- where the secrets are managed
- who owns or maintains them

This file must not contain actual secret values.

## Source specifications

If original source material exists, keep it near the relevant aggregator documentation, for example:
- PDF specifications
- OpenAPI files
- callback examples
- error code references
- onboarding or test environment notes

Store those files under the relevant aggregator folder, for example:
- `integrations/dimoco/source/`
- `integrations/nth/source/`

Whenever possible, accompany source files with Markdown summaries so engineers and agents can navigate the information quickly inside the repository.

## How to use these docs

### Planner
Start with:
1. `AGENTS.md`
2. relevant files under `docs/integrations/`
3. `architecture/capability-matrix.md` if capability coverage matters

Focus on:
- which capability is being changed
- which aggregator is involved
- what is generally true vs country/flow-specific
- whether the change should remain extensible

### Builder
Before implementation, check:
- the relevant aggregator `general-api.md`
- the relevant country/flow document
- `operations/credentials-and-environments.md` if the change touches auth, callbacks, or environments

Update docs when:
- integration behavior changes
- a new country/flow is added
- credential requirements change
- new assumptions or extension points are introduced

### Reviewer
Compare code changes against:
- `AGENTS.md`
- the relevant aggregator documentation
- the relevant country/flow documentation
- `architecture/capability-matrix.md` where applicable

Check especially for:
- aggregator-specific logic leaking into shared code
- undocumented country/flow assumptions
- missing updates to integration docs
- mismatches between code and documented behavior

## Goal

This documentation should make it easy to answer:
- What does this integration do?
- Which aggregator is involved?
- What is generally true for the aggregator?
- What is specific to one country or flow?
- Which credentials are required and where are they managed?
- Which existing capability or pattern should be reused?
