# Next Steps: Oxygen HTML Converter Plugin

> **Generated:** January 18, 2026  
> **Based on:** Comprehensive codebase analysis

---

## 🔴 Phase 1: Critical Fixes (MUST DO BEFORE PRODUCTION)

These issues will cause runtime failures or completely broken functionality.

### 1.1 Fix PHP 7.4 Compatibility

**Priority:** 🔴 CRITICAL  
**Effort:** 30 minutes  
**Files:**
- `src/Services/JavaScriptTransformer.php` (line 178)
- `src/Services/FrameworkDetector.php` (lines 53, 67, 94)
- `src/Services/InteractionDetector.php` (line 102)

**Action:**
```bash
# Create polyfill file
touch src/polyfills.php
```

**Content for `src/polyfills.php`:**
```php
<?php
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return strpos($haystack, $needle) === 0;
    }
}
```

**Then:** Include in `oxygen-html-converter.php` before autoloader.

**Also:** Change `int|false` return type to PHPDoc annotation.

---

### 1.2 Fix Oxygen Builder Detection

**Priority:** 🔴 CRITICAL  
**Effort:** 15 minutes  
**Files:**
- `oxygen-html-converter.php` (lines 45-50)
- `src/Plugin.php` (lines 58-63)

**Action:** Replace Breakdance checks with Oxygen checks:

```php
// In oxygen-html-converter.php - replace BREAKDANCE_MODE check
if (!defined('OXYGEN_VSB_VERSION') && !class_exists('OxygenElement')) {
    // Show error notice
}

// In Plugin.php - replace isOxygenBuilder()
private function isOxygenBuilder(): bool
{
    return isset($_GET['ct_builder']) ||
           isset($_GET['oxygen_iframe']) ||
           (defined('OXYGEN_IFRAME') && OXYGEN_IFRAME);
}
```

---

### 1.3 Fix WindPress Detection

**Priority:** 🔴 CRITICAL  
**Effort:** 15 minutes  
**File:** `src/Services/EnvironmentService.php`

**Action:** Replace lines 21-38 with correct detection:

```php
public function isWindPressActive(): bool
{
    if (class_exists('\WindPress\WindPress\Plugin')) {
        return true;
    }
    if (class_exists('\WIND_PRESS')) {
        return true;
    }
    if (function_exists('is_plugin_active')) {
        return is_plugin_active('windpress/windpress.php');
    }
    return false;
}
```

**Also:** Remove the global `get_option()` function definition at top of file.

---

### 1.4 Remove Debug Logging

**Priority:** 🔴 CRITICAL  
**Effort:** 5 minutes  
**File:** `src/TreeBuilder.php`

**Action:** Remove or comment out these lines:
- Line 412-413: `error_log('[OxyConverter] Button Element ID...')`
- Line 449: `error_log('[OxyConverter] Element ID...')`
- Line 514: `error_log('[OxyConverter] Element ID...')`

---

### 1.5 Fix AJAX Batch Validation

**Priority:** 🟠 HIGH  
**Effort:** 10 minutes  
**File:** `src/Ajax.php`

**Action:** Add type validation for batch input at line 133:

```php
$batch = isset($_POST['batch']) && is_array($_POST['batch']) 
    ? array_map('wp_unslash', $_POST['batch']) 
    : [];

if (empty($batch)) {
    wp_send_json_error(['message' => 'No HTML batch provided'], 400);
    return;
}
```

---

## 🟠 Phase 2: High Priority Improvements

### 2.1 Add Missing Tests for Core Classes

**Priority:** 🟠 HIGH  
**Effort:** 2-3 hours  
**Create:**
- `tests/Unit/HtmlParserTest.php`
- `tests/Unit/ElementMapperTest.php`
- `tests/Unit/StyleExtractorTest.php`

**Why:** These are core classes with 0% test coverage. Bugs here affect everything.

---

### 2.2 Create Integration Test Directory

**Priority:** 🟠 HIGH  
**Effort:** 30 minutes

**Action:**
```bash
mkdir tests/Integration
touch tests/Integration/.gitkeep
```

**Why:** `phpunit.xml` references this directory but it doesn't exist.

---

### 2.3 Add TailwindDetector Tests

**Priority:** 🟠 HIGH  
**Effort:** 1 hour  
**Create:** `tests/Unit/Services/TailwindDetectorTest.php`

**Why:** Class handling is critical for WindPress integration.

---

## 🟡 Phase 3: Architecture Improvements

### 3.1 Extract TreeBuilder into Smaller Services

**Priority:** 🟡 MEDIUM  
**Effort:** 4-6 hours

**Current Problem:** `TreeBuilder.php` is 640 lines with 12+ dependencies.

**Proposed Extraction:**
```
TreeBuilder (orchestrator, ~100 lines)
├── NodeConverter (handles convertNode logic)
├── CssExtractor (handles style tag extraction)
├── ClassProcessor (handles class processing)
└── ScriptProcessor (handles script handling)
```

