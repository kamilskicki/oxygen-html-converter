# Next Chat Runbook

Use this file when a new chat picks up the project.

## 1. Start Here

Read, in order:

```text
skill-docs/README.md
skill-docs/generic-site-kit-workflow.md
skill-docs/oxygen6-native-patterns.md
skill-docs/current-architecture.md
skill-docs/remaining-to-10-10.md
skill-docs/quality-gates.md
```

Then inspect the current report:

```powershell
Get-Content -Raw artifacts\site-build\maximus-site-build-20260519-051234.json
```

If a newer report exists, use the newest file in:

```text
artifacts/site-build/
```

## 2. Rebuild The Local Site

Run:

```powershell
npm run build:site:maximus
```

This resets the local test site content and rebuilds Maximus from fixtures.
Do not run against a production site.

## 2a. Add New Maximus V2 Fixtures Without Reset

If the task is to enrich the already accepted Maximus site with `fixtures/html/Maximus/New Maximus`, run the additive importer instead:

```powershell
npm run import:site:maximus-v2
```

Latest verified report:

```text
artifacts/site-build/maximus-v2-import-20260519-090412.json
```

Expected additive behavior:

```text
resetPerformed=false
existingPostIdsMissingAfter=[]
pagesCreatedOrUpdated=5
inactiveHeadersCreatedOrUpdated=5
inactiveFootersCreatedOrUpdated=5
sectionComponentsCreatedOrUpdated=13
componentInstancesCreated=13
badCodePosts=0
```

Do not delete or overwrite the accepted original pages/components. V2 headers and footers are imported but intentionally inactive.

V2 frontend URLs:

```text
http://oxyconvo6.localhost/maximus-premium-dla-kogo/
http://oxyconvo6.localhost/maximus-premium-programy/
http://oxyconvo6.localhost/maximus-premium-startery/
http://oxyconvo6.localhost/maximus-premium-o-nas/
http://oxyconvo6.localhost/maximus-premium-kontakt/
```

## 3. Verify Oxygen Data

Use:

```powershell
@'
<?php
require '/var/www/html/wp-load.php';
$posts = get_posts(['post_type'=>['page','oxygen_header','oxygen_footer','oxygen_template','oxygen_block'],'posts_per_page'=>-1,'post_status'=>'any']);
$bad = 0; $semanticMissing = 0; $componentInstances = 0; $editableProperties = 0;
foreach ($posts as $p) {
  $raw = get_post_meta($p->ID, '_oxygen_data', true);
  $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
  $treeJson = is_array($decoded) ? (string)($decoded['tree_json_string'] ?? '') : '';
  $hasCode = str_contains($treeJson, 'CssCode') || str_contains($treeJson, 'HtmlCode') || str_contains($treeJson, 'JavaScriptCode');
  $semantic = str_contains($treeJson, 'maximus-');
  if ($hasCode) $bad++;
  if (!$semantic && $p->post_type !== 'oxygen_template') $semanticMissing++;
  if ($p->post_type === 'page') $componentInstances += substr_count($treeJson, 'OxygenElements\\\\Component');
  if ($p->post_type === 'oxygen_block') $editableProperties += substr_count($treeJson, 'editableProperties');
}
echo 'posts=', count($posts), ' badCode=', $bad, ' semanticMissing=', $semanticMissing, ' componentInstances=', $componentInstances, ' editableProperties=', $editableProperties, PHP_EOL;
'@ | docker exec -i oxyconvo6-wordpress-1 php
```

Expected:

```text
badCode=0
semanticMissing=0
componentInstances=20
editableProperties>=160
```

## 4. Verify Frontend

Check:

```powershell
@'
<?php
require '/var/www/html/wp-load.php';
foreach (['/', '/diagnoza-i-dobor-programu/', '/lokalizacje-klubu/'] as $url) {
 $response = wp_remote_get(home_url($url), ['timeout'=>20]);
 $body = is_wp_error($response) ? '' : wp_remote_retrieve_body($response);
 echo $url, ' status=', is_wp_error($response)?'error':wp_remote_retrieve_response_code($response), ' critical=', strpos($body,'critical error')!==false?'yes':'no', ' oldNative=', strpos($body,'ohc-native-')!==false?'yes':'no', ' semantic=', strpos($body,'maximus-section')!==false?'yes':'no', ' inner=', strpos($body,'maximus-section-inner')!==false?'yes':'no', PHP_EOL;
}
'@ | docker exec -i oxyconvo6-wordpress-1 php
```

Expected:

```text
status=200
critical=no
oldNative=no
semantic=yes
inner=yes
```

