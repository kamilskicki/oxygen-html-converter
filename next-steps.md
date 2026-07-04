# Next Steps: Oxygen Native Core + Tailwind/WindPress Pro

Goal: build a reliable workflow where AI/Figma/Stitch/Claude-generated templates become Oxygen Builder 6-native editable pages, templates, and design kits.

## Recommendation

Do not position the product as a generic "HTML converter".

The best architecture is:

```text
AI/Figma/Stitch/Claude output
  -> OHC Design Package
  -> Oxygen Native Compiler
  -> WordPress/Oxygen importer
  -> Oxygen-native page/template/design kit
```

HTML remains an input format, but the source of truth should be an intermediate design package that contains structure, tokens, components, variants, assets, and metadata.

## Product Split

Core should be the Oxygen-native importer/compiler.

Core owns:

- HTML/CSS parsing
- Oxygen document tree creation
- native Oxygen selectors/classes
- Oxygen variables
- Oxygen global settings and color palette
- page/template import
- manifest and rollback metadata
- render cache refresh
- fallback CSS only when explicitly marked as non-native

Pro should own Tailwind and WindPress.

Pro owns:

- Tailwind parser/compiler
- Tailwind config import
- utility class interpretation
- Tailwind arbitrary value normalization
- Tailwind-to-Oxygen native mapping
- WindPress mode
- WindPress detection/cache tooling
- WindPress live gates
- visual diff gates
- Figma/Stitch/Claude export normalization profiles
- design kit/component extraction workflows

## How Tailwind HTML Should Work

Tailwind in source HTML should be treated as an authoring syntax, not as the final model.

In Core:

- Tailwind classes are not a supported runtime dependency.
- Known utility-like styling may be converted only through generic native style mapping.
- Unknown utilities should be reported in the import plan as unsupported or Pro candidates.
- Core output should remain Oxygen-native.

In Pro Tailwind Native mode:

- Tailwind classes are parsed into style intent.
- Repeated values become Oxygen variables.
- Repeated style groups become semantic Oxygen selectors.
- One-off styles become local Oxygen properties.
- Remaining unsupported constructs become explicit fallback CSS.

In Pro WindPress mode:

- Tailwind classes can be preserved for WindPress runtime rendering.
- This mode optimizes quick visual parity.
- It should not be marketed as 100% Oxygen-native editing.

## OHC Design Package Spec

Define a first-party import package:

```text
/page.html
/styles.css
/tokens.json
/components.json
/assets/
/ohc-package.json
```

Required package concepts:

- sections
- components
- variants
- design tokens
- responsive breakpoints
- asset references
- external font/icon declarations
- intended import target: page, template, section library, or design kit

HTML should use stable hints:

```html
<section data-ohc-section="hero">
  <div data-ohc-component="program-card" data-ohc-variant="featured">
    ...
  </div>
</section>
```

Recommended generator rules:

- use semantic section/component markers
- use CSS variables for brand tokens
- avoid random hardcoded values when values are design tokens
- keep breakpoints aligned with Oxygen
- avoid framework runtime dependencies
- avoid important content in pseudo-elements
- declare fonts, icons, and assets explicitly
- include Tailwind config or token manifest when Tailwind generated the template

## Style Unification Strategy

Use a style graph, not one-to-one class conversion.

Flow:

1. Parse HTML, CSS, inline styles, and classes.
2. Build element-level computed style intent.
3. Extract repeated values into variables.
4. Extract repeated style groups into semantic selectors.
5. Map page structure into Oxygen nodes.
6. Map one-off styling into local Oxygen properties.
7. Route unsupported behavior to explicit fallback CSS.
8. Save manifest showing native coverage and fallback reasons.

Do not create one Oxygen selector for every Tailwind class. That produces a noisy Oxygen class system and weak editability.

Prefer semantic selectors:

- `section-hero`
- `btn-primary`
- `btn-secondary`
- `program-card`
- `nav-link`
- `pricing-card-featured`

## What Gets Compiled Where

Oxygen Variables:

- brand colors
- text/background colors
- spacing scale
- font families
- type sizes
- radii
- shadows
- reusable arbitrary values

Oxygen Global Settings:

- global color palette
- body typography
- heading typography
- container widths
- default page/site styling
- global code only for declared assets such as fonts/icons

Oxygen Selectors:

- component styles
- section styles
- reusable variants
- states where Oxygen supports them
- responsive selector properties

Oxygen Page/Template Tree:

- section hierarchy
- element structure
- text/content
- images/media
- local one-off styles

Fallback CSS:

- pseudo-elements not representable natively
- complex animations
- complex media/state logic
- third-party widget styling
- temporary unsupported constructs

