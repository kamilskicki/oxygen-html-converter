# Oxygen HTML Converter

Convert HTML to native Oxygen Builder 6 elements. Import entire HTML pages and edit them natively in the builder.

## Features

- **Full HTML Page Import**: Paste complete HTML source code and convert to Oxygen elements
- **Smart Element Mapping**: Automatically maps HTML tags to appropriate Oxygen elements
- **Style Extraction**: Extracts inline CSS and converts to Oxygen properties
- **Class Preservation**: Preserves all CSS classes in Oxygen's native format
- **ID Preservation**: Maintains HTML IDs for JavaScript functionality
- **Custom Attributes**: Preserves data-*, aria-*, and other attributes
- **Interactive Elements**: Converts event handlers (onclick, onmouseenter, etc.) to Oxygen Interactions
- **JavaScript Support**: Transforms functions to be compatible with Oxygen's interaction system
- **Builder Integration**: Direct paste support in Oxygen Builder canvas
- **Admin Interface**: Standalone conversion tool in WordPress admin

## Installation

1. Upload the `oxygen-html-converter` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Requires Oxygen Builder 6 to be active

## Usage

### Method 1: Admin Interface

1. Go to **Tools → Oxygen HTML Converter** (or **Oxygen → HTML Converter**)
2. Paste your HTML in the input area
3. Click **Preview** to see conversion summary
4. Click **Convert** to generate Oxygen JSON
5. Click **Copy to Clipboard**
6. In Oxygen Builder, press `Ctrl+V` to paste

### Method 2: Direct Paste in Builder

1. Copy HTML from any source
2. In Oxygen Builder canvas, press `Ctrl+V`
3. The plugin automatically detects HTML and converts it
4. Elements appear on canvas

### Method 3: Import Modal

1. In Oxygen Builder, press `Ctrl+Shift+H`
2. Paste HTML in the modal
3. Click **Import**
4. Press `Ctrl+V` to paste converted elements

## Element Mapping

| HTML Element | Oxygen Element |
|--------------|----------------|
| `<div>`, `<section>`, `<article>`, `<header>`, `<footer>`, `<nav>`, `<aside>`, `<main>` | Container |
| `<p>`, `<h1>`-`<h6>`, `<span>`, `<blockquote>` | Text |
| `<ul>`, `<ol>`, `<table>` | Rich Text |
| `<a>` | Text Link |
| `<img>` | Image |
| `<iframe>`, `<svg>`, `<form>` | HTML Code |
| `<video>` | HTML Code |

## How It Works

### Element Conversion
HTML elements are mapped to their closest Oxygen equivalents. Container elements (`div`, `section`, etc.) become Oxygen Containers. Text elements (`p`, `h1-h6`, `span`) become Oxygen Text elements.

### Class & ID Handling
- Classes are stored in `settings.advanced.classes` (array format)
- IDs are stored in `settings.advanced.id`
- Both are fully editable in Oxygen Builder's Advanced panel

### Interaction Conversion
Event handlers like `onclick="toggleMenu()"` are automatically converted to Oxygen's native Interaction system:
- The event (click, mouseenter, etc.) becomes an Oxygen trigger
- The function call becomes a `javascript_function` action
- Function arguments are preserved via data attributes

### JavaScript Transformation
Functions in `<script>` tags are automatically transformed:
```javascript
// Original
function toggleMenu() { ... }

// Transformed (accessible by Oxygen Interactions)
window.toggleMenu = function(event, target, action) { ... }
```

## Limitations

- **External CSS**: Classes are imported by name but styles from external stylesheets are not extracted
- **Complex JavaScript**: Regex-based parsing works for standard patterns; complex code may need manual adjustment
- **Framework Code**: Alpine.js, Vue, React etc. directives are preserved but not converted
- **Complex Forms**: Form elements become HTML Code blocks (not native Oxygen forms)
- **Media Library**: Images use URL mode, not WordPress Media Library

See [ROADMAP.md](ROADMAP.md) for planned improvements.

## Compatibility

- WordPress 5.0+
- PHP 7.4+
- Oxygen Builder 6.x

## Works With

This plugin is designed to work alongside the **Oxygen Converter Pasting Plugin** which handles:
- Class ID remapping
- Inline property extraction
- Class deduplication

## Changelog

### 1.1.0 (Development)
- Added event handler → Oxygen Interaction conversion
- Added JavaScript function transformation for Oxygen compatibility
- Added HTML ID preservation
- Added custom attribute preservation (data-*, aria-*, etc.)
- Added argument passing via data attributes for interaction functions
- Fixed class preservation using correct Oxygen property path
- Fixed PHP `empty("0")` bug causing argument 0 to be skipped

### 1.0.0
- Initial release
- HTML to Oxygen JSON conversion
- Admin interface
- Builder integration
- Style extraction

## Documentation

- [README.md](README.md) - This file
- [ROADMAP.md](ROADMAP.md) - Development roadmap and future plans
- [CONVERSION-RESEARCH.md](CONVERSION-RESEARCH.md) - Technical research notes

## License

GPL v2 or later
