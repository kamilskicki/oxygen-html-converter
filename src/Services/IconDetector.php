<?php

namespace OxyHtmlConverter\Services;

/**
 * Service to detect and create Oxygen elements for icon libraries
 */
class IconDetector
{
    /**
     * Detect icon libraries used in the document
     */
    public function detectIconLibraries(\DOMDocument $doc): array
    {
        $detectedIconLibraries = [];
        $xpath = new \DOMXPath($doc);

        // Check for Lucide icons
        if ($xpath->query('//*[@data-lucide]')->length > 0) {
            $detectedIconLibraries['lucide'] = [
                'name' => 'Lucide Icons',
                'cdn' => 'https://unpkg.com/lucide@latest',
                'init' => 'lucide.createIcons();',
            ];
        }

        // Check for Feather icons
        if ($xpath->query('//*[@data-feather]')->length > 0) {
            $detectedIconLibraries['feather'] = [
                'name' => 'Feather Icons',
                'cdn' => 'https://unpkg.com/feather-icons',
                'init' => 'feather.replace();',
            ];
        }

        // Check for Font Awesome
        $faElements = $xpath->query('//*[contains(@class, "fa-") or contains(@class, "fas ") or contains(@class, "far ") or contains(@class, "fab ")]');
        if ($faElements->length > 0) {
            $detectedIconLibraries['fontawesome'] = [
                'name' => 'Font Awesome',
                'cdn' => 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
                'type' => 'css',
            ];
        }

        // Check for Bootstrap Icons
        $biElements = $xpath->query('//*[contains(@class, "bi-")]');
        if ($biElements->length > 0) {
            $detectedIconLibraries['bootstrap-icons'] = [
                'name' => 'Bootstrap Icons',
                'cdn' => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
                'type' => 'css',
            ];
        }

        // Check for Material Icons
        $materialElements = $xpath->query('//*[contains(@class, "material-icons")]');
        if ($materialElements->length > 0) {
            $detectedIconLibraries['material-icons'] = [
                'name' => 'Material Icons',
                'cdn' => 'https://fonts.googleapis.com/icon?family=Material+Icons',
                'type' => 'css',
            ];
        }

        return $detectedIconLibraries;
    }

    /**
     * Create HtmlCode elements for detected icon libraries
     *
     * @param array $detectedIconLibraries
     * @param callable $idGenerator Callback to generate unique node IDs
     * @return array
     */
    public function createIconLibraryElements(array $detectedIconLibraries, callable $idGenerator): array
    {
        $elements = [];

        foreach ($detectedIconLibraries as $key => $library) {
            $type = $library['type'] ?? 'js';

            if ($type === 'css') {
                // CSS library - create link tag
                $html = '<link rel="stylesheet" href="' . htmlspecialchars($library['cdn']) . '">';
            } else {
                // JS library - create script tag with initialization
                $html = '<script src="' . htmlspecialchars($library['cdn']) . '"></script>';
                if (!empty($library['init'])) {
                    $html .= "\n<script>document.addEventListener('DOMContentLoaded', function() { " . $library['init'] . " });</script>";
                }
            }

            $elements[] = [
                'id' => $idGenerator(),
                'data' => [
                    'type' => 'OxygenElements\\HtmlCode',
                    'properties' => [
                        'content' => [
                            'content' => [
                                'html_code' => "<!-- {$library['name']} -->\n" . $html,
                            ],
                        ],
                    ],
                ],
                'children' => [],
                '_libraryKey' => $key,
            ];
        }

        return $elements;
    }
}
