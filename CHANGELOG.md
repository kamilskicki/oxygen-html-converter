# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added
- Core extension hooks for add-ons and Pro integrations.
- Open Core documentation in `docs/OPEN_CORE.md`.
- Pro starter scaffold in `scaffolds/oxygen-html-converter-pro`.
- API contract version constant `OXY_HTML_CONVERTER_API_VERSION`.
- Repository `LICENSE` file (GPL-2.0-or-later).
- GitHub issue templates and release checklist.
- Builder-safe document tree serializer for Oxygen documents.
- Public `SECURITY.md` policy.
- Supported scope documentation in `docs/SUPPORTED_SCOPE.md`.
- Draft release notes for `0.9.0-beta`.
- Stable fixture index and local fixture audit covering supported, unsupported, fallback, site-kit, and release-gate fixtures.
- Live Builder/browser smoke gate for Docker-backed Oxygen imports, Builder save/reopen, editability signals, selector/template/component checks, and failure artifacts.
- Visual review gate with targeted frontend smoke checks and capture-failure artifacts.
- Release hygiene checks for docs, fixture metadata, package scripts, and release readiness.

### Changed
- Builder script localization now supports feature flag/filter injection.
- AJAX responses now pass through extension filters.
- Core/API documentation now lists all exported extension hooks.
- Roadmap version naming aligned to current plugin version (`v0.8.0-beta`).
- AJAX convert and batch responses now expose builder-safe `documentTree` and `documentJson` payloads.
- Core Safe Mode is now the documented default: supported structures target native/no-code output, while executable JavaScript fallback requires explicit unsafe opt-in.
- Unsupported forms, dynamic data, WooCommerce, advanced component patterns, and Pro/future scope are reported instead of silently guessed.
- Tailwind-like utilities are treated as Core source hints or safe fallback CSS; Tailwind runtime preservation, WindPress class mode, config parsing, and cache reset are Pro/advanced scope.
- Release checklist and DOD now use current M8 commands: PHPUnit, PHPStan, PHPCS, JS, fixture audit, Docker sync, live smoke, visual smoke, `npm run check`, and release verify.

### Fixed
- Prevented Safe Mode imports from leaving JS-controlled hidden reveal content invisible after stripped runtime code.
- Removed stale Builder 500/bootstrap guidance from current skill docs and moved it to historical troubleshooting.
- Updated fixture live-smoke metadata from pending M8-03 status to current passed M8-03 evidence.
- Clarified Maximus site-kit proof documents as examples instead of generic Core acceptance rules.

## [0.8.0-beta] — 2025-02-11

### Added
- Animation detection service (`AnimationDetector`)
- Heuristics service for smarter element mapping (`HeuristicsService`)
- Element types registry (`ElementTypes`)
- Output validation (`OutputValidator`)
- Component detection service (`ComponentDetector`)
- Icon detection service (`IconDetector`)
- Environment service for host CMS/framework detection (`EnvironmentService`)
- CSS Grid detection (`GridDetector`)
- CSS parser service (`CssParser`)
- Class strategy service for Tailwind/utility-first handling (`ClassStrategyService`)
- Conversion report system (`ConversionReport`)
- PHP 7.4 polyfills for broad hosting compatibility
- Contributing guidelines in README

### Changed
- Restructured source into `Services/`, `Report/`, and `Validation/` directories
- Updated Oxygen 6 detection to support RC1 and Breakdance-based builds
- Marked as beta — seeking community feedback before v1.0

### Fixed
- PHP 7.4 compatibility (union types, `str_starts_with` polyfill)
- AJAX input validation limits (single conversion: 1 MB; batch: 50 items max, 500 KB per item, 5 MB total)
- Removed routine debug `error_log()` noise from normal conversion flow

## [0.1.0] — 2024-12-01

### Added
- Initial release
- HTML → Oxygen Builder 6 element conversion
- Inline style extraction and mapping
- Class and ID preservation
- Event handler → Oxygen Interactions conversion
- JavaScript function transformation
- Tailwind CSS class detection
- Framework detection (Alpine.js, HTMX, Stimulus.js)
- Admin page (Tools → Oxygen HTML Converter)
- Builder integration (Ctrl+V paste, Ctrl+Shift+H import modal)
