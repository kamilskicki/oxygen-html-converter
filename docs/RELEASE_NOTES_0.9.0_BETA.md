# Release Notes Draft: 0.9.0-beta

Status: Draft  
Date: 2026-07-06

## Summary

`0.9.0-beta` is the release line focused on one standard: supported HTML imported by Core should remain editable as a native Oxygen document without hidden unsafe runtime dependencies.

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
- M8-06 final release evidence is still the authority for tagging readiness

## Upgrade Notes

- No Pro add-on is required for Core import/editability guarantees
- If you have custom automation around AJAX conversion responses, a builder-safe `documentTree` and `documentJson` payload is now available in addition to the existing `element` and `json` fields

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
