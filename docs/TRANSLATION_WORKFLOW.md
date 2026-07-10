# Translation Workflow

Oxygen HTML Converter uses the `oxygen-html-converter` text domain and keeps translation templates in `languages/`.

## Runtime Loading

The plugin header declares:

- `Text Domain: oxygen-html-converter`
- `Domain Path: /languages`

The bootstrap also calls `load_plugin_textdomain()` on `plugins_loaded`. WordPress 6.5+ can auto-load many plugin translations, but the explicit call keeps bundled translation files under `/languages` predictable for local, ZIP, and non-wp.org installs.

## String Rules

- Wrap user-facing PHP strings with `__()`, `esc_html__()`, `esc_attr__()`, or the matching echo helper.
- Always pass `oxygen-html-converter` as the text domain.
- Use translator comments before placeholder strings.
- Keep machine keys, handles, option names, CSS values, and Oxygen schema identifiers untranslated.
- Do not edit `src/Services/*` as part of Worker B i18n changes; report any findings there separately.

## Generate the POT

Preferred local command when WP-CLI is available:

```bash
composer i18n:make-pot
```

Equivalent npm command:

```bash
npm run i18n:make-pot
```

Windows Docker fallback:

```bash
composer i18n:make-pot:docker
```

On macOS/Linux, use the same WP-CLI Docker image with a POSIX volume path:

```bash
docker run --rm -v "$(pwd):/app" -w /app wordpress:cli wp i18n make-pot . languages/oxygen-html-converter.pot --domain=oxygen-html-converter --exclude=node_modules,vendor,tests,tests/live,artifacts,.git,.worktrees --allow-root
```

After generation, confirm the file exists at `languages/oxygen-html-converter.pot` and review the entry count before release.
