<?php

namespace OxyHtmlConverter\Tests\Unit\Compatibility;

use OxyHtmlConverter\ElementTypes;
use OxyHtmlConverter\Services\OxygenStorageContract;
use PHPUnit\Framework\TestCase;

/**
 * Optional live compatibility checks against installed builder plugin sources.
 *
 * These tests auto-skip when local plugin directories are unavailable.
 */
class InstalledBuilderContractsTest extends TestCase
{
    private const CONTRACT_FIXTURE_DIR = 'tests/fixtures/oxygen6-contracts';

    private const OXYGEN_CONTRACT_VERSION = '6.1.0';

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
        $configuredOxygenDir = getenv('OXY_HTML_CONVERTER_OXYGEN_DIR');
        if (is_string($configuredOxygenDir) && trim($configuredOxygenDir) !== '') {
            return dirname(rtrim($configuredOxygenDir, "\\/"));
        }

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
            $this->assertSame(self::OXYGEN_CONTRACT_VERSION, $fixture['oxygenVersion'] ?? null, $fileName);
            $this->assertNotEmpty($fixture['sourceFiles'] ?? [], $fileName);
            $this->assertIsArray($fixture['payload'] ?? null, $fileName);

            if ($contract === 'block') {
                $this->assertContains('oxygen/plugin/blocks/ajax_save_block.php', $fixture['sourceFiles']);
                $this->assertContains('oxygen/subplugins/oxygen-elements/elements/CSS_Code/element.php', $fixture['sourceFiles']);
            }

