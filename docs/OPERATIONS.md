# Operations and Oxygen cache permissions

Oxygen cache regeneration writes generated files below `wp-content/uploads/oxygen`, commonly including `wp-content/uploads/oxygen/css`. The PHP/WordPress runtime user must be able to create, replace, and remove files in that directory tree.

## Required state

- `wp-content/uploads/oxygen` and all cache subdirectories are owned by, or group-writable for, the PHP/WordPress runtime user.
- Directories have normal traversable and writable modes for the selected owner/group, commonly `755` for matching ownership or `775` for group-writable deployments.
- Files commonly use `644` or `664`, matching the ownership model.
- World-writable `777` permissions are not required and should not be used as the fix.

In the maintained Docker environment, the WordPress runtime user is `www-data`. Verify the actual PHP-FPM, Apache, or container user before changing ownership on another host.

## Failure and recovery

When converter-triggered Oxygen cache regeneration throws an error, Oxygen HTML Converter keeps the successfully imported document, logs the cache error to the PHP error log, and stores a one-time warning for wp-admin. The page can temporarily use stale or missing generated CSS until the cache is regenerated.

1. Inspect ownership and permissions for `wp-content/uploads/oxygen` and its descendants.
2. Correct them to match the PHP/WordPress runtime user or writable deployment group.
3. Regenerate Oxygen caches using the normal Oxygen maintenance workflow.
4. Reload the imported page and confirm the frontend CSS is present.
5. Check the PHP error log for any new cache-write failure.

For Docker, make ownership changes inside the WordPress container so the container path and runtime UID/GID are used. Avoid applying recursive ownership changes outside the scoped uploads directory.

## Deactivation and uninstall

Imported posts, Oxygen document data, and converter post meta are retained when the plugin is uninstalled. Converter-owned page and fallback CSS stored in `_oxy_html_converter_page_styles` is printed by Oxygen HTML Converter at runtime, however. Deactivating or uninstalling the plugin stops that runtime output, so an imported page that depends on the retained CSS may lose styling.

Before removing the plugin, move or materialize required converter-owned CSS into Oxygen or another persistent stylesheet, then verify each affected page with the plugin inactive. This retention policy preserves user content without leaving executable cleanup logic that edits imported documents during uninstall.
