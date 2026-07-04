# Quality Gates

Use this checklist before claiming an Oxygen-native import is complete.

## Structural Gates

Required:

- 0 `HtmlCode` elements.
- 0 `CssCode` elements.
- 0 `JavaScriptCode` elements.
- 100% native coverage in import report.
- No `ohc-native-` unscoped legacy classes on frontend.
- Pages return HTTP 200.
- No WordPress critical error.
- Oxygen Builder opens target page/header/component.
- Builder iframe contains expected content.

Useful shell check:

```powershell
@'
<?php
require '/var/www/html/wp-load.php';
$posts = get_posts(['post_type'=>['page','oxygen_header','oxygen_footer','oxygen_template','oxygen_block'],'posts_per_page'=>-1,'post_status'=>'any']);
$bad = 0;
foreach ($posts as $p) {
  $raw = get_post_meta($p->ID, '_oxygen_data', true);
  $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
  $treeJson = is_array($decoded) ? (string)($decoded['tree_json_string'] ?? '') : '';
  $hasCode = str_contains($treeJson, 'CssCode') || str_contains($treeJson, 'HtmlCode') || str_contains($treeJson, 'JavaScriptCode');
  if ($hasCode) $bad++;
}
echo 'badCode=', $bad, PHP_EOL;
'@ | docker exec -i oxyconvo6-wordpress-1 php
```

## Oxygen Design System Gates

Required:

- Semantic selector collection exists.
- Shared classes are used across multiple elements.
- Section outer and inner classes exist.
- Buttons share base and variant classes.
- Cards share base and variant classes.
- Headings/body copy have shared classes.
- Variables and global settings are seeded.
- Header/footer/template are correct Oxygen post types.
- Reusable sections exist as blocks/components.
- Pages use real `OxygenElements\Component` instances for reusable sections.
- Component blocks expose editable text properties through `meta.component.editableProperties`.

Current expected Maximus checks:

```text
Maximus Design System semantic selectors >= 44
section components >= 20
component instances >= 20
editable text properties >= 160
maximus-section usage >= 17
maximus-section-inner usage >= 17
maximus-card usage >= 38
maximus-button usage >= 23
maximus-nav usage >= 4
maximus-question-block usage >= 3
maximus-progress-step usage >= 3
```

## CSS Routing Gates

Required:

- Foundation/runtime CSS global.
- Active shared chrome CSS global only when expressed as canonical semantic rules.
- Page CSS stored per page.
- Component/section CSS stored with component document, not dumped globally.
- When pages use `OxygenElements\Component`, component page CSS must be merged into the host page style until Oxygen reliably enqueues component CSS itself.
- Imported frontend/global CSS is not enqueued on ordinary `wp-admin` screens. Tailwind fallback utilities such as `.fixed`, `.relative`, `.border`, and `.hidden` collide with WordPress admin classes and can break screens like `Pages`.
- Global style asset count should remain small and explainable.
- Generated header/footer residual CSS should not remain global when semantic chrome classes cover the layout.

Current expected Maximus value:

```text
globalStyleAssets: 2
```

After additive Maximus V2 enrichment, expected values are:

```text
globalStyleAssets: 2
selectors: 332
premium semantic selectors: 27
bad code posts: 0
highRiskHosts: []
```

The expected global assets are:

```text
Maximus base site-kit global asset
Maximus V2 source utility fallback
```

Do not allow inactive alternate V2 header/footer CSS to remain global. They are imported as available layouts but should be page-scoped/inactive unless manually activated.
Do not allow stale active generated chrome assets such as `.ohc-maximus-site-header-native-*` or `.ohc-maximus-site-footer-native-*` to survive once `maximus-site-header-inner`, `maximus-site-actions`, `maximus-footer-nav`, and related canonical classes cover the shared chrome.

## Visual Gates

The user explicitly deprioritized perfect visual parity if unification and reusability are elegant.

Still required:

