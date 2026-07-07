# Maximus Implementation Log

This is the process log for the Oxygen-native Maximus import work.

## Starting Context

The product goal was to let a user generate HTML quickly with AI tools, iterate on it, then import it into WordPress as Oxygen Builder 6 native, editable websites.

Initial hypothesis:

```text
HTML -> converter plugin -> Oxygen JSON/page data
```

Concerns raised during the process:

- large CSS code blocks after import
- Tailwind utility fallback bloat
- whether global CSS should be moved to Oxygen global settings
- whether WindPress should be core or pro
- whether sections should be templates, components, or plain page content
- whether cards/buttons should share Oxygen classes

## Core Product Decision

The best architecture is not just an HTML converter.
It is an Oxygen-native site-kit compiler:

```text
HTML fixtures
  -> sanitize runtime assets
  -> map source nodes to native Oxygen elements
  -> extract tokens
  -> create Oxygen variables/global settings
  -> create semantic selectors
  -> route CSS to page/global stores
  -> create pages
  -> create header/footer/template
  -> create reusable blocks/components
  -> verify frontend and Builder
```

## Major Fixes Implemented

### 1. CSS code block removal

Problem:

The diagnosis fixture produced a large CSS block and Tailwind fallback CSS in the canvas.

Resolution:

- set conversion payloads to avoid `CssCode` insertion
- route page CSS to page style persistence
- route shared/foundation CSS to global style assets
- strip Tailwind CDN/config from site-kit conversion

### 2. Header/footer/template creation

Problem:

Pages were imported, but the site was not a true Oxygen theme/design kit.

Resolution:

- created `oxygen_header`
- created `oxygen_footer`
- created `oxygen_template`
- inserted `OxygenElements\TemplateContentArea`
- applied header/footer conditions to exclude the diagnosis page

### 3. Selector merge bug

Problem:

Batch imports saved selectors repeatedly in one PHP process. Oxygen's helper could return stale request-local cached selectors, causing later imports to overwrite earlier ones.

Resolution:

- `OxygenSelectorRepository::getExistingSelectors()` now reads raw Breakdance/Oxygen global option first.

### 4. Diagnosis form fallback

Problem:

`<form>` and nested `input` elements mapped to `HtmlCode`, causing native coverage to drop.

Resolution:

- for Maximus site-kit build, visual forms are flattened:
  - remove `input`, `textarea`, `select`
  - convert `form` and `label` to editable containers
  - preserve classes and static selected state visually

Tradeoff:

- visual questionnaire is native/editable
- it is not a functional form submission system yet

### 5. Design system class layer

Problem:

The previous result had too many fixture-specific/generated classes and not enough reusable Oxygen semantics.

Resolution:

- added semantic class injection before conversion
- added `Maximus Design System` selector collection
- added shared classes for sections, section inners, containers, grids, cards, buttons, headings, body copy, icons

### 6. Reusable section blocks

Problem:

Fixtures contained many sections that should be reusable in Oxygen, not just buried inside pages.

Resolution:

- extracted each fixture section into `oxygen_block`
- created 20 section blocks
- kept pages as native page content for stable frontend output

Current caveat:

- resolved in the next pass: pages now contain real Component instances rather than section copies

### 7. Global style asset bloat

Problem:

When every component saved page CSS as global CSS, global styles ballooned to 28 assets.

Resolution:

- foundation CSS remains global
- header/footer CSS remains global because header/footer render globally
- section/component CSS persists as document/page style meta

Final global style asset count:

```text
5
```

### 8. True Component instances and Component Properties

Problem:

Reusable section blocks existed, but pages still contained copied native section trees. That was useful, but not a true Oxygen component workflow.

Research:

- `OxygenElements\Component` is defined in `oxygen/subplugins/oxygen-elements/elements/Component/element.php`.
- SSR reads `content.content.block.componentId`.
- Oxygen passes Component property overrides through `content.content.block.targets` and `content.content.block.properties`.
- Target nodes inside the `oxygen_block` expose editable fields via `data.properties.meta.component.editableProperties`.
- Global block rendering happens through `\Breakdance\Render\renderGlobalBlock()`.

