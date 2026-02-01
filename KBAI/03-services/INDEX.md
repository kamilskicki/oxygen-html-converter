# Services Index

Quick reference for all service classes in `src/Services/`.

## Service Overview

| Service | File | Purpose | Has State |
|---------|------|---------|-----------|
| [CssParser](css-parser.md) | `CssParser.php` | Parse CSS rules | No |
| [JavaScriptTransformer](javascript-transformer.md) | `JavaScriptTransformer.php` | Transform JS for Oxygen | No |
| [InteractionDetector](interaction-detector.md) | `InteractionDetector.php` | Convert event handlers | No |
| [FrameworkDetector](framework-detector.md) | `FrameworkDetector.php` | Detect Alpine/HTMX/Stimulus | No |
| [TailwindDetector](tailwind-detector.md) | `TailwindDetector.php` | Identify Tailwind classes | No |
| [ClassStrategyService](class-strategy.md) | `ClassStrategyService.php` | WindPress vs Native mode | No |
| [EnvironmentService](environment-service.md) | `EnvironmentService.php` | Detect WindPress, get options | No |
| [IconDetector](icon-detector.md) | `IconDetector.php` | Detect icon libraries | No |
| [GridDetector](grid-detector.md) | `GridDetector.php` | Map Tailwind Grid to Oxygen | No |
| [ComponentDetector](component-detector.md) | `ComponentDetector.php` | Find repeated patterns | **Yes** |

## Quick Lookup

### Need to add a new HTML tag mapping?
→ See `src/ElementMapper.php` (not a service, but core class)

### Need to add a new event handler type?
→ See [InteractionDetector](interaction-detector.md) → `EVENT_TO_TRIGGER_MAP`

### Need to add a new CSS property mapping?
→ See `src/StyleExtractor.php` → `STYLE_MAP`

### Need to add a new icon library?
→ See [IconDetector](icon-detector.md)

### Need to add a new framework detection?
→ See [FrameworkDetector](framework-detector.md)

### Need to change class handling behavior?
→ See [ClassStrategyService](class-strategy.md)

## Service Patterns

All services follow these patterns:

```php
namespace OxyHtmlConverter\Services;

class ServiceName
{
    // Constants for configuration
    private const SOME_MAP = [...];
    
    // Optional dependencies (nullable with defaults)
    private ?ConversionReport $report;
    
    public function __construct(?ConversionReport $report = null)
    {
        $this->report = $report;
    }
    
    // Main public method
    public function process($input): $output
    {
        // Implementation
    }
}
```

## Test Coverage

| Service | Test File | Coverage |
|---------|-----------|----------|
| CssParser | ✅ `CssParserTest.php` | Partial |
| JavaScriptTransformer | ✅ `JavaScriptTransformerTest.php` | Good |
| InteractionDetector | ✅ `InteractionDetectorTest.php` | Partial |
| FrameworkDetector | ✅ `FrameworkDetectorTest.php` | Partial |
| TailwindDetector | ❌ None | 0% |
| ClassStrategyService | ❌ None | 0% |
| EnvironmentService | ✅ `EnvironmentServiceTest.php` | Partial |
| IconDetector | ❌ None | 0% |
| ComponentDetector | ✅ `ComponentDetectorTest.php` | Partial |
