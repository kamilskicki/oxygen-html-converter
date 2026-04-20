<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\UiConfigProvider;
use PHPUnit\Framework\TestCase;

class UiConfigProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        remove_all_filters();
    }

    public function testReturnsCurrentDocsUrls(): void
    {
        $provider = new UiConfigProvider();
        $config = $provider->getConfig();

        $this->assertSame('https://github.com/kamilskicki/oxygen-html-converter#readme', $config['docs']['readme']);
        $this->assertSame(
            'https://github.com/kamilskicki/oxygen-html-converter/blob/master/docs/SUPPORTED_SCOPE.md',
            $config['docs']['supportedScope']
        );
        $this->assertSame(
            'https://github.com/kamilskicki/oxygen-html-converter/blob/master/docs/RELEASE_CHECKLIST.md',
            $config['docs']['releaseChecklist']
        );
    }

    public function testAppliesUiConfigFilter(): void
    {
        add_filter('oxy_html_converter_ui_config', static function (array $config): array {
            $config['docs']['readme'] = 'https://example.com/docs';
            return $config;
        });

        $provider = new UiConfigProvider();
        $config = $provider->getConfig();

        $this->assertSame('https://example.com/docs', $config['docs']['readme']);
    }
}