Resolution:

- each section block is annotated with editable text Component Properties
- each page section is replaced with an `OxygenElements\Component` node
- standard pages replace direct `maximus-main` sections
- the diagnosis page keeps its own transactional shell and componentizes the progress strip plus main groups
- builder URLs now use `\Breakdance\Admin\get_builder_loader_url()`

Result:

```text
20 page Component instances
166 editable text properties attached to page instances
176 editable text markers across all oxygen_block components
0 page code blocks
0 component code blocks
```

### 9. Matrix gate aligned with native workflow

Problem:

The Maximus matrix failed on visual document height drift, even when frontend rendering, native nodes, editability, style routing, and live gates were correct. The user explicitly prioritized elegant native unification over exact visual height parity.

Resolution:

- width/breakpoint drift remains a strict visual failure
- large height drift remains a failure
- expected height variance up to 320px is accepted
- unit coverage was added for this gate behavior

### 10. Diagnosis visual regression after componentization

Problem:

`/diagnoza-i-dobor-programu/` rendered with a stacked progress bar, block-level grids, and excessive vertical rhythm after sections were replaced by `OxygenElements\Component` instances. The component HTML was present, but generated component/post CSS was not reliably enqueued on the page frontend.

Evidence:

- progress wrapper computed as `display:block` instead of `flex`
- question grids computed as `display:block` instead of `grid`
- internal questionnaire `<section>` nodes had inherited `maximus-section` rhythm before correction
- Oxygen upload CSS files for some component posts were empty, while global style assets were loaded

Resolution:

- added `maximus-questionnaire` and `maximus-question-block` so form-internal sections do not inherit full landing-section padding
- added `maximus-progress-track`, `maximus-progress-line`, `maximus-progress-step`, and `maximus-progress-dot`
- added a global Maximus runtime style bridge for semantic layout classes that must work even when component-scoped CSS is not enqueued
- fixed card selected-state detection so `peer-checked:*` classes do not falsely create `maximus-card-selected`

Browser verification:

```text
progress track: display:flex, justify-content:space-between
progress line: position:absolute, width:832px
question grid: display:grid, grid-template-columns:344px 344px
unselected cards: dark text on paper background
selected primary card: white text on primary background
horizontal overflow: false
```

### 11. Site-wide component layout regression

Problem:

After true Component instances were introduced, all normal pages looked worse than the pre-componentized build. The issue was broader than diagnosis:

- page instances rendered component markup
- component-local page CSS stayed attached to `oxygen_block` posts
- frontend pages loaded their own page CSS, but not the page CSS of each rendered component block
- `<nav>` was incorrectly classified as `maximus-site-header`, which forced nav width/spacing rules onto menu groups

Resolution:

- during `componentizeImportedPages()`, each page now merges its existing page CSS with the page CSS of every inserted component
- merged CSS is persisted through `PageStyleRepository`, so component instances render with their own native generated classes
- `<header>` alone receives `maximus-site-header`; `<nav>` receives `maximus-nav`
- `maximus-nav` is a compact flex row and does not inherit full header width/space-between behavior

Browser verification after rebuild:

```text
home: no overflow, one hero in DOM, header nav separated
diagnosis: progress horizontal, questionnaire grid active
for_whom: header nav separated, hero centered
locations: header nav separated, hero layout intact
```

## Latest Verified Metrics

Report:

```text
artifacts/site-build/maximus-site-build-20260519-051234.json
```

Metrics:

```text
pages: 7
oxygen_header: 1
oxygen_footer: 1
oxygen_template: 1
oxygen_block: 24
section components: 20
component instances: 20
editable text properties attached to page instances: 166
selectors: 245
semantic selectors: 44
variables: 27
palette colors: 20
global style assets: 5
component CSS merged into page styles: 91119 bytes
total persisted page style CSS: 171166 bytes
HtmlCode/CssCode/JavaScriptCode: 0
native coverage: 100%
```

## Verification Performed

Commands:

