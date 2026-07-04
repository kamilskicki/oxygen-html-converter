# Generic Site Kit Workflow Handoff

This is the handoff for building a non-Maximus site from AI/Figma/Stitch/Claude HTML into Oxygen 6 native pages, components, templates, selectors, variables, and editable content.

## Current Product Status

The Oxygen HTML Converter core is still a functional standalone HTML-to-Oxygen importer.

It can:

- convert supported HTML into native Oxygen elements
- persist Oxygen selectors, variables, global settings, global CSS, and page CSS
- avoid visible `HtmlCode`, `CssCode`, and `JavaScriptCode` blocks for supported structures
- support native/Tailwind/WindPress class strategies
- run live fixture, visual, and builder/editability checks

The Maximus work added a stronger site-kit proof-of-concept, but that pipeline is currently custom/test-side:

```text
tests/live/build-maximus-site.php
```

That script proves the desired architecture works, but it is not yet a generic UI product flow. Do not assume every Maximus-specific class or rule should become hard-coded core behavior.

## Recommended Workflow For A New Brand/Site

### Phase 0: Source Preparation

Input should be a folder of HTML fixtures:

```text
fixtures/html/<Brand>/
  home/code.html
  about/code.html
  offer/code.html
  contact/code.html
  ...
```

Best source characteristics:

- one coherent HTML page per target WordPress page
- real section boundaries using `section`, `header`, `footer`, `main`, `nav`
- repeated visual primitives use repeated classes where possible
- assets are stable URLs or local files
- no huge client-side app runtime when static HTML is enough
- Tailwind classes are acceptable, but native mode should not depend on WindPress

### Phase 1: Base Native Import

Run the existing converter/import path.

Expected deterministic work:

- create WordPress pages
- create native Oxygen element trees
- persist page-specific CSS outside the canvas
- persist global/foundation CSS
- persist selectors and variables where generated
- strip source Tailwind CDN/config from the Oxygen canvas
- keep `HtmlCode/CssCode/JavaScriptCode` at zero for supported structures

This is plugin-core functionality.

### Phase 2: Design System Audit

This still needs Codex/human design judgement unless/until automated.

Identify:

- section types: hero, editorial, cards, CTA, location, form, pricing, footer
- layout primitives: containers, grids, columns, stacks
- repeated components: cards, buttons, nav, badges, icons, forms
- typography roles: hero heading, section heading, card heading, body, metadata
- color roles: primary, surface, paper, ink, outline, accent
- spacing roles: page margin, section gap, grid gutter, component padding

Output should be a brand semantic map, for example:

```text
brand-page
brand-main
brand-shell
brand-site-header
brand-nav
brand-site-footer
brand-section
brand-section-inner
brand-section-hero
brand-container
brand-grid
brand-card
brand-card-featured
brand-button
brand-button-primary
brand-heading
brand-body-copy
```

### Phase 3: Semantic Class Unification

Map repeated source patterns to shared Oxygen classes.

Deterministic once the semantic map exists:

- add shared classes to matching nodes before conversion or as a post-pass
- put those classes in a dedicated Oxygen selector collection
- keep generated `ohc-*` classes only for fixture-specific residual styling

Needs judgement:

- naming
- variant boundaries
- deciding what is a reusable component versus local layout

### Phase 4: Template, Header, Footer, Pages

Create:

- `oxygen_template` with `OxygenElements\TemplateContentArea`
- `oxygen_header` for normal site header
- `oxygen_footer` for normal site footer
- WordPress pages for each fixture

Important pattern:

```text
header -> brand-site-header brand-shell
nav -> brand-nav
```

Do not assign full header semantics to nested `nav`; it causes menu drift and collisions.

### Phase 5: Reusable Section Blocks

Extract logical fixture sections into `oxygen_block` posts.

Recommended rule:

- use section blocks/components for repeated or independently editable page sections
- use templates for site-wide page shells
- use header/footer post types for site chrome
- do not turn every page into one giant component

The Maximus proof creates section blocks and replaces page sections with:

```text
OxygenElements\Component
```

This is the best current pattern for reusable native site kits.

### Phase 6: Component Properties

Currently proven:

- text Component Properties via `targets` and `properties`

Still needs product hardening:

- image properties
- URL/link properties
- icon properties
- selected-state or variant properties
- repeaters/lists
- functional form state

Do not guess new Component Property serialization. Manually create one in Oxygen Builder, inspect `_oxygen_data`, document it, then automate it.

### Phase 7: CSS Routing

Current safe routing:

- foundation/runtime CSS -> global style asset
- active shared chrome CSS -> semantic global style asset
- generated header/footer residual CSS -> document/page scope or prune after semanticization
- page CSS -> page style persistence
- component/block CSS -> component page style persistence
- when a page uses `OxygenElements\Component`, merge that component CSS into the host page CSS until Oxygen reliably enqueues component CSS itself
- never enqueue imported frontend/Tailwind fallback CSS on ordinary `wp-admin` screens; utilities like `.fixed` can override WordPress admin classes such as the pages list table and break scrolling

This last point is critical. Without it, pages can render component markup with missing layout styles.

### Phase 8: Visual And Builder QA

Minimum checks:

- pages return HTTP 200
- no WordPress critical error
- no horizontal overflow
- header/nav/footer do not collide
- grids are grids, flex rows are flex rows
- main sections are present and in correct order
- Oxygen Builder opens target page/header/block
- target content appears inside Builder iframe
- no visible code blocks in Builder

