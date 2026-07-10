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

## Builder Modal (UX-03 / PRD/09)

Date: 2026-07-10  
Environment: `http://oxyconvo6.localhost/?oxygen=builder&id=2109`  
Builder document: existing imported page `Fixture native-no-code-01-text`  
Browser/viewport: Playwright Chromium, 1440 x 1000  
Invocation: keyboard shortcut `Ctrl+Shift+H`; the test import was not saved.

Post-fix keyboard-only verdicts:

- **PASS - Tab order within modal.** Opening the modal places focus in the HTML textarea. Forward order is HTML textarea -> Safe mode -> Cancel -> Import into Builder -> Close -> HTML textarea, with no focus escape into the Builder.
- **PASS - Focus trap.** Pressing Tab on the last DOM-order control (`Import into Builder`) moves focus to the first (`Close`). The focused JS regression also verifies reverse wrapping from Close to Import with Shift+Tab.
- **PASS - Escape and focus restoration.** Escape closes the overlay and restores focus to the exact Builder control that held focus before `Ctrl+Shift+H` (the Desktop viewport control in this run).
- **PASS - Dialog semantics.** The modal exposes `role="dialog"`, `aria-modal="true"`, and `aria-labelledby="oxy-html-import-title"`; the referenced heading is `Import HTML`.
- **PASS - Async result announcements.** Conversion errors are written to the existing `aria-live="polite"` error region. Import progress/completion use a persistent `role="status"`, `aria-live="polite"`, `aria-atomic="true"` region; successful completion announced `HTML import completed.` after the visible modal closed.

Failures found and corrected:

- **Initial FAIL - Escape/focus restoration.** Oxygen consumed Escape before the modal's bubble-phase document listener. The modal key handler now runs in capture phase, prevents the default action, stops propagation for Escape, closes the modal, and restores the saved invoker focus.
- **Initial FAIL - Async success announcement.** Success was conveyed only by a visual toast without live-region semantics. The modal now owns a visually hidden persistent status region and updates it for import progress and successful completion.

Evidence:

- `artifacts/ux-modal/final-modal-open.png` - open modal used for the keyboard-order and dialog-semantics checks.
- `artifacts/ux-modal/final-modal-async-error.png` - visible async error associated with the polite error live region.
- `artifacts/ux-modal/final-modal-async-success.png` - successful unsaved Builder insertion and visual success toast; the parallel status region carried the screen-reader announcement.
- `artifacts/ux-modal/initial-modal-open.png`, `initial-modal-async-error.png`, and `initial-modal-async-success.png` - pre-fix evidence from the same Builder page.
