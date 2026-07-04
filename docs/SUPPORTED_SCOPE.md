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
| WindPress-specific rendering and cache operations | Pro | Core may expose feature flags and hooks, but generic imports must not depend on WindPress. |
| site-kit automation | Pro | Multi-page manifests, site assembly, homepage assignment, and site-wide orchestration belong behind extension points until a Core milestone explicitly implements them. |
| Advanced components | Future | Core may create verified reusable patterns when tests prove editable properties; variants, repeated regions, component-scoped CSS, and advanced property models are deferred. |
| forms | Future | Core must either use an approved safe strategy or report forms as unsupported; raw executable form fallback is unsafe unless explicitly selected. |
| dynamic data | Pro | Dynamic bindings, loops, archive logic, and CMS-aware mapping are outside public Core until explicitly implemented. |
| WooCommerce | Pro | Product templates, carts, checkout, and WooCommerce dynamic areas are premium/future integration scope. |
| menus | Future | Static nav markup may be imported as editable containers/links; WordPress menu creation and assignment are deferred. |
| unsafe executable preservation | Unsupported by default | Scripts, event handlers, dangerous URLs, and raw embeds require Safe Mode policy and explicit opt-in paths. |

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
- WooCommerce, dynamic data, loop, archive, or menu automation as default Core behavior
- functional forms unless an approved safe integration is selected

## Practical Reading

If a page is mostly marketing HTML, utility classes, inline styles, and lightweight scripts, it is inside the intended support envelope.

If a page depends on a complex external CSS architecture, framework runtime, or application state system, it is outside the current Core guarantee even if partial conversion works.
