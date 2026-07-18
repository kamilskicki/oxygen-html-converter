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

All commands above resolve maintained fixtures from `fixtures/html` inside this
repository. A release gate must not depend on a sibling workspace directory.

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
https://oxyconvo6.mylab
SSH alias: oxyconvo6.mylab
WordPress root: /var/www/wordpress
```

The `*.localhost` Docker target is retained only for isolated legacy debugging.
It is not the canonical artifact or browser E2E target.

Confirm the run covers:

- exact release ZIP installation on the maintained SSH staging target
- fixture import with current `fixture-index.json` expectations
- frontend nonblank render and visual smoke
- Builder open, import/save, reopen, and editability smoke
- selectors/classes/variables/templates/components/site-kit checks where relevant
- failure artifacts under `artifacts/live-gate`, `artifacts/visual-review`, or `artifacts/visual-review/capture-failures`

For the maintained staging target, run the focused P0 fixture baseline and then
the browser round-trip against the same installed ZIP:

```powershell
$env:OXY_HTML_CONVERTER_SSH_HOST = 'oxyconvo6.mylab'
$env:OXY_HTML_CONVERTER_WORDPRESS_ROOT = '/var/www/wordpress'
node tests/live/run-fixture-baseline.cjs --fixture=regressions/css-cascade-component-semantics.html --slug-prefix=codex-e2e-
npm run test:staging:browser -- --base-url=https://oxyconvo6.mylab --page-id=9
```

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
$zip = Resolve-Path artifacts/release/oxygen-html-converter-0.9.0-beta.zip
$sha = (Get-FileHash -Algorithm SHA256 $zip).Hash.ToLowerInvariant()
scp $zip oxyconvo6.mylab:/tmp/oxygen-html-converter-$sha.zip
ssh oxyconvo6.mylab "cd /var/www/wordpress && wp plugin install /tmp/oxygen-html-converter-$sha.zip --force --activate --allow-root"
$env:OXY_HTML_CONVERTER_SSH_HOST = 'oxyconvo6.mylab'
$env:OXY_HTML_CONVERTER_WORDPRESS_ROOT = '/var/www/wordpress'
node tests/live/run-fixture-baseline.cjs --fixture=regressions/css-cascade-component-semantics.html --slug-prefix=codex-e2e-
npm run test:staging:browser -- --base-url=https://oxyconvo6.mylab --page-id=<fixture-page-id> --artifact-sha256=$sha --commit=(git rev-parse HEAD)
```

Always pass the explicit, SHA-named ZIP to staging. An unqualified "latest ZIP"
selection is not sufficient hash proof. Record the local and uploaded SHA256 and
confirm the installed file manifest matches the ZIP manifest.

Before replacing an existing staging installation, retain a recoverable backup
until the artifact and browser gates finish. Confirm the plugin remains active
and loaded after installation.

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