```powershell
php -l tests\live\build-maximus-site.php
npm run build:site:maximus
npm test
npm run test:maximus:matrix
composer test
npm run test:js
composer lint:phpstan
composer lint:phpcs
```

Current caveat:

```text
npm run test:maximus:matrix currently fails on baseline converter gates:
- native baseline emitted no selectors for Maximus\diagnoza_i_dob_r_programu\code.html
- native baseline has mapped utility residuals for diagnosis and for-whom fixtures

The rebuilt live Maximus site and the reported diagnosis page were verified separately in browser.
```

Browser checks:

- frontend desktop home
- frontend mobile home
- frontend mobile diagnosis
- Oxygen Builder page
- Oxygen Builder header
- Oxygen Builder section block

Screenshots:

```text
artifacts/browser/maximus-home-desktop-system-final.png
artifacts/browser/maximus-home-mobile-system-final.png
artifacts/browser/maximus-diagnosis-mobile-system-final.png
artifacts/browser/maximus-page-builder-system-final.png
artifacts/browser/maximus-header-builder-system-final.png
artifacts/browser/maximus-section-block-builder-system-final.png
```

## Current Build IDs

At latest verification:

```text
front page: 845
diagnosis page: 851
header: 852
first home section block: 859
```

These IDs change after every reset/rebuild.
Always query fresh IDs before Builder tests.

## 12. Additive New Maximus V2 Import

Date:

```text
2026-05-19
```

Scope:

- imported `fixtures/html/Maximus/New Maximus` without resetting the accepted Maximus site
- preserved existing pages, header, footer, template, and existing section components
- created new premium pages under `maximus-premium-*`
- created inactive parallel V2 headers and footers for later manual use
- created V2 section components and replaced page sections with real `OxygenElements\Component` instances
- treated source Tailwind utilities as parity hints and added semantic Maximus selectors as the editable layer

Latest additive report:

```text
artifacts/site-build/maximus-v2-import-20260519-145736.json
```

Created/updated:

```text
pages: 5
inactive headers: 5
inactive footers: 5
section components: 13
page component instances: 13
editable text properties in V2 blocks: 81
premium semantic selectors: 27
existing post ids missing after import: 0
HtmlCode/CssCode/JavaScriptCode in imported V2 posts: 0
```

Important implementation decisions:

- The V2 import is additive and idempotent. Re-running it updates posts with the same slugs and does not increase post counts.
- Source fixtures are not edited. Brand normalization happens during conversion and persisted Oxygen tree normalization.
- `PHYSICAL CULTURE` source brand text is normalized to `MAXIMUS`, including the shared active footer and footer component because new pages inherit the active global footer.
- Header/footer V2 variants are imported as `oxygen_header` / `oxygen_footer`, then template settings are cleared so they stay inactive and available.
- Contact form controls are converted into native visual Oxygen-editable fields. Functional submit wiring is intentionally not guessed because Fluent Forms/Oxygen Forms integration was not installed/configured for this repo.
- A source `<header>` inside `main` must not behave like a site header. The V2 importer adds `maximus-content-header` and scopes a stronger selector rule to `.maximus-v2-page .maximus-content-header` to override the base site-header flex row.
- Component labels with collapsed text such as `Dziedzictwomaximus` are normalized for readable Oxygen admin titles while preserving existing slugs.
- V2 sections must not keep legacy V1 section classes (`maximus-section`, `maximus-section-cards`, etc.). Those classes impose V1 flex centering, backgrounds, and `section-gap` padding and will break source V2 grid sections. The importer removes those classes from V2 `<section>` nodes and adds only `maximus-v2-section`.
- Responsive Tailwind grid placement needs compound fallback rules when `col-span-*` and `col-start-*` appear together. A plain `.md\:col-span-5` shorthand can override `.md\:col-start-2`; add higher-specificity compound rules such as `.md\:col-span-5.md\:col-start-2 { grid-column: 2 / span 5 !important; }`.
- Shared spacing tokens are the source of truth across V1 and V2. `--ohc-space-component-padding` is `16px`, matching both fixture Tailwind configs. Larger source-specific card padding may still exist as page/component residual CSS where the fixture explicitly used utilities such as `p-8` or `p-10`.
- Before saving generated Maximus global CSS, prune old generated Maximus base/V2 global style assets by marker. This prevents repeated local import iterations from leaving obsolete global fallback CSS in the library and confusing cascade order.

