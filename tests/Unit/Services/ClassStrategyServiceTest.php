<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use OxyHtmlConverter\Services\ClassStrategyService;
use OxyHtmlConverter\Services\EnvironmentService;
use OxyHtmlConverter\Services\TailwindDetector;
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
            new TailwindDetector()
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

    public function testOxygenNativeModePreservesAllClassesForNow(): void
    {
        // Current implementation preserves all classes even in native mode
        // (Tailwind-to-properties conversion not yet implemented)
        $service = $this->createServiceWithWindPressMode(false);
        $element = ['data' => ['properties' => []]];
        
        $classes = ['flex', 'items-center', 'my-custom-class'];
        $service->processClasses($classes, $element);
        
        $storedClasses = $element['data']['properties']['settings']['advanced']['classes'];
        
        // In native mode, classes are reordered: custom first, then tailwind
        $this->assertCount(3, $storedClasses);
        $this->assertContains('flex', $storedClasses);
        $this->assertContains('items-center', $storedClasses);
        $this->assertContains('my-custom-class', $storedClasses);
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
        
        // Include Tailwind classes to trigger warning
        $classes = ['flex', 'items-center'];
        $service->processClasses($classes, $element);
        
        $stats = $this->report->toArray();
        
        $this->assertNotEmpty($stats['warnings']);
        $this->assertStringContainsString(
            'Tailwind class conversion to properties not yet implemented',
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
