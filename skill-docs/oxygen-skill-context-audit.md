# Oxygen 6 Skill Context Completeness Audit

This audit answers whether the current repository knowledge is complete enough to create a portable Codex skill for Oxygen Builder 6 native theming, HTML import, and site-kit workflows.

## Verdict

The current context is sufficient to create a useful v1 skill.

It is not sufficient to claim universal senior-level coverage of every Oxygen 6 edge case. The skill should be scoped as:

```text
Oxygen Builder 6 native site-kit developer for HTML-to-Oxygen imports, reusable sections/components, selectors, variables, CSS routing, and verification.
```

The skill must explicitly require verification against the target Oxygen installation before automating undocumented serialized formats.

## Authority Levels

### Strong / Ready

These areas are supported by local implementation, live Maximus proof, tests, and official Oxygen concepts:

- Oxygen-native conversion target: avoid `HtmlCode`, `CssCode`, and `JavaScriptCode` blocks for supported structures.
- `_oxygen_data` / `tree_json_string` practical handling in this plugin.
- Native element mapping for containers, text, links, images, sections, header/footer/template trees.
- Oxygen classes/selectors as the primary design-system layer.
- Oxygen variables and global settings as the token layer.
- Header, footer, template, and Template Content Area division.
- Reusable section components as `oxygen_block` posts.
- Page-side `OxygenElements\Component` instances referencing blocks.
- Text Component Properties through observed `targets`, `properties`, and `meta.component.editableProperties`.
- CSS routing out of the canvas into page, global, and component style persistence.
- Component CSS merge into host page CSS when component instances render without their own component-scoped CSS.
- Visual/build QA gates for the Maximus-style workflow.

### Partial / Needs Verification Before Productizing

These are known enough to guide work, but not enough to automate generically without another manual Oxygen inspection pass:

- URL/link Component Properties.
- Image URL and alt-text Component Properties.
- Icon Component Properties.
- Variant/state Component Properties.
- Repeaters/lists and loop-aware components.
- Functional forms and questionnaire behavior.
- Dynamic Data, ACF, Meta Box, CPT, archive, and loop workflows.
- Native variable binding serialization for every selector control.
- Tailwind/WindPress preservation mode across arbitrary exports.
- Pixel-perfect visual diff automation for arbitrary source HTML.

### Missing For A Portable Skill Release

The repo has the knowledge, but not yet in a clean skill package:

- Short `SKILL.md` with precise trigger scope and non-goals.
- Split reference files instead of asking the model to read all `skill-docs`.
- Diagnostic scripts that can be copied into other environments.
- Minimal fixture and expected-output example.
- Validation/eval checklist for the skill itself.
- Official-documentation source list with update dates.
- "Do not guess serialized Oxygen formats" safety rule in the main skill.

## Current Local Evidence

Latest verified Maximus report:

```text
artifacts/site-build/maximus-site-build-20260519-051234.json
```

Current proof metrics:

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

Important implementation proof:

```text
tests/live/build-maximus-site.php
```

Important functions:

```text
seedMaximusBrandSystem()
applyMaximusDesignSystemClasses()
importMaximusSectionComponents()
enableMaximusComponentProperties()
componentizeImportedPages()
buildOxygenComponentInstanceNode()
syncComponentStylesToPage()
createTemplateContentAreaTemplate()
collectMaximusDesignSystemSummary()
```

## Skill Shape Recommended By Supra-Skill-Creator Audit

Use a small, progressive-disclosure skill:

```text
oxygen-builder-6-native-developer/
  SKILL.md
  references/
    oxygen-native-data-model.md
    native-site-kit-workflow.md
    selectors-variables-global-settings.md
    components-and-component-properties.md
    css-routing-and-tailwind-boundaries.md
    qa-and-debugging-playbook.md
    product-boundaries-core-vs-pro.md
  scripts/
    inspect-oxygen-tree.php
    audit-oxygen-site.php
    check-component-css-routing.php
    smoke-oxygen-builder.php
  examples/
    minimal-fixture/
    expected-report.md
```

The top-level `SKILL.md` should stay short. It should tell the model when to use the skill, what to verify first, which reference to open for each task, and which claims are unsafe without local inspection.

## Trigger Scope

