<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use OxyHtmlConverter\Services\RequestOptions;
use PHPUnit\Framework\TestCase;

class RequestOptionsTest extends TestCase
{
    public function testNormalizeConvertDefaultsToNoVisibleCssCodeFallback(): void
    {
        $options = (new RequestOptions())->normalizeConvert([]);

        $this->assertFalse($options['includeCssElement']);
        $this->assertTrue($options['safeMode']);
        $this->assertFalse($options['allowExecutableCode']);
        $this->assertFalse($options['strictNative']);
    }

    public function testNormalizeConvertAllowsExplicitVisibleCssCodeFallbackOptIn(): void
    {
        $options = (new RequestOptions())->normalizeConvert([
            'includeCssElement' => 'true',
        ]);

        $this->assertTrue($options['includeCssElement']);
    }

    public function testNormalizeConvertRequiresUnsafeModeForExecutableCodeOptIn(): void
    {
        $safeOptions = (new RequestOptions())->normalizeConvert([
            'allowExecutableCode' => 'true',
        ]);
        $unsafeOptions = (new RequestOptions())->normalizeConvert([
            'safeMode' => 'false',
            'allowExecutableCode' => 'true',
        ]);
        $strictOptions = (new RequestOptions())->normalizeConvert([
            'safeMode' => 'false',
            'strictNative' => 'true',
            'allowExecutableCode' => 'true',
        ]);

        $this->assertFalse($safeOptions['allowExecutableCode']);
        $this->assertTrue($unsafeOptions['allowExecutableCode']);
        $this->assertFalse($strictOptions['allowExecutableCode']);
    }
}
