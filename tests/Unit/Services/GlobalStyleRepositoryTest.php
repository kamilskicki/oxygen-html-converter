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
            ],
        ]);

        $this->assertTrue($result['saved']);
        $this->assertSame('@font-face { font-family: "Material Symbols Outlined"; }', $result['library']['styles'][0]['css']);
    }
}
