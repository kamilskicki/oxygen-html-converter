# Changelog

All notable changes to this project will be documented in this file.

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
- AJAX input validation (10 MB HTML limit, 100-item batch limit)
- Removed debug `error_log()` calls

## [0.1.0] — 2024-12-01

### Added
- Initial release
- HTML → Oxygen Builder 6 element conversion
- Inline style extraction and mapping
- Class and ID preservation
- Event handler → Oxygen Interactions conversion
- JavaScript function transformation
- Tailwind CSS class detection
- Framework detection (Alpine.js, Vue, React, HTMX)
- Admin page (Tools → Oxygen HTML Converter)
- Builder integration (Ctrl+V paste, Ctrl+Shift+H import modal)
