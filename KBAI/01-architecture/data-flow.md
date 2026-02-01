# Data Flow Reference

## Conversion Pipeline

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              INPUT                                           │
│  HTML String (from paste, admin UI, or API)                                 │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  STEP 1: HTML PARSING (HtmlParser)                                          │
│  ───────────────────────────────────                                        │
│  1. Preprocess HTML:                                                        │
│     - Escape @ in attributes (Alpine.js: @click → data-oxy-at-click)       │
│     - Wrap fragments in document structure                                  │
│     - Ensure UTF-8 encoding                                                 │
│  2. Load with DOMDocument (suppress libxml errors)                          │
│  3. Return <body> element or documentElement                                │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  STEP 2: CSS EXTRACTION (TreeBuilder.extractStyleTags)                      │
│  ──────────────────────────────────────────────────────                     │
│  1. Find all <style> tags in document                                       │
│  2. Extract textContent from each                                           │
│  3. Concatenate into single CSS string                                      │
│  4. Parse rules with CssParser                                              │
│  5. Create CssCode element if CSS found                                     │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  STEP 3: ICON DETECTION (IconDetector)                                      │
│  ─────────────────────────────────────                                      │
│  Detect icon libraries by scanning for:                                     │
│  - data-lucide attributes → Lucide Icons                                   │
│  - data-feather attributes → Feather Icons                                  │
│  - class="fa-*" → Font Awesome                                             │
│  - class="bi-*" → Bootstrap Icons                                           │
│  - class="material-*" → Material Icons                                      │
│  Create HtmlCode elements with CDN scripts for detected libraries           │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  STEP 4: NODE CONVERSION (TreeBuilder.convertNode - recursive)              │
│  ────────────────────────────────────────────────────────────               │
│  For each DOM node:                                                         │
│                                                                             │
│  4a. Skip Check:                                                            │
│      - Text node? → Create Text element or skip if whitespace              │
│      - Comment/DOCTYPE? → Skip                                              │
│      - <meta>, <noscript>, <style>, <script>? → Handle specially           │
│                                                                             │
│  4b. Element Type Resolution (ElementMapper):                               │
│      - Look up tag in TAG_MAP                                               │
│      - Check for special cases (button-like links, lists)                   │
│      - Determine if should be Container, Text, HtmlCode, etc.               │
│                                                                             │
│  4c. Property Building (ElementMapper.buildProperties):                     │
│      - Container → { settings: { tag } }                                   │
│      - Text → { content: { content: { text } }, settings: { tag } }       │
│      - TextLink → { content: { content: { text, link, target } } }        │
│      - Image → { content: { image: { url, alt } } }                        │
│      - HtmlCode → { content: { content: { html_code } } }                  │
│                                                                             │
│  4d. Style Extraction (StyleExtractor):                                     │
│      - Parse style attribute                                                │
│      - Map CSS properties to Oxygen design paths                            │
│      - Merge into element properties                                        │
│                                                                             │
│  4e. Class Processing (ClassStrategyService):                               │
│      - WindPress mode: Preserve all classes                                 │
│      - Native mode: Separate Tailwind from custom (not fully implemented)  │
│      - Store in settings.advanced.classes                                   │
│                                                                             │
│  4f. ID Processing:                                                         │
│      - Store in settings.advanced.id                                        │
│                                                                             │
│  4g. Attribute Processing (InteractionDetector):                            │
│      - Event handlers (onclick, etc.) → Interactions                       │
│      - data-*, aria-* → Custom attributes                                  │
│      - Framework attrs (x-*, hx-*) → Preserve and warn                     │
│                                                                             │
│  4h. Framework Detection (FrameworkDetector):                               │
│      - Alpine.js (x-*, @*, :*) → Add warning                               │
│      - HTMX (hx-*) → Add warning                                           │
│      - Stimulus (data-controller, etc.) → Add warning                      │
│                                                                             │
│  4i. CSS Rule Application:                                                  │
│      - Match element ID to CSS rules                                        │
│      - Apply matching styles to design properties                           │
│                                                                             │
│  4j. Recurse for Children:                                                  │
│      - Call convertNode() for each child                                    │
│      - Collect non-null results                                             │
│      - Store in element.children                                            │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  STEP 5: SCRIPT PROCESSING                                                  │
│  ─────────────────────────────                                              │
│  For <script> tags:                                                         │
│  - External (src=) → Create HtmlCode with script tag                       │
│  - Inline → Transform with JavaScriptTransformer:                          │
│    * function foo() → window.foo = function(event, target, action)         │
│    * const foo = () => → window.foo = () =>                                │
│    * Wrap DOM-dependent code in DOMContentLoaded                           │
│  - Create JavaScriptCode element                                            │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  STEP 6: RESULT ASSEMBLY                                                    │
│  ───────────────────────────                                                │
│  Combine all pieces:                                                        │
│  - Root container with converted children                                   │
│  - CssCode element (if CSS extracted)                                       │
│  - Icon library script elements                                             │
│  - Conversion statistics                                                    │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                              OUTPUT                                          │
│  {                                                                          │
│    success: true,                                                           │
│    element: { id, data, children },    // Root element tree                │
│    cssElement: { ... },                // CssCode element                  │
│    iconScriptElements: [ ... ],        // Icon library loaders             │
│    detectedIconLibraries: ['lucide'],  // Library names                    │
│    extractedCss: '...',                // Raw CSS string                   │
│    customClasses: ['my-class'],        // Non-Tailwind classes             │
│    stats: {                            // Conversion report                │
│      elementCount: 45,                                                     │
│      tailwindClassCount: 120,                                              │
│      customClassCount: 15,                                                 │
│      warnings: [...],                                                      │
│      errors: [...],                                                        │
│      info: [...]                                                           │
│    }                                                                       │
│  }                                                                          │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Interaction Conversion Flow

