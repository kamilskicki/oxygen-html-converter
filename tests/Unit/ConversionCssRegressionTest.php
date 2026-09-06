<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Tests\Unit;

use OxyHtmlConverter\TreeBuilder;
use OxyHtmlConverter\Services\ConvertPayloadBuilder;
use OxyHtmlConverter\Services\ConversionAuditBuilder;
use OxyHtmlConverter\Services\OxygenDocumentTree;
use OxyHtmlConverter\Services\RequestOptions;
use OxyHtmlConverter\Services\TreeBuilderFactory;
use OxyHtmlConverter\Validation\OutputValidator;
use PHPUnit\Framework\TestCase;

final class ConversionCssRegressionTest extends TestCase
{
    public function testIvoryFixtureKeepsFluidContainerPaddingAndNavigationGapAfterTokenBinding(): void
    {
        $html = file_get_contents(__DIR__ . '/../../fixtures/html/design-4-ivory-editorial.html');
        self::assertIsString($html);
        $options = (new RequestOptions())->normalizeConvert(['safeMode' => true]);
        $result = (new TreeBuilderFactory())->createForConvert($options, $html)->convert($html);
        $payload = (new ConvertPayloadBuilder(new OxygenDocumentTree(), new ConversionAuditBuilder(), new OutputValidator()))
            ->build($result, $options, $html);
        self::assertTrue($payload['success']);
        $selectors = array_column($payload['data']['selectorPayload']['selectors'], null, 'name');
        self::assertSame(
            'clamp(var(--ohc-space-1-5rem), var(--ohc-space-4vw), var(--ohc-space-4rem))',
            $selectors['container']['properties']['breakpoint_base']['spacing']['spacing']['padding']['left']['style']
        );
        self::assertSame(
            'clamp(var(--ohc-space-1-5rem), var(--ohc-space-3vw), var(--ohc-space-3rem))',
            $selectors['nav__links']['properties']['breakpoint_base']['layout']['gap']['column']['style']
        );
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            self::assertSame(
                'clamp(var(--ohc-space-2rem), var(--ohc-space-3vw), var(--ohc-space-3rem))',
                $selectors['service']['properties']['breakpoint_base']['spacing']['spacing']['padding'][$side]['style']
            );
        }
        foreach ($selectors as $selector) {
            if (!is_array($selector['properties'])) {
                continue;
            }
            $customCss = $selector['properties']['breakpoint_base']['custom_css']['custom_css'] ?? '';
            self::assertStringNotContainsString('padding-top: clamp(var(--ohc-space-2rem),;', $customCss);
        }
    }

    public function testOverBudgetMediaGroupsRemainInOriginalCss(): void
    {
        $media = str_repeat('@media (hover: hover), (pointer: fine) { ', 20)
            . '.card { contain: paint; }' . str_repeat('}', 20);
        $result = (new TreeBuilder())->convert('<style>' . $media . '</style><div class="card">Card</div>');

        self::assertTrue($result['success']);
        $css = $result['cssElement']['data']['properties']['content']['content']['css_code'] ?? '';
        self::assertStringContainsString($media, $css);
    }

    public function testParserSelectorCascadeFixtureConvertsWithCssFallbackOwnership(): void
    {
        $html = file_get_contents(__DIR__ . '/../../fixtures/html/regressions/2026-09-06-parser-selector-cascade.html');
        self::assertIsString($html);
        $result = (new TreeBuilder())->convert($html);

        self::assertTrue($result['success']);
        $serialized = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Zażółć gęślą jaźń — 日本語', $serialized);
        $css = $result['cssElement']['data']['properties']['content']['content']['css_code'] ?? '';
        self::assertStringContainsString('contain: paint !important', $css);
        self::assertStringContainsString('.card + .badge', $css);
    }

    public function testPartiallyConsumedCssKeepsImportanceOnUnsupportedProperties(): void
    {
        $result = (new TreeBuilder())->convert('<style>#card { color: red; contain: paint !important; }</style><div id="card">Card</div>');

        self::assertTrue($result['success']);
        $css = $result['cssElement']['data']['properties']['content']['content']['css_code'] ?? '';
        self::assertStringContainsString('contain: paint !important', $css);
    }
}