For the diagnosis page, also inspect the rendered progress/questionnaire layout. It is a known sensitive area because component-scoped CSS may not be enqueued for `OxygenElements\Component` output.

Expected computed state:

```text
.maximus-progress-track display=flex
.maximus-progress-step count=3
.maximus-questionnaire display=flex
.maximus-question-block padding-top=0
.maximus-question-block .maximus-grid display=grid
```

For normal pages, check the shared header:

```text
header .maximus-nav display=flex
header .maximus-nav width is compact, not full page width
header nav links do not collide with logo or CTA
```

For V2 pages, also check:

```text
no PHYSICAL CULTURE in rendered text
no MAXIMUS MAXIMUS in rendered text
no horizontal overflow on mobile
maximus-premium-programy content header flex-direction=column
```

## 5. Verify Builder

Query fresh IDs:

```powershell
@'
<?php
require '/var/www/html/wp-load.php';
echo wp_json_encode([
  'front' => (int)get_option('page_on_front'),
  'header' => (int)(get_posts(['post_type'=>'oxygen_header','posts_per_page'=>1])[0]->ID ?? 0),
  'firstSectionBlock' => (int)(get_posts(['post_type'=>'oxygen_block','name'=>'maximus-section-home-1-silniejszy-ruch-jasna-droga-jeden-system-dla-calego-domu','posts_per_page'=>1])[0]->ID ?? 0),
], JSON_PRETTY_PRINT), PHP_EOL;
'@ | docker exec -i oxyconvo6-wordpress-1 php
```

Then open Builder URLs:

```text
http://oxyconvo6.localhost/?oxygen=builder&id=<front>
http://oxyconvo6.localhost/?oxygen=builder&id=<header>
http://oxyconvo6.localhost/?oxygen=builder&id=<firstSectionBlock>
```

Expected:

- Builder chrome loads.
- iframe exists.
- iframe contains expected text.
- no login form after authentication.
- no code-block text.

## 6. Run Tests

```powershell
npm test
npm run test:maximus:matrix
composer lint:phpstan
composer lint:phpcs
```

For additive V2 CSS regressions, run:

```powershell
npm run import:site:maximus-v2
```

Then browser-check the five `maximus-premium-*` pages. The critical V2 CSS assertions are:

```text
--ohc-space-component-padding: 16px
body/main font: Inter
h1 font: Noto Serif
form/progress exceptions keep their smaller native padding
/maximus-premium-programy/ first card grid-column: 2 / span 5
/maximus-premium-programy/ first image grid-column: 6 / span 7
no horizontal overflow, no broken images, no console errors
```

If these fail, check for three known causes first:

- old V1 section classes leaked onto V2 sections
- responsive `col-span-*` + `col-start-*` needs a compound fallback rule
- generated `ohc-*`/Tailwind fallback padding is beating the semantic Maximus token
- inactive V2 header/footer CSS leaked into the global CSS library
- repeated import left stale generated Maximus global assets because pruning markers missed whitespace variants

For V1/V2 combined regressions, run the safe additive sequence:

```powershell
node tests/live/run-maximus-site-build.cjs --no-reset
npm run import:site:maximus-v2
```

Do not run raw `npm run build:site:maximus` unless a local reset is explicitly intended; that script resets by default.

Current post-import expectations:

```text
globalStyleAssets=2
codeBlocks=0
componentInstances=33
editableProperties=257
highRiskHosts=[]
```

Fixture media gotcha:

```text
Maximus fixtures contain ephemeral lh3.googleusercontent.com/aida-public image URLs.
The live import now localizes those and Unsplash URLs into wp-content/uploads/ohc-maximus-media.
Only failed downloads should fall back to data:image/svg+xml;base64 brand placeholders.
Check "true broken images" as complete && naturalWidth===0 or src="#"; lazy offscreen images with complete=false are not broken.
```

Mobile shared header gotcha:

```text
At viewport < 768px the active shared header should hide .maximus-site-header .maximus-nav.
If the desktop nav is visible on mobile, it will look clipped even when overflowX=0.
```

As of the final 2026-05-20 production pass, `npm test` includes the Maximus matrix and passes.

## 7. Current Publish-Ready Maximus State

As of the 2026-05-20 publishing polish pass, use this sequence to reproduce the accepted site without deleting existing posts:

```powershell
node tests/live/run-maximus-site-build.cjs --no-reset
npm run import:site:maximus-v2
```

Latest known-good reports:

```text
artifacts/site-build/maximus-site-build-20260520-171505.json
artifacts/site-build/maximus-v2-import-20260520-171528.json
```

Latest visual and structural proof:

