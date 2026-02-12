<?php

namespace OxyHtmlConverter;

use OxyHtmlConverter\Report\ConversionReport;
use OxyHtmlConverter\Services\JavaScriptTransformer;
use OxyHtmlConverter\Services\EnvironmentService;
use OxyHtmlConverter\Services\ClassStrategyService;
use OxyHtmlConverter\Services\IconDetector;
use OxyHtmlConverter\Services\InteractionDetector;
use OxyHtmlConverter\Services\TailwindDetector;
use OxyHtmlConverter\Services\FrameworkDetector;
use OxyHtmlConverter\Services\CssParser;
use OxyHtmlConverter\Services\AnimationDetector;
use OxyHtmlConverter\Services\ComponentDetector;
use OxyHtmlConverter\Services\HeuristicsService;
use OxyHtmlConverter\Validation\OutputValidator;
use OxyHtmlConverter\ElementTypes;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Builds Oxygen-compatible JSON tree from parsed HTML
 */
class TreeBuilder
{
    private HtmlParser $parser;
    private ElementMapper $mapper;
    private StyleExtractor $styleExtractor;
    private JavaScriptTransformer $jsTransformer;
    private EnvironmentService $environment;
    private ClassStrategyService $classStrategy;
    private IconDetector $iconDetector;
    private InteractionDetector $interactionDetector;
    private TailwindDetector $tailwindDetector;
    private FrameworkDetector $frameworkDetector;
    private CssParser $cssParser;
    private AnimationDetector $animationDetector;
    private ComponentDetector $componentDetector;
    private HeuristicsService $heuristics;
    private OutputValidator $validator;
    private ConversionReport $report;

    private bool $validateOutput = false;
    private bool $inlineStyles = true;  // NEW: Force all styles inline instead of CSS Code
    private bool $debugMode = false;     // NEW: Enable debug logging
    private int $nodeIdCounter = 1;
    private string $extractedCss = '';
    private array $customClasses = [];
    private array $detectedIconLibraries = [];
    private array $cssRules = [];
    private bool $firstBodyElementProcessed = false;
    private bool $fixedHeaderDetected = false;
    private array $jsPatterns = [];
    private array $consumedCssSelectors = [];

    public function __construct()
    {
        $this->parser = new HtmlParser();
        $this->mapper = new ElementMapper();
        $this->styleExtractor = new StyleExtractor();
        $this->jsTransformer = new JavaScriptTransformer();
        $this->report = new ConversionReport();
        $this->environment = new EnvironmentService();
        $this->tailwindDetector = new TailwindDetector();
        $this->classStrategy = new ClassStrategyService($this->environment, $this->report, $this->tailwindDetector);
        $this->iconDetector = new IconDetector();
        $this->frameworkDetector = new FrameworkDetector($this->report);
        $this->interactionDetector = new InteractionDetector($this->frameworkDetector);
        $this->cssParser = new CssParser();
        $this->animationDetector = new AnimationDetector();
        $this->componentDetector = new ComponentDetector($this->report);
        $this->heuristics = new HeuristicsService();
        $this->validator = new OutputValidator();
    }

