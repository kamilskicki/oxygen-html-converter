# Workflows Index

Development workflows for common tasks.

## Quick Links

| Workflow | When to Use |
|----------|-------------|
| [Feature Development](feature-development.md) | Adding new capabilities |
| [Bug Fixing](bug-fixing.md) | Fixing issues |
| [Adding Element Types](add-element-type.md) | Support new HTML→Oxygen mappings |
| [Adding Event Handlers](add-event-handler.md) | Support new HTML events |
| [Adding Framework Support](add-framework.md) | Detect new JS frameworks |
| [Debugging Conversion](debugging.md) | Troubleshooting conversion issues |

## Standard Development Flow

```
1. Research & Plan
   ├── Read relevant KBAI docs
   ├── Check existing tests
   └── Identify affected files

2. Write Tests First (TDD)
   ├── Create test file if needed
   ├── Write failing test
   └── Verify test fails

3. Implement
   ├── Make minimal changes
   ├── Follow existing patterns
   └── Run tests frequently

4. Verify
   ├── All tests pass
   ├── No new warnings
   └── Manual testing if needed

5. Document
   ├── Update PHPDoc
   ├── Update KBAI if needed
   └── Update CHANGELOG
```

## Pre-Commit Checklist

```bash
# 1. Run all tests
vendor/bin/phpunit

# 2. Check for PHP 7.4 compatibility
# - No str_starts_with(), str_ends_with(), str_contains()
# - No union types (int|false)
# - No named arguments

# 3. Check for debug code
# - No error_log() calls
# - No var_dump() or print_r()
# - No echo statements in classes

# 4. Verify no hardcoded values
# - Use constants for magic strings
# - Use configuration for URLs/paths
```

## File Naming Conventions

| Type | Pattern | Example |
|------|---------|---------|
| Service | `{Name}Service.php` | `ClassStrategyService.php` |
| Detector | `{What}Detector.php` | `TailwindDetector.php` |
| Parser | `{What}Parser.php` | `CssParser.php` |
| Transformer | `{What}Transformer.php` | `JavaScriptTransformer.php` |
| Test | `{ClassName}Test.php` | `CssParserTest.php` |

## Key Files to Know

| Task | Primary File | Secondary Files |
|------|--------------|-----------------|
| Element mapping | `ElementMapper.php` | `TreeBuilder.php` |
| Event handling | `InteractionDetector.php` | `TreeBuilder.php` |
| CSS parsing | `CssParser.php` | `StyleExtractor.php` |
| JS transformation | `JavaScriptTransformer.php` | `TreeBuilder.php` |
| Class handling | `ClassStrategyService.php` | `TailwindDetector.php` |
| Framework detection | `FrameworkDetector.php` | `InteractionDetector.php` |
| Icon detection | `IconDetector.php` | `TreeBuilder.php` |