Final verification:

```text
npm run import:site:maximus-v2
npm test
composer lint:phpstan
composer lint:phpcs
audit-oxygen-site.php: codeBlocks=0, componentInstances=33, editableProperties=257
check-component-css-routing.php: highRiskHosts=[]
Browser desktop: 5 V2 pages HTTP 200, no console errors, no horizontal overflow, no broken images
Browser CSS checks: component-padding token=16px, body/main font=Inter, h1 font=Noto Serif, V2 Programy grid card=2 / span 5, image=6 / span 7
```

New V2 URLs:

```text
http://oxyconvo6.localhost/maximus-premium-dla-kogo/
http://oxyconvo6.localhost/maximus-premium-programy/
http://oxyconvo6.localhost/maximus-premium-startery/
http://oxyconvo6.localhost/maximus-premium-o-nas/
http://oxyconvo6.localhost/maximus-premium-kontakt/
```

## 13. V1/V2 CSS Regression Audit And Fix

Date:

```text
2026-05-19
```

User-visible symptom:

- V1 pages looked inconsistent after the V2/layout normalization pass.
- Colors, fonts, spacing, and page feel drifted away from source/brand even though Oxygen elements existed.
- On the in-app browser's narrow viewport, the shared header also looked clipped because desktop nav stayed visible.

Root causes found:

- `--ohc-space-component-padding` had been changed to `32px`, but both V1 and V2 Tailwind configs consistently define `component-padding=16px`.
- A high-specificity V2 global rule targeted broad card classes (`.maximus-card.maximus-card...`) and beat page/component-specific CSS in V1.
- V2 inactive alternate headers/footers had been routed as global CSS, so their generated CSS could leak into the accepted V1 theme.
- Maximus generated global CSS pruning was whitespace-sensitive, so stale Material Symbols/generated assets survived repeated imports.
- Several fixtures used ephemeral `lh3.googleusercontent.com/aida-public/...` image URLs. These are not reliable source assets and caused broken visual panels when the external URLs failed.

Fixes implemented:

- Restored `--ohc-space-component-padding` to `16px` in design tokens and global CSS.
- Removed the broad high-specificity `.maximus-card.maximus-card...` V2 override.
- Kept V2 utility fallback global, but changed inactive V2 header/footer imports to page-scoped CSS.
- Made `pruneMaximusGeneratedGlobalStyles()` whitespace-insensitive so repeated imports remove stale generated assets.
- Stabilized Maximus fixture media at import time: `aida-public` image URLs are replaced with deterministic brand SVG data URIs, while `data-ohc-original-src` records the original source URL for manual replacement later.
- Added a mobile shared-header rule: on viewports below 768px, desktop `.maximus-nav` is hidden and the header no longer clips.

Latest safe reimport:

```text
node tests/live/run-maximus-site-build.cjs --no-reset
npm run import:site:maximus-v2
```

Latest reports:

```text
artifacts/site-build/maximus-site-build-20260519-152217.json
artifacts/site-build/maximus-v2-import-20260519-152235.json
```

Historical expected global CSS library at this stage, before the final production dedupe in section 15:

```text
global asset count was four
- active V1 generated header CSS
- active V1 generated footer CSS
- Maximus base site-kit global asset
- Maximus V2 source utility fallback
```

This was superseded by the final semantic chrome pass. The current accepted value is two global assets: base Maximus foundation CSS and V2 utility fallback.

Fresh verification:

```text
audit-oxygen-site.php: pages=12, headers=6, footers=6, template=1, blocks=37
audit-oxygen-site.php: componentInstances=33, editableProperties=257, codeBlocks=0
check-component-css-routing.php: highRiskHosts=[]
Browser audit: 12/12 pages, issuePages=[]
Browser audit: overflowX=0 on all checked pages
Browser audit: bodyFont=Inter, h1Font=Noto Serif, componentPaddingVar=16px
Browser audit: trueBrokenImages=0
Browser mobile header: .maximus-site-header .maximus-nav display=none
npm test: JS 11/11, PHP 384 tests / 1109 assertions
composer lint:phpstan: OK
composer lint:phpcs: OK
```

