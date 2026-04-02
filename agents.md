# AGENTS.md

## Purpose
Guide Codex agents working in this repository.

This repository is an mVAS backend. Agents must favor small, correct, extensible changes over fast one-off patches. Design for future aggregators, countries, and operator setups even when implementing only one provider today.

## Domain Summary
Business flow:

Landing Page -> Aggregator -> MNO

Key domain terms:
- mVAS = mobile value added services
- MSISDN = subscriber phone number
- Aggregator = partner/provider integrating with one or more MNOs
- MNO = mobile network operator
- Carrier = mobile network operator
- Operator = mobile network operator
- Billing = payments of an end-user. Can have different periodicity, common are: weekly, daily, monthly etc.
- Flow = The set of user actions required to make a sale, from landing page to successful billing confirmation
- PIN = A user-entered confirmation code used to validate an mVAS billing action
- PIN-flow = A flow where the user confirms the mVAS purchase/subscription by submitting a code on a PIN entry page
- Click-flow = A flow where the user confirms the billing action directly on the landing/payment page through one or more clicks, without separate PIN or SMS verification
- Web2sms-flow = A flow where the user usually enters their MSISDN first and clicks a submit-button, receives an optin-SMS that they have to answer/confirm
- Click2sms-flow = A flow where the user usually presses a button on the landing-page, that opens a pre-filled message via sms://<number>?body=<text>
- Carrier-Billing = When users are billed directly via their MNO via API, usually the case for click- or PIN-flows
- Premium-SMS = When users are billed via paid SMS, usually the case for Web2sms-flows or Click2sms-flows
- One-off = When a user pays only once. In general the opposite of "subscription"

Current known capabilities:
- operator lookup
- add blacklist
- refund

Current known implementations:
- operator lookup via Dimoco and Lily
- add-blacklist for blocking MSISDNs from services via Dimoco
- refunds via Dimoco

## Core Engineering Rule
Model reusable business capabilities first. Add provider-specific implementation second.

Do not treat a single aggregator integration as the system design unless the requirement is explicitly provider-only and non-reusable.

Prefer:
- capability-oriented design
- provider/adapter abstractions
- stable internal contracts
- isolated integration layers
- configuration-driven extension where reasonable

Avoid:
- hardcoded aggregator-specific branching inside shared business logic
- copy-paste provider implementations
- leaking external request/response formats into core domain logic
- quick patches that block future providers

## Architecture Principles
1. Keep internal domain concepts separate from aggregator API models.
2. Define or refine an internal contract before or alongside a provider adapter.
3. Keep provider-specific request/response mapping at the integration boundary.
4. Shared business flows must not depend directly on aggregator payload formats.
5. Authentication, endpoints, retries, error translation, and provider quirks belong in provider/integration layers.
6. New capabilities should be designed so additional providers can be added with minimal change to core business logic.
7. Prefer explicit interfaces and narrow extension points over implicit coupling.

## Agent Roles

### 1) Planner
Use for non-trivial changes.

Responsibilities:
- restate the requested change in domain terms
- identify the capability being changed or added
- separate generic capability design from provider-specific implementation details
- identify affected modules, contracts, adapters, tests, and docs
- call out extension points for future aggregators/countries/setups
- flag risks, assumptions, and coupling hazards
- produce a concise implementation plan before coding

The Planner must prefer minimal extensible design over broad speculative architecture.

### 2) Builder
Implements the approved plan.

Responsibilities:
- implement against the plan, not against unstated assumptions
- keep provider-specific code isolated behind adapters/providers
- preserve existing behavior unless a breaking change is intentional and documented
- keep diffs focused and avoid unrelated refactors
- add or update tests when behavior changes
- update documentation when contracts, integration behavior, or extension points change

If the plan is incomplete or contradictory, stop and ask for clarification instead of inventing architecture.

### 3) Reviewer
Validates the result against both code quality and architectural fit.

Responsibilities:
- verify the implementation matches the plan
- verify the change fits the architecture principles in this file
- detect scope creep
- detect overfitting to one aggregator, country, or setup
- detect leakage of provider-specific models into core logic
- detect missing or weak extension points
- detect missing tests or insufficient validation
- detect undocumented architectural debt or hidden tradeoffs

The Reviewer should be strict about boundaries, but pragmatic about small incremental progress.

## Workflow

