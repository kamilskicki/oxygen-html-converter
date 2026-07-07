<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\GlobalStyleRepository;
use PHPUnit\Framework\TestCase;

class GlobalStyleRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__wp_options'] = [];
    }

    public function testSaveFromPayloadDeduplicatesGlobalCssAssets(): void
    {
        $repository = new GlobalStyleRepository();
        $payload = [
            'globalCss' => '.material-symbols-outlined { font-variation-settings: "FILL" 0; }',
        ];

        $first = $repository->saveFromPayload($payload);
        $second = $repository->saveFromPayload($payload);

        $this->assertTrue($first['saved']);
        $this->assertSame(1, $first['changes']);
        $this->assertTrue($second['saved']);
        $this->assertSame(0, $second['changes']);
        $this->assertCount(1, $second['library']['styles']);
        $this->assertStringContainsString('.material-symbols-outlined', $repository->getCombinedCss());
    }

    public function testSaveFromPayloadUsesStyleRoutingGlobalCssFallback(): void
    {
        $result = (new GlobalStyleRepository())->saveFromPayload([
            'styleRouting' => [
                'globalCss' => '@font-face { font-family: "Material Symbols Outlined"; }',
                'routes' => [[
                    'type' => 'global_asset',
                    'destination' => 'global_styles',
                    'label' => 'Material Symbols global style',
                    'owner' => 'global',
                    'cascadeOrder' => 20,
                    'exportBehavior' => 'export_with_global_styles',
                    'rollbackStore' => 'global_styles',
                    'hash' => 'route-hash',
                ]],
            ],
        ]);

        $this->assertTrue($result['saved']);
        $this->assertSame('@font-face { font-family: "Material Symbols Outlined"; }', $result['library']['styles'][0]['css']);
        $this->assertSame('global', $result['library']['styles'][0]['owner']);
        $this->assertSame(20, $result['library']['styles'][0]['cascadeOrder']);
        $this->assertSame('export_with_global_styles', $result['library']['styles'][0]['exportBehavior']);
        $this->assertSame('global_styles', $result['library']['styles'][0]['rollbackStore']);
    }

    public function testGetCombinedCssSortsByCascadeOrder(): void
    {
        $GLOBALS['__wp_options'][GlobalStyleRepository::OPTION_NAME] = wp_json_encode([
            'version' => 1,
            'styles' => [[
                'id' => 'b',
                'css' => '.b { color: blue; }',
                'cascadeOrder' => 20,
            ], [
                'id' => 'a',
                'css' => '.a { color: red; }',
                'cascadeOrder' => 10,
            ]],
        ]);

        $css = (new GlobalStyleRepository())->getCombinedCss();

        $this->assertLessThan(strpos($css, '.b'), strpos($css, '.a'));
    }
}
