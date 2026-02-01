# Oxygen Builder Element Reference

> Based on Oxygen Builder 6 RC1 analysis

## Element Base Structure

Every Oxygen element follows this structure:

```json
{
  "id": "el-unique-id",
  "data": {
    "type": "OxygenElements\\Container",
    "properties": {
      "content": { },
      "design": { },
      "settings": {
        "advanced": {
          "id": "html-id",
          "classes": ["class1", "class2"],
          "attributes": [
            { "name": "data-attr", "value": "value" }
          ]
        },
        "interactions": {
          "interactions": [ ]
        }
      }
    }
  },
  "children": [ ]
}
```

## Element Types

### Container
**Type:** `OxygenElements\Container`  
**Tag Options:** `div`, `section`, `footer`, `header`, `nav`, `aside`, `figure`, `article`, `main`, `details`, `summary`, `ul`, `li`, `ol`  
**Can Have Children:** Yes

#### Sticky Implementation (Navbar)
For navbars/headers with sticky behavior, use `OxygenElements\Container` with the `sticky` property group.

```json
{
  "type": "OxygenElements\\Container",
  "properties": {
    "design": {
      "tag": "nav",
      "sticky": {
        "position": "top",
        "relative_to": "viewport",
        "offset": "0"
      }
    }
  }
}
```

---

---

### Container_Link
**Type:** `OxygenElements\Container_Link`  
**Tag:** `a` (fixed)  
**Can Have Children:** Yes

```json
{
  "type": "OxygenElements\\Container_Link",
  "properties": {
    "content": {
      "content": {
        "url": "https://example.com/",
        "open_in_new_tab": false
      }
    }
  }
}
```

---

### Text
**Type:** `OxygenElements\Text`  
**Tag Options:** `div`, `span`, `p`, `h1`, `h2`, `h3`, `h4`, `h5`, `h6`, `li`, `blockquote`  
**Can Have Children:** No

```json
{
  "type": "OxygenElements\\Text",
  "properties": {
    "content": {
      "content": {
        "text": "Your text with <strong>HTML</strong> formatting"
      }
    },
    "settings": {
      "tag": "p"
    }
  }
}
```

---

### Text_Link
**Type:** `OxygenElements\Text_Link`  
**Tag:** `a` (fixed)  
**Can Have Children:** No

```json
{
  "type": "OxygenElements\\Text_Link",
  "properties": {
    "content": {
      "content": {
        "text": "Click Here",
        "url": "https://example.com/",
        "open_in_new_tab": true
      }
    }
  }
}
```

---

### Rich_Text
**Type:** `OxygenElements\Rich_Text`  
**Tag Options:** `div`, `details`, `summary`, `article`, `main`, `aside`, `section`  
**Can Have Children:** No  
**Use For:** Lists, tables, complex HTML that should render as-is

```json
{
  "type": "OxygenElements\\Rich_Text",
  "properties": {
    "content": {
      "content": {
        "text": "<ul><li>Item 1</li><li>Item 2</li></ul>"
      }
    }
  }
}
```

---

### Image
**Type:** `OxygenElements\Image`  
**Tag:** `img` (fixed)  
**Can Have Children:** No

```json
{
  "type": "OxygenElements\\Image",
  "properties": {
    "content": {
      "image": {
        "from": "url",
        "url": "https://example.com/image.jpg",
        "alt": "custom",
        "custom_alt": "Image description"
      }
    }
  }
}
```

---

### HTML_Code
**Type:** `OxygenElements\HTML_Code`  
**Tag Options:** `div`, `section`, `span`, `p`, `h1`-`h6`, `footer`, `header`, `nav`, `aside`, `figure`, `ul`, `ol`, `li`, `article`, `main`, `details`, `a`, `summary`  
**Can Have Children:** No  
**Use For:** iframes, SVG, forms, video, custom HTML

```json
{
  "type": "OxygenElements\\HTML_Code",
  "properties": {
    "content": {
      "content": {
        "html_code": "<svg>...</svg>",
        "builder_label": "My SVG"
      }
    }
  }
}
```

---

### CSS_Code
**Type:** `OxygenElements\CSS_Code`  
**Tag:** `div` (fixed)  
**Can Have Children:** No  
**Note:** Builder-only (not rendered on frontend, CSS added to stylesheet)

