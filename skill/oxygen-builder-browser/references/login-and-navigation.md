# Login And Navigation

## Canonical builder URLs

Source of truth:
- `D:\WordPress\Html to Oxygen\oxygen\plugin\admin\util.php`
- Function: `Breakdance\Admin\get_builder_loader_url($post_id)`

Derived URL patterns:
- Document builder: `home_url/?oxygen=builder&id={postId}`
- Browse mode shell: `home_url/?oxygen=builder&mode=browse`
- Browse mode with target page: `home_url/?oxygen=builder&mode=browse&browseModeOpenUrl={urlencodedPageUrl}`
- Browse mode with return target: add `returnUrl={urlencodedAdminOrFrontendUrl}`

Do not substitute `postId`; Oxygen source uses `id`.

## Admin launcher helpers

Source of truth:
- `D:\WordPress\Html to Oxygen\oxygen\plugin\admin\launcher\shared.php`
- `D:\WordPress\Html to Oxygen\oxygen\plugin\admin\launcher\js\shared.js`
- `D:\WordPress\Html to Oxygen\oxygen\plugin\admin\launcher\classic.php`
- `D:\WordPress\Html to Oxygen\oxygen\plugin\admin\admin_bar_menu.php`

Useful facts:
- The launcher injects `window.breakdanceConfig.builderLoaderUrl`.
- The admin-side helper replaces `%%POSTID%%` with `#post_ID`.
- If the post has no title, the launcher can auto-generate `${builderName} - ${postId}` before redirecting.
- The launcher body can include `is-breakdance-available` and `oxygen-mode`, which are useful wp-admin state hints before trying to open the builder.

## Preferred launch surfaces

Use the most direct verified surface that matches the current page instead of wandering through wp-admin menus.

1. Direct URL:
   - Open `http://oxyconvo6.localhost/?oxygen=builder&id={postId}` when you already know the post id.
2. Classic editor:
   - Large launcher button: `.breakdance-launcher-button[data-breakdance-action="edit"]`
   - Small launcher button near the title: `.breakdance-launcher-small-button[data-breakdance-action="edit"]`
3. Gutenberg launcher block:
   - Edit button: `[data-test-id="launcher-edit"]`
   - Disable button: `[data-test-id="launcher-disable"]`
4. Frontend admin bar:
   - Parent menu id: `breakdance_admin_bar_menu`
   - Child items are created with ids such as `edit_with_breakdance`, `edit_template_with_breakdance_{id}`, `edit_header_with_breakdance_{id}`, and `edit_footer_with_breakdance_{id}`.

If a launcher button is present, prefer clicking it over reconstructing the URL by hand because it confirms the current editor context already exposes Oxygen access.

## Browser login flow

1. Open `http://oxyconvo6.localhost/wp-login.php`.
2. Snapshot before filling.
3. Fill username `admin`.
4. Fill password `admin`.
5. Submit and confirm the admin dashboard or editor screen loaded.
6. Navigate to the target post or page edit screen, then use the best available launcher surface from the list above or the direct builder URL.

## Navigation shortcuts after login

- If you are already on the frontend while logged in, check for the WordPress admin bar first. It can open the current page, matched templates, header, or footer directly into Oxygen.
- If you are on the post editor, look for the launcher before opening the page list. The launcher JS already knows how to save-and-redirect from Gutenberg and how to clear `beforeunload.edit-post`.
- Only fall back to the Pages or Templates list when you still need to discover the document id.

## Browser-verified Pages list workflow

This run verified a low-friction launch surface that does not require opening Gutenberg first.

1. Log in at `http://oxyconvo6.localhost/wp-login.php`.
2. Open `http://oxyconvo6.localhost/wp-admin/edit.php?post_type=page`.
3. Use the page table row action link named `Edit in Oxygen`.
4. Expect the link target to already contain the canonical URL form:
   - `http://oxyconvo6.localhost/?oxygen=builder&id={postId}`
5. Known verified example from this run:
   - page title `Fixture design-1-noir-architect`
   - wp-admin edit link `post.php?post=521&action=edit`
   - Oxygen launcher link `http://oxyconvo6.localhost/?oxygen=builder&id=521`

This Pages-list route is currently the most reliable way to capture both the human page title and the concrete post id from a Browser snapshot.

If this workflow reaches the canonical `/?oxygen=builder&id={postId}` URL but the page then shows `[vp-wp] Entry assets/integration/oxygen/main.js not found.`, treat launch navigation as verified and move to integration-bootstrap troubleshooting instead of retrying alternate launcher URLs.

## Finding a post id

Preferred order:
1. Read the post/page edit URL in wp-admin.
2. Read the hidden `#post_ID` field on the editor screen if visible in the DOM snapshot.
3. If source or local scripts already know the post id, use that exact id.
4. If you only need the canonical URLs outside Browser, run `node .\scripts\smoke-builder-access.mjs --post-id {id}`.

## Pre-browser smoke check

When the site should be running but Browser access is still uncertain:

1. Run `node .\scripts\smoke-builder-access.mjs` for base frontend, login, and admin probes.
2. If you know the post id, rerun with `--post-id {id}` to emit the exact document-builder URL.
3. If you need browse mode for a target page, pass `--open-url` and optional `--return-url` so the encoded source-backed URL is produced instead of hand-building it.
4. If these probes fail, treat the environment as the blocker before spending time on builder selectors.

## Grep anchors

- `rg -n "get_builder_loader_url|builderLoaderUrl|%%POSTID%%|redirectToBuilder|launcher-edit|breakdance_admin_bar_menu|data-breakdance-action" D:\WordPress\Html to Oxygen\oxygen`
