# Environment

## Local targets

- Repo root: `D:\WordPress\Html to Oxygen\oxygen-html-converter-dev\plugins\core`
- Workspace root: `D:\WordPress\Html to Oxygen\oxygen-html-converter-dev`
- Oxygen source: `D:\WordPress\Html to Oxygen\oxygen`
- Skill root: `D:\WordPress\Html to Oxygen\oxygen-html-converter-dev\plugins\core\skill\oxygen-builder-browser`

## Local WordPress site

- Hostname to target in Browser: `http://oxyconvo6.localhost/`
- Admin login: `http://oxyconvo6.localhost/wp-login.php`
- Admin area: `http://oxyconvo6.localhost/wp-admin/`
- Local credential for this dev site only: `admin` / `admin`

## Current environment notes

- `node_repl` is available.
- The Browser plugin files are present locally under:
  `C:\Users\Skicu\.codex\plugins\cache\openai-bundled\browser-use\0.1.0-alpha1`
- The in-app browser backend is available in this environment and can attach to the currently open browser tab.
- M8-03 live verification used Docker container `oxyconvo6-wordpress-1` and WordPress `home_url()` resolved to `http://oxyconvo6.localhost`.
- `npm run sync:docker` syncs the Core plugin into `/var/www/html/wp-content/plugins/oxygen-html-converter` in that container.
- `npm run test:live` has verified local admin login, fixture import, Builder open/save/reopen, nonblank canvas/editability signals, selector persistence, and site-kit page/header/footer/template smoke.
- `npm run test:visual` has verified maintained fixture captures plus design-1/design-3 safe-mode smoke against the same local site.
- Treat a successful HEAD or fetch probe as transport reachability only. It does not prove the builder JS booted correctly.
- If Docker is stopped or a different container is active, re-run `docker ps` and `npm run sync:docker` before browser work.

## Useful external knowledge files

- `D:\WordPress\Html to Oxygen\oxygen-html-converter-dev\knowledge\KBAI\02-oxygen-reference\oxygen-6-breakdance-core.md`
- `D:\WordPress\Html to Oxygen\oxygen-html-converter-dev\knowledge\KBAI\04-testing\localhost-docker-browser-validation.md`

## First checks before browser work

1. Run `scripts/check-local-environment.ps1`.
2. Run `node .\scripts\smoke-builder-access.mjs --post-id <id>` when you know a candidate page id.
3. If Browser cannot attach, stop UI-driving work for that run.
4. Read the `builder-dist`, `builder-manifest`, `builder-bundle`, and `integration-asset` entries from `check-local-environment.ps1` before retrying a broken builder session.
5. Also read `bootstrap-error-anchor` and `integration-bootstrap` when present. Those checks distinguish a missing builder route from a source-backed integration bootstrap failure.
6. Read `docker-sync-defaults` to see which live plugin copy `npm run sync:docker` will target.
7. If Browser attaches but Builder launch regresses, inspect the latest `artifacts/live-gate` or `artifacts/visual-review` failure artifact before continuing selector work.

If PowerShell blocks direct script execution on this host, use:
- `powershell -ExecutionPolicy Bypass -File .\scripts\check-local-environment.ps1`
- `powershell -ExecutionPolicy Bypass -File .\scripts\check-local-environment.ps1 -SkipHttp -SkipDocker`
