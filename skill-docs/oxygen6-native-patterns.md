# Oxygen 6 Native Patterns

This document captures practical Oxygen 6 rules used by the importer. Maximus names and counts below are examples from one site-kit proof, not mandatory names for generic Core imports.

## Classes Are The Center Of The Design System

Official Oxygen 6 documentation says Oxygen's design system is centered around classes. A class can be applied to multiple elements, styled once, managed in the Selectors Panel, renamed, duplicated, removed, deleted, locked, and given states or nested selectors.

Importer implication:

- Repeated visual primitives must receive shared semantic classes.
- Do not leave every repeated card/button/section as only generated technical classes.
- Keep generated fixture classes only when needed for parity.
- Put product/design-system classes in a dedicated collection, e.g. `Imported HTML` for generic fixture imports or `Maximus Design System` for the Maximus proof.
- Use generated classes for fixture-specific residual styling only.

Current semantic class examples:

```text
maximus-page
maximus-main
maximus-shell
maximus-site-header
maximus-site-footer
maximus-nav
maximus-component-instance
maximus-section-component-instance
maximus-section
maximus-section-inner
maximus-section-hero
maximus-section-cards
maximus-section-cta
maximus-container
maximus-grid
maximus-grid-2
maximus-grid-3
maximus-grid-4
maximus-heading
maximus-heading-xl
maximus-body-copy
maximus-eyebrow
maximus-button
maximus-button-primary
maximus-button-secondary
maximus-button-outline
maximus-card
maximus-card-program
maximus-card-path
maximus-card-location
maximus-card-selected
maximus-icon
```

## Variables Are The Token Layer

Official Oxygen variables support colors, numbers, units, font families, and image URLs. Variables should be organized into collections.

Importer implication:

- Brand colors, spacing, and fonts belong in Oxygen variables and global settings.
- CSS custom properties may be used as runtime bridge, but Oxygen variables/global settings are the proper editing target.
- For 10/10, selector controls should be bound to Oxygen variables wherever the serialized Oxygen format supports it.

Current collections:

```text
Imported HTML Colors
Imported HTML Spacing
Imported HTML Fonts
```

Current Maximus token examples:

```text
--ohc-oxblood-primary
--ohc-oxblood-deep
--ohc-ivory-base
--ohc-paper-bright
--ohc-paper-soft
--ohc-ink-black
--ohc-ink-soft
--ohc-space-section-gap
--ohc-space-gutter-grid
--ohc-font-hero-serif
--ohc-font-body-main
```

## Templates vs Components vs Sections

### Template

Use an Oxygen Template when a consistent shell must wrap dynamic or page-specific content. The Oxygen `Template Content Area` is the placeholder where each page's own content renders.

Current pattern:

- `oxygen_template`: `Maximus Page Template`
- contains `OxygenElements\TemplateContentArea`
- should remain simple and structural
- do not turn each fixture section into a template

### Header and Footer

Use Oxygen Header/Footer post types for site chrome. Use templating conditions and priority to decide where they appear.

Current pattern:

- `oxygen_header`: `Maximus Site Header`
- `oxygen_footer`: `Maximus Site Footer`
- condition excludes diagnosis page because diagnosis keeps its own transactional shell
- header/footer styles are global because these render outside individual page styles

### Component / Block

Use Oxygen Components/Blocks for reusable sections, cards, CTA units, header/footer alternates, and repeatable design patterns.

Official docs say design changes to a Component apply to all instances and Component Properties can expose editable content per instance.

Current pattern:

- section fixtures are extracted into `oxygen_block` posts
- pages contain real `OxygenElements\Component` nodes that reference those section blocks
- each section block remains a native Oxygen tree, not `HtmlCode`
- text nodes inside section blocks expose Component Properties through Oxygen's observed `targets`/`properties` format

Important caveat:

- Component Properties are currently generated for text content.
- Images, links, icons, repeaters, and functional form state are still product hardening work.

Observed Oxygen 6 serialized shape:

```php
// Page node
[
  'data' => [
    'type' => 'OxygenElements\\Component',
    'properties' => [
      'content' => [
        'content' => [
          'block' => [
            'componentId' => 123,
            'targets' => [
              [
                'nodeId' => 8,
                'propertyKey' => 'maximus_section_text_8',
                'controlPath' => 'content.content.text',
              ],
            ],
            'properties' => [
              'maximus_section_text_8' => 'Editable text',
            ],
          ],
        ],
      ],
    ],
  ],
]

// Target node inside the oxygen_block
[
  'data' => [
    'properties' => [
      'meta' => [
        'component' => [
          'editableProperties' => [
            [
              'enabled' => true,
              'label' => 'Section heading: Example',
              'controlPath' => 'content.content.text',
              'propertyKey' => 'maximus_section_text_8',
            ],
          ],
        ],
      ],
    ],
  ],
]
```