### Small changes
For clearly local, low-risk changes:
1. Make a short plan.
2. Implement.
3. Validate.
4. Summarize.

### Non-trivial changes
For new capabilities, provider work, refactors, or cross-module changes, use explicit approval gates:

1. **Planner**
   - Restate the task in your own words.
   - Explain which business capability is affected.
   - Distinguish generic capability design from provider-specific implementation details.
   - Propose a concise implementation plan.
   - List assumptions, risks, and open questions.
   - Then **stop and wait for explicit user approval**.

2. **Builder**
   - After plan approval, summarize the approved plan in implementation terms.
   - State the intended implementation scope, including expected files, modules, and tests to change.
   - Call out any potential deviation from the approved plan before coding.
   - Then **stop and wait for explicit user approval**.

3. **Implementation**
   - After approval, implement strictly against the approved plan.
   - Keep the diff focused.
   - Do not introduce unrelated refactors unless explicitly requested.

4. **Reviewer**
   - Review the result against:
     - the approved plan
     - this AGENTS.md
     - tests and documentation expectations
   - Report mismatches, architectural issues, coupling risks, missing extension points, missing tests, and undocumented tradeoffs.

5. **Revision loop**
   - If the review fails, revise and re-check.
   - If implementation reveals that the approved plan is incomplete, incorrect, or conflicts with the codebase, **stop, explain the issue, and request plan revision before proceeding**.

## Approval Rules
For non-trivial changes:
- Do not start implementation before the Planner has been explicitly approved.
- Do not start coding before the Builder summary has been explicitly approved.
- Do not silently deviate from the approved plan.
- If a better approach is discovered during implementation, pause and request approval for the revised plan.

## Default Output Style for Agents
For non-trivial tasks:
1. Planner: task understanding, capability analysis, plan, risks, approval wait
2. Builder: implementation summary, scope, approval wait
3. Implementation
4. Reviewer: validation against plan and architecture
5. Risks / follow-ups

## Change Rules
When changing code:
- keep changes cohesive and small
- prefer extension through interfaces/adapters over branching in shared services
- reuse existing capability patterns before creating new structures
- do not introduce provider-specific behavior into generic modules without a strong reason
- document intentional tradeoffs
- avoid large opportunistic rewrites unless explicitly requested

When adding a provider-specific implementation:
- define the internal capability contract first, if missing
- isolate provider auth, endpoints, payload mapping, and error handling
- expose normalized internal results to the rest of the system
- keep provider naming from spreading beyond the integration boundary unless required

## Integration Boundary Rules
Provider integrations are boundaries, not the domain model.

Required:
- map external request/response shapes at the edge
- translate provider errors into internal error semantics
- keep retries/timeouts/idempotency concerns close to the provider adapter
- make provider-specific assumptions explicit in code and docs

Not allowed unless explicitly justified:
- controller/service/domain logic depending directly on provider payload formats
- mixing transport concerns with business rules
- duplicating shared business rules inside provider adapters

## Testing & Validation
When behavior changes, add or update tests proportionate to the risk.

Expected coverage:
- business logic tests for capability behavior
- adapter/provider tests for request mapping, response mapping, and error handling
- regression tests for fixed bugs
- edge-case tests for provider failures where relevant

Validation should cover:
- happy path
- expected failures
- boundary conditions
- backward compatibility where applicable

## Security & Sensitive Data
Treat credentials and provider secrets as sensitive.

Rules:
- never expose secrets or credentials
- preserve auditability for blacklist and refund actions
- validate external inputs strictly
- handle provider/API failures explicitly
- do not commit tokens, passwords, certificates, or private keys

## Documentation Rules
Update docs when any of the following changes:
- integration behavior
- provider-specific assumptions
- internal contracts
- extension points
- operational constraints or known limitations

If supporting docs exist, consult and update them as needed, especially under:
- `docs/domain/`
- `docs/architecture/`
- `docs/integrations/`

## Definition of Done
A change is done when:
- the requested behavior is implemented
- the solution follows the architecture principles in this file
- provider-specific logic is isolated appropriately
- tests were added or updated where behavior changed
- relevant docs were updated
- risks, tradeoffs, and limitations are made explicit
- the diff does not introduce unnecessary coupling to one aggregator

## Default Output Style for Agents
For non-trivial tasks:
1. Brief plan
2. Implementation
3. Validation summary
4. Risks / follow-ups

Be concise, explicit, and repository-specific.