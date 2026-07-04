# Validation

## Cheapest successful checks

After any edit, prefer one cheap check that proves the next fact you need:

- save control returned to idle
- unsaved-change warning disappeared
- expected panel stayed open and the changed value is visible
- frontend page or browse-mode iframe reflects the change

## Source-backed save verification

- UI save should map to the `breakdance_save` handler in `D:\WordPress\Html to Oxygen\oxygen\plugin\data\save.php`.
- A successful save should be compatible with `tree_json_string` persistence plus `wp_update_post()` and cache regeneration for the same `id`.
- If UI verification is impossible, at least verify the intended save path and id are plausible from source and current URL state.

## Frontend render checks

When the site is reachable:

1. Open the frontend URL directly.
2. Verify the expected text, class-driven styling, or structural element exists.
3. If visual parity matters, take one screenshot after the page settles.

## Validation scripts and commands

- `powershell -File .\\scripts\\check-local-environment.ps1`
- `powershell -ExecutionPolicy Bypass -File .\\scripts\\check-local-environment.ps1`
- `powershell -ExecutionPolicy Bypass -File .\\scripts\\check-local-environment.ps1 -PostId <id>`
- `node .\\scripts\\smoke-builder-access.mjs --post-id <id>`
- `powershell -File .\\scripts\\summarize-oxygen-source.ps1`
- `python C:\\Users\\Skicu\\.codex\\skills\\.system\\skill-creator\\scripts\\quick_validate.py <skill-dir>`

## Builder URL validation

Use `smoke-builder-access.mjs` when you need a cheap, non-browser confirmation that:

- the base frontend, login page, and wp-admin respond at all
- the canonical document-builder URL was constructed with `?oxygen=builder&id={postId}`
- browse-mode URLs include encoded `browseModeOpenUrl` and optional `returnUrl`

Use `check-local-environment.ps1 -PostId <id>` when you want the same canonical builder target checked through the shell-native probe path instead of the Node helper.

For builder bootstrap failures, treat these `check-local-environment.ps1` entries as the cheapest local evidence before reopening Browser:

- `builder-dist`: confirms the local builder dist manifest exists
- `builder-manifest`: confirms the manifest still maps `app.js` and `app.html`
- `builder-bundle`: confirms a hashed `app*.js` bundle exists under `builder\dist\js`
- `integration-asset`: confirms whether any local `integration\oxygen\main.js` file exists at all
- `bootstrap-error-anchor`: confirms whether the built source maps still carry the exact `vp-wp` missing-entry error text
- `integration-bootstrap`: combines the two checks into a direct "asset missing plus builder expects it" signal
- `docker-sync-defaults`: shows which container and plugin path the repo sync script would target when Docker becomes available

If probe results disagree with `check-local-environment.ps1`, trust the PowerShell probe for raw reachability and the Node script for URL construction.

In this sandbox, prefer the Node script result when it reports `transport: "http-head"` or `transport: "https-head"`. Those low-level probes have matched direct `curl.exe -I` checks more reliably than Node `fetch`.

If the hostname or Docker daemon is flaky, rerun the PowerShell probe as:
- `powershell -ExecutionPolicy Bypass -File .\scripts\check-local-environment.ps1 -SkipHttp -SkipDocker`

Use that fast mode to answer a narrower question first:
- does the local source tree still have the builder dist bundle
- does it still lack `integration\oxygen\main.js`
- which container and plugin path will `npm run sync:docker` target later

## Browser-verified launch validation

Before attempting any edit, establish which of these states you are actually in:

1. Login page rendered:
   - `wp-login.php` shows the `Log In` heading and username/password fields.
2. Authenticated wp-admin rendered:
   - the dashboard or Pages list shows `Howdy, admin`.
3. Launcher surface rendered:
   - the Pages list row action `Edit in Oxygen` is visible for the target page.
4. Builder booted:
   - not just `200 OK`, but a usable shell instead of the 500 overlay.

If state 3 succeeds but state 4 fails with `[vp-wp] Entry assets/integration/oxygen/main.js not found.`, record that as a validated bootstrap failure. Do not continue with element-editing validation for that run.

## Launch-path validation from wp-admin

If you launch from a WordPress editor screen, the cheapest reliable check is:

1. Confirm the launcher control is the official Oxygen/Breakdance entrypoint.
2. Confirm the redirect path came from `builderLoaderUrl`, not a hand-built navigation from the edit screen.
3. If WordPress raised an unload prompt, treat the launch as suspect and retry through the launcher flow before diagnosing builder selectors.

## Current limitation

This skill has not yet completed a browser-verified end-to-end edit in the current environment because the builder currently fails after launch with a 500 overlay tied to a missing integration asset.