```json
{
  "type": "OxygenElements\\CSS_Code",
  "properties": {
    "content": {
      "content": {
        "css_code": ".my-class { color: red; }",
        "builder_label": "Converted CSS"
      }
    }
  }
}
```

---

### JavaScript_Code
**Type:** `OxygenElements\JavaScript_Code`  
**Tag:** `div` (fixed)  
**Can Have Children:** No  
**Note:** Builder-only, JS wrapped in DOMContentLoaded

```json
{
  "type": "OxygenElements\\JavaScript_Code",
  "properties": {
    "content": {
      "content": {
        "javascript_code": "window.myFunction = function() { }",
        "builder_label": "Converted JS"
      }
    }
  }
}
```

---

### HTML5_Video
**Type:** `OxygenElements\HTML5_Video`  
**Tag:** `video` (fixed)  
**Can Have Children:** No

```json
{
  "type": "OxygenElements\\HTML5_Video",
  "properties": {
    "content": {
      "content": {
        "video_file_url": { "url": "https://example.com/video.mp4" },
        "loop": true,
        "autoplay": true,
        "muted": true,
        "controls": true
      }
    }
  }
}
```

---

## HTML Tag to Oxygen Type Mapping

| HTML Tag | Oxygen Type | Notes |
|----------|-------------|-------|
| `div` | Container | Default tag |
| `section` | Container | tag: 'section' |
| `article` | Container | tag: 'article' |
| `header` | Container | tag: 'header' |
| `footer` | Container | tag: 'footer' |
| `nav` | Container | Use for navbar |
| `nav#navbar`| Container | tag: 'nav', sticky: { position: 'top' } |
| `aside` | Container | tag: 'aside' |
| `main` | Container | tag: 'main' |
| `figure` | Container | tag: 'figure' |
| `p` | Text | tag: 'p' |
| `h1`-`h6` | Text | tag: 'h1' etc |
| `span` | Text | tag: 'span' |
| `blockquote` | Text | tag: 'blockquote' |
| `a` | Text_Link | or Container_Link if has children |
| `img` | Image | |
| `ul`, `ol` | Container | or Rich_Text if complex |
| `table` | Rich_Text | Preserves table structure |
| `svg` | HTML_Code | |
| `iframe` | HTML_Code | |
| `form` | HTML_Code | |
| `video` | HTML5_Video or HTML_Code | |
| `button` | Container or Container_Link | Special handling |

---

## Grid & Layout Properties
```php
$element['data']['properties']['design']['layout'] = [
    'display' => 'grid',
    'grid' => 'true',
    'grid-template-columns' => 'repeat(3, minmax(0, 1fr))',
    'gap' => '1rem',
    'column-gap' => '1rem',
    'row-gap' => '1rem'
];
```

### Typography Centering (Icons)
```php
$element['data']['properties']['design']['typography'] = [
    'text-align' => 'center',
    'line-height' => '0' // Fixes vertical offset for icons
];
```

## Property Paths Quick Reference

### Classes
```php
$element['data']['properties']['settings']['advanced']['classes'] = ['class1', 'class2'];
```

### ID
```php
$element['data']['properties']['settings']['advanced']['id'] = 'my-id';
```

### Custom Attributes
```php
$element['data']['properties']['settings']['advanced']['attributes'] = [
    ['name' => 'data-custom', 'value' => 'value'],
    ['name' => 'aria-label', 'value' => 'Label']
];
```

### Tag Override
```php
$element['data']['properties']['design']['tag'] = 'section';
```

### Text Content
```php
$element['data']['properties']['content']['content']['text'] = 'Hello World';
```

### Link URL
```php
$element['data']['properties']['content']['content']['url'] = 'https://example.com';
```

### Image URL
```php
$element['data']['properties']['content']['image']['url'] = 'https://example.com/img.jpg';
$element['data']['properties']['content']['image']['custom_alt'] = 'Alt text';
```

### HTML Code
```php
$element['data']['properties']['content']['content']['html_code'] = '<div>...</div>';
```

### CSS Code
```php
$element['data']['properties']['content']['content']['css_code'] = '.class { }';
```

### JavaScript Code
```php
$element['data']['properties']['content']['content']['javascript_code'] = 'window.fn = function() {}';
```