```
HTML Event Handler                    Oxygen Interaction
─────────────────                    ───────────────────

onclick="toggleMenu()"         →     {
                                       trigger: 'click',
                                       target: 'this_element',
                                       actions: [{
                                         name: 'javascript_function',
                                         target: 'this_element',
                                         js_function_name: 'toggleMenu'
                                       }]
                                     }

onclick="func1(); func2(arg)"  →     {
                                       trigger: 'click',
                                       actions: [
                                         { js_function_name: 'func1' },
                                         { js_function_name: 'func2' }
                                       ]
                                     }
```

## JavaScript Transformation Flow

```
Original JavaScript                  Transformed JavaScript
───────────────────                  ──────────────────────

function toggleMenu() {        →     window.toggleMenu = function(event, target, action) {
  // code                              // code
}                                    }

const handleClick = () => {    →     window.handleClick = () => {
  // code                              // code
}                                    }

document.addEventListener(...) →     document.addEventListener('DOMContentLoaded', function() {
                                       document.addEventListener(...)
                                     });
```

## Class Handling Flow

```
┌─────────────────────────────────────────────────────────────────┐
│  INPUT: class="flex items-center bg-blue-500 my-custom-class"   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  ClassStrategyService.processClasses()                          │
│  ───────────────────────────────────                            │
│  Check mode via EnvironmentService:                             │
│  - 'windpress': WindPress detected or explicitly set            │
│  - 'native': Oxygen-only installation                           │
│  - 'auto': Detect based on WindPress presence                   │
└─────────────────────────────────────────────────────────────────┘
                              │
              ┌───────────────┴───────────────┐
              ▼                               ▼
┌─────────────────────────────┐ ┌─────────────────────────────────┐
│  WINDPRESS MODE             │ │  NATIVE MODE                    │
│  ─────────────              │ │  ───────────                    │
│  Preserve ALL classes:      │ │  Separate classes:              │
│  settings.advanced.classes  │ │  - Tailwind → (should map to   │
│  = ['flex', 'items-center', │ │              design properties) │
│     'bg-blue-500',          │ │  - Custom → classes array      │
│     'my-custom-class']      │ │                                 │
│                             │ │  (NOT FULLY IMPLEMENTED)        │
│  WindPress generates CSS    │ │                                 │
└─────────────────────────────┘ └─────────────────────────────────┘
```
