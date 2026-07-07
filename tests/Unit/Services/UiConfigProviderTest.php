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

    public function testExposesOptionalIntegrationFeatureFlagsDisabledByDefault(): void
    {
        $provider = new UiConfigProvider();
        $config = $provider->getConfig();

        $this->assertTrue($config['featureFlags']['tailwind_native_mapping']);
        $this->assertTrue($config['featureFlags']['tailwind_fallback_css']);
        $this->assertFalse($config['featureFlags']['tailwind_runtime_integration']);
        $this->assertFalse($config['featureFlags']['windpress_integration']);
        $this->assertFalse($config['featureFlags']['windpress_class_mode']);
        $this->assertFalse($config['featureFlags']['windpress_cache_reset']);
        $this->assertSame('core_native_hints', $config['integrations']['tailwind']['scope']);
        $this->assertSame('pro_optional', $config['integrations']['windpress']['scope']);
        $this->assertFalse($config['integrations']['windpress']['enabled']);
        $this->assertFalse($config['integrations']['windpress']['classModeSelection']);
        $this->assertFalse($config['integrations']['windpress']['cacheReset']);
    }

    public function testProductBoundaryConfigDoesNotExposeDeferredOperationsAsActive(): void
    {
        $provider = new UiConfigProvider();
        $config = $provider->getConfig();

        foreach (['advancedComponents', 'forms', 'dynamicData', 'loops', 'woocommerce'] as $key) {
            $this->assertArrayHasKey($key, $config['productBoundaries']);
            $this->assertFalse($config['productBoundaries'][$key]['active']);
            $this->assertNotSame('core', $config['productBoundaries'][$key]['status']);
            $this->assertNotSame('', $config['productBoundaries'][$key]['extensionPoint']);
            $this->assertNotSame('', $config['productBoundaries'][$key]['remediation']);
        }

        $this->assertSame('unsupported', $config['productBoundaries']['forms']['status']);
        $this->assertSame('pro', $config['productBoundaries']['dynamicData']['status']);
        $this->assertSame('pro', $config['productBoundaries']['woocommerce']['status']);
    }

    public function testFeatureFlagFilterCanEnableWindPressIntegrationContract(): void
    {
        add_filter('oxy_html_converter_feature_flags', static function (array $flags): array {
            $flags['windpress_integration'] = true;
            $flags['windpress_class_mode'] = true;
            $flags['windpress_cache_reset'] = true;
            return $flags;
        });

        $provider = new UiConfigProvider();
        $config = $provider->getConfig();

        $this->assertTrue($config['integrations']['windpress']['enabled']);
        $this->assertTrue($config['integrations']['windpress']['classModeSelection']);
        $this->assertTrue($config['integrations']['windpress']['cacheReset']);
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
