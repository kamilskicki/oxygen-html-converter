<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use OxyHtmlConverter\ElementTypes;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Preserves supported <head> assets as HtmlCode elements.
 */
class HeadAssetExtractor
{
    /**
     * @param \Closure():int $nodeIdGenerator
     */
    public function __construct(private readonly \Closure $nodeIdGenerator)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function extractLinks(DOMDocument $doc): array
    {
        $elements = [];
        $seenSignatures = [];
        $linkTags = $doc->getElementsByTagName('link');

        foreach ($linkTags as $linkTag) {
            $rel = strtolower($linkTag->getAttribute('rel'));

            if (!in_array($rel, ['stylesheet', 'preconnect'], true)) {
                continue;
            }

            $html = $doc->saveHTML($linkTag);
            if (trim((string) $html) === '') {
                continue;
            }

            $signature = strtolower($rel) . '|' . trim((string) $linkTag->getAttribute('href')) . '|' . trim((string) $linkTag->getAttribute('as'));
            if ($signature === '|' || isset($seenSignatures[$signature])) {
                continue;
            }

            $seenSignatures[$signature] = true;
            $elements[] = $this->createHtmlCodeElement((string) $html);
        }

        return $elements;
    }

    /**
     * @param array<int, array<string, mixed>> $detectedIconLibraries
     * @return array<int, array<string, mixed>>
     */
    public function extractScripts(DOMDocument $doc, array $detectedIconLibraries = []): array
    {
        $elements = [];
        $iconCdns = [];
        $seenSignatures = [];

        foreach ($detectedIconLibraries as $library) {
            $cdn = trim((string) ($library['cdn'] ?? ''));
            if ($cdn !== '') {
                $iconCdns[] = $cdn;
            }
        }

        $xpath = new DOMXPath($doc);
        $scriptTags = $xpath->query('//head//script');
        if ($scriptTags === false) {
            return $elements;
        }

        foreach ($scriptTags as $scriptTag) {
            if (!($scriptTag instanceof DOMElement)) {
                continue;
            }

            $src = trim((string) $scriptTag->getAttribute('src'));
            if ($src !== '' && in_array($src, $iconCdns, true)) {
                continue;
            }

            $html = $doc->saveHTML($scriptTag);
            if (trim((string) $html) === '') {
                continue;
            }

            $signature = $src !== '' ? 'src|' . $src : 'inline|' . md5(trim((string) $html));
            if (isset($seenSignatures[$signature])) {
                continue;
            }

            $seenSignatures[$signature] = true;
            $elements[] = $this->createHtmlCodeElement((string) $html);
        }

        return $elements;
    }

    /**
     * @return array<string, mixed>
     */
    private function createHtmlCodeElement(string $html): array
    {
        $generateNodeId = $this->nodeIdGenerator;

        return [
            'id' => $generateNodeId(),
            'data' => [
                'type' => ElementTypes::HTML_CODE,
                'properties' => [
                    'content' => [
                        'content' => [
                            'html_code' => $html,
                        ],
                    ],
                ],
            ],
            'children' => [],
        ];
    }
}
