# Oxygen HTML Converter Pro (Starter)

This folder is a starter scaffold for a **separate private repository**.

## How to use

1. Copy this folder into a new repo named `oxygen-html-converter-pro`.
2. Keep it as a standalone plugin in `wp-content/plugins/oxygen-html-converter-pro`.
3. Require `oxygen-html-converter` core plugin to be active.
4. Add premium functionality by hooking Core actions and filters.

## Notes

- Do not vendor-lock Pro logic inside Core.
- Keep shared contracts in Core hooks and documented payload shapes.
- Version Pro independently from Core.
- Keep code licensing WordPress-compatible (GPL); monetize through licensing, support, and premium capabilities.

## Reserved Component Extension Points

Core reports advanced component patterns without guessing unsupported serialization. Pro can implement verified mappings through these reserved extension points:

| Pattern | Extension point |
| --- | --- |
| variants | `oxy_html_converter_component_variant_mapper` |
| repeated regions | `oxy_html_converter_component_repeated_region_mapper` |
| lists | `oxy_html_converter_component_list_mapper` |
| forms | `oxy_html_converter_component_form_mapper` |
| dynamic data | `oxy_html_converter_pro_dynamic_component_mapper` |
| component-scoped CSS | `oxy_html_converter_component_scoped_css_mapper` |

Each mapper must preserve Core's import-plan reporting contract and only mark a pattern as supported after a verified Oxygen 6 fixture exists.

## Reserved Site Operation Extension Points

Core can persist explicit static site-kit homepage, menu, and template records. Pro can implement CMS-aware behavior through these reserved extension points:

| Operation | Extension point |
| --- | --- |
| dynamic bindings | `oxy_html_converter_pro_dynamic_binding_mapper` |
| loops and repeaters | `oxy_html_converter_pro_loop_mapper` |
| WooCommerce areas | `oxy_html_converter_pro_woocommerce_mapper` |

Core reports these operations as product-boundary deferrals unless a verified extension owns the mapping and persistence contract.

## Reserved Tailwind And WindPress Extension Points

Core maps a conservative Tailwind subset to native Oxygen properties and emits safety fallback CSS without requiring Tailwind or WindPress at runtime. Pro can opt into runtime-specific behavior through these feature flags and hooks:

| Capability | Feature flag or extension point |
| --- | --- |
| Tailwind runtime bridge | `tailwind_runtime_integration`; `oxy_html_converter_convert_options` |
| WindPress class mode selection | `windpress_integration`; `windpress_class_mode` |
| WindPress cache reset | `windpress_cache_reset`; `oxy_html_converter_windpress_cache_reset_enabled` |

Keep all WindPress side effects behind explicit opt-in flags. Core imports must remain native and side-effect-free when these flags are disabled.
