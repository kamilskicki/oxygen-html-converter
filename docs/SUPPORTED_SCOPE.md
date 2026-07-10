# Supported Scope

This document describes what the public `Core` plugin is meant to handle reliably today, and what it does not yet promise.

## Product Standard

For supported inputs, the expected result is:

- import succeeds without corrupting the Oxygen document
- the imported page opens in Oxygen Builder without validation errors
- the user can edit, save, and reopen the page in Builder
- the frontend remains materially close to the source HTML for a human reviewer

## Supported Inputs

Core is currently aimed at:

- single-page marketing and landing-page HTML
- HTML with inline styles
- utility-first CSS markup, especially Tailwind-style classes
- native Oxygen conversion of supported utility styling
- non-module inline scripts needed for page behavior
- simple preserved interactions such as:
  - menu toggles
  - anchor scroll
  - reveal-on-scroll
  - counters
  - straightforward inline event handlers

## Supported Output Guarantees

Core should provide:

- native Oxygen elements where supported
- builder-safe document serialization
- preserved IDs, classes, and relevant attributes
- safe degradation for unsupported constructs
- no dependency on `Pro` for import or editability

## Capability Boundary

| Capability | Current Core disposition | Notes |
| --- | --- | --- |
| Tailwind utility detection and native mapping | Core | Core may map common utilities to native Oxygen selectors/properties when this does not require optional runtime integrations. |
| Tailwind arbitrary/future utility parity | Future | Unmapped utilities must be reported or routed through an explicit fallback; Core must not claim complete Tailwind parity. |
| WindPress-specific rendering and cache operations | Pro | Core exposes feature flags and hooks, but WindPress class mode and cache reset are disabled by default and generic imports must not depend on WindPress. |
| site-kit manifest import | Core | Core can import static site-kit pages, Oxygen template/header/footer/part records, homepage assignment, and WordPress menu records from the documented manifest shape. |
| custom site orchestration beyond manifest import | Pro/Future | Discovery, crawling, CMS-aware page generation, and unattended full-site assembly beyond explicit manifests belong behind extension points. |
| Component editable text/link/image/icon | Core | Core may create editable properties for verified text, link URL, image source/alt, and icon fields using tested component `targets`, `controlPath`, and `propertyKey` records. |
| Advanced components beyond verified fields | Future/Pro/Unsupported by matrix | Core must report advanced patterns instead of guessing unverified component property serialization. See the advanced component scope matrix below. |
| forms | Unsupported by default / Future integration | Core must either use an approved safe strategy or report forms as unsupported; raw executable form fallback is unsafe unless explicitly selected. |
| dynamic data | Pro | Dynamic bindings, loops, and CMS-aware mapping are outside public Core until explicitly implemented by a verified extension. |
| WooCommerce | Pro | Product templates, carts, checkout, and WooCommerce dynamic areas are premium/future integration scope. |
| menus | Core for explicit site-kit menu manifests / Future for inferred menus | Static nav markup may be imported as editable containers/links. Explicit site-kit `menus` records can create/select WordPress menus and locations. Inferring menus from arbitrary HTML remains deferred. |
| unsafe executable preservation | Unsupported by default | Scripts, event handlers, dangerous URLs, and raw embeds require Safe Mode policy and explicit opt-in paths. |

### Tailwind/WindPress Service Boundary Review (CQ-05)

This review covers every service in src/Services with a direct Tailwind or WindPress reference. core-appropriate means the current responsibility fits Core's native mapping, safe fallback, reporting, or disabled extension-surface contract. flag means the service contains WindPress runtime, class-mode, cache, or Tailwind runtime/config behavior reserved for Pro by docs/OPEN_CORE.md; the flag applies to that branch even when the service also contains valid Core behavior.

| Service | Verdict | Boundary rationale |
| --- | --- | --- |
| BatchConvertRequestHandler | core-appropriate | Aggregates Tailwind class counts only; it does not select or invoke an optional runtime. |
| ClassStrategyService | flag | Native Tailwind-to-Oxygen mapping belongs in Core, but processWindPressMode() preserves classes for a WindPress-specific runtime path. |
| ConversionAuditBuilder | core-appropriate | Reports Tailwind class counts as conversion evidence without implementing an integration. |
| DesignDocumentBuilder | flag | Tailwind counting is Core-safe, but recommending WindPress and emitting WindPress-specific guidance is Pro integration behavior. |
| DocumentCssExtractor | flag | Runtime-independent fallback CSS is Core-safe, but shouldUseWindPressFallback() changes extraction for WindPress class mode. |
| EnvironmentService | flag | General environment checks are Core-safe, but WindPress activation detection and automatic/class-mode selection implement a Pro-reserved integration decision. |
| GridDetector | core-appropriate | Detects Tailwind-style grid hints and maps them to native Oxygen grid properties. |
| HeadAssetExtractor | flag | Rewriting and preserving tailwind.config scripts supports Tailwind runtime preservation/config handling, which is outside Core Safe Mode. |
| ImportPlanBuilder | flag | Declares a windpress_runtime destination and a required WindPress plugin dependency. |
| NativeCssMaterializer | flag | Native CSS materialization is Core-safe, but bypassing consumption/materialization in WindPress mode is a Pro-specific branch. |
| NativeNodeMapper | flag | Tailwind recognition for native nodes is Core-safe, but WindPress mode changes which classes become Oxygen selector references. |
| OxygenPageImporter | flag | The general importer belongs in Core, but directly constructing and invoking WindPressCacheResetService couples Core imports to Pro-reserved cache tooling. |
| PageStyleRepository | flag | Generic page-style persistence is Core-safe, but hard-coded WindPress runtime ownership and dependency notices are integration-specific. |
| PreviewRequestHandler | core-appropriate | Exposes the Tailwind class count already produced by Core conversion reporting. |
| StyleRoutingService | flag | Generic CSS routing is Core-safe, but its WindPress mode, safety bucket, runtime detection, and plugin dependency metadata implement a Pro path. |
| TailwindCssFallbackGenerator | core-appropriate | Produces bounded, materialized CSS with no default runtime dependency; consumers of its optional WindPress destination remain separately flagged. |
| TailwindDetector | core-appropriate | Recognizes utility-style source hints for native mapping and reporting. |
| TailwindPropertyMapper | core-appropriate | Conservatively maps a supported utility subset into native Oxygen properties and leaves unsupported utilities for explicit fallback/reporting. |
| UiConfigProvider | core-appropriate | Exposes the documented Core/Pro extension boundary with native mapping enabled and all runtime/WindPress flags disabled by default. |
| WindPressCacheResetService | flag | Detects WindPress internals, deletes its cache file, flushes its object cache, and is explicitly assigned to Pro in docs/OPEN_CORE.md. |

