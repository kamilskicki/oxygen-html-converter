# Roadmap

## Vision

A robust, widely-adopted tool that reliably converts HTML templates to native Oxygen Builder 6 elements, handling the majority of real-world use cases while gracefully degrading for edge cases.

---

## Current Status (v1.1)

### What Works
- HTML → Oxygen element mapping (all common tags)
- CSS class and ID preservation
- Custom attributes (`data-*`, `aria-*`) preservation
- Inline style extraction and conversion
- Event handlers (`onclick`, `onmouseenter`, etc.) → Oxygen Interactions
- JavaScript function transformation
- Tailwind CSS detection (WindPress integration)
- CSS Grid detection
- Animation and transition detection
- Framework detection (Alpine.js, Vue, React, HTMX)
- Conversion reporting with warnings
- Output validation

---

## Planned

### Tier 1 — Robustness (Target: 95% of static templates)

- **Event handler improvements** — support all standard event types, multiple handlers per event, inline code handlers, `this` references
- **JavaScript transformation** — arrow functions, async functions, ES6 class methods, IIFEs, scope-aware transformation
- **Argument handling** — multiple arguments, string/object/expression arguments
- **CSS improvements** — CSS custom properties, Tailwind responsive and state prefixes, arbitrary values

### Tier 2 — Intelligence (Target: 85% of interactive templates)

- **Framework partial conversion** — Alpine.js directives to Oxygen interactions where possible
- **Component detection** — identify repeated patterns (cards, navbars) and suggest Oxygen partials
- **Smart fallbacks** — when conversion fails, preserve original with a warning rather than dropping content
- **External stylesheet processing** — fetch and parse linked CSS files

### Tier 3 — Advanced (Long-term)

- **AST-based JavaScript parsing** — replace regex with a proper parser for reliable script transformation
- **Batch processing** — import multiple files, import from URL, import from ZIP
- **CSS → Oxygen global styles** — generate Oxygen global style sets from CSS rules
- **State management detection** — identify simple state patterns and map to Oxygen dynamic data

---

## Success Metrics

| Metric | Current | Tier 1 Target | Tier 2 Target |
|---|---|---|---|
| Static HTML conversion | ~90% | 99% | 99% |
| Tailwind templates | ~80% | 95% | 98% |
| Simple interactivity | ~60% | 90% | 95% |
| Complex interactivity | ~30% | 70% | 85% |
| Framework-based | ~10% | 30% | 60% |

---

## Contributing

Areas where contributions are welcome:

1. **Test templates** — submit HTML that doesn't convert well
2. **Framework conversion strategies** — approaches for Alpine.js, HTMX, etc.
3. **CSS parsing improvements** — edge cases in style extraction
4. **Bug reports** — with sample HTML that reproduces the issue

---

*Last updated: February 2025*