Fallback CSS must be visible in the manifest and should never be hidden as "native".

## Implementation Path

Step 1: Lock Core promise

- Rename the primary mode mentally and in docs to `oxygen_native`.
- Remove Core claims around WindPress-assisted rendering.
- Keep current compatibility only until Pro extension points are ready.

Step 2: Add extension contracts in Core

Expected files:

- `src/Services/ClassStrategyRegistry.php`
- `src/Services/CssFrameworkIntegrationInterface.php`
- `src/Services/UtilityClassMapperInterface.php`
- `src/Services/ImportPlanBuilder.php`
- `src/TreeBuilder.php`

Core should expose hooks so Pro can register Tailwind/WindPress behavior without patching Core.

Step 3: Convert current Tailwind/WindPress logic to plugin-style adapters while still inside Core

This reduces migration risk.

Current candidates to isolate:

- `TailwindDetector`
- `TailwindPropertyMapper`
- `TailwindCssFallbackGenerator`
- `WindPressCacheResetService`
- WindPress class mode UI
- WindPress live scripts/tests

Step 4: Create Pro implementation

Use `scaffolds/oxygen-html-converter-pro`.

Pro should:

- require Core
- validate `OXY_HTML_CONVERTER_API_VERSION`
- register Tailwind Native mode
- register WindPress mode
- own WindPress cache reset
- own Tailwind/WindPress UI copy
- own Pro-only tests and live gates

Step 5: Add OHC package importer

Core should accept:

- pasted HTML
- uploaded `.html`
- uploaded OHC package directory/zip
- JSON package manifest

The OHC package path should become the preferred route for reliable 100% native output.

Step 6: Add AI/export prompt spec

Create a prompt/spec document for template generators that says exactly how to output HTML for best Oxygen-native import.

Expected file:

- `docs/OHC_DESIGN_PACKAGE_SPEC.md`

Step 7: Add native design kit output

Importer should eventually create:

- page
- template
- reusable sections
- component candidates
- Oxygen variables
- Oxygen global settings
- selector collections
- manifest

## Verification Plan

Core checks:

```bash
composer test
npm run test:js
composer lint:phpstan
composer lint:phpcs
npm run test:live:maximus
```

Core-specific live assertions:

- imported page opens in Oxygen Builder
- text edit persists after save/reopen
- selectors referenced in tree exist globally
- variables are written to Oxygen variables
- color palette entries are written to Oxygen global settings
- fallback CSS is reported in manifest
- no WindPress dependency is required

Pro checks:

```bash
npm run test:tailwind
npm run test:windpress
npm run test:visual
```

Pro-specific live assertions:

- Tailwind classes compile to Oxygen native properties in Tailwind Native mode
- WindPress classes remain runtime classes only in WindPress mode
- WindPress cache is regenerated/reset
- Tailwind v3/v4 fixture matrix passes
- visual diff reports native vs WindPress parity separately

## Risks

- 100% visual parity and 100% native editing are only realistic for controlled packages, not arbitrary web HTML.
- Tailwind arbitrary values need strong normalization or they will create token noise.
- Components need stable detection rules; premature auto-componentization can create bad reusable structures.
- Global settings writes must stay merge-based, not destructive replacement.
- WindPress can provide faster visual parity, but it weakens the native Oxygen editing promise.

## Immediate Next Action

Implement the Core extension contracts first.

Do not move files into Pro until the current Tailwind/WindPress behavior is routed through those contracts. After that, migrate the adapters into Pro and leave Core with only Oxygen-native behavior plus neutral extension points.

## 2026-05-19 Maximus V2 Additive Proof Update

Implemented and verified an additive import path for `fixtures/html/Maximus/New Maximus`:

- command: `npm run import:site:maximus-v2`
- latest report: `artifacts/site-build/maximus-v2-import-20260519-090412.json`
- created/updated 5 new premium pages, 5 inactive headers, 5 inactive footers, and 13 section components
- replaced new page sections with 13 real `OxygenElements\Component` instances
- kept existing accepted post ids intact; no reset and no deleted existing posts
- kept source fixtures unchanged
- normalized source `PHYSICAL CULTURE` brand text to `MAXIMUS` in conversion and shared active footer content
- added `maximus-content-header` to separate content headers inside `main` from actual site chrome
- contact form remains native visual/editable markup; functional submit integration should be handled in a dedicated Maximus repo with Oxygen Forms or Fluent Forms installed/configured

Product lesson:

The generic product should support an additive staging/enrichment mode in addition to reset/rebuild mode. That mode needs first-class protection snapshots, inactive alternate chrome, idempotent slugs, semantic class usage reporting, and Browser smoke gates.