Important caveat:

- The stabilized SVGs are a reliability placeholder for missing/ephemeral AI image URLs, not final photography. They preserve Oxygen-native editable Image elements and keep the site visually coherent. For a production Maximus theme, replace them later with real licensed/local media through Oxygen image controls or a media-import step.

## 14. Publishing Polish Pass: Fixture Drift, Fonts, Icons, Media

Date:

```text
2026-05-20
```

User goal:

- Make the Maximus site publish-ready now, even if some fixes are manual/theme-specific.
- Compare every imported page/component with its source fixture.
- Preserve native Oxygen editability and reusable blocks/classes.
- Avoid reset/data loss while improving the accepted V1 and additive V2 imports.

Root causes found:

- Font `@import` rules were stored in a global asset that Oxygen prints after header/page rules. CSS `@import` is ignored unless it appears before style rules, so Inter/Noto/Material Symbols could appear correct in `font-family` but not actually load as webfonts.
- Material Symbols checks that only inspect `font-family` are insufficient. A failed icon font can still report the intended family while rendering raw ligature text. Use intrinsic ligature width or screenshots.
- Some earlier icon checks produced false positives because Oxygen text nodes can be block-level and full width even when the ligature glyph itself renders correctly.
- Responsive Tailwind fallback needed compound selectors for `grid-cols-*` plus breakpoint variants so desktop grids do not collapse to one column.
- Ephemeral `lh3.googleusercontent.com/aida-public` and Unsplash source images should not remain hotlinked in the final site.

Fixes implemented:

- Replaced runtime font `@import` dependency in the Maximus global asset with valid `@font-face` declarations for Inter, Noto Serif, Noto Serif italic, and Material Symbols Outlined. `@font-face` is valid regardless of Oxygen's concatenation order.
- Added `font-feature-settings: "liga"` and matching WebKit settings to Material Symbols rules and V2 fallback rules.
- Localized remote source images into WordPress uploads under `wp-content/uploads/ohc-maximus-media/` during import. The original remote URL is retained as `data-ohc-original-src`.
- Kept placeholder SVG fallback only for failed media downloads.
- Added compound responsive fallback rules for V1/V2 Tailwind grid combinations.
- Reimported safely with no reset:

```powershell
node tests/live/run-maximus-site-build.cjs --no-reset
npm run import:site:maximus-v2
```

Latest reports:

```text
artifacts/site-build/maximus-site-build-20260520-153353.json
artifacts/site-build/maximus-v2-import-20260520-153411.json
```

Visual audit artifacts:

```text
artifacts/publishing-audit/maximus-pass3-20260520T153655/source-vs-live-desktop-contact-sheet.jpg
artifacts/publishing-audit/maximus-pass3-20260520T153655/source-vs-live-mobile-contact-sheet.jpg
artifacts/publishing-audit/maximus-final-live-20260520T154412/audit.json
```

Final verification:

```text
final live audit: 24 checks (12 pages x desktop/mobile), issueCount=0
true broken images: 0
remote fixture image URLs on live pages: 0
raw icon ligature leaks by intrinsic width: 0
horizontal scrollWidth overflow: 0
webfonts: Inter, Noto Serif, Material Symbols loaded
audit-oxygen-site.php: pages=12, headers=6, footers=6, template=1, blocks=37
audit-oxygen-site.php: componentInstances=33, editableProperties=257, codeBlocks=0
check-component-css-routing.php: highRiskHosts=[]
npm test: JS 11/11, PHP 384 tests / 1109 assertions
composer lint:phpstan: OK
composer lint:phpcs: OK
```

Current production-readiness note:

