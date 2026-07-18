# Release Audit: Oxygen HTML Converter Core

Date: 2026-04-25
Branch: `master`
Target release line: `0.9.0-beta`
Status: Superseded historical audit

This April audit is retained as historical evidence. Its publication verdict, command counts, and remaining-work list no longer describe the current release candidate. The current authority is [PRD/09: Final Independent Publication Audit](../../../PRD/09-publication-readiness-scorecard.md#final-independent-publication-audit), including its later close-out evidence, adversarial findings, score, and publication gate. Documentation cleanup in this release does not by itself establish a `GO` decision.

## Executive Summary

Historical verdict (superseded): the April 25 candidate was not ready to tag or publish.

The core conversion code has a healthy baseline: the default fast gate passes, AJAX endpoints have nonce/capability checks, Safe Mode is covered by tests, and dependency audits reported no known vulnerabilities. However, the audit found two release-blocking issues:

1. The generated release ZIP includes local screenshots, temporary live-audit files, and `.tmp-*` data. The current ZIP is about `113.22 MB` and contains `291` entries, including `.screens/`, `.tmp-live-audit/`, `.tmp-playwright/`, and top-level `.tmp-*.json` files.
2. `startingNodeId` is not honored for generated child nodes. With `startingNodeId=50`, the AJAX convert response produced IDs `[50, 1, 2, 3]`, which can collide with an existing Oxygen document tree during builder import.

At that checkpoint, these were treated as release blockers. The remaining findings were classified as hardening and maintainability work to follow the blockers unless they affected the final beta acceptance gate.

## Remediation Update (Historical)

Date: 2026-04-26
Status: fixes implemented in the working tree; live Docker/Oxygen gate still needs to be run on the maintained local stack before publishing.

Implemented:

- Release ZIP packaging now uses tracked distribution files instead of recursively walking local scratch artifacts.
- `.distignore`, release hygiene, and release ZIP verification now reject `.screens`, `.tmp-*`, `.tmp-*/`, `.gitattributes`, and `release-audit.md`.
- `startingNodeId` is honored across the final AJAX convert payload; IDs are reindexed into a single monotonic sequence and `_nextNodeId` follows the max ID.
- Tree builder factory hooks now provide a consistent context for convert, preview, and batch flows.
- Builder paste dispatch now returns the browser cancellation result instead of always reporting success.
- Missing required Builder contract paths for known elements now fail validation instead of being warnings only.
- PHPStan now analyzes all `src/`, and the previously reported PHPStan issues were fixed by removing dead duplicated code and tightening impossible conditions.
- Full PHPCS warnings for mixed line endings and non-strict `in_array()` usage were addressed; `.gitattributes` pins text files to LF.
- `composer.lock` is no longer ignored, so it can be tracked for reproducible dependency resolution.
- `npm run release:verify` is now a deterministic local gate; `npm run release:verify:live` runs the Docker/Oxygen live gate explicitly.

Verified after fixes:

- `npm run check`: passed.
- `vendor\bin\phpunit`: `330` tests, `744` assertions passed.
- `node tests/js/run-tests.cjs`: `6/6` suites passed.
- `vendor\bin\phpstan analyse src --level=5 --autoload-file=tests/bootstrap.php --no-progress`: passed.
- `vendor\bin\phpcs --standard=phpcs.xml.dist src oxygen-html-converter.php`: passed.
- `php scripts/build_zip.php`: produced `artifacts/release/oxygen-html-converter-0.9.0-beta.zip` with `70` entries.
- Manual ZIP inspection: `113531` bytes, `0` forbidden artifact entries.
- `npm run release:verify`: passed with live gate skipped by design.

Remaining at the April checkpoint:

- Run `npm run release:verify:live` on the maintained Docker/Oxygen stack.
- Commit the now-unignored `composer.lock` if the release branch should lock dev tool versions.

## Verification Performed

Passed:

- `npm run check`
  - `scripts/check_release_hygiene.php`: passed under current narrow checks.
  - JS tests: `6/6` suites passed.
  - PHPUnit: `327` tests, `717` assertions passed.
  - Configured PHPCS/PHPStan gates passed.
- `composer install --dry-run`: passed in this local workspace.
- `composer audit`: no advisories found.
- `npm ci --ignore-scripts --dry-run`: passed.
- `npm audit --omit=dev`: no vulnerabilities found.
- `npm audit`: no vulnerabilities found.
- `php scripts/build_zip.php`: command succeeded and created a ZIP.

Failed or incomplete:

- `npm run release:verify`: timed out after 10 minutes while running the live gate path. The timeout left child `node`/`php` processes running; they were identified as descendants of the timed-out release verify run and stopped.
- Full PHPStan over all `src/` failed with `18` findings. The configured PHPStan gate only covers selected files.
- Full PHPCS over all `src/` found warnings for mixed line endings and non-strict `in_array()` usage. The configured PHPCS gate only covers selected entrypoint/hardening files and ignores warnings on exit.

## P0 Release Blockers

### P0.1 Release ZIP Includes Local Artifacts

Evidence:

- `scripts/build_zip.php:32-58` recursively walks the whole working tree and relies on `.distignore`.
- `.gitignore` excludes `.screens/`, `.tmp-*/`, and `.tmp-*.json`, but `.distignore` does not.
- Manual ZIP inspection found:
  - `107` entries under `oxygen-html-converter/.screens/`
  - `110` entries under `.tmp-live-audit/` or `.tmp-playwright/`
  - top-level `.tmp-builder-problem-pages.json`, `.tmp-design1-jsfix.json`, `.tmp-page-86-oxygen-data.json`, `.tmp-page-88-oxygen-data.json`
- Current generated ZIP size: `113.22 MB`.
- Local artifact directories total about `117.73 MB`.

Impact:

- Ships private/local QA artifacts and screenshots.
- Bloats the plugin ZIP far beyond a normal WordPress plugin package.
- Makes release verification misleading because the ZIP layout check only rejects a narrow list of forbidden entries.

Fix plan:

1. Prefer changing `scripts/build_zip.php` to package from `git ls-files` plus explicitly allowed generated runtime assets, not from a recursive working-tree walk.
2. Also update `.distignore` to include `.screens`, `.tmp-*`, `.tmp-*/`, `.tmp-live-audit`, `.tmp-playwright`, and any future scratch/output directory pattern.
3. Update `scripts/check_release_hygiene.php` required entries to mirror the artifact exclusions in `.gitignore`.
4. Expand `scripts/release_verify.php` forbidden ZIP prefixes to reject any hidden temp/screenshot/artifact prefix, not only `tests`, `docs`, `scripts`, `node_modules`, `vendor`, `.phpunit.cache`, `.phpstan`, and `.worktrees`.
5. Add a packaging regression test that builds a ZIP in a workspace containing fake `.screens/` and `.tmp-*.json` files, then asserts they are absent.
6. Rebuild the ZIP and verify the entry list contains only runtime plugin files plus intentional public docs such as `README.md`, `LICENSE`, and `CHANGELOG.md` if those are meant to ship.

Acceptance:

- `php scripts/build_zip.php` produces a small runtime ZIP.
- Manual ZIP check reports `0` `.screens`, `.tmp-*`, `artifacts`, `tests`, `docs`, `scripts`, `vendor`, and `node_modules` entries.
- `npm run release:verify` or the packaging-specific equivalent fails if a hidden temp artifact would be included.

### P0.2 `startingNodeId` Can Create Duplicate Oxygen Node IDs

Evidence:

- `src/Services/TreeBuilderFactory.php:45` applies `setStartingNodeId()` before conversion.
- `src/TreeBuilder.php:156-160` resets `$nodeIdCounter` to `1` at the start of every `convert()`.
- `src/Services/ConvertPayloadBuilder.php:83-90` wraps the generated root with the requested `startingNodeId`, but children remain generated from `1`.
- Reproduction through AJAX with `startingNodeId=50` returned IDs `[50, 1, 2, 3]` and `_nextNodeId=51`.
- Existing tests only cover negative normalization (`startingNodeId=-5`), not positive offsets.

Impact:

- Importing into an existing builder document can collide with existing node IDs.
- `_nextNodeId` can look valid while child IDs are still unsafe.
- This directly threatens the release DOD for builder-safe import/save/reopen integrity.

Fix plan:

1. Add a failing unit test for positive `startingNodeId` through `Ajax::handleConvert()`.
2. Preserve the configured starting ID across `TreeBuilder::convert()` resets. A simple approach is to add a separate `$startingNodeId` property, set it in `setStartingNodeId()`, and reset `$nodeIdCounter` to `$startingNodeId` inside `convert()`.
3. Ensure wrapper, CSS/head asset elements, and converted children all use a single monotonic ID sequence.
4. Add a helper test that walks the returned `element` and `documentTree` and asserts:
   - every ID is unique,
   - every generated ID is `>= startingNodeId`,
   - `_nextNodeId` is `max(id) + 1`.
5. Add a batch/preview test only if those flows expose or rely on node offsets.

Acceptance:

- `startingNodeId=50` yields IDs like `[50, 51, 52, 53]` or another strictly unique, monotonic sequence with no IDs below `50`.
- `composer test` passes.
- The builder import smoke can import into a non-empty document without validation errors.

## P1 High Priority

### P1.1 Release Verify Is Too Easy to Hang and Too Hard to Diagnose

Evidence:

- `scripts/release_verify.php:10-16` runs `npm run test:live` before ZIP build verification.
- `scripts/release_common.php:103-128` captures subprocess output through pipes but does not stream progress, enforce per-command timeouts, or clean up descendants on failure.
- The attempted `npm run release:verify` timed out after 10 minutes and left child processes active.

Impact:

- A release gate can hang silently in local automation.
- Failures do not show progress until the child process exits.
- Timeouts can leave Docker/Node/PHP work running after the controlling command is gone.

Fix plan:

1. Split release verification into deterministic subcommands:
   - `release:verify:static`
   - `release:verify:zip`
   - `release:verify:live`
   - `release:verify:artifact`
2. Add per-command timeouts and live stdout/stderr forwarding to `release_run_command()`.
3. On timeout, terminate the process tree and print the command that was running.
4. Keep local live gates explicit because they require Docker, WordPress, Oxygen, fixtures, and Playwright.

Acceptance:

- If Docker/Oxygen is unavailable, live verification fails fast with a clear error.
- Static and ZIP verification can run in CI without the local live stack.

### P1.2 Builder Paste Smoke Does Not Prove Content Was Inserted

Evidence:

- `assets/js/lib/builder-paste.js:49-65` dispatches a synthetic paste event and returns `true` immediately after `doc.dispatchEvent(pasteEvent)`.
- `tests/live/run-live-gate.cjs:315-327` checks for a toast and absence of builder validation errors, but does not verify the imported section exists in the builder document or persists after save/reopen.

Impact:

- The UI can report "converted and pasted" even if Oxygen does not consume the paste payload.
- A central DOD requirement ("paste/import flow works end to end") can pass without proving document mutation.

Fix plan:

1. Make `dispatchConvertedPaste()` use the return value of `dispatchEvent()` or an explicit builder/store mutation signal where possible.
2. In live smoke, assert that known marker content such as `live-gate-paste` appears in the builder state, frontend, or saved document tree.
3. After save/reopen, assert the marker still exists and no validation error appears.
4. Add a JS unit test where `dispatchEvent()` returns `false` or no mutation signal appears, and verify fallback-to-clipboard behavior.

Acceptance:

- Paste smoke fails if the converted payload is not actually added to the document.
- Modal import and paste smoke both verify persistence after save/reopen.

### P1.3 Extension Hook Coverage Is Inconsistent Across Convert, Preview, and Batch

Evidence:

- `src/Services/TreeBuilderFactory.php:17-24` applies `oxy_html_converter_tree_builder` only in `createForConvert()`.
- `src/Services/TreeBuilderFactory.php:32-35` creates a plain `TreeBuilder` for preview and batch flows.
- README/Open Core docs list `oxy_html_converter_tree_builder` as a Core extension hook without documenting that it is convert-only.

Impact:

- Pro/add-ons can customize conversion output but not preview or batch behavior consistently.
- Preview may disagree with convert for extensions.
- Batch imports cannot reuse the same tree builder injection path.

Fix plan:

1. Decide whether the hook is intentionally convert-only.
2. If not intentional, apply the hook through a unified factory method for convert, preview, and batch with a context argument such as `convert`, `preview`, or `batch`.
3. If intentional, rename/document the hook as convert-only and add separate preview/batch hooks.
4. Add tests proving hook behavior for all advertised flows.

Acceptance:

- Docs and behavior match.
- Pro can rely on stable, versioned extension points.

### P1.4 Static Analysis and Coding Standards Gates Are Too Narrow

Evidence:

- `phpstan.neon.dist` analyzes only:
  - `src/AdminPage.php`
  - `src/Ajax.php`
  - `src/Plugin.php`
  - `src/Services/ConversionAuditBuilder.php`
  - `src/Services/EnvironmentService.php`
  - `src/Services/RequestOptions.php`
- `phpcs.xml.dist` checks only the same hardened entrypoint subset plus the bootstrap file.
- Full PHPStan over all `src/` found `18` issues, including unused methods in `TreeBuilder`, unreachable branches, and always-true/always-false checks.
- Full PHPCS over all `src/` found mixed line endings and non-strict `in_array()` warnings.

Impact:

- The default green gate does not represent the whole plugin.
- Refactor leftovers and type drift can accumulate in conversion services.

Fix plan:

1. Expand PHPStan paths to all `src/` at the current level.
2. Fix the current full-src PHPStan findings.
3. Expand PHPCS in stages:
   - first line endings and strict `in_array()`,
   - then security/escaping for all PHP files,
   - then broader WordPress standards where practical.
4. Remove `--runtime-set ignore_warnings_on_exit 1` once warnings are intentionally managed.
5. Add CI jobs for the expanded gates.

Acceptance:

- `vendor/bin/phpstan analyse src --level=5 --autoload-file=tests/bootstrap.php --no-progress` passes or is represented by an equivalent config.
- Full-src PHPCS has no warnings from mixed line endings or non-strict `in_array()`.

### P1.5 Composer Lock Is Local but Not Tracked

Evidence:

- `composer.lock` exists in the workspace and `composer install --dry-run` uses it locally.
- `git ls-files composer.lock` returns nothing.
- `.gitignore` excludes `composer.lock`.

Impact:

- Local verification and CI/fresh checkouts can resolve different dev dependency versions.
- PHPStan/PHPCS/PHPUnit behavior can drift without a code change.

Fix plan:

1. Decide repository policy. For a plugin with CI gates, track `composer.lock` for reproducible development tooling.
2. Remove `composer.lock` from `.gitignore`.
3. Commit the lock file.
4. Keep excluding `composer.lock` from the release ZIP unless runtime dependencies require it.

Acceptance:

- Fresh checkout uses the same Composer dependency graph as local release verification.

## P2 Medium Priority

### P2.1 Builder Contract Warnings Do Not Block Invalid Builder Contracts

Evidence:

- `src/Validation/OutputValidator.php:239-247` records missing required contract paths as warnings.
- Convert/batch validation only fails on errors, not warnings.
- Tests explicitly assert contract warnings for missing Essential Button and HTML5 Video paths.

Impact:

- A builder-critical missing property can pass conversion validation.
- The release DOD is stricter than the validator for known builder contracts.

Fix plan:

1. Split validator messages into fatal contract errors and advisory warnings.
2. Treat missing required paths for known Core element types as errors.
3. Keep unknown/extension element types warning-only unless a registered contract exists.
4. Add tests where missing `content.content.url`, `content.image.url`, or `content.content.video_file_url` causes a `422` response in convert/batch flows.

### P2.2 `TreeBuilder` Still Contains Dead Code After Refactors

Evidence:

Full PHPStan reports unused methods in `src/TreeBuilder.php`, including:

- `applyOxygenCssCompatibilityFixes()`
- `appendTailwindFallbackCss()`
- `selectorMatchesDomPath()`
- `getTagNameFromElement()`
- `storeAttributes()`
- `sanitizeHtmlCodeFragment()`

Impact:

- The largest class in the plugin is harder to review and modify safely.
- Duplicate sanitizer/CSS helper logic increases the chance of fixing the wrong implementation.

Fix plan:

1. Remove dead methods that moved to services such as `DocumentCssExtractor`, `SelectorMatcher`, and `HtmlCodeSanitizer`.
2. Add tests around the service implementations that remain.
3. Continue splitting `TreeBuilder` into focused collaborators only where existing tests make the move safe.

### P2.3 Builder Modal Uses `innerHTML` with Localized Strings

Evidence:

- `assets/js/lib/builder-modal.js:96-122` builds the modal through `container.innerHTML`.
- Localized/config strings such as `strings.modalTitle`, `strings.cancelButton`, `strings.safeModeLabel`, and `strings.importButton` are interpolated directly into the HTML template.

Impact:

- The current data source is privileged/localized plugin data, so this is not the same risk as rendering user HTML.
- Still, it is unnecessary XSS surface and makes the modal harder to harden if strings become filterable or translation-supplied.

Fix plan:

1. Build the modal DOM with `createElement()` and assign dynamic text via `textContent` / attributes via `setAttribute()`.
2. Add a JS test with strings containing HTML and quotes to prove they render as text, not markup.

### P2.4 Batch Exception Logging Is Weaker Than Convert/Preview

Evidence:

- `src/Ajax.php` logs convert and preview exceptions when `WP_DEBUG_LOG` is enabled.
- Batch conversion catches exceptions but only returns a generic response and fires an action.

Impact:

- Production debugging of batch failures is weaker than single convert/preview.

Fix plan:

1. Mirror the convert/preview `WP_DEBUG_LOG` behavior in `handleBatchConvert()`.
2. Add a unit test confirming sensitive details remain redacted by default.

### P2.5 Legacy Tests Are Explicitly Outside the Default Gate

Evidence:

- `tests/Legacy/README.md` says historical/regression tests currently do not pass against active Core.
- `composer test` only runs `tests/Unit`.

Impact:

- This is acceptable if intentional, but regressions covered only by legacy tests are not release blockers today.

Fix plan:

1. Review legacy tests and either delete obsolete cases or promote still-relevant regressions into `tests/Unit`.
2. Keep `composer test:legacy` for archaeological checks only after the useful coverage is migrated.

## Strengths Observed

- AJAX endpoints consistently enforce nonce and capability checks.
- Error details are redacted by default and can be exposed through a filter for debugging.
- Safe Mode strips scripts, event handlers, and external head assets, with focused tests.
- Output now includes `documentTree` and `documentJson`, aligning with builder-safe serialization goals.
- Unit and JS test coverage is broad for conversion helpers, security behaviors, and UI utilities.
- Dependency audits found no current npm or Composer vulnerability advisories.
- Docs clearly describe the Core/Pro split and supported-scope boundary.

## Recommended Repair Sequence

### Phase 0: Release Blockers

1. Fix packaging so release ZIPs are built from tracked/allowlisted runtime files or a complete `.distignore`.
2. Expand ZIP verification to reject all hidden temp/screenshot/artifact paths.
3. Fix `startingNodeId` so all generated nodes use a monotonic, collision-free sequence.
4. Add regression tests for packaging and positive `startingNodeId`.
5. Rebuild the ZIP and manually inspect the entry list once.

### Phase 1: Release Gate Reliability

1. Split `release:verify` into static, ZIP, live, and artifact subcommands.
2. Add subprocess timeout, streaming output, and process-tree cleanup.
3. Make the live gate fail fast when Docker/Oxygen/fixture prerequisites are unavailable.
4. Make builder paste/modal smoke prove insertion and persistence, not only toast/no-error.

### Phase 2: Quality Gate Expansion

1. Track `composer.lock`.
2. Expand PHPStan to all `src/` and fix the 18 current findings.
3. Normalize line endings and fix strict `in_array()` warnings.
4. Run the expanded static gates in CI.
5. Add release hygiene and ZIP verification to CI where local live dependencies are not required.

### Phase 3: API and Contract Hardening

1. Normalize `oxy_html_converter_tree_builder` behavior across convert/preview/batch or document the narrower contract.
2. Make required builder contracts fatal for known Core element types.
3. Add tests for hook parity and contract-failure AJAX responses.

### Phase 4: Maintainability Cleanup

1. Remove dead `TreeBuilder` helper methods left after service extraction.
2. Move builder modal markup away from `innerHTML`.
3. Align stale PHP 7.4 polyfill comments with the current PHP `8.2+` requirement or remove unnecessary polyfills later.
4. Review legacy tests and migrate still-useful regressions into active unit tests.

## Release Decision Rule (Historical)

Release `0.9.0-beta` only after:

- the ZIP contains no local artifacts or temporary files,
- positive `startingNodeId` imports are collision-free,
- static/ZIP release verification is deterministic,
- the live or artifact gate proves builder import/paste content persists after save/reopen,
- docs still honestly state the supported beta scope.
