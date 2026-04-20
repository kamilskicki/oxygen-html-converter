<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\PreviewSummaryBuilder;
use PHPUnit\Framework\TestCase;

class PreviewSummaryBuilderTest extends TestCase
{
    public function testBuildCountsNestedElementTypes(): void
    {
        $builder = new PreviewSummaryBuilder();

        $summary = $builder->build([
            'data' => ['type' => 'OxygenElements\\Container'],
            'children' => [
                [
                    'data' => ['type' => 'OxygenElements\\Text'],
                    'children' => [],
                ],
                [
                    'data' => ['type' => 'OxygenElements\\Container'],
                    'children' => [
                        [
                            'data' => ['type' => 'EssentialElements\\Button'],
                            'children' => [],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame(4, $summary['total']);
        $this->assertSame(2, $summary['byType']['Container']);
        $this->assertSame(1, $summary['byType']['Text']);
        $this->assertSame(1, $summary['byType']['Button']);
    }
}
