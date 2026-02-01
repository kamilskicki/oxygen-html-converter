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
use OxyHtmlConverter\Services\ComponentDetector;
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
    private ComponentDetector $componentDetector;
    private ConversionReport $report;

    private int $nodeIdCounter = 1;
    private string $extractedCss = '';
    private array $customClasses = [];
    private array $detectedIconLibraries = [];
    private array $cssRules = [];
    private bool $firstBodyElementProcessed = false;
    private bool $fixedHeaderDetected = false;

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
        $this->componentDetector = new ComponentDetector($this->report);
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
                    'type' => 'OxygenElements\\Container',
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

        // Create CSS Code element if we have extracted CSS
        $cssElement = null;
        if (!empty(trim($this->extractedCss))) {
            $cssElement = $this->createCssCodeElement($this->extractedCss);
        }

        // Detect icon libraries in the HTML
        $this->detectedIconLibraries = $this->iconDetector->detectIconLibraries($this->parser->getDom());

        // Create script elements for detected icon libraries
        $iconScriptElements = $this->iconDetector->createIconLibraryElements(
            $this->detectedIconLibraries,
            function() { return $this->generateNodeId(); }
        );

        return [
            'success' => true,
            'element' => $rootElement,
            'cssElement' => $cssElement,
            'iconScriptElements' => $iconScriptElements,
            'detectedIconLibraries' => $this->detectedIconLibraries,
            'extractedCss' => $this->extractedCss,
            'customClasses' => array_unique($this->customClasses),
            'stats' => $this->report->toArray(),
        ];
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
                // Map .nav-scrolled to .oxy-header-sticky for Oxygen's native sticky behavior
                // This is the "trick" to make the glass effect work natively with Oxygen's sticky header
                $content = str_replace('.nav-scrolled', '.nav-scrolled, .oxy-header-sticky', $content);
                
                $css .= "/* Extracted from <style> tag */\n";
                $css .= trim($content) . "\n\n";
            }
        }

        return $css;
    }

    /**
     * Create a CSS Code element for extracted styles
     */
    private function createCssCodeElement(string $css): array
    {
        return [
            'id' => $this->generateNodeId(),
            'data' => [
                'type' => 'OxygenElements\\CSS_Code',
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
                    'type' => 'OxygenElements\\Text',
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
                    'type' => 'OxygenElements\\HTML_Code',
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

                $element = [
                    'id' => $this->generateNodeId(),
                    'data' => [
                    'type' => 'OxygenElements\\JavaScript_Code',
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

        // Handle Style Tags
        if ($tag === 'style') {
            $styleContent = $node->textContent;

            if (!empty(trim($styleContent))) {
                $this->report->incrementElementCount();
                $element = [
                    'id' => $this->generateNodeId(),
                    'data' => [
                    'type' => 'OxygenElements\\CSS_Code',
                        'properties' => [
                            'content' => [
                                'content' => [
                                    'css_code' => $styleContent,
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

        // Handle Link Tags (External CSS)
        if ($tag === 'link') {
            $element = [
                'id' => $this->generateNodeId(),
                'data' => [
                'type' => 'OxygenElements\\HTML_Code',
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

        // Special handling for Oxygen Header (sticky settings for navbars)
        if ($tag === 'nav' && $node->getAttribute('id') === 'navbar') {
            $element['data']['properties']['design']['sticky'] = $element['data']['properties']['design']['sticky'] ?? [];
            $sticky = &$element['data']['properties']['design']['sticky'];
            $sticky['position'] = 'top';
            $sticky['relative_to'] = 'viewport';
            $sticky['offset'] = '0';
            
            // Do NOT hardcode nav-scrolled here if we want a scroll transition,
            // instead we map it in the CSS to .oxy-header-sticky (handled in extractStyleTags)
        }

        // If it's a nav link or looks like one, default to white
        if (($node->parentNode instanceof DOMElement && strtolower($node->parentNode->tagName) === 'nav') || strpos($node->getAttribute('class'), 'nav') !== false) {
             $element['data']['properties']['design']['typography']['color'] = '#ffffff';
        }

        // Special handling for Play Icon container or any icon container that should be perfectly centered
        if ($tag === 'span' && strpos($node->getAttribute('class'), 'rounded-full') !== false) {
            $element['data']['properties']['design']['layout'] = $element['data']['properties']['design']['layout'] ?? [];
            $layout = &$element['data']['properties']['design']['layout'];
            $layout['display'] = 'flex';
            $layout['justify-content'] = 'center';
            $layout['align-items'] = 'center';

            // Ensure no line-height issues for icons to prevent vertical misalignment
            $element['data']['properties']['design']['typography'] = $element['data']['properties']['design']['typography'] ?? [];
            $element['data']['properties']['design']['typography']['line-height'] = '0';
        }

        // Special handling for Buttons (Container): Force Flex Centering if not already set
        if (($tag === 'button' || $this->mapper->getElementType($tag, $node) === 'OxygenElements\\Container_Link') && $elementType === 'OxygenElements\\Container') {
            $element['data']['properties']['design']['layout'] = $element['data']['properties']['design']['layout'] ?? [];
            $layout = &$element['data']['properties']['design']['layout'];
            
            // Default to flex, center, center
            $layout['display'] = $layout['display'] ?? 'flex';
            $layout['justify-content'] = $layout['justify-content'] ?? 'center';
            $layout['align-items'] = $layout['align-items'] ?? 'center';
            
            // Add Typography Centering
            $element['data']['properties']['design']['typography'] = $element['data']['properties']['design']['typography'] ?? [];
            $element['data']['properties']['design']['typography']['text-align'] = $element['data']['properties']['design']['typography']['text-align'] ?? 'center';
        }

        // Sanitize URLs for Images and Links
        if ($tag === 'img' && isset($element['data']['properties']['content']['image']['url'])) {
            $element['data']['properties']['content']['image']['url'] = $this->sanitizeUrl($element['data']['properties']['content']['image']['url']);
        }
        if ($tag === 'a' && isset($element['data']['properties']['content']['content']['url'])) {
            $element['data']['properties']['content']['content']['url'] = $this->sanitizeUrl($element['data']['properties']['content']['content']['url']);
        }

        // Fixed Header Spacing Logic
        if (!$this->firstBodyElementProcessed && $node instanceof DOMElement) {
            $classes = $node->getAttribute('class');
            $id = $node->getAttribute('id');
            if (strpos($classes, 'fixed') !== false || strpos($classes, 'sticky') !== false || $id === 'navbar') {
                $this->fixedHeaderDetected = true;
            }
            $this->firstBodyElementProcessed = true;
        } elseif ($this->fixedHeaderDetected && $this->firstBodyElementProcessed) {
            // Add top padding to the next major section to account for fixed header
            if ($tag === 'header' || $tag === 'section' || $tag === 'div') {
                $element['data']['properties']['design']['spacing'] = $element['data']['properties']['design']['spacing'] ?? [];
                $element['data']['properties']['design']['spacing']['padding-top'] = $element['data']['properties']['design']['spacing']['padding-top'] ?? '80px';
                $this->fixedHeaderDetected = false; // Only once
            }
        }

        // Process CSS classes (settings.advanced.classes)
        $this->processClasses($node, $element);

        // Process HTML ID attribute (settings.advanced.id)
        $this->processId($node, $element);

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
                    $element['data']['type'] = 'OxygenElements\\Text';
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
     * Apply CSS rules from style tags to an element
     */
    private function applyCssRules(array &$element, array $cssRules): void
    {
        if (empty($cssRules)) {
            return;
        }

        $elementId = $element['data']['properties']['settings']['advanced']['id'] ?? null;
        if (!$elementId) {
            return;
        }

        foreach ($cssRules as $rule) {
            if ($rule['selector'] === '#' . $elementId) {
                $convertedStyles = $this->styleExtractor->toOxygenProperties($rule['declarations']);
                $element['data']['properties'] = $this->mergeProperties($element['data']['properties'], $convertedStyles);
                
                $this->report->addInfo("Applied CSS rules from <style> tag to element #{$elementId}");
            }
        }
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
}
