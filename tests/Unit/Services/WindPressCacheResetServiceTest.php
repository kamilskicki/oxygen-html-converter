<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\WindPressCacheResetService;
use PHPUnit\Framework\TestCase;

class WindPressCacheResetServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        remove_all_filters();
    }

    public function testCacheResetIsDisabledByDefault(): void
    {
        $service = new WindPressCacheResetService();

        $this->assertFalse($service->isCacheResetEnabled());

        $result = $service->resetIfEnabled();

        $this->assertFalse($result['enabled']);
        $this->assertFalse($result['attempted']);
        $this->assertSame('windpress_cache_reset_disabled', $result['reason']);
    }

    public function testCacheResetCanBeEnabledThroughFeatureFlagContract(): void
    {
        add_filter('oxy_html_converter_feature_flags', static function (array $flags): array {
            $flags['windpress_cache_reset'] = true;
            return $flags;
        });

        $service = new WindPressCacheResetService();

        $this->assertTrue($service->isCacheResetEnabled());

        $result = $service->resetIfEnabled();

        $this->assertTrue($result['enabled']);
        $this->assertFalse($result['attempted']);
        $this->assertSame('windpress_inactive', $result['reason']);
    }
}
