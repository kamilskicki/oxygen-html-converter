<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\StyleRoutingService;
use PHPUnit\Framework\TestCase;

class StyleRoutingServiceTest extends TestCase
{
    public function testRoutesMaterialSymbolsToGlobalCssAndPageRulesToPageCss(): void
    {
        $routing = (new StyleRoutingService())->route(<<<'CSS'
/* Extracted from <style> tag */
@font-face { font-family: 'Material Symbols Outlined'; src: url(material.woff2) format('woff2'); }
.material-symbols-outlined { font-family: 'Material Symbols Outlined'; }
.hero-card { backdrop-filter: blur(12px); }
CSS);

        $this->assertTrue($routing['summary']['hasGlobalCss']);
        $this->assertTrue($routing['summary']['hasPageCss']);
        $this->assertStringContainsString('Material Symbols Outlined', $routing['globalCss']);
        $this->assertStringContainsString('.hero-card', $routing['pageCss']);
        $this->assertStringNotContainsString('.hero-card', $routing['globalCss']);

        $destinations = array_column($routing['routes'], 'destination');
        $this->assertContains('global_styles', $destinations);
        $this->assertContains('page_css', $destinations);
    }

    public function testWindPressModeRoutesTailwindUtilityFallbackCssToPageScopedSafetyCss(): void
    {
        $routing = (new StyleRoutingService())->route(<<<'CSS'
/* Tailwind utility fallback */
.text-6xl { font-size: 3.75rem !important; }
CSS, true);

        $this->assertSame('', $routing['pageCss']);
        $this->assertSame('', $routing['globalCss']);
        $this->assertStringContainsString('.text-6xl', $routing['pageScopedCss']);
        $this->assertTrue($routing['summary']['usesWindPressRuntime']);
        $this->assertGreaterThan(0, $routing['summary']['windPressSafetyCssBytes']);
        $this->assertSame('page_scoped_styles', $routing['routes'][0]['destination']);
    }

    public function testUnsupportedCssRoutesToPageFallbackTaxonomy(): void
    {
        $routing = (new StyleRoutingService())->route('.mask { clip-path: circle(50%); }');

        $this->assertSame('page_fallback', $routing['routes'][0]['type']);
        $this->assertSame('page_css', $routing['routes'][0]['destination']);
        $this->assertStringContainsString('clip-path', $routing['pageCss']);
    }
}
