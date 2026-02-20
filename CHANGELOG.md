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

### Changed
- Builder script localization now supports feature flag/filter injection.
- AJAX responses now pass through extension filters.
- Core/API documentation now lists all exported extension hooks.
- Roadmap version naming aligned to current plugin version (`v0.8.0-beta`).

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
