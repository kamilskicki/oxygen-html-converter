# Oxygen HTML Converter - Architecture Analysis Report

## Executive Summary

- **Architecture**: Clean separation between parsing (HtmlParser), mapping (ElementMapper), and transformation (TreeBuilder) with a service-oriented design for specialized tasks (CSS, JS, interactions, icons)
- **Test Coverage**: ~75% coverage on core classes; strong unit tests for services but missing integration tests for the full conversion pipeline
- **Technical Debt**: Regex-based JavaScript parsing is fragile, tight coupling in TreeBuilder (god class), hardcoded CSS style mappings, and no input sanitization beyond basic WordPress nonce checks
- **v1.0 Blockers**: JavaScript transformation edge cases, missing error handling for malformed HTML, no CSRF protection on preview endpoint, and incomplete Tailwind-to-Oxygen property conversion

---

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           ENTRY POINTS                                  │
├─────────────────────────────────────────────────────────────────────────┤
│  ┌──────────────┐   ┌──────────────┐   ┌──────────────────────────────┐ │
│  │  Admin Page  │   │    Ajax      │   │  Builder Integration (JS)    │ │
│  │  (AdminPage) │   │   Handlers   │   │  (converter.js paste hook)   │ │
│  └──────┬───────┘   └──────┬───────┘   └──────────────┬───────────────┘ │
│         │                  │                          │                 │
│         └──────────────────┼──────────────────────────┘                 │
│                            ▼                                            │
│                   ┌──────────────────┐                                  │
│                   │   TreeBuilder    │                                  │
│                   │   (Orchestrator) │                                  │
│                   └────────┬─────────┘                                  │
└────────────────────────────┼────────────────────────────────────────────┘
                             │
        ┌────────────────────┼────────────────────┐
        ▼                    ▼                    ▼
┌───────────────┐    ┌───────────────┐    ┌───────────────┐
│ HtmlParser    │    │ ElementMapper │    │StyleExtractor │
│ (DOMDocument) │    │ (Tag mapping) │    │(CSS→Oxygen)   │
└───────────────┘    └───────────────┘    └───────────────┘
                             │
        ┌────────────────────┼────────────────────┐
        ▼                    ▼                    ▼
┌─────────────────────────────────────────────────────────────┐
│                      SERVICE LAYER                          │
├─────────────────────────────────────────────────────────────┤
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────────────┐ │
│  │TailwindDetector│ │GridDetector  │ │  JavaScriptTransformer│ │
│  │(Classify)    │ │(Grid props)  │ │  (Regex→Window.func) │ │
│  └──────────────┘ └──────────────┘ └──────────────────────┘ │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────────────┐ │
│  │IconDetector  │ │FrameworkDet. │ │  InteractionDetector │ │
│  │(Lib detect)  │ │(Alpine/HTMX) │ │  (Event→Interaction) │ │
│  └──────────────┘ └──────────────┘ └──────────────────────┘ │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────────────┐ │
│  │ClassStrategy │ │ComponentDet. │ │  EnvironmentService  │ │
│  │(WindPress/O2)│ │(Repeats)     │ │  (Plugin detection)  │ │
│  └──────────────┘ └──────────────┘ └──────────────────────┘ │
│  ┌──────────────┐ ┌──────────────┐                          │
│  │  CssParser   │ │ConversionRep.│                          │
│  │(CSS rules)   │ │ (Stats/warn) │                          │
│  └──────────────┘ └──────────────┘                          │
└─────────────────────────────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────┐
│                       OUTPUT                                │
│  { element: {...}, cssElement: {...}, stats: {...} }       │
└─────────────────────────────────────────────────────────────┘
```

---

## PHP Classes & Responsibilities

### Core Classes (6)

| Class | Responsibility | Lines |
|-------|---------------|-------|
| `Plugin` | Singleton plugin initialization, script enqueueing, Oxygen detection | ~80 |
| `Ajax` | WP AJAX endpoints (convert, preview, batch), permission checks | ~200 |
| `AdminPage` | Settings registration, admin UI rendering, menu registration | ~150 |
| `HtmlParser` | DOMDocument wrapper, HTML preprocessing, error collection | ~120 |
| `ElementMapper` | HTML tag → Oxygen type mapping, property building per type | ~400 |
| `TreeBuilder` | Main orchestrator, element tree construction, state management | ~600 |
| `StyleExtractor` | Inline CSS extraction, CSS→Oxygen property mapping | ~200 |

### Services (10)

| Service | Responsibility | Complexity |
|---------|---------------|------------|
| `TailwindDetector` | Regex-based Tailwind class identification | Low |
| `GridDetector` | Extract grid-cols-* and gap-* to Oxygen grid properties | Low |
| `CssParser` | Basic CSS rule parsing (selector + declarations) | Medium |
| `JavaScriptTransformer` | Transform JS functions to window.* assignments | **High** |
| `IconDetector` | Detect Lucide/FontAwesome/Bootstrap icons, create script elements | Medium |
| `FrameworkDetector` | Detect Alpine.js, HTMX, Stimulus attributes | Low |
| `InteractionDetector` | Convert onclick/onmouseenter to Oxygen Interactions | Medium |
| `ComponentDetector` | Detect repeated DOM structures for component suggestions | Low |
| `EnvironmentService` | Detect WindPress plugin, determine class handling mode | Low |
| `ClassStrategyService` | Route classes to WindPress preservation or native conversion | Medium |

### Report (1)

| Class | Responsibility |
|-------|---------------|
| `ConversionReport` | Track element counts, class counts, warnings (deduped) |

---

## Main Conversion Flow

```
1. HTML Input
   ↓
