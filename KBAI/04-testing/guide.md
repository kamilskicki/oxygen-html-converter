# Testing Guide

## Quick Reference

```bash
# Run all tests
vendor/bin/phpunit

# Run specific test suite
vendor/bin/phpunit --testsuite Unit

# Run specific test file
vendor/bin/phpunit tests/Unit/Services/CssParserTest.php

# Run specific test method
vendor/bin/phpunit --filter testParseBasicRules

# Run with verbose output
vendor/bin/phpunit -v

# Run with coverage report
vendor/bin/phpunit --coverage-html coverage/
```

## Test Configuration

**File:** `phpunit.xml`

Key settings:
- `beStrictAboutOutputDuringTests="true"` - No echo/print in tests
- `failOnRisky="true"` - Tests must have assertions
- `failOnWarning="true"` - Warnings fail the test

## Test Structure

```
tests/
├── bootstrap.php           # Autoloader setup
├── TestCase.php            # Base class for all tests
└── Unit/
    ├── TreeBuilderTest.php
    ├── Report/
    │   └── ConversionReportTest.php
    └── Services/
        ├── CssParserTest.php
        ├── JavaScriptTransformerTest.php
        ├── InteractionDetectorTest.php
        ├── FrameworkDetectorTest.php
        ├── EnvironmentServiceTest.php
        └── ComponentDetectorTest.php
```

## Writing Tests

### Basic Test Pattern

```php
<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Tests\TestCase;
use OxyHtmlConverter\Services\MyService;

class MyServiceTest extends TestCase
{
    private MyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MyService();
    }

    public function testBasicFunctionality(): void
    {
        $result = $this->service->process('input');
        
        $this->assertNotNull($result);
        $this->assertEquals('expected', $result);
    }
}
```

### Using Data Providers

```php
/**
 * @dataProvider inputOutputProvider
 */
public function testMultipleCases(string $input, string $expected): void
{
    $result = $this->service->process($input);
    $this->assertEquals($expected, $result);
}

public static function inputOutputProvider(): array
{
    return [
        'Simple case' => ['input1', 'output1'],
        'Edge case' => ['input2', 'output2'],
        'Empty input' => ['', ''],
    ];
}
```

### Testing DOM Operations

```php
protected function createDomElement(string $html): \DOMElement
{
    $doc = new \DOMDocument();
    @$doc->loadHTML(
        '<!DOCTYPE html><html><body>' . $html . '</body></html>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    return $doc->getElementsByTagName('body')->item(0)->firstChild;
}

public function testElementProcessing(): void
{
    $element = $this->createDomElement('<div id="test" class="container"></div>');
    
    $this->assertEquals('test', $element->getAttribute('id'));
    $this->assertEquals('container', $element->getAttribute('class'));
}
```

### Using Mocks (Mockery)

```php
use Mockery;

public function testWithMockedDependency(): void
{
    $mockReport = Mockery::mock(ConversionReport::class);
    $mockReport->shouldReceive('addWarning')
        ->once()
        ->with('Expected warning message');
    
    $service = new MyService($mockReport);
    $service->processWithWarning();
}

protected function tearDown(): void
{
    parent::tearDown();
    Mockery::close();  // Important!
}
```

## Test Coverage Gaps

### Classes Without Tests (Priority Order)

1. **HtmlParser** - Core parsing functionality
2. **ElementMapper** - Element type mapping
3. **StyleExtractor** - Style extraction
4. **TailwindDetector** - Class detection
5. **ClassStrategyService** - Mode handling
6. **IconDetector** - Icon library detection

### Missing Test Scenarios

- Malformed HTML handling
- Empty/whitespace input
- Very large documents
- Unicode content
- Error conditions
- Edge cases in JavaScript transformation

## Common Issues

### "Risky Test" Warning

**Cause:** Test has no assertions

```php
// WRONG
public function testSomething(): void
{
    $service->doSomething();  // No assertion!
}

// FIX
public function testSomething(): void
{
    $result = $service->doSomething();
    $this->assertNotNull($result);
}
```

### "Output During Test" Warning

**Cause:** echo/print/error_log in code

```php
// WRONG - in service code
error_log('Debug info');

// FIX - remove or wrap
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('Debug info');
}
```

### Mock Not Called

**Cause:** Method name mismatch or mock not injected

```php
// Check the method name matches exactly
$mock->shouldReceive('methodName')  // Must match actual method
    ->once()
    ->andReturn($value);

// Make sure mock is injected
$service = new Service($mock);  // Not new Service()
```

## Integration Tests

For WordPress-dependent tests, use the WordPress test framework:

```php
// tests/Integration/WordPressTest.php
class WordPressTest extends \WP_UnitTestCase
{
    public function testPluginActivation(): void
    {
        // WordPress functions available
        $this->assertTrue(is_plugin_active('oxygen-html-converter/oxygen-html-converter.php'));
    }
}
```

**Note:** Integration tests require WordPress test environment setup.