## Advanced Component Scope Matrix

Core currently supports verified editable component properties for text, link URL, image source/alt, and icon fields. Advanced component operations beyond that verified contract are explicitly scoped as follows:

| Advanced pattern | Current disposition | Extension point | Import behavior |
| --- | --- | --- | --- |
| variants | Future | `oxy_html_converter_component_variant_mapper` | Report as deferred; preserve static output where possible, but do not persist guessed variant targets. |
| repeated regions | Future | `oxy_html_converter_component_repeated_region_mapper` | Report as deferred; repeated source structures may become separate static/native children, but not editable repeater component properties. |
| lists | Future | `oxy_html_converter_component_list_mapper` | Import static list markup when safe; report editable list/repeater component properties as deferred. |
| forms | Unsupported in Core safe mode | `oxy_html_converter_component_form_mapper` | Report functional form component controls as unsupported unless a verified extension maps them. |
| dynamic data | Pro | `oxy_html_converter_pro_dynamic_component_mapper` | Report dynamic data, loops, archives, and CMS bindings as Pro scope; Core must not serialize guessed bindings. |
| component-scoped CSS | Future | `oxy_html_converter_component_scoped_css_mapper` | Report component-scoped CSS as deferred until component CSS ownership and host merge behavior is verified. |

## Dynamic And Site Operation Scope Matrix

M7 separates static site-kit operations that Core can persist from CMS-aware dynamic operations that Core must report instead of guessing.

| Operation | Current disposition | Extension point | Import behavior |
| --- | --- | --- | --- |
| homepage assignment | Core | `oxy_html_converter_site_homepage_importer` | Apply explicit `homepage` records through `SiteConfigurationImporter` with rollback. |
| WordPress menus | Core | `oxy_html_converter_site_menu_importer` | Apply explicit `menus` records through `SiteConfigurationImporter` with rollback. Inferred menus from arbitrary HTML are deferred. |
| single templates | Core | `oxy_html_converter_template_importer` | Persist Oxygen template posts with verified static single-template conditions. |
| archive templates | Core | `oxy_html_converter_template_importer` | Persist Oxygen template posts with verified static archive-template conditions. Query/listing behavior inside the template is still deferred. |
| dynamic bindings | Pro | `oxy_html_converter_pro_dynamic_binding_mapper` | Report as deferred product-boundary items; Core must not serialize guessed CMS binding paths. |
| loops and repeaters | Pro | `oxy_html_converter_pro_loop_mapper` | Report as deferred product-boundary items; Core may preserve static markup only. |
| WooCommerce areas | Pro | `oxy_html_converter_pro_woocommerce_mapper` | Report product, cart, checkout, and account areas as Pro scope. |

## Strict Native Fallback Taxonomy

Every unsupported or non-native outcome must be one of these categories and must be visible in the report or import plan with `location`, `reason`, `severity`, `owner`, and `remediation`.

| Outcome | Allowed when | Strict native behavior |
| --- | --- | --- |
| Native mapping | The source structure has a tested Oxygen 6-native representation. | Allowed. |
| Safe substitution | The source can be represented safely without executable behavior, such as static visual form layout or sanitized text. | Allowed with report note when fidelity changes. |
| Reported unsupported item | Core has no safe native representation or the feature belongs to Pro/future scope. | Allowed only as a non-importing report entry or blocker. |
| Explicit opt-in code fallback | The user chooses a fidelity/unsafe path for raw HTML, CSS, or JavaScript. | Blocked unless strict native is off and the report records the risk. |
| Page fallback CSS | CSS must remain page-owned because no native selector/global route exists yet. | Blocking. |
| Global asset CSS | Fonts/icons/global support CSS are routed to global style storage with deterministic ownership. | Review unless executable or unsafe. |
| Page-scoped asset CSS | Utility safety CSS is stored as page-scoped plugin metadata with export/rollback ownership. | Review unless it creates visible code blocks. |

## Current Non-Goals

Core does not yet promise:

- perfect conversion of every arbitrary HTML document on the public web
- full migration of framework apps
- module bundler support
- SPA routing semantics
- React, Vue, or similar app-runtime parity
- complete external stylesheet ingestion and rule reconstruction
- WindPress-specific rendering guarantees in Core
- Web Components parity beyond safe preservation
- WooCommerce, dynamic data, loop, or inferred menu automation as default Core behavior
- functional forms unless an approved safe integration is selected

## Practical Reading

If a page is mostly marketing HTML, utility classes, inline styles, and lightweight scripts, it is inside the intended support envelope.

If a page depends on a complex external CSS architecture, framework runtime, or application state system, it is outside the current Core guarantee even if partial conversion works.