Representative browser pages:

```text
/
/key-secondary-page/
/contact-or-form-page/
one reusable block in Oxygen Builder
```

## What Is Deterministic Today

The following can be repeated mostly mechanically:

- base HTML-to-Oxygen conversion
- native element tree persistence
- selector/variable/global settings persistence
- page/global style persistence
- section extraction if section boundaries are clean
- page section replacement with `OxygenElements\Component`
- text Component Properties
- structural QA checks
- live rebuild of a local WordPress/Oxygen site

## What Still Requires Codex/Human Intervention

The following are not generic enough yet:

- deciding the brand semantic class map
- deciding component boundaries
- deciding which visual drift is acceptable
- advanced Component Properties beyond text
- functional forms and interactions
- image/link/icon prop serialization
- Tailwind/WindPress split decisions
- final visual polish

## What Should Be Built Next

To make this a real generic product workflow, build a site-kit import layer around the existing converter.

Recommended product modules:

1. Brand profile schema

```json
{
  "brandSlug": "acme",
  "prefix": "acme",
  "colors": {},
  "fonts": {},
  "spacing": {},
  "classMap": {},
  "componentRules": {}
}
```

2. Generic section detector

- finds `header`, `footer`, `main > section`
- detects hero/cards/CTA/form/location-like sections
- supports per-brand overrides

3. Semantic class mapper

- applies shared classes before conversion
- creates selector collection
- prevents generated-only design systems

4. Componentizer

- creates `oxygen_block` section components
- replaces page sections with `OxygenElements\Component`
- exposes text Component Properties
- merges component CSS into host page CSS

5. QA report

- structural counts
- code-block counts
- selector/variable/global settings counts
- component instance counts
- editable property counts
- frontend smoke result
- Builder smoke result
- visual drift notes

6. Optional Pro layer

- Tailwind/WindPress preservation
- WindPress cache reset
- Tailwind source compilation
- Tailwind utility-first workflows

Core should remain focused on Oxygen-native output.

## Additive Staging Imports

For enriching an existing accepted Oxygen site kit, prefer an additive importer over a reset:

```text
existing accepted site
  + new HTML fixture folder
  -> new page slugs
  -> inactive alternate headers/footers
  -> new reusable blocks/components
  -> no source fixture edits
  -> no deletion of existing post ids
```

The importer should capture a protection snapshot before and after import:

- counts for `page`, `oxygen_header`, `oxygen_footer`, `oxygen_template`, and `oxygen_block`
- all existing post ids
- missing existing ids after import
- code-block counts for newly imported/updated posts
- selector usage for new semantic classes

The Maximus V2 additive proof used:

```text
npm run import:site:maximus-v2
artifacts/site-build/maximus-v2-import-20260519-090412.json
```

Acceptance from that run:

```text
new pages: 5
inactive alternate headers: 5
inactive alternate footers: 5
new section components: 13
component instances on new pages: 13
existing post ids missing: 0
bad code posts: 0
```

## Source Brand Normalization

If a source export uses a placeholder or adjacent brand name, normalize it during conversion, not by editing source fixtures. Also check shared active Oxygen chrome because new pages may inherit the existing global header/footer.

For Maximus V2, `PHYSICAL CULTURE` was normalized to `MAXIMUS` in:

- V2 pages/components/layouts during conversion
- shared active footer
- shared footer component

Add a rendered text gate for source brand leftovers and double replacements.

## Content Header Versus Site Header

Do not treat every HTML `<header>` as site chrome.

Pattern:

- top-level site chrome: `brand-site-header`, `brand-shell`
- nested content header inside `main`: `brand-content-header`
- nav group: `brand-nav`

If a source content header inherits a site-header class, mobile layouts can overflow because site headers often use `space-between` row alignment. Add a scoped semantic selector rather than patching the page manually.

## Current Maximus Reference Build

Latest known good live site-kit build:

```text
artifacts/site-build/maximus-site-build-20260519-051234.json
```

Known current metrics:

```text
pages: 7
oxygen_header: 1
oxygen_footer: 1
oxygen_template: 1
oxygen_block: 24
section components: 20
page Component instances: 20
editable text properties attached to page instances: 166
editable markers inside blocks: 176
semantic selectors: 44
selectors total: 245
variables: 27
palette colors: 20
global style assets: 5
bad code posts: 0
```

Latest additive Maximus V2 enrichment:

```text
artifacts/site-build/maximus-v2-import-20260519-090412.json
pages added: 5
inactive headers added: 5
inactive footers added: 5
section components added: 13
component instances added: 13
bad code posts: 0
existing post ids missing: 0
```

## Next Chat Starting Instructions

If the next task is "make this work for another brand/site", do this:

1. Read this file first.
2. Read `skill-docs/oxygen6-native-patterns.md`.
3. Read `skill-docs/current-architecture.md`.
4. Inspect `tests/live/build-maximus-site.php` as the working proof.
5. Do not copy Maximus classes blindly.
6. Extract generic pipeline pieces from the Maximus proof into reusable services/scripts.
7. Keep the standalone converter working.
8. Add a new brand fixture folder and run the pipeline end to end.
9. Verify frontend and Builder before claiming success.

The correct product direction is:

```text
standalone HTML importer
  + generic site-kit builder layer
  + optional brand preset
  + optional Pro Tailwind/WindPress layer
```

Do not turn the core plugin into a Maximus-only importer.
