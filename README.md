# Oxygen HTML Converter

Convert pasted HTML into native Oxygen Builder 6 content that can be edited and saved in the builder.

This repository is the public `Core` plugin. The long-term product goal is simple: paste HTML, get a native Oxygen document, and continue editing without validation errors or broken document state.

Current release line:
- `v0.9.0-beta` work in progress
- stable public baseline: `v0.8.0-beta`

## What Core Does

- Converts full HTML fragments and pages into native Oxygen elements
- Preserves IDs, classes, data attributes, and inline styles
- Supports builder paste/import flows
- Preserves supported scripts and interactions needed for parity
- Keeps imported content editable in Oxygen Builder

## Supported Scope

Core is intended for:
- single-page marketing and landing-page HTML
- inline styles
- utility-first markup, especially Tailwind-like classes
- WindPress-assisted Tailwind rendering
- common frontend interactions such as nav toggles, anchor scroll, reveal-on-scroll, counters, and simple inline handlers

Core is not yet a promise of perfect conversion for every arbitrary frontend stack on the web.

Detailed scope:
- [Supported Scope](docs/SUPPORTED_SCOPE.md)
- [Definition of Done for v0.9.0-beta](docs/DOD-0.9.0-BETA.md)

## Import Methods

- Admin page: `Tools -> Oxygen HTML Converter`
- Builder paste: `Ctrl+V`
- Builder import modal: `Ctrl+Shift+H`

## Requirements

- WordPress `5.0+`
- PHP `7.4+`
- Oxygen Builder `6.x`
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
- [Roadmap](ROADMAP.md)
- [Contributing](CONTRIBUTING.md)
- [Release Checklist](docs/RELEASE_CHECKLIST.md)
- [Changelog](CHANGELOG.md)

## Installation

1. Download the latest release ZIP.
2. In WordPress admin, go to `Plugins -> Add New -> Upload Plugin`.
3. Upload the ZIP and activate it.
4. Ensure Oxygen Builder 6 is installed and active.

## Testing

- PHP unit gate: `composer test`
- JS tests: `npm run test:js`
- Combined gate: `npm test`

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
