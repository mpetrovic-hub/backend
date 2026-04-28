# Landing Page Architecture

## Purpose

This document defines the target architecture for landing pages in this project.

The goal is to replace the current `wp-config.php`-driven landing-page definition model with a filesystem-based structure that is easier to design, maintain, review, and extend. Landing pages should be self-contained at the folder level, while flow and country-specific integration behavior remains explicit and documented.

This file is the source of truth for:

- how landing pages are stored
- how landing pages are discovered
- how landing pages are linked to flows and integrations
- which files are required in each landing-page folder
- how related integration documentation is organized
- how migration away from `wp-config.php` should be handled

---

## Problem Statement

The current landing-page setup is too clunky for ongoing design and maintenance work because the full definition of landing pages lives in `wp-config.php`.

This creates several problems:

- page structure and business mapping are mixed into bootstrap configuration
- changing a landing page requires editing central configuration instead of local page files
- adding a new landing page is harder than it needs to be
- landing-page ownership is unclear because assets, routing, and integration details are not grouped together
- country-specific and provider-specific setup details are harder to connect to the actual landing-page implementation

This architecture introduces a folder-per-landing-page model so each landing page can be added or updated with minimal central changes.

---

## Goals

### Primary goals

- Move landing-page definitions out of `wp-config.php`
- Use one folder per landing-page version
- Keep HTML and CSS local to the landing-page folder
- Add a small metadata file per landing page that links the page to the required flow
- Preserve compatibility with existing request and flow resolution logic wherever practical
- Keep deployment simple and dependency-light
- Make it easy for non-core developers to create a new landing page by copying an existing folder

### Non-goals

- Moving media assets into landing-page folders
- Replacing existing provider or billing integrations
- Introducing a CMS for landing-page management
- Adding external services or runtime network dependencies
- Inventing undocumented provider or callback values

---

## Target Repository Structure

```text
/architecture
  landing-page-architecture.md

/integrations
  nth-fr-one-off.md
  dimoco-at-subscription.md
  ...

/operations
  landing-page-prod-behaviour.md
  ...

/landing-pages
  /lp2-fr
    index.html
    styles.css
    integration.php
    README.md              # optional
  /lp14-at
    index.html
    styles.css
    integration.php
  /lp17-gr
    index.html
    styles.css
    integration.php
```

### Notes

- Each landing-page folder represents one concrete landing-page version.
- Media files are not stored in the folder unless there is an explicit future exception.
- Media can be referenced by URL or by existing shared asset paths.
- `README.md` at repository root remains the entry-point document and should link to this architecture file.

---

## Landing-Page Folder Contract

Each landing page must live in its own folder under `/landing-pages`.

### Required files

Every landing-page folder must contain:

- `index.html`
- `styles.css`
- `integration.php`

These three files are the minimum contract for a valid landing page:

- `index.html` contains the landing-page markup
- `styles.css` contains landing-page-local styles
- `integration.php` contains the metadata that links the landing page to the correct flow and integration documentation

### Optional files

A landing-page folder may also contain:

- `README.md` for landing-page-specific notes for designers or developers
- additional local static files only if explicitly approved by project conventions

By default, media should not be stored in the landing-page folder. Media can remain external and be referenced from `index.html`.

### Folder naming convention

Landing-page folders must follow this format:

```text
lp<version>-<country>
```

Test or variant pages may append a lowercase, hyphen-separated suffix:

```text
lp<version>-<country>-<variant>
```

Examples:

- `lp2-fr`
- `lp14-at`
- `lp17-gr`
- `lp4-fr-img-preload-test`

### Naming rules

- the `lp` prefix is required
- `<version>` must be numeric
- `<country>` should be a lowercase country code
- optional variant suffixes must use lowercase letters, numbers, and hyphens
- the folder name must be stable once used in production
- the folder name is the landing-page key and must match the metadata `key`

The folder name is not just descriptive. It is an identifier used by the registry and resolution logic.

---

## Metadata Format

Each landing page must include a lightweight integration metadata file named `integration.php`.

The preferred format is a PHP file that returns an array.

### Metadata Format example

```php
<?php

return [
    'key' => 'lp2-fr',
    'country' => 'FR',
    'locale' => 'fr',
    'flow' => 'nth-fr-one-off',
    'provider' => 'nth',
    'service_type' => 'premium_sms',
    'business_number' => '84072',
    'keyword' => 'Jplay*',
    'documentation' => '/integrations/nth-fr-one-off.md',
    'title' => 'France One-off LP2',
    'active' => true,
];
```

### Why `integration.php`

A PHP array file is the preferred default because it:

- fits naturally into the existing WordPress / PHP stack
- avoids adding JSON or YAML parsing dependencies
- is easy to load during bootstrap
- allows strict validation with minimal code

### Required metadata fields

Each `integration.php` file must define at minimum:

- `key`  
  Unique landing-page key. Must match the folder name exactly.
- `country`  
  Uppercase country code, for example `FR`.
- `flow`  
  Canonical flow identifier, for example `nth-fr-one-off`.
- `provider`  
  Provider or integration key, for example `nth` or `dimoco`.
