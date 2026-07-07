<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;

class AssetNormalizationService
{
    /**
     * @param list<array<string, mixed>> $headAssets
     * @param array<string, array<string, mixed>> $iconLibraries
     * @return array<string, mixed>
     */
    public function buildReport(DOMDocument $document, string $css, array $headAssets = [], array $iconLibraries = []): array
    {
        $assets = [];

        foreach ($headAssets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $source = $this->stringField($asset, 'source');
            if ($source === '') {
                continue;
            }

            $type = $this->headAssetType($asset);
            $assets[] = $this->assetRecord(
                $type,
                $source,
                $this->stringField($asset, 'location', 'head asset'),
                'head',
                $this->stringField($asset, 'selector', 'head ' . $this->stringField($asset, 'tag', 'asset'))
            );
        }

        foreach ($this->extractCssUrls($css) as $asset) {
            $assets[] = $this->assetRecord(
                $this->classifyUrl($asset['source']),
                $asset['source'],
                $asset['location'],
                'css',
                'css url()'
            );
        }

        foreach ($this->extractDomAssets($document) as $asset) {
            $assets[] = $this->assetRecord(
                $asset['type'],
                $asset['source'],
                $asset['location'],
                'dom',
                $asset['selector']
            );
        }

        foreach ($iconLibraries as $key => $library) {
            if (!is_array($library)) {
                continue;
            }

            $source = $this->stringField($library, 'cdn');
            if ($source === '') {
                continue;
            }

            $record = $this->assetRecord(
                'icon',
                $source,
                'document icon library: ' . $this->stringField($library, 'name', (string) $key),
                'icon_library',
                'icon-library[' . (string) $key . ']'
            );
            $record['license'] = $this->stringField($library, 'license', $record['license']);
            $record['cachePolicy'] = $this->stringField($library, 'cachePolicy', $record['cachePolicy']);
            $assets[] = $record;
        }

        $assets = $this->dedupeAssets($assets);

        return [
            'version' => 1,
            'policy' => 'Core records asset normalization decisions without downloading remote assets during conversion.',
            'summary' => $this->summarize($assets),
            'assets' => $assets,
        ];
    }

    /**
     * @param array<string, mixed> $report
     * @return list<string>
     */
    public function rejectedSources(array $report): array
    {
        $sources = [];
        $assets = is_array($report['assets'] ?? null) ? $report['assets'] : [];

        foreach ($assets as $asset) {
            if (!is_array($asset) || ($asset['status'] ?? '') !== 'rejected') {
                continue;
            }

            $source = $this->stringField($asset, 'source');
            if ($source !== '') {
                $sources[] = $source;
            }
        }

        return array_values(array_unique($sources));
    }

    /**
     * @param array<string, mixed> $report
     */
    public function sanitizeCss(string $css, array $report): string
    {
        $rejected = array_flip($this->rejectedSources($report));
        if ($rejected === [] || $css === '') {
            return $css;
        }

        $css = (string) preg_replace_callback(
            '/@import\s+(?:url\(\s*)?(?:"([^"]*)"|\'([^\']*)\'|([^);\s]+))(?:\s*\))?[^;]*;/i',
            static function (array $matches) use ($rejected): string {
                $url = trim(self::firstRegexCapture($matches), " \t\n\r\0\x0B\"'");

                return isset($rejected[$url]) ? '' : (string) $matches[0];
            },
            $css
        );

        return (string) preg_replace_callback(
            '/url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)]*))\s*\)/i',
            static function (array $matches) use ($rejected): string {
                $url = self::firstRegexCapture($matches);
                $url = trim($url, " \t\n\r\0\x0B\"'");

                return isset($rejected[$url]) ? 'url("")' : (string) $matches[0];
            },
            $css
        );
    }

    /**
     * @param array<string, mixed> $element
     * @param array<string, mixed> $report
     */
    public function applyRejectedPolicyToElement(array &$element, array $report): void
    {
        $rejectedSources = array_flip($this->rejectedSources($report));
        if ($rejectedSources === []) {
            return;
        }

        $this->replaceRejectedAssetStrings($element, $rejectedSources);
    }

