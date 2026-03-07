# Definition of Done: v0.9.0-beta

Date: 2026-03-07
Product: `oxygen-html-converter` (Core)
Target release: `0.9.0-beta`

## Product Promise

The Core plugin should let a user paste HTML and get editable native Oxygen Builder content with:

- no Builder validation errors
- no broken document state after save/reopen
- no major visual drift on supported page types
- no manual JSON surgery

For `0.9.0-beta`, this promise must be true for the supported scope below. It does **not** yet mean perfect conversion of every arbitrary HTML document on the public web.

## Supported Scope for 0.9.0-beta

In scope:

- single-page marketing / landing-page HTML
- inline styles
- utility-first classes, especially Tailwind-like markup
- WindPress-assisted Tailwind rendering
- common interactions:
  - nav toggle
  - anchor scroll
  - reveal-on-scroll
  - counters
  - simple inline event handlers
  - non-module inline scripts
- head assets/scripts required for visual parity on the page

Out of scope for declaring `0.9.0-beta` done:

- perfect import of every arbitrary external stylesheet stack
- full JS app migration
- framework runtime conversion beyond preserved attributes / simple handlers
- module bundlers, SPA routing, React/Vue app semantics
- Web Components parity

## Core vs Pro Boundary

This release definition explicitly respects the Open Core split.

Core owns:

- HTML -> native Oxygen conversion
- builder-safe document serialization
- builder paste/import UX
- baseline visual parity for supported HTML
- supported interaction conversion
- compatibility and validation gates
- extension hooks used by Pro

Pro should own:

- premium presets and workflow automation
- site/library/template packs
- advanced batch operations and project management
- AI-assisted cleanup / remediation
- media ingestion / mapping workflows
- premium auditing dashboards and reporting UX
- niche framework integrations beyond Core baseline

Core must not depend on Pro to satisfy the Builder editability guarantee.

## Release DOD

`0.9.0-beta` is done only when all items below are true.

### 1. Builder Editability

- any page created by Core-supported import/save flow opens in Oxygen Builder with no `Validation Error` / `IO-TS decoding failed`
- imported page can be:
  - opened in Builder
  - edited
  - saved
  - reopened
  without document corruption
- saved Builder document contains required metadata for Oxygen document tree:
  - `root`
  - `_nextNodeId`
  - `status`

### 2. Conversion UX

- admin convert flow works end to end
- Builder `Ctrl+V` paste flow works end to end
- Builder import modal works end to end
- failures are surfaced as actionable messages, not silent corruption
- unsupported constructs degrade safely

### 3. Visual Parity

For the maintained fixture suite, imported frontend output must be visually close enough that a human would not call it a broken import.

Minimum Beta requirement:

- no catastrophic layout breakage
- no missing hero/content sections
- no clearly wrong gradients/colors caused by converter fallbacks
- no duplicated text layers caused by conversion bugs
- no broken above-the-fold CTA/layout behavior

Operational gate for maintained fixtures:

- visual audit performed on the maintained fixture set
- all fixtures open in Builder after save/import
- all fixtures pass manual review for:
  - hero
  - section ordering
  - typography hierarchy
  - CTA visibility
  - major interaction behavior

Recommended quantitative support gate:

- keep full-page visual diff trending downward
- treat any fixture with clearly visible human-facing defects as blocking even if metric looks acceptable

### 4. Behavior Parity

Supported interactions must work after import on the frontend and remain editable in Builder:

- menu open/close
- smooth anchor navigation where supported
- reveal animations / visibility classes
- counters
- preserved head scripts needed for the page
- transformed JS functions must not break local closure scope

### 5. Safety / Robustness

- malformed or unsupported HTML must fail safely
- import must not create invalid Builder documents
- security checks remain intact on AJAX endpoints
- tests cover the builder-safe serialization path

### 6. Core/Pro Contract

- no Pro-only dependency required for Core import/editability baseline
- hooks/filters used by Pro remain intact
- release docs keep Core vs Pro scope explicit

## Current Status vs DOD

### What is already true

- Builder editability bug for debug/parity pages was fixed by adding Builder metadata to saved document trees
- visual parity workflow exists and is now based on source vs frontend screenshots, not only class-count proxies
- multiple fixture regressions were already fixed:
  - JS closure-safe transformation
  - Tailwind gradient text fallback
  - head script preservation
  - reveal/link mapping fixes
- current quality gates are healthy:
  - PHP unit tests
  - JS tests
  - live fixture verification
- Core/Pro split is already respected in repo structure and docs

### What is not yet true

- we are not yet at honest “paste any HTML and it just works” scope
- fixture-by-fixture visual cleanup is still ongoing
- supported save/import path needs to be consistently exercised against Builder editability, not just frontend render parity
- external stylesheet and complex JS ecosystems are still outside reliable Core coverage
- some parity cases still need section-level manual closure

## Distance to Target

Assessment against the final long-term promise:

- `paste any HTML and edit it natively with zero issues`: about `55-65%`

Assessment against a realistic `0.9.0-beta` Core release:

- about `75-80%`

Why not higher:

- we still have known fixture parity work left
- “arbitrary HTML” is materially broader than today’s reliable support envelope
- editability after save must be treated as a first-class gate everywhere, not only in debug pages

Why not lower:

- the conversion core is already functional
- Builder paste/import UX exists
- the biggest recent blockers are now specific and diagnosable, not foundational unknowns
- we already have repeatable live parity and Builder validation loops

## Blocking Items Before Calling 0.9.0-beta Done

- finish visual/manual closure on the maintained fixture suite
- treat Builder reopen/edit/save as mandatory for imported pages, not optional
- ensure every save path writes Builder-safe document metadata
- document supported scope honestly in release notes

## Release Decision Rule

Do **not** ship `0.9.0-beta` if either of these is still false:

- imported pages in the supported scope can be edited and re-saved natively in Builder without validation errors
- a human reviewer would still describe maintained fixture imports as visibly broken
