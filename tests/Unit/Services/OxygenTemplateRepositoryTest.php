<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\OxygenTemplateRepository;
use PHPUnit\Framework\TestCase;

class OxygenTemplateRepositoryTest extends TestCase
{
    public function testSupportedTemplatePostTypesAndRequiredMetaKeys(): void
    {
        $repository = new OxygenTemplateRepository();

        $this->assertSame([
            'oxygen_template',
            'oxygen_header',
            'oxygen_footer',
            'oxygen_part',
        ], $repository->supportedPostTypes());
        $this->assertSame([
            '_oxygen_data',
            '_oxygen_template_settings',
        ], $repository->requiredMetaKeys());
    }

    public function testValidateTemplateSpecAcceptsEveryTemplatePostType(): void
    {
        $repository = new OxygenTemplateRepository();

        foreach ($repository->supportedPostTypes() as $postType) {
            $result = $repository->validateTemplateSpec($this->templateSpec($postType));

            $this->assertTrue($result['valid'], $postType . ': ' . implode(' ', $result['errors']));
            $this->assertSame($postType, $result['postType']);
            $this->assertSame($repository->requiredMetaKeys(), $result['metaKeys']);
        }
    }

    public function testValidateTemplateSpecRejectsUnsupportedPostTypeAndInvalidSettings(): void
    {
        $spec = $this->templateSpec('page');
        $spec['_oxygen_template_settings'] = wp_json_encode([
            'type' => '',
            'ruleGroups' => 'bad',
            'priority' => 'high',
            'fallback' => 'no',
        ]);

        $result = (new OxygenTemplateRepository())->validateTemplateSpec($spec);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Unsupported Oxygen template post type "page"', implode(' ', $result['errors']));
        $this->assertStringContainsString('_oxygen_template_settings.type must be a non-empty string', implode(' ', $result['errors']));
        $this->assertStringContainsString('_oxygen_template_settings.ruleGroups must be an array', implode(' ', $result['errors']));
        $this->assertStringContainsString('_oxygen_template_settings.priority must be an integer', implode(' ', $result['errors']));
        $this->assertStringContainsString('_oxygen_template_settings.fallback must be a boolean', implode(' ', $result['errors']));
    }

    public function testValidateTemplateSpecRequiresOxygenDataEnvelopeAndTemplateSettingsJsonString(): void
    {
        $result = (new OxygenTemplateRepository())->validateTemplateSpec([
            'post_type' => 'oxygen_template',
            '_oxygen_data' => ['tree_json_string' => '{"root":{"id":"bad"}}'],
            '_oxygen_template_settings' => ['type' => 'everywhere'],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('root.id must be an integer', implode(' ', $result['errors']));
        $this->assertStringContainsString('_oxygen_template_settings must be a JSON string', implode(' ', $result['errors']));
    }

    /**
     * @return array<string, mixed>
     */
    private function templateSpec(string $postType): array
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
            'post_type' => $postType,
            'post_status' => 'publish',
            '_oxygen_data' => [
                'tree_json_string' => wp_json_encode($tree),
            ],
            '_oxygen_template_settings' => wp_json_encode([
                'type' => 'everywhere',
                'ruleGroups' => [],
                'triggers' => [],
                'priority' => 1,
                'fallback' => false,
            ]),
        ];
    }
}