    /**
     * @return list<array{source:string,location:string}>
     */
    private function extractCssUrls(string $css): array
    {
        if ($css === '') {
            return [];
        }

        $assets = [];
        $seen = [];
        $matches = [];

        preg_match_all('/url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)]*))\s*\)/i', $css, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $source = self::firstRegexCapture($match);
            $source = trim($source, " \t\n\r\0\x0B\"'");
            if ($source === '' || isset($seen[$source])) {
                continue;
            }

            $seen[$source] = true;
            $assets[] = [
                'source' => $source,
                'location' => 'stylesheet url()',
            ];
        }

        $matches = [];
        preg_match_all('/@import\s+(?:url\(\s*)?(?:"([^"]*)"|\'([^\']*)\'|([^);\s]+))(?:\s*\))?[^;]*;/i', $css, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $source = self::firstRegexCapture($match);
            $source = trim($source, " \t\n\r\0\x0B\"'");
            if ($source === '' || isset($seen[$source])) {
                continue;
            }

            $seen[$source] = true;
            $assets[] = [
                'source' => $source,
                'location' => 'stylesheet @import',
            ];
        }

        return $assets;
    }

    /**
     * @param mixed $value
     * @param array<string, int> $rejectedSources
     */
    private function replaceRejectedAssetStrings(&$value, array $rejectedSources): void
    {
        if (is_string($value)) {
            if (isset($rejectedSources[$value])) {
                $value = '';
            }

            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as &$childValue) {
            $this->replaceRejectedAssetStrings($childValue, $rejectedSources);
        }
        unset($childValue);
    }

    /**
     * @param array<int, mixed> $matches
     */
    private static function firstRegexCapture(array $matches): string
    {
        foreach ([1, 2, 3] as $index) {
            if (isset($matches[$index]) && trim((string) $matches[$index]) !== '') {
                return trim((string) $matches[$index]);
            }
        }

        return '';
    }

