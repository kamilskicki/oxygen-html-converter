<?php

namespace OxyHtmlConverter\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/release_common.php';

class ReleasePackagingTest extends TestCase
{
    public function testDistignoreExcludesLocalAuditAndScreenshotArtifacts(): void
    {
        $patterns = release_distignore_patterns();

        $this->assertTrue(release_should_exclude('.screens/source.png', $patterns));
        $this->assertTrue(release_should_exclude('.tmp-live-audit/summary.json', $patterns));
        $this->assertTrue(release_should_exclude('.tmp-playwright/inspect.spec.js', $patterns));
        $this->assertTrue(release_should_exclude('.tmp-page-86-oxygen-data.json', $patterns));
        $this->assertTrue(release_should_exclude('.gitattributes', $patterns));
        $this->assertTrue(release_should_exclude('release-audit.md', $patterns));
        $this->assertTrue(release_should_exclude('skill/oxygen-builder-browser/SKILL.md', $patterns));
    }

    public function testProcessOptionsUseExplicitValuesAndRejectInvalidLimits(): void
    {
        $options = release_process_options([
            'timeoutSeconds' => 12.5,
            'outputLimitBytes' => 4096,
            'pollIntervalMicroseconds' => 25000,
        ], []);

        $this->assertSame(12.5, $options['timeoutSeconds']);
        $this->assertSame(4096, $options['outputLimitBytes']);
        $this->assertSame(25000, $options['pollIntervalMicroseconds']);

        $defaults = release_process_options([], [
            'OXY_HTML_CONVERTER_COMMAND_TIMEOUT' => false,
            'OXY_HTML_CONVERTER_OUTPUT_CAP_BYTES' => false,
        ]);
        $this->assertSame(900.0, $defaults['timeoutSeconds']);
        $this->assertSame(4 * 1024 * 1024, $defaults['outputLimitBytes']);

        $this->expectException(\InvalidArgumentException::class);
        release_process_options(['timeoutSeconds' => 0], []);
    }

    public function testOutputCaptureNeverExceedsItsByteLimit(): void
    {
        $capture = release_new_output_capture();

        release_capture_output($capture, 'abcdef', 8);
        release_capture_output($capture, 'ghijkl', 8);

        $this->assertSame('abcdefgh', $capture['content']);
        $this->assertSame(12, $capture['receivedBytes']);
        $this->assertTrue($capture['truncated']);
    }

    public function testTimeoutCleanupPlanTargetsDescendantsOnWindows(): void
    {
        $this->assertSame(
            [['taskkill', '/PID', '4321', '/T', '/F']],
            release_timeout_cleanup_commands(4321, true)
        );
    }

    public function testTimeoutCleanupPlanTerminatesUnixDescendantsBeforeParent(): void
    {
        $processTable = "100 1\n101 100\n102 101\n103 100\n";

        $this->assertSame(
            [
                ['kill', '-TERM', '102'],
                ['kill', '-TERM', '101'],
                ['kill', '-TERM', '103'],
                ['kill', '-TERM', '100'],
            ],
            release_timeout_cleanup_commands(100, false, $processTable)
        );
    }
}