    /**
     * Convert HTML string to Oxygen JSON structure
     */
    public function convert(string $html): array
    {
        // Reset state
        $this->nodeIdCounter = 1;
        $this->extractedCss = '';
        $this->customClasses = [];
        $this->detectedIconLibraries = [];
        $this->cssRules = [];
        $this->firstBodyElementProcessed = false;
        $this->fixedHeaderDetected = false;
        $this->jsPatterns = [];
        $this->consumedCssSelectors = [];
        $this->report->reset();

        // Parse HTML
        $root = $this->parser->parse($html);
        if (!$root) {
            return [
                'success' => false,
                'error' => 'Failed to parse HTML',
                'errors' => $this->parser->getErrors(),
            ];
        }

        // Extract custom CSS from <style> tags
        $this->extractedCss = $this->extractStyleTags($this->parser->getDom());

        // Parse extracted CSS rules
        $this->cssRules = $this->cssParser->parse($this->extractedCss);

        // Pre-analyze CSS rules for animation detection
        $this->animationDetector->analyzeCssRules($this->cssRules, $this->extractedCss);

        // Pre-analyze JavaScript for toggle/scroll patterns
        $this->jsPatterns = $this->analyzeJavaScriptPatterns($this->parser->getDom());

        // Get body content
        $bodyNodes = $this->parser->extractBodyContent($root);

        // Analyze for repeated components
        foreach ($bodyNodes as $node) {
            $this->componentDetector->analyze($node);
        }
        $this->componentDetector->reportFindings();

        // Build element tree
        $children = [];
        foreach ($bodyNodes as $node) {
            $element = $this->convertNode($node);
            if ($element !== null) {
                $children[] = $element;
            }
        }

        // If single child, use it as root; otherwise wrap in container
        $rootElement = null;
        if (count($children) === 1) {
            $rootElement = $children[0];
        } elseif (count($children) > 1) {
            $rootElement = [
                'id' => $this->generateNodeId(),
                'data' => [
                    'type' => ElementTypes::CONTAINER,
                    'properties' => [],
                ],
                'children' => $children,
            ];
        }

        if ($rootElement === null) {
            return [
                'success' => false,
                'error' => 'No convertible content found in HTML',
            ];
        }

        // Clean up CSS rules that were converted to native Oxygen features
        $this->extractedCss = $this->animationDetector->cleanupConvertedCss($this->extractedCss);

        // Clean up CSS rules that were applied as native design properties
        $this->extractedCss = $this->cleanupConsumedCssRules($this->extractedCss);

        // Create CSS Code element if we have extracted CSS
        $cssElement = null;
        if (!empty(trim($this->extractedCss))) {
            $cssElement = $this->createCssCodeElement($this->extractedCss);
        }

        // Detect icon libraries in the HTML
        $this->detectedIconLibraries = $this->iconDetector->detectIconLibraries($this->parser->getDom());

        // Extract <link> tags from <head> (Google Fonts, preconnect, etc.)
        $headLinkElements = $this->extractHeadLinks($this->parser->getDom());

        // Create script elements for detected icon libraries
        $iconScriptElements = $this->iconDetector->createIconLibraryElements(
            $this->detectedIconLibraries,
            function() { return $this->generateNodeId(); }
        );

        $result = [
            'success' => true,
            'element' => $rootElement,
            'cssElement' => $cssElement,
            'headLinkElements' => $headLinkElements,
            'iconScriptElements' => $iconScriptElements,
            'detectedIconLibraries' => $this->detectedIconLibraries,
            'extractedCss' => $this->extractedCss,
            'customClasses' => array_unique($this->customClasses),
            'stats' => $this->report->toArray(),
        ];

        // Optionally validate output
        if ($this->validateOutput) {
            $this->validator->reset();
            if (!$this->validator->validateConversionResult($result)) {
                $result['validationErrors'] = $this->validator->getErrors();
                $this->report->addWarning('Output validation failed: ' . implode('; ', $this->validator->getErrors()));
            }
            if (!empty($this->validator->getWarnings())) {
                $result['validationWarnings'] = $this->validator->getWarnings();
            }
        }

        return $result;
    }


    /**
     * Extract CSS from <style> tags in the entire document
     */
    private function extractStyleTags(\DOMDocument $doc): string
    {
        $css = '';
        $styleTags = $doc->getElementsByTagName('style');

        foreach ($styleTags as $styleTag) {
            $content = $styleTag->textContent;
            // Fix invalid CSS (fontFamily -> font-family)
            $content = str_replace('fontFamily', 'font-family', $content);

            if (!empty(trim($content))) {
                // Apply nav-scrolled CSS rewrite heuristic if enabled
                $content = $this->heuristics->applyNavScrolledCssRewrite($content);

                $css .= "/* Extracted from <style> tag */\n";
                $css .= trim($content) . "\n\n";
            }
        }

        return $css;
    }

