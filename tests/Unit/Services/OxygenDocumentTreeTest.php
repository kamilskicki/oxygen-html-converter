<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\OxygenDocumentTree;
use PHPUnit\Framework\TestCase;

class OxygenDocumentTreeTest extends TestCase
{
    public function testBuildWrapsRootAndAddsBuilderMetadata(): void
    {
        $service = new OxygenDocumentTree();

        $tree = $service->build([
            'id' => 10,
            'data' => ['type' => 'OxygenElements\\Container'],
            'children' => [
                [
                    'id' => 11,
                    'data' => ['type' => 'OxygenElements\\Text'],
                    'children' => [],
                ],
            ],
        ]);

        $this->assertArrayHasKey('root', $tree);
        $this->assertSame(12, $tree['_nextNodeId']);
        $this->assertSame('exported', $tree['status']);
    }

    public function testBuildPreservesExistingBuilderMetadata(): void
    {
        $service = new OxygenDocumentTree();

        $tree = $service->build([
            'root' => [
                'id' => 30,
                'data' => ['type' => 'OxygenElements\\Container'],
                'children' => [],
            ],
            '_nextNodeId' => 99,
            'status' => 'draft',
        ]);

        $this->assertSame(99, $tree['_nextNodeId']);
        $this->assertSame('draft', $tree['status']);
    }
}
