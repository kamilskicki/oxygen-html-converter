# Definition of Done: v0.9.0-beta

Date: 2026-07-06
Product: `oxygen-html-converter` (Core)
Target release: `0.9.0-beta`

## Product Promise

For supported inputs, Core imports HTML into editable native Oxygen Builder content with:

- no Builder validation errors
- no corrupted document state after save/reopen
- no visible code blocks for supported/no-code structures
- safe reporting for unsupported or deferred structures
- no dependency on Pro to satisfy import/editability

This is still a beta promise. It applies to the supported scope below, not every arbitrary frontend application or CMS workflow.

## Supported Scope For 0.9.0-beta

In scope:

- single-page marketing and landing-page HTML
- deterministic static site-kit manifests
- Oxygen templates, headers, footers, pages, parts, reusable blocks/components, and homepage/menu records from explicit manifests
- native Oxygen conversion for supported text, image, link, button, layout, selector, variable, responsive, and safe animation patterns
- text component properties and the verified component property fields documented in `docs/SUPPORTED_SCOPE.md`
- Tailwind-like utility classes as source hints or safe generated fallback CSS when they are materialized into native output
- Safe Mode by default: executable JavaScript, event handlers, dangerous URLs, and raw runtime embeds are removed, converted, or reported unless unsafe fallback is explicitly selected

Out of scope for declaring `0.9.0-beta` done:

- perfect import of every arbitrary external stylesheet stack
- full JS app migration
- framework runtime conversion beyond safe static preservation
- module bundlers, SPA routing, React/Vue app semantics, and Web Components parity
- functional forms without a verified native form element or approved plugin integration
- dynamic data, loops, WooCommerce, inferred CMS mappings, or archive-query semantics
- Tailwind runtime preservation, Tailwind config parsing, WindPress class mode, and WindPress cache reset as Core requirements

## Core Vs Pro Boundary

Core owns:

- HTML to native Oxygen conversion for supported structures
- Safe Mode/no-code import policy
- builder-safe document serialization
- admin and Builder import/paste UX
- static site-kit manifest import
- templates, headers, footers, pages, parts, components, variables, selectors, and page/global/component CSS routing where verified
- unsupported/fallback reporting
- stable unit, static-analysis, fixture, and live smoke gates
- extension hooks used by Pro

Pro/future owns:

- Tailwind/WindPress runtime preservation workflows
- dynamic data, loops, WooCommerce, inferred CMS mappings, and archive-query behavior
- functional form workflows beyond verified Core integrations
- advanced component property automation beyond verified fields
- project orchestration, crawling, AI remediation, dashboards, media workflows, and commercial automation

Core must not depend on Pro to satisfy the Builder editability guarantee.

## Release DOD

`0.9.0-beta` is done only when all items below are true.

### 1. Builder Editability

- supported imports open in Oxygen Builder without `Validation Error` / `IO-TS decoding failed`
- imported pages can be opened, edited, saved, and reopened without document corruption
- saved Builder documents contain required metadata for Oxygen document trees:
  - `root`
  - `_nextNodeId`
  - `status`
- failure artifacts identify fixture ID, URL, screenshot/log path, and blocking observation

### 2. No-Code / Safe Mode

- supported structures produce native Oxygen elements, selectors, variables, and page/global/component styles instead of visible `HtmlCode`, `CssCode`, or `JavaScriptCode`
- unsupported, form, dynamic-data, or product-boundary fixtures match the explicit `fixture-index.json` expected code block/fallback/unsupported counts
- executable JavaScript fallback is unavailable by default and requires an explicit unsafe mode
- JS-only hidden animation states resolve to native animation or safe final visible state

### 3. Conversion UX

- admin convert flow works end to end
- Builder paste/import flow works end to end where supported
- failures are surfaced as actionable messages, not silent corruption
- unsupported constructs degrade safely and are visible in the import report or fixture expectations

### 4. Visual And Behavior Smoke

For the maintained fixture suite:

- no catastrophic layout breakage
- no missing hero/content sections
- no hidden reveal content after Safe Mode conversion
- no broken above-the-fold CTA/layout behavior
- no Builder canvas blankness after import/save/reopen
- visual smoke and live smoke gates pass or have an approved written exclusion

### 5. Site-Kit And Components

- explicit static site-kit manifests can persist supported pages, templates, headers, footers, parts, components, homepage assignment, and menus
- reusable component instances use verified Oxygen 6 serialization
- component CSS is available to host pages when component instances render
- advanced component/dynamic/form patterns outside the verified contract are reported, not guessed

### 6. Safety / Robustness

- malformed or unsupported HTML fails safely
- import does not create invalid Builder documents
- security checks remain intact on AJAX endpoints
- tests cover builder-safe serialization, unsupported boundaries, Safe Mode behavior, and fixture expectations

### 7. Release Evidence

- `composer install` exits 0
- `vendor\bin\phpunit` exits 0
- `vendor\bin\phpstan analyse --configuration=phpstan.neon.dist` exits 0
- `vendor\bin\phpcs --runtime-set ignore_warnings_on_exit 1 --standard=phpcs.xml.dist` exits 0
- `npm run test:js` exits 0
- `npm run test:fixtures:local` exits 0
- `npm run sync:docker` exits 0 on the maintained local stack
- `npm run test:live` exits 0 on the maintained local stack
- `npm run test:visual` exits 0 on the maintained local stack
- `npm run check` exits 0
- `php scripts/release_verify.php --with-live` exits 0 or records approved written exclusions

## Current M8 Status

Current remediation evidence is recorded in `oxygen-html-converter-dev/knowledge/KBAI`, especially:

- `quality-baseline.md`
- `m8-01-stable-fixture-suite-expansion-notes.md`
- `m8-02-automated-quality-gates-notes.md`
- `m8-03-builder-browser-smoke-notes.md`
- `m8-04-tandem-skill-alignment-notes.md`
- `m8-remediation-summary.md`

The M8-06 final release gate remains the final authority for ship/no-ship evidence. Do not claim the release done until that final gate is run and recorded.

## Release Decision Rule

Do not ship `0.9.0-beta` if any of these is still false:

- stable unit, static analysis, coding standard, JS, fixture, live smoke, and visual gates have current passing evidence or approved written exclusions
- supported imports can be edited and re-saved natively in Builder without validation errors
- unsupported and Pro/future boundaries are reported honestly instead of hidden behind unsafe fallback
- maintained fixture imports would not be described by a human reviewer as visibly broken
