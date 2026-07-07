<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\PageStyleRepository;
use PHPUnit\Framework\TestCase;

class PageStyleRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__wp_post_meta'] = [];
    }

    public function testSaveForPostPersistsPageScopedCssInPostMeta(): void
    {
        $repository = new PageStyleRepository();
        $css = "/* Tailwind utility fallback */\n.text-6xl { font-size: 3.75rem !important; }";

        $result = $repository->saveForPost(12, [
            'pageScopedCss' => $css,
            'styleRouting' => [
                'routes' => [[
                    'type' => 'tailwind_utility_fallback',
                    'destination' => 'page_scoped_styles',
                    'label' => 'Tailwind utility fallback safety CSS for WindPress',
                    'owner' => 'runtime_plugin_dependency',
                    'cascadeOrder' => 30,
                    'exportBehavior' => 'requires_runtime_plugin',
                    'rollbackStore' => 'page_styles',
                    'pluginDependency' => [
                        'slug' => 'windpress',
                        'name' => 'WindPress',
                        'required' => true,
                        'notice' => 'Tailwind utility fallback CSS requires the WindPress runtime for full fidelity.',
                    ],
                    'hash' => 'route-hash',
                ]],
            ],
        ]);

        $this->assertTrue($result['saved']);
        $this->assertGreaterThan(0, $result['bytes']);
        $this->assertSame('runtime_plugin_dependency', $result['owner']);
        $this->assertSame(30, $result['cascadeOrder']);
        $this->assertSame('requires_runtime_plugin', $result['exportBehavior']);
        $this->assertSame('windpress', $result['pluginDependency']['slug']);
        $this->assertSame($css, $repository->getCssForPost(12));

        $stored = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][12][PageStyleRepository::META_KEY]), true);
        $this->assertSame('page_styles', $stored['rollbackStore']);
        $this->assertSame('windpress', $stored['pluginDependency']['slug']);
        $this->assertStringContainsString('WindPress', $stored['pluginDependencyNotice']);
    }

    public function testSaveForPostNormalizesMinimalPluginDependentRoute(): void
    {
        $repository = new PageStyleRepository();

        $result = $repository->saveForPost(12, [
            'styleRouting' => [
                'pageScopedCss' => '.text-6xl { font-size: 3.75rem !important; }',
                'routes' => [[
                    'type' => 'tailwind_utility_fallback',
                    'destination' => 'page_scoped_styles',
                ]],
            ],
        ]);

        $this->assertTrue($result['saved']);
        $this->assertSame('runtime_plugin_dependency', $result['owner']);
        $this->assertSame('requires_runtime_plugin', $result['exportBehavior']);
        $this->assertSame('page_styles', $result['rollbackStore']);
        $this->assertSame('windpress', $result['pluginDependency']['slug']);

        $stored = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][12][PageStyleRepository::META_KEY]), true);
        $this->assertSame('runtime_plugin_dependency', $stored['routes'][0]['owner']);
        $this->assertSame('requires_runtime_plugin', $stored['routes'][0]['exportBehavior']);
        $this->assertSame('windpress', $stored['routes'][0]['pluginDependency']['slug']);
        $this->assertStringContainsString('WindPress', $stored['pluginDependencyNotice']);
    }

    public function testSaveForPostDeletesEmptyPageScopedCss(): void
    {
        $repository = new PageStyleRepository();
        $repository->saveForPost(12, [
            'pageScopedCss' => '.text-6xl { font-size: 3.75rem !important; }',
        ]);

        $result = $repository->saveForPost(12, [
            'pageScopedCss' => '',
        ]);

        $this->assertFalse($result['saved']);
        $this->assertSame('', $repository->getCssForPost(12));
    }

    public function testSaveForPostPersistsRoutedPageCssWithPageOwner(): void
    {
        $repository = new PageStyleRepository();

        $result = $repository->saveForPost(12, [
            'styleRouting' => [
                'pageCss' => '.hero { color: red; }',
                'routes' => [[
                    'type' => 'source_style',
                    'destination' => 'page_css',
                    'label' => 'Source style CSS',
                    'owner' => 'page',
                    'cascadeOrder' => 20,
                    'exportBehavior' => 'export_with_page_manifest',
                    'rollbackStore' => 'page_styles',
                    'hash' => 'page-route-hash',
                ]],
            ],
        ]);

        $this->assertTrue($result['saved']);
        $this->assertSame('page', $result['owner']);
        $this->assertFalse($result['hasMixedOwners']);
        $this->assertSame(20, $result['cascadeOrder']);
        $this->assertSame('export_with_page_manifest', $result['exportBehavior']);
        $this->assertSame('.hero { color: red; }', $repository->getCssForPost(12));
    }

    public function testSaveForPostExposesMixedPageAndRuntimeOwners(): void
    {
        $repository = new PageStyleRepository();

        $result = $repository->saveForPost(12, [
            'styleRouting' => [
                'pageCss' => '.hero { color: red; }',
                'pageScopedCss' => '.text-6xl { font-size: 3.75rem !important; }',
                'routes' => [[
                    'type' => 'source_style',
                    'destination' => 'page_css',
                    'owner' => 'page',
                    'cascadeOrder' => 20,
                    'exportBehavior' => 'export_with_page_manifest',
                    'rollbackStore' => 'page_styles',
                ], [
                    'type' => 'tailwind_utility_fallback',
                    'destination' => 'page_scoped_styles',
                    'owner' => 'runtime_plugin_dependency',
                    'cascadeOrder' => 30,
                    'exportBehavior' => 'requires_runtime_plugin',
                    'rollbackStore' => 'page_styles',
                    'pluginDependency' => [
                        'slug' => 'windpress',
                        'name' => 'WindPress',
                        'required' => true,
                        'notice' => 'Tailwind utility fallback CSS requires the WindPress runtime for full fidelity.',
                    ],
                ]],
            ],
        ]);

        $this->assertTrue($result['saved']);
        $this->assertTrue($result['hasMixedOwners']);
        $this->assertSame(1, $result['ownerCounts']['page']);
        $this->assertSame(1, $result['ownerCounts']['runtime_plugin_dependency']);
        $this->assertSame('windpress', $result['pluginDependency']['slug']);

        $stored = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][12][PageStyleRepository::META_KEY]), true);
        $this->assertTrue($stored['hasMixedOwners']);
        $this->assertSame(['page', 'runtime_plugin_dependency'], $stored['owners']);
    }

    public function testSaveForPostSkipsPageCssWhenVisibleCssCodeFallbackExists(): void
    {
        $repository = new PageStyleRepository();

        $result = $repository->saveForPost(12, [
            'cssElement' => [
                'data' => ['type' => 'OxygenElements\\CssCode'],
                'children' => [],
            ],
            'styleRouting' => [
                'pageCss' => '.hero { color: red; }',
                'pageScopedCss' => '.text-6xl { font-size: 3.75rem !important; }',
                'routes' => [[
                    'type' => 'source_style',
                    'destination' => 'page_css',
                    'owner' => 'page',
                    'cascadeOrder' => 20,
                    'exportBehavior' => 'export_with_page_manifest',
                    'rollbackStore' => 'page_styles',
                ], [
                    'type' => 'tailwind_utility_fallback',
                    'destination' => 'page_scoped_styles',
                    'owner' => 'runtime_plugin_dependency',
                    'cascadeOrder' => 30,
                    'exportBehavior' => 'requires_runtime_plugin',
                    'rollbackStore' => 'page_styles',
                    'pluginDependency' => [
                        'slug' => 'windpress',
                        'name' => 'WindPress',
                        'required' => true,
                        'notice' => 'Tailwind utility fallback CSS requires the WindPress runtime for full fidelity.',
                    ],
                ]],
            ],
        ]);

        $this->assertTrue($result['saved']);
        $this->assertStringNotContainsString('.hero', $repository->getCssForPost(12));
        $this->assertStringContainsString('.text-6xl', $repository->getCssForPost(12));
        $this->assertSame(['runtime_plugin_dependency'], $result['owners']);
    }

    public function testSaveForPostPersistsComponentHostBridgeOwnerMetadata(): void
    {
        $repository = new PageStyleRepository();

        $result = $repository->saveForPost(12, [
            'styleRouting' => [
                'pageScopedCss' => '.feature-card { padding: 32px; }',
                'routes' => [[
                    'type' => 'component_css_host_bridge',
                    'destination' => 'page_scoped_styles',
                    'componentId' => 42,
                    'componentName' => 'Feature Card',
                    'hash' => 'component-css-hash',
                ]],
            ],
        ]);

        $this->assertTrue($result['saved']);
        $this->assertSame('component', $result['owner']);
        $this->assertSame(['component'], $result['owners']);
        $this->assertSame(1, $result['ownerCounts']['component']);
        $this->assertSame('export_with_page_manifest', $result['exportBehavior']);
        $this->assertSame('page_styles', $result['rollbackStore']);

        $stored = json_decode(stripslashes((string) $GLOBALS['__wp_post_meta'][12][PageStyleRepository::META_KEY]), true);
        $this->assertSame('component_css_host_bridge', $stored['routes'][0]['type']);
        $this->assertSame('component', $stored['routes'][0]['owner']);
        $this->assertSame(42, $stored['routes'][0]['componentId']);
        $this->assertSame('Feature Card', $stored['routes'][0]['componentName']);
        $this->assertSame('.feature-card { padding: 32px; }', $stored['css']);
    }
}
