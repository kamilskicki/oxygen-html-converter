<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use OxyHtmlConverter\Services\ClassStrategyService;
use OxyHtmlConverter\Services\EnvironmentService;
use OxyHtmlConverter\Services\TailwindDetector;
use OxyHtmlConverter\Services\TailwindPropertyMapper;
use OxyHtmlConverter\Report\ConversionReport;

class ClassStrategyServiceTest extends TestCase
{
    private ClassStrategyService $service;
    private ConversionReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->report = new ConversionReport();
    }

    /**
     * Create a ClassStrategyService with a mock EnvironmentService
     */
    private function createServiceWithWindPressMode(bool $useWindPress): ClassStrategyService
    {
        $environment = $this->createMock(EnvironmentService::class);
        $environment->method('shouldUseWindPressMode')->willReturn($useWindPress);
        
        return new ClassStrategyService(
            $environment,
            $this->report,
            new TailwindDetector(),
            new TailwindPropertyMapper()
        );
    }

    public function testProcessClassesEmptyArray(): void
    {
        $service = $this->createServiceWithWindPressMode(true);
        $element = ['data' => ['properties' => []]];
        
        $service->processClasses([], $element);
        
        // Should not add classes key for empty array
        $this->assertArrayNotHasKey('settings', $element['data']['properties']);
    }

    public function testWindPressModePreservesAllClasses(): void
    {
        $service = $this->createServiceWithWindPressMode(true);
        $element = ['data' => ['properties' => []]];
        
        $classes = ['flex', 'items-center', 'my-custom-class', 'text-lg'];
        $service->processClasses($classes, $element);
        
        $storedClasses = $element['data']['properties']['settings']['advanced']['classes'];
        
        $this->assertCount(4, $storedClasses);
        $this->assertEquals($classes, $storedClasses);
    }

    public function testOxygenNativeModeMapsSupportedTailwindUtilitiesAndPreservesCustomClasses(): void
    {
        $service = $this->createServiceWithWindPressMode(false);
        $element = ['data' => ['properties' => []]];
        
        $classes = ['flex', 'items-center', 'my-custom-class'];
        $service->processClasses($classes, $element);
        
        $storedClasses = $element['data']['properties']['settings']['advanced']['classes'] ?? [];

        $this->assertSame(['my-custom-class'], $storedClasses);
        $this->assertSame('flex', $element['data']['properties']['design']['layout']['display']);
        $this->assertSame('center', $element['data']['properties']['design']['layout']['flex_align']['cross_axis']);
        $this->assertArrayNotHasKey('align-items', $element['data']['properties']['design']['layout']);
    }

    public function testOxygenNativeModeMapsKnownUtilitiesEvenWhenDetectorMissesThem(): void
    {
        $service = $this->createServiceWithWindPressMode(false);
        $element = ['data' => ['properties' => []]];

        $service->processClasses(['inline-flex', 'inline-block', 'custom-card'], $element);

        $this->assertSame(['custom-card'], $element['data']['properties']['settings']['advanced']['classes']);
        $this->assertSame('inline-block', $element['data']['properties']['design']['layout']['display']);
    }

    public function testBuildSemanticClassProfileDeduplicatesRepeatedStylePatterns(): void
    {
        $profile = ClassStrategyService::buildSemanticClassProfile(
            [
                [
                    'selector' => '.pricing-card',
                    'declarations' => [
                        'color' => '#123456',
                        'padding' => '24px',
                    ],
                ],
                [
                    'selector' => '.feature-card',
                    'declarations' => [
                        'padding' => '24px',
                        'color' => '#123456',
                    ],
                ],
                [
                    'selector' => '.hero',
                    'declarations' => [
                        'color' => '#654321',
                    ],
                ],
            ],
            ['pricing-card', 'feature-card', 'feature-card', 'hero']
        );

        $this->assertSame('ohc-card', $profile['aliases']['pricing-card']);
        $this->assertSame('ohc-card', $profile['aliases']['feature-card']);
        $this->assertSame(['feature-card', 'pricing-card'], $profile['duplicateStylePatterns'][0]['sourceClasses']);
        $this->assertSame(3, $profile['duplicateStylePatterns'][0]['occurrences']);
        $this->assertSame(2, $profile['duplicateStylePatterns'][0]['threshold']['minOccurrences']);
        $this->assertSame(0.9, $profile['duplicateStylePatterns'][0]['threshold']['minConfidence']);
        $this->assertSame(1, $profile['selectorCountReduction']);
        $this->assertSame('dedupe_selector', $profile['classMap'][0]['action']);
    }

    public function testBuildSemanticClassProfileDoesNotDedupeWhenStateStylesDiffer(): void
    {
        $profile = ClassStrategyService::buildSemanticClassProfile(
            [
                [
                    'selector' => '.primary-card',
                    'declarations' => ['color' => '#123456'],
                ],
                [
                    'selector' => '.secondary-card',
                    'declarations' => ['color' => '#123456'],
                ],
                [
                    'selector' => '.primary-card:hover',
                    'declarations' => ['color' => '#000000'],
                ],
            ],
            ['primary-card', 'secondary-card']
        );

        $this->assertSame([], $profile['aliases']);
        $this->assertSame('state_or_responsive_mismatch', $profile['skippedPatterns'][0]['reason']);
        $this->assertSame(2, $profile['skippedPatterns'][0]['threshold']['minOccurrences']);
        $this->assertSame(0.9, $profile['skippedPatterns'][0]['threshold']['minConfidence']);
    }

    public function testBuildSemanticClassProfileNormalizesEquivalentSpacingPatterns(): void
    {
        $profile = ClassStrategyService::buildSemanticClassProfile(
            [
                [
                    'selector' => '.pricing-card',
                    'declarations' => ['padding' => '24px'],
                ],
                [
                    'selector' => '.feature-card',
                    'declarations' => [
                        'padding-top' => '24px',
                        'padding-right' => '24px',
                        'padding-bottom' => '24px',
                        'padding-left' => '24px',
                    ],
                ],
            ],
            ['pricing-card', 'feature-card']
        );

        $this->assertSame('ohc-card', $profile['aliases']['pricing-card']);
        $this->assertSame('ohc-card', $profile['aliases']['feature-card']);
    }

    public function testOxygenNativeModeAppliesSemanticClassAliases(): void
    {
        $service = $this->createServiceWithWindPressMode(false);
        $service->setClassAliases([
            'pricing-card' => 'ohc-card',
        ]);
        $element = ['data' => ['properties' => []]];

        $service->processClasses(['pricing-card'], $element);

        $this->assertSame(['ohc-card'], $element['data']['properties']['settings']['advanced']['classes']);
    }

    public function testOxygenNativeModePreservesUnsupportedFlexWrapUtilities(): void
    {
        $service = $this->createServiceWithWindPressMode(false);
        $element = ['data' => ['properties' => []]];

        $service->processClasses(['flex', 'flex-wrap', 'flex-nowrap'], $element);

        $this->assertSame(['flex-wrap', 'flex-nowrap'], $element['data']['properties']['settings']['advanced']['classes']);
        $this->assertSame('flex', $element['data']['properties']['design']['layout']['display']);
        $this->assertArrayNotHasKey('flex_wrap', $element['data']['properties']['design']['layout']);
    }

    public function testReportTracksClassCounts(): void
    {
        $service = $this->createServiceWithWindPressMode(true);
        $element = ['data' => ['properties' => []]];
        
        // 2 Tailwind classes, 1 custom class (use 'customclass' to not match patterns)
        $classes = ['flex', 'items-center', 'customclass'];
        $service->processClasses($classes, $element);
        
        $stats = $this->report->toArray();
        
        // flex matches, items-center matches, customclass does NOT match
        $this->assertEquals(2, $stats['tailwindClasses']);
        $this->assertEquals(1, $stats['customClasses']);
    }

    public function testOxygenNativeModeWarnsAboutTailwindClasses(): void
    {
        $service = $this->createServiceWithWindPressMode(false);
        $element = ['data' => ['properties' => []]];
        
        // Include an unsupported Tailwind class to trigger parity-preservation warning.
        $classes = ['container'];
        $service->processClasses($classes, $element);
        
        $stats = $this->report->toArray();
        
        $this->assertNotEmpty($stats['warnings']);
        $this->assertStringContainsString(
            'preserved unsupported Tailwind utilities',
            $stats['warnings'][0]
        );
    }

    public function testElementStructureCreation(): void
    {
        $service = $this->createServiceWithWindPressMode(true);
        
        // Start with minimal element structure
        $element = ['data' => ['properties' => []]];
        
        $service->processClasses(['test-class'], $element);
        
        // Verify full path is created
        $this->assertArrayHasKey('settings', $element['data']['properties']);
        $this->assertArrayHasKey('advanced', $element['data']['properties']['settings']);
        $this->assertArrayHasKey('classes', $element['data']['properties']['settings']['advanced']);
    }

    public function testClassesAreArrayValues(): void
    {
        $service = $this->createServiceWithWindPressMode(true);
        $element = ['data' => ['properties' => []]];
        
        $service->processClasses(['class-a', 'class-b', 'class-c'], $element);
        
        $classes = $element['data']['properties']['settings']['advanced']['classes'];
        
        // Ensure sequential array keys (0, 1, 2...)
        $this->assertEquals([0, 1, 2], array_keys($classes));
    }

    public function testMultipleProcessCallsTrackCumulativeStats(): void
    {
        $service = $this->createServiceWithWindPressMode(true);
        
        $element1 = ['data' => ['properties' => []]];
        $element2 = ['data' => ['properties' => []]];
        
        $service->processClasses(['flex', 'custom-1'], $element1);
        $service->processClasses(['grid', 'custom-2'], $element2);
        
        $stats = $this->report->toArray();
        
        $this->assertEquals(2, $stats['tailwindClasses']); // flex, grid
        $this->assertEquals(2, $stats['customClasses']); // custom-1, custom-2
    }
}
