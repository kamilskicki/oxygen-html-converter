# Agentic Development Guide

This project is the oxygen-html-converter, a PHP library/plugin for converting HTML content to Oxygen Builder JSON format.

## Knowledge Base (KBAI)

The `KBAI/` folder contains comprehensive documentation for AI agents. **Always consult KBAI before making changes.**

### Quick Navigation

| Need To... | Load This File |
|------------|----------------|
| Understand codebase | [KBAI/01-architecture/overview.md](KBAI/01-architecture/overview.md) |
| Understand data flow | [KBAI/01-architecture/data-flow.md](KBAI/01-architecture/data-flow.md) |
| Look up Oxygen elements | [KBAI/02-oxygen-reference/elements.md](KBAI/02-oxygen-reference/elements.md) |
| Work with interactions | [KBAI/02-oxygen-reference/interactions.md](KBAI/02-oxygen-reference/interactions.md) |
| Understand design properties | [KBAI/02-oxygen-reference/design-properties.md](KBAI/02-oxygen-reference/design-properties.md) |
| Work on a service | [KBAI/03-services/INDEX.md](KBAI/03-services/INDEX.md) |
| Write/fix tests | [KBAI/04-testing/guide.md](KBAI/04-testing/guide.md) |
| Fix bugs | [KBAI/05-issues/INDEX.md](KBAI/05-issues/INDEX.md) |
| Fix PHP 7.4 issues | [KBAI/05-issues/php-compatibility.md](KBAI/05-issues/php-compatibility.md) |
| Work with WindPress | [KBAI/06-windpress/integration.md](KBAI/06-windpress/integration.md) |
| Follow workflows | [KBAI/07-workflows/INDEX.md](KBAI/07-workflows/INDEX.md) |

### KBAI Structure

```
KBAI/
├── INDEX.md                    # Start here - main navigation
├── 01-architecture/            # Codebase structure and data flow
│   ├── overview.md
│   └── data-flow.md
├── 02-oxygen-reference/        # Oxygen Builder JSON structure
│   ├── elements.md             # Element types and properties
│   ├── interactions.md         # Event handlers and triggers
│   └── design-properties.md    # CSS to Oxygen property mapping
├── 03-services/                # Service class documentation
│   └── INDEX.md
├── 04-testing/                 # Testing guide and patterns
│   └── guide.md
├── 05-issues/                  # Known bugs and fixes
│   ├── INDEX.md                # Priority-ordered issue list
│   └── php-compatibility.md    # PHP 7.4 polyfills
├── 06-windpress/               # WindPress integration
│   └── integration.md
└── 07-workflows/               # Development workflows
    └── INDEX.md
```

---

## Project Structure

