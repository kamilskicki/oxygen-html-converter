<?php

namespace OxyHtmlConverter\Tests\Unit\Compatibility;

use PHPUnit\Framework\TestCase;

/**
 * Optional live compatibility checks against installed builder plugin sources.
 *
 * These tests auto-skip when local plugin directories are unavailable.
 */
class InstalledBuilderContractsTest extends TestCase
{
    private function resolveWorkspaceRoot(): string
    {
        return dirname(__DIR__, 6);
    }

    private function resolveBreakdanceElementsButtonFile(): ?string
    {
        $envDir = getenv('OXY_HTML_CONVERTER_BREAKDANCE_ELEMENTS_DIR');
        $candidates = array_filter([
            $envDir ? rtrim($envDir, "\\/") . DIRECTORY_SEPARATOR . 'elements' . DIRECTORY_SEPARATOR . 'Button' . DIRECTORY_SEPARATOR . 'element.php' : null,
            $this->resolveWorkspaceRoot() . DIRECTORY_SEPARATOR . 'breakdance-elements-for-oxygen' . DIRECTORY_SEPARATOR . 'elements' . DIRECTORY_SEPARATOR . 'Button' . DIRECTORY_SEPARATOR . 'element.php',
        ]);

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveOxygenHtml5VideoFile(): ?string
    {
        $envDir = getenv('OXY_HTML_CONVERTER_OXYGEN_DIR');
        $candidates = array_filter([
            $envDir ? rtrim($envDir, "\\/") . DIRECTORY_SEPARATOR . 'subplugins' . DIRECTORY_SEPARATOR . 'oxygen-elements' . DIRECTORY_SEPARATOR . 'elements' . DIRECTORY_SEPARATOR . 'HTML5_Video' . DIRECTORY_SEPARATOR . 'element.php' : null,
            $this->resolveWorkspaceRoot() . DIRECTORY_SEPARATOR . 'oxygen' . DIRECTORY_SEPARATOR . 'subplugins' . DIRECTORY_SEPARATOR . 'oxygen-elements' . DIRECTORY_SEPARATOR . 'elements' . DIRECTORY_SEPARATOR . 'HTML5_Video' . DIRECTORY_SEPARATOR . 'element.php',
        ]);

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function testBreakdanceEssentialButtonSourceContract(): void
    {
        $file = $this->resolveBreakdanceElementsButtonFile();
        if ($file === null) {
            $this->markTestSkipped('Breakdance Elements for Oxygen source not found.');
        }

        $content = file_get_contents($file);
        $this->assertIsString($content);
        $this->assertStringContainsString('class Button extends \\Breakdance\\Elements\\Element', $content);
        $this->assertStringContainsString('content.content.text', $content);
        $this->assertStringContainsString('content.content.link.url', $content);
        $this->assertStringContainsString('availableIn', $content);
        $this->assertStringContainsString("'oxygen'", $content);
    }

    public function testOxygenHtml5VideoSourceContract(): void
    {
        $file = $this->resolveOxygenHtml5VideoFile();
        if ($file === null) {
            $this->markTestSkipped('Oxygen HTML5 Video element source not found.');
        }

        $content = file_get_contents($file);
        $this->assertIsString($content);
        $this->assertStringContainsString('OxygenElements\\\\Html5Video', $content);
        $this->assertStringContainsString('video_file_url', $content);
    }
}

