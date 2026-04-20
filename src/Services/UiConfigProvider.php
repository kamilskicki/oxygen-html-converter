<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

/**
 * Shared UI/docs configuration exposed to admin and builder clients.
 */
class UiConfigProvider
{
    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        $baseDocsUrl = 'https://github.com/kamilskicki/oxygen-html-converter';

        $config = [
            'docs' => [
                'readme' => $baseDocsUrl . '#readme',
                'supportedScope' => $baseDocsUrl . '/blob/master/docs/SUPPORTED_SCOPE.md',
                'releaseChecklist' => $baseDocsUrl . '/blob/master/docs/RELEASE_CHECKLIST.md',
            ],
            'examples' => [
                'hero' => "<section class=\"hero\">\n  <h1>Build native Oxygen pages from HTML</h1>\n  <p>Paste full landing-page sections and keep them editable.</p>\n  <a href=\"#cta\" class=\"btn btn-primary\">Start importing</a>\n</section>",
            ],
        ];

        return (array) apply_filters('oxy_html_converter_ui_config', $config);
    }
}