- page is not broken
- header/footer visible where expected
- diagnosis has transactional shell
- diagnosis progress bar is horizontal and uses `maximus-progress-*`
- diagnosis question blocks use `maximus-question-block`, not `maximus-section`
- typography roughly matches brand
- mobile layout is usable
- no obvious overlapping or missing primary content

For true pixel-perfect mode, add:

- baseline screenshots of source HTML
- imported screenshots after build
- automated visual diff
- per-viewport acceptance thresholds
- manual review of representative pages and reusable components

Current matrix gate is intentionally not a pixel-perfect gate. It blocks width/breakpoint drift and large height drift, but allows up to 320px height variance because the user prioritized native reusability over exact source document height.

## Builder Gates

Check at least:

- home page Builder
- header Builder
- one section block Builder

Expected:

- login succeeds or is already authenticated
- Oxygen chrome appears
- iframe count >= 1
- iframe contains expected content
- no visible `HTML Code`, `CSS Code`, or `JavaScript Code` text

## Test Gates

Run:

```powershell
composer test
npm run test:js
npm run test:maximus:matrix
composer lint:phpstan
composer lint:phpcs
```

Current latest results:

```text
PHPUnit: 384 tests, 1109 assertions
JS suites: 11/11 passed
PHPStan: OK
PHPCS: OK
```

Latest additive V2 verification also requires:

```text
npm run import:site:maximus-v2: OK
Browser desktop V2 pages: status 200, no console errors, no overflow, no broken images
V1/V2 CSS checks: component-padding token=16px, body/main font=Inter, h1 font=Noto Serif
Programy grid checks: card=2 / span 5, image=6 / span 7
Global style assets do not grow on repeated V2 import after generated Maximus asset pruning
```

Fixture-media reliability gate:

```text
true broken visible images: 0
source aida-public / Unsplash fixture images are localized to wp-content/uploads/ohc-maximus-media during import
data-ohc-original-src is retained for audit/manual replacement
data:image/svg+xml;base64 placeholders are allowed only when media localization fails
```

Mobile shared-header gate:

```text
viewport < 768px:
overflowX=0
.maximus-site-header .maximus-nav display=none
brand remains visible
```

## Font And Icon Gate

Do not rely on `font-family` alone for Material Symbols. A broken font import can still leave the intended family in computed styles while rendering raw ligature words.

Required:

```text
document.fonts.check('16px Inter') === true
document.fonts.check('48px "Noto Serif"') === true
document.fonts.check('24px "Material Symbols Outlined"') === true
Material Symbols intrinsic ligature width <= 1.75 * font-size for visible icon names
```

Maximus global CSS should use `@font-face`, not `@import`, because Oxygen can concatenate global assets after generated header/page rules. `@import` after a normal style rule is ignored by browsers.

Latest full live visual gate:

```text
artifacts/publishing-audit/maximus-production-final-20260520T171803/visual-audit.json
12 pages x desktop/mobile = 24 checks
issueCount=0
```

## Global CSS Dedupe Gate

For the final Maximus site, global CSS should stay intentionally small:

```text
globalStyleAssets=2
asset 1: Maximus base site-kit global asset
asset 2: Maximus V2 source utility fallback
```

Fail the gate if global CSS contains:

- `.ohc-maximus-site-header-native-`
- `.ohc-maximus-site-footer-native-`
- standalone `/* Extracted from <style> tag */` Material Symbols assets
- duplicate Maximus base or V2 fallback assets

Active shared header/footer should render from semantic classes:

```text
maximus-site-header
maximus-site-header-inner
maximus-site-nav
maximus-site-actions
maximus-mobile-menu-icon
maximus-site-footer
maximus-site-footer-inner
maximus-footer-nav
maximus-footer-legal
maximus-wordmark
```

Desktop expectation:

```text
header height ~= 90px
site nav display=flex
site actions display=flex
overflowX=0
```

Mobile expectation:

```text
header height >= 60px
site nav display=none
site actions display=none
mobile menu icon visible
overflowX=0
```
