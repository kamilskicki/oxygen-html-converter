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
        $this->assertSame(0, $summary['codeBlocks']['total']);
    }

    public function testBuildReportsVisibleCodeBlockCounts(): void
    {
        $builder = new PreviewSummaryBuilder();

        $summary = $builder->build([
            'data' => ['type' => 'OxygenElements\\Container'],
            'children' => [
                [
                    'data' => ['type' => 'OxygenElements\\CssCode'],
                    'children' => [],
                ],
                [
                    'data' => ['type' => 'OxygenElements\\JavaScriptCode'],
                    'children' => [],
                ],
                [
                    'data' => ['type' => 'OxygenElements\\HtmlCode'],
                    'children' => [],
                ],
            ],
        ]);

        $this->assertSame(3, $summary['codeBlocks']['total']);
        $this->assertSame(1, $summary['codeBlocks']['html']);
        $this->assertSame(1, $summary['codeBlocks']['css']);
        $this->assertSame(1, $summary['codeBlocks']['javascript']);
    }
}
