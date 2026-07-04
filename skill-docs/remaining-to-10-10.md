# Remaining Work After The 10/10 Maximus Workflow Pass

The Maximus workflow now satisfies the core target for this local project:

```text
HTML fixtures -> Oxygen-native pages -> reusable section components -> page Component instances -> editable text properties -> verified frontend
```

Current score for the Maximus site-kit workflow:

```text
10/10 for Oxygen-native structure, repeatability, and closest-to-native editing currently implemented.
```

This does not mean the commercial product is finished. The remaining items are productization and broader coverage, not blockers for the current Maximus native workflow.

## Completed 10/10 Items

- Pages are rebuilt from Maximus fixtures.
- Header/footer/template are proper Oxygen post types.
- 20 reusable section `oxygen_block` components are created.
- 20 page-level `OxygenElements\Component` instances reference those blocks.
- Text nodes in section blocks expose Component Properties.
- Page instances carry Component `targets` and `properties`.
- Global selectors, variables, palette, and global settings are persisted.
- CSS is routed out of editable code blocks.
- Imported pages contain 0 `HtmlCode`, 0 `CssCode`, and 0 `JavaScriptCode`.
- Browser and HTTP checks show no Oxygen Component SSR errors.
- `npm test`, PHPStan, and PHPCS pass.

Current caveat:

- `npm run test:maximus:matrix` still fails on baseline converter gates unrelated to the rebuilt Maximus site. Do not claim the matrix is fully green until those baseline converter cases are fixed or intentionally rescoped.

## Product Hardening Still Worth Doing

### 1. More Component Property Types

Current implementation exposes text fields.

Next useful property types:

- CTA URLs.
- CTA labels as explicit button properties.
- image URLs/alt text.
- card icons.
- section background/image toggles.
- selected state for visual questionnaire cards.

### 2. Stronger Token Binding

Variables and selectors exist, but some selector values still use CSS variable strings or custom CSS bridges.

Next step:

1. In Oxygen Builder, manually bind variables to selector controls.
2. Inspect serialized selector data.
3. Add native variable binding where Oxygen supports it.
4. Keep CSS custom properties only as a compatibility bridge.

### 3. Residual Utility Class Reduction

Semantic classes now carry the design system.
Some original Tailwind/source classes remain because they help parity and are also useful context.

Next step:

- classify utilities as removable, semantic-covered, page-specific, or Pro/WindPress-only
- remove redundant utilities when semantic selectors fully cover the style
- keep fixture-specific classes only where they add real value

### 4. Functional Forms

Diagnosis is currently a native visual questionnaire, not a functional form.

Product options:

- visual-only import: current behavior, explicit in report
- functional form import: map to a form plugin/native form element
- questionnaire import: create Oxygen-compatible JS/state behavior

### 5. Admin Product UI

The 10/10 workflow currently lives in scripts and service classes.

The WordPress admin UI should expose:

- design-kit import
- Oxygen-native vs WindPress/Tailwind mode
- import plan preview
- token/class/component/template preview
- replace/update existing kit
- post-import verification report

### 6. Pixel-Perfect Mode

The user explicitly accepted elegant unification over beautiful parity.
Still, a Pro/polish workflow can add:

- desktop/tablet/mobile screenshot diffs
- missing asset detection
- color/typography delta report
- overlap/overflow detection
- auto-fix suggestions

The current Maximus matrix allows expected height variance but still blocks width/breakpoint drift and functional regressions.

## Open Core vs Pro Boundary

Core should keep:

- Oxygen-native conversion.
- selectors/classes.
- variables/global settings.
- headers/footers/templates/pages/blocks.
- page/global style routing.
- Component instances.
- text Component Properties.
- verification report.

Pro can carry:

- WindPress integration.
- Tailwind config parsing and preservation.
- Tailwind-to-token compiler.
- WindPress cache reset.
- advanced Component Properties.
- visual diff automation.
- brand kit/design-system manager.
