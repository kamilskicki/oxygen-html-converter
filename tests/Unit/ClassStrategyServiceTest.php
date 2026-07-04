<?php

namespace OxyHtmlConverter\Tests\Unit;

use OxyHtmlConverter\Report\ConversionReport;
use OxyHtmlConverter\Services\ClassStrategyService;
use OxyHtmlConverter\Services\EnvironmentService;
use OxyHtmlConverter\Services\TailwindDetector;
use OxyHtmlConverter\Services\TailwindPropertyMapper;
use PHPUnit\Framework\TestCase;

class ClassStrategyServiceTest extends TestCase
{
    private mixed $previousClassMode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousClassMode = $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] ?? null;
        $GLOBALS['__wp_options']['oxy_html_converter_class_mode'] = 'native';
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

    public function testNativeModeMapsSupportedTailwindUtilitiesToDesignProperties(): void
    {
        $service = new ClassStrategyService(
            new EnvironmentService(),
            new ConversionReport(),
            new TailwindDetector(),
            new TailwindPropertyMapper()
        );

        $element = [
            'data' => [
                'properties' => [],
            ],
        ];

        $service->processClasses(['flex', 'items-center', 'text-white', 'custom-card'], $element);

        $this->assertSame('flex', $element['data']['properties']['design']['layout']['display']);
        $this->assertSame('center', $element['data']['properties']['design']['layout']['flex_align']['cross_axis']);
        $this->assertSame('#FFFFFFFF', $element['data']['properties']['design']['typography']['color']);
        $this->assertArrayNotHasKey('align-items', $element['data']['properties']['design']['layout']);
        $this->assertSame(['custom-card'], $element['data']['properties']['settings']['advanced']['classes']);
    }

    public function testNativeModePreservesUnsupportedTailwindUtilitiesAsClasses(): void
    {
        $service = new ClassStrategyService(
            new EnvironmentService(),
            new ConversionReport(),
            new TailwindDetector(),
            new TailwindPropertyMapper()
        );

        $element = [
            'data' => [
                'properties' => [],
            ],
        ];

        $service->processClasses(['container', 'reveal'], $element);

        $this->assertSame(['reveal', 'container'], $element['data']['properties']['settings']['advanced']['classes']);
        $this->assertArrayNotHasKey('design', $element['data']['properties']);
    }
}
