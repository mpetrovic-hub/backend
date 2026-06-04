# NTH Integration

This folder contains the documentation for the NTH aggregator integration used by the mVAS backend.

Use this folder to understand:
- which NTH-related capabilities exist
- what is generally true for NTH integrations
- what differs by country or flow
- where source specifications and supporting material are stored

## Scope

This folder is for NTH-specific integration knowledge only.

It should document:
- NTH-specific API behavior
- NTH authentication and environment notes
- NTH callback/webhook patterns
- NTH-specific request/response conventions
- NTH-specific quirks and operational constraints
- country- and flow-specific NTH setups

It should not duplicate repository-wide engineering rules from `AGENTS.md`.

## Folder structure

- `general-api-premium-sms-nth.md` and `general-api-carrier-billing.md`
  General NTH API documentation that applies across multiple countries or flows. `general-api-premium-sms-nth.md` for Premium-SMS-services and `general-api-carrier-billing.md` for carrier-billing

- `<country-code>/<flow-name>/README.md`  
  Country- and flow-specific NTH integration documentation.

- `source/`  
  Original source material such as PDFs, API specs, onboarding notes, callback examples, or other reference documents.

## How to use this folder

### Start here
1. Read `general-api.md`
2. Then read the relevant country/flow document
3. Check `docs/operations/credentials-and-environments.md` if the change affects authentication, secrets, callbacks, or environments
4. Check `docs/architecture/capability-matrix.md` if capability coverage or reuse matters

## Documentation split

### `general-api.md`
Use this file for information that is generally true for NTH, for example:
- authentication model
- common endpoints or endpoint groups
- shared request/response conventions
- shared callback behavior
- shared error handling conventions
- aggregator-wide terminology
- mapping notes between NTH concepts and internal concepts

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

NTH documentation should make it easy to answer:
- Which capabilities currently use NTH?
- What is generally true for NTH integrations?
- What differs by country?
- What differs by flow?
- Which callbacks or status updates are relevant?
- Which parts of the integration are reusable?
- Which assumptions are local to one setup?

## Current capability coverage

Document the currently relevant NTH capabilities here as they are implemented or planned in this repository.

Examples may include:
- operator lookup
- subscription handling
- refund
- blacklist handling
- other country- or flow-specific cases as they are added

Keep this section updated when NTH-related capability coverage changes.

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
- a new NTH country setup is added
- a new NTH flow is added
- general NTH behavior changes
- callback handling changes
- mapping rules change
- new operational constraints are discovered

Update `general-api.md` when the change affects NTH broadly.  
Update the relevant country/flow file when the change is setup-specific.

## Credentials and configuration

NTH credentials and integration configuration should be documented without storing real secret values.

Document here:
- which NTH configuration values exist
- which values are global vs service-specific
- which values are country- or flow-specific
- which parts of the code consume them
- where to look for the current configuration access pattern

Do not store real secret values in this documentation.

Document the detailed configuration and environment handling centrally in:
- `docs/operations/credentials-and-environments.md`

## Intended audience

This folder should be useful for:
- planners deciding how an NTH-related change should be structured
- builders implementing or extending an NTH integration
- reviewers checking whether the code matches the documented NTH behavior

## Goal

This folder should provide a clear bridge between:
- NTH source documentation
- actual NTH integration behavior in this repository
- country- and flow-specific implementation details
