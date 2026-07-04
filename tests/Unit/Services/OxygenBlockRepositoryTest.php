<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\OxygenBlockRepository;
use PHPUnit\Framework\TestCase;

class OxygenBlockRepositoryTest extends TestCase
{
    public function testSupportedBlockPostTypeAndRequiredMetaKeys(): void
    {
        $repository = new OxygenBlockRepository();

        $this->assertSame('oxygen_block', $repository->postType());
        $this->assertSame([
            '_oxygen_data',
            '_breakdance_block_settings',
        ], $repository->requiredMetaKeys());
    }

    public function testValidateBlockSpecAcceptsMinimumPublishBlockShape(): void
    {
        $repository = new OxygenBlockRepository();
        $result = $repository->validateBlockSpec($this->blockSpec());

        $this->assertTrue($result['valid'], implode(' ', $result['errors']));
        $this->assertSame('oxygen_block', $result['postType']);
        $this->assertSame($repository->requiredMetaKeys(), $result['metaKeys']);
    }

    public function testValidateBlockSpecRejectsWrongPostTypeDraftStatusAndInvalidSettings(): void
    {
        $spec = $this->blockSpec();
        $spec['post_type'] = 'oxygen_template';
        $spec['post_status'] = 'draft';
        $spec['_breakdance_block_settings'] = [
            'preview' => 'bad',
        ];

        $result = (new OxygenBlockRepository())->validateBlockSpec($spec);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('post_type must be oxygen_block', implode(' ', $result['errors']));
        $this->assertStringContainsString('oxygen_block post_status must be publish', implode(' ', $result['errors']));
        $this->assertStringContainsString('_breakdance_block_settings.preview must be an object', implode(' ', $result['errors']));
    }

    public function testValidateBlockSpecRequiresOxygenDataEnvelope(): void
    {
        $result = (new OxygenBlockRepository())->validateBlockSpec([
            'post_type' => 'oxygen_block',
            'post_status' => 'publish',
            '_oxygen_data' => ['tree_json_string' => '{"root":{"id":"bad"}}'],
            '_breakdance_block_settings' => [],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('root.id must be an integer', implode(' ', $result['errors']));
    }

    /**
     * @return array<string, mixed>
     */
    private function blockSpec(): array
    {
        $tree = [
            'root' => [
                'id' => 0,
                'data' => [
                    'type' => 'root',
                    'properties' => [],
                ],
                'children' => [],
            ],
            '_nextNodeId' => 1,
            'exportedLookupTable' => [],
        ];

        return [
            'post_type' => 'oxygen_block',
            'post_status' => 'publish',
            '_oxygen_data' => [
                'tree_json_string' => wp_json_encode($tree),
            ],
            '_breakdance_block_settings' => [
                'preview' => [
                    'acfFlexibleField' => '',
                    'acfFlexibleFieldRow' => '',
                ],
            ],
        ];
    }
}
