# Builder UI Map

This file records source-backed UI anchors. Treat them as orientation hints until Browser snapshots confirm the exact rendered DOM.

## Outer builder shell

Built bundle clues from `D:\WordPress\Html to Oxygen\oxygen\builder\dist\js\app.*.js` show:

- A top bar with breakpoint controls
- Undo/redo controls
- Save controls
- Sidebar panels for global settings, selectors, presets, and history
- Mode-specific layouts for builder and browse mode

Before the builder loads, the most reliable outer-shell action is often the launcher surface rather than the builder canvas itself. Use the launcher map in `login-and-navigation.md` to enter the shell cleanly.

## Confirmed labels from source

From `D:\WordPress\Html to Oxygen\oxygen\languages\breakdance-builder.pot`:

- `Save`
- `Save and continue`
- `Global Settings`
- `Selectors`
- `Design Presets`
- `History`

These are good starting visible-text anchors in Browser when exact DOM attributes are unknown.

## Browse mode shell

Source-backed component names in the built bundle:

- `BrowseModeSave`
- `BrowseModeSidebarPanels`
- `BreakpointsSelection`
- `IframeWrapper`
- `ClosingConfirmations`

Observed behavior from the bundle:

- Browse mode displays a top bar with the viewed page URL.
- Global settings, selectors, presets, and history can be opened from a menu.
- Saving is handled from the outer shell, not the page iframe.

## Frontend admin-bar shell

When logged in on the frontend, WordPress can expose Oxygen entrypoints before the builder opens:

- Parent menu id: `#wp-admin-bar-breakdance_admin_bar_menu`
- Current document item id: `#wp-admin-bar-edit_with_breakdance`
- Template/header/footer items use `#wp-admin-bar-edit_template_with_breakdance_{id}`, `#wp-admin-bar-edit_header_with_breakdance_{id}`, and `#wp-admin-bar-edit_footer_with_breakdance_{id}`

Treat these as pre-builder navigation controls, not builder-shell controls.

## Oxygen-specific theme signals

From `D:\WordPress\Html to Oxygen\oxygen\builder\dist\css\chunk-common.*.css`:

- Root class `is-oxygen`
- Oxygen-specific scrollbars:
  - `oxygen-custom-scrollbar`
  - `oxygen-thin-scrollbar`

These are useful context signals when verifying that the UI loaded in Oxygen mode rather than generic Breakdance mode.

## Grep anchors

- `rg -n "BrowseModeSave|BreakpointsSelection|SelectorsPanel|GlobalSettingsPanel|HistoryAndRevisionsPanel|is-oxygen" D:\WordPress\Html to Oxygen\oxygen`