Source of truth in Oxygen 6 plugin:

```text
oxygen/subplugins/oxygen-elements/elements/Component/element.php
oxygen/subplugins/oxygen-elements/elements/Component/ssr.php
oxygen/subplugins/oxygen-elements/elements/Component/component.php
oxygen/plugin/breakdance-oxygen/components.php
oxygen/plugin/render/global-blocks.php
```

## Section Structure

Use an outer/inner pattern:

```text
section.maximus-section.maximus-section-*
  div.maximus-section-inner.maximus-container
    content
```

Why:

- outer class controls section background, vertical rhythm, full-width behavior
- inner class controls max width, gutters, and content alignment
- this matches Oxygen's class-first editing model

Current implementation marks the first suitable child of each section as `maximus-section-inner`.

## Header And Nav

Do not assign full header semantics to nested navigation.

Use:

```text
header -> maximus-site-header maximus-shell
nav -> maximus-nav
```

Reason:

- `maximus-site-header` owns full-width header behavior and `justify-content: space-between`
- `nav` should remain compact and gap-driven
- applying header rules to `nav` causes menu items to spread across the row and collide with the CTA group

## CSS Routing

Do not put large CSS into a `CssCode` element inside the Oxygen canvas.

Current routing:

- page-specific CSS goes to page style persistence
- active shared header/footer behavior goes to semantic global CSS because chrome renders globally
- generated header/footer residual CSS stays document-scoped or is pruned once semantic chrome covers it
- foundation CSS goes to one global asset
- pages that insert `OxygenElements\Component` also merge each inserted component's page CSS into the host page CSS
- source Tailwind CDN/config is stripped from the canvas
- Material Symbols and fonts are registered via foundation CSS

The result must have no visible editable code blocks:

```text
HtmlCode = 0
CssCode = 0
JavaScriptCode = 0
```

## Tailwind and WindPress

Core/open plugin should prioritize native Oxygen output.

Recommended product split:

- Core: Oxygen-native conversion, variables, selectors, templates, blocks, page styles, plus Tailwind utility hints or fallback CSS when they are materialized into native output safely.
- Pro: Tailwind runtime preservation, Tailwind config parsing, WindPress class mode, WindPress cache reset, and workflows that avoid generated fallback utilities because WindPress can compile them.

For inline HTML containing Tailwind:

- Native path: map common utilities to Oxygen controls/classes and route residual CSS to page/global styles.
- WindPress path: preserve Tailwind semantics and avoid generating redundant fallback utilities where WindPress can compile them.

## Forms

The Maximus diagnosis fixture had a `<form>` with `label/input` radio groups. The custom Maximus site-kit pre-normalizer flattens this into native visual containers so that specific proof remains editable and avoids `HtmlCode`.

This is correct for visual/template import.
It is not a full functional form import.

Generic Core does not flatten arbitrary forms by default. Forms remain reported as unsupported/static fallback unless a verified native form element or approved plugin integration owns the behavior.

For 10/10 product:

- if user asks for a custom visual questionnaire inside an owned site-kit pre-normalizer, flatten to native cards
- if user asks for functional form behavior, map to a supported native form element/plugin integration
- do not silently preserve source form as `HtmlCode` without reporting the unsupported boundary or expected fallback

## Diagnosis Transactional Layout

Do not classify `<section>` elements inside a source `<form>` as full landing-page sections.

Use:

```text
form -> maximus-questionnaire
form section -> maximus-question-block
progress wrapper -> maximus-progress-track
progress line -> maximus-progress-line
progress card -> maximus-progress-step
progress dot -> maximus-progress-dot
```

Reason:

- landing sections need full vertical rhythm
- questionnaire blocks need compact form rhythm
- component instances may render without their generated component CSS on the page frontend
- semantic global classes keep the layout native, editable, and reliable

When detecting selected card variants, ignore potential-state utilities such as `peer-checked:bg-primary`, `peer-checked:border-primary`, and `hover:*`. Only exact source state classes such as `bg-primary`, `bg-ink-black`, or `text-on-primary` should create `maximus-card-selected`.