- The live Maximus pages are not pixel-perfect to every source fixture. They are intentionally unified around the Maximus theme, active Maximus header/footer, reusable semantic classes, local media, Oxygen blocks, and editable Component Properties. This is the preferred state for publishing and further manual Oxygen tweaking.

## 15. Final Production Pass: Global Dedupe And Canonical Chrome

Date:

```text
2026-05-20
```

User goal:

- Run a global pass across all pages/components.
- Deduplicate CSS artifacts.
- Ensure all source fixtures have pages.
- Ensure all sections are reusable blocks.
- Ensure canonical classes are used for global elements, especially buttons and shared chrome.
- Stop only when no further useful production changes are visible.

Issues found:

- Global style assets had grown to 4. Two were active header/footer generated `.ohc-maximus-site-*-native-*` assets, and two were redundant Material Symbols `<style>` extracts.
- Removing the generated header/footer assets without replacement made the header grow and mobile chrome collapse, proving they were functionally active.
- The first semantic replacement pass over-classified the whole header inner wrapper as `maximus-site-actions`, which hid the mobile header. This was caught by computed layout checks before final acceptance.

Fixes implemented:

- Added canonical semantic chrome classes:
  - `maximus-site-header-inner`
  - `maximus-site-nav`
  - `maximus-site-actions`
  - `maximus-mobile-menu-icon`
  - `maximus-site-footer-inner`
  - `maximus-footer-nav`
  - `maximus-footer-legal`
  - `maximus-wordmark`
- Moved active header/footer residual generated CSS out of global scope. Shared frontend chrome is now driven by Maximus semantic global CSS.
- Added pruning markers for stale `.ohc-maximus-site-header-native-*` and `.ohc-maximus-site-footer-native-*` global assets.
- Filtered redundant Material Symbols source `<style>` global CSS because the base Maximus asset already provides valid `@font-face` and symbol rules.
- Reimported safely with no reset:

```powershell
node tests/live/run-maximus-site-build.cjs --no-reset
npm run import:site:maximus-v2
```

Latest reports:

```text
artifacts/site-build/maximus-site-build-20260520-171505.json
artifacts/site-build/maximus-v2-import-20260520-171528.json
```

Final visual report:

```text
artifacts/publishing-audit/maximus-production-final-20260520T171803/REPORT.md
artifacts/publishing-audit/maximus-production-final-20260520T171803/visual-audit.json
```

Final metrics:

```text
source code.html fixtures: 12
published pages: 12
reusable section blocks: 33
page component instances: 33
editable text properties: 257
selectors: 339
variables: 27
global style assets: 2
code blocks: 0
visual audit: 24 checks, issueCount=0
component CSS routing: highRiskHosts=[]
npm test: JS 11/11, PHP 384 tests / 1109 assertions
composer lint:phpstan: OK
composer lint:phpcs: OK
```

Intentional boundary at this 2026-05-20 stage, superseded by the 2026-05-26 Fluent Forms pivot below:

- The contact form remains an Oxygen-native editable visual layout. Functional submit wiring should be done through the chosen form system, such as Fluent Forms or Oxygen Forms, and should not be guessed by the importer.

## Production Migration Hotfixes - 2026-05-22

After migration to `avemaximus.com`, production still contained hardcoded `oxyconvo6.localhost` references in Oxygen/page/global data. A one-time WPCodeBox cleanup snippet recursively scanned WordPress tables, preserved serialized payloads, replaced local-domain variants with `https://avemaximus.com`, flushed cache, and wrote a backup.

Production result:

```text
changed database cells: 94
backup: /home/u363549550/domains/avemaximus.com/public_html/wp-content/uploads/codex-ave-maximus-url-migration-cleanup-backup-20260522-054858.json
public pages checked: 15
pages with oxyconvo6.localhost references: 0
broken production resources detected: 0
```

The WordPress `Pages` admin screen then failed to scroll because the importer enqueued global Maximus/Tailwind fallback CSS on ordinary `wp-admin` screens. The fallback utility `.fixed { position: fixed !important; }` collided with the WordPress core `table.wp-list-table.fixed` class and made the pages table fixed-position. Production `oxygen-html-converter/src/Plugin.php` was patched to load imported global/page CSS only on the frontend and Oxygen Builder/canvas requests, not generic admin screens.

