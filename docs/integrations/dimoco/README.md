# Dimoco Integration

This folder contains the documentation for the Dimoco aggregator integration used by the mVAS backend.

Use this folder to understand:
- which Dimoco-related capabilities exist
- what is generally true for Dimoco integrations
- what differs by country or flow
- where source specifications and supporting material are stored

## Scope

This folder is for Dimoco-specific integration knowledge only.

It should document:
- Dimoco-specific API behavior
- Dimoco authentication and environment notes
- Dimoco callback/webhook patterns
- Dimoco-specific request/response conventions
- Dimoco-specific quirks and operational constraints
- country- and flow-specific Dimoco setups

It should not duplicate repository-wide engineering rules from `AGENTS.md`.

## Folder structure

- `general-api-dimoco.md`  
  General Dimoco API documentation that applies across multiple countries or flows.

- `<country-code>/<flow-name>/README.md`  
  Country- and flow-specific Dimoco integration documentation.

- `source/`  
  Original source material such as PDFs, OpenAPI files, onboarding notes, callback examples, or other reference documents.

## How to use this folder

### Start here
1. Read `general-api-dimoco.md`
2. Then read the relevant country/flow document
3. Check `docs/operations/credentials-and-environments.md` if the change affects authentication, secrets, callbacks, or environments
4. Check `docs/architecture/capability-matrix.md` if capability coverage or reuse matters

## Documentation split

### `general-api-dimoco.md`
Use this file for information that is generally true for Dimoco, for example:
- authentication model
- common endpoints or endpoint groups
- shared request/response conventions
- shared callback behavior
- shared error handling conventions
- aggregator-wide terminology
- mapping notes between Dimoco concepts and internal concepts

### `<country-code>/<flow-name>/README.md`
Use these files for information that is specific to one concrete setup, for example:
- country-specific rules
- flow-specific steps
- endpoint usage for that setup
- request/response examples
- callback behavior for that setup
- sandbox or testing notes
- operational assumptions or local limitations

Examples:
- `de/subscription/README.md`
- `at/subscription/README.md`
- `de/refund/README.md`

## What should be documented here

Dimoco documentation should make it easy to answer:
- Which capabilities currently use Dimoco?
- What is generally true for Dimoco integrations?
- What differs by country?
- What differs by flow?
- Which callbacks or status updates are relevant?
- Which parts of the integration are reusable?
- Which assumptions are local to one setup?

## Current capability coverage

Document the currently relevant Dimoco capabilities here as they are implemented or planned in this repository.

Known examples may include:
- operator lookup
- refund
- other country- or flow-specific integration cases as they are added

Keep this section updated when Dimoco-related capability coverage changes.

## Source material

Store original reference material under `source/`, for example:
- API specifications
- onboarding documents
- callback examples
- error code references
- test environment notes

Prefer keeping a Markdown summary in this folder even if the original source file is available.

## Rules for updates

Update this folder when:
- a new Dimoco country setup is added
- a new Dimoco flow is added
- general Dimoco behavior changes
- callback handling changes
- mapping rules change
- new operational constraints are discovered

Update `general-api-dimoco.md` when the change affects Dimoco broadly.  
Update the relevant country/flow file when the change is setup-specific.

## Credentials and configuration

Dimoco credentials and integration configuration are currently stored in `wp-config.php`.

Access to configuration values inside the codebase is handled through the existing configuration layer, currently defined in `class-config.php`.

This folder should document:
- which Dimoco configuration values exist
- which values are global vs service-specific
- which values are country- or flow-specific
- which parts of the code consume them
- where to look for the current configuration access pattern

Do not store real secret values in this documentation.

Document the detailed configuration and environment handling centrally in:
- `docs/operations/credentials-and-environments.md`

For Dimoco, document here which configuration areas are relevant, for example:
- base URL
- callback URL
- digest
- debug flag
- service-level configuration entries
- merchant identifiers ("merchant")
- shared secrets
- order or product identifiers ("order-id")

## Intended audience

This folder should be useful for:
- planners deciding how a Dimoco-related change should be structured
- builders implementing or extending a Dimoco integration
- reviewers checking whether the code matches the documented Dimoco behavior

## Goal

This folder should provide a clear bridge between:
- Dimoco source documentation
- actual Dimoco integration behavior in this repository
- country- and flow-specific implementation details
