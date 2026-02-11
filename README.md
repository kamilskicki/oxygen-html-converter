# Oxygen HTML Converter

> **v0.8.0-beta** — Convert HTML to native Oxygen Builder 6 elements.

Paste entire HTML pages and edit them natively in Oxygen — no iframes, no shortcodes, no code blocks.

**Built by [Kamil Skicki](https://kamilskicki.com)** — growth engineer & WordPress specialist.

⚠️ **Beta release** — this plugin is functional and tested, but edge cases exist. Feedback welcome via [GitHub Issues](https://github.com/kamilskicki/oxygen-html-converter/issues).

## Features

### Core Conversion
- **Full HTML page import** → native Oxygen elements
- **Smart element mapping** — tags are mapped to their closest Oxygen equivalents
- **Style extraction** — inline CSS is converted to Oxygen properties
- **Class & ID preservation** — all classes and IDs are carried over

### Interactive & Dynamic
- **Event handler conversion** — `onclick`, `onmouseenter`, etc. → Oxygen Interactions
- **JavaScript transformation** — scripts are rewritten for Oxygen compatibility
- **Framework detection** — Alpine.js, Vue, React, HTMX detected and preserved

### CSS Intelligence
- **Tailwind CSS detection** — classes are preserved; WindPress integration supported
- **CSS Grid detection** — grid layouts are mapped to Oxygen grid settings
- **Animation detection** — CSS animations and transitions are identified
- **Inline style parsing** — comprehensive CSS property → Oxygen property mapping

### Import Methods
- **Admin interface** — Tools → Oxygen HTML Converter (paste & convert)
- **Direct paste in builder** — `Ctrl+V` to paste HTML directly
- **Import modal** — `Ctrl+Shift+H` for the import dialog inside the builder

## Element Mapping

| HTML Element | Oxygen Element |
|---|---|
| `<div>`, `<section>`, `<article>`, `<header>`, `<footer>`, `<main>`, `<nav>`, `<aside>` | Container |
| `<a>` (wrapping block content) | Container Link |
| `<a>` (inline) | Text Link |
| `<p>`, `<h1>`–`<h6>`, `<span>`, `<blockquote>` | Text |
| `<img>` | Image |
| `<video>` | Video |
| `<ul>`, `<ol>` | Container (list) |
| `<li>` | Container (list item) |
| `<form>` | Container (form) |
| `<input>`, `<textarea>`, `<select>`, `<button>` | Form elements |
| `<svg>` | SVG / Icon |
| `<iframe>` | HTML Code |
| `<script>`, `<style>` | Code elements |

## Installation

1. Download the latest release ZIP
2. In WordPress admin, go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP and activate
4. Ensure **Oxygen Builder 6** is installed and active

### Manual Installation

```bash
cd wp-content/plugins/
git clone https://github.com/kamilskicki/oxygen-html-converter.git
cd oxygen-html-converter
composer install --no-dev
```

## Usage

### Method 1: Admin Page
1. Go to **Tools → Oxygen HTML Converter**
2. Paste your HTML into the editor
3. Click **Convert**
4. Copy the generated JSON into an Oxygen page

### Method 2: Direct Paste (in Builder)
1. Open any page in Oxygen Builder 6
2. Copy HTML from your source
3. Press `Ctrl+V` — the plugin intercepts and converts automatically

### Method 3: Import Modal (in Builder)
1. Inside the Oxygen editor, press `Ctrl+Shift+H`
2. Paste HTML into the modal
3. Click **Import** — elements are inserted at your cursor position

## Requirements

- WordPress 5.0+
- PHP 7.4+
- Oxygen Builder 6.x

## Known Limitations (Beta)

- **JavaScript frameworks** (React, Vue, Alpine.js) are detected but not fully converted to Oxygen equivalents — framework attributes are preserved for manual handling
- **External stylesheets** are not fetched or parsed — only inline styles are converted
- **Complex JavaScript** with closures, async patterns, or module imports may need manual adjustment after conversion
- **Web Components** (`<template>`, `<slot>`) are preserved as HTML Code blocks
- **SVG sprites** and complex SVG structures are inserted as raw SVG
- **Class-based styles** — classes are imported by name, but styles from external CSS files are not extracted (use Tailwind/utility-first for best results)

## Roadmap to v1.0

- [ ] External CSS stylesheet fetching & parsing
- [ ] Batch import (multiple pages)
- [ ] Template library integration
- [ ] WP Media Library image import
- [ ] Full test suite

See [ROADMAP.md](ROADMAP.md) for the full development plan.

## Compatibility

- **Oxygen Builder 6.x** (required)
- **WordPress 5.0+**
- **PHP 7.4 – 8.3**
- **Tailwind CSS** — classes preserved, WindPress integration supported
- **Bootstrap** — classes preserved
- **Alpine.js, HTMX** — attributes detected and preserved

## Contributing

This is a beta release. Bug reports, feature requests, and pull requests are welcome.

1. Fork the repo
2. Create your feature branch (`git checkout -b feature/my-feature`)
3. Commit your changes
4. Push and open a Pull Request

## License

GPL v2 or later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html)

## Author

**[Kamil Skicki](https://kamilskicki.com)** — Growth engineer specializing in WordPress, AI-powered marketing, and conversion optimization.

- 🌐 [kamilskicki.com](https://kamilskicki.com)
- 🐙 [github.com/kamilskicki](https://github.com/kamilskicki)
