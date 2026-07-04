# Open Core + Pro Strategy

This repository is the **Core** plugin (`oxygen-html-converter`).

## Repository Model

1. `oxygen-html-converter` (public)
- Free feature set with stable UX and API hooks.
- Community issue intake and bugfixes.
- Compatibility and security baseline.

2. `oxygen-html-converter-pro` (private)
- Premium feature plugin that requires Core.
- Ships as a separate plugin package and license flow.
- Extends Core through hooks, not by patching Core files.

## Core/Pro Contract

Core exposes extension points used by Pro:
- `oxy_html_converter_before_boot`
- `oxy_html_converter_loaded`
- `oxy_html_converter_core_init`
- `oxy_html_converter_feature_flags`
- `oxy_html_converter_builder_script_data`
- `oxy_html_converter_after_enqueue_builder_scripts`
- `oxy_html_converter_convert_options`
- `oxy_html_converter_tree_builder`
- `oxy_html_converter_conversion_result`
- `oxy_html_converter_convert_response`
- `oxy_html_converter_batch_response`
- `oxy_html_converter_preview_response`

Core must treat these hooks as backward-compatible API within a major contract version.
The contract version is exposed in `OXY_HTML_CONVERTER_API_VERSION`.

## Boundary Extension Points

These named boundaries are reserved for Core-safe extension without moving public Core code into the Pro package:

| Boundary | Core hook or module surface | Intended extension owner |
| --- | --- | --- |
| Tailwind mode selection | `oxy_html_converter_convert_options`; `oxy_html_converter_feature_flags` | Core for common native mapping, Pro/future for full utility parity |
| WindPress integration | `oxy_html_converter_feature_flags`; `OxyHtmlConverter\Services\WindPressCacheResetService` | Pro |
| site-kit automation | `oxy_html_converter_conversion_result`; `oxy_html_converter_convert_response` | Pro |
| advanced components | `oxy_html_converter_tree_builder`; `oxy_html_converter_conversion_result` | Future/Core when fixture-proven, otherwise Pro |
| forms | `oxy_html_converter_tree_builder`; conversion audit/report hooks | Future safe Core strategy or Pro integration |
| dynamic data and loops | `oxy_html_converter_tree_builder`; `oxy_html_converter_convert_response` | Pro |
| WooCommerce | `oxy_html_converter_feature_flags`; conversion result hooks | Pro |
| menus | conversion result hooks and future manifest importer | Future/Core for static links, Pro for site automation |

## Recommended Split

Keep in Core:
- HTML import and baseline conversion pipeline.
- UI needed for general usage.
- Baseline quality, bug fixes, and compatibility work.
- Tailwind-style class recognition where it maps to native Oxygen output without optional integrations.
- Explicit unsupported/future reporting for forms, dynamic data, WooCommerce, menus, and advanced components.

Move to Pro over time:
- Workflow accelerators (batch import from URL/ZIP, presets, pipelines).
- Team/agency features (saved templates, sharing, histories).
- Advanced AI-assisted mapping and premium integrations.
- WindPress-specific Tailwind rendering mode, cache tooling, version checks, and live integration gates.
- site-kit automation, dynamic data, WooCommerce, and WordPress menu assignment.
- Priority support tooling and diagnostics.

## Release Flow

1. Release Core publicly first.
2. Add Pro plugin as a separate repo from `scaffolds/oxygen-html-converter-pro`.
3. Never commit release ZIPs, logs, or local snapshots into either repo.
4. Publish ZIPs only through GitHub Releases or your sales delivery channel.
