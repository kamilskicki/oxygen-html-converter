# Release Checklist (Core)

## Fast Gate

1. Confirm version and release docs are in sync:
   - `oxygen-html-converter.php`
   - `README.md`
   - `docs/RELEASE_NOTES_0.9.0_BETA.md`
   - `docs/DOD-0.9.0-BETA.md`
2. Run `npm run check`.
3. Confirm `package-lock.json` and `composer install --dry-run` remain consistent with repo state.

## Local Live Gate

1. Run `npm run test:live`.
2. Confirm the run covers:
   - plugin sync into the maintained local WordPress/Oxygen container
   - fixture parity baseline artifacts in `artifacts/`
   - admin converter preview/convert smoke
   - builder open -> import/save -> reopen smoke with no `Validation Error` / `IO-TS decoding failed`

## ZIP Artifact Gate

1. Run `npm run build:zip`.
2. Install the fresh ZIP through wp-admin on the maintained Oxygen stack with `npm run install:zip`.
3. Run `npm run test:live:artifact`.
4. Run `npm run test:visual`.
5. Confirm the artifact gate covers:
   - real ZIP upload/update through wp-admin
   - admin preview/convert smoke against the installed artifact
   - builder `Ctrl+Shift+H` import modal smoke
   - builder `Ctrl+V` paste smoke
   - maintained fixture screenshot pairs and targeted frontend interaction smoke

## Packaging

1. Run `npm run build:zip`.
2. Verify the ZIP excludes `.distignore` entries.
3. Verify the ZIP installs and activates through wp-admin on the maintained Oxygen stack.

## Release Verify

1. Run `npm run release:verify` for the deterministic local gate.
2. Run `npm run release:verify:live` on the maintained Docker/Oxygen stack before publishing.
3. Confirm the generated ZIP layout matches the expected plugin root structure.

## Publish

1. Create the git tag for the release line.
2. Publish the GitHub Release manually with changelog summary.
3. Monitor issues and builder regressions after release.
