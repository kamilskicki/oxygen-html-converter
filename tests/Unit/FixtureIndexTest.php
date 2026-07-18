<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Tests\Unit;

use OxyHtmlConverter\ElementTypes;
use OxyHtmlConverter\TreeBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class FixtureIndexTest extends TestCase
{
    private mixed $previousClassMode;

    protected function setUp(): void
    {
        parent::setUp();

        remove_all_filters();
        $this->previousClassMode = $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] ?? null;
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';
    }

    protected function tearDown(): void
    {
        if ($this->previousClassMode === null) {
            unset($GLOBALS['__wp_options']['oxy_html_converter_class_mode']);
        } else {
            $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = $this->previousClassMode;
        }

        remove_all_filters();
        parent::tearDown();
    }

    public function testFixtureIndexListsAllStableHtmlFixturesAndGapCoverage(): void
    {
        $coreRoot = self::coreRoot();
        $fixtureRoot = $coreRoot . '/fixtures/html';
        $indexPath = $fixtureRoot . '/fixture-index.json';

        $this->assertFileExists($indexPath);

        $index = json_decode((string) file_get_contents($indexPath), true);
        $this->assertIsArray($index);
        $this->assertSame(1, $index['version'] ?? null);

        $stableFixtures = $index['stableHtmlFixtures'] ?? null;
        $this->assertIsArray($stableFixtures);
        $this->assertNotEmpty($stableFixtures);

        $indexedHtml = [];
        $coveredGapIds = [];
        foreach ($stableFixtures as $entry) {
            $this->assertFixtureIndexEntryShape($entry);

            $fixture = (string) $entry['fixture'];
            $path = $fixtureRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $fixture);
            $this->assertFileExists($path, $fixture);
            $indexedHtml[] = str_replace('\\', '/', $fixture);

            foreach ($entry['gapIds'] as $gapId) {
                $coveredGapIds[(string) $gapId] = true;
            }
        }

        sort($indexedHtml);
        $actualHtml = $this->discoverHtmlFixtures($fixtureRoot);
        $this->assertSame($actualHtml, $indexedHtml);

        $supportingFixtures = $index['supportingFixtures'] ?? null;
        $this->assertIsArray($supportingFixtures);
        foreach ($supportingFixtures as $entry) {
            $this->assertFixtureIndexEntryShape($entry);

            $fixture = (string) $entry['fixture'];
            $this->assertFileExists($coreRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $fixture), $fixture);

            foreach ($entry['gapIds'] as $gapId) {
                $coveredGapIds[(string) $gapId] = true;
            }
        }

        $requiredGapIds = $index['requiredGapIds'] ?? null;
        $this->assertIsArray($requiredGapIds);
        $this->assertCount(40, $requiredGapIds);
        foreach ($requiredGapIds as $gapId) {
            $this->assertArrayHasKey((string) $gapId, $coveredGapIds, (string) $gapId);
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[DataProvider('stableHtmlFixtureProvider')]
    public function testStableHtmlFixtureConvertsAgainstIndexedContract(array $entry): void
    {
        $fixture = (string) $entry['fixture'];
        $path = self::fixtureRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fixture);
        $html = file_get_contents($path);
        $this->assertIsString($html, $fixture);

        $result = (new TreeBuilder())->convert($html);
        $this->assertTrue((bool) ($result['success'] ?? false), $fixture);

        $expected = is_array($entry['expected'] ?? null) ? $entry['expected'] : [];
        $expectedCodeBlocks = is_array($expected['codeBlocks'] ?? null) ? $expected['codeBlocks'] : [];
        $visibleTypes = $this->countVisibleElementTypes($result);

        $this->assertSame([
            'total' => (int) ($expectedCodeBlocks['total'] ?? 0),
            'html' => (int) ($expectedCodeBlocks['html'] ?? 0),
            'css' => (int) ($expectedCodeBlocks['css'] ?? 0),
            'javascript' => (int) ($expectedCodeBlocks['javascript'] ?? 0),
        ], $this->codeBlockCounts($visibleTypes), $fixture);

        $this->assertSame(
            (int) ($expected['fallbackCount'] ?? 0),
            $this->countFallbackRoutes($result),
            $fixture
        );

        $unsupportedItems = is_array($result['stats']['unsupportedItems'] ?? null)
            ? $result['stats']['unsupportedItems']
            : [];
        $this->assertCount((int) ($expected['unsupportedCount'] ?? 0), $unsupportedItems, $fixture);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function stableHtmlFixtureProvider(): iterable
    {
        $index = self::loadFixtureIndex();

        foreach ($index['stableHtmlFixtures'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            yield (string) ($entry['fixture'] ?? 'fixture') => [$entry];
        }
    }

    /**
     * @param mixed $entry
     */
    private function assertFixtureIndexEntryShape($entry): void
    {
        $this->assertIsArray($entry);
        $this->assertIsString($entry['fixture'] ?? null);
        $this->assertNotSame('', trim((string) $entry['fixture']));

        $this->assertIsArray($entry['gapIds'] ?? null);
        $this->assertNotEmpty($entry['gapIds']);

        $expected = $entry['expected'] ?? null;
        $this->assertIsArray($expected);
        $codeBlocks = $expected['codeBlocks'] ?? null;
        $this->assertIsArray($codeBlocks);
        foreach (['total', 'html', 'css', 'javascript'] as $key) {
            $this->assertArrayHasKey($key, $codeBlocks);
            $this->assertIsInt($codeBlocks[$key]);
        }

        foreach (['fallbackCount', 'unsupportedCount'] as $key) {
            $this->assertArrayHasKey($key, $expected);
            $this->assertIsInt($expected[$key]);
        }

        $liveSmoke = $entry['liveSmoke'] ?? null;
        $this->assertIsArray($liveSmoke);
        $this->assertIsBool($liveSmoke['required'] ?? null);
        $this->assertIsString($liveSmoke['status'] ?? null);
        $this->assertNotSame('', trim((string) $liveSmoke['status']));
    }

    /**
     * @return list<string>
     */
    private function discoverHtmlFixtures(string $fixtureRoot): array
    {
        $fixtures = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureRoot));

        foreach ($iterator as $item) {
            if (!($item instanceof SplFileInfo) || !$item->isFile()) {
                continue;
            }

            if (strtolower($item->getExtension()) !== 'html') {
                continue;
            }

            $fixtures[] = str_replace('\\', '/', substr($item->getPathname(), strlen($fixtureRoot) + 1));
        }

        sort($fixtures);

        return $fixtures;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadFixtureIndex(): array
    {
        $json = file_get_contents(self::fixtureRoot() . '/fixture-index.json');
        $index = is_string($json) ? json_decode($json, true) : null;

        return is_array($index) ? $index : [];
    }

    private static function fixtureRoot(): string
    {
        return self::coreRoot() . '/fixtures/html';
    }

    private static function coreRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, int>
     */
    private function countVisibleElementTypes(array $result): array
    {
        $types = [];
        if (is_array($result['element'] ?? null)) {
            $this->countElementTypeInNode($result['element'], $types);
        }

        foreach (['headLinkElements', 'headScriptElements', 'iconScriptElements'] as $key) {
            if (empty($result[$key])) {
                continue;
            }

            $items = isset($result[$key]['data']) ? [$result[$key]] : $result[$key];
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (is_array($item)) {
                    $this->countElementTypeInNode($item, $types);
                }
            }
        }

        return $types;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, int> $types
     */
    private function countElementTypeInNode(array $node, array &$types): void
    {
        $type = $node['data']['type'] ?? null;
        if (is_string($type)) {
            $types[$type] = ($types[$type] ?? 0) + 1;
        }

        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->countElementTypeInNode($child, $types);
            }
        }
    }

    /**
     * @param array<string, int> $types
     * @return array{total:int, html:int, css:int, javascript:int}
     */
    private function codeBlockCounts(array $types): array
    {
        $html = $types[ElementTypes::HTML_CODE] ?? 0;
        $css = $types[ElementTypes::CSS_CODE] ?? 0;
        $javascript = $types[ElementTypes::JAVASCRIPT_CODE] ?? 0;

        return [
            'total' => $html + $css + $javascript,
            'html' => $html,
            'css' => $css,
            'javascript' => $javascript,
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function countFallbackRoutes(array $result): int
    {
        $styleRouting = is_array($result['styleRouting'] ?? null) ? $result['styleRouting'] : [];
        $routes = is_array($styleRouting['routes'] ?? null) ? $styleRouting['routes'] : [];
        $count = 0;

        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }

            if (str_contains((string) ($route['type'] ?? ''), 'fallback')) {
                $count++;
            }
        }

        return $count;
    }
}
