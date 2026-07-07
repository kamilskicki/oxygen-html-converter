<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use DOMElement;
use DOMNode;
use DOMText;
use OxyHtmlConverter\ElementMapper;
use OxyHtmlConverter\ElementTypes;
use OxyHtmlConverter\HtmlParser;
use OxyHtmlConverter\Report\ConversionReport;
use OxyHtmlConverter\StyleExtractor;

/**
 * Owns DOM-node to native Oxygen element mapping for the conversion pipeline.
 */
final class NativeNodeMapper
{
    /** @var list<array<string, mixed>> */
    private array $cssRules = [];

    /** @var array{toggles?: list<array<string, mixed>>, smoothScroll?: bool} */
    private array $jsPatterns = [];

    /** @var list<string> */
    private array $customClasses = [];

    private bool $inlineStyles = true;
    private bool $safeMode = true;
    private bool $canEmitExecutableCodeFallback = false;
    private bool $firstBodyElementProcessed = false;
    private bool $fixedHeaderDetected = false;
    private ?\Closure $nodeIdGenerator = null;

    public function __construct(
        private readonly HtmlParser $parser,
        private readonly ElementMapper $mapper,
        private readonly StyleExtractor $styleExtractor,
        private readonly JavaScriptTransformer $jsTransformer,
        private readonly EnvironmentService $environment,
        private readonly ClassStrategyService $classStrategy,
        private readonly TailwindDetector $tailwindDetector,
        private readonly InteractionDetector $interactionDetector,
        private readonly FrameworkDetector $frameworkDetector,
        private readonly AnimationDetector $animationDetector,
        private readonly HeuristicsService $heuristics,
        private readonly HtmlCodeSanitizer $htmlCodeSanitizer,
        private readonly OxygenSelectorImporter $selectorImporter,
        private readonly NativeCssMaterializer $cssMaterializer,
        private readonly ConversionFallbackReporter $fallbackReporter,
        private readonly ConversionReport $report
    ) {
    }

    /**
     * @param list<array<string, mixed>> $cssRules
     * @param array{toggles?: list<array<string, mixed>>, smoothScroll?: bool} $jsPatterns
     */
    public function configure(
        array $cssRules,
        array $jsPatterns,
        bool $inlineStyles,
        bool $safeMode,
        bool $canEmitExecutableCodeFallback,
        callable $nodeIdGenerator
    ): void {
        $this->cssRules = $cssRules;
        $this->jsPatterns = $jsPatterns;
        $this->inlineStyles = $inlineStyles;
        $this->safeMode = $safeMode;
        $this->canEmitExecutableCodeFallback = $canEmitExecutableCodeFallback;
        $this->nodeIdGenerator = \Closure::fromCallable($nodeIdGenerator);
        $this->customClasses = [];
        $this->firstBodyElementProcessed = false;
        $this->fixedHeaderDetected = false;
    }

    /**
     * @return list<string>
     */
    public function customClasses(): array
    {
        return $this->customClasses;
    }

