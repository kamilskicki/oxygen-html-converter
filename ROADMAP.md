# Oxygen HTML Converter - Development Roadmap

## Vision

Transform the Oxygen HTML Converter into a robust, widely-adopted tool that reliably converts HTML templates to native Oxygen Builder 6 elements, handling the majority of real-world use cases while gracefully degrading for edge cases.

---

## Current Status (v1.0)

### What Works
- Basic HTML → Oxygen element mapping
- CSS class preservation (`settings.advanced.classes`)
- HTML ID preservation (`settings.advanced.id`)
- Custom attributes (data-*, aria-*) preservation
- Inline style extraction and conversion
- Event handlers (onclick, onmouseenter, etc.) → Oxygen Interactions
- JavaScript function transformation (window.X assignment)
- Argument passing via data attributes for interaction functions

### Known Limitations
- Regex-based JavaScript parsing (fragile)
- Limited event handler patterns supported
- No framework detection (Alpine.js, Vue, HTMX)
- No conversion reporting/warnings
- Complex inline handlers not supported

---

## Development Tiers

### Tier 1: Solid Foundation (Target: 80% of templates)
**Goal**: Bulletproof conversion for static HTML and simple interactive templates

#### 1.1 Event Handler Improvements
- [ ] Support all standard event types:
  - `onclick`, `ondblclick`
  - `onmouseenter`, `onmouseleave`, `onmouseover`, `onmouseout`
  - `onfocus`, `onblur`
  - `onchange`, `oninput`
  - `onsubmit`
  - `onkeydown`, `onkeyup`, `onkeypress`
  - `onscroll`
  - `ontouchstart`, `ontouchend`
- [ ] Handle multiple function calls: `onclick="func1(); func2()"`
- [ ] Handle inline returns: `onclick="return false"`
- [ ] Handle `this` references: `onclick="func(this)"`

#### 1.2 JavaScript Transformation Robustness
- [ ] Replace regex with proper JavaScript AST parser
  - Research: Peast (PHP JS parser) or external service
  - Alternative: Use Node.js subprocess for parsing
- [ ] Handle arrow functions at module level
- [ ] Handle async functions
- [ ] Handle ES6 class methods
- [ ] Handle IIFEs (Immediately Invoked Function Expressions)
- [ ] Preserve non-function code (event listeners, initialization)

#### 1.3 Argument Handling
- [ ] Support multiple arguments: `func(a, b, c)`
- [ ] Support string arguments: `func('string')`
- [ ] Support object literals: `func({key: 'value'})`
- [ ] Support `this` keyword: `func(this.value)`
- [ ] Support expressions: `func(index + 1)`

#### 1.4 CSS/Style Improvements
- [ ] Extract and convert more inline style properties
- [ ] Handle CSS custom properties (variables)
- [ ] Preserve Tailwind arbitrary values: `w-[200px]`
- [ ] Handle responsive prefixes: `md:flex lg:hidden`
- [ ] Handle state prefixes: `hover:bg-red-500 focus:ring`

#### 1.5 Element Mapping Expansion
- [ ] `<button>` → proper handling (Container vs ContainerLink)
- [ ] `<details>/<summary>` → native or HtmlCode
- [ ] `<dialog>` → popup/modal handling
- [ ] `<template>` → skip or preserve
- [ ] `<slot>` → handle web components gracefully

---

### Tier 2: Enhanced Intelligence (Target: Additional 15%)
**Goal**: Detect and convert common framework patterns

#### 2.1 Framework Detection
- [ ] Alpine.js detection and conversion
  - `x-data`, `x-on:click`, `x-show`, `x-if`, `x-for`
  - Convert to Oxygen interactions where possible
  - Preserve as attributes where not
- [ ] HTMX detection
  - `hx-get`, `hx-post`, `hx-trigger`, `hx-target`
  - Preserve attributes, ensure HTMX script included
- [ ] Stimulus.js detection
  - `data-controller`, `data-action`, `data-target`
- [ ] Add framework warnings to conversion report

#### 2.2 Conversion Reporting System
- [ ] Create `ConversionReport` class
- [ ] Track for each element:
  - Conversion status (success, partial, failed)
  - Warnings (manual attention needed)
  - Preserved as fallback (HtmlCode)
- [ ] Generate user-friendly report:
  ```
  Conversion Summary:
  ✅ 45 elements converted successfully
  ⚠️ 3 elements need manual attention:
     - Line 52: Complex onclick handler preserved as attribute
     - Line 78: Alpine.js x-show directive (not converted)
     - Line 134: Inline SVG with scripts (preserved as HtmlCode)
  ❌ 1 element could not be converted:
     - Line 201: React component detected
  ```
- [ ] Show report in admin interface
- [ ] Option to download report as JSON/text

#### 2.3 Component Detection
- [ ] Identify repeated patterns (cards, list items)
- [ ] Suggest Oxygen component/partial creation
- [ ] Detect navigation patterns → suggest header template
- [ ] Detect footer patterns → suggest footer template

#### 2.4 Smart Fallbacks
- [ ] When interaction can't be converted → preserve original + add Oxygen interaction placeholder
- [ ] When CSS can't be parsed → create CssCode element with extracted styles
- [ ] When script can't be transformed → create JavaScriptCode with original + warning comment

---

### Tier 3: Advanced Features (Target: Edge cases)
**Goal**: Handle complex scenarios and provide maximum flexibility

#### 3.1 Full JavaScript AST Processing
- [ ] Integrate proper JS parser (consider options):
  - PHP-based: Custom tokenizer
  - Node subprocess: Use esprima/acorn
  - External API: Parse and return AST
