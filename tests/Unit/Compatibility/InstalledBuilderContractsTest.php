<?php

namespace OxyHtmlConverter\Tests\Unit\Compatibility;

use OxyHtmlConverter\ElementTypes;
use PHPUnit\Framework\TestCase;

/**
 * Optional live compatibility checks against installed builder plugin sources.
 *
 * These tests auto-skip when local plugin directories are unavailable.
 */
class InstalledBuilderContractsTest extends TestCase
{
    private const CONTRACT_FIXTURE_DIR = 'tests/fixtures/oxygen6-contracts';

    private const CONTRACT_FIXTURES = [
        'page-tree' => 'page-tree.json',
        'selectors' => 'selectors.json',
        'variables' => 'variables.json',
        'global-settings' => 'global-settings.json',
        'template-settings' => 'template-settings.json',
        'block' => 'block.json',
        'component-instance' => 'component-instance.json',
    ];

    private function resolveWorkspaceRoot(): string
    {
        return dirname(__DIR__, 6);
    }

    private function resolveCoreRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function resolveBreakdanceElementsButtonFile(): ?string
    {
        $envDir = getenv('OXY_HTML_CONVERTER_BREAKDANCE_ELEMENTS_DIR');
        $candidates = array_filter([
            $envDir ? rtrim($envDir, "\\/") . DIRECTORY_SEPARATOR . 'elements' . DIRECTORY_SEPARATOR . 'Button' . DIRECTORY_SEPARATOR . 'element.php' : null,
            $this->resolveWorkspaceRoot() . DIRECTORY_SEPARATOR . 'breakdance-elements-for-oxygen' . DIRECTORY_SEPARATOR . 'elements' . DIRECTORY_SEPARATOR . 'Button' . DIRECTORY_SEPARATOR . 'element.php',
        ]);

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveOxygenHtml5VideoFile(): ?string
    {
        $envDir = getenv('OXY_HTML_CONVERTER_OXYGEN_DIR');
        $candidates = array_filter([
            $envDir ? rtrim($envDir, "\\/") . DIRECTORY_SEPARATOR . 'subplugins' . DIRECTORY_SEPARATOR . 'oxygen-elements' . DIRECTORY_SEPARATOR . 'elements' . DIRECTORY_SEPARATOR . 'HTML5_Video' . DIRECTORY_SEPARATOR . 'element.php' : null,
            $this->resolveWorkspaceRoot() . DIRECTORY_SEPARATOR . 'oxygen' . DIRECTORY_SEPARATOR . 'subplugins' . DIRECTORY_SEPARATOR . 'oxygen-elements' . DIRECTORY_SEPARATOR . 'elements' . DIRECTORY_SEPARATOR . 'HTML5_Video' . DIRECTORY_SEPARATOR . 'element.php',
        ]);

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function testBreakdanceEssentialButtonSourceContract(): void
    {
        $file = $this->resolveBreakdanceElementsButtonFile();
        if ($file === null) {
            $this->markTestSkipped('Breakdance Elements for Oxygen source not found.');
        }

        $content = file_get_contents($file);
        $this->assertIsString($content);
        $this->assertStringContainsString('class Button extends \\Breakdance\\Elements\\Element', $content);
        $this->assertStringContainsString('content.content.text', $content);
        $this->assertStringContainsString('content.content.link.url', $content);
        $this->assertStringContainsString('availableIn', $content);
        $this->assertStringContainsString("'oxygen'", $content);
    }

    public function testOxygenHtml5VideoSourceContract(): void
    {
        $file = $this->resolveOxygenHtml5VideoFile();
        if ($file === null) {
            $this->markTestSkipped('Oxygen HTML5 Video element source not found.');
        }

        $content = file_get_contents($file);
        $this->assertIsString($content);
        $this->assertStringContainsString('OxygenElements\\\\Html5Video', $content);
        $this->assertStringContainsString('video_file_url', $content);
    }

    public function testOxygenSixContractFixtureBaselineIsComplete(): void
    {
        $fixtureDir = $this->resolveCoreRoot() . DIRECTORY_SEPARATOR . self::CONTRACT_FIXTURE_DIR;
        $this->assertDirectoryExists($fixtureDir);

        foreach (self::CONTRACT_FIXTURES as $contract => $fileName) {
            $fixture = $this->loadContractFixture($fileName);

            $this->assertSame($contract, $fixture['contract'] ?? null, $fileName);
            $this->assertSame('6.1.0-beta.1', $fixture['oxygenVersion'] ?? null, $fileName);
            $this->assertNotEmpty($fixture['sourceFiles'] ?? [], $fileName);
            $this->assertIsArray($fixture['payload'] ?? null, $fileName);
        }
    }

    public function testOxygenSixTreeFixturesDecodeWithoutWordPress(): void
    {
        foreach (['page-tree.json', 'template-settings.json', 'block.json'] as $fileName) {
            $payload = $this->loadContractFixture($fileName)['payload'];
            $tree = $this->decodeOxygenDataTree($payload, $fileName);

            $this->assertArrayHasKey('root', $tree, $fileName);
            $this->assertArrayHasKey('_nextNodeId', $tree, $fileName);
            $this->assertArrayHasKey('exportedLookupTable', $tree, $fileName);
            $this->assertTreeNodeShape($tree['root'], $fileName . ':root');
        }
    }

    public function testOxygenSixStorageFixturesMatchAuthoritativeContracts(): void
    {
        $selectors = $this->loadContractFixture('selectors.json')['payload'];
        $this->assertSelectorPayloadShape($selectors);

        $variables = $this->loadContractFixture('variables.json')['payload'];
        $this->assertVariablePayloadShape($variables);

        $globalSettings = $this->loadContractFixture('global-settings.json')['payload'];
        $this->assertIsArray($globalSettings['oxygen_global_settings_json_string'] ?? null);
        $this->assertIsArray($globalSettings['oxygen_global_settings_json_string']['settings'] ?? null);

        $template = $this->loadContractFixture('template-settings.json')['payload'];
        $this->assertSame('oxygen_template', $template['post_type'] ?? null);
        $this->assertJson((string) ($template['_oxygen_template_settings'] ?? ''));

        $block = $this->loadContractFixture('block.json')['payload'];
        $this->assertSame('oxygen_block', $block['post_type'] ?? null);
        $this->assertIsArray($block['_breakdance_block_settings'] ?? null);

        $component = $this->loadContractFixture('component-instance.json')['payload']['componentNode'] ?? null;
        $this->assertIsArray($component);
        $this->assertSame('OxygenElements\\Component', $component['data']['type'] ?? null);
        $this->assertComponentBlockPayloadShape($component['data']['properties']['content']['content']['block'] ?? null);
    }

    public function testElementRegistryMatchesOxygenSixComponentContract(): void
    {
        $this->assertTrue(ElementTypes::isValid(ElementTypes::COMPONENT));
        $this->assertFalse(ElementTypes::isValid('OxygenElements\\Header'));
    }

    /**
     * @return array<string, mixed>
     */
    private function loadContractFixture(string $fileName): array
    {
        $file = $this->resolveCoreRoot()
            . DIRECTORY_SEPARATOR
            . self::CONTRACT_FIXTURE_DIR
            . DIRECTORY_SEPARATOR
            . $fileName;

        $this->assertFileExists($file);

        $content = file_get_contents($file);
        $this->assertIsString($content);

        $decoded = json_decode($content, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), $fileName . ': ' . json_last_error_msg());
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function decodeOxygenDataTree(array $payload, string $label): array
    {
        $oxygenData = $payload['_oxygen_data'] ?? null;
        $this->assertIsArray($oxygenData, $label);
        $this->assertIsString($oxygenData['tree_json_string'] ?? null, $label);

        $tree = json_decode($oxygenData['tree_json_string'], true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), $label . ': ' . json_last_error_msg());
        $this->assertIsArray($tree, $label);

        return $tree;
    }

    /**
     * @param mixed $node
     */
    private function assertTreeNodeShape($node, string $label): void
    {
        $this->assertIsArray($node, $label);
        $this->assertIsInt($node['id'] ?? null, $label);
        $this->assertIsArray($node['data'] ?? null, $label);
        $this->assertIsString($node['data']['type'] ?? null, $label);
        $this->assertIsArray($node['data']['properties'] ?? null, $label);
        $this->assertIsArray($node['children'] ?? null, $label);

        foreach ($node['children'] as $index => $child) {
            $this->assertTreeNodeShape($child, $label . '.children.' . $index);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertSelectorPayloadShape(array $payload): void
    {
        $selectors = $payload['oxygen_oxy_selectors_json_string'] ?? null;
        $collections = $payload['oxygen_oxy_selectors_collections_json_string'] ?? null;

        $this->assertIsArray($selectors);
        $this->assertIsArray($collections);
        $this->assertContains('Imported HTML', $collections);

        foreach ($selectors as $selector) {
            $this->assertIsArray($selector);
            $this->assertIsString($selector['id'] ?? null);
            $this->assertIsString($selector['name'] ?? null);
            $this->assertSame('class', $selector['type'] ?? null);
            $this->assertStringStartsNotWith('.', $selector['name']);
            $this->assertIsArray($selector['children'] ?? null);
            $this->assertIsArray($selector['properties'] ?? null);
            $this->assertIsString($selector['collection'] ?? null);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertVariablePayloadShape(array $payload): void
    {
        $variables = $payload['oxygen_variables_json_string'] ?? null;
        $collections = $payload['oxygen_variables_collections_json_string'] ?? null;

        $this->assertIsArray($variables);
        $this->assertIsArray($collections);
        $this->assertContains('Imported HTML', $collections);

        foreach ($variables as $variable) {
            $this->assertIsArray($variable);
            $this->assertIsString($variable['id'] ?? null);
            $this->assertMatchesRegularExpression('/^[A-Za-z_][A-Za-z0-9_-]*$/', $variable['cssVariableName'] ?? '');
            $this->assertArrayHasKey('dynamicData', $variable);
            $this->assertIsString($variable['collection'] ?? null);
        }
    }

    /**
     * @param mixed $block
     */
    private function assertComponentBlockPayloadShape($block): void
    {
        $this->assertIsArray($block);
        $this->assertIsInt($block['componentId'] ?? null);
        $this->assertIsArray($block['targets'] ?? null);
        $this->assertIsArray($block['properties'] ?? null);

        foreach ($block['targets'] as $target) {
            $this->assertIsArray($target);
            $this->assertIsInt($target['nodeId'] ?? null);
            $this->assertIsString($target['propertyKey'] ?? null);
            $this->assertIsString($target['controlPath'] ?? null);
        }
    }
}
