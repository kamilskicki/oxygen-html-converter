<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use OxyHtmlConverter\HtmlParser;
use OxyHtmlConverter\Report\ConversionReport;

/**
 * Converts raw source HTML into a normalized DOM/CSS boundary.
 */
final class HtmlNormalizer
{
    public function __construct(
        private readonly HtmlParser $parser,
        private readonly DocumentCssExtractor $documentCssExtractor,
        private readonly CssParser $cssParser,
        private readonly ?HeadAssetExtractor $headAssetExtractor = null,
        private readonly ?FrameworkDetector $frameworkDetector = null,
        private readonly ?HeuristicsService $heuristics = null
    ) {
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function normalize(string $html, array $manifest = []): NormalizedHtml
    {
        $root = $this->parser->parse($html);
        $document = $this->parser->getDom();
        $sourceHash = hash('sha256', $html);

        if ($root === null) {
            return new NormalizedHtml(
                $html,
                $document,
                null,
                '',
                [],
                [],
                array_values(array_map('strval', $this->parser->getErrors())),
                '',
                [],
                [],
                [],
                [],
                $sourceHash,
                hash('sha256', '')
            );
        }

        $issues = [];
        $decisions = [];
        $this->removeDuplicateRootWrappers($root, $issues, $decisions);
        $this->stripSourceArtifactAttributes($document, $issues);
        $this->reportPlaceholderLinks($document, $issues);
        $this->reportTemporaryMedia($document, $issues);
        $this->classifyHeaders($document, $manifest, $decisions);

        $headAssetExtractor = $this->headAssetExtractor ?? new HeadAssetExtractor(static fn (): int => 0);
        $headAssets = $headAssetExtractor->extractAssetReferences($document);
        $frameworkDetector = $this->frameworkDetector ?? new FrameworkDetector(new ConversionReport());
        $frameworkDetector->resetReportedFrameworks();
        $frameworks = $frameworkDetector->detectDocument($document);

        $extractedCss = $this->documentCssExtractor->extract($document);
        $cssRules = $this->cssParser->parse($extractedCss);
        $bodyNodes = $this->parser->extractBodyContent($root);
        $normalizedHtml = trim((string) $document->saveHTML());

        return new NormalizedHtml(
            $html,
            $document,
            $root,
            $extractedCss,
            $cssRules,
            $bodyNodes,
            array_values(array_map('strval', $this->parser->getErrors())),
            $normalizedHtml,
            $issues,
            $decisions,
            $headAssets,
            $frameworks,
            $sourceHash,
            hash('sha256', $normalizedHtml)
        );
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @param list<array<string, mixed>> $decisions
     */
    private function removeDuplicateRootWrappers(DOMElement $root, array &$issues, array &$decisions): void
    {
        for ($pass = 0; $pass < 5; $pass++) {
            $children = $this->elementChildren($root);
            if (count($children) !== 1) {
                return;
            }

            $wrapper = $children[0];
            if (!$this->isGeneratedRootWrapper($wrapper)) {
                return;
            }

            $wrapperChildren = $this->elementChildren($wrapper);
            if ($wrapperChildren === []) {
                return;
            }

            $descriptor = $this->nodeDescriptor($wrapper);
            $moved = 0;
            while ($wrapper->firstChild instanceof DOMNode) {
                $root->insertBefore($wrapper->firstChild, $wrapper);
                $moved++;
            }
            $root->removeChild($wrapper);

            $decisions[] = [
                'type' => 'duplicate_root_wrapper',
                'action' => 'removed',
                'selector' => $descriptor,
                'movedNodes' => $moved,
            ];
            $issues[] = [
                'type' => 'duplicate_root_wrapper',
                'severity' => 'info',
                'action' => 'removed',
                'selector' => $descriptor,
                'message' => 'Generated root wrapper was removed before native mapping.',
            ];
        }
    }

    private function isGeneratedRootWrapper(DOMElement $element): bool
    {
        $tag = strtolower($element->tagName);
        if (!in_array($tag, ['div', 'section'], true)) {
            return false;
        }

        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->name);
            if ($this->isSourceArtifactAttribute($name)) {
                return true;
            }
        }

        $id = strtolower(trim($element->getAttribute('id')));
        if (in_array($id, ['root', 'app', '__next', 'page-root', 'generated-root'], true)) {
            return true;
        }

        $classSignature = strtolower(' ' . trim($element->getAttribute('class')) . ' ');

        foreach ([
            ' ai-page ',
            ' generated-page ',
            ' root-wrapper ',
            ' page-wrapper ',
            ' app-wrapper ',
            ' figma-wrapper ',
            ' stitch-wrapper ',
            ' claude-wrapper ',
        ] as $needle) {
            if (str_contains($classSignature, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<DOMElement>
     */
    private function elementChildren(DOMElement $element): array
    {
        $children = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * @param list<array<string, mixed>> $issues
     */
    private function stripSourceArtifactAttributes(DOMDocument $document, array &$issues): void
    {
        foreach ($document->getElementsByTagName('*') as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }

            $remove = [];
            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->name);
                if ($this->isSourceArtifactAttribute($name)) {
                    $remove[] = $attribute->name;
                } elseif ($this->isGenericSourceHintAttribute($name)) {
                    $issues[] = [
                        'type' => 'source_artifact_attribute',
                        'severity' => 'info',
                        'action' => 'reported',
                        'selector' => $this->nodeDescriptor($element),
                        'attribute' => $attribute->name,
                        'message' => 'Generic source hint attribute was preserved because CSS or interactions may depend on it.',
                    ];
                }
            }

            foreach ($remove as $attributeName) {
                $issues[] = [
                    'type' => 'source_artifact_attribute',
                    'severity' => 'info',
                    'action' => 'removed',
                    'selector' => $this->nodeDescriptor($element),
                    'attribute' => $attributeName,
                    'message' => 'Source-generator attribute was removed before native mapping.',
                ];
                $element->removeAttribute($attributeName);
            }
        }
    }

    private function isSourceArtifactAttribute(string $attributeName): bool
    {
        return str_starts_with($attributeName, 'data-ai-')
            || str_starts_with($attributeName, 'data-figma-')
            || str_starts_with($attributeName, 'data-framer-')
            || str_starts_with($attributeName, 'data-stitch-')
            || str_starts_with($attributeName, 'data-claude-');
    }

    private function isGenericSourceHintAttribute(string $attributeName): bool
    {
        return in_array($attributeName, ['data-source', 'data-generated', 'data-layer'], true);
    }

    /**
     * @param list<array<string, mixed>> $issues
     */
    private function reportPlaceholderLinks(DOMDocument $document, array &$issues): void
    {
        $xpath = new DOMXPath($document);
        $links = $xpath->query('//a[@href]');
        if ($links === false) {
            return;
        }

        foreach ($links as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $href = strtolower(trim($link->getAttribute('href')));
            if (!in_array($href, ['', '#', '#!', 'javascript:void(0)', 'javascript:void(0);'], true)) {
                continue;
            }

            $issues[] = [
                'type' => 'placeholder_link',
                'severity' => 'review',
                'action' => 'reported',
                'selector' => $this->nodeDescriptor($link),
                'href' => $link->getAttribute('href'),
                'text' => trim((string) preg_replace('/\s+/', ' ', $link->textContent)),
                'message' => 'Placeholder link requires a real target or an explicit unsupported/deferred decision.',
            ];
        }
    }

    /**
     * @param list<array<string, mixed>> $issues
     */
    private function reportTemporaryMedia(DOMDocument $document, array &$issues): void
    {
        $extractor = $this->headAssetExtractor ?? new HeadAssetExtractor(static fn (): int => 0);

        foreach ($extractor->extractTemporaryMediaReferences($document) as $asset) {
            $issues[] = [
                'type' => 'temporary_media',
                'severity' => 'blocking',
                'action' => 'reported',
                'selector' => $asset['selector'],
                'attribute' => $asset['attribute'],
                'source' => $asset['source'],
                'message' => 'Temporary external media URL must be replaced with a stable local asset before production import.',
            ];
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<array<string, mixed>> $decisions
     */
    private function classifyHeaders(DOMDocument $document, array $manifest, array &$decisions): void
    {
        $heuristics = $this->heuristics ?? new HeuristicsService();
        $overrides = $this->headerRoleOverrides($manifest);
        $headers = $document->getElementsByTagName('header');

        foreach ($headers as $header) {
            if (!$header instanceof DOMElement) {
                continue;
            }

            $classification = $heuristics->classifyHeaderRole($header, $overrides);
            $decisions[] = [
                'type' => 'header_role',
                'selector' => $this->nodeDescriptor($header),
                'role' => $classification['role'],
                'source' => $classification['source'],
                'reason' => $classification['reason'],
            ];
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, string>
     */
    private function headerRoleOverrides(array $manifest): array
    {
        $candidates = [
            $manifest['headerRoles'] ?? null,
            $manifest['normalization']['headerRoles'] ?? null,
            $manifest['normalizationRules']['headerRoles'] ?? null,
        ];

        $overrides = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            foreach ($candidate as $selector => $role) {
                if (!is_scalar($selector) || !is_scalar($role)) {
                    continue;
                }

                $role = (string) $role;
                if (in_array($role, ['site_header', 'content_header'], true)) {
                    $overrides[(string) $selector] = $role;
                }
            }
        }

        return $overrides;
    }

    private function nodeDescriptor(DOMElement $element): string
    {
        $descriptor = strtolower($element->tagName);
        $id = trim($element->getAttribute('id'));
        if ($id !== '') {
            return $descriptor . '#' . $id;
        }

        $classes = array_values(array_filter(preg_split('/\s+/', trim($element->getAttribute('class'))) ?: []));
        if ($classes !== []) {
            return $descriptor . '.' . implode('.', array_slice($classes, 0, 3));
        }

        return $descriptor;
    }
}
