# Upgrading to 0.9.0-beta

This guide describes the expected direct upgrade from `0.8.0-beta` to `0.9.0-beta`. Current release evidence does not include a dedicated in-place upgrade test, so validate this path on staging before using it on a production site.

## Before upgrading

- Confirm the site meets the current plugin requirements: WordPress `6.5+`, PHP `8.2+`, and an active Oxygen Builder `6.1.0` installation.
- Back up the WordPress database and test the beta on staging before changing production content.
- Finish or record any in-progress imports so their rollback data is not confused with a later re-import.

## What changes

`0.9.0-beta` tightens Safe Mode and supported-scope reporting, adds builder-safe Oxygen document serialization, expands import and rollback records, and adds new response fields such as `documentTree` and `documentJson`. Existing extension code can continue using the earlier `element` and `json` fields while it adopts the new document payloads.

The release also raises the supported PHP runtime to `8.2+`. Upgrade PHP before activating this plugin version if the site still runs an older release.

## Migration behavior

No automatic data migration runs for this upgrade. The plugin creates no custom database tables and has no stored schema version to migrate. The existing `oxy_html_converter_*` settings used by `0.8.0-beta` keep their keys and compatible values, so they remain available to `0.9.0-beta`.

Previously imported WordPress posts, Oxygen document data, selectors, variables, global settings, and converter import/rollback post meta are left unchanged. The new serialization and validation behavior applies when content is converted or imported again; the upgrade does not silently rewrite existing Oxygen documents.

If an existing import needs the `0.9.0-beta` output contract, reconvert and re-import it on staging, review the conversion audit, and verify Builder save/reopen behavior before replacing live content.

## Retained page CSS

Page and fallback CSS stored in `_oxy_html_converter_page_styles` is retained, but Oxygen HTML Converter emits that CSS at runtime. Pages that depend on it can lose styling when the plugin is deactivated or uninstalled. Before removing the plugin, migrate required CSS into Oxygen or another persistent stylesheet and test the pages with the plugin inactive. See [OPERATIONS.md](OPERATIONS.md) for the operational checklist.

## After upgrading

1. Open **Tools > Oxygen HTML Converter** and confirm the converter loads without a compatibility notice.
2. Preview a representative supported page in Safe Mode.
3. Import on staging, open the result in Oxygen, save it, reopen it, and verify the frontend.
4. Confirm Oxygen cache generation can write below `wp-content/uploads/oxygen`.
5. Review custom integrations against the versioned hooks and response fields documented in the project README.
