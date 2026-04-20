<?php

namespace OxyHtmlConverter\Tests\Unit;

use OxyHtmlConverter\TreeBuilder;
use PHPUnit\Framework\TestCase;

class TreeBuilderElementMappingTest extends TestCase
{
    private mixed $previousClassMode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousClassMode = $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousClassMode === null) {
            unset($GLOBALS['__wp_options']['oxy_html_converter_class_mode']);
        } else {
            $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = $this->previousClassMode;
        }

        parent::tearDown();
    }

    public function testTreeBuilderKeepsOxygenButtonMappingByDefault(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert('<button>Click me</button>');

        $this->assertTrue($result['success']);
        $this->assertSame('OxygenElements\\Container', $result['element']['data']['type']);
        $this->assertNotEmpty($result['element']['children']);
    }

    public function testTreeBuilderCanPreferEssentialButtonMapping(): void
    {
        $builder = new TreeBuilder();
        $builder->setPreferEssentialElements(true);
        $result = $builder->convert('<button onclick="location=\'https://example.com\'">Buy now</button>');

        $this->assertTrue($result['success']);
        $this->assertSame('EssentialElements\\Button', $result['element']['data']['type']);
        $this->assertSame('Buy now', $result['element']['data']['properties']['content']['content']['text']);
        $this->assertSame(
            'https://example.com',
            $result['element']['data']['properties']['content']['content']['link']['url']
        );
        $this->assertEmpty($result['element']['children']);
    }

    public function testTreeBuilderKeepsStyledButtonsAsContainersEvenWhenEssentialElementsPreferred(): void
    {
        $builder = new TreeBuilder();
        $builder->setPreferEssentialElements(true);
        $result = $builder->convert('<button class="bg-[#ff0084] text-white rounded-full">Buy now</button>');

        $this->assertTrue($result['success']);
        $this->assertSame('OxygenElements\\Container', $result['element']['data']['type']);
        $this->assertNotEmpty($result['element']['children']);
    }

    public function testTreeBuilderPreservesComplexButtonChildrenWhenEssentialElementsPreferred(): void
    {
        $builder = new TreeBuilder();
        $builder->setPreferEssentialElements(true);
        $result = $builder->convert('<button class="group"><span>Watch</span><i data-lucide="play"></i></button>');

        $this->assertTrue($result['success']);
        $this->assertSame('OxygenElements\\Container', $result['element']['data']['type']);
        $this->assertCount(2, $result['element']['children']);
    }

    public function testTreeBuilderDropsMappedTailwindClassesFromNativeModeOutput(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $result = $builder->convert('<div class="flex items-center text-white bg-[#ff0084] custom-card">Card</div>');

        $this->assertTrue($result['success']);
        $this->assertSame('flex', $result['element']['data']['properties']['design']['layout']['display']);
        $this->assertSame('center', $result['element']['data']['properties']['design']['layout']['align-items']);
        $this->assertSame('#ffffff', $result['element']['data']['properties']['design']['typography']['color']);
        $this->assertSame('#ff0084', $result['element']['data']['properties']['design']['background']['background-color']);
        $this->assertSame(['custom-card'], $result['element']['data']['properties']['settings']['advanced']['classes']);
    }

    public function testTreeBuilderPrunesFallbackCssForMappedTailwindUtilities(): void
    {
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';

        $builder = new TreeBuilder();
        $result = $builder->convert('<div class="flex items-center text-white">Card</div>');

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString('.flex', $result['extractedCss']);
        $this->assertStringNotContainsString('.items-center', $result['extractedCss']);
        $this->assertStringNotContainsString('.text-white', $result['extractedCss']);
    }

    public function testTreeBuilderOnlyPrunesFullySupportedCssRules(): void
    {
        $builder = new TreeBuilder();
        $result = $builder->convert(
            '<style>.card { display:flex; justify-content:center; } .mask { clip-path: circle(50%); color:red; }</style>'
            . '<div class="card">One</div><div class="mask">Two</div>'
        );

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString('.card', $result['extractedCss']);
        $this->assertStringContainsString('.mask', $result['extractedCss']);
    }
}