Production plugin backup:

```text
/home/u363549550/domains/avemaximus.com/public_html/wp-content/uploads/codex-ave-maximus-plugin-admin-css-gate-backup-20260522-060104.php
```

Generic lesson:

- Tailwind utility fallback/global imported CSS must never load on normal WordPress admin screens.
- Builder/canvas requests still need imported styles for visual editing.
- Add admin regression checks for `edit.php?post_type=page`: no `oxy-html-converter-global-styles-inline-css`, `table.wp-list-table.fixed` computed `position: static`, and mouse scroll changes `window.scrollY`.

## Contact Form Implementation - 2026-05-26

Initial decision:

- Use Breakdance Forms for Oxygen for `/kontakt/` because it renders as an Oxygen-native editable `EssentialElements\FormBuilder`, stores submissions as `breakdance_form_res`, and avoids a shortcode/runtime bridge.
- Fluent Forms was verified locally through a standalone shortcode prototype, but it is less native inside the current Oxygen `_oxygen_data` contact page unless a bridge/shortcode element is introduced.
- Keep email notification disabled until SMTP/mail is explicitly tested in the target environment. The deterministic action is `store_submission`.

Local implementation:

- Replaced visual-only contact form node `12` with `EssentialElements\FormBuilder`.
- Fields: name, email/phone contact, persona radio, preferred location, goal, message.
- Scoped page CSS marker: `Codex Maximus native Breakdance contact form`.
- Important CSS lesson: Breakdance forms use a 12-column grid and fields default to `grid-column: span 12`, so fixture-like two-column layouts need high-specificity selectors such as `#maximus-contact-form.breakdance-form > .breakdance-form-field`.

Local verification:

```text
URL: http://maximus.localhost/kontakt/
frontend status: 200
real form: <form id="maximus-contact-form" class="breakdance-form ...">
_oxygen_data outer JSON: OK
tree_json_string: OK
FormBuilder node: present
browser submit: success message visible
stored submission: breakdance_form_res ID 1090
fields stored: name, contact, persona, location, goal, message
browser console errors/warnings: none
```

Production transfer:

- Prepared one-time WPCodeBox snippet:

```text
tools/production-snippets/maximus-contact-breakdance-form-wpcodebox.php
```

- Snippet creates a targeted backup of `_oxygen_data` and `_oxy_html_converter_page_styles`, replaces contact form node `12`, appends scoped CSS, clears Oxygen caches, and writes `codex_maximus_contact_form_report`.
- Production wp-admin session was at login screen during this pass; snippet should be run after login, then disabled after verification.

## Contact Form Pivot To Fluent Forms - 2026-05-26

Reason:

- Requirements expanded from "real form" to admin notifications, submitter confirmation, FunnelKit Automations, and Turnstile/reCAPTCHA.
- Local code inspection found FunnelKit Automations Pro has a native Fluent Forms trigger on `fluentform_submission_inserted`.
- FunnelKit's Breakdance module expects Breakdance-shaped form data; Oxygen FormBuilder submissions in this stack store `_oxygen_*` meta, so Breakdance/Oxygen Forms would need a compatibility shim for reliable FunnelKit use.
- Fluent Forms has native Turnstile/reCAPTCHA/hCaptcha components and notification storage, so it is the cleaner production path.

Implemented locally:

```text
URL: http://maximus.localhost/kontakt/
form engine: Fluent Forms
embed: OxygenElements\Shortcode with [fluentform id="3"]
Oxygen node: 12
fields:
  first_name required
  last_name optional
  email required
  phone required
  persona required
  goal optional
  message required
removed: preferred location
```

Important Fluent Forms pattern:

- Use Fluent `container` fields for the horizontal rows, not a CSS-only grid:
  - row 1: first name + last name
  - row 2: email + phone
  - row 3: persona + goal
