# Editing Workflows

These workflows are intentionally conservative until browser verification is available.

## Open an existing page in Oxygen

1. Log in to wp-admin.
2. Prefer the verified Pages-list workflow when you do not already have a post edit screen open:
   - open `http://oxyconvo6.localhost/wp-admin/edit.php?post_type=page`
   - find the target page row
   - click that row's `Edit in Oxygen` action
3. If you are already on the target page or post edit screen, prefer the launcher-provided Oxygen entry point when visible.
4. If the launcher is missing but the post id is known, open `/?oxygen=builder&id={postId}` directly.
5. Snapshot once the builder shell loads and confirm Oxygen mode before editing.
6. If the builder immediately shows a 500 overlay with `[vp-wp] Entry assets/integration/oxygen/main.js not found.`, stop. That is an environment or asset issue, not a selector issue.

## Save safely

1. Make one small edit.
2. Re-snapshot if the edit opened a panel or iframe.
3. Click the visible `Save` control in the outer shell.
4. Confirm the unsaved-change warning disappears or the save control returns to idle.
5. If you launched from wp-admin and need to leave the editor screen before opening the builder, prefer the built launcher flow because `shared.js` explicitly removes `beforeunload.edit-post` before redirecting.

What the save action actually persists:
- `tree` is written into `tree_json_string`
- `singularityMeta` is written separately
- `templateSettings`, `classes`, `oxySelectors`, `presets`, `variables`, `globalSettings`, and `ai` save only when present
- WordPress post modified time is updated with `wp_update_post(['ID' => $id])`
- post cache is regenerated with `\Breakdance\Render\generateCacheForPost($id)`

Source anchors:
- `D:\WordPress\Html to Oxygen\oxygen\plugin\data\save.php`
- `D:\WordPress\Html to Oxygen\oxygen\plugin\admin\launcher\js\shared.js`
- built bundle grep:
  `rg -n "unsavedChangesPresent|Save and continue|save_document|breakdance_save" D:\WordPress\Html to Oxygen\oxygen`
  `rg -n "beforeunload.edit-post|saveGutenberg|saveClassic|redirectToBuilder" D:\WordPress\Html to Oxygen\oxygen`

## Change classes or selectors

1. Open the selectors or class-related panel from the outer shell.
2. Prefer visible labels confirmed by the current snapshot.
3. If the UI behavior is unclear, consult `references/data-model.md` to understand how classes are persisted before attempting more changes.

## When a task is not yet browser-safe

If you cannot reliably identify the correct element, panel, or frame:

- stop before interacting
- collect a fresh snapshot
- consult `references/builder-ui-map.md` and `references/selectors-and-locators.md`
- document the blocker instead of guessing

If the canonical builder URL itself is reachable but the UI fails before the shell loads:

- capture the exact overlay text
- capture the exact builder URL and page id
- check `references/troubleshooting.md` for builder bootstrap failures before doing more UI work