Use the skill when asked to:

- import HTML into Oxygen 6 as native editable pages,
- turn AI/Figma/Stitch/Claude HTML exports into Oxygen pages or design kits,
- debug Oxygen 6 frontend vs Builder drift,
- create or inspect Oxygen templates, headers, footers, blocks, components, classes, selectors, variables, or global settings,
- decide whether a section should be a page section, reusable component/block, template, header, or footer,
- split open-core native Oxygen functionality from Pro Tailwind/WindPress functionality.

Do not use it as an authority for unrelated WordPress plugin development unless Oxygen-native output is involved.

## Core Workflow The Skill Should Teach

1. Inspect source HTML and assets.
2. Convert to native Oxygen tree without code blocks where possible.
3. Seed variables, palette, global settings, and semantic selector collection.
4. Add shared semantic classes before or immediately after conversion.
5. Create Oxygen Header/Footer post types for site chrome.
6. Create an Oxygen Template with Template Content Area for page shelling.
7. Extract logical sections into reusable `oxygen_block` components.
8. Replace page sections with `OxygenElements\Component` instances.
9. Expose editable content through Component Properties, starting with proven text fields.
10. Route CSS to global/page/component style persistence and merge component CSS into host pages when needed.
11. Run structural, frontend, Builder, and visual QA before claiming completion.

## Key Safety Rules

- Do not reset or wipe a WordPress site unless it is explicitly local/disposable or the user has approved it.
- Do not claim full functional form import when the result is only a visual native questionnaire.
- Do not guess new Oxygen Component Property serialization. Manually create a property in Oxygen, inspect stored data, then automate.
- Do not hard-code Maximus-specific classes into a generic product path.
- Do not move Tailwind/WindPress compatibility into open core unless the product decision changes. Core should remain Oxygen-native first.
- Do not claim `test:maximus:matrix` is green while baseline converter gates still fail.

## Local Documentation Issues To Fix Or Account For

- `current-architecture.md` had stale Maximus report metrics in one section and should use `maximus-site-build-20260519-051234.json`.
- `remaining-to-10-10.md` stated `npm run test:maximus:matrix` passes, while newer quality/runbook notes correctly say it still fails on baseline converter gates.
- Some docs mix "10/10 Maximus proof" with "commercial product complete"; the skill should keep those separate.

## Product Boundary For The Skill

Core/open workflow:

- native Oxygen element conversion,
- selectors/classes,
- variables/global settings,
- headers/footers/templates/pages/blocks,
- page/global/component CSS routing,
- component instances,
- text Component Properties,
- verification report.

Pro/advanced workflow:

- Tailwind/WindPress preservation and cache reset,
- Tailwind config parsing,
- Tailwind-to-token compiler,
- advanced Component Properties,
- visual diff automation,
- brand-kit manager,
- functional form integrations.

## Final Assessment

The skill can be created now as a strong v1 operating guide. It should be honest about scope: excellent for the proven Oxygen-native import/site-kit workflow, still incomplete for all advanced Oxygen 6 domains such as dynamic data, loops, forms, WooCommerce, and every possible Component Property type.

## Created Skill Package

The skill was created at:

```text
C:\Users\Skicu\.codex\skills\oxygen-builder-6-native-developer
```

Package contents:

```text
SKILL.md
agents/openai.yaml
references/native-site-kit-workflow.md
references/oxygen-native-data-model.md
references/selectors-variables-global-settings.md
references/components-and-component-properties.md
references/css-routing-and-tailwind-boundaries.md
references/qa-and-debugging-playbook.md
references/product-boundaries-core-vs-pro.md
scripts/inspect-oxygen-tree.php
scripts/audit-oxygen-site.php
scripts/check-component-css-routing.php
```

Validation performed:

```text
quick_validate.py: Skill is valid
php -l inspect-oxygen-tree.php: OK
php -l audit-oxygen-site.php: OK
php -l check-component-css-routing.php: OK
local oxyconvo6 inspect script: OK
local oxyconvo6 audit script: OK, codeBlocks=0, componentInstances=20, editableProperties=176
local oxyconvo6 component CSS routing script: OK, highRiskHosts=0
local markdown link check: OK
red-team text scan for TODO/injection phrases/absolute local paths: OK
```
