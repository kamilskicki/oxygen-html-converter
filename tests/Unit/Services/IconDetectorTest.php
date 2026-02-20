<?php

namespace OxyHtmlConverter\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use OxyHtmlConverter\Services\IconDetector;
use DOMDocument;

class IconDetectorTest extends TestCase
{
    private IconDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new IconDetector();
    }

    /**
     * @dataProvider iconLibraryDetectionProvider
     */
    public function testDetectIconLibraries(string $html, array $expectedLibraries): void
    {
        $doc = new DOMDocument();
        @$doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        
        $detected = $this->detector->detectIconLibraries($doc);
        
        foreach ($expectedLibraries as $key) {
            $this->assertArrayHasKey($key, $detected, "Should detect {$key} icons");
        }
        
        $this->assertCount(
            count($expectedLibraries),
            $detected,
            'Should detect exactly ' . count($expectedLibraries) . ' icon library(ies)'
        );
    }

    public static function iconLibraryDetectionProvider(): array
    {
        return [
            'Lucide icons' => [
                '<div><i data-lucide="menu"></i></div>',
                ['lucide'],
            ],
            'Feather icons' => [
                '<div><i data-feather="arrow-right"></i></div>',
                ['feather'],
            ],
            'Font Awesome (fa- prefix)' => [
                '<div><i class="fa-solid fa-user"></i></div>',
                ['fontawesome'],
            ],
            'Font Awesome (fas class)' => [
                '<div><i class="fas fa-home"></i></div>',
                ['fontawesome'],
            ],
            'Font Awesome (far class)' => [
                '<div><i class="far fa-envelope"></i></div>',
                ['fontawesome'],
            ],
            'Font Awesome (fab class)' => [
                '<div><i class="fab fa-github"></i></div>',
                ['fontawesome'],
            ],
            'Bootstrap Icons' => [
                '<div><i class="bi-arrow-right"></i></div>',
                ['bootstrap-icons'],
            ],
            'Material Icons' => [
                '<div><span class="material-icons">home</span></div>',
                ['material-icons'],
            ],
            'No icons' => [
                '<div><p>No icons here</p></div>',
                [],
            ],
            'Multiple icon libraries' => [
                '<div><i data-lucide="menu"></i><i class="fas fa-user"></i><i class="bi-home"></i></div>',
                ['lucide', 'fontawesome', 'bootstrap-icons'],
            ],
        ];
    }

    public function testCreateIconLibraryElementsGeneratesCorrectStructure(): void
    {
        $libraries = [
            'lucide' => [
                'name' => 'Lucide Icons',
                'cdn' => 'https://unpkg.com/lucide@latest',
                'init' => 'lucide.createIcons();',
            ],
        ];

        $idCounter = 100;
        $idGenerator = function () use (&$idCounter) {
            return $idCounter++;
        };

        $elements = $this->detector->createIconLibraryElements($libraries, $idGenerator);

        $this->assertCount(1, $elements);
        
        $element = $elements[0];
        $this->assertEquals(100, $element['id']);
        $this->assertEquals('OxygenElements\\HtmlCode', $element['data']['type']);
        $this->assertArrayHasKey('html_code', $element['data']['properties']['content']['content']);
        
        $htmlCode = $element['data']['properties']['content']['content']['html_code'];
        $this->assertStringContainsString('Lucide Icons', $htmlCode);
        $this->assertStringContainsString('https://unpkg.com/lucide@latest', $htmlCode);
        $this->assertStringContainsString('lucide.createIcons();', $htmlCode);
    }

    public function testCreateIconLibraryElementsForCssLibrary(): void
    {
        $libraries = [
            'fontawesome' => [
                'name' => 'Font Awesome',
                'cdn' => 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
                'type' => 'css',
            ],
        ];

        $idGenerator = fn() => 1;
        $elements = $this->detector->createIconLibraryElements($libraries, $idGenerator);

        $this->assertCount(1, $elements);
        
        $htmlCode = $elements[0]['data']['properties']['content']['content']['html_code'];
        $this->assertStringContainsString('<link rel="stylesheet"', $htmlCode);
        $this->assertStringContainsString('font-awesome', $htmlCode);
    }

    public function testCreateIconLibraryElementsWithMultipleLibraries(): void
    {
        $libraries = [
            'lucide' => [
                'name' => 'Lucide Icons',
                'cdn' => 'https://unpkg.com/lucide@latest',
                'init' => 'lucide.createIcons();',
            ],
            'fontawesome' => [
                'name' => 'Font Awesome',
                'cdn' => 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
                'type' => 'css',
            ],
        ];

        $idCounter = 50;
        $idGenerator = function () use (&$idCounter) {
            return $idCounter++;
        };

        $elements = $this->detector->createIconLibraryElements($libraries, $idGenerator);

        $this->assertCount(2, $elements);
        $this->assertEquals(50, $elements[0]['id']);
        $this->assertEquals(51, $elements[1]['id']);
    }

    public function testLibraryKeyStoredInElement(): void
    {
        $libraries = [
            'bootstrap-icons' => [
                'name' => 'Bootstrap Icons',
                'cdn' => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
                'type' => 'css',
            ],
        ];

        $elements = $this->detector->createIconLibraryElements($libraries, fn() => 1);

        $this->assertEquals('bootstrap-icons', $elements[0]['_libraryKey']);
    }
}
