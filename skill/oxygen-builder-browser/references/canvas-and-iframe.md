# Canvas And Iframe

## Source-backed frame facts

- Browse mode uses iframe id `breakdance-browser-iframe`.
- The outer builder shell tracks iframe state, URL, preview width, and breakpoint.
- Global settings, selectors, presets, and history watchers update the iframe document after changes.

Source anchors:
- built bundle under `D:\WordPress\Html to Oxygen\oxygen\builder\dist\js\app.*.js`
- grep terms:
  - `breakdance-browser-iframe`
  - `iframeUrl`
  - `IframeWrapper`
  - `iframeDocument`

## Practical frame strategy

1. Snapshot the page immediately after opening the builder.
2. Identify whether the visible editing target is:
   - the outer builder shell
   - a browse-mode preview iframe
   - a nested content iframe such as media or TinyMCE controls
3. Use outer-shell locators for save, breakpoints, selectors, presets, and global settings.
4. Use frame locators only after the snapshot clearly shows the iframe selector.

## Known nested iframe entry points in source

The plugin includes builder helper iframes for:

- TinyMCE: `plugin/wpuiforbuilder/tinymce/tinymce-iframe.php`
- Media: `plugin/wpuiforbuilder/media/media-iframe.php`
- Link controls: `plugin/wpuiforbuilder/link/link-iframe.php`

Do not assume these are always present. Enter them only when the active control opens them.

## Browser rule

Do not guess frame selectors. Use the current DOM snapshot and the built-in `frameLocator(...)` only after the iframe is visible in the snapshot.
