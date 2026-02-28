<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\EnvironmentService;
use OxyHtmlConverter\Tests\TestCase;
use Mockery;

/**
 * Unit tests for EnvironmentService
 * 
 * Note: Since we are testing functions like class_exists, defined, and function_exists
 * which are hard to mock without extensions like runkit or uopz, we focus on what we can 
 * verify or assume about the logic flow.
 */
class EnvironmentServiceTest extends TestCase
{
    private $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EnvironmentService();
    }

    /**
     * @test
     */
    public function it_returns_auto_as_default_class_handling_mode()
    {
        $this->assertEquals('auto', $this->service->getClassHandlingMode());
    }

    /**
     * @test
     */
    public function it_should_use_windpress_mode_if_mode_is_windpress()
    {
        // We need to mock getClassHandlingMode to return 'windpress'
        // Since it's a simple service, we can create a partial mock or a subclass for testing
        $mockService = Mockery::mock(EnvironmentService::class)->makePartial();
        $mockService->shouldReceive('getClassHandlingMode')->andReturn('windpress');

        $this->assertTrue($mockService->shouldUseWindPressMode());
    }

    /**
     * @test
     */
    public function it_should_not_use_windpress_mode_if_mode_is_oxygen()
    {
        $mockService = Mockery::mock(EnvironmentService::class)->makePartial();
        $mockService->shouldReceive('getClassHandlingMode')->andReturn('oxygen');

        $this->assertFalse($mockService->shouldUseWindPressMode());
    }

    /**
     * @test
     */
    public function it_uses_is_windpress_active_when_mode_is_auto()
    {
        $mockService = Mockery::mock(EnvironmentService::class)->makePartial();
        $mockService->shouldReceive('getClassHandlingMode')->andReturn('auto');
        
        // Test when active
        $mockService->shouldReceive('isWindPressActive')->once()->andReturn(true);
        $this->assertTrue($mockService->shouldUseWindPressMode());

        // Test when inactive
        $mockService->shouldReceive('isWindPressActive')->once()->andReturn(false);
        $this->assertFalse($mockService->shouldUseWindPressMode());
    }

    /**
     * @test
     */
    public function it_returns_auto_as_default_element_mapping_mode()
    {
        $this->assertEquals('auto', $this->service->getElementMappingMode());
    }

    /**
     * @test
     */
    public function it_should_not_prefer_essential_elements_when_mode_is_oxygen()
    {
        $mockService = Mockery::mock(EnvironmentService::class)->makePartial();
        $mockService->shouldReceive('getElementMappingMode')->andReturn('oxygen');

        $this->assertFalse($mockService->shouldPreferEssentialElements());
    }

    /**
     * @test
     */
    public function it_should_prefer_essential_elements_when_mode_is_essential_and_plugin_is_active()
    {
        $mockService = Mockery::mock(EnvironmentService::class)->makePartial();
        $mockService->shouldReceive('getElementMappingMode')->andReturn('essential');
        $mockService->shouldReceive('isBreakdanceElementsForOxygenActive')->once()->andReturn(true);
        $mockService->shouldReceive('isEssentialButtonContractCompatible')->once()->andReturn(true);

        $this->assertTrue($mockService->shouldPreferEssentialElements());
    }

    /**
     * @test
     */
    public function it_should_not_prefer_essential_elements_when_mode_is_essential_and_plugin_is_inactive()
    {
        $mockService = Mockery::mock(EnvironmentService::class)->makePartial();
        $mockService->shouldReceive('getElementMappingMode')->andReturn('essential');
        $mockService->shouldReceive('isBreakdanceElementsForOxygenActive')->once()->andReturn(false);

        $this->assertFalse($mockService->shouldPreferEssentialElements());
    }

    /**
     * @test
     */
    public function it_should_follow_plugin_detection_when_element_mapping_mode_is_auto()
    {
        $mockService = Mockery::mock(EnvironmentService::class)->makePartial();
        $mockService->shouldReceive('getElementMappingMode')->andReturn('auto');
        $mockService->shouldReceive('isBreakdanceElementsForOxygenActive')->once()->andReturn(true);
        $mockService->shouldReceive('isEssentialButtonContractCompatible')->once()->andReturn(true);
        $this->assertTrue($mockService->shouldPreferEssentialElements());

        $mockService->shouldReceive('isBreakdanceElementsForOxygenActive')->once()->andReturn(false);
        $this->assertFalse($mockService->shouldPreferEssentialElements());
    }

    /**
     * @test
     */
    public function it_should_not_prefer_essential_elements_when_contract_is_incompatible()
    {
        $mockService = Mockery::mock(EnvironmentService::class)->makePartial();
        $mockService->shouldReceive('getElementMappingMode')->andReturn('essential');
        $mockService->shouldReceive('isBreakdanceElementsForOxygenActive')->once()->andReturn(true);
        $mockService->shouldReceive('isEssentialButtonContractCompatible')->once()->andReturn(false);

        $this->assertFalse($mockService->shouldPreferEssentialElements());
    }

    /**
     * @test
     */
    public function it_returns_empty_contract_issues_when_status_does_not_contain_issues()
    {
        $mockService = Mockery::mock(EnvironmentService::class)->makePartial();
        $mockService->shouldReceive('getEssentialButtonContractStatus')
            ->once()
            ->andReturn(['compatible' => false]);

        $this->assertSame([], $mockService->getEssentialButtonContractIssues());
    }

    /**
     * @test
     */
    public function it_returns_contract_issues_from_status()
    {
        $mockService = Mockery::mock(EnvironmentService::class)->makePartial();
        $mockService->shouldReceive('getEssentialButtonContractStatus')
            ->once()
            ->andReturn([
                'compatible' => false,
                'issues' => ['Issue A', 'Issue B'],
            ]);

        $this->assertSame(['Issue A', 'Issue B'], $mockService->getEssentialButtonContractIssues());
    }

    /**
     * @test
     */
    public function it_reports_contract_compatibility_from_status()
    {
        $mockService = Mockery::mock(EnvironmentService::class)->makePartial();
        $mockService->shouldReceive('getEssentialButtonContractStatus')
            ->once()
            ->andReturn(['compatible' => true, 'issues' => []]);

        $this->assertTrue($mockService->isEssentialButtonContractCompatible());
    }
}