    /**
     * @return list<array{type:string,source:string,location:string,selector:string}>
     */
    private function extractDomAssets(DOMDocument $document): array
    {
        $xpath = new DOMXPath($document);
        $assets = [];

        foreach ([
            '//img[@src]' => ['type' => 'image', 'attribute' => 'src'],
            '//video[@src]' => ['type' => 'video', 'attribute' => 'src'],
            '//video[@poster]' => ['type' => 'poster', 'attribute' => 'poster'],
            '//video/source[@src]' => ['type' => 'video', 'attribute' => 'src'],
        ] as $query => $config) {
            $nodes = $xpath->query($query);
            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }

                $source = trim((string) $node->getAttribute($config['attribute']));
                if ($source === '') {
                    continue;
                }

                $assets[] = [
                    'type' => $config['type'],
                    'source' => $source,
                    'location' => $this->nodeLocation($node) . ' @' . $config['attribute'],
                    'selector' => strtolower($node->tagName) . '[' . $config['attribute'] . ']',
                ];
            }
        }

        return $assets;
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function headAssetType(array $asset): string
    {
        $source = $this->stringField($asset, 'source');
        if ($this->isFontUrl($source)) {
            return 'font';
        }

        return $this->stringField($asset, 'type', 'head_asset');
    }

    private function classifyUrl(string $url): string
    {
        if ($this->isFontUrl($url)) {
            return 'font';
        }

        if (preg_match('/\.(?:mp4|webm|mov)(?:[?#].*)?$/i', $url) === 1) {
            return 'video';
        }

        return 'image';
    }

    private function isFontUrl(string $url): bool
    {
        return preg_match('/\.(?:woff2?|ttf|otf|eot)(?:[?#].*)?$/i', $url) === 1
            || str_contains(strtolower($url), 'fonts.googleapis.com')
            || str_contains(strtolower($url), 'fonts.gstatic.com');
    }

    /**
     * @return array<string, mixed>
     */
    private function assetRecord(string $type, string $source, string $location, string $origin, string $selector): array
    {
        $status = $this->statusFor($source);
        $reason = $this->reasonFor($source, $status);

        return [
            'id' => substr(sha1($type . ':' . $source . ':' . $location), 0, 16),
            'type' => $type,
            'source' => $source,
            'origin' => $origin,
            'location' => $location,
            'selector' => $selector,
            'status' => $status,
            'reason' => $reason,
            'license' => $this->licenseFor($type, $source),
            'cachePolicy' => $this->cachePolicyFor($status),
        ];
    }

    private function statusFor(string $source): string
    {
        if ($this->isUnsafeUrl($source) || $this->isTemporaryExternalUrl($source)) {
            return 'rejected';
        }

        if ($this->isStableLocalReference($source) || $this->isAllowedDataMedia($source)) {
            return 'stable_reference';
        }

        if (preg_match('/^https?:\/\//i', $source) === 1) {
            return 'manual_follow_up';
        }

        return 'stable_reference';
    }

    private function reasonFor(string $source, string $status): string
    {
        if ($status === 'rejected') {
            return $this->isTemporaryExternalUrl($source)
                ? 'Temporary external asset URL is not stable enough to persist.'
                : 'Asset URL is unsafe for an Oxygen-rendered media or CSS sink.';
        }

        if ($status === 'manual_follow_up') {
            return 'External asset requires operator review for localization, license, and cache behavior.';
        }

        return 'Asset reference is already stable for Oxygen output.';
    }

    private function licenseFor(string $type, string $source): string
    {
        if ($type === 'icon' || $type === 'font' || preg_match('/^https?:\/\//i', $source) === 1) {
            return 'Verify source license before redistribution or local caching.';
        }

        return str_starts_with($source, 'data:')
            ? 'Embedded data URI; verify source ownership.'
            : 'Site-owned or already-local asset reference.';
    }

    private function cachePolicyFor(string $status): string
    {
        return match ($status) {
            'manual_follow_up' => 'Core does not download remote assets during conversion; localize through Media Library or approved asset pipeline.',
            'rejected' => 'Do not persist; replace with a stable local asset before production import.',
            default => 'Reuse existing stable reference.',
        };
    }

    private function isStableLocalReference(string $source): bool
    {
        if (str_starts_with($source, '//')) {
            return false;
        }

        return $source !== ''
            && preg_match('/^(?:\/(?!\/)|\.{0,2}\/|[^:?#]+(?:[?#].*)?$)/', $source) === 1
            && preg_match('/^(?:javascript|vbscript|data):/i', $source) !== 1;
    }

    private function isAllowedDataMedia(string $source): bool
    {
        return preg_match('/^data:(?:image\/(?:png|jpe?g|gif|webp|avif)|video\/(?:mp4|webm));base64,/i', $source) === 1;
    }

    private function isUnsafeUrl(string $source): bool
    {
        return preg_match('/^(?:javascript|vbscript):/i', $source) === 1
            || preg_match('/^data:(?:text\/html|image\/svg\+xml|application\/javascript)/i', $source) === 1;
    }

    private function isTemporaryExternalUrl(string $source): bool
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

    /**
     * @param list<array<string, mixed>> $assets
     * @return list<array<string, mixed>>
     */
    private function dedupeAssets(array $assets): array
    {
        $deduped = [];
        $seen = [];

        foreach ($assets as $asset) {
            $key = (string) ($asset['type'] ?? '') . '|' . (string) ($asset['source'] ?? '') . '|' . (string) ($asset['location'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduped[] = $asset;
        }

        return $deduped;
    }

    /**
     * @param list<array<string, mixed>> $assets
     * @return array<string, int>
     */
    private function summarize(array $assets): array
    {
        $summary = [
            'total' => count($assets),
            'localized' => 0,
            'stable' => 0,
            'rejected' => 0,
            'manualFollowUp' => 0,
            'fonts' => 0,
            'icons' => 0,
            'images' => 0,
            'videos' => 0,
            'temporary' => 0,
        ];

        foreach ($assets as $asset) {
            $status = (string) ($asset['status'] ?? '');
            if ($status === 'stable_reference') {
                $summary['stable']++;
            } elseif ($status === 'rejected') {
                $summary['rejected']++;
            } elseif ($status === 'manual_follow_up') {
                $summary['manualFollowUp']++;
            } elseif ($status === 'localized') {
                $summary['localized']++;
            }

            $type = (string) ($asset['type'] ?? '');
            if ($type === 'font') {
                $summary['fonts']++;
            } elseif ($type === 'icon') {
                $summary['icons']++;
            } elseif (in_array($type, ['image', 'poster'], true)) {
                $summary['images']++;
            } elseif ($type === 'video') {
                $summary['videos']++;
            }

            if ($this->isTemporaryExternalUrl((string) ($asset['source'] ?? ''))) {
                $summary['temporary']++;
            }
        }

        return $summary;
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
     * @param array<string, mixed> $source
     */
    private function stringField(array $source, string $field, string $default = ''): string
    {
        $value = $source[$field] ?? null;
        if (!is_scalar($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : $default;
    }
}
