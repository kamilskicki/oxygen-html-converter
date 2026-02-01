# KBAI - Knowledge Base for AI Agents

> **Last Updated:** January 20, 2026  
> **Plugin Version:** 1.0.0 (Technical Debt resolved)  
> **PHP Target:** >=7.4 (Production Ready)

## Purpose

This knowledge base provides AI agents with comprehensive, context-efficient documentation for developing, debugging, and enhancing the `oxygen-html-converter` plugin.

## Quick Navigation

| Need To... | Go To |
|------------|-------|
| Understand the codebase structure | [01-architecture/overview.md](01-architecture/overview.md) |
| Look up Oxygen JSON format | [02-oxygen-reference/elements.md](02-oxygen-reference/elements.md) |
| Work on a specific service | [03-services/INDEX.md](03-services/INDEX.md) |
| Write or fix tests | [04-testing/guide.md](04-testing/guide.md) |
| Fix bugs or issues | [05-issues/INDEX.md](05-issues/INDEX.md) |
| Work with WindPress | [06-windpress/integration.md](06-windpress/integration.md) |
| Follow development workflows | [07-workflows/INDEX.md](07-workflows/INDEX.md) |

## Critical Information

### Resolved Critical Bugs

1. **PHP 7.4 Incompatibility** - ✅ FIXED: Removed `str_starts_with()` and union types.
2. **Wrong Builder Detection** - ✅ FIXED: `Plugin.php` updated to detect Oxygen 6 and Breakdance accurately.
3. **Wrong WindPress Detection** - ✅ FIXED: `EnvironmentService.php` updated with correct namespace and paths.
4. **Debug Logging in Production** - ✅ FIXED: `error_log()` calls removed.

See [05-issues/INDEX.md](05-issues/INDEX.md) for details on other minor issues.

### Architecture Overview

```
oxygen-html-converter/
├── src/
│   ├── Plugin.php              # Entry point, singleton
│   ├── TreeBuilder.php         # Main orchestrator (needs refactoring)
│   ├── HtmlParser.php          # DOM parsing
│   ├── ElementMapper.php       # HTML→Oxygen type mapping
│   ├── StyleExtractor.php      # Inline CSS extraction
│   ├── AdminPage.php           # WordPress admin UI
│   ├── Ajax.php                # AJAX endpoints
│   ├── Services/               # Specialized services
│   │   ├── CssParser.php
│   │   ├── JavaScriptTransformer.php
│   │   ├── InteractionDetector.php
│   │   ├── FrameworkDetector.php
│   │   ├── TailwindDetector.php
│   │   ├── ClassStrategyService.php
│   │   ├── EnvironmentService.php
│   │   ├── IconDetector.php
│   │   └── ComponentDetector.php
│   └── Report/
│       └── ConversionReport.php
├── tests/
│   ├── TestCase.php            # Base test class
│   └── Unit/                   # Unit tests
├── assets/
│   ├── js/converter.js         # Oxygen Builder integration
│   ├── js/admin.js             # Admin page functionality
│   └── css/admin.css           # Admin styles
└── KBAI/                       # This knowledge base
```

### Key Concepts

1. **HTML→Oxygen Conversion Flow:**
   ```
   HTML String → HtmlParser → DOMDocument → TreeBuilder.convertNode() → Oxygen JSON
   ```

2. **Oxygen JSON Structure:**
   ```json
   {
     "id": "el-123",
     "data": {
       "type": "OxygenElements\\Container",
       "properties": {
         "content": {},
         "design": {},
         "settings": { "advanced": { "classes": [], "id": "" } }
       }
     },
     "children": []
   }
   ```

3. **Class Handling Modes:**
   - `windpress`: Preserve all classes (WindPress generates CSS)
   - `native`: Map Tailwind to Oxygen properties (not fully implemented)
   - `auto`: Detect WindPress and choose mode

## File Loading Strategy

To conserve context tokens, load files progressively:

1. **Start with INDEX.md files** - Get overview before diving deep
2. **Load specific files** - Only load what you need for the current task
3. **Reference cross-links** - Follow links to related documentation

## Common Tasks Quick Reference

| Task | Load These Files |
|------|------------------|
| Fix a bug | `05-issues/critical-bugs.md`, relevant service file |
| Add element type | `02-oxygen-reference/elements.md`, `03-services/element-mapper.md` |
| Add event handler | `02-oxygen-reference/interactions.md`, `03-services/interaction-detector.md` |
| Add framework support | `03-services/framework-detector.md`, `07-workflows/add-framework.md` |
| Write tests | `04-testing/guide.md`, `04-testing/patterns.md` |
| Debug conversion | `07-workflows/debugging.md`, `01-architecture/data-flow.md` |