2. HtmlParser::parse()
   - Wrap fragments in <!DOCTYPE html> structure
   - Preprocess Alpine @ attributes → data-oxy-at-
   - Return DOMElement (body or document root)
   ↓
3. TreeBuilder::convert()
   a. Extract <style> tags → $extractedCss
   b. Parse CSS rules → $cssRules
   c. ComponentDetector::analyze() on body nodes
   d. For each body child: convertNode()
      ↓
      convertNode(DOMNode):
      - Skip if shouldSkipNode() (whitespace, comments, meta, noscript)
      - Handle <script> → JavaScript_Code (with JS transformation)
      - Handle <style> → CSS_Code
      - Handle <link> → HTML_Code
      - Get element type from ElementMapper
      - Extract content properties (href, src, text, etc.)
      - Extract inline styles → StyleExtractor::extractAndConvert()
      - Process classes → ClassStrategyService::processClasses()
      - Process ID → TreeBuilder::processId()
      - Process custom attributes → InteractionDetector::processCustomAttributes()
      - Framework detection → FrameworkDetector::detect()
      - Special handling (nav sticky, button centering, grid props)
      - Recursively process children if container
      - Apply CSS rules matching element ID
      - Return element array
   ↓
4. IconDetector::detectIconLibraries() + createIconLibraryElements()
   ↓
5. Assemble final output
   - Root element (or wrapper if multiple children)
   - CSS element (if extracted CSS)
   - Icon script elements
   - Stats from ConversionReport
