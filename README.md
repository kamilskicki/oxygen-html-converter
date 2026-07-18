# Oxygen HTML Converter

Convert pasted HTML into native Oxygen Builder 6 content that can be edited and saved in the builder.

This repository is the public `Core` plugin. The long-term product goal is simple: paste HTML, get a native Oxygen document, and continue editing without validation errors or broken document state.

Current release candidate:

- `v0.9.0-beta` public beta candidate
- WordPress.org promotion will use the numeric stable tag `0.9.0`; the plugin runtime and release documentation retain the `0.9.0-beta` prerelease version

## What Core Does

- Converts full HTML fragments and pages into native Oxygen elements
- Preserves IDs, classes, data attributes, and inline styles
- Supports builder paste/import flows
- Maps supported safe interactions; executable script fallback requires explicit unsafe opt-in and permission
- Keeps imported content editable in Oxygen Builder

## Supported Scope

Core is intended for:
- single-page marketing and landing-page HTML
- inline styles
- utility-first markup, especially Tailwind-like classes
- native Oxygen conversion of supported utility styling
- common frontend interactions such as nav toggles, anchor scroll, reveal-on-scroll, counters, and simple inline handlers

Core is not yet a promise of perfect conversion for every arbitrary frontend stack on the web.

Detailed scope:
- [Supported Scope](docs/SUPPORTED_SCOPE.md)
- [OxyMade/Oxygen 6 Globals + WindPress Decision](docs/OXYMADE_OXYGEN6_GLOBALS_AND_WINDPRESS_DECISION.md)
- [Definition of Done for v0.9.0-beta](docs/DOD-0.9.0-BETA.md)

## Import Methods

- Admin page: `Tools -> Oxygen HTML Converter`
- Builder paste: `Ctrl+V`
- Builder import modal: `Ctrl+Shift+H`

## Requirements

- WordPress `6.5+`
- PHP `8.2+`
- Oxygen Builder `6.1.0`
- Logged-in user with `manage_options` by default

The required capability can be changed with `oxy_html_converter_required_capability`.

## Open Core Model

This repo contains only the public `Core`.

- Core owns conversion, builder-safe serialization, editability, and baseline parity
- Pro should extend Core through hooks, not patch Core files
- Core must not require Pro for the import/editability guarantee

More detail:
- [Open Core Model](docs/OPEN_CORE.md)

## Project Docs

- [Documentation Index](docs/README.md)
- [Contributing](CONTRIBUTING.md)
- [Security Policy](SECURITY.md)
- [Release Checklist](docs/RELEASE_CHECKLIST.md)
- [Release Notes: 0.9.0-beta](docs/RELEASE_NOTES_0.9.0_BETA.md)
- [Upgrade Guide](docs/UPGRADE.md)
- [Operations and Cache Permissions](docs/OPERATIONS.md)
- [Changelog](CHANGELOG.md)

## Installation

1. Download the latest release ZIP.
2. In WordPress admin, go to `Plugins -> Add New -> Upload Plugin`.
3. Upload the ZIP and activate it.
4. Ensure Oxygen Builder 6.1.0 is installed and active.

## Testing

- Fast gate: `npm run check`
- PHP unit gate: `composer test`
- JS tests: `npm run test:js`
- Local live gate: `npm run test:live`
- Install latest ZIP via wp-admin on the local Oxygen stack: `npm run install:zip`
- Artifact live gate against the installed ZIP: `npm run test:live:artifact`
- Maintained fixture screenshot pairs and frontend smoke: `npm run test:visual`
- Full artifact gate after `npm run build:zip`: `npm run test:artifact`
- Release verification: `npm run release:verify`
- Build release ZIP: `npm run build:zip`

## Extension Hooks

Core exposes versioned extension points for add-ons, including Pro:

- `oxy_html_converter_before_boot`
- `oxy_html_converter_core_init`
- `oxy_html_converter_loaded`
- `oxy_html_converter_feature_flags`
- `oxy_html_converter_builder_script_data`
- `oxy_html_converter_after_enqueue_builder_scripts`
- `oxy_html_converter_convert_options`
- `oxy_html_converter_required_capability`
- `oxy_html_converter_preview_options`
- `oxy_html_converter_batch_options`
- `oxy_html_converter_tree_builder`
- `oxy_html_converter_conversion_result`
- `oxy_html_converter_convert_response`
- `oxy_html_converter_batch_response`
- `oxy_html_converter_preview_response`
- `oxy_html_converter_expose_error_details`

API compatibility is versioned via `OXY_HTML_CONVERTER_API_VERSION`.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
