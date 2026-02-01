# Oxygen Interactions Reference

## Interaction Structure

Interactions are stored at:
```
element.data.properties.settings.interactions.interactions
```

Note the double `interactions` in the path.

## Complete Interaction Object

```json
{
  "trigger": "click",
  "target": "this_element",
  "actions": [
    {
      "name": "javascript_function",
      "target": "this_element",
      "js_function_name": "myFunction"
    }
  ],
  "advanced": {
    "run_only_once": false
  }
}
```

## Trigger Types

| HTML Event | Oxygen Trigger | Notes |
|------------|----------------|-------|
| `onclick` | `click` | Most common |
| `ondblclick` | `dbl_click` | |
| `onmouseenter` | `mouse_enter` | |
| `onmouseleave` | `mouse_leave` | |
| `onmouseover` | `mouse_over` | |
| `onmouseout` | `mouse_out` | |
| `onfocus` | `focus` | Form elements |
| `onblur` | `blur` | Form elements |
| `onchange` | `change` | Form elements |
| `oninput` | `input` | Form elements |
| `onsubmit` | `submit` | Forms |
| `onkeydown` | `key_down` | |
| `onkeyup` | `key_up` | |
| `ontouchstart` | `touchstart` | Mobile |
| `ontouchend` | `touchend` | Mobile |
| (none) | `scroll_into_view` | Oxygen-only |
| (none) | `page_loaded` | Oxygen-only |
| (none) | `page_scrolled` | Oxygen-only |

## Action Types

### javascript_function
Calls a window-level function.

```json
{
  "name": "javascript_function",
  "target": "this_element",
  "js_function_name": "toggleMenu"
}
```

**How it works:** Oxygen calls `window.toggleMenu(event, target, action)` where:
- `event` - The DOM event
- `target` - The target element
- `action` - The action object

### toggle_class
```json
{
  "name": "toggle_class",
  "target": "%%SELECTOR%%",
  "css_class": "is-active"
}
```

### add_class
```json
{
  "name": "add_class",
  "target": ".other-element",
  "css_class": "visible"
}
```

### remove_class
```json
{
  "name": "remove_class",
  "target": "#my-id",
  "css_class": "hidden"
}
```

### show_element / hide_element
```json
{
  "name": "show_element",
  "target": "#modal"
}
```

### scroll_to
```json
{
  "name": "scroll_to",
  "target": "#section-2",
  "scroll_offset": 100,
  "scroll_delay": 0
}
```

### set_attribute / remove_attribute
```json
{
  "name": "set_attribute",
  "target": "this_element",
  "attribute_name": "data-state",
  "attribute_value": "open"
}
```

### focus / blur
```json
{
  "name": "focus",
  "target": "#input-field"
}
```

## Target Selectors

| Target | Meaning |
|--------|---------|
| `this_element` | The element with the interaction |
| `%%SELECTOR%%` | Oxygen's selector for this element |
| `.class-name` | CSS class selector |
| `#element-id` | ID selector |
| Any CSS selector | Standard CSS selectors work |

## Conversion from HTML Events

### Simple Function Call
```html
<button onclick="toggleMenu()">Click</button>
```

Converts to:
```json
{
  "trigger": "click",
  "target": "this_element",
  "actions": [{
    "name": "javascript_function",
    "target": "this_element",
    "js_function_name": "toggleMenu"
  }]
}
```

### Multiple Function Calls
```html
<button onclick="func1(); func2(); func3()">Click</button>
```

Converts to:
```json
{
  "trigger": "click",
  "actions": [
    { "name": "javascript_function", "js_function_name": "func1" },
    { "name": "javascript_function", "js_function_name": "func2" },
    { "name": "javascript_function", "js_function_name": "func3" }
  ]
}
```

### Function with Arguments
```html
<button onclick="showItem(5, 'test')">Click</button>
```

**Challenge:** Oxygen interactions don't support passing arguments directly.

**Solution:** Store arguments in data attributes:
```json
{
  "data": {
    "properties": {
      "settings": {
        "advanced": {
          "attributes": [
            { "name": "data-arg-0", "value": "5" },
            { "name": "data-arg-1", "value": "test" }
          ]
        },
        "interactions": {
          "interactions": [{
            "trigger": "click",
            "actions": [{
              "name": "javascript_function",
              "js_function_name": "showItem"
            }]
          }]
        }
      }
    }
  }
}
```

Then the function reads arguments from `target.dataset`:
```javascript
window.showItem = function(event, target, action) {
    const arg0 = target.dataset.arg0;  // '5'
    const arg1 = target.dataset.arg1;  // 'test'
    // Use arguments...
}
```

## Data Attribute Output

On the frontend, interactions are serialized to `data-interactions`:
```html
<div data-interactions='[{"trigger":"click","actions":[...]}]'>
```

Oxygen's JavaScript parses this and attaches event listeners.

## Code Location

- **InteractionDetector:** `src/Services/InteractionDetector.php`
- **EVENT_TO_TRIGGER_MAP:** Lines 20-38
- **createInteractionFromHandler():** Main conversion method
