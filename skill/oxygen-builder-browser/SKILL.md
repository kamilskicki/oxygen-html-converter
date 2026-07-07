---
name: oxygen-builder-browser
description: Use when operating Oxygen Builder 6 or the Oxygen WordPress builder UI through @Browser or browser-use on a local dev site, including login, opening the builder for a page/template, understanding the builder canvas or iframe, editing content/styles/classes/settings, saving, and validating frontend output.
---

# Oxygen Builder Browser

Use this skill when Codex needs to drive Oxygen Builder 6 in the browser on the local dev stack.

## Quick Start

1. Read `references/environment.md` for exact local paths, URLs, credentials, and current environment caveats.
2. Before the first browser action, read the `browser-use:browser` skill and use the in-app Browser flow only.
3. Run `scripts/check-local-environment.ps1` when builder access is uncertain.
4. Use `references/login-and-navigation.md` to log in and derive the canonical builder URL for a post.
5. Use `references/builder-ui-map.md` and `references/canvas-and-iframe.md` to orient inside the builder before clicking.
6. Use `references/editing-workflows.md` for common tasks and `references/validation.md` after each meaningful change.

## Workflow

### 1. Verify local prerequisites

- Confirm the repo root, Oxygen source tree, and browser plugin script exist.
- Confirm whether the local hostname and Docker-backed site are reachable from the current environment.
- If the in-app browser backend is unavailable, stop browser work and capture the blocker in your response.

### 2. Open the correct builder surface

- The canonical builder URL pattern comes from `oxygen/plugin/admin/util.php`.
- Open `home_url/?oxygen=builder&id={postId}` for a document-specific builder session.
- For browse mode, use `home_url/?oxygen=builder&mode=browse` plus the encoded `browseModeOpenUrl` and optional `returnUrl`.
- Do not guess alternate query keys when source already defines the loader URL.

### 3. Establish frame strategy before editing

- Use a fresh DOM snapshot or screenshot to determine whether you are in the parent builder shell, the canvas iframe, or browse-mode iframe.
- Expect the browse-mode iframe id `breakdance-browser-iframe` based on the built builder bundle.
- Parent UI actions such as save, breakpoints, selectors, and global settings live in the outer builder shell, not inside the preview page.

### 4. Make one small edit at a time

- Prefer one reversible UI action, then verify the state change immediately.
- Re-snapshot after opening sidebars, menus, dropdowns, or switching frames.
- When UI selectors are unclear, fall back to source-backed labels and visible text from the latest snapshot, not guessed selectors.

### 5. Save and validate

- Save after each coherent unit of work.
- Validate both builder state and frontend output.
- If browser access is blocked, use the local scripts and source references to narrow the failure mode for the next run.

## References

- `references/environment.md`: local URLs, credentials, paths, and current access notes
- `references/login-and-navigation.md`: login flow and canonical builder URLs
- `references/builder-ui-map.md`: source-backed map of top-bar, panels, and browse-mode chrome
- `references/canvas-and-iframe.md`: iframe ownership and browse-mode frame notes
- `references/editing-workflows.md`: repeatable operating workflows
- `references/data-model.md`: `_oxygen_data` and save/load anchors
- `references/selectors-and-locators.md`: stable locator strategy and grep anchors
- `references/troubleshooting.md`: known failure modes and how to isolate them
- `references/validation.md`: cheapest checks after edits

## Scripts

- `scripts/check-local-environment.ps1`: quick environment probe for site reachability, browser plugin presence, and Docker access
- `scripts/smoke-builder-access.mjs`: builds canonical Oxygen URLs and performs lightweight HTTP probes for login, admin, and optional builder targets
- `scripts/summarize-oxygen-source.ps1`: prints the key source anchors and grep terms for this skill
- `scripts/script-usage.md`: when to run each script

## Known Gaps

- M8 live smoke now verifies login, Builder open/save/reopen, nonblank canvas, editability signals, selector persistence, and site-kit surfaces on the local Docker site.
- Browser work should still confirm the current target page/post ID and not rely on stale page IDs from older reports.
- If Builder launch regresses, use the failure artifacts from `npm run test:live` or `npm run test:visual` before adding new locator assumptions.