    /**
     * Convert a DOM node to an Oxygen element structure.
     *
     * @return array<string, mixed>|null
     */
    public function mapNode(DOMNode $node): ?array
    {
        if ($node instanceof DOMText) {
            return $this->mapTextNode($node);
        }

        if (!($node instanceof DOMElement)) {
            return null;
        }

        if ($this->parser->shouldSkipNode($node)) {
            return null;
        }

        $tag = strtolower($node->tagName);

        if ($tag === 'script') {
            return $this->mapScriptNode($node);
        }

        if ($tag === 'style') {
            return null;
        }

        if ($tag === 'link') {
            return $this->mapLinkNode($node);
        }

        $elementType = $this->mapper->getElementType($tag, $node);

        $this->report->incrementElementCount();

        $element = [
            'id' => $this->generateNodeId(),
            'data' => [
                'type' => $elementType,
                'properties' => [],
            ],
            'children' => [],
        ];

        $contentProperties = $this->mapper->buildProperties($node);
        $nativeStyleProperties = $this->inlineStyles
            ? $this->styleExtractor->extractAndConvert($node)
            : [];
        $styleProperties = $nativeStyleProperties !== []
            ? ['design' => $nativeStyleProperties]
            : [];

        $element['data']['properties'] = $this->mergeAssociativeProperties($contentProperties, $styleProperties);

        if ($elementType === ElementTypes::HTML_CODE && !$this->canEmitExecutableCodeFallback) {
            $this->fallbackReporter->reportUnsupportedNode(
                $node,
                $this->fallbackReporter->unsupportedHtmlReason($node, false),
                'blocking',
                $this->fallbackReporter->unsupportedHtmlSafeModeImpact($node, false),
                $this->fallbackReporter->unsupportedHtmlRemediation($node)
            );
            if (!$this->fallbackReporter->sanitizeHtmlCodeElement($element)) {
                return null;
            }
        } elseif ($elementType === ElementTypes::HTML_CODE) {
            $this->fallbackReporter->reportUnsupportedNode(
                $node,
                $this->fallbackReporter->unsupportedHtmlReason($node, true),
                'blocking',
                $this->fallbackReporter->unsupportedHtmlSafeModeImpact($node, true),
                $this->fallbackReporter->unsupportedHtmlRemediation($node)
            );
        }

        $tagOption = $this->mapper->getTagOption($tag);
        if ($tagOption) {
            $element['data']['properties']['design'] = $element['data']['properties']['design'] ?? [];
            $element['data']['properties']['design']['tag'] = $tagOption;
            $element['data']['properties']['settings'] = $element['data']['properties']['settings'] ?? [];
            $element['data']['properties']['settings']['advanced'] = $element['data']['properties']['settings']['advanced'] ?? [];
            $element['data']['properties']['settings']['advanced']['tag'] = $tagOption;
        }

        $this->heuristics->applyStickyNavbar($node, $element);
        $this->heuristics->applyNavLinkWhite($node, $element);
        $this->heuristics->applyRoundedFullCentering($node, $element);
        $this->heuristics->applyButtonCentering($tag, $elementType, $element);

        $this->sanitizeMappedUrls($tag, $element);

        $this->heuristics->applyFixedHeaderSpacing(
            $node,
            $element,
            $this->fixedHeaderDetected,
            $this->firstBodyElementProcessed
        );

        $this->processClasses($node, $element);
        $this->processId($node, $element);
        $this->applyAnimations($node, $element);
        $this->applySmoothScroll($tag, $node, $element);

        $this->fallbackReporter->reportExecutableAttributes($node);
        $this->interactionDetector->processCustomAttributes($node, $element);
        $this->frameworkDetector->detect($node);
        $this->checkForWarnings($node);

        if ($this->mapper->needsChildTextElement($node)) {
            $textChild = $this->mapper->buildChildTextElement($node, $this->generateNodeId());
            if ($textChild !== null) {
                $textChild['data']['properties']['design']['typography'] = $textChild['data']['properties']['design']['typography'] ?? [];
                $textChild['data']['properties']['design']['typography']['text_align'] = 'center';
                $element['children'][] = $textChild;
            }

            $this->finalizeElement($element, $node);
            return $element;
        }

        if ($this->mapper->isContainer($tag, $node)
            && !$this->mapper->shouldKeepInnerHtml($tag)
            && $this->mapper->shouldConvertToText($node)
        ) {
            $element['data']['type'] = ElementTypes::TEXT;
            $element['data']['properties']['content'] = $element['data']['properties']['content'] ?? [];
            $element['data']['properties']['content']['content'] = [
                'text' => $this->mapper->getInnerHtml($node),
            ];
            $element['children'] = [];
        } elseif ($this->mapper->isContainer($tag, $node) && !$this->mapper->shouldKeepInnerHtml($tag)) {
            $children = [];
            foreach ($node->childNodes as $childNode) {
                $childElement = $this->mapNode($childNode);
                if ($childElement !== null) {
                    $children[] = $childElement;
                }
            }
            $element['children'] = $children;

            if (empty($children) && trim($node->textContent) !== '' && $this->mapper->shouldConvertToText($node)) {
                $element['data']['type'] = ElementTypes::TEXT;
                if (!isset($element['data']['properties']['content'])) {
                    $element['data']['properties']['content'] = [];
                }
                $element['data']['properties']['content']['content'] = [
                    'text' => $this->mapper->getInnerHtml($node),
                ];
            }
        }

        $this->finalizeElement($element, $node);

        return $element;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapTextNode(DOMText $node): ?array
    {
        $text = trim($node->textContent);
        if ($text === '') {
            return null;
        }

        if ($this->safeMode) {
            $text = $this->htmlCodeSanitizer->escapePlainText($text);
        }

        $this->report->incrementElementCount();

        return [
            'id' => $this->generateNodeId(),
            'data' => [
                'type' => ElementTypes::TEXT,
                'properties' => [
                    'content' => [
                        'content' => [
                            'text' => $text,
                        ],
                    ],
                ],
            ],
            'children' => [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapScriptNode(DOMElement $node): ?array
    {
        if (!$this->canEmitExecutableCodeFallback) {
            $this->fallbackReporter->reportUnsupportedNode(
                $node,
                $this->safeMode
                    ? 'Script tags are removed in Safe Mode and are not imported as executable code.'
                    : 'Script tags require explicit executable-code opt-in and are not imported by default.',
                'blocking',
                $this->safeMode
                    ? 'Safe Mode removed the script and no executable Oxygen code block was created.'
                    : 'Unsafe preservation was not explicitly approved, so no executable Oxygen code block was created.',
                'Remove the script, replace it with a safe native interaction, or explicitly approve unsafe executable fallback.'
            );
            return null;
        }

        $src = $node->getAttribute('src');
        $scriptContent = $node->textContent;

        if ($src) {
            $this->fallbackReporter->reportUnsupportedNode(
                $node,
                'External script requires an unsafe visible HtmlCode fallback.',
                'blocking',
                'Safe Mode would remove this script; unsafe mode preserves it as visible HtmlCode.',
                'Replace with a safe local asset/native interaction or explicitly approve unsafe fallback.'
            );
            $element = [
                'id' => $this->generateNodeId(),
                'data' => [
                    'type' => ElementTypes::HTML_CODE,
                    'properties' => [
                        'content' => [
                            'content' => [
                                'html_code' => $node->ownerDocument?->saveHTML($node) ?: '',
                            ],
                        ],
                    ],
                ],
                'children' => [],
            ];
            $this->processClasses($node, $element);
            $this->processId($node, $element);
            $this->interactionDetector->processCustomAttributes($node, $element);
            return $element;
        }

        if (trim($scriptContent) === '') {
            return null;
        }

        $this->report->incrementElementCount();
        $this->fallbackReporter->reportUnsupportedNode(
            $node,
            'Inline script requires an unsafe visible JavaScriptCode fallback.',
            'blocking',
            'Safe Mode would remove this script; unsafe mode preserves transformed JavaScript.',
            'Replace with a safe native interaction or explicitly approve unsafe fallback.'
        );

        $transformedJs = $this->jsTransformer->transformJavaScriptForOxygen($scriptContent);
        $hasObserver = strpos($scriptContent, 'IntersectionObserver') !== false
            && strpos($scriptContent, 'animate-on-scroll') !== false;
        $hasSmoothScroll = !empty($this->jsPatterns['smoothScroll'])
            && strpos($scriptContent, 'scrollIntoView') !== false;
        $transformedJs = $this->jsTransformer->stripConvertedPatterns(
            $transformedJs,
            $hasObserver,
            $hasSmoothScroll,
            []
        );

        if (trim($transformedJs) === '') {
            return null;
        }

        $element = [
            'id' => $this->generateNodeId(),
            'data' => [
                'type' => ElementTypes::JAVASCRIPT_CODE,
                'properties' => [
                    'content' => [
                        'content' => [
                            'javascript_code' => $transformedJs,
                        ],
                    ],
                ],
            ],
            'children' => [],
        ];
        $this->processClasses($node, $element);
        $this->processId($node, $element);
        $this->interactionDetector->processCustomAttributes($node, $element);

        return $element;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapLinkNode(DOMElement $node): ?array
    {
        if (!$this->canEmitExecutableCodeFallback) {
            $this->fallbackReporter->reportUnsupportedNode(
                $node,
                $this->safeMode
                    ? 'External link/head asset is removed in Safe Mode.'
                    : 'External link/head asset requires explicit code-fallback opt-in and is not imported by default.',
                'review',
                $this->safeMode
                    ? 'Safe Mode removed the external asset and no HtmlCode fallback was created.'
                    : 'Unsafe preservation was not explicitly approved, so no HtmlCode fallback was created.',
                'Persist supported assets through global styles or explicitly approve unsafe fallback.'
            );
            return null;
        }

        $this->fallbackReporter->reportUnsupportedNode(
            $node,
            'External link/head asset requires an unsafe visible HtmlCode fallback.',
            'review',
            'Safe Mode would remove this asset; unsafe mode preserves it as visible HtmlCode.',
            'Persist supported assets through global styles or explicitly approve unsafe fallback.'
        );

        $element = [
            'id' => $this->generateNodeId(),
            'data' => [
                'type' => ElementTypes::HTML_CODE,
                'properties' => [
                    'content' => [
                        'content' => [
                            'html_code' => $node->ownerDocument?->saveHTML($node) ?: '',
                        ],
                    ],
                ],
            ],
            'children' => [],
        ];
        $this->processClasses($node, $element);
        $this->processId($node, $element);
        $this->interactionDetector->processCustomAttributes($node, $element);

        return $element;
    }

    /**
     * @param array<string, mixed> $element
     */
    private function sanitizeMappedUrls(string $tag, array &$element): void
    {
        if ($tag === 'img' && isset($element['data']['properties']['content']['image']['url'])) {
            $element['data']['properties']['content']['image']['url'] = $this->sanitizeUrl(
                (string) $element['data']['properties']['content']['image']['url'],
                ['http', 'https', 'data']
            );
        }
        if ($tag === 'a' && isset($element['data']['properties']['content']['content']['url'])) {
            $element['data']['properties']['content']['content']['url'] = $this->sanitizeUrl(
                (string) $element['data']['properties']['content']['content']['url'],
                ['http', 'https', 'mailto', 'tel']
            );
        }
        if ($tag === 'button' && isset($element['data']['properties']['content']['content']['link']['url'])) {
            $element['data']['properties']['content']['content']['link']['url'] = $this->sanitizeUrl(
                (string) $element['data']['properties']['content']['content']['link']['url'],
                ['http', 'https', 'mailto', 'tel']
            );
        }
        if ($tag === 'video' && isset($element['data']['properties']['content']['content']['video_file_url'])) {
            $element['data']['properties']['content']['content']['video_file_url'] = $this->sanitizeUrl(
                (string) $element['data']['properties']['content']['content']['video_file_url'],
                ['http', 'https', 'data']
            );
        }
    }

    /**
     * @param array<string, mixed> $element
     */
    private function applyAnimations(DOMElement $node, array &$element): void
    {
        $classAttr = $node->getAttribute('class');
        $classNames = $classAttr ? array_filter(array_map('trim', preg_split('/\s+/', $classAttr) ?: [])) : [];
        $animationSettings = $this->animationDetector->detectAnimations($node, $classNames, $this->cssRules);
        if (!$animationSettings) {
            return;
        }

        $element['data']['properties']['settings'] = $element['data']['properties']['settings'] ?? [];
        $element['data']['properties']['settings']['animations'] = $element['data']['properties']['settings']['animations'] ?? [];
        $element['data']['properties']['settings']['animations']['entrance_animation'] = $animationSettings;

        $consumedClasses = $this->animationDetector->getRemovableConsumedClasses();
        if (!empty($consumedClasses) && isset($element['data']['properties']['settings']['advanced']['classes'])) {
            $element['data']['properties']['settings']['advanced']['classes'] = array_values(
                array_diff($element['data']['properties']['settings']['advanced']['classes'], $consumedClasses)
            );
        }
    }

    /**
     * @param array<string, mixed> $element
     */
    private function applySmoothScroll(string $tag, DOMElement $node, array &$element): void
    {
        if (empty($this->jsPatterns['smoothScroll']) || $tag !== 'a') {
            return;
        }

        $href = $node->getAttribute('href');
        if (!$href || strpos($href, '#') !== 0 || strlen($href) <= 1) {
            return;
        }

        $scrollInteraction = [
            'trigger' => 'click',
            'target' => 'this_element',
            'actions' => [[
                'name' => 'scroll_to',
                'target' => $href,
                'scroll_behavior' => 'smooth',
            ]],
        ];
        $this->interactionDetector->applyDetectedInteraction('', $scrollInteraction, $element);
    }

    /**
     * @param array<string, mixed> $element
     */
    private function finalizeElement(array &$element, DOMElement $node): void
    {
        if ($this->inlineStyles) {
            $this->cssMaterializer->applyCssRules($element, $this->cssRules, $node);
        }

        $this->sanitizeSafeModeElementSinks($element);
        $this->syncElementSelectorReferences($element);
        $this->sanitizeTagForElementType($element);
    }

    /**
     * Oxygen reads classes from `settings.advanced.classes`.
     *
     * @param array<string, mixed> $element
     */
    private function processClasses(DOMElement $node, array &$element): void
    {
        $classAttr = $node->getAttribute('class');
        if (!$classAttr) {
            return;
        }

        $classNames = array_filter(array_map('trim', preg_split('/\s+/', $classAttr) ?: []));
        if (empty($classNames)) {
            return;
        }

        foreach ($classNames as $className) {
            if (!$this->tailwindDetector->isTailwindClass($className)) {
                $this->customClasses[] = $className;
            }
        }

        $this->classStrategy->processClasses($classNames, $element);
        $this->syncElementSelectorReferences($element);
    }

    /**
     * @param array<string, mixed> $element
     */
    private function syncElementSelectorReferences(array &$element): void
    {
        $classes = $element['data']['properties']['settings']['advanced']['classes'] ?? [];
        if (!is_array($classes)) {
            $classes = [];
        }

        if ($this->environment->shouldUseWindPressMode()) {
            $classes = array_values(array_filter(
                $classes,
                fn($className): bool => is_string($className)
                    && !$this->tailwindDetector->isTailwindClass($className)
            ));
        }

        $this->selectorImporter->syncElementClasses($classes, $element);
    }

    /**
     * @param array<string, mixed> $element
     */
    private function sanitizeTagForElementType(array &$element): void
    {
        $type = $element['data']['type'] ?? '';
        $allowedTags = match ($type) {
            ElementTypes::CONTAINER, ElementTypes::CONTAINER_LINK => [
                'section', 'footer', 'header', 'nav', 'aside', 'figure',
                'article', 'main', 'details', 'summary', 'ul', 'li', 'ol',
            ],
            ElementTypes::TEXT, ElementTypes::TEXT_LINK => [
                'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'blockquote',
            ],
            default => [],
        };

        if ($allowedTags === []) {
            unset($element['data']['properties']['design']['tag']);
            unset($element['data']['properties']['settings']['advanced']['tag']);
            return;
        }

        $designTag = $element['data']['properties']['design']['tag'] ?? null;
        if (is_string($designTag) && !in_array($designTag, $allowedTags, true)) {
            unset($element['data']['properties']['design']['tag']);
        }

        $settingsTag = $element['data']['properties']['settings']['advanced']['tag'] ?? null;
        if (is_string($settingsTag) && !in_array($settingsTag, $allowedTags, true)) {
            unset($element['data']['properties']['settings']['advanced']['tag']);
        }
    }

    /**
     * @param array<string, mixed> $element
     */
    private function processId(DOMElement $node, array &$element): void
    {
        $id = $node->getAttribute('id');
        if (!$id) {
            return;
        }

        $element['data']['properties']['settings'] = $element['data']['properties']['settings'] ?? [];
        $element['data']['properties']['settings']['advanced'] = $element['data']['properties']['settings']['advanced'] ?? [];
        $element['data']['properties']['settings']['advanced']['id'] = $id;
    }

    private function checkForWarnings(DOMElement $node): void
    {
        if ($node->hasAttribute('data-lucide') || $node->hasAttribute('data-feather')) {
            $iconName = $node->getAttribute('data-lucide') ?: $node->getAttribute('data-feather');
            $this->report->addWarning("Icon element (data-lucide=\"{$iconName}\") detected. Scripts are automatically included, but you may need to adjust the icon size or color manually in Oxygen.");
        }
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function mergeAssociativeProperties(array $base, array $override): array
    {
        $merged = $base;

        foreach ($override as $key => $value) {
            if (
                array_key_exists($key, $merged)
                && is_array($merged[$key])
                && is_array($value)
                && $this->isAssocArray($merged[$key])
                && $this->isAssocArray($value)
            ) {
                $merged[$key] = $this->mergeAssociativeProperties($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    private function isAssocArray(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Sanitize rendered Oxygen property sinks after mapping and heuristics.
     *
     * @param array<string, mixed> $element
     */
    private function sanitizeSafeModeElementSinks(array &$element): void
    {
        if (!$this->safeMode) {
            return;
        }

        $type = (string) ($element['data']['type'] ?? '');
        if (isset($element['data']['properties']['content']) && is_array($element['data']['properties']['content'])) {
            $content = &$element['data']['properties']['content'];

            if (isset($content['content']['text']) && is_string($content['content']['text'])) {
                if ($type === ElementTypes::RICH_TEXT) {
                    $content['content']['text'] = $this->htmlCodeSanitizer->sanitizeRichText($content['content']['text']);
                } elseif ($type === ElementTypes::ESSENTIAL_BUTTON) {
                    $content['content']['text'] = $this->htmlCodeSanitizer->sanitizePlainText($content['content']['text']);
                } else {
                    $content['content']['text'] = $this->htmlCodeSanitizer->sanitizeInlineRichText($content['content']['text']);
                }
            }

            if (isset($content['content']['url']) && is_string($content['content']['url'])) {
                $content['content']['url'] = $this->sanitizeUrl($content['content']['url'], ['http', 'https', 'mailto', 'tel']);
            }

            if (isset($content['content']['link']['url']) && is_string($content['content']['link']['url'])) {
                $content['content']['link']['url'] = $this->sanitizeUrl(
                    $content['content']['link']['url'],
                    ['http', 'https', 'mailto', 'tel']
                );
            }

            if (isset($content['image']['url']) && is_string($content['image']['url'])) {
                $content['image']['url'] = $this->sanitizeUrl($content['image']['url'], ['http', 'https', 'data']);
            }

            if (isset($content['image']['custom_alt_when_from_url']) && is_string($content['image']['custom_alt_when_from_url'])) {
                $content['image']['custom_alt_when_from_url'] = $this->htmlCodeSanitizer->sanitizePlainText(
                    $content['image']['custom_alt_when_from_url']
                );
            }

            if (isset($content['content']['video_file_url']) && is_string($content['content']['video_file_url'])) {
                $content['content']['video_file_url'] = $this->sanitizeUrl(
                    $content['content']['video_file_url'],
                    ['http', 'https', 'data']
                );
            }

            unset($content);
        }

        $attributes = $element['data']['properties']['settings']['advanced']['attributes'] ?? null;
        if (!is_array($attributes)) {
            return;
        }

        $safeAttributes = [];
        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }

            $name = strtolower((string) ($attribute['name'] ?? ''));
            $value = (string) ($attribute['value'] ?? '');
            if (!$this->isSafeModeAdvancedAttribute($name, $value)) {
                continue;
            }

            $safeAttributes[] = [
                'name' => $name,
                'value' => $this->htmlCodeSanitizer->sanitizePlainText($value),
            ];
        }

        if ($safeAttributes === []) {
            unset($element['data']['properties']['settings']['advanced']['attributes']);
        } else {
            $element['data']['properties']['settings']['advanced']['attributes'] = $safeAttributes;
        }
    }

    private function isSafeModeAdvancedAttribute(string $name, string $value): bool
    {
        if ($name === '' || strpos($name, 'on') === 0) {
            return false;
        }

        if (strpos($name, 'data-oxy-at-') === 0
            || strpos($name, 'x-') === 0
            || strpos($name, 'v-') === 0
            || strpos($name, 'ng-') === 0
            || strpos($name, 'hx-on') === 0
            || strpos($name, 'bind:') === 0
            || strpos($name, ':') === 0
            || strpos($name, '@') === 0
        ) {
            return false;
        }

        if (in_array($name, ['ping', 'formaction', 'action', 'srcdoc', 'srcset', 'sizes'], true)) {
            return false;
        }

        $urlAttributeNames = ['href', 'src', 'poster', 'action', 'formaction', 'xlink:href'];
        if (in_array($name, $urlAttributeNames, true)) {
            return $this->sanitizeUrl($value, ['http', 'https', 'mailto', 'tel']) !== '#';
        }

        return preg_match('/^(aria-[a-z0-9_-]+|data-[a-z0-9_-]+|role|title|lang|dir|tabindex)$/', $name) === 1;
    }

    /**
     * @param list<string> $allowedSchemes
     */
    private function sanitizeUrl(string $url, array $allowedSchemes = ['http', 'https']): string
    {
        return $this->htmlCodeSanitizer->sanitizeUrl($url, $allowedSchemes);
    }

    private function generateNodeId(): int
    {
        if ($this->nodeIdGenerator === null) {
            throw new \LogicException('NativeNodeMapper must be configured before mapping nodes.');
        }

        return (int) ($this->nodeIdGenerator)();
    }
}
