# Maximus Example Architecture

This file maps the Maximus site-kit example implementation to concrete files and functions. It is useful proof material, but the generic Core acceptance contract is the stable fixture/live/visual gate documented in `quality-gates.md`.

## Entry Point

Command:

```powershell
npm run build:site:maximus
```

Defined in:

```text
package.json
```

Runner:

```text
tests/live/run-maximus-site-build.cjs
```

Build script copied into the WordPress Docker container:

```text
tests/live/build-maximus-site.php
```

## Important Implementation Functions

### Build orchestration

```text
tests/live/build-maximus-site.php
```

Important functions:

- `resetLocalMaximusSite()` - resets pages, posts, Oxygen post types, menus, selectors, variables, global settings, global styles.
- `seedMaximusBrandSystem()` - seeds variables, global settings, design system selectors, and foundation CSS.
- `maximusFixtureDefinitions()` - maps fixture folders to page titles/slugs.
- `buildConversionPayload()` - sanitizes and converts HTML into Oxygen payload.
- `importOxygenDocumentPost()` - imports header/footer/blocks.
- `importMaximusSectionComponents()` - extracts fixture sections into reusable `oxygen_block` posts.
- `enableMaximusComponentProperties()` - annotates block text nodes with Oxygen Component editable-property metadata.
- `componentizeImportedPages()` - replaces imported page sections with real `OxygenElements\Component` instances.
- `buildOxygenComponentInstanceNode()` - serializes the Oxygen Component element shape.
- `createTemplateContentAreaTemplate()` - creates the main Oxygen page template with Template Content Area.
- `collectMaximusDesignSystemSummary()` - reports semantic selector and shared class usage.

### Source HTML normalization

Run before TreeBuilder conversion:

- `stripMaximusRuntimeHeadAssets()` removes Tailwind CDN, `tailwind.config`, Google font links, and duplicated Material Symbols style tags.
- `flattenMaximusFormsForNativeEditing()` converts source `form`/`label` visual questionnaire markup into editable containers and removes `input/textarea/select`.
- `applyMaximusDesignSystemClasses()` adds semantic Oxygen classes to source nodes before conversion.

### Design system selectors

Defined in:

```text
maximusDesignSystemSelectors()
```

Selector collection:

```text
Maximus Design System
```

The importer attaches these selectors to each payload via:

```text
attachMaximusDesignSystemSelectors()
```

### Section components

Reusable section components are created by:

```text
importMaximusSectionComponents()
extractMaximusReusableSectionFragments()
```

Each relevant `<section>` becomes an `oxygen_block`.
For diagnosis, the progress strip and visual questionnaire blocks are also extracted.

Current count:

```text
20 section components
```

### Component instances

After section components are created, pages are rewritten by:

```text
componentizeImportedPages()
componentizeStandardPageTree()
componentizeDiagnosisPageTree()
```

Current count:

```text
20 OxygenElements\Component instances across 7 pages
166 editable text properties wired into page instances
```

Standard pages replace direct `maximus-main` section children with component instances.
The diagnosis page keeps its local transactional shell and replaces the progress strip plus the two main content groups with component instances.

The Component instance payload follows Oxygen 6's observed shape:

```text
content.content.block.componentId
content.content.block.targets
content.content.block.properties
```

The target block nodes expose:

```text
data.properties.meta.component.editableProperties
```

### Selector persistence

File:

```text
src/Services/OxygenSelectorRepository.php
```

Important bug fix:

`getExistingSelectors()` reads `\Breakdance\Data\get_global_option('oxy_selectors_json_string')` before calling Oxygen's helper, because the helper can use request-local static cache during a batch import. Without this, repeated saves in one PHP process can overwrite earlier selectors.

Persisted targets:

- `oxy_selectors_json_string`
- `oxy_selectors_collections_json_string`
- `breakdance_classes_json_string`

### Style persistence

Files:

```text
src/Services/GlobalStyleRepository.php
src/Services/PageStyleRepository.php
src/Services/StyleRoutingService.php
```

Current routing:

- foundation CSS: global style asset
- active shared chrome CSS: canonical semantic rules in the base global style asset
- page CSS: page style meta
- section/component CSS: component page style meta
- inactive or alternate header/footer residual CSS: document/page scoped, not global

Do not depend on generated `.ohc-*` header/footer CSS as permanent global assets when the layout can be expressed through canonical semantic chrome classes. Header/footer render globally, so their accepted shared styling must be globally available, but the preferred form is the brand foundation asset and selector system, not duplicated generated document CSS.

### Variable/global setting persistence

Files:

```text
src/Services/OxygenVariableRepository.php
src/Services/OxygenGlobalSettingsRepository.php
```

Current token sources:

```text
maximusDesignTokens()
maximusGlobalSettings()
maximusGlobalAssetCss()
```

## Current WordPress/Oxygen Output

Latest verified report:

```text
artifacts/site-build/maximus-site-build-20260519-051234.json
```

Current post counts:

```text
page: 7
post: 0
oxygen_header: 1
oxygen_footer: 1
oxygen_template: 1
oxygen_block: 24
```

Current design system report:

```text
semanticSelectors: 44
componentInstances: 20
editableTextProperties: 166
sectionComponents: 20
maximus-section: 17
maximus-section-inner: 17
maximus-container: 30
maximus-grid: 17
maximus-card: 38
maximus-button: 23
maximus-heading: 44
maximus-body-copy: 41
maximus-component-instance: 20
maximus-section-component-instance: 20
maximus-nav: 4
maximus-questionnaire: 1
maximus-question-block: 3
maximus-progress-step: 3
```

## Known Workspace Caveat

The repository is dirty and contains many pre-existing edits unrelated to this Maximus pass.
Do not revert unrelated changes.
When editing, keep scope tight and inspect files before patching.