- `documentation`  
  Path to the country-specific or flow-specific integration document.

### Recommended metadata fields

These are not always mandatory, but should be included when known:

- `locale`
- `service_type`
- `business_number`
- `keyword`
- `asset_base_url` only when a page must override the default shared media folder
- `title`
- `active`

### Validation rules

The loader must fail clearly when:

- `integration.php` is missing
- the file does not return an array
- `key` does not match the folder name
- required fields are missing
- `active` is not boolean when provided
- `documentation` points outside approved documentation locations

---

## Discovery and Loading Model

Landing pages must be discovered by scanning the `/landing-pages` directory.

### Discovery rules

A folder qualifies as a landing page only if:

- it is a direct child of `/landing-pages`
- it matches the folder naming convention
- it contains all required files
- its metadata passes validation

### Loader behavior

The loader should:

1. scan `/landing-pages`
2. inspect each candidate folder
3. validate required files
4. load `integration.php`
5. normalize metadata
6. register the landing page into an internal registry keyed by landing-page key
7. expose lookup methods by:
   - landing-page key
   - flow identifier
   - country, where needed by existing routing logic

### Failure behavior

Invalid landing pages should fail loudly in development and fail clearly in logs or diagnostics in production.

Recommended behavior:

- skip invalid entries from the active registry
- record exactly why discovery failed
- make the error actionable for developers

Example failure messages:

- `Landing page "lp2-fr" is missing integration.php`
- `Landing page "lp14-at" has key mismatch: expected "lp14-at", got "lp15-at"`
- `Landing page "lp17-gr" is missing required field "flow"`

---

## Runtime Resolution Model

The filesystem registry should become the source of truth for landing-page lookup.

### Resolution flow

At runtime, the application should:

1. determine the requested landing-page key or campaign mapping using existing request logic
2. resolve the landing page from the filesystem registry
3. read the associated flow and provider metadata
4. render the landing-page HTML/CSS using the resolved folder
5. continue using existing integration, billing, callback, and provider logic for the resolved flow

### Important boundary

The landing-page folder owns:

- HTML
- CSS
- landing-page metadata
- documentation linkage

The core application continues to own:

- request parsing
- traffic or campaign resolution
- provider execution logic
- billing submission
- callback handling
- compliance enforcement outside the page copy itself

This keeps landing pages easy to edit without duplicating business logic into each folder.

### Backward compatibility during resolution

During migration, existing request resolution may still rely on legacy mapping from `wp-config.php`.

The preferred migration approach is:

1. resolve via filesystem registry first
2. fall back to legacy config only for unmigrated pages
3. remove legacy fallback after parity is verified

No new landing-page definitions should be introduced into `wp-config.php`.

---

## Relationship Between Landing Pages and Integrations

Landing pages and integrations are related but must remain separate concerns.

### Landing-page folder

A landing-page folder defines:

- presentation
- local metadata
- which flow it belongs to
- which integration document explains that flow

### Integration document

An integration document under `/integrations` defines:

- provider behavior
- country-specific rules
- shortcode or business number mappings
- operator or NWC mappings
- billing behavior
- compliance wording requirements
- callback or notification notes
- open questions and unresolved fields

### Rule

Multiple landing pages may point to the same integration document.

Example:

- `/landing-pages/lp2-fr/integration.php`
- `/landing-pages/lp5-fr/integration.php`

Both may reference:

- `/integrations/nth-fr-one-off.md`

This allows multiple landing-page variants to share one technical integration source of truth.

---

## Documentation Model

Documentation should be split by responsibility.

### `/architecture`

Contains system-level decisions and conventions.

This file belongs here because it describes how the landing-page system works across the project.

### `/integrations`

Contains country-specific and flow-specific technical documentation.

Example:

- `nth-fr-one-off.md`

This document should preserve operational and compliance details such as:

- country: `FR`
- flow: `one-off`
- business number / shortcode: `84072`
- keyword: `Jplay*`
- MT submission URL: `http://mobilegate58.nth.ch:9099`
- encoding: `UTF-8`
- billing type: `MT billing`
- end user cost: `4.5 EUR`
- tariff factor: `100`
- `price` parameter: `450`
- operator / NWC mappings
- encrypted MSISDN behavior
- Orange-specific behavior
- FR wording and compliance requirements
- callback documentation
- unresolved values clearly marked as unresolved

### `/operations`

Contains rollout, migration, and maintenance procedures.

Example topics:

- how to migrate a legacy landing page from `wp-config.php`
- how to validate new folders before release
- how to disable a landing page safely
- release checklist for design changes

---

## Backward Compatibility and Migration Strategy

The migration away from `wp-config.php` should be pragmatic.

### Rule

`wp-config.php` should no longer contain full landing-page definitions.

### Acceptable temporary use

During migration, `wp-config.php` may contain only thin bootstrap configuration such as:

- root path to the landing-pages directory
- feature flag to enable filesystem loading
- legacy fallback toggle during migration period

### Preferred compatibility approach

Use a staged migration:

1. introduce filesystem registry and loader
2. migrate one or more known landing pages into `/landing-pages`
3. wire existing resolution logic to prefer filesystem entries
4. keep legacy config fallback only while unmigrated pages still exist
5. remove legacy definitions after parity is verified

### Migration rule

No new landing page should be added to `wp-config.php`.

All new landing pages must be created as folders under `/landing-pages`.

---

## Example Landing Page

```text
/landing-pages/lp2-fr
  index.html
  styles.css
  integration.php
```

### Example `integration.php`

```php
<?php

return [
    'key' => 'lp2-fr',
    'country' => 'FR',
    'locale' => 'fr',
    'flow' => 'nth-fr-one-off',
    'provider' => 'nth',
    'service_type' => 'premium_sms',
    'business_number' => '84072',
    'keyword' => 'Jplay*',
    'documentation' => '/integrations/nth-fr-one-off.md',
    'title' => 'LP2 France One-off',
    'active' => true,
];
```

### Example `index.html`

```html
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>LP2 France</title>
  <link rel="stylesheet" href="./styles.css">
</head>
<body>
  <main class="lp-container">
    <h1>Sample landing page</h1>
    <p>Media assets may be referenced externally.</p>
    <a class="cta" href="{{KIWI_PRIMARY_CTA_HREF}}">CONTINUER ET PAYER</a>
  </main>
</body>
</html>
```

`{{KIWI_PRIMARY_CTA_HREF}}` is a render-time placeholder resolved by centralized backend flow logic.
Landing-page folders should not encode provider-specific CTA payload assembly directly in HTML.
By default, `./asset.ext` references in `index.html` and `styles.css` resolve under `https://backend.kiwimobile.de/wp-content/uploads/assets/` at render time. This includes direct `src`/`href` attributes, CSS `url(...)` values, and local candidates in `srcset`/`imagesrcset`. When `asset_base_url` is configured, it overrides that shared media folder; `styles.css` remains local to the landing-page folder.

The above-the-fold hero should be treated as the LCP image: preload it with `rel="preload" as="image"` and `fetchpriority="high"`, keep the visible `<img>` on the same high-priority asset path, and set explicit `width`/`height` attributes. When responsive hero variants exist, expose the small/common mobile candidates through matching `imagesrcset`/`imagesizes` on the preload and `srcset`/`sizes` on the `<img>`.

---

## Adding a New Landing Page

To create a new landing page:

1. copy an existing landing-page folder
2. rename the folder using the naming convention
3. edit `index.html`
4. edit `styles.css`
5. update `integration.php`
6. verify the referenced integration document exists
7. run validation and tests
8. deploy without adding landing-page definitions to `wp-config.php`

### Minimum checklist

- folder name is valid
- metadata `key` matches folder name
- flow identifier is correct
- documentation path is correct
- page assets render correctly
- compliance wording has been reviewed
- page resolves correctly in the application

---

## Validation and Testing Expectations

The codebase should include tests that prove the new system works and fails clearly.

### Required test coverage

- landing pages are discovered from folders
- metadata is parsed correctly
- landing page resolves to the correct flow
- landing page links to a country-specific integration document
- invalid or incomplete landing-page folders fail clearly

### Recommended implementation boundaries

Tests should focus on:

- directory discovery
- metadata validation
- registry building
- flow lookup
- legacy fallback behavior if still present during migration

---

## Security and Safety Constraints

- Never place secrets in `integration.php`
- Never place API keys in landing-page folders
- Never store PII in landing-page assets or metadata
- Do not invent callback or billing values that are undocumented
- Keep external asset references explicit and reviewable
- Treat integration documents as operational references, not secret storage

---

## Design Principles

### Prefer convention over registration

A valid folder under `/landing-pages` should be enough to make a landing page discoverable.

### Keep page-local concerns local

HTML, CSS, and page-to-flow metadata should live together.

### Keep business logic centralized

Provider execution, callbacks, and billing logic should remain in application code, not duplicated into landing-page folders.

### Keep documentation linked, not duplicated

Use the metadata file to point to the integration document instead of copying country-specific technical notes into each landing-page folder.

### Fail clearly

Broken folder structure or invalid metadata should produce clear errors.

---

## Open Questions

These topics should be resolved during implementation against the real codebase:

- Which existing bootstrap or router component should own the landing-page registry?
- Which current request parameter or campaign mapping resolves the landing-page key?
- Whether HTML should be rendered directly or wrapped by an existing WordPress template layer
- Whether `documentation` paths should be repo-relative or resolved from a fixed docs root
- How long legacy `wp-config.php` fallback needs to remain enabled

These must be answered from the codebase, not guessed.

---

## Implementation Summary

The target architecture is:

- filesystem-based landing pages under `/landing-pages`
- one folder per landing-page version
- local `index.html` and `styles.css`
- local `integration.php` metadata
- automatic discovery and validation
- flow linkage through metadata, not `wp-config.php`
- country/provider technical behavior documented under `/integrations`
- migration and operational procedures documented under `/operations`

This keeps the system easier to maintain while preserving explicit technical and compliance documentation.

---

## Related Documents

- `/README.md`
- `/integrations/nth-fr-one-off.md`
- `/operations/landing-page-prod-behaviour.md`
