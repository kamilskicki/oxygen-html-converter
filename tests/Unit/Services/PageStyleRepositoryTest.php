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
        ]);

        $this->assertTrue($result['saved']);
        $this->assertGreaterThan(0, $result['bytes']);
        $this->assertSame($css, $repository->getCssForPost(12));
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
}
