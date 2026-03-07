# Release Notes Draft: 0.9.0-beta

Status: Draft  
Date: 2026-03-07

## Summary

`0.9.0-beta` is the release line focused on one standard: HTML imported by Core should remain editable as a native Oxygen document while getting materially closer visual and behavioral parity on supported page types.

This is still a beta release. It aims to be strong and honest inside the supported scope, not to promise perfect conversion of every arbitrary frontend stack.

## Highlights

### Builder editability

- Added Builder-safe Oxygen document metadata for saved/imported document trees
- Eliminated a class of `Validation Error` / `IO-TS decoding failed` issues caused by incomplete document tree serialization
- Exposed builder-safe document payloads through AJAX conversion responses for save/import automation paths

### Visual and behavior parity

- Improved Tailwind utility fallback generation
- Added gradient text fallbacks for Tailwind-like markup
- Preserved required head scripts and frontend assets used by imported pages
- Fixed JavaScript transformation regressions around IIFEs and local closure scope
- Improved mapping for inline links and reveal-animation classes

### Reliability

- Expanded tests around AJAX behavior and Oxygen document serialization
- Kept PHP and JS test gates green while tightening the Builder document contract

## Supported Scope

`0.9.0-beta` is aimed at:

- single-page marketing and landing-page HTML
- inline styles
- utility-first CSS, especially Tailwind-like markup
- WindPress-assisted Tailwind rendering
- common interactions such as toggles, anchor scroll, reveal-on-scroll, counters, and simple inline handlers

See [SUPPORTED_SCOPE.md](SUPPORTED_SCOPE.md) and [DOD-0.9.0-BETA.md](DOD-0.9.0-BETA.md) for the exact boundary.

## Known Limits

- external stylesheet ecosystems are not yet a universal guarantee
- complex JS app runtimes remain outside the Core promise
- framework app migration is still partial at best
- fixture-by-fixture visual closure is still part of the beta hardening process

## Upgrade Notes

- No Pro add-on is required for Core import/editability guarantees
- If you have custom automation around AJAX conversion responses, a builder-safe `documentTree` and `documentJson` payload is now available in addition to the existing `element` and `json` fields

## Recommended Validation Before Tagging

- run `composer test`
- run `node tests/js/run-tests.cjs`
- verify admin converter flow
- verify Builder paste/import flow
- verify open -> edit -> save -> reopen on maintained fixtures
