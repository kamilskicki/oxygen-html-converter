=== Oxygen HTML Converter ===
Contributors: kamilskicki
Tags: oxygen builder, html import, page builder, conversion, no code
Requires at least: 6.5
Tested up to: 6.5
Requires PHP: 8.2
Stable tag: 0.9.0-beta
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert HTML pages into native, editable Oxygen Builder 6 documents with Safe Mode and supported-scope reporting.

== Description ==

Oxygen HTML Converter helps Oxygen Builder 6 users turn pasted HTML into native Oxygen document content that can be edited, saved, and reopened in the builder.

The Core plugin focuses on supported marketing and landing-page HTML: inline styles, IDs, classes, attributes, common utility-first markup, and safe interaction patterns. Its goal is to produce editable Oxygen output without corrupting the Oxygen document tree or requiring hidden runtime dependencies.

= Key features =

* Convert full HTML fragments and pages into Oxygen Builder 6 document data.
* Preserve IDs, classes, data attributes, and supported inline styles.
* Use Safe Mode by default for supported native/no-code output.
* Report unsupported or deferred items instead of silently guessing unsafe behavior.
* Import explicit site-kit manifests for supported pages, templates, headers, footers, homepage assignments, and menu records.
* Expose documented hooks for add-ons and Pro integrations.

= Supported scope =

The current Core release is intended for:

* Single-page marketing and landing-page HTML.
* Static site-kit manifests with explicit records.
* Oxygen templates, headers, footers, pages, parts, reusable blocks/components, selectors, variables, homepage assignment, and menu records from explicit manifests.
* Inline styles.
* Utility-first CSS markup, especially Tailwind-like classes.
* Common safe interactions such as static toggles, anchor navigation, reveal-on-scroll final states/native animation, and supported counters.

Core does not promise perfect conversion for every arbitrary frontend stack. Complex JavaScript applications, dynamic data, loops, WooCommerce areas, inferred CMS mappings, advanced component patterns, full Tailwind runtime preservation, WindPress class mode, Tailwind config parsing, and WindPress cache reset are Pro, future, or unsupported scope unless a verified extension maps them.

== Installation ==

1. Upload the plugin ZIP through Plugins > Add New > Upload Plugin in WordPress admin.
2. Activate Oxygen HTML Converter.
3. Make sure Oxygen Builder 6 is installed and active.
4. Open Tools > Oxygen HTML Converter, paste HTML, preview the conversion audit, and convert or import the supported output.

== Frequently Asked Questions ==

= Does this require Oxygen Builder? =

Yes. Oxygen Builder 6 must be installed and active. The plugin is not a standalone page builder.

= Does Core require a Pro add-on? =

No. Core owns the public import, builder-safe serialization, editability, and baseline parity guarantees for supported inputs. Pro or other add-ons may extend Core through hooks.

= What happens in Safe Mode? =

Safe Mode is the default supported path. It targets native Oxygen output and avoids unsafe executable fallbacks unless an explicit unsafe opt-in path is available and allowed.

= Will every HTML page convert perfectly? =

No. Core is strongest for static marketing and landing-page HTML. Framework apps, complex external CSS systems, dynamic CMS bindings, WooCommerce, loops, inferred menus, and advanced component automation are reported as unsupported, Pro, or future scope when Core cannot safely map them.

= What data is removed on uninstall? =

Uninstall removes plugin-owned options under the `oxy_html_converter_*` prefix. It intentionally keeps imported WordPress posts, Oxygen document data, Oxygen selectors, Oxygen variables, global settings, and Breakdance/Oxygen-owned options so imported content survives plugin removal.

= Can developers extend conversion behavior? =

Yes. Core exposes hooks including `oxy_html_converter_convert_options`, `oxy_html_converter_tree_builder`, `oxy_html_converter_conversion_result`, `oxy_html_converter_convert_response`, `oxy_html_converter_preview_response`, `oxy_html_converter_required_capability`, and `oxy_html_converter_feature_flags`.

== Screenshots ==

1. Admin converter screen for pasting HTML, previewing the conversion audit, and generating Oxygen output.
2. Conversion audit showing supported, unsupported, deferred, and fallback items before import.
3. Builder import modal for inserting converted HTML into an Oxygen document.
4. Imported page reopened in Oxygen Builder with editable native elements.

== Changelog ==

= 0.9.0-beta =

* Added Core extension hooks for add-ons and Pro integrations.
* Added Open Core documentation, Pro starter scaffold, public security policy, supported scope documentation, draft release notes, and release checklist updates.
* Added API contract version constant `OXY_HTML_CONVERTER_API_VERSION`.
* Added Builder-safe document tree serialization and AJAX response payloads for Oxygen documents.
* Added stable fixture index, local fixture audit, live Builder/browser smoke gate, visual review gate, release hygiene checks, and failure artifacts.
* Changed Builder script localization to support feature flag/filter injection.
* Changed AJAX responses to pass through extension filters.
* Changed Core/API documentation to list exported extension hooks.
* Changed roadmap version naming to align with the current plugin version.
* Changed Safe Mode documentation to make supported native/no-code behavior the Core default.
* Changed unsupported forms, dynamic data, WooCommerce, advanced component patterns, and Pro/future scope to be reported instead of guessed.
* Changed Tailwind-like utilities to be treated as Core source hints or safe fallback CSS, with Tailwind runtime preservation, WindPress class mode, config parsing, and cache reset outside Core scope.
* Changed release checklist and definition-of-done gates to use the current PHPUnit, PHPStan, PHPCS, JavaScript, fixture, Docker, live smoke, visual smoke, aggregate, and release verification commands.
* Fixed Safe Mode imports so stripped runtime JavaScript does not leave JS-controlled reveal content invisible.
* Fixed stale Builder troubleshooting guidance by moving it out of current skill docs.
* Fixed fixture live-smoke metadata to reflect current M8 evidence.
* Fixed Maximus site-kit proof documents to be examples instead of generic Core acceptance rules.

= 0.8.0-beta =

* Added animation detection, heuristics, element registry, output validation, component detection, icon detection, environment detection, CSS grid detection, CSS parser, class strategy, conversion report system, PHP 7.4 polyfills, and contributing guidance.
* Changed source layout into Services, Report, and Validation directories.
* Changed Oxygen 6 detection to support RC1 and Breakdance-based builds.
* Changed release status to beta for community feedback before v1.0.
* Fixed PHP 7.4 compatibility.
* Fixed AJAX input validation limits for single conversion and batch conversion.
* Fixed routine debug logging noise from normal conversion flow.

= 0.1.0 =

* Added initial HTML to Oxygen Builder 6 element conversion.
* Added inline style extraction and mapping.
* Added class and ID preservation.
* Added event handler to Oxygen Interactions conversion.
* Added JavaScript function transformation.
* Added Tailwind CSS class detection.
* Added framework detection for Alpine.js, HTMX, and Stimulus.js.
* Added admin page under Tools > Oxygen HTML Converter.
* Added Builder integration for paste and import modal workflows.

== Upgrade Notice ==

= 0.9.0-beta =

Beta release focused on builder-safe Oxygen document output, Safe Mode scope clarity, extension hooks, and stronger release gates. Validate imports on staging before using on production content.

= 0.8.0-beta =

Beta release with a restructured conversion pipeline, additional detectors, validation, and AJAX limits.
