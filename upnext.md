# Oxygen HTML Converter - Improvement Roadmap

## 🔴 High Priority (High Impact)

### 1. Tailwind → Oxygen Properties Converter
**Location:** `src/Services/ClassStrategyService.php`

Currently when WindPress isn't active, Tailwind classes are preserved with a warning. Convert them to native Oxygen design properties:

```php
// Example mappings:
// 'p-4' → design.padding.all = '1rem'
// 'text-blue-500' → design.color = '#3b82f6'
// 'flex' → design.display = 'flex'
```

**Files to create/modify:**
- `src/Services/TailwindToPropertyMapper.php` (new)
- `src/Services/ClassStrategyService.php` (integrate mapper)

---

### 2. Expand Interaction/Event Support
**Location:** `src/Services/InteractionDetector.php`

Add support for:
- Scroll-based triggers (scroll position, viewport entry)
- Keyboard events (`onkeydown`, `onkeyup`)
- Touch events (`ontouchstart`, `ontouchend`)
- Form events (`onsubmit`, `oninput`, `onchange`)

---

### 3. Alpine.js → Oxygen Interactions
**Location:** `src/Services/FrameworkDetector.php`

Convert Alpine.js directives to native Oxygen Interactions:
```html
<!-- From: @click="open = !open" -->
<!-- To: Oxygen toggle class interaction -->
```

---

## 🟡 Medium Priority (Good Value)

### 4. Enhanced JavaScript Transformer
**Location:** `src/Services/JavaScriptTransformer.php`

- Transform ES6 arrow functions in event handlers
- Handle IIFE patterns
- Extract inline anonymous functions
- Better async/await support

---

### 5. Component Detection → Reusable Partials
**Location:** `src/Services/ComponentDetector.php`

- Generate Oxygen Reusable Component JSON templates
- Detect common UI patterns (cards, testimonials, pricing tables)
- Suggest component extraction in conversion report

---

### 6. CSS Variable Support
**Location:** `src/StyleExtractor.php`

Map CSS custom properties to Oxygen Global Colors:
```css
:root { --primary: #3b82f6; }
.btn { color: var(--primary); }
```

---

## 🟢 Lower Priority (Nice to Have)

### 7. Batch Processing UI
- Drag & drop multiple HTML files
- Progress indicator
- Download results as ZIP

### 8. Live Preview in Admin
Show Oxygen-rendered preview before copying

### 9. Template Library
Pre-built conversions for:
- Tailwind UI components
- Flowbite
- DaisyUI patterns

---

## Recommended Starting Point

**#1 (Tailwind → Properties)** is the highest-value feature because:
1. Biggest gap for users without WindPress
2. Many Tailwind templates in the wild
3. Well-scoped mapping task