    /**
     * Extract <link> tags from <head> (stylesheets, preconnect, etc.)
     */
    private function extractHeadLinks(\DOMDocument $doc): array
    {
        $elements = [];
        $linkTags = $doc->getElementsByTagName('link');

        foreach ($linkTags as $linkTag) {
            $rel = strtolower($linkTag->getAttribute('rel'));

            // Only extract stylesheet and preconnect links
            if (!in_array($rel, ['stylesheet', 'preconnect'])) {
                continue;
            }

            $html = $doc->saveHTML($linkTag);
            if (empty(trim($html))) {
                continue;
            }

            $elements[] = [
                'id' => $this->generateNodeId(),
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

        return $elements;
    }

    /**
     * Create a CSS Code element for extracted styles
     * Returns null if inlineStyles mode is enabled
     */
    private function createCssCodeElement(string $css): ?array
    {
        // In inline styles mode, we don't create CSS Code elements
        // All styles are applied directly to elements via applyCssRules
        if ($this->inlineStyles) {
            return null;
        }

        return [
            'id' => $this->generateNodeId(),
            'data' => [
                'type' => ElementTypes::CSS_CODE,
                'properties' => [
                    'content' => [
                        'content' => [
                            'css_code' => $css,
                        ],
                    ],
                ],
            ],
            'children' => [],
        ];
    }

    /**
     * Convert a DOM node to Oxygen element structure
     */
    private function convertNode(DOMNode $node): ?array
    {
        // Handle text nodes
        if ($node instanceof DOMText) {
            $text = trim($node->textContent);
            if ($text === '') {
                return null;
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

        // Handle element nodes
        if (!($node instanceof DOMElement)) {
            return null;
        }

        // Skip certain elements
        if ($this->parser->shouldSkipNode($node)) {
            return null;
        }

        $tag = strtolower($node->tagName);

        // Handle Script Tags
        if ($tag === 'script') {
            $src = $node->getAttribute('src');
            $scriptContent = $node->textContent;

            // External script -> Use HTML Code to preserve the tag
            if ($src) {
                $element = [
                    'id' => $this->generateNodeId(),
                    'data' => [
                        'type' => ElementTypes::HTML_CODE,
                        'properties' => [
                            'content' => [
                                'content' => [
                                    'html_code' => $node->ownerDocument->saveHTML($node),
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

            // Inline script -> Use JavaScript Code
            if (!empty(trim($scriptContent))) {
                $this->report->incrementElementCount();

                // Transform JavaScript to make functions available on window object
                // This is required for Oxygen's interaction system to call them
                $transformedJs = $this->jsTransformer->transformJavaScriptForOxygen($scriptContent);

                // Strip JS patterns that were converted to native Oxygen features
                $hasObserver = strpos($scriptContent, 'IntersectionObserver') !== false
                    && strpos($scriptContent, 'animate-on-scroll') !== false;
                $hasSmoothScroll = $this->jsPatterns['smoothScroll']
                    && strpos($scriptContent, 'scrollIntoView') !== false;
                $toggleIds = [];
                foreach ($this->jsPatterns['toggles'] as $key => $data) {
                    if (strpos($key, '__selector__') === 0) {
                        continue;
                    }
                    // Check if this script contains the getElementById for this ID
                    if (strpos($scriptContent, "'" . $key . "'") !== false || strpos($scriptContent, '"' . $key . '"') !== false) {
                        $toggleIds[] = $key;
                    }
                }

                $transformedJs = $this->jsTransformer->stripConvertedPatterns(
                    $transformedJs,
                    $hasObserver,
                    $hasSmoothScroll,
                    $toggleIds
                );

                // If JS is empty after cleanup, skip creating the element
                if (empty(trim($transformedJs))) {
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
            return null;
        }

        // Skip <style> tags — all styles are already captured by extractStyleTags()
        // which creates a single combined CSS Code element to avoid duplication
        if ($tag === 'style') {
            return null;
        }

        // Handle Link Tags (External CSS)
        if ($tag === 'link') {
            $element = [
                'id' => $this->generateNodeId(),
                'data' => [
                    'type' => ElementTypes::HTML_CODE,
                    'properties' => [
                        'content' => [
                            'content' => [
                                'html_code' => $node->ownerDocument->saveHTML($node),
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

        $elementType = $this->mapper->getElementType($tag, $node);

        $this->report->incrementElementCount();

        // Build base element
        $element = [
            'id' => $this->generateNodeId(),
            'data' => [
                'type' => $elementType,
                'properties' => [],
            ],
            'children' => [],
        ];

        // Get element properties from mapper
        $contentProperties = $this->mapper->buildProperties($node);

        // Extract and convert styles
        $styleProperties = $this->styleExtractor->extractAndConvert($node);

        // Merge properties
        $element['data']['properties'] = $this->mergeProperties($contentProperties, $styleProperties);

        // Handle tag option
        $tagOption = $this->mapper->getTagOption($tag);
        if ($tagOption) {
            $element['data']['properties']['design'] = $element['data']['properties']['design'] ?? [];
            $element['data']['properties']['design']['tag'] = $tagOption;
        }

        // Apply heuristics (optional template-specific optimizations)
        $this->heuristics->applyStickyNavbar($node, $element);
        $this->heuristics->applyNavLinkWhite($node, $element);
        $this->heuristics->applyRoundedFullCentering($node, $element);
        $this->heuristics->applyButtonCentering($tag, $elementType, $element);

        // Sanitize URLs for Images and Links
        if ($tag === 'img' && isset($element['data']['properties']['content']['image']['url'])) {
            $element['data']['properties']['content']['image']['url'] = $this->sanitizeUrl($element['data']['properties']['content']['image']['url']);
        }
        if ($tag === 'a' && isset($element['data']['properties']['content']['content']['url'])) {
            $element['data']['properties']['content']['content']['url'] = $this->sanitizeUrl($element['data']['properties']['content']['content']['url']);
        }

        // Apply fixed header spacing heuristic (optional)
        $this->heuristics->applyFixedHeaderSpacing(
            $node,
            $element,
            $this->fixedHeaderDetected,
            $this->firstBodyElementProcessed
        );

        // Process CSS classes (settings.advanced.classes)
        $this->processClasses($node, $element);

        // Process HTML ID attribute (settings.advanced.id)
        $this->processId($node, $element);

        // Detect and apply native entrance animations
        $classAttr = $node->getAttribute('class');
        $classNames = $classAttr ? array_filter(array_map('trim', explode(' ', $classAttr))) : [];
        $animationSettings = $this->animationDetector->detectAnimations($node, $classNames, $this->cssRules);
        if ($animationSettings) {
            $element['data']['properties']['settings'] = $element['data']['properties']['settings'] ?? [];
            $element['data']['properties']['settings']['animations'] = $element['data']['properties']['settings']['animations'] ?? [];
            $element['data']['properties']['settings']['animations']['entrance_animation'] = $animationSettings;

            // Remove consumed animation classes from element
            $consumedClasses = $this->animationDetector->getConsumedClasses();
            if (!empty($consumedClasses) && isset($element['data']['properties']['settings']['advanced']['classes'])) {
                $element['data']['properties']['settings']['advanced']['classes'] = array_values(
                    array_diff($element['data']['properties']['settings']['advanced']['classes'], $consumedClasses)
                );
            }
        }

        // Apply pre-detected toggle interactions from JS analysis
        $elementId = $node->getAttribute('id');
        if ($elementId && isset($this->jsPatterns['toggles'][$elementId])) {
            $this->interactionDetector->applyDetectedInteraction(
                $elementId,
                $this->jsPatterns['toggles'][$elementId]['interaction'],
                $element
            );
        }

        // Apply smooth scroll to anchor links
        if ($this->jsPatterns['smoothScroll'] && $tag === 'a') {
            $href = $node->getAttribute('href');
            if ($href && strpos($href, '#') === 0 && strlen($href) > 1) {
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
        }

        // Apply class-based interactions from querySelectorAll patterns (e.g., .mobile-link)
        foreach ($this->jsPatterns['toggles'] as $key => $data) {
            if (strpos($key, '__selector__') !== 0) {
                continue;
            }
            $selector = $data['selector'] ?? '';
            // Check if selector is a class selector and element has that class
            if (strpos($selector, '.') === 0) {
                $selectorClass = substr($selector, 1);
                if (in_array($selectorClass, $classNames, true)) {
                    $this->interactionDetector->applyDetectedInteraction('', $data['interaction'], $element);
                }
            }
        }

        // Process custom attributes (data-*, aria-*, onclick, etc.)
        $this->interactionDetector->processCustomAttributes($node, $element);

        // Detect frameworks and add warnings
        $this->frameworkDetector->detect($node);

        // Check for interactive elements and add warnings
        $this->checkForWarnings($node);

        // Special handling for buttons and button-like links: create child Text element
        if ($this->mapper->needsChildTextElement($node)) {
            $textChild = $this->mapper->buildChildTextElement($node, $this->generateNodeId());
            if ($textChild !== null) {
                // Ensure text inside button is centered in Oxygen
                $textChild['data']['properties']['design']['typography'] = $textChild['data']['properties']['design']['typography'] ?? [];
                $textChild['data']['properties']['design']['typography']['text-align'] = 'center';
                $element['children'][] = $textChild;
            }

            // Don't process other children for buttons - they're handled as text
            return $element;
        }

        // Process children if this is a container element
        if ($this->mapper->isContainer($tag, $node) && !$this->mapper->shouldKeepInnerHtml($tag)) {
            $children = [];
            foreach ($node->childNodes as $childNode) {
                $childElement = $this->convertNode($childNode);
                if ($childElement !== null) {
                    $children[] = $childElement;
                }
            }
            $element['children'] = $children;

            // If container has no children but has text, convert text content
            if (empty($children) && trim($node->textContent) !== '') {
                // Check if it should be converted to text element
                if ($this->mapper->shouldConvertToText($node)) {
                    $element['data']['type'] = ElementTypes::TEXT;
                    // IMPORTANT: Preserve existing properties (like settings.advanced.classes)
                    // by only setting the content, not replacing the entire properties array
                    if (!isset($element['data']['properties']['content'])) {
                        $element['data']['properties']['content'] = [];
                    }
                    $element['data']['properties']['content']['content'] = [
                        'text' => $this->mapper->getInnerHtml($node),
                    ];
                }
            }
        }



        // Apply CSS rules from style tags if they match this element's ID
        $this->applyCssRules($element, $this->cssRules);

        return $element;
    }

    /**
     * Pre-analyze all JavaScript in the document for toggle/scroll patterns.
     *
     * @return array ['toggles' => [...], 'smoothScroll' => bool]
     */
    private function analyzeJavaScriptPatterns(\DOMDocument $doc): array
    {
        $allJs = '';
        $scriptTags = $doc->getElementsByTagName('script');

        foreach ($scriptTags as $script) {
            if (!$script->getAttribute('src')) {
                $allJs .= $script->textContent . "\n";
            }
        }

        $toggles = $this->interactionDetector->detectTogglePatterns($allJs);
        $smoothScroll = $this->interactionDetector->detectSmoothScrollPattern($allJs);

        if (!empty($toggles)) {
            $this->report->addInfo('Detected ' . count($toggles) . ' toggle interaction(s) from JavaScript — converted to native Oxygen interactions.');
        }
        if ($smoothScroll) {
            $this->report->addInfo('Detected smooth scroll pattern — converted to native Oxygen scroll_to interactions on anchor links.');
        }

        return [
            'toggles' => $toggles,
            'smoothScroll' => $smoothScroll,
        ];
    }

    /**
     * Apply CSS rules from style tags to an element
     */
    private function applyCssRules(array &$element, array $cssRules): void
    {
        if (empty($cssRules)) {
            $this->logDebug('No CSS rules to apply');
            return;
        }

        $elementId = $element['data']['properties']['settings']['advanced']['id'] ?? null;
        $elementClasses = $element['data']['properties']['settings']['advanced']['classes'] ?? [];
        $elementType = $element['data']['type'] ?? 'unknown';

        $this->logDebug(sprintf(
            'Applying CSS rules to element type=%s, id=%s, classes=%s',
            $elementType,
            $elementId ?? 'none',
            implode(',', $elementClasses) ?: 'none'
        ));

        $matchedCount = 0;

        foreach ($cssRules as $rule) {
            $selector = $rule['selector'];
            $matched = false;

            // Match #id (exact)
            if ($elementId && $selector === '#' . $elementId) {
                $matched = true;
                $this->logDebug("Matched ID selector: $selector");
            }

            // Match .className - improved to handle multiple class selectors
            // e.g., .flex, .items-center, .flex.items-center, .md:flex
            if (!$matched && strpos($selector, '.') === 0) {
                $matched = $this->selectorMatchesElement($selector, $elementClasses, $elementId);
                if ($matched) {
                    $this->logDebug("Matched class selector: $selector");
                }
            }

            // Match element selectors (e.g., div, section, article)
            if (!$matched && preg_match('/^[a-z][a-z0-9]*$/i', $selector)) {
                // Get tag name from element type
                $tagName = $this->getTagNameFromElement($element);
                if ($tagName && strtolower($selector) === strtolower($tagName)) {
                    $matched = true;
                    $this->logDebug("Matched tag selector: $selector");
                }
            }

            // Match [attribute] selectors - simplified check
            if (!$matched && strpos($selector, '[') !== false && $elementId) {
                // Check for [id="..."] pattern
                if (preg_match('/\[id=["\']?' . preg_quote($elementId, '/') . '["\']?\]/', $selector)) {
                    $matched = true;
                    $this->logDebug("Matched attribute selector: $selector");
                }
            }

            if ($matched) {
                $expandedDeclarations = $this->expandShorthandProperties($rule['declarations']);
                $convertedStyles = $this->styleExtractor->toOxygenProperties($expandedDeclarations);

                $this->logDebug(sprintf(
                    'Applying styles: %s',
                    json_encode($convertedStyles)
                ));

                $element['data']['properties'] = $this->mergeProperties(
                    $element['data']['properties'], ['design' => $convertedStyles]
                );
                $this->consumedCssSelectors[$selector] = true;
                $matchedCount++;
            }
        }

        $this->logDebug("Total rules matched: $matchedCount");
    }

    /**
     * Check if a CSS selector matches an element's classes
     * Handles: .class, .class1.class2, .responsive:class, etc.
     */
    private function selectorMatchesElement(string $selector, array $elementClasses, ?string $elementId): bool
    {
        // Remove pseudo-classes and pseudo-elements (:hover, ::before, etc.)
        $selector = preg_replace('/::?[a-z-]+(\([^)]*\))?/', '', $selector);
        
        // Split by combinators (space, >, +, ~)
        $parts = preg_split('/\s*[>+~]\s*|\s+(?![\[\(])/', $selector);
        $lastPart = end($parts);
        
        // Extract classes from the last part of selector (the element itself)
        preg_match_all('/\.([a-zA-Z0-9_-]+)/', $lastPart, $matches);
        $selectorClasses = $matches[1] ?? [];
        
        if (empty($selectorClasses)) {
            return false;
        }
        
        // Check if ALL classes from selector are present in element
        foreach ($selectorClasses as $class) {
            if (!in_array($class, $elementClasses, true)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Get tag name from Oxygen element type
     */
    private function getTagNameFromElement(array $element): ?string
    {
        $type = $element['data']['type'] ?? '';
        
        // Map Oxygen element types to HTML tags
        $mapping = [
            'OxygenElements\\Container' => 'div',
            'OxygenElements\\Text' => 'span',
            'OxygenElements\\TextLink' => 'a',
            'OxygenElements\\Image' => 'img',
            'OxygenElements\\RichText' => 'div',
            'OxygenElements\\Header' => 'header',
        ];
        
        return $mapping[$type] ?? null;
    }

    /**
     * Expand shorthand CSS properties into longhand equivalents
     */
    private function expandShorthandProperties(array $declarations): array
    {
        $expanded = [];

        foreach ($declarations as $property => $value) {
            if ($property === 'margin' || $property === 'padding') {
                $sides = $this->styleExtractor->parseShorthandSpacing($value);
                if (!empty($sides)) {
                    $expanded[$property . '-top'] = $sides['top'];
                    $expanded[$property . '-right'] = $sides['right'];
                    $expanded[$property . '-bottom'] = $sides['bottom'];
                    $expanded[$property . '-left'] = $sides['left'];
                } else {
                    $expanded[$property] = $value;
                }
            } elseif ($property === 'border' && preg_match('/^(\S+)\s+(\S+)\s+(.+)$/', $value, $m)) {
                $expanded['border-width'] = $m[1];
                $expanded['border-style'] = $m[2];
                $expanded['border-color'] = $m[3];
            } elseif ($property === 'background' && preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([^)]+\)|[a-zA-Z]+)$/', trim($value))) {
                $expanded['background-color'] = trim($value);
            } else {
                $expanded[$property] = $value;
            }
        }

        return $expanded;
    }

    /**
     * Remove consumed CSS rules from the raw CSS string
     */
    private function cleanupConsumedCssRules(string $css): string
    {
        if (empty($this->consumedCssSelectors)) {
            return $css;
        }

        foreach (array_keys($this->consumedCssSelectors) as $selector) {
            $escaped = preg_quote($selector, '/');
            // Match the selector followed by its rule block { ... }
            $pattern = '/' . $escaped . '\s*\{[^}]*\}\s*/';
            $css = preg_replace($pattern, '', $css);
        }

        return $css;
    }

    /**
     * Process CSS classes - uses settings.advanced.classes for Oxygen rendering
     *
     * Oxygen reads classes from: node['data']['properties']['settings']['advanced']['classes']
     * Data type must be: string[] (array of class name strings)
     * See: plugin/render/renderer.php getAppliedClassNames()
     */
    private function processClasses(DOMElement $node, array &$element): void
    {
        $classAttr = $node->getAttribute('class');
        if (!$classAttr) {
            return;
        }

        $classNames = array_filter(array_map('trim', explode(' ', $classAttr)));

        if (empty($classNames)) {
            return;
        }

        // Track custom classes for the report and final response
        foreach ($classNames as $className) {
            if (!$this->tailwindDetector->isTailwindClass($className)) {
                $this->customClasses[] = $className;
            }
        }

        // Use strategy service to process classes based on mode
        $this->classStrategy->processClasses($classNames, $element);


    }

    /**
     * Process HTML ID attribute - stores in settings.advanced.id
     *
     * Oxygen reads ID from: node['data']['properties']['settings']['advanced']['id']
     * See: plugin/render/renderer.php getHtmlId()
     */
    private function processId(DOMElement $node, array &$element): void
    {
        $id = $node->getAttribute('id');
        if (!$id) {
            return;
        }

        // Store in settings.advanced.id - this is where Oxygen's renderer reads it
        $element['data']['properties']['settings'] = $element['data']['properties']['settings'] ?? [];
        $element['data']['properties']['settings']['advanced'] = $element['data']['properties']['settings']['advanced'] ?? [];
        $element['data']['properties']['settings']['advanced']['id'] = $id;
    }



    /**
     * Store attributes in element (legacy method - kept for compatibility)
     */
    private function storeAttributes(array $attributes, array &$element): void
    {
        if (empty($attributes)) {
            return;
        }

        // Store in settings.advanced.attributes
        $element['data']['properties']['settings'] = $element['data']['properties']['settings'] ?? [];
        $element['data']['properties']['settings']['advanced'] = $element['data']['properties']['settings']['advanced'] ?? [];
        $element['data']['properties']['settings']['advanced']['attributes'] = $attributes;
    }


    /**
     * Check for elements that may need warnings
     */
    private function checkForWarnings(DOMElement $node): void
    {
        // Check for icons
        if ($node->hasAttribute('data-lucide') || $node->hasAttribute('data-feather')) {
            $iconName = $node->getAttribute('data-lucide') ?: $node->getAttribute('data-feather');
            $this->addWarning("Icon element (data-lucide=\"{$iconName}\") detected. Scripts are automatically included, but you may need to adjust the icon size or color manually in Oxygen.");
        }
    }

    /**
     * Add a warning to the conversion stats (deduped)
     */
    private function addWarning(string $warning): void
    {
        $this->report->addWarning($warning);
    }

    /**
     * Merge content and style properties
     */
    private function mergeProperties(array $content, array $styles): array
    {
        $merged = $content;

        foreach ($styles as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = array_merge_recursive($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Generate unique node ID
     */
    private function generateNodeId(): int
    {
        return $this->nodeIdCounter++;
    }

    /**
     * Get the parser instance
     */
    public function getParser(): HtmlParser
    {
        return $this->parser;
    }

    /**
     * Get the mapper instance
     */
    public function getMapper(): ElementMapper
    {
        return $this->mapper;
    }

    /**
     * Get the style extractor instance
     */
    public function getStyleExtractor(): StyleExtractor
    {
        return $this->styleExtractor;
    }

    /**
     * Set starting node ID (useful when adding to existing document)
     */
    public function setStartingNodeId(int $id): void
    {
        $this->nodeIdCounter = $id;
    }

    /**
     * Get conversion statistics
     */
    public function getStats(): array
    {
        return $this->report->toArray();
    }

    /**
     * Get the heuristics service for configuration
     */
    public function getHeuristics(): HeuristicsService
    {
        return $this->heuristics;
    }

    /**
     * Enable all heuristics for template-specific conversion
     */
    public function enableAllHeuristics(): void
    {
        $this->heuristics->enableAll();
    }

    /**
     * Disable all heuristics for general-purpose conversion
     */
    public function disableAllHeuristics(): void
    {
        $this->heuristics->disableAll();
    }

    /**
     * Enable output validation
     */
    public function enableValidation(): void
    {
        $this->validateOutput = true;
    }

    /**
     * Disable output validation
     */
    public function disableValidation(): void
    {
        $this->validateOutput = false;
    }

    /**
     * Get the validator instance
     */
    public function getValidator(): OutputValidator
    {
        return $this->validator;
    }

    /**
     * Sanitize local URLs
     */
    private function sanitizeUrl(string $url): string
    {
        if (strpos($url, 'file://') === 0) {
            // Extract filename or relative path
            $parts = explode('/', str_replace('\\', '/', $url));
            $filename = end($parts);
            return $filename; // Minimal fix, just keep filename
        }
        return $url;
    }

    /**
     * Enable inline styles mode - all CSS is applied directly to elements
     * instead of creating CSS Code elements
     */
    public function setInlineStyles(bool $enabled): void
    {
        $this->inlineStyles = $enabled;
    }

    /**
     * Enable debug mode - logs additional information during conversion
     */
    public function setDebugMode(bool $enabled): void
    {
        $this->debugMode = $enabled;
    }

    /**
     * Log debug message (if debug mode is enabled)
     */
    private function logDebug(string $message): void
    {
        if ($this->debugMode) {
            $this->report->addInfo('[DEBUG] ' . $message);
        }
    }
}
