# Release Notes: 0.9.0-beta

Status: Release candidate  
Date: 2026-07-10

## Summary

`0.9.0-beta` is the release line focused on one standard: supported HTML imported by Core should remain editable as a native Oxygen document, with any converter runtime dependencies disclosed before removal.

This is still a beta release. It aims to be strong and honest inside the supported scope, not to promise perfect conversion of every arbitrary frontend stack.

## Highlights

### Builder editability

- Added Builder-safe Oxygen document metadata for saved/imported document trees
- Eliminated a class of `Validation Error` / `IO-TS decoding failed` issues caused by incomplete document tree serialization
- Exposed builder-safe document payloads through AJAX conversion responses for save/import automation paths

### Visual and behavior parity

- Improved Tailwind utility mapping and safe fallback CSS generation
- Added gradient text fallbacks for Tailwind-like markup
- Converted or safely finalized JS-controlled reveal states so Safe Mode does not leave content hidden
- Improved mapping for inline links, reveal-animation classes, selectors, responsive styles, and unsupported reporting

### Safe Mode and boundaries

- Documented Safe Mode/no-code as the Core default for supported structures
- Reported unsupported forms, dynamic data, WooCommerce, advanced component patterns, and Pro/future scope instead of guessing unsafe behavior
- Kept Tailwind runtime preservation, WindPress class mode, Tailwind config parsing, and WindPress cache reset outside the Core release promise

### Reliability

- Expanded tests around AJAX behavior and Oxygen document serialization
- Kept PHP and JS test gates green while tightening the Builder document contract
- Added stable fixture audit, live Builder/browser smoke, visual smoke, release hygiene checks, and failure artifacts

## Beta Channel

WordPress.org requires the `Stable tag` value to contain only numbers and periods. This beta will therefore be promoted from the numeric `0.9.0` stable tag while the plugin header, runtime constant, changelog, and release notes continue to identify the build as `0.9.0-beta`. The numeric directory tag does not make this a general-availability release.

## Supported Scope

`0.9.0-beta` is aimed at:

- single-page marketing and landing-page HTML
- deterministic static site-kit manifests
- Oxygen templates, headers, footers, pages, parts, reusable blocks/components, selectors, variables, homepage assignment, and menu records from explicit manifests
- inline styles
- utility-first CSS, especially Tailwind-like markup
- common safe interactions such as static toggles, anchor navigation, reveal-on-scroll final states/native animation, and counters where supported

See [SUPPORTED_SCOPE.md](SUPPORTED_SCOPE.md) and [DOD-0.9.0-BETA.md](DOD-0.9.0-BETA.md) for the exact boundary.

## Known Limits

- external stylesheet ecosystems are not yet a universal guarantee
- complex JS app runtimes remain outside the Core promise
- framework app migration is still partial at best
- functional forms need a verified native form element or approved plugin integration
- dynamic data, loops, WooCommerce, inferred CMS mappings, and WindPress runtime workflows are Pro/future scope
- converter-owned page and fallback CSS stored in `_oxy_html_converter_page_styles` is emitted only while the plugin is active; migrate that CSS before deactivation or uninstall when an imported page depends on it
- the Final Independent Publication Audit in `PRD/09-publication-readiness-scorecard.md` is the current authority for publication readiness

## Upgrade Notes

- No Pro add-on is required for Core import/editability guarantees
- If you have custom automation around AJAX conversion responses, a builder-safe `documentTree` and `documentJson` payload is now available in addition to the existing `element` and `json` fields
- No database or option migration runs when upgrading from `0.8.0-beta`; existing imports and compatible settings are retained and are not rewritten
- See [UPGRADE.md](UPGRADE.md) for the expected direct-upgrade behavior, runtime requirements, and post-upgrade checks

## Recommended Validation Before Tagging

- run `composer install`
- run `vendor\bin\phpunit`
- run `vendor\bin\phpstan analyse --configuration=phpstan.neon.dist`
- run `vendor\bin\phpcs --runtime-set ignore_warnings_on_exit 1 --standard=phpcs.xml.dist`
- run `npm run test:js`
- run `npm run test:fixtures:local`
- run `npm run sync:docker`
- run `npm run test:live`
- run `npm run test:visual`
- run `npm run check`
- run `php scripts/release_verify.php --with-live`