- Use CSS only for brand styling and responsive polish, scoped to `.maximus-fluent-form-embed`.
- Do not write raw CSS into `_oxy_html_converter_page_styles`; it must remain JSON with `version`, `updatedAt`, `css`, `bytes`, and `hash`.
- Fluent Form Styler is Pro, but it is not required for deterministic developer-owned CSS. Pro is only useful if the site owner wants no-code visual form styling inside Fluent.

Local verification:

```text
frontend render: OK
layout: desktop two-column Fluent containers, mobile single column
phone required: true
preferred location: removed
submission success message: OK
wp_fluentform_submissions count for form 3: 2
latest submission fields: first_name, last_name, email, phone, persona, goal, message
skill updated: C:\Users\Skicu\.codex\skills\wordpress-oxygen-forms-operator\SKILL.md
skill validation: OK
```

Production rule:

- Do not run the old Breakdance production snippet for this form.
- Production migration should create/update the Fluent form, replace node `12` with `OxygenElements\Shortcode`, preserve `_oxygen_data` and `_oxy_html_converter_page_styles` backups, then disable the WPCodeBox migration snippet after verification.

Production implementation:

```text
site: https://avemaximus.com/kontakt/
WPCodeBox snippet: One-time Maximus Fluent contact form migration
snippet id: 2
snippet status after run: disabled
production form_id: 3
backup: /home/u363549550/domains/avemaximus.com/public_html/wp-content/uploads/codex-maximus-contact-fluent-backup-20260526-091311.json
production report: Codex Maximus Fluent contact migration: success
prepared snippet file: D:\WordPress\Html to Oxygen\oxygen-html-converter-dev\tools\production-snippets\maximus-contact-fluent-form-wpcodebox.php
```

Production verification:

```text
frontend form class: frm-fluent-form fluent_form_3
preferred location field: absent
required fields: Imię, Email, Telefon, Kim jesteś?, Wiadomość / dodatkowe informacje
desktop rows:
  Imię + Nazwisko
  Email + Telefon
  Kim jesteś? + Główny cel
submit success message: Dziękujemy. Skontaktujemy się z Tobą w ciągu 24h.
Fluent Forms entry: created, visible under Maximus Kontakt entries
FluentSMTP logs:
  Otrzymaliśmy Twoje zgłoszenie Maximus -> codex-production-test@example.com -> sent
  [Maximus] Nowe zgłoszenie: Codex Production Test -> admin@example.com -> sent
```

Security/automation status:

- Fluent Forms Free + installed code supports reCAPTCHA/hCaptcha/Turnstile fields.
- Do not add Turnstile/reCAPTCHA until production global keys are configured; adding the field without keys can break public submission.
- FunnelKit Automations Pro is active on production and is the correct automation layer for Fluent Forms submissions.
- Fluent Forms Pro is not required for the current deterministic CSS implementation. It is only useful if no-code Form Styler control is desired.

## Breakdance Forms For Oxygen Removal - 2026-05-26

Decision:

- Breakdance Forms for Oxygen is no longer needed because `/kontakt/` is now a Fluent Forms shortcode embedded in Oxygen.
- Keep `breakdance-elements-for-oxygen/plugin.php`; it is a broader Oxygen element compatibility bridge and was not part of the form migration.

Local result:

```text
breakdance-forms-for-oxygen/plugin.php: deactivated and deleted
breakdance-elements-for-oxygen/plugin.php: still active
Kontakt frontend: Fluent form renders
Breakdance form markup on Kontakt: absent
```

Production result:

```text
wp-admin Plugins search: Breakdance Forms for Oxygen deactivated, then deleted
success notice: Breakdance Forms for Oxygen was successfully deleted
post-delete plugin search: Breakdance Forms row absent, Breakdance Elements still active
https://avemaximus.com/kontakt/: Fluent form 3 renders, phone remains required, preferred location absent
browser console errors after contact smoke test: none
```

Generic lesson persisted:

- `wordpress-oxygen-forms-operator` documents when to remove a form plugin after migration to Fluent.
- `wordpress-production-wpcodebox-operator` documents plugin decommissioning and the fallback to native wp-admin plugin controls when they are safer than forcing a WPCodeBox snippet.
