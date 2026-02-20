<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\GridDetector;
use PHPUnit\Framework\TestCase;

class GridDetectorTest extends TestCase
{
    private GridDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new GridDetector();
    }

    public function testMapsNumericGridColumns(): void
    {
        $cols = $this->detector->getGridTemplateColumns(['grid', 'grid-cols-3']);
        $this->assertSame('repeat(3, minmax(0, 1fr))', $cols);
    }

    public function testMapsArbitraryGridColumns(): void
    {
        $cols = $this->detector->getGridTemplateColumns(['grid', 'grid-cols-[200px_1fr]']);
        $this->assertSame('200px 1fr', $cols);
    }

    public function testMapsGridColumnKeywords(): void
    {
        $this->assertSame('none', $this->detector->getGridTemplateColumns(['grid-cols-none']));
        $this->assertSame('subgrid', $this->detector->getGridTemplateColumns(['grid-cols-subgrid']));
    }

    public function testMapsGapScaleAndPxUtilities(): void
    {
        $gaps = $this->detector->getGridGap(['gap-2.5', 'gap-x-px', 'gap-y-0']);

        $this->assertSame('0.625rem', $gaps['gap']);
        $this->assertSame('1px', $gaps['column-gap']);
        $this->assertSame('0rem', $gaps['row-gap']);
    }

    public function testMapsArbitraryGapUtilities(): void
    {
        $gaps = $this->detector->getGridGap([
            'gap-[clamp(8px,_2vw,_24px)]',
            'gap-x-[10px]',
            'gap-y-[1.5rem]',
        ]);

        $this->assertSame('clamp(8px, 2vw, 24px)', $gaps['gap']);
        $this->assertSame('10px', $gaps['column-gap']);
        $this->assertSame('1.5rem', $gaps['row-gap']);
    }

    public function testReturnsGridPropertiesForGridElements(): void
    {
        $props = $this->detector->getGridProperties('grid grid-cols-4 gap-x-6 gap-y-2');

        $this->assertSame('true', $props['grid']);
        $this->assertSame('grid', $props['display']);
        $this->assertSame('repeat(4, minmax(0, 1fr))', $props['grid-template-columns']);
        $this->assertSame('1.5rem', $props['column-gap']);
        $this->assertSame('0.5rem', $props['row-gap']);
    }

    public function testCapturesResponsiveAndStateGridUtilitiesAsVariantDeclarations(): void
    {
        $props = $this->detector->getGridProperties('grid md:grid-cols-2 lg:gap-8 hover:gap-x-3');

        $this->assertSame('repeat(2, minmax(0, 1fr))', $props['__tw_variant__md__grid-template-columns']);
        $this->assertSame('2rem', $props['__tw_variant__lg__gap']);
        $this->assertSame('0.75rem', $props['__tw_variant__hover__column-gap']);
    }

    public function testMapsFlexUtilitiesToLayoutDeclarations(): void
    {
        $props = $this->detector->getGridProperties('flex flex-col justify-between items-center gap-4');

        $this->assertSame('flex', $props['display']);
        $this->assertSame('column', $props['flex-direction']);
        $this->assertSame('space-between', $props['justify-content']);
        $this->assertSame('center', $props['align-items']);
        $this->assertSame('1rem', $props['gap']);
    }

    public function testCapturesResponsiveAndStateFlexUtilitiesAsVariantDeclarations(): void
    {
        $props = $this->detector->getGridProperties('flex md:flex-row lg:justify-center hover:items-end');

        $this->assertSame('row', $props['__tw_variant__md__flex-direction']);
        $this->assertSame('center', $props['__tw_variant__lg__justify-content']);
        $this->assertSame('flex-end', $props['__tw_variant__hover__align-items']);
    }

    public function testMapsSpacingUtilitiesToDeclarations(): void
    {
        $props = $this->detector->getGridProperties('px-4 py-2 -mt-6 mx-auto');

        $this->assertSame('1rem', $props['padding-left']);
        $this->assertSame('1rem', $props['padding-right']);
        $this->assertSame('0.5rem', $props['padding-top']);
        $this->assertSame('0.5rem', $props['padding-bottom']);
        $this->assertSame('-1.5rem', $props['margin-top']);
        $this->assertSame('auto', $props['margin-left']);
        $this->assertSame('auto', $props['margin-right']);
    }

    public function testCapturesVariantSpacingAndTypographyUtilitiesAsVariantDeclarations(): void
    {
        $props = $this->detector->getGridProperties('md:p-6 lg:mt-4 hover:text-lg focus:font-semibold');

        $this->assertSame('1.5rem', $props['__tw_variant__md__padding-top']);
        $this->assertSame('1.5rem', $props['__tw_variant__md__padding-right']);
        $this->assertSame('1.5rem', $props['__tw_variant__md__padding-bottom']);
        $this->assertSame('1.5rem', $props['__tw_variant__md__padding-left']);
        $this->assertSame('1rem', $props['__tw_variant__lg__margin-top']);
        $this->assertSame('1.125rem', $props['__tw_variant__hover__font-size']);
        $this->assertSame('600', $props['__tw_variant__focus__font-weight']);
    }

    public function testMapsTailwindTextColorsIncludingPaletteAndOpacity(): void
    {
        $props = $this->detector->getGridProperties('text-slate-700 hover:text-white/80 text-[rgb(12,34,56)]');

        // Later class wins for base property
        $this->assertSame('rgb(12,34,56)', $props['color']);
        $this->assertSame('rgba(255, 255, 255, 0.8)', $props['__tw_variant__hover__color']);

        $paletteProps = $this->detector->getGridProperties('text-slate-700/50');
        $this->assertSame('rgba(51, 65, 85, 0.5)', $paletteProps['color']);
    }

    public function testMapsLineHeightTrackingAndTextTransformUtilities(): void
    {
        $props = $this->detector->getGridProperties('leading-relaxed tracking-wide uppercase md:leading-[1.8] hover:tracking-[0.2em]');

        $this->assertSame('1.625', $props['line-height']);
        $this->assertSame('0.025em', $props['letter-spacing']);
        $this->assertSame('uppercase', $props['text-transform']);
        $this->assertSame('1.8', $props['__tw_variant__md__line-height']);
        $this->assertSame('0.2em', $props['__tw_variant__hover__letter-spacing']);
    }

    public function testMapsFontStyleAndTextDecorationUtilities(): void
    {
        $props = $this->detector->getGridProperties('italic underline md:not-italic hover:line-through focus:no-underline');

        $this->assertSame('italic', $props['font-style']);
        $this->assertSame('underline', $props['text-decoration-line']);
        $this->assertSame('normal', $props['__tw_variant__md__font-style']);
        $this->assertSame('line-through', $props['__tw_variant__hover__text-decoration-line']);
        $this->assertSame('none', $props['__tw_variant__focus__text-decoration-line']);
    }

    public function testMapsFontFamilyUtilitiesIncludingArbitraryAndVariants(): void
    {
        $props = $this->detector->getGridProperties('font-sans hover:font-mono md:font-[Inter_var]');

        $this->assertSame('ui-sans-serif, system-ui, sans-serif', $props['font-family']);
        $this->assertSame(
            'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace',
            $props['__tw_variant__hover__font-family']
        );
        $this->assertSame('Inter var', $props['__tw_variant__md__font-family']);
    }

    public function testMapsBracketOpacityAndTokenTextColorUtilities(): void
    {
        $props = $this->detector->getGridProperties('text-slate-700/[.35] hover:text-white/[0.8] text-(--brand-color)');

        // Later class wins for base property
        $this->assertSame('var(--brand-color)', $props['color']);
        $this->assertSame('rgba(255, 255, 255, 0.8)', $props['__tw_variant__hover__color']);

        $bracketOpacityProps = $this->detector->getGridProperties('text-slate-700/[.35]');
        $this->assertSame('rgba(51, 65, 85, 0.35)', $bracketOpacityProps['color']);

        $unsupportedExpressionOpacity = $this->detector->getGridProperties('text-slate-700/[var(--x)]');
        $this->assertArrayNotHasKey('color', $unsupportedExpressionOpacity);
    }

    public function testMapsArbitraryHexTextColorsWithSlashOpacity(): void
    {
        $props = $this->detector->getGridProperties('text-[#0f172a]/75 hover:text-[#fff]/[.8]');

        $this->assertSame('rgba(15, 23, 42, 0.75)', $props['color']);
        $this->assertSame('rgba(255, 255, 255, 0.8)', $props['__tw_variant__hover__color']);
    }

}
