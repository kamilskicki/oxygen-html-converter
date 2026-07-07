# Troubleshooting

## Browser runtime unavailable

Symptom:
- `setupAtlasRuntime({ backend: "iab" })` fails with no discovered IAB backends.

Meaning:
- This is an in-app Browser attachment problem, not an Oxygen selector problem.

Action:
1. Report that Browser is unavailable in the current run.
2. Do not fall back to unrelated browser controllers.
3. Use source and script work for the run instead.

## Local hostname unreachable

Symptom:
- `http://oxyconvo6.localhost/` or related admin URLs time out from shell probes.
- `smoke-builder-access.mjs` times out even though shell probes succeed.

Possible causes:
- local stack not running
- hostname not mapped in the current environment
- Docker or reverse proxy not available from the current session

Action:
1. Run `scripts/check-local-environment.ps1`.
2. Run `node .\scripts\smoke-builder-access.mjs` to separate hostname or HTTP failure from Browser-only failure.
3. If Docker access is denied, treat container state as unknown.
4. Avoid diagnosing WordPress login or Oxygen UI until the base host responds.
5. If direct PowerShell execution is blocked, rerun step 1 as `powershell -ExecutionPolicy Bypass -File .\scripts\check-local-environment.ps1`.

Interpretation:
- If `check-local-environment.ps1` shows `HTTP/1.1 200 OK` or `302 Found` and `smoke-builder-access.mjs` reports `http-head` or `https-head`, treat the host as reachable.
- If the Node script falls back to `fetch-get` and disagrees with the PowerShell probe, trust the PowerShell result for reachability and keep using the Node script mainly for canonical URL derivation.
- If both scripts time out for frontend, login, admin, and builder URLs in the same run, pause Oxygen UI diagnosis and treat the local stack or hostname as degraded before chasing builder-specific errors.

## Historical Builder bootstrap overlay recurs

Current baseline: after M8-03, `npm run test:live` passes the Builder import, save/reopen, editability, and Site Kit smoke on `oxyconvo6.localhost`. Use `validation.md` for the current gate. This section is only for runs where the exact historical overlay below reappears.

Symptom:
- `smoke-builder-access.mjs --post-id <id>` reports the builder URL as reachable with `200 OK`
- Browser can open `/?oxygen=builder&id={postId}`
- the page title becomes `Oxygen`
- the visible UI immediately shows `Your server returned a 500 error.`
- the page body includes `[vp-wp] Entry assets/integration/oxygen/main.js not found.`

Meaning:
- This is not a bad builder URL and not a missing login session.
- The failure happens after the initial HTML response, during builder asset loading or plugin integration bootstrap.

Action:
1. Capture the exact builder URL and post id.
2. Capture the visible overlay text from Browser.
3. Treat selector or iframe mapping work as blocked for that run.
4. Check for local plugin sync or asset-generation drift before retrying Browser work.
5. Run `powershell -ExecutionPolicy Bypass -File .\scripts\check-local-environment.ps1` and inspect:
   - `builder-dist` and `builder-bundle` for the local builder app bundle
   - `builder-manifest` for `app.js` and `app.html` entries
   - `integration-asset` for any local `integration\oxygen\main.js` match
   - `bootstrap-error-anchor` for the exact `vp-wp` missing-entry string inside local builder source maps
   - `integration-bootstrap` for the combined diagnosis when the asset is absent and the local bundle still contains the missing-entry anchor
   - `docker-sync-defaults` for the exact container and plugin path that `npm run sync:docker` would use once Docker is reachable
6. Inspect built builder source maps before guessing at runtime causes:
   - `D:\WordPress\Html to Oxygen\oxygen\builder\dist\js\app.dec5a514.js.map`
   - `D:\WordPress\Html to Oxygen\oxygen\builder\dist\js\chunk-common.384771e2.js.map`
7. Search the repo and Oxygen source for `assets/integration/oxygen/main.js`, `vp-wp`, and `Entry .* not found`.
8. If host or daemon probes are wasting time, rerun the script with `-SkipHttp -SkipDocker` to get only the local asset and sync-target evidence.

Verified example:
- `http://oxyconvo6.localhost/?oxygen=builder&id=521`
- page title `Oxygen`
- overlay text `[vp-wp] Entry assets/integration/oxygen/main.js not found.`

Interpretation:
- This failure appears after the builder HTML route resolves, so it is more consistent with missing built integration assets or a mismatched asset manifest than with a bad login session.
- In the historical failing workspace snapshot, `builder/dist/manifest.json` and `app*.js` existed locally, while no `integration\oxygen\main.js` file was present under the Oxygen source tree or workspace. Treat that as evidence of an asset-sync or integration-entry mismatch only if the same overlay recurs.
- `tests/live/sync-docker-plugin.cjs` currently targets container `oxyconvo6-wordpress-1` and plugin path `/var/www/html/wp-content/plugins/oxygen-html-converter`. When Docker returns, inspect that exact live copy before assuming the running site matches the local source tree.
- If `bootstrap-error-anchor` is `ok` at the same time `integration-asset` is `missing`, treat the issue as locally reproducible from source artifacts even before reopening Browser.

## Builder opens but saving fails

Source checks:
- `D:\WordPress\Html to Oxygen\oxygen\plugin\data\save.php`
- `D:\WordPress\Html to Oxygen\oxygen\plugin\admin\launcher\js\shared.js`
- grep `breakdance_save`

What to inspect:
- whether the save action fired
- whether the document id was correct
- whether unsaved changes remain present after clicking save
- whether the problem started before the builder even booted; if so, this is not a save bug
- whether the launch path should have saved the editor first through `saveGutenberg()` or `saveClassic()`

## Wrong URL pattern

Symptom:
- builder opens to the wrong place or not at all

Action:
- Re-check `D:\WordPress\Html to Oxygen\oxygen\plugin\admin\util.php`
- Use `?oxygen=builder&id={postId}`
- For browse mode, add `mode=browse`, not a guessed alternative

## Frame confusion

Symptom:
- clicks hit the wrong surface or controls are missing

Action:
1. Re-snapshot.
2. Determine whether the target is in the outer shell or inside `breakdance-browser-iframe`.
3. Enter nested builder helper iframes only when the snapshot shows them.

## WordPress editor leave-page prompt before builder launch

Symptom:
- Browser shows an unsaved-changes prompt while leaving the post editor for Oxygen.

Meaning:
- The launch likely bypassed the official launcher sequence that clears `beforeunload.edit-post`, or the editor save had not finished yet.

Action:
1. Retry from the official launcher button or row action instead of typing the builder URL manually from the edit screen.
2. On Gutenberg, wait for the editor save to settle before expecting redirect.
3. On Classic editor, allow the autosave heartbeat path to complete.
