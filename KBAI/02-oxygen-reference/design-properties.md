# Oxygen Design Properties Reference

## Property Path Structure

Design properties are stored at:
```
element.data.properties.design.{category}.{property}
```

## Categories and Properties

### Typography (`design.typography`)

| Property | Example Value | CSS Equivalent |
|----------|---------------|----------------|
| `color` | `"#333333"` | `color` |
| `text-align` | `"center"` | `text-align` |
| `font-family` | `"Inter"` | `font-family` |
| `font-size` | `{ "number": 16, "unit": "px" }` | `font-size` |
| `font-weight` | `"700"` | `font-weight` |
| `line-height` | `{ "number": 1.5, "unit": "" }` | `line-height` |
| `letter-spacing` | `{ "number": 0.5, "unit": "px" }` | `letter-spacing` |
| `text-transform` | `"uppercase"` | `text-transform` |
| `font-style` | `"italic"` | `font-style` |

### Spacing (`design.spacing`)

| Property | Example Value | CSS Equivalent |
|----------|---------------|----------------|
| `margin` | `{ "top": {...}, "right": {...}, "bottom": {...}, "left": {...} }` | `margin` |
| `padding` | `{ "top": {...}, "right": {...}, "bottom": {...}, "left": {...} }` | `padding` |
| `margin-top` | `{ "number": 20, "unit": "px" }` | `margin-top` |
| `padding-left` | `{ "number": 10, "unit": "px" }` | `padding-left` |

**Value Object Format:**
```json
{
  "number": 20,
  "unit": "px",
  "style": "20px"
}
```

### Size (`design.size`)

| Property | Example Value | CSS Equivalent |
|----------|---------------|----------------|
| `width` | `{ "number": 100, "unit": "%" }` | `width` |
| `min-width` | `{ "number": 300, "unit": "px" }` | `min-width` |
| `max-width` | `{ "number": 1200, "unit": "px" }` | `max-width` |
| `height` | `{ "number": 400, "unit": "px" }` | `height` |
| `min-height` | `{ "number": 100, "unit": "vh" }` | `min-height` |
| `max-height` | `{ "number": 500, "unit": "px" }` | `max-height` |

### Layout (`design.layout`)

| Property | Values | CSS Equivalent |
|----------|--------|----------------|
| `layout` | `"vertical"`, `"horizontal"`, `"grid"`, `"advanced"` | - |
| `display` | `"flex"`, `"block"`, `"grid"`, `"none"` | `display` |
| `flex-direction` | `"row"`, `"column"`, `"row-reverse"`, `"column-reverse"` | `flex-direction` |
| `justify-content` | `"flex-start"`, `"center"`, `"flex-end"`, `"space-between"`, `"space-around"` | `justify-content` |
| `align-items` | `"flex-start"`, `"center"`, `"flex-end"`, `"stretch"`, `"baseline"` | `align-items` |
| `flex-wrap` | `"nowrap"`, `"wrap"`, `"wrap-reverse"` | `flex-wrap` |
| `gap` | `{ "number": 20, "unit": "px" }` | `gap` |

### Position (`design.position`)

| Property | Example Value | CSS Equivalent |
|----------|---------------|----------------|
| `position` | `"relative"`, `"absolute"`, `"fixed"`, `"sticky"` | `position` |
| `top` | `{ "number": 0, "unit": "px" }` | `top` |
| `right` | `{ "number": 0, "unit": "px" }` | `right` |
| `bottom` | `{ "number": 0, "unit": "px" }` | `bottom` |
| `left` | `{ "number": 0, "unit": "px" }` | `left` |
| `z-index` | `100` | `z-index` |

### Background (`design.background`)

| Property | Example Value | CSS Equivalent |
|----------|---------------|----------------|
| `color` | `"#ffffff"` | `background-color` |
| `background-color` | `"rgba(0,0,0,0.5)"` | `background-color` |
| `background-image` | `"url(...)"` | `background-image` |

### Border (`design.border` or `design.borders`)

| Property | Example Value | CSS Equivalent |
|----------|---------------|----------------|
| `border-radius` | `{ "number": 8, "unit": "px" }` | `border-radius` |
| `border-width` | `{ "number": 1, "unit": "px" }` | `border-width` |
| `border-style` | `"solid"`, `"dashed"`, `"dotted"` | `border-style` |
| `border-color` | `"#cccccc"` | `border-color` |

### Effects (`design.effects`)

| Property | Example Value | CSS Equivalent |
|----------|---------------|----------------|
| `opacity` | `0.8` | `opacity` |
| `transform` | `"rotate(45deg)"` | `transform` |
| `box-shadow` | `{ shadows: [...] }` | `box-shadow` |

### Overflow (`design.overflow`)

