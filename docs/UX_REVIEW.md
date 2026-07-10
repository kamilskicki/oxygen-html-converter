# UX Review - Oxygen HTML Converter Admin

Date: 2026-07-08
Environment: `http://oxyconvo6.localhost/wp-admin`, WordPress admin user `admin`
Viewport used for screenshots: 1280px wide

## Workflow Reviewed

1. Logged into WordPress admin.
2. Opened `Tools > Oxygen HTML Converter`.
3. Loaded the sample HTML.
4. Ran Preview.
5. Ran Convert.
6. Reviewed the generated import output and copied JSON state.

Evidence:

- `assets-wporg/screenshot-1.png` - main converter UI.
- `assets-wporg/screenshot-2.png` - options plus preview audit.
- `assets-wporg/screenshot-3.png` - conversion/import output.
- Convert result: 6 elements, 3 custom classes, JSON output populated.

## UX Findings

### High

- The admin flow labels the final step as import output, but it does not complete an import from this screen. Users must copy JSON into Oxygen or know to use the separate Builder import flow with `Ctrl+Shift+H`. This is a handoff rather than an import, and the page could make the next action more explicit.

### Medium

- After keyboard activation of Preview or Convert, focus falls back to the document body. The operation succeeds, but keyboard and screen reader users are not moved to the newly revealed preview or JSON result.
- The first keyboard path through WordPress admin is long unless the user activates the standard "Skip to main content" link. This is mostly WordPress chrome, but the plugin page benefits from keeping its primary action near the top, as it already does.

### Low

- The generated audit follow-up says to create or update a draft page and verify Oxygen editability. That is useful, but it appears after conversion rather than as a clear next-step action.
- The Copy JSON action becomes enabled after Convert and works from the keyboard, but there is no persistent success region confirming that copied content is ready for the Oxygen import step.

## Accessibility Smoke

Keyboard-only pass:

- The WordPress "Skip to main content" link is visible and works.
- Plugin controls are reachable by keyboard after the skip link: docs links, Load sample HTML, HTML textarea, preset select, option checkboxes, Preview, Convert, settings selects, and Save settings.
- Load sample HTML, Preview, Convert, and Copy JSON can be activated with the keyboard.
- Focus indicators are visible on links, buttons, selects, textareas, and checkboxes.

Labels and names:

- The HTML input and generated JSON textareas have labels.
- Preset and settings selects have labels.
- Option checkboxes have associated label text.
- Buttons have visible accessible text.
- No duplicate IDs were detected on the admin page.

Modal trap check:

- No active modal/dialog is present on the plugin admin page, so a live modal trap was not exercised there.
- The separate Builder import modal exists in the plugin scripts and should be smoke-tested inside an Oxygen Builder session before release because it owns the direct import interaction.

Console/runtime notes:

- No page errors were recorded during the admin flow.
- Only the expected WordPress jQuery Migrate console messages appeared.
- One Gravatar request was aborted during page load; it did not affect the plugin workflow.
