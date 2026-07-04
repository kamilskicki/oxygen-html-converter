# Script Usage

## check-local-environment.ps1

Run when:
- the site may be down
- Browser cannot attach
- Docker access is uncertain

It reports:
- whether key local paths exist
- whether the browser plugin files exist
- whether the local Oxygen builder dist manifest and app bundle exist
- whether any local `integration\oxygen\main.js` asset exists under the Oxygen source tree or workspace
- whether the local builder source maps still contain the exact `vp-wp` missing-entry error text
- whether the current workspace looks like an integration bootstrap mismatch instead of a bad URL or login
- what `tests/live/sync-docker-plugin.cjs` currently treats as the default WordPress container, plugin path, and owner
- whether `oxyconvo6.localhost` responds
- whether Docker is reachable from this shell
- optional canonical Oxygen builder and browse-mode URLs when you pass `-PostId`, `-OpenUrl`, or `-ReturnUrl`

Use `powershell -ExecutionPolicy Bypass -File .\scripts\check-local-environment.ps1` if the default PowerShell execution policy blocks direct script execution.
Use `-SkipHttp -SkipDocker` when you need a fast asset-only diagnosis without waiting on flaky host or daemon probes.

Examples:
- `powershell -ExecutionPolicy Bypass -File .\scripts\check-local-environment.ps1`
- `powershell -ExecutionPolicy Bypass -File .\scripts\check-local-environment.ps1 -PostId 521`
- `powershell -ExecutionPolicy Bypass -File .\scripts\check-local-environment.ps1 -SkipHttp -SkipDocker`
- `powershell -ExecutionPolicy Bypass -File .\scripts\check-local-environment.ps1 -OpenUrl http://oxyconvo6.localhost/sample-page/ -ReturnUrl http://oxyconvo6.localhost/wp-admin/`

## summarize-oxygen-source.ps1

Run when:
- you need the key source anchors quickly
- you want grep terms without rereading the full source tree
- a future run needs a fast refresher before editing the skill

## smoke-builder-access.mjs

Run when:
- you know the local site should be up and want a single command to build canonical Oxygen URLs
- you want to check whether login, admin, or a specific builder URL responds before using Browser
- you have a post id and want the exact `?oxygen=builder&id={postId}` URL plus browse-mode variants

Notes:
- This script currently prefers `curl.exe -I` on Windows and only falls back to `fetch` when needed.
- Treat its `transport` field as evidence for which probe path succeeded:
  - `curl-head`: preferred result in this environment
  - `fetch`: fallback result after a failed `curl.exe` probe
- If PowerShell scripts are blocked, invoke `check-local-environment.ps1` with `-ExecutionPolicy Bypass`.
- If you need the exact builder URL in the shell-native probe, prefer `check-local-environment.ps1 -PostId <id>` over hand-building the query string.

Examples:
- `node .\scripts\smoke-builder-access.mjs`
- `node .\scripts\smoke-builder-access.mjs --post-id 123`
- `node .\scripts\smoke-builder-access.mjs --post-id 123 --open-url http://oxyconvo6.localhost/sample-page/ --return-url http://oxyconvo6.localhost/wp-admin/post.php?post=123&action=edit`