| Property | Example Value | CSS Equivalent |
|----------|---------------|----------------|
| `overflow` | `"hidden"`, `"auto"`, `"scroll"`, `"visible"` | `overflow` |
| `overflow-x` | `"hidden"` | `overflow-x` |
| `overflow-y` | `"auto"` | `overflow-y` |

---

## CSS to Oxygen Mapping (StyleExtractor)

The `STYLE_MAP` constant in `StyleExtractor.php` maps CSS properties to Oxygen paths:

```php
private const STYLE_MAP = [
    // Typography
    'font-family' => ['typography', 'font-family'],
    'font-size' => ['typography', 'font-size'],
    'font-weight' => ['typography', 'font-weight'],
    'color' => ['typography', 'color'],
    'text-align' => ['typography', 'text-align'],
    'line-height' => ['typography', 'line-height'],
    'letter-spacing' => ['typography', 'letter-spacing'],
    'text-transform' => ['typography', 'text-transform'],
    'font-style' => ['typography', 'font-style'],
    'text-decoration' => ['typography', 'text-decoration'],
    
    // Spacing
    'margin' => ['spacing', 'margin'],
    'margin-top' => ['spacing', 'margin-top'],
    'margin-right' => ['spacing', 'margin-right'],
    'margin-bottom' => ['spacing', 'margin-bottom'],
    'margin-left' => ['spacing', 'margin-left'],
    'padding' => ['spacing', 'padding'],
    'padding-top' => ['spacing', 'padding-top'],
    'padding-right' => ['spacing', 'padding-right'],
    'padding-bottom' => ['spacing', 'padding-bottom'],
    'padding-left' => ['spacing', 'padding-left'],
    
    // Size
    'width' => ['size', 'width'],
    'min-width' => ['size', 'min-width'],
    'max-width' => ['size', 'max-width'],
    'height' => ['size', 'height'],
    'min-height' => ['size', 'min-height'],
    'max-height' => ['size', 'max-height'],
    
    // Layout
    'display' => ['layout', 'display'],
    'flex-direction' => ['layout', 'flex-direction'],
    'justify-content' => ['layout', 'justify-content'],
    'align-items' => ['layout', 'align-items'],
    'flex-wrap' => ['layout', 'flex-wrap'],
    'gap' => ['layout', 'gap'],
    'grid-template-columns' => ['layout', 'grid-template-columns'],
    'grid-template-rows' => ['layout', 'grid-template-rows'],
    'order' => ['layout', 'order'],
    'flex-grow' => ['layout', 'flex-grow'],
    
    // Position
    'position' => ['position', 'position'],
    'top' => ['position', 'top'],
    'right' => ['position', 'right'],
    'bottom' => ['position', 'bottom'],
    'left' => ['position', 'left'],
    'z-index' => ['position', 'z-index'],
    
    // Background
    'background' => ['background', 'background'],
    'background-color' => ['background', 'background-color'],
    'background-image' => ['background', 'background-image'],
    'background-size' => ['background', 'background-size'],
    'background-position' => ['background', 'background-position'],
    'background-repeat' => ['background', 'background-repeat'],
    
    // Border
    'border' => ['border', 'border'],
    'border-radius' => ['border', 'border-radius'],
    'border-width' => ['border', 'border-width'],
    'border-style' => ['border', 'border-style'],
    'border-color' => ['border', 'border-color'],
    'border-top' => ['border', 'border-top'],
    'border-right' => ['border', 'border-right'],
    'border-bottom' => ['border', 'border-bottom'],
    'border-left' => ['border', 'border-left'],
    
    // Effects
    'opacity' => ['effects', 'opacity'],
    'transform' => ['effects', 'transform'],
    'box-shadow' => ['effects', 'box-shadow'],
    'filter' => ['effects', 'filter'],
    'transition' => ['effects', 'transition'],
    
    // Overflow
    'overflow' => ['overflow', 'overflow'],
    'overflow-x' => ['overflow', 'overflow-x'],
    'overflow-y' => ['overflow', 'overflow-y'],
];
```

---

## Responsive Values

Oxygen supports breakpoint-specific values:

```json
{
  "design": {
    "spacing": {
      "padding": {
        "breakpoint_base": { "top": { "number": 40, "unit": "px" } },
        "breakpoint_tablet_portrait": { "top": { "number": 30, "unit": "px" } },
        "breakpoint_phone_landscape": { "top": { "number": 20, "unit": "px" } },
        "breakpoint_phone_portrait": { "top": { "number": 10, "unit": "px" } }
      }
    }
  }
}
```

**Breakpoint Names:**
- `breakpoint_base` - Desktop (default)
- `breakpoint_tablet_landscape` - Tablet landscape
- `breakpoint_tablet_portrait` - Tablet portrait
- `breakpoint_phone_landscape` - Phone landscape
- `breakpoint_phone_portrait` - Phone portrait

**Note:** The converter currently doesn't extract responsive styles from CSS media queries.