- **src/**: Main source code with PSR-4 namespace `OxyHtmlConverter\`
  - **Services/**: Service classes (CssParser, JavaScriptTransformer, etc.)
  - **Report/**: Conversion reporting classes
- **tests/**: PHPUnit tests with namespace `OxyHtmlConverter\Tests\`
  - **Unit/**: Unit tests
  - **TestCase.php**: Base test class
- **assets/**: Frontend assets (JavaScript, CSS)
- **KBAI/**: Knowledge Base for AI agents

## Build & Dependencies

- **Dependency Manager**: Composer
- **PHP Version**: >= 7.4 (but has PHP 8.0+ bugs - see issues)
- **Install Dependencies**: `composer install`

## Testing

Tests are written using **PHPUnit** with strict configuration (fail on warning/risky).

```bash
# Run all tests
vendor/bin/phpunit

# Run specific test suite
vendor/bin/phpunit --testsuite Unit

# Run specific test file
vendor/bin/phpunit tests/Unit/Services/CssParserTest.php

# Run specific test method
vendor/bin/phpunit --filter testParseCss

# Run with coverage
vendor/bin/phpunit --coverage-html coverage/
```

### Test Configuration
- PHPUnit 9.5+ with strict mode enabled
- `beStrictAboutOutputDuringTests="true"` - No echo/print in code
- `failOnRisky="true"` - Tests must have assertions
- `failOnWarning="true"` - Warnings fail tests

**See:** [KBAI/04-testing/guide.md](KBAI/04-testing/guide.md) for complete testing documentation.

## Code Style & Conventions

### Standard
- Follow **PSR-12** coding standards
- No linting tools configured (no phpcs, phpstan, or psalm)

### Imports & Namespaces
- PSR-4 autoloading: `OxyHtmlConverter\` → `src/`, `OxyHtmlConverter\Tests\` → `tests/`
- Use `use` statements for all class imports (including DOM classes like `use DOMElement;`)
- Order: namespace declaration, use statements, class definition

### Typing
- **Strong typing** for all properties and return types (PHP 7.4+ features)
- Use nullable types: `?Plugin`, `?FrameworkDetector`
- **AVOID union types** (`int|false`) - requires PHP 8.0+. Use PHPDoc instead.
- **AVOID `str_starts_with()`** - requires PHP 8.0+. Use `strpos($str, $prefix) === 0`
- Type hints on all method parameters and return values
- No `declare(strict_types=1);` declarations

### Naming Conventions
- **Classes**: PascalCase (`CssParser`, `JavaScriptTransformer`, `ElementMapper`)
- **Methods**: camelCase (`parse`, `transformJavaScriptForOxygen`, `extractBodyContent`)
- **Variables**: camelCase (`$html`, `$element`, `$customClasses`)
- **Constants**: UPPER_CASE with snake_case keys (`TAG_MAP`, `EVENT_TO_TRIGGER_MAP`)
- **Private methods**: camelCase
- **Boolean return methods**: `is...`, `has...`, `should...`

### Error Handling
- **DOM parsing**: Suppress libxml errors with `libxml_use_internal_errors(true)`, collect errors, restore error state
- Return `null` on parsing failures (e.g., `public function parse(string $html): ?DOMElement`)
- Collect errors in arrays rather than throwing exceptions (`private array $errors = []`)
- Use `trim()` and check `empty()` before processing strings

### Code Structure
- **PHPDoc comments** on all classes and public methods
- **Private helper methods** for internal logic
- **Singleton pattern** used in `Plugin::getInstance()` with `private static ?Plugin $instance`
- **Constructor injection** for optional dependencies (`public function __construct(?FrameworkDetector $frameworkDetector = null)`)
- **Service classes** for specific domains (CssParser, JavaScriptTransformer, InteractionDetector, etc.)
- **Constants arrays** for mapping data (e.g., `TAG_MAP`, `EVENT_TO_TRIGGER_MAP`, `STYLE_MAP`)
- **Avoid side effects** in constructors - defer to initialization methods when needed

### DOM Manipulation Patterns
- Use `DOMDocument` and `DOMElement` from built-in PHP DOM extension
- Load HTML with flags: `LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING`
- Pre-process special characters in attributes (e.g., `@` → `data-oxy-at-`)
- Wrap HTML fragments in full document structure: `<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>...</body></html>`
- Skip whitespace-only text nodes, comments, and specific tags (meta, noscript)

### Testing Patterns
- Extend `OxyHtmlConverter\Tests\TestCase` which extends PHPUnit base class
- Use `setUp()` to initialize test instances: `private CssParser $parser;`
- Use data providers with descriptive keys: `'Simple function declaration' => [...]`
- Test naming: `test{MethodName}` (e.g., `testParseBasicRules`)
- Use `assertInstanceOf`, `assertEquals`, `assertCount`, `assertMatchesRegularExpression` assertions
- Mockery available for mocking (called in `tearDown()`)

## Agent Operational Rules

1. **Consult KBAI first** - Load relevant KBAI files before making changes.
2. **Check for critical bugs** - Review [KBAI/05-issues/INDEX.md](KBAI/05-issues/INDEX.md) for known issues.
3. **Before modifying code** - Search for existing tests covering the functionality. Create tests if none exist.
4. **After changes** - Run relevant tests to prevent regressions.
5. **Use absolute paths** for all file operations (read/write).
6. **Read files before editing** to understand context.
7. **Respect existing abstractions** - don't introduce new libraries without permission.
8. **Keep methods focused** - prefer small, single-purpose methods.
9. **PHPDoc maintenance** - keep class/method documentation up to date.
10. **PHP 7.4 compatibility** - Avoid PHP 8.0+ features. See [KBAI/05-issues/php-compatibility.md](KBAI/05-issues/php-compatibility.md).

## Key File Reference

| Task | Primary File | KBAI Reference |
|------|--------------|----------------|
| Element mapping | `src/ElementMapper.php` | [02-oxygen-reference/elements.md](KBAI/02-oxygen-reference/elements.md) |
| Event handling | `src/Services/InteractionDetector.php` | [02-oxygen-reference/interactions.md](KBAI/02-oxygen-reference/interactions.md) |
| CSS parsing | `src/Services/CssParser.php` | [02-oxygen-reference/design-properties.md](KBAI/02-oxygen-reference/design-properties.md) |
| JS transformation | `src/Services/JavaScriptTransformer.php` | [01-architecture/data-flow.md](KBAI/01-architecture/data-flow.md) |
| Class handling | `src/Services/ClassStrategyService.php` | [06-windpress/integration.md](KBAI/06-windpress/integration.md) |
| Main conversion | `src/TreeBuilder.php` | [01-architecture/overview.md](KBAI/01-architecture/overview.md) |

## Security

- Do not commit secrets to repository
- Validate inputs before processing HTML/XML
- When outputting in WordPress context, use `esc_html`, `esc_url`, `wp_kses`

## Version Control

- Write clear, concise commit messages explaining the "why" not just "what"
- Never force push to main/master
- Only amend commits if safe (HEAD created by you, not pushed to remote)
