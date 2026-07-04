# OxyMade Oxygen 6 Globals + WindPress Decision

Date: 2026-05-18

## Goal

The product goal is not generic HTML rendering. The goal is fast creation of Oxygen Builder 6-native pages that remain editable after import.

That means Core should prefer:

- native Oxygen document trees
- Oxygen selector records for reusable classes
- Oxygen variables for detected design tokens
- scoped, manifest-tracked fallback CSS only where native mapping is not reliable yet

## Sources Reviewed

Local OxyMade archive:

- `D:\WordPress\Html to Oxygen\oxymade-v1.4.0.zip`
- inspected files under `artifacts/oxymade-v1.4.0-inspect/oxymade`
- especially `includes/variables.php`, `includes/json-manager.php`, `includes/admin-settings.php`, `includes/color-palette.php`, `includes/class-builder-autocomplete.php`, `includes/class-paste-css-handler.php`, `data/globals.json`

Local Oxygen 6 source in `oxyconvo6-wordpress-1`:

- `/var/www/html/wp-content/plugins/oxygen/plugin/variables/save.php`
- `/var/www/html/wp-content/plugins/oxygen/plugin/variables/variables.php`
- `/var/www/html/wp-content/plugins/oxygen/plugin/variables/template/macro.twig`
- `/var/www/html/wp-content/plugins/oxygen/plugin/data/save.php`
- `/var/www/html/wp-content/plugins/oxygen/plugin/data/load.php`
- `/var/www/html/wp-content/plugins/oxygen/plugin/data/api/options.php`

Primary public docs:

- [Oxygen Variables](https://oxygenbuilder.com/documentation/design/variables/)
- [Oxygen Classes](https://oxygenbuilder.com/documentation/design/classes/)
- [WindPress Oxygen integration](https://wind.press/docs/guide/integrations/oxygen)
- [WindPress cached CSS](https://wind.press/docs/guide/concepts/cache)
- [WindPress WordPress.org changelog](https://wordpress.org/plugins/windpress/)

## Adopted From OxyMade

OxyMade's useful patterns:

- Use Oxygen/Breakdance APIs first: `Breakdance\Variables\saveVariables()`, `Breakdance\Data\save_global_settings()`, `Breakdance\Data\get_global_settings_array()`.
- Keep selector records global and attach class UUIDs to page elements through `data.properties.meta.classes`.
- Persist selector collections with selectors.
- Regenerate global settings cache after selector/variable/global-settings writes when Oxygen APIs are available.
- Clear stale selector caches around selector writes, including OxyMade's selector transient if present.

Implemented in Core:

- `OxygenVariableRepository` maps detected color, spacing, and font tokens into Oxygen 6 variables.
- `OxygenGlobalSettingsRepository` merges detected color tokens into the Oxygen color palette and can merge explicit `oxygenGlobalSettings` payloads.
- `OxygenPageImporter` now records native variable/global-settings persistence in the import manifest.
- `OxygenSelectorRepository` now invalidates selector caches before and after writes.

## Not Adopted

Patterns intentionally rejected:

- Full replacement of `settings['settings']` from OxyMade globals JSON. That is too destructive for an importer because it can overwrite user typography, containers, code, colors, and presets.
- Remote design-set fetches from OxyMade/Breakmade URLs.
- Runtime `pre_option_oxygen_oxy_selectors_json_string` caching. It helps OxyMade autocomplete, but it increases stale selector risk during importer writes.
- Random IDs for repeat imports. Core keeps deterministic IDs for idempotency.
- Broad repair flows that delete or replace user-created selector data.

## WindPress Decision

Recommendation: WindPress compatibility should become an optional Pro/integration layer. Core should stay focused on native Oxygen.

Reasons:

- Oxygen's design model is class-first; official docs say classes are managed through the Selectors panel and styling is attached to classes.
- Oxygen variables are native design-system primitives for colors, units, font families, image URLs, and numbers.
- WindPress's own Oxygen integration is Pro and uses Plain Classes specifically to avoid cluttering Oxygen's class system.
- WindPress production rendering depends on scanning and cached CSS at `/wp-content/uploads/windpress/cache/tailwind.css`.
- The WordPress.org changelog shows recent Oxygen 6 integration churn: Oxygen 6 Pro integration added on 2026-05-07, editor script loading fixed on 2026-05-10, and Tailwind v4 updated again on 2026-05-16.

Core should keep:

- Tailwind/utility detection
- native mapping of supported utilities into Oxygen properties
- deterministic selector creation for real reusable classes
- page-scoped fallback CSS when strict native parity is not yet possible

Pro should own:

- WindPress mode
- WindPress auto-detection
- WindPress cache reset and cache generation tooling
- Tailwind v3/v4 integration gates
- WindPress-specific UI/docs/promises
- workflows that preserve utility classes in WindPress Plain Classes rather than Oxygen selectors

## Ideal Workflow

1. Create or iterate a design in Stitch, Claude, Figma, or another AI/design tool.
2. Export HTML/CSS.
3. Import through Core in native mode.
4. Core extracts document structure, selectors, tokens, and fallback CSS routes.
5. Core persists:
   - Oxygen page tree
   - Oxygen selector records
   - Oxygen variables
   - Oxygen global color palette entries
   - tracked fallback CSS only where still needed
6. User opens the page in Oxygen Builder 6 and edits native elements/classes/variables.
7. For Tailwind-heavy prototypes, Pro can optionally route utility-heavy styling through WindPress, then promote repeated patterns back into native Oxygen selectors and variables.

## Current Verification Standard

For a completed importer run, the manifest should show:

- selector persistence: saved/total/collections
- variable persistence: created/updated/linked/collections
- Oxygen global settings persistence: sections changed and palette size
- page/global fallback CSS: saved bytes and hashes
- render cache refresh attempted after document writes

The frontend can look correct because fallback CSS exists, but the product standard is stricter: the user should be able to reopen the page in Oxygen and edit the imported page through native controls as much as the source allows.

## Verification Run

Completed locally on 2026-05-18 against `oxyconvo6.localhost`:

- `composer test`: 384 tests, 1109 assertions
- `npm run test:js`: 11/11 suites
- `composer lint:phpstan`: no errors
- `composer lint:phpcs`: no errors
- `npm run test:live:maximus`: passed builder open, modal/paste smoke, save, reopen, native text edit persistence, frontend text persistence, and native style-routing proof
- direct `OxygenPageImporter` Maximus import to `http://oxyconvo6.localhost/ohc-native-e2e-maximus/`: created 4 Oxygen variables, skipped Material Symbols as an icon font, created 2 Oxygen global color palette entries, persisted manifest summaries
- Browser Use frontend smoke: loaded imported page, detected expected H1, body text length 1560, no console errors
- screenshot artifact: `artifacts/browser/ohc-native-e2e-maximus.png`