            if ($contract === 'component-instance') {
                $this->assertContains('oxygen/plugin/breakdance-oxygen/components.php', $fixture['sourceFiles']);
                $this->assertContains('oxygen/subplugins/oxygen-elements/elements/Component/ssr.php', $fixture['sourceFiles']);
            }
        }
    }

    public function testRuntimePackagedOxygenSixContractsMatchAuthoritativeFixtures(): void
    {
        $runtimeFixtureDir = OxygenStorageContract::defaultFixtureDirectory();
        $this->assertDirectoryExists($runtimeFixtureDir);

        foreach (self::CONTRACT_FIXTURES as $fileName) {
            $authoritativeFile = $this->resolveCoreRoot()
                . DIRECTORY_SEPARATOR
                . self::CONTRACT_FIXTURE_DIR
                . DIRECTORY_SEPARATOR
                . $fileName;
            $runtimeFile = $runtimeFixtureDir . DIRECTORY_SEPARATOR . $fileName;

            $this->assertFileExists($runtimeFile);
            $this->assertFileEquals($authoritativeFile, $runtimeFile, $fileName);
        }
    }

    public function testListedOxygenSourceFilesExistAndExposeComponentContracts(): void
    {
        $oxygenPlugin = $this->resolveWorkspaceRoot()
            . DIRECTORY_SEPARATOR
            . 'oxygen'
            . DIRECTORY_SEPARATOR
            . 'plugin.php';

        if (!is_file($oxygenPlugin)) {
            $this->markTestSkipped('Oxygen source checkout not found.');
        }

        $pluginSource = file_get_contents($oxygenPlugin);
        $this->assertIsString($pluginSource);
        $this->assertStringContainsString('Version: ' . self::OXYGEN_CONTRACT_VERSION, $pluginSource);

        foreach (['block.json', 'component-instance.json'] as $fileName) {
            $fixture = $this->loadContractFixture($fileName);
            foreach ($fixture['sourceFiles'] as $sourceFile) {
                $this->assertIsString($sourceFile);
                $this->assertFileExists($this->resolveWorkspaceSourceFile($sourceFile), $sourceFile);
            }
        }

        $constants = $this->sourceFileContents('oxygen/plugin/themeless/constants.php');
        $this->assertStringContainsString("define('BREAKDANCE_BLOCK_POST_TYPE', 'oxygen_block')", $constants);

        $blockSave = $this->sourceFileContents('oxygen/plugin/blocks/ajax_save_block.php');
        $this->assertStringContainsString('function saveGlobalBlock', $blockSave);
        $this->assertStringContainsString("__bdox('_meta_prefix') . 'data'", $blockSave);
        $this->assertStringContainsString('_breakdance_block_settings', $blockSave);
        $this->assertStringContainsString('generateCacheForPost', $blockSave);

        $componentElement = $this->sourceFileContents('oxygen/subplugins/oxygen-elements/elements/Component/element.php');
        $this->assertStringContainsString('component_chooser', $componentElement);
        $this->assertStringContainsString('inlineEditableBlockPath', $componentElement);
        $this->assertStringContainsString('content.content.block.componentId', $componentElement);

        $componentSsr = $this->sourceFileContents('oxygen/subplugins/oxygen-elements/elements/Component/ssr.php');
        $this->assertStringContainsString("content']['content']['block", $componentSsr);
        $this->assertStringContainsString('renderGlobalBlock', $componentSsr);

        $componentRuntime = $this->sourceFileContents('oxygen/plugin/breakdance-oxygen/components.php');
        $this->assertStringContainsString('@psalm-type ComponentTarget', $componentRuntime);
        $this->assertStringContainsString('targets', $componentRuntime);
        $this->assertStringContainsString('properties', $componentRuntime);
        $this->assertStringContainsString('editableProperties', $componentRuntime);
        $this->assertStringContainsString('assignArrayByPath', $componentRuntime);

        $cssCode = $this->sourceFileContents('oxygen/subplugins/oxygen-elements/elements/CSS_Code/element.php');
        $this->assertStringContainsString('OxygenElements\\\\CssCode', $cssCode);
        $this->assertStringContainsString('css_code', $cssCode);

        $svgIconElement = $this->sourceFileContents('oxygen/subplugins/oxygen-elements/elements/SVG_Icon/element.php');
        $this->assertStringContainsString('OxygenElements\\\\SvgIcon', $svgIconElement);

        $textLinkElement = $this->sourceFileContents('oxygen/subplugins/oxygen-elements/elements/Text_Link/element.php');
        $this->assertStringContainsString('OxygenElements\\\\TextLink', $textLinkElement);

        $svgIcon = $this->sourceFileContents('oxygen/subplugins/oxygen-elements/elements/SVG_Icon/html.twig');
        $this->assertStringContainsString('content.content.icon.svgCode', $svgIcon);
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
        $blockTree = $this->decodeOxygenDataTree($block, 'block.json');
        $this->assertComponentBlockTreeShape($blockTree);

        $component = $this->loadContractFixture('component-instance.json')['payload']['componentNode'] ?? null;
        $this->assertIsArray($component);
        $this->assertSame('OxygenElements\\Component', $component['data']['type'] ?? null);
        $componentBlock = $component['data']['properties']['content']['content']['block'] ?? null;
        $this->assertComponentBlockPayloadShape($componentBlock);
        $this->assertComponentTargetsResolveToBlockEditableProperties($blockTree, $componentBlock);
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
            if (array_key_exists('dynamicData', $variable)) {
                $this->assertIsArray($variable['dynamicData']);
            }
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

    /**
     * @param array<string, mixed> $tree
     */
    private function assertComponentBlockTreeShape(array $tree): void
    {
        $nodes = $this->indexTreeNodesById($tree['root'] ?? null);

        $this->assertSame('OxygenElements\\Text', $nodes[2]['data']['type'] ?? null);
        $this->assertSame('OxygenElements\\TextLink', $nodes[3]['data']['type'] ?? null);
        $this->assertSame('OxygenElements\\Image', $nodes[4]['data']['type'] ?? null);
        $this->assertSame('OxygenElements\\SvgIcon', $nodes[5]['data']['type'] ?? null);
        $this->assertSame('OxygenElements\\CssCode', $nodes[6]['data']['type'] ?? null);

        $icon = $nodes[5]['data']['properties']['content']['content']['icon']['svgCode'] ?? null;
        $this->assertIsString($icon);
        $this->assertStringContainsString('<svg', $icon);

        $css = $nodes[6]['data']['properties']['content']['content']['css_code'] ?? null;
        $this->assertIsString($css);
        $this->assertStringContainsString('.ohc-component-card', $css);

        $editableKeys = [];
        foreach ([2, 3, 4, 5] as $nodeId) {
            $editableProperties = $nodes[$nodeId]['data']['properties']['meta']['component']['editableProperties'] ?? null;
            $this->assertIsArray($editableProperties, 'editableProperties for node ' . (string) $nodeId);

            foreach ($editableProperties as $editableProperty) {
                $this->assertIsArray($editableProperty);
                $this->assertIsBool($editableProperty['enabled'] ?? null);
                $this->assertIsString($editableProperty['label'] ?? null);
                $this->assertIsString($editableProperty['controlPath'] ?? null);
                $this->assertIsString($editableProperty['propertyKey'] ?? null);
                $editableKeys[] = $editableProperty['propertyKey'];
            }
        }

        $this->assertSame([
            'cta_heading',
            'cta_button_label',
            'cta_button_url',
            'cta_image_url',
            'cta_image_alt',
            'cta_icon',
        ], $editableKeys);
    }

    /**
     * @param array<string, mixed> $tree
     * @param mixed $componentBlock
     */
    private function assertComponentTargetsResolveToBlockEditableProperties(array $tree, $componentBlock): void
    {
        $this->assertIsArray($componentBlock);
        $nodes = $this->indexTreeNodesById($tree['root'] ?? null);
        $properties = $componentBlock['properties'] ?? null;
        $this->assertIsArray($properties);

        foreach ($componentBlock['targets'] as $target) {
            $this->assertIsArray($target);
            $nodeId = $target['nodeId'] ?? null;
            $propertyKey = $target['propertyKey'] ?? null;
            $controlPath = $target['controlPath'] ?? null;

            $this->assertIsInt($nodeId);
            $this->assertIsString($propertyKey);
            $this->assertIsString($controlPath);
            $this->assertArrayHasKey($nodeId, $nodes);
            $this->assertArrayHasKey($propertyKey, $properties);

            $editableProperties = $nodes[$nodeId]['data']['properties']['meta']['component']['editableProperties'] ?? [];
            $matching = array_values(array_filter($editableProperties, static function ($editableProperty) use ($propertyKey): bool {
                return is_array($editableProperty) && ($editableProperty['propertyKey'] ?? null) === $propertyKey;
            }));

            $this->assertCount(1, $matching, 'editable property match for ' . $propertyKey);
            $this->assertSame($controlPath, $matching[0]['controlPath'] ?? null);
            $this->assertControlPathResolves($nodes[$nodeId]['data']['properties'] ?? [], $controlPath);
        }
    }

    /**
     * @param mixed $node
     * @return array<int, array<string, mixed>>
     */
    private function indexTreeNodesById($node): array
    {
        if (!is_array($node)) {
            return [];
        }

        $nodes = [];
        if (is_int($node['id'] ?? null)) {
            $nodes[(int) $node['id']] = $node;
        }

        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            $nodes += $this->indexTreeNodesById($child);
        }

        return $nodes;
    }

    /**
     * @param mixed $properties
     */
    private function assertControlPathResolves($properties, string $controlPath): void
    {
        $cursor = $properties;
        foreach (explode('.', $controlPath) as $segment) {
            $this->assertIsArray($cursor, $controlPath);
            $this->assertArrayHasKey($segment, $cursor, $controlPath);
            $cursor = $cursor[$segment];
        }
    }

    private function resolveWorkspaceSourceFile(string $sourceFile): string
    {
        return $this->resolveWorkspaceRoot()
            . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sourceFile);
    }

    private function sourceFileContents(string $sourceFile): string
    {
        $path = $this->resolveWorkspaceSourceFile($sourceFile);
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertIsString($content);

        return $content;
    }
}
