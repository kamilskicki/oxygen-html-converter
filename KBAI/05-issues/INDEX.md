# Critical Bugs Index

> **Priority Order:** Fix these before production use

## 🔴 CRITICAL (Must Fix)

### 1. PHP 7.4 Incompatibility
**Files:** Multiple  
**Issue:** Uses PHP 8.0+ features on PHP 7.4 target  
**Details:** [php-compatibility.md](php-compatibility.md)

### 2. Wrong Builder Detection  
**File:** `src/Plugin.php:58-63`  
**Issue:** `isOxygenBuilder()` checks for Breakdance, not Oxygen  
**Details:** [builder-detection.md](builder-detection.md)

### 3. Wrong Oxygen Check in Bootstrap
**File:** `oxygen-html-converter.php:45-50`  
**Issue:** Checks `BREAKDANCE_MODE` constant instead of Oxygen  
**Details:** [builder-detection.md](builder-detection.md)

### 4. Wrong WindPress Detection
**File:** `src/Services/EnvironmentService.php:21-38`  
**Issue:** Wrong namespace, constant, and plugin path  
**Details:** [windpress-detection.md](windpress-detection.md)

### 5. Global Namespace Pollution
**File:** `src/Services/EnvironmentService.php:5-9`  
**Issue:** Defines `get_option()` in global namespace  
**Details:** [windpress-detection.md](windpress-detection.md)

### 6. Debug Logging in Production
**File:** `src/TreeBuilder.php:412, 449, 514`  
**Issue:** `error_log()` calls left in production code  
**Fix:** Remove or wrap in debug constant check

### 7. AJAX Batch Sanitization
**File:** `src/Ajax.php:133`  
**Issue:** Batch array not properly validated  
**Details:** [ajax-security.md](ajax-security.md)

---

## Quick Fixes

### PHP 8.0+ Functions → PHP 7.4 Polyfill

```php
// WRONG (PHP 8.0+)
if (str_starts_with($name, 'data-')) { }

// FIX (PHP 7.4 compatible)
if (strpos($name, 'data-') === 0) { }
```

```php
// WRONG (PHP 8.0+ union type)
private function findMatchingBrace(string $code, int $pos): int|false

// FIX (PHP 7.4 compatible)
/**
 * @return int|false
 */
private function findMatchingBrace(string $code, int $pos)
```

### Oxygen Builder Detection

```php
// WRONG (checks Breakdance)
private function isOxygenBuilder(): bool
{
    return isset($_GET['breakdance']) ||
           isset($_GET['breakdance_iframe']);
}

// FIX (checks Oxygen)
private function isOxygenBuilder(): bool
{
    return isset($_GET['ct_builder']) ||
           isset($_GET['oxygen_iframe']) ||
           (defined('OXYGEN_IFRAME') && OXYGEN_IFRAME);
}
```

### WindPress Detection

```php
// WRONG
if (class_exists('\Jewei\WindPress\WindPress')) { }
if (defined('JEWEI_WINDPRESS_VERSION')) { }
is_plugin_active('jewei-windpress/windpress.php');

// FIX
if (class_exists('\WindPress\WindPress\Plugin')) { }
if (class_exists('\WIND_PRESS')) { }
is_plugin_active('windpress/windpress.php');
```

### Remove Debug Logging

```php
// REMOVE these lines from TreeBuilder.php
error_log('[OxyConverter] Button Element ID ' . $element['id'] . ' FINAL classes: ' . implode(', ', $buttonClasses));
error_log('[OxyConverter] Element ID ' . $element['id'] . ' FINAL classes: ' . implode(', ', $finalClasses));
error_log('[OxyConverter] Element ID ' . ($element['id'] ?? 'unknown') . ' - Setting classes: ' . implode(', ', $finalClasses));
```

---

## Files Affected

| File | Issues |
|------|--------|
| `oxygen-html-converter.php` | Wrong Oxygen check |
| `src/Plugin.php` | Wrong builder detection |
| `src/Services/EnvironmentService.php` | Wrong WindPress detection, global pollution |
| `src/Services/JavaScriptTransformer.php` | Union type (line 178) |
| `src/Services/FrameworkDetector.php` | `str_starts_with()` (lines 53, 67, 94) |
| `src/Services/InteractionDetector.php` | `str_starts_with()` (line 102) |
| `src/TreeBuilder.php` | Debug `error_log()` calls |
| `src/Ajax.php` | Batch sanitization |