```text
artifacts/publishing-audit/maximus-production-final-20260520T171803/REPORT.md
artifacts/publishing-audit/maximus-production-final-20260520T171803/visual-audit.json
24 live checks: 12 pages x desktop/mobile, issueCount=0
audit-oxygen-site.php: componentInstances=33, editableProperties=257, codeBlocks=0
check-component-css-routing.php: highRiskHosts=[]
npm test: passed
composer lint:phpstan: OK
composer lint:phpcs: OK
```

Critical implementation detail:

```text
Do not put Google Fonts @import rules in generated Maximus global CSS.
Oxygen may print that asset after normal rules, which makes @import invalid.
Use @font-face declarations for Inter, Noto Serif, and Material Symbols.
```

When auditing icons, measure intrinsic ligature width in an inline clone or inspect screenshots. Oxygen block-level text wrappers can have large bounding boxes even when the actual icon glyph is correct.

Final global CSS shape:

```text
globalStyleAssets=2
Maximus base site-kit global asset
Maximus V2 source utility fallback
```

If global assets grow, first check for stale generated shared-chrome CSS or redundant Material Symbols source style extracts:

```text
.ohc-maximus-site-header-native-
.ohc-maximus-site-footer-native-
/* Extracted from <style> tag */ .material-symbols-outlined
```

Active shared chrome should be semantic. Do not keep header/footer generated global CSS if `maximus-site-header-inner`, `maximus-site-actions`, `maximus-footer-nav`, and related semantic classes can cover the layout.

Functional form status:

```text
The contact form has now been pivoted locally from Breakdance/Oxygen FormBuilder to Fluent Forms because the production workflow needs FunnelKit Automations, submitter/admin notifications, and Turnstile/reCAPTCHA.
Local URL: http://maximus.localhost/kontakt/
Current local form: Fluent Forms "Maximus Kontakt", embedded through OxygenElements\Shortcode on Oxygen node 12.
Current fields: first_name required, last_name optional, email required, phone required, persona required, goal optional, message required.
Removed field: preferred location.
Current structure: Fluent container rows for first/name, email/phone, persona/goal; CSS is only brand polish scoped to .maximus-fluent-form-embed.
Local verification: render OK, desktop two-column containers OK, mobile one-column OK, browser submit OK, wp_fluentform_submissions created.
Do not run the old Breakdance production snippet. Prepare a new Fluent Forms WPCodeBox production migration snippet.
```

Production form status:

```text
Production is already migrated.
URL: https://avemaximus.com/kontakt/
Fluent form id: 3
WPCodeBox snippet: One-time Maximus Fluent contact form migration, snippet id 2, disabled after success
backup: /home/u363549550/domains/avemaximus.com/public_html/wp-content/uploads/codex-maximus-contact-fluent-backup-20260526-091311.json
prepared snippet source: D:\WordPress\Html to Oxygen\oxygen-html-converter-dev\tools\production-snippets\maximus-contact-fluent-form-wpcodebox.php
verified: frontend layout OK, production submit OK, Fluent Forms entry created, FluentSMTP admin + submitter emails logged as sent
```

Breakdance Forms cleanup:

```text
Breakdance Forms for Oxygen is removed locally and on production.
Do not reinstall it for Maximus unless a future Oxygen-native FormBuilder implementation is intentionally chosen.
Breakdance Elements for Oxygen remains active and must not be removed without a separate audit.
Production verification after removal: Breakdance Forms row absent in Plugins search, Breakdance Elements active, /kontakt/ renders Fluent form 3, no breakdance-form markup, no console errors.
```

If adding anti-spam:

```text
Use Fluent Forms Turnstile or reCAPTCHA after global site/secret keys are configured.
Do not add the field before keys exist; it can break public submissions.
FunnelKit Automations Pro is active and should use Fluent Forms submission triggers.
Fluent Forms Pro is not required unless the owner wants no-code Form Styler controls.
```

Critical form styling gotcha:

```text
_oxy_html_converter_page_styles is JSON, not raw CSS.
If raw CSS is appended directly, the converter renderer returns no page-scoped CSS and the form looks unstyled.
Always decode the payload, modify payload.css, then wp_slash(wp_json_encode(payload)).
```

## 8. If Improving Beyond The Current 10/10 Workflow

The highest leverage next gap is not basic Component serialization anymore. That is implemented.
Start with broader Component Properties:

```text
URL, image, icon, and selected-state Component Properties
```

Recommended experiment:

1. In Oxygen Builder, manually create a component with one URL property and one image property.
2. Insert it into a test page.
3. Inspect `_oxygen_data` for the component and page.
4. Document the exact serialized shape in `skill-docs/component-properties-research.md`.
5. Extend `enableMaximusComponentProperties()` only after the shape is verified.

Do not guess this format.
