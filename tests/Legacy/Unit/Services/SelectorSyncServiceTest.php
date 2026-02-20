<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\SelectorSyncService;
use OxyHtmlConverter\Tests\TestCase;

class SelectorSyncServiceTest extends TestCase
{
    private SelectorSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SelectorSyncService();
    }

    public function testMergeSelectorsReusesExistingSemanticDuplicateAndReturnsIdMap(): void
    {
        $existing = [[
            'id' => 'existing-uuid',
            'name' => 'hero',
            'type' => 'class',
            'collection' => 'Default',
            'children' => [],
            'properties' => [
                'breakpoint_base' => [
                    'typography' => ['color' => '#FF0000FF'],
                ],
            ],
        ]];

        $incoming = [[
            'id' => 'incoming-uuid',
            'name' => 'hero-copy',
            'type' => 'class',
            'collection' => 'Default',
            'children' => [],
            'properties' => [
                'breakpoint_base' => [
                    'typography' => ['color' => '#FF0000FF'],
                ],
            ],
        ]];

        $result = $this->service->mergeSelectors($existing, $incoming);

        $this->assertCount(1, $result['selectors']);
        $this->assertEquals(0, $result['added']);
        $this->assertEquals(1, $result['reused']);
        $this->assertEquals('existing-uuid', $result['idMap']['incoming-uuid']);
    }

    public function testMergeSelectorsNormalizesLockedForSelectorsAndChildren(): void
    {
        $existing = [[
            'id' => 'existing-uuid',
            'name' => 'base',
            'type' => 'class',
            'collection' => 'Default',
            'children' => [[
                'id' => 'child-uuid',
                'name' => '&:hover',
                'properties' => [],
            ]],
            'properties' => [],
        ]];

        $incoming = [[
            'id' => 'incoming-uuid',
            'name' => 'new-selector',
            'type' => 'class',
            'collection' => 'Default',
            'children' => [],
            'properties' => ['breakpoint_base' => ['layout' => ['display' => 'flex']]],
        ]];

        $result = $this->service->mergeSelectors($existing, $incoming);

        $this->assertCount(2, $result['selectors']);
        foreach ($result['selectors'] as $selector) {
            $this->assertArrayHasKey('locked', $selector);
            $this->assertIsBool($selector['locked']);
            foreach ($selector['children'] as $child) {
                $this->assertArrayHasKey('locked', $child);
                $this->assertIsBool($child['locked']);
            }
        }
    }

    public function testMergeSelectorsRenamesIncomingWhenNameCollidesWithDifferentDefinition(): void
    {
        $existing = [[
            'id' => 'existing-uuid',
            'name' => 'card',
            'type' => 'class',
            'collection' => 'Default',
            'children' => [],
            'properties' => [
                'breakpoint_base' => ['typography' => ['color' => '#FF0000FF']],
            ],
        ]];

        $incoming = [[
            'id' => 'incoming-uuid',
            'name' => 'card',
            'type' => 'class',
            'collection' => 'Default',
            'children' => [],
            'properties' => [
                'breakpoint_base' => ['typography' => ['color' => '#0000FFFF']],
            ],
        ]];

        $result = $this->service->mergeSelectors($existing, $incoming);

        $this->assertCount(2, $result['selectors']);
        $this->assertEquals(1, $result['added']);
        $this->assertEquals('incoming-uuid', $result['idMap']['incoming-uuid']);

        $incomingSelector = null;
        foreach ($result['selectors'] as $selector) {
            if ($selector['id'] === 'incoming-uuid') {
                $incomingSelector = $selector;
                break;
            }
        }

        $this->assertNotNull($incomingSelector);
        $this->assertNotEquals('card', $incomingSelector['name']);
        $this->assertStringStartsWith('card-', $incomingSelector['name']);
    }
}
