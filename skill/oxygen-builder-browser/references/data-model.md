# Data Model

## Core storage

Source of truth:
- `D:\WordPress\Html to Oxygen\oxygen\plugin\data\tree.php`
- `D:\WordPress\Html to Oxygen\oxygen\plugin\data\save.php`
- `D:\WordPress\Html to Oxygen\oxygen-html-converter-dev\knowledge\KBAI\02-oxygen-reference\oxygen-6-breakdance-core.md`

Key facts:

- The document tree is stored in post meta under the Oxygen/Breakdance data key.
- The tree payload uses `tree_json_string`.
- `save_document(...)` writes the tree, singularity meta, selectors, oxy selectors, presets, variables, and global settings when provided.
- Saving also updates the WP post modified date and regenerates cache for the post.
- The save handler does not require every payload on every request; a Browser save may only send the changed slices plus `id`.

## Important save endpoint

- AJAX action: `breakdance_save`
- Registered in `D:\WordPress\Html to Oxygen\oxygen\plugin\data\save.php`
- Required payload includes at least:
  - `tree`
  - `id`
- Optional payloads include:
  - `templateSettings`
  - `singularityMeta`
  - `globalSettings`
  - `classes`
  - `oxySelectors`
  - `presets`
  - `variables`
  - `ai`

## Load endpoint

- AJAX action: `breakdance_load_document`
- Registered in `D:\WordPress\Html to Oxygen\oxygen\plugin\data\load.php`
- When given `id`, it returns the current document tree and builder boot data.

## Practical implications

- A visible save button in the UI should ultimately drive `breakdance_save`.
- If only selectors, variables, or global settings changed, persistence can still succeed without a full tree rewrite as long as the corresponding payload is present.
- If the builder appears loaded but edits do not persist, inspect save flow first before blaming frontend render.
- Source-based debugging can compare load and save payload expectations even when Browser is unavailable.

## Launcher and unsaved-state interaction

- `D:\WordPress\Html to Oxygen\oxygen\plugin\admin\launcher\js\shared.js` clears `beforeunload.edit-post` before redirecting to the builder.
- Gutenberg launcher flow saves through `wp.data.dispatch("core/editor").savePost()` and waits for save completion via `wp.data.subscribe(...)`.
- Classic editor launcher flow triggers `wp.autosave.server.triggerSave()` and waits for `heartbeat-tick.autosave`.

Operational takeaway:
- If Browser shows a WordPress "leave page" prompt while opening the builder from wp-admin, prefer the official launcher button over manual navigation so the editor save and unload-guard cleanup happen in the expected order.

## Grep anchors

- `rg -n "breakdance_save|breakdance_load_document|tree_json_string|save_document|get_tree" D:\WordPress\Html to Oxygen\oxygen`