- [ ] Dependency analysis (what functions call what)
- [ ] Dead code elimination
- [ ] Scope analysis for variable conflicts

#### 3.2 CSS Processing
- [ ] Parse external stylesheets (fetch and process)
- [ ] Merge duplicate class definitions
- [ ] Convert CSS to Oxygen design properties where possible
- [ ] Generate Oxygen global styles from CSS

#### 3.3 State Management
- [ ] Detect simple state patterns
- [ ] Convert to Oxygen dynamic data where possible
- [ ] Generate appropriate initialization code

#### 3.4 Batch Processing
- [ ] Import multiple HTML files
- [ ] Import from URL
- [ ] Import from ZIP archive
- [ ] Generate multiple Oxygen templates

---

## Architecture Improvements

### Current Architecture
```
HTML Input
    ↓
HtmlParser (DOMDocument)
    ↓
TreeBuilder (regex-based JS, manual traversal)
    ↓
ElementMapper (tag → Oxygen type)
    ↓
StyleExtractor (inline styles)
    ↓
JSON Output
```

### Target Architecture
```
HTML Input
    ↓
┌─────────────────────────────────────────┐
│ PARSER LAYER                            │
│ ├── HtmlParser (DOMDocument)            │
│ ├── CssParser (Sabberworm or similar)   │
│ ├── JsParser (AST-based)                │
│ └── FrameworkDetector                   │
└─────────────────────────────────────────┘
    ↓
┌─────────────────────────────────────────┐
│ ANALYSIS LAYER                          │
│ ├── DependencyAnalyzer                  │
│ ├── InteractionDetector                 │
│ ├── ComponentDetector                   │
│ └── ConversionPlanner                   │
└─────────────────────────────────────────┘
    ↓
┌─────────────────────────────────────────┐
│ TRANSFORMATION LAYER                    │
│ ├── ElementTransformer                  │
│ ├── StyleTransformer                    │
│ ├── InteractionTransformer              │
│ ├── ScriptTransformer                   │
│ └── FallbackHandler                     │
└─────────────────────────────────────────┘
    ↓
┌─────────────────────────────────────────┐
│ OUTPUT LAYER                            │
│ ├── OxygenJsonGenerator                 │
│ ├── ConversionReportGenerator           │
│ ├── WarningCollector                    │
│ └── ValidationEngine                    │
└─────────────────────────────────────────┘
    ↓
JSON Output + Conversion Report
```

---

## Implementation Priority

### Phase 1: Stabilization (Immediate)
1. Fix remaining edge cases in current implementation
2. Add comprehensive error handling
3. Add input validation
4. Add basic conversion warnings

### Phase 2: Reporting (Short-term)
1. Implement ConversionReport class
2. Add warnings for unsupported patterns
3. Improve admin UI to show report
4. Add "copy report" functionality

### Phase 3: Event Handler Expansion (Short-term)
1. Add all missing event types
2. Handle multiple handlers per event
3. Handle inline code (not just function calls)
4. Improve argument parsing

### Phase 4: JavaScript Robustness (Medium-term)
1. Research and select JS parsing approach
2. Implement AST-based function extraction
3. Handle more function declaration patterns
4. Add scope-aware transformation

### Phase 5: Framework Support (Medium-term)
1. Alpine.js detection and partial conversion
2. HTMX attribute preservation
3. Framework-specific warnings

### Phase 6: Advanced Features (Long-term)
1. CSS stylesheet processing
2. Component detection
3. Batch processing
4. URL import

---

## Testing Strategy

### Unit Tests Needed
- [ ] HtmlParser: Various HTML structures
- [ ] ElementMapper: All tag types
- [ ] TreeBuilder: Event handler patterns
- [ ] StyleExtractor: CSS property conversion
- [ ] JavaScript transformation: Function patterns

### Integration Tests
- [ ] Simple static HTML page
- [ ] Tailwind template (no JS)
- [ ] Interactive template (simple handlers)
- [ ] Complex template (multiple interactions)
- [ ] Framework-based template (Alpine.js)

### Test Templates to Collect
- [ ] Tailwind UI components
- [ ] Flowbite templates
- [ ] Free HTML templates (ThemeForest, etc.)
- [ ] Bootstrap templates
- [ ] Custom hand-coded pages

---

## Success Metrics

| Metric | Current | Target (Tier 1) | Target (Tier 2) |
|--------|---------|-----------------|-----------------|
| Static HTML conversion | 90% | 99% | 99% |
| Tailwind templates | 80% | 95% | 98% |
| Simple interactivity | 60% | 90% | 95% |
| Complex interactivity | 30% | 70% | 85% |
| Framework-based | 10% | 30% | 60% |
| User satisfaction | - | 4/5 stars | 4.5/5 stars |

---

## Contributing

Areas where contributions would be valuable:
1. Test templates (submit HTML that doesn't convert well)
2. JavaScript AST parser research
3. Framework-specific conversion strategies
4. CSS parsing improvements
5. Documentation and examples

---

## Resources

### Libraries to Evaluate
- **JavaScript Parsing**:
  - Peast (PHP) - Limited ES6 support
  - Node.js subprocess with esprima/acorn
  - External parsing API
- **CSS Parsing**:
  - Sabberworm/PHP-CSS-Parser (already in Oxygen)
- **HTML Parsing**:
  - DOMDocument (current, adequate)
  - Masterminds/HTML5 (better HTML5 support)

### Reference Materials
- Oxygen Builder renderer.php (for property paths)
- Oxygen interactions system (for event handling)
- Tailwind CSS documentation (for class patterns)
- Alpine.js documentation (for directive patterns)

---

*Last Updated: December 2024*
*Version: 1.0.0-dev*
