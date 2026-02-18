# Release Checklist (Core)

## Pre-release

1. Confirm version in `oxygen-html-converter.php`.
2. Update `CHANGELOG.md` with release notes.
3. Verify `README.md` accuracy for current behavior.
4. Run quick syntax checks (`php -l`) on changed PHP files.
5. Smoke-test in local WordPress + Oxygen environment.

## Packaging

1. Build release ZIP excluding `.distignore` entries.
2. Verify ZIP installs and activates in a clean site.
3. Confirm core UI works:
   - Admin converter page
   - Builder `Ctrl+V` conversion
   - Builder `Ctrl+Shift+H` modal

## Publish

1. Tag release in git (for example `v0.8.0-beta.1`).
2. Publish GitHub Release with changelog summary.
3. Monitor Issues for first-week regressions.
