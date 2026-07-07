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

            if ($rel !== 'stylesheet') {
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
            $html = $this->normalizeScriptHtml((string) $html, $scriptTag);

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
     * @return list<array<string, mixed>>
     */
    public function extractAssetReferences(DOMDocument $doc): array
    {
        $assets = [];
        $seen = [];
        $xpath = new DOMXPath($doc);

        $linkTags = $doc->getElementsByTagName('link');
        foreach ($linkTags as $linkTag) {
            if (!$linkTag instanceof DOMElement) {
                continue;
            }

            $rel = strtolower(trim((string) $linkTag->getAttribute('rel')));
            $relTokens = array_fill_keys(array_filter(preg_split('/\s+/', $rel) ?: []), true);
            $href = trim((string) $linkTag->getAttribute('href'));
            $as = strtolower(trim((string) $linkTag->getAttribute('as')));
            if ($href === '') {
                continue;
            }

            if (isset($relTokens['stylesheet'])) {
                $type = 'head_stylesheet';
                $location = 'head link[rel=stylesheet]';
                $selector = 'head link[rel=stylesheet]';
            } elseif (isset($relTokens['preload']) && $as === 'font') {
                $type = 'head_font_preload';
                $location = 'head link[rel=preload][as=font]';
                $selector = 'head link[rel=preload][as=font]';
            } else {
                continue;
            }

            $key = 'link|' . $href;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $assets[] = [
                'type' => $type,
                'tag' => 'link',
                'source' => $href,
                'rel' => $rel,
                'as' => $as,
                'location' => $location,
                'selector' => $selector,
            ];
        }

        $scriptTags = $xpath->query('//head//script[@src]');
        if ($scriptTags === false) {
            return $assets;
        }

        foreach ($scriptTags as $scriptTag) {
            if (!$scriptTag instanceof DOMElement) {
                continue;
            }

            $src = trim((string) $scriptTag->getAttribute('src'));
            if ($src === '') {
                continue;
            }

            $key = 'script|' . $src;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $assets[] = [
                'type' => 'head_script',
                'tag' => 'script',
                'source' => $src,
                'location' => 'head script[src]',
                'selector' => 'head script[src]',
            ];
        }

        return $assets;
    }

    /**
     * @return list<array{type:string,source:string,location:string,selector:string,attribute:string}>
     */
    public function extractTemporaryMediaReferences(DOMDocument $doc): array
    {
        $assets = [];
        $seen = [];
        $xpath = new DOMXPath($doc);
        $queries = [
            '//*[@src]' => 'src',
            '//*[@poster]' => 'poster',
            '//*[@href]' => 'href',
        ];

        foreach ($queries as $query => $attribute) {
            $nodes = $xpath->query($query);
            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }

                $source = trim((string) $node->getAttribute($attribute));
                if ($source === '' || !self::isTemporaryExternalUrl($source)) {
                    continue;
                }

                $key = $attribute . '|' . $source . '|' . $this->nodeLocation($node);
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $assets[] = [
                    'type' => $this->temporaryAssetType($node, $attribute),
                    'source' => $source,
                    'location' => $this->nodeLocation($node) . ' @' . $attribute,
                    'selector' => strtolower($node->tagName) . '[' . $attribute . ']',
                    'attribute' => $attribute,
                ];
            }
        }

        $srcsetNodes = $xpath->query('//*[@srcset]');
        if ($srcsetNodes !== false) {
            foreach ($srcsetNodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }

                foreach ($this->srcsetSources((string) $node->getAttribute('srcset')) as $source) {
                    if (!self::isTemporaryExternalUrl($source)) {
                        continue;
                    }

                    $key = 'srcset|' . $source . '|' . $this->nodeLocation($node);
                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $assets[] = [
                        'type' => 'image',
                        'source' => $source,
                        'location' => $this->nodeLocation($node) . ' @srcset',
                        'selector' => strtolower($node->tagName) . '[srcset]',
                        'attribute' => 'srcset',
                    ];
                }
            }
        }

        return $assets;
    }

    public static function isTemporaryExternalUrl(string $source): bool
    {
        if (preg_match('/^https?:\/\//i', $source) !== 1) {
            return false;
        }

        $probe = strtolower($source);

        return str_contains($probe, 'oaidalle')
            || str_contains($probe, 'aida-public')
            || str_contains($probe, 'blob.core.windows.net')
            || str_contains($probe, 'tmp/')
            || str_contains($probe, 'temporary')
            || str_contains($probe, 'expires=')
            || str_contains($probe, 'x-amz-expires');
    }

    private function normalizeScriptHtml(string $html, DOMElement $scriptTag): string
    {
        if (trim((string) $scriptTag->getAttribute('src')) !== '') {
            return $html;
        }

        $patterns = [
            [
                'pattern' => '/(?<![\w$.])window\s*\.\s*tailwind\s*\.\s*config\s*=/',
                'guard' => 'window.tailwind = window.tailwind || {};',
                'replacement' => 'window.tailwind.config =',
            ],
            [
                'pattern' => '/(?<![\w$.])globalThis\s*\.\s*tailwind\s*\.\s*config\s*=/',
                'guard' => 'globalThis.tailwind = globalThis.tailwind || {};',
                'replacement' => 'globalThis.tailwind.config =',
            ],
            [
                'pattern' => '/(?<![\w$.])tailwind\s*\.\s*config\s*=/',
                'guard' => 'window.tailwind = window.tailwind || {};',
                'replacement' => 'window.tailwind.config =',
            ],
        ];

        $normalized = $html;
        foreach ($patterns as $entry) {
            if (preg_match($entry['pattern'], $normalized) !== 1) {
                continue;
            }

            $guard = str_contains($normalized, $entry['guard'])
                ? ''
                : $entry['guard'] . "\n";
            $normalized = (string) preg_replace(
                $entry['pattern'],
                $guard . $entry['replacement'],
                $normalized,
                1
            );
        }

        if ($normalized === $html) {
            return $html;
        }

        return $normalized;
    }

    private function temporaryAssetType(DOMElement $node, string $attribute): string
    {
        $tag = strtolower($node->tagName);
        if ($tag === 'video' || $tag === 'source') {
            return $attribute === 'poster' ? 'poster' : 'video';
        }

        if ($tag === 'link') {
            return 'head_asset';
        }

        return 'image';
    }

    /**
     * @return list<string>
     */
    private function srcsetSources(string $srcset): array
    {
        $sources = [];
        foreach (explode(',', $srcset) as $candidate) {
            $parts = preg_split('/\s+/', trim($candidate)) ?: [];
            $source = trim((string) ($parts[0] ?? ''));
            if ($source !== '') {
                $sources[] = $source;
            }
        }

        return array_values(array_unique($sources));
    }

    private function nodeLocation(DOMElement $node): string
    {
        $parts = [];
        $current = $node;

        while ($current instanceof DOMElement) {
            $parts[] = strtolower($current->tagName);
            if (!($current->parentNode instanceof DOMElement)) {
                break;
            }

            $current = $current->parentNode;
        }

        return implode(' > ', array_reverse($parts));
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
