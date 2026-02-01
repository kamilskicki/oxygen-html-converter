# PHP 7.4 Compatibility Issues

## Problem

The plugin targets PHP 7.4 (`composer.json`: `"php": ">=7.4"`) but uses PHP 8.0+ features.

## Issues Found

### 1. Union Return Types (PHP 8.0+)

**Location:** `src/Services/JavaScriptTransformer.php:178`

```php
// BROKEN in PHP 7.4
private function findMatchingBrace(string $code, int $openBracePos): int|false
```

**Fix:**
```php
/**
 * Find the position of the matching closing brace
 *
 * @param string $code The code to search
 * @param int $openBracePos Position of opening brace
 * @return int|false Position of closing brace, or false if not found
 */
private function findMatchingBrace(string $code, int $openBracePos)
```

---

### 2. str_starts_with() Function (PHP 8.0+)

**Locations:**
- `src/Services/FrameworkDetector.php:53`
- `src/Services/FrameworkDetector.php:67`
- `src/Services/FrameworkDetector.php:94`
- `src/Services/InteractionDetector.php:102`

```php
// BROKEN in PHP 7.4
if (str_starts_with($name, 'x-')) { }
if (str_starts_with($name, 'hx-')) { }
if (str_starts_with($name, 'data-oxy-at-')) { }
```

**Fix Option 1 - Use strpos():**
```php
if (strpos($name, 'x-') === 0) { }
if (strpos($name, 'hx-') === 0) { }
if (strpos($name, 'data-oxy-at-') === 0) { }
```

**Fix Option 2 - Add polyfill in bootstrap or helper:**
```php
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return strpos($haystack, $needle) === 0;
    }
}
```

---

### 3. str_ends_with() Function (PHP 8.0+)

Not currently used, but be aware if adding new code.

**Polyfill:**
```php
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}
```

---

### 4. str_contains() Function (PHP 8.0+)

Not currently used, but be aware if adding new code.

**Polyfill:**
```php
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return strpos($haystack, $needle) !== false;
    }
}
```

---

## Recommended Solution

Create a polyfill file and include it in the bootstrap:

**File:** `src/polyfills.php`
```php
<?php
/**
 * PHP 7.4 polyfills for PHP 8.0+ functions
 */

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return strpos($haystack, $needle) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return strpos($haystack, $needle) !== false;
    }
}
```

**Include in main plugin file:**
```php
// oxygen-html-converter.php
require_once __DIR__ . '/src/polyfills.php';
```

---

## Alternative: Upgrade Minimum PHP Version

If PHP 7.4 support isn't required, update `composer.json`:

```json
{
    "require": {
        "php": ">=8.0"
    }
}
```

And update the plugin header:
```php
* Requires PHP: 8.0
```

**Considerations:**
- PHP 7.4 reached EOL November 2022
- PHP 8.0 is widely available
- Would simplify codebase