```

---

## Critical Issues List

### HIGH Severity

| Issue | Location | Impact | Fix Complexity |
|-------|----------|--------|----------------|
| **Regex-based JS parsing** | JavaScriptTransformer | Fails on complex nested braces, template literals, comments | High - needs AST parser |
| **No CSRF on preview endpoint** | Ajax::handlePreview() | Potential replay attacks on preview | Low - add nonce check |
| **Missing input sanitization** | Ajax::handleConvert() | XSS via malicious HTML/JS injection | Medium - use wp_kses or DOM validation |
| **TreeBuilder is a God Class** | TreeBuilder | 600+ lines, too many responsibilities | Medium - extract node converters |
| **No error handling for malformed HTML** | HtmlParser::parse() | Silent failures, empty output | Low - validate and return detailed errors |

### MEDIUM Severity

| Issue | Location | Impact | Fix Complexity |
|-------|----------|--------|----------------|
| **Hardcoded CSS property map** | StyleExtractor::STYLE_MAP | Can't easily extend style support | Low - make configurable or use CSS parser |
| **Incomplete Tailwind conversion** | ClassStrategyService | Tailwind classes preserved, not converted to properties | Medium - build comprehensive mapper |
| **No caching** | TreeBuilder | Re-parses same HTML repeatedly | Low - add transient caching |
| **Missing validation on element properties** | ElementMapper | Could generate invalid Oxygen JSON | Medium - add schema validation |
| **Tight coupling to WordPress functions** | Multiple | Hard to test outside WP | Low - inject WP wrapper or mock |

### LOW Severity

| Issue | Location | Impact | Fix Complexity |
|-------|----------|--------|----------------|
| **Inconsistent naming** | IconDetector | Uses `HtmlCode` not `HTML_Code` (case mismatch) | Low - standardize |
| **Polyfills loaded unconditionally** | polyfills.php | Unnecessary on PHP 8.0+ | Low - version check |
| **Magic numbers** | TreeBuilder | Hardcoded padding values (80px), node ID starting at 1 | Low - make constants |
| **Missing return type hints** | Some methods | Reduced IDE support | Low - add type hints |

---

## v1.0 Blocker Checklist

### Must Fix Before Release

- [ ] **Security: Add nonce verification to handlePreview()**
  - Currently only checks permissions, no CSRF protection
  - Risk: Replay attacks (low impact, but should fix)

- [ ] **Security: Sanitize HTML input**
  - Use `wp_kses()` or DOM validation to strip dangerous tags
  - Prevent XSS via `<script>alert(1)</script>` in input

- [ ] **Robustness: Handle malformed HTML gracefully**
  - Currently returns `null` from parse(), should return detailed error
  - Add validation for common issues (unclosed tags, encoding issues)

- [ ] **JavaScript: Fix regex parsing edge cases**
  - Template literals with `${}` containing braces break brace counting
  - Comments containing `}` break brace counting
  - ES6 class methods extraction incomplete

- [ ] **Tailwind: Implement basic property conversion**
  - Currently warns "not yet implemented" in native mode
  - Map common utilities: flex, grid, padding, margin, text colors

- [ ] **Bug: Fix element type casing**
  - IconDetector creates `OxygenElements\HtmlCode` but should be `HTML_Code`
  - Check all element type strings for consistency

### Should Fix Before Release

- [ ] Add comprehensive error logging (WP_DEBUG_LOG integration)
- [ ] Add rate limiting on AJAX endpoints
- [ ] Validate Oxygen JSON output against schema before returning
- [ ] Add maximum input size limit (prevent DoS via huge HTML)
- [ ] Add timeout handling for long conversions

### Nice to Have

- [ ] Extract TreeBuilder node conversion to strategy classes
- [ ] Add PHP 8.0+ union types where applicable
- [ ] Add static analysis (PHPStan level 5+)

---

## Test Coverage Analysis

### Current Test Files (17)

| Test File | Coverage | Notes |
|-----------|----------|-------|
| HtmlParserTest | 90% | Good coverage of parsing, UTF-8, error handling |
| ElementMapperTest | 85% | Tag mapping, property building, edge cases |
| TreeBuilderTest | 70% | Integration-level tests, complex templates |
| StyleExtractorTest | 80% | Style extraction, shorthand parsing, colors |
| TailwindDetectorTest | 95% | Comprehensive pattern matching tests |
| CssParserTest | 75% | Basic rule parsing, comments, selectors |
| FrameworkDetectorTest | 85% | Alpine, HTMX, Stimulus detection |
| InteractionDetectorTest | 80% | Event handler conversion, multiple functions |
| JavaScriptTransformerTest | 75% | Various function patterns, edge cases |
| ComponentDetectorTest | 70% | Repeated structure detection |
| EnvironmentServiceTest | 60% | Mode detection, mocking WordPress functions |
| IconDetectorTest | 85% | Library detection, element creation |
| ClassStrategyServiceTest | 80% | WindPress vs native mode |
| ConversionReportTest | 90% | Stats tracking, deduplication |
| NexusTemplateTest | 75% | Full template integration test |

### Missing Tests

| What | Priority | Why |
|------|----------|-----|
| GridDetectorTest | HIGH | No dedicated test file exists |
| Ajax endpoint integration tests | HIGH | No tests for actual AJAX handlers |
| AdminPage rendering tests | MEDIUM | UI output not tested |
| Plugin initialization tests | MEDIUM | Activation, dependency checks |
| Error handling paths | HIGH | Exception paths not covered |
| Malformed HTML edge cases | MEDIUM | Invalid input handling |
| Performance/benchmark tests | LOW | No regression testing |

### Coverage Estimate

- **Core classes**: ~80% (poor: TreeBuilder error paths)
- **Services**: ~75% (missing: GridDetector, some edge cases)
- **AJAX/Integration**: ~40% (only unit tests, no integration)
- **Overall**: ~70%

---

## Code Smells & Anti-Patterns

### 1. God Class - TreeBuilder
- **Location**: `src/TreeBuilder.php` (600+ lines)
- **Smell**: Handles too many responsibilities - orchestration, node conversion, CSS extraction, ID processing, special case handling
- **Fix**: Extract `NodeConverter` strategy classes for each element type

### 2. Primitive Obsession
- **Location**: `ElementMapper::TAG_MAP`, `StyleExtractor::STYLE_MAP`
- **Smell**: Arrays of strings instead of value objects
- **Fix**: Create `ElementType` and `CssProperty` value objects

### 3. Tight Coupling to WordPress
- **Location**: Multiple services use `get_option()`, `wp_create_nonce()`, `is_plugin_active()` directly
- **Smell**: Hard to unit test, can't run outside WordPress
- **Fix**: Inject `WpEnvironment` interface, use dependency injection container

### 4. Mutable State
- **Location**: `TreeBuilder` uses `$this->nodeIdCounter++`, `$this->extractedCss`
- **Smell**: Side effects make testing harder, not thread-safe
- **Fix**: Pass state through parameters or use immutable objects

### 5. Commented Code
- **Location**: `ElementMapper` has commented Header mapping, Rich Text handling
- **Smell**: Dead code reduces readability
- **Fix**: Remove or move to git history

### 6. Magic Strings
- **Location**: Element type strings (`'OxygenElements\Container'`), attribute names
- **Smell**: Typos cause bugs, hard to refactor
- **Fix**: Use constants: `ElementType::CONTAINER`

---

## Performance Considerations

| Bottleneck | Impact | Mitigation |
|------------|--------|------------|
| DOMDocument parsing large HTML | High | Add size limit, consider streaming |
| Regex-based JS parsing | Medium | Cache parsed results, consider AST library |
| Recursive tree traversal | Low | Current implementation is O(n), acceptable |
| No output caching | Medium | Add transients for repeated conversions |
| Multiple service instantiations | Low | Services created per conversion, could use DI |

---

## Recommendations

### Immediate (v1.0)
1. Fix security issues (CSRF, sanitization)
2. Add input validation and error handling
3. Fix JavaScript regex edge cases (template literals)
4. Implement basic Tailwind→property conversion

### Short-term (v1.1)
1. Refactor TreeBuilder - extract node converters
2. Add proper caching layer
3. Complete test coverage for missing paths
4. Add static analysis tooling

### Long-term (v2.0)
1. Replace regex JS parsing with proper AST parser
2. Support external CSS fetching and processing
3. Component/partial detection and suggestions
4. Batch processing for multiple files

---

*Report generated: 2026-02-01*
*Analyzed version: 1.0.0-dev*
*Lines of PHP code: ~3,500*
*Test files: 17*
*Classes: 20 (6 core + 10 services + 4 support)*
