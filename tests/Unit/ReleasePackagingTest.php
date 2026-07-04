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
}
