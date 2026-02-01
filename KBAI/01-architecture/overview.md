# Architecture Overview

## Plugin Entry Point

**File:** `oxygen-html-converter.php`

```php
// Bootstrap sequence:
1. Define constants (OXY_HTML_CONVERTER_VERSION, PATH, URL)
2. Register PSR-4 autoloader
3. Check for Oxygen Builder (CURRENTLY BROKEN - checks wrong constant)
4. Initialize Plugin::getInstance()
```

## Core Classes

### Plugin (`src/Plugin.php`)

**Pattern:** Singleton  
**Purpose:** WordPress integration, hook registration, asset enqueueing

```php
Plugin::getInstance()
├── new Ajax()          # Register AJAX handlers
├── new AdminPage()     # Register admin menu
└── Hook: oxygen_enqueue_iframe_scripts → enqueueBuilderScripts()
```

**Critical Bug:** `isOxygenBuilder()` checks for Breakdance, not Oxygen.

---

### TreeBuilder (`src/TreeBuilder.php`)

**Pattern:** Facade/Orchestrator (God Class - needs refactoring)  
**Purpose:** Main conversion engine, coordinates all services

**Dependencies (all created in constructor):**
- HtmlParser
- ElementMapper
- StyleExtractor
- JavaScriptTransformer
- EnvironmentService
- ClassStrategyService
- IconDetector
- InteractionDetector
- TailwindDetector
- FrameworkDetector
- CssParser
- ComponentDetector
- ConversionReport

**Key Method:**
```php
public function convert(string $html): array
{
    // 1. Parse HTML
    $root = $this->parser->parse($html);
    
    // 2. Extract <style> tags
    $this->extractedCss = $this->extractStyleTags($doc);
    
    // 3. Detect icons
    $this->detectedIconLibraries = $this->iconDetector->detectIconLibraries($doc);
    
    // 4. Convert each node recursively
    $children = [];
    foreach ($bodyNodes as $node) {
        $element = $this->convertNode($node);
        if ($element !== null) {
            $children[] = $element;
        }
    }
    
    // 5. Return result
    return [
        'success' => true,
        'element' => $rootElement,
        'cssElement' => $cssElement,
        'iconScriptElements' => $iconScriptElements,
        'stats' => $this->report->toArray(),
    ];
}
```

---

### HtmlParser (`src/HtmlParser.php`)

**Pattern:** Stateful Parser  
**Purpose:** Parse HTML strings into traversable DOM

**Key Methods:**
- `parse(string $html): ?DOMElement` - Main parsing, returns body element
- `extractBodyContent(DOMElement $body): array` - Get non-skippable child nodes
- `shouldSkipNode(DOMNode $node): bool` - Skip whitespace, comments, meta, noscript
- `extractStyles(): array` - Get inline/external stylesheets
- `getDom(): DOMDocument` - Access underlying document

**Important:** Uses `libxml_use_internal_errors(true)` for error suppression.

---

### ElementMapper (`src/ElementMapper.php`)

**Pattern:** Strategy/Factory  
**Purpose:** Map HTML tags to Oxygen element types

**Key Constants:**
```php
TAG_MAP = [
    'div' => 'OxygenElements\\Container',
    'p' => 'OxygenElements\\Text',
    'a' => 'OxygenElements\\TextLink',
    'img' => 'OxygenElements\\Image',
    // ... 30+ mappings
];

CONTAINER_TAG_OPTIONS = ['section', 'footer', 'header', 'nav', ...];
TEXT_TAG_OPTIONS = ['span', 'p', 'h1', 'h2', ...];
KEEP_INNER_HTML = ['ul', 'ol', 'table', 'pre', 'svg', 'form', ...];
```

**Key Methods:**
- `getElementType(string $tag, ?DOMElement $node): string`
- `buildProperties(DOMElement $node): array`
- `isContainer(string $tag): bool`
- `shouldKeepInnerHtml(string $tag): bool`

---

### StyleExtractor (`src/StyleExtractor.php`)

**Pattern:** Transformer  
**Purpose:** Convert inline CSS to Oxygen property structure

**Key Constant:**
```php
STYLE_MAP = [
    'color' => ['typography', 'color'],
    'font-size' => ['typography', 'font-size'],
    'margin-top' => ['spacing', 'margin-top'],
    // ... 50+ mappings
];
```

---

## Service Layer (`src/Services/`)

| Service | Purpose | Stateless |
|---------|---------|-----------|
| CssParser | Parse CSS rules from strings | Yes |
| JavaScriptTransformer | Transform JS for Oxygen interactions | Yes |
| InteractionDetector | Convert event handlers to interactions | Yes |
| FrameworkDetector | Detect Alpine.js, HTMX, Stimulus | Yes |
| TailwindDetector | Identify Tailwind utility classes | Yes |
| ClassStrategyService | WindPress vs Native class handling | Yes |
| EnvironmentService | Detect WindPress, get options | Yes |
| IconDetector | Detect icon libraries (Lucide, FA) | Yes |
| ComponentDetector | Find repeated HTML patterns | No (has state) |

---

## Report Layer (`src/Report/`)

### ConversionReport

**Purpose:** Track conversion statistics and collect warnings/errors

**Properties:**
- `elementCount: int`
- `tailwindClassCount: int`
- `customClassCount: int`
- `warnings: array`
- `errors: array`
- `info: array`

---

## WordPress Integration

### Admin Page (`src/AdminPage.php`)
- Adds menu under Oxygen admin and Tools
- Renders conversion interface
- Registers `oxy_html_converter_class_mode` setting

### Ajax (`src/Ajax.php`)
- `wp_ajax_oxy_html_convert` - Main conversion endpoint
- `wp_ajax_oxy_html_convert_preview` - Preview/stats endpoint
- `wp_ajax_oxy_html_convert_batch` - Batch conversion

---

## Assets

### converter.js
- Runs in Oxygen Builder iframe
- Intercepts paste events
- Converts HTML via AJAX
- Re-triggers paste with Oxygen JSON

### admin.js
- Preview functionality
- JSON output display
- Copy to clipboard

---

## Dependency Graph

```
Plugin
├── Ajax
│   └── TreeBuilder
│       ├── HtmlParser
│       ├── ElementMapper
│       ├── StyleExtractor
│       ├── ConversionReport
│       ├── CssParser
│       ├── JavaScriptTransformer
│       ├── IconDetector
│       ├── InteractionDetector
│       │   └── FrameworkDetector
│       ├── TailwindDetector
│       ├── ClassStrategyService
│       │   ├── EnvironmentService
│       │   ├── TailwindDetector
│       │   └── ConversionReport
│       ├── FrameworkDetector
│       │   └── ConversionReport
│       └── ComponentDetector
│           └── ConversionReport
└── AdminPage
```
