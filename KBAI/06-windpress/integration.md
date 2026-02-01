# WindPress Integration Guide

## Overview

WindPress is a WordPress plugin that enables Tailwind CSS in WordPress page builders. When active, it handles CSS generation for Tailwind classes via JIT compilation.

## Detection

### Correct Detection Methods

```php
public function isWindPressActive(): bool
{
    // Method 1: Check for WindPress Plugin class
    if (class_exists('\WindPress\WindPress\Plugin')) {
        return true;
    }

    // Method 2: Check for WIND_PRESS constant class
    if (class_exists('\WIND_PRESS')) {
        return true;
    }

    // Method 3: Check plugin active status
    if (function_exists('is_plugin_active')) {
        return is_plugin_active('windpress/windpress.php');
    }

    return false;
}
```

### Current Bug (MUST FIX)

The current `EnvironmentService.php` uses **wrong** detection:

```php
// WRONG - These don't exist
class_exists('\Jewei\WindPress\WindPress')     // Wrong namespace
defined('JEWEI_WINDPRESS_VERSION')              // Wrong constant
is_plugin_active('jewei-windpress/windpress.php') // Wrong path
```

## WindPress Constants

When WindPress is active, these are available:

```php
WIND_PRESS::FILE           // Plugin main file path
WIND_PRESS::VERSION        // e.g., '3.3.73'
WIND_PRESS::WP_OPTION      // 'windpress'
WIND_PRESS::REST_NAMESPACE // 'windpress/v1'
WIND_PRESS::DATA_DIR       // '/windpress/data/'
WIND_PRESS::CACHE_DIR      // '/windpress/cache/'
```

## Class Handling Strategy

### WindPress Mode (Recommended when WindPress active)

**Behavior:** Preserve ALL CSS classes exactly as-is.

**Why:** WindPress scans the page and generates CSS for any Tailwind classes it finds. No preprocessing needed.

```php
public function processWindPressMode(array $classes, array &$element): void
{
    // Simply preserve all classes
    $element['data']['properties']['settings']['advanced']['classes'] = $classes;
}
```

### Native Mode (When WindPress not active)

**Behavior:** Attempt to map Tailwind classes to Oxygen design properties.

**Status:** Not fully implemented. Currently just preserves classes.

**Future Goal:**
```php
// Convert: class="flex items-center gap-4"
// To: design.layout.display = 'flex'
//     design.layout.align-items = 'center'
//     design.layout.gap = '16px'
```

## WindPress Integration Points

### Oxygen Detection (in WindPress)

WindPress detects Oxygen Builder using:

```php
// Oxygen 6 (Breakdance mode)
isset($_GET['breakdance']) || isset($_GET['breakdance_iframe'])

// Oxygen Classic
isset($_GET['ct_builder']) || isset($_GET['oxygen_iframe'])
```

### Available Filters

```php
// Prevent WindPress loading
add_filter('f!windpress/core/runtime:is_prevent_load', '__return_true');

// Register custom content provider for class scanning
add_filter('f!windpress/core/cache:compile.providers', function($providers) {
    $providers[] = [
        'id' => 'oxy-html-converter',
        'name' => 'Oxygen HTML Converter',
        'callback' => MyCompileClass::class,
        'enabled' => true,
    ];
    return $providers;
});
```

## Best Practices

### 1. Always Check Mode First

```php
$mode = $this->environment->getClassHandlingMode();

if ($mode === 'auto') {
    $mode = $this->environment->isWindPressActive() ? 'windpress' : 'native';
}
```

### 2. Never Strip Tailwind Classes

Even in native mode, unknown classes should be preserved:

```php
foreach ($classes as $class) {
    $mapped = $this->mapToOxygenProperty($class);
    if ($mapped === null) {
        // Keep unknown classes - might be custom or future Tailwind
        $remainingClasses[] = $class;
    }
}
```

### 3. Support All Tailwind Syntax

Tailwind has complex class patterns:

```
flex                    // Simple utility
hover:bg-blue-500      // State prefix
md:flex                // Responsive prefix
md:hover:bg-blue-500   // Combined prefixes
w-[200px]              // Arbitrary value
bg-[#ff0000]           // Arbitrary color
-mt-4                  // Negative value
```

### 4. Don't Interfere with WindPress Runtime

WindPress uses Play CDN for JIT compilation. Don't:
- Modify class names
- Remove seemingly "unused" classes
- Add CSS for Tailwind classes (WindPress does this)

## Testing with WindPress

```php
public function testWindPressModePreservesAllClasses(): void
{
    $env = Mockery::mock(EnvironmentService::class);
    $env->shouldReceive('isWindPressActive')->andReturn(true);
    $env->shouldReceive('getClassHandlingMode')->andReturn('auto');

    $service = new ClassStrategyService($env, new ConversionReport(), new TailwindDetector());
    
    $classes = ['flex', 'items-center', 'custom-class', 'bg-blue-500', 'hover:bg-blue-600'];
    $element = ['data' => ['properties' => ['settings' => ['advanced' => []]]]];
    
    $service->processClasses($classes, $element);
    
    // ALL classes should be preserved
    $this->assertEquals($classes, $element['data']['properties']['settings']['advanced']['classes']);
}
```
