# Release Checklist (Core)

Use this checklist before merging or tagging the Core remediation line.

Core path:

```powershell
cd "D:\WordPress\Html to Oxygen\oxygen-html-converter-dev\plugins\core"
```

## Scope And Docs Gate

1. Confirm version and release docs are in sync:
   - `oxygen-html-converter.php`
   - `README.md`
   - `CHANGELOG.md`
   - `docs/SUPPORTED_SCOPE.md`
   - `docs/RELEASE_NOTES_0.9.0_BETA.md`
   - `docs/DOD-0.9.0-BETA.md`
   - `..\..\knowledge\KBAI\m8-remediation-summary.md`
2. Confirm every open GAP has a Core, Pro, future, or unsupported disposition in KBAI or fixture metadata.
3. Confirm release docs describe Safe Mode/no-code behavior, unsupported form and dynamic-data boundaries, component/template/site-kit scope, and live smoke requirements.
4. Validate the WordPress.org readme before publication:
   - Confirm `readme.txt` has contributors, tags, requires/tested/stable tags, license, description, installation, FAQ, screenshots, changelog, and upgrade notice.
   - Run the official WordPress.org readme validator or parser against `readme.txt`.
   - Confirm the parsed changelog matches `CHANGELOG.md` for the release being published.

## Stable Local Gate

Run:

```powershell
composer install
vendor\bin\phpunit
vendor\bin\phpstan analyse --configuration=phpstan.neon.dist
vendor\bin\phpcs --runtime-set ignore_warnings_on_exit 1 --standard=phpcs.xml.dist
npm run test:js
npm run test:fixtures:local
npm run check
```

Expected:

- Composer exits 0; existing Legacy/test-bootstrap PSR-4 warnings are allowed only while non-growing and documented.
- Stable PHPUnit, PHPStan, PHPCS, JS tests, fixture audit, and aggregate check pass.
- Legacy tests under `tests/Legacy` remain outside the default gate unless explicitly requested.

## Live Smoke Gate

Run against the maintained local WordPress/Oxygen stack:

```powershell
npm run sync:docker
npm run test:live
npm run test:visual
```

Current maintained target:

```text
http://oxyconvo6.localhost
oxyconvo6-wordpress-1
```

Note: in this workspace, the maintained and verified M8 target is `http://oxyconvo6.localhost` with Docker container `oxyconvo6-wordpress-1`; use that target unless the environment is deliberately reconfigured.

Confirm the run covers:

- plugin sync into the maintained local WordPress/Oxygen container
- fixture import with current `fixture-index.json` expectations
- frontend nonblank render and visual smoke
- Builder open, import/save, reopen, and editability smoke
- selectors/classes/variables/templates/components/site-kit checks where relevant
- failure artifacts under `artifacts/live-gate`, `artifacts/visual-review`, or `artifacts/visual-review/capture-failures`

## Release Verify Gate

Run:

```powershell
php scripts/release_verify.php
php scripts/release_verify.php --with-live
```

Expected:

- deterministic local checks pass
- live checks pass or any intentionally excluded check has written approval and an explicit release risk
- generated evidence records command, date, exit code, and artifact paths

## Artifact Gate

Run before publishing a ZIP:

```powershell
npm run build:zip
npm run install:zip -- --zip=artifacts/release/oxygen-html-converter-0.9.0-beta.zip --base-url=http://oxyconvo6.localhost
npm run test:live:artifact -- --base-url=http://oxyconvo6.localhost --output-dir=artifacts/release-smoke/rel05-live-gate
npm run test:visual
```

Always pass the exact ZIP path to `install:zip`; its unqualified fallback selects
the newest matching artifact by modification time and is not sufficient hash
proof. Record the ZIP SHA256 before installation and confirm the installed file
manifest matches the ZIP manifest.

`install:zip` reports the development-copy `backupPath`. Keep that backup until
the artifact gate finishes. In a failure-safe cleanup step, move the installed
release copy to the run's evidence directory, restore the reported development
copy to the canonical plugin path, normalize ownership/modes, compare the
pre/post path-and-content manifest hashes, and confirm the plugin is active and
loaded. Do this cleanup on both pass and failure paths.

Confirm:

- the ZIP excludes `.distignore` entries
- the ZIP installs and activates through wp-admin on the maintained Oxygen stack
- artifact live smoke passes against the installed ZIP, not the working tree copy
- the original development plugin copy is restored byte-for-byte after the smoke

## Merge Gate

Before merge:

1. Re-run the Stable Local Gate and Live Smoke Gate after the final docs/code changes.
2. Confirm `git status` contains only intentional Core/docs/fixture/KBAI changes for this release line.
3. Confirm no gate failure represents baseline growth. Any failure must be fixed or recorded with an approved exclusion before merge.
4. Confirm `CHANGELOG.md` includes user/operator-visible behavior changes and known operational implications.

## Publish

1. Create the git tag for the release line.
2. Publish the GitHub Release manually with changelog summary and release notes.
3. Monitor issues, Builder regressions, live smoke failures, and unsupported-boundary reports after release.
## Build

Build the distributable ZIP from an allowlisted staging directory:

```powershell
npm run build:zip
```

Expected:

- `scripts/build-release.php` creates `artifacts/release/oxygen-html-converter-<version>.zip`.
- final ZIP contents are verified against `scripts/release-allowlist.json`.
- dev tooling, tests, docs, scripts, `vendor`, `node_modules`, temp files, and package manifests are excluded.
- command output includes the ZIP path, entry count, and SHA256 for the exact artifact to install-smoke.

### Composer vendor decision (WP-07 / REL-03 close-out)

The 2026-07-10 release audit found 12 generated files under `vendor/`: Composer's
autoload entry point plus loader and installed-package metadata. Core has no
production Composer package dependencies (`composer.lock` has an empty
`packages` list), and `oxygen-html-converter.php` registers the production
`OxyHtmlConverter\\` to `src/` autoloader directly. No production path loads
`vendor/autoload.php`; only the development test bootstrap uses it.

Those 12 files were therefore unused runtime output, not required production
libraries. The release builder no longer runs Composer in staging, `vendor` is
not release-allowlisted, and PRD/09 Future Audit Protocol rule 9 remains
unchanged. If a future release adds a genuine production Composer dependency,
that release must make a new protocol decision before adding any `vendor/`
entry to the artifact.
