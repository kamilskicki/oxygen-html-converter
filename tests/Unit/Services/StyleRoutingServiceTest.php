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
        $this->assertSame('global', $routing['routes'][0]['owner']);
        $this->assertSame('page', $routing['routes'][1]['owner']);
        $this->assertLessThan($routing['routes'][1]['cascadeOrder'], $routing['routes'][0]['cascadeOrder']);
        $this->assertSame('export_with_global_styles', $routing['routes'][0]['exportBehavior']);
        $this->assertSame('global_styles', $routing['routes'][0]['rollbackStore']);
    }

    public function testRoutesQuotedGoogleFontImportsToGlobalCss(): void
    {
        $routing = (new StyleRoutingService())->route(<<<'CSS'
@import "https://fonts.googleapis.com/css2?family=Inter";
.hero-card { color: red; }
CSS);

        $this->assertTrue($routing['summary']['hasGlobalCss']);
        $this->assertTrue($routing['summary']['hasPageCss']);
        $this->assertStringContainsString('fonts.googleapis.com', $routing['globalCss']);
        $this->assertStringContainsString('.hero-card', $routing['pageCss']);
        $this->assertSame('global_styles', $routing['routes'][0]['destination']);
        $this->assertSame('global', $routing['routes'][0]['owner']);
        $this->assertSame('page_css', $routing['routes'][1]['destination']);
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
        $this->assertSame('runtime_plugin_dependency', $routing['routes'][0]['owner']);
        $this->assertSame('requires_runtime_plugin', $routing['routes'][0]['exportBehavior']);
        $this->assertSame('windpress', $routing['routes'][0]['pluginDependency']['slug']);
        $this->assertTrue($routing['summary']['hasPluginDependentCss']);
        $this->assertSame('windpress', $routing['summary']['pluginDependencies'][0]['slug']);
    }

    public function testUnsupportedCssRoutesToPageFallbackTaxonomy(): void
    {
        $routing = (new StyleRoutingService())->route('.mask { clip-path: circle(50%); }');

        $this->assertSame('page_fallback', $routing['routes'][0]['type']);
        $this->assertSame('page_css', $routing['routes'][0]['destination']);
        $this->assertStringContainsString('clip-path', $routing['pageCss']);
    }

    public function testResponsiveStateAndNestedFallbackCssStayPageOwned(): void
    {
        $css = <<<'CSS'
@media (max-width: 767px) { .card { padding: 12px; } }
.card:hover { color: #2563eb; }
.card { & .title { font-weight: 700; } }
CSS;

        $routing = (new StyleRoutingService())->route($css);

        $this->assertSame('page_fallback', $routing['routes'][0]['type']);
        $this->assertSame('page_css', $routing['routes'][0]['destination']);
        $this->assertStringContainsString('@media (max-width: 767px)', $routing['pageCss']);
        $this->assertStringContainsString('.card:hover', $routing['pageCss']);
        $this->assertStringContainsString('& .title', $routing['pageCss']);
    }

    public function testMergesComponentCssIntoHostRoutingWithDeterministicDedupe(): void
    {
        $service = new StyleRoutingService();
        $routing = $service->route('.page-shell { display: grid; }');

        $merged = $service->mergeComponentCssIntoHostRouting($routing, [[
            'componentId' => 42,
            'componentName' => 'Feature Card',
            'signature' => 'div[h3,p,a,style]',
            'css' => '.feature-card { padding: 32px; }',
        ], [
            'componentId' => 42,
            'componentName' => 'Feature Card',
            'signature' => 'div[h3,p,a,style]',
            'css' => '.feature-card { padding: 32px; }',
        ], [
            'componentId' => 7,
            'componentName' => 'CTA',
            'signature' => 'section[h2,a,style]',
            'css' => '.cta { color: red; }',
        ]]);

        $this->assertStringContainsString('.page-shell', $merged['pageCss']);
        $this->assertSame(1, substr_count((string) $merged['pageScopedCss'], '.feature-card { padding: 32px; }'));
        $this->assertLessThan(
            strpos((string) $merged['pageScopedCss'], '.feature-card'),
            strpos((string) $merged['pageScopedCss'], '.cta')
        );

        $componentRoutes = array_values(array_filter(
            $merged['routes'],
            static fn (array $route): bool => ($route['type'] ?? '') === 'component_css_host_bridge'
        ));

        $this->assertCount(2, $componentRoutes);
        $this->assertSame(7, $componentRoutes[0]['componentId']);
        $this->assertSame(42, $componentRoutes[1]['componentId']);
        $this->assertSame('component', $componentRoutes[0]['owner']);
        $this->assertSame('page_scoped_styles', $componentRoutes[0]['destination']);
        $this->assertSame('export_with_page_manifest', $componentRoutes[0]['exportBehavior']);
        $this->assertSame('page_styles', $componentRoutes[0]['rollbackStore']);
        $this->assertLessThan($componentRoutes[1]['cascadeOrder'], $componentRoutes[0]['cascadeOrder']);
        $this->assertSame(2, $merged['summary']['ownerCounts']['component']);
        $this->assertSame(1, $merged['summary']['ownerCounts']['page']);
    }

    public function testComponentCssMergeDoesNotDuplicateExistingScopedSection(): void
    {
        $service = new StyleRoutingService();
        $routing = $service->route(".page-shell { display: grid; }\n\n.feature-card { padding: 32px; }");

        $merged = $service->mergeComponentCssIntoHostRouting($routing, [[
            'componentId' => 42,
            'componentName' => 'Feature Card',
            'signature' => 'div[h3,p,a,style]',
            'css' => '.feature-card { padding: 32px; }',
        ]]);

        $this->assertSame(1, substr_count($merged['pageCss'] . "\n" . $merged['pageScopedCss'], '.feature-card { padding: 32px; }'));
        $this->assertSame(1, $merged['summary']['ownerCounts']['page']);
        $this->assertArrayNotHasKey('component', $merged['summary']['ownerCounts']);
    }

    public function testComponentCssMergePreservesWindPressSafetyByteAccounting(): void
    {
        $service = new StyleRoutingService();
        $routing = $service->route(<<<'CSS'
/* Tailwind utility fallback */
.text-6xl { font-size: 3.75rem !important; }
CSS, true);
        $beforeBytes = $routing['summary']['windPressSafetyCssBytes'];

        $merged = $service->mergeComponentCssIntoHostRouting($routing, [[
            'componentId' => 42,
            'componentName' => 'Feature Card',
            'signature' => 'div[h3,p,a,style]',
            'css' => '.feature-card { padding: 32px; }',
        ]]);

        $this->assertSame($beforeBytes, $merged['summary']['windPressSafetyCssBytes']);
        $this->assertStringContainsString('.feature-card', $merged['pageScopedCss']);
    }
}