---

### 3.2 Implement Dependency Injection

**Priority:** 🟡 MEDIUM  
**Effort:** 2-3 hours

**Action:** Change TreeBuilder constructor from:
```php
public function __construct()
{
    $this->parser = new HtmlParser();
    // ... 12 more instantiations
}
```

To:
```php
public function __construct(
    ?HtmlParser $parser = null,
    ?ElementMapper $mapper = null,
    // ...
) {
    $this->parser = $parser ?? new HtmlParser();
    // ...
}
```

**Why:** Enables testing with mocks, follows SOLID principles.

---

### 3.3 Create Service Interfaces

**Priority:** 🟡 MEDIUM  
**Effort:** 2 hours

**Create:**
- `src/Contracts/ParserInterface.php`
- `src/Contracts/DetectorInterface.php`
- `src/Contracts/TransformerInterface.php`

**Why:** Allows swapping implementations, better testability.

---

## 🟢 Phase 4: Feature Enhancements

### 4.1 Add CSS @media Query Support

**Priority:** 🟢 LOW  
**Effort:** 4-6 hours  
**File:** `src/Services/CssParser.php`

**Why:** Currently @media queries break parsing entirely.

---

### 4.2 Complete Tailwind-to-Oxygen Property Mapping

**Priority:** 🟢 LOW  
**Effort:** 6-8 hours  
**File:** `src/Services/ClassStrategyService.php`

**Current State:** Native mode just preserves classes (same as WindPress mode).

**Goal:** Map `flex items-center gap-4` to:
```php
design.layout.display = 'flex'
design.layout.align-items = 'center'
design.layout.gap = '16px'
```

---

### 4.3 Add More Element Type Mappings

**Priority:** 🟢 LOW  
**Effort:** 2-3 hours  
**File:** `src/ElementMapper.php`

**Missing Tags:**
- `<template>` (Web Components)
- `<canvas>`
- `<audio>`
- `<picture>` / `<source>`
- `<dialog>`
- `<progress>` / `<meter>`

---

### 4.4 Add Extensibility Hooks

**Priority:** 🟢 LOW  
**Effort:** 3-4 hours

**Add WordPress filters:**
```php
// In ElementMapper
$tagMap = apply_filters('oxy_html_converter_tag_map', self::TAG_MAP);

// In InteractionDetector
$eventMap = apply_filters('oxy_html_converter_event_map', self::EVENT_TO_TRIGGER_MAP);
```

**Why:** Allows users/plugins to extend functionality without modifying core.

---

## 📋 Quick Reference: File Locations

| Issue | File | Lines |
|-------|------|-------|
| PHP 8.0 union type | `src/Services/JavaScriptTransformer.php` | 178 |
| str_starts_with() | `src/Services/FrameworkDetector.php` | 53, 67, 94 |
| str_starts_with() | `src/Services/InteractionDetector.php` | 102 |
| Breakdance check | `oxygen-html-converter.php` | 45-50 |
| Wrong isOxygenBuilder() | `src/Plugin.php` | 58-63 |
| Wrong WindPress detection | `src/Services/EnvironmentService.php` | 21-38 |
| Global get_option() | `src/Services/EnvironmentService.php` | 5-9 |
| Debug error_log() | `src/TreeBuilder.php` | 412, 449, 514 |
| Batch validation | `src/Ajax.php` | 133 |

---

## ✅ Definition of Done

### For Phase 1 (Critical):
- [ ] All tests pass: `vendor/bin/phpunit`
- [ ] Plugin loads without errors on PHP 7.4
- [ ] Plugin activates with Oxygen Builder 6 RC1
- [ ] WindPress detection works correctly
- [ ] No debug logging in production

### For Phase 2 (High Priority):
- [ ] Core classes have >70% test coverage
- [ ] All existing tests still pass
- [ ] No new warnings in test output

### For Phase 3 (Architecture):
- [ ] TreeBuilder under 150 lines
- [ ] All services injectable
- [ ] Documentation updated

---

## 🚀 Recommended Execution Order

```
Day 1 (2-3 hours):
├── 1.1 PHP 7.4 polyfills
├── 1.2 Oxygen detection fix
├── 1.3 WindPress detection fix
├── 1.4 Remove debug logging
└── 1.5 AJAX validation

Day 2-3 (4-6 hours):
├── 2.1 HtmlParser tests
├── 2.2 ElementMapper tests
└── 2.3 StyleExtractor tests

Day 4-5 (6-8 hours):
├── 3.1 TreeBuilder extraction
└── 3.2 Dependency injection

Future:
├── 3.3 Service interfaces
├── 4.1 CSS @media support
├── 4.2 Tailwind mapping
├── 4.3 More element types
└── 4.4 Extensibility hooks
```

---

**Ready to start? Load the relevant KBAI files and begin with Phase 1.1!**
