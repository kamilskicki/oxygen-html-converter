# Selectors And Locators

## Current confidence level

This file lists source-backed locator anchors, not fully browser-verified selectors. Use them to narrow searches in Browser snapshots.

## Prefer these anchors first

1. Visible labels from the current snapshot:
   - `Save`
   - `Global Settings`
   - `Selectors`
   - `Design Presets`
   - `History`
2. Oxygen-mode context classes:
   - `is-oxygen`
   - `oxygen-custom-scrollbar`
   - `oxygen-thin-scrollbar`
3. Browse-mode iframe id:
   - `breakdance-browser-iframe`
4. Admin-side config names when inspecting launcher screens:
   - `builderLoaderUrl`
   - `ajaxNonce`
   - `openButton`
5. Source-backed launcher selectors:
   - `.breakdance-launcher-button[data-breakdance-action="edit"]`
   - `.breakdance-launcher-small-button[data-breakdance-action="edit"]`
   - `[data-test-id="launcher-edit"]`
   - `[data-test-id="launcher-disable"]`
6. Frontend admin-bar ids:
   - `#wp-admin-bar-breakdance_admin_bar_menu`
   - `#wp-admin-bar-edit_with_breakdance`
   - `#wp-admin-bar-edit_template_with_breakdance_{id}`
   - `#wp-admin-bar-edit_header_with_breakdance_{id}`
   - `#wp-admin-bar-edit_footer_with_breakdance_{id}`
7. Browser-verified wp-admin Pages list anchors:
   - page list URL: `http://oxyconvo6.localhost/wp-admin/edit.php?post_type=page`
   - row action link text: `Edit in Oxygen`
   - row-title link pattern: `http://oxyconvo6.localhost/wp-admin/post.php?post={id}&action=edit`
   - Oxygen row-action target pattern: `http://oxyconvo6.localhost/?oxygen=builder&id={id}`

## Locator strategy

- Build locators from the latest Browser DOM snapshot only.
- Scope to the outer builder shell before targeting generic controls like `Save`.
- If multiple matching buttons exist, scope by nearby text or container before clicking.
- Use frame locators only after the iframe is confirmed in the snapshot.
- When targeting launcher controls, prefer the source-backed selector over visible text because the button label changes with builder branding (`Edit in Oxygen` vs `Edit in Breakdance`).
- In the Pages list table, the visible row action text `Edit in Oxygen` is Browser-verified and unique only within the current row. Scope to the page title row before clicking if multiple row actions are visible.
- For frontend entrypoints, verify the WordPress admin bar is visible before querying `#wp-admin-bar-*` ids.

## Source grep terms

- `rg -n "Save and continue|Global Settings|Selectors|Design Presets|History" D:\WordPress\Html to Oxygen\oxygen\languages\breakdance-builder.pot`
- `rg -n "breakdance-browser-iframe|is-oxygen|builderLoaderUrl|launcher-edit|data-breakdance-action|breakdance_admin_bar_menu" D:\WordPress\Html to Oxygen\oxygen`

## Do not do this

- Do not invent `data-testid` values.
- Do not assume React/Vue component names are rendered CSS classes.
- Do not click guessed frame selectors or invisible save buttons.
