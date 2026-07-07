<?php

namespace OxyHtmlConverter;

use OxyHtmlConverter\Report\ConversionReport;
use OxyHtmlConverter\Services\JavaScriptTransformer;
use OxyHtmlConverter\Services\EnvironmentService;
use OxyHtmlConverter\Services\ClassStrategyService;
use OxyHtmlConverter\Services\IconDetector;
use OxyHtmlConverter\Services\InteractionDetector;
use OxyHtmlConverter\Services\TailwindDetector;
use OxyHtmlConverter\Services\TailwindCssFallbackGenerator;
use OxyHtmlConverter\Services\TailwindPropertyMapper;
use OxyHtmlConverter\Services\FrameworkDetector;
use OxyHtmlConverter\Services\CssParser;
use OxyHtmlConverter\Services\AnimationDetector;
use OxyHtmlConverter\Services\DocumentCssExtractor;
use OxyHtmlConverter\Services\StyleRoutingService;
use OxyHtmlConverter\Services\HeuristicsService;
use OxyHtmlConverter\Services\AssetNormalizationService;
use OxyHtmlConverter\Services\ConversionFallbackReporter;
use OxyHtmlConverter\Services\ConversionIrBuilder;
use OxyHtmlConverter\Services\HeadAssetExtractor;
use OxyHtmlConverter\Services\HtmlNormalizer;
use OxyHtmlConverter\Services\HtmlCodeSanitizer;
use OxyHtmlConverter\Services\NativeCssMaterializer;
use OxyHtmlConverter\Services\NativeElementMapper;
use OxyHtmlConverter\Services\NativeNodeMapper;
use OxyHtmlConverter\Services\OxygenSelectorImporter;
use OxyHtmlConverter\Services\SelectorMatcher;
use OxyHtmlConverter\Validation\OutputValidator;
use OxyHtmlConverter\ElementTypes;
use DOMDocument;

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
    private TailwindCssFallbackGenerator $tailwindFallbackGenerator;
    private TailwindPropertyMapper $tailwindPropertyMapper;
    private FrameworkDetector $frameworkDetector;
    private CssParser $cssParser;
    private AnimationDetector $animationDetector;
    private HeuristicsService $heuristics;
    private DocumentCssExtractor $documentCssExtractor;
    private StyleRoutingService $styleRoutingService;
    private AssetNormalizationService $assetNormalizationService;
    private ConversionFallbackReporter $fallbackReporter;
    private HtmlNormalizer $htmlNormalizer;
    private ConversionIrBuilder $conversionIrBuilder;
    private NativeCssMaterializer $nativeCssMaterializer;
    private NativeElementMapper $nativeElementMapper;
    private HeadAssetExtractor $headAssetExtractor;
    private NativeNodeMapper $nativeNodeMapper;
    private OxygenSelectorImporter $selectorImporter;
    private HtmlCodeSanitizer $htmlCodeSanitizer;
    private OutputValidator $validator;
    private ConversionReport $report;

    private bool $validateOutput = false;
    private bool $inlineStyles = true;  // NEW: Force all styles inline instead of CSS Code
    private bool $debugMode = false;     // NEW: Enable debug logging
    private bool $safeMode = true;
    private bool $allowExecutableCode = false;
    private ?bool $preferEssentialElements = null;
    private int $nodeIdCounter = 1;
    private string $extractedCss = '';
    private array $detectedIconLibraries = [];
    private array $cssRules = [];
    private array $jsPatterns = [];
    private array $styleRouting = [];
    private array $semanticClassProfile = [];

    public function __construct()
    {
        $this->parser = new HtmlParser();
        $this->mapper = new ElementMapper();
        $this->styleExtractor = new StyleExtractor();
        $this->jsTransformer = new JavaScriptTransformer();
        $this->report = new ConversionReport();
        $this->environment = new EnvironmentService();
        $this->tailwindDetector = new TailwindDetector();
        $this->tailwindFallbackGenerator = new TailwindCssFallbackGenerator();
        $this->tailwindPropertyMapper = new TailwindPropertyMapper();
        $this->classStrategy = new ClassStrategyService(
            $this->environment,
            $this->report,
            $this->tailwindDetector,
            $this->tailwindPropertyMapper
        );
        $this->iconDetector = new IconDetector();
        $this->frameworkDetector = new FrameworkDetector($this->report);
        $this->interactionDetector = new InteractionDetector($this->frameworkDetector);
        $this->syncInteractionPolicy();
        $this->cssParser = new CssParser();
        $this->animationDetector = new AnimationDetector();
        $this->heuristics = new HeuristicsService();
        $this->documentCssExtractor = new DocumentCssExtractor(
            $this->heuristics,
            $this->tailwindDetector,
            $this->tailwindPropertyMapper,
            $this->tailwindFallbackGenerator
        );
        $this->styleRoutingService = new StyleRoutingService();
        $this->assetNormalizationService = new AssetNormalizationService();
        $this->headAssetExtractor = new HeadAssetExtractor(function (): int {
            return $this->generateNodeId();
        });
        $this->selectorImporter = new OxygenSelectorImporter();
        $this->htmlCodeSanitizer = new HtmlCodeSanitizer();
        $selectorMatcher = new SelectorMatcher();
        $this->nativeCssMaterializer = new NativeCssMaterializer(
            $this->styleExtractor,
            $this->cssParser,
            $selectorMatcher,
            $this->selectorImporter,
            $this->environment
        );
        $this->fallbackReporter = new ConversionFallbackReporter(
            $this->report,
            $this->htmlCodeSanitizer
        );
        $this->htmlNormalizer = new HtmlNormalizer(
            $this->parser,
            $this->documentCssExtractor,
            $this->cssParser,
            $this->headAssetExtractor,
            $this->frameworkDetector,
            $this->heuristics
        );
        $this->conversionIrBuilder = new ConversionIrBuilder(
            $this->interactionDetector,
            $this->iconDetector,
            $this->report
        );
        $this->nativeNodeMapper = new NativeNodeMapper(
            $this->parser,
            $this->mapper,
            $this->styleExtractor,
            $this->jsTransformer,
            $this->environment,
            $this->classStrategy,
            $this->tailwindDetector,
            $this->interactionDetector,
            $this->frameworkDetector,
            $this->animationDetector,
            $this->heuristics,
            $this->htmlCodeSanitizer,
            $this->selectorImporter,
            $this->nativeCssMaterializer,
            $this->fallbackReporter,
            $this->report
        );
        $this->nativeElementMapper = new NativeElementMapper($this->nativeNodeMapper);
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
        $this->detectedIconLibraries = [];
        $this->cssRules = [];
        $this->jsPatterns = [];
        $this->styleRouting = [];
        $this->semanticClassProfile = [];
        $this->selectorImporter->reset();
        $this->nativeCssMaterializer->reset();
        $this->report->reset();
        $this->fallbackReporter->configure($this->safeMode, $this->canEmitExecutableCodeFallback());

        // Configure element mapping mode per conversion.
        // Manual override takes precedence; otherwise resolve from environment setting.
        $preferEssentialElements = $this->preferEssentialElements ?? $this->environment->shouldPreferEssentialElements();
        $this->mapper->setPreferEssentialElements($preferEssentialElements);

        // Report compatibility decisions when mapping mode is environment-driven.
        if ($this->preferEssentialElements === null) {
            $mappingMode = $this->environment->getElementMappingMode();
            $essentialPluginActive = $this->environment->isBreakdanceElementsForOxygenActive();

            if ($mappingMode === 'essential' && !$preferEssentialElements) {
                $issues = $essentialPluginActive
                    ? $this->environment->getEssentialButtonContractIssues()
                    : ['Breakdance Elements for Oxygen plugin is not active'];
                $message = 'Essential button mapping was requested, but compatibility contract failed. Falling back to Oxygen mapping.';
                if (!empty($issues)) {
                    $message .= ' Issues: ' . implode('; ', $issues);
                }
                $this->report->addWarning($message);
            } elseif ($mappingMode === 'auto' && $essentialPluginActive && !$preferEssentialElements) {
                $issues = $this->environment->getEssentialButtonContractIssues();
                $message = 'Essential button contract check failed in auto mode. Using Oxygen button mapping.';
                if (!empty($issues)) {
                    $message .= ' Issues: ' . implode('; ', $issues);
                }
                $this->report->addWarning($message);
            } elseif ($preferEssentialElements) {
                $this->report->addInfo('Essential button mapping enabled (contract verified).');
            }
        }

        $normalized = $this->htmlNormalizer->normalize($html);
        if (!$normalized->isSuccess()) {
            return [
                'success' => false,
                'error' => 'Failed to parse HTML',
                'errors' => $normalized->errors(),
            ];
        }

        $conversionIr = $this->conversionIrBuilder->build($normalized);
        $document = $conversionIr->document();
        $this->extractedCss = $conversionIr->extractedCss();
        $this->cssRules = $conversionIr->cssRules();
        $this->semanticClassProfile = $conversionIr->semanticClassProfile();
        $classAliases = $conversionIr->classAliases();
        $this->classStrategy->setClassAliases($classAliases);
        $this->selectorImporter->setClassAliases($classAliases);
        $this->selectorImporter->setCssRules($this->cssRules);
        $this->nativeCssMaterializer->configure($classAliases, $this->inlineStyles, function (string $message): void {
            $this->logDebug($message);
        });
        $this->nativeCssMaterializer->markImportableSelectorCssRulesConsumed($this->cssRules, $conversionIr->sourceClassTokens());
        $this->nativeCssMaterializer->markSemanticAliasCssRulesConsumed($this->cssRules);

        // Pre-analyze CSS rules for animation detection
        $this->animationDetector->analyzeCssRules($this->cssRules, $this->extractedCss);

        $this->jsPatterns = $conversionIr->javaScriptPatterns();
        $detectedIconLibraries = $conversionIr->detectedIconLibraries();
        $this->nativeNodeMapper->configure(
            $this->cssRules,
            $this->jsPatterns,
            $this->inlineStyles,
            $this->safeMode,
            $this->canEmitExecutableCodeFallback(),
            function (): int {
                return $this->generateNodeId();
            }
        );
        $mappingResult = $this->nativeElementMapper->map(
            $conversionIr,
            function (): int {
                return $this->generateNodeId();
            }
        );

        if (!$mappingResult->hasRootElement()) {
            return [
                'success' => false,
                'error' => 'No convertible content found in HTML',
                'stats' => $this->report->toArray(),
                'headLinkElements' => [],
                'headScriptElements' => [],
                'iconScriptElements' => [],
                'detectedIconLibraries' => [],
            ];
        }
        $rootElement = $mappingResult->rootElement();
        if ($rootElement === null) {
            return [
                'success' => false,
                'error' => 'No convertible content found in HTML',
                'stats' => $this->report->toArray(),
                'headLinkElements' => [],
                'headScriptElements' => [],
                'iconScriptElements' => [],
                'detectedIconLibraries' => [],
            ];
        }

        // Keep stateful CSS as fallback, but prune rules that were fully
        // materialized into native Oxygen properties or converted animations.
        $this->extractedCss = $this->nativeCssMaterializer->cleanupConsumedCssRules($this->extractedCss);
        $this->extractedCss = $this->animationDetector->cleanupConvertedCss($this->extractedCss);
        $jsFinalStateCss = $this->canEmitExecutableCodeFallback()
            ? ''
            : $this->nativeCssMaterializer->buildJsFinalStateOverrideCss($this->cssRules);
        if ($jsFinalStateCss !== '') {
            $this->extractedCss = trim($this->extractedCss);
            $this->extractedCss = $this->extractedCss === ''
                ? $jsFinalStateCss
                : $this->extractedCss . "\n" . $jsFinalStateCss;
        }
        $this->nativeCssMaterializer->appendNativeCssMirrorFallback($rootElement);
        $nativeCssMirrorRules = $this->nativeCssMaterializer->nativeCssMirrorRules();
        if ($nativeCssMirrorRules !== []) {
            $this->extractedCss = trim($this->extractedCss);
            $nativeMirrorCss = implode("\n", $nativeCssMirrorRules);
            $this->extractedCss = $this->extractedCss === ''
                ? $nativeMirrorCss
                : $nativeMirrorCss . "\n" . $this->extractedCss;
            $this->report->addInfo(
                'Native CSS mirror fallback emitted for ' . count($nativeCssMirrorRules) . ' element(s).'
            );
        }
        $assetNormalization = $this->assetNormalizationService->buildReport(
            $document,
            $this->extractedCss,
            $this->headAssetExtractor->extractAssetReferences($document),
            $detectedIconLibraries
        );
        $this->extractedCss = $this->assetNormalizationService->sanitizeCss($this->extractedCss, $assetNormalization);
        $this->assetNormalizationService->applyRejectedPolicyToElement($rootElement, $assetNormalization);
        $this->fallbackReporter->reportAssetNormalization($assetNormalization);
        $this->styleRouting = $this->styleRoutingService->route(
            $this->extractedCss,
            $this->environment->shouldUseWindPressMode()
        );
        $this->extractedCss = (string) ($this->styleRouting['pageCss'] ?? $this->extractedCss);

        // Create CSS Code element if we have extracted CSS
        $cssElement = null;
        if (!empty(trim($this->extractedCss))) {
            $cssElement = $this->createCssCodeElement($this->extractedCss);
        }

        $headLinkElements = [];
        $headScriptElements = [];
        $iconScriptElements = [];

        if ($this->canEmitExecutableCodeFallback()) {
            $this->detectedIconLibraries = $detectedIconLibraries;

            // Extract <link> tags from <head> (Google Fonts, preconnect, etc.)
            $headLinkElements = $this->extractHeadLinks($document);
            $this->fallbackReporter->reportHeadAssetNodes($document, 'link', false);

            // Preserve non-icon <script> tags from <head> as raw HTML so execution order remains intact.
            $headScriptElements = $this->extractHeadScripts($document, $this->detectedIconLibraries);
            $this->fallbackReporter->reportHeadAssetNodes($document, 'script', false);

            // Create script elements for detected icon libraries
            $iconScriptElements = $this->iconDetector->createIconLibraryElements(
                $this->detectedIconLibraries,
                function() { return $this->generateNodeId(); }
            );
            $this->fallbackReporter->reportDetectedIconLibraries($this->detectedIconLibraries, false);
        } else {
            $this->fallbackReporter->reportHeadAssetNodes($document, 'link', true);
            $this->fallbackReporter->reportHeadAssetNodes($document, 'script', true);
            $this->fallbackReporter->reportDetectedIconLibraries($detectedIconLibraries, true);
            $this->report->addInfo($this->safeMode
                ? 'Safe mode enabled: stripped scripts, event handlers, and external head assets.'
                : 'Executable-code fallback was not approved: stripped scripts, event handlers, and external head assets.');
        }

        $result = [
            'success' => true,
            'element' => $rootElement,
            'cssElement' => $cssElement,
            'globalCss' => (string) ($this->styleRouting['globalCss'] ?? ''),
            'pageScopedCss' => (string) ($this->styleRouting['pageScopedCss'] ?? ''),
            'styleRouting' => $this->styleRouting,
            'headLinkElements' => $headLinkElements,
            'headScriptElements' => $headScriptElements,
            'iconScriptElements' => $iconScriptElements,
            'detectedIconLibraries' => $this->detectedIconLibraries,
            'assetNormalization' => $assetNormalization,
            'normalization' => $normalized->normalizationReport(),
            'extractedCss' => $this->extractedCss,
            'customClasses' => array_unique($this->nativeNodeMapper->customClasses()),
            'classStrategy' => $this->semanticClassProfile,
            'selectorPayload' => $this->selectorImporter->buildPayload(),
            'stats' => array_merge($this->report->toArray(), [
                'assetNormalization' => $assetNormalization['summary'],
                'normalization' => $normalized->normalizationReport()['summary'],
            ]),
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

        $result = apply_filters('oxy_html_converter_conversion_result', $result, $html, $this);
        return $result;
    }

    /**
     * Extract <link> tags from <head> (stylesheets, preconnect, etc.)
     */
    private function extractHeadLinks(\DOMDocument $doc): array
    {
        return $this->headAssetExtractor->extractLinks($doc);
    }

    /**
     * Extract non-icon <script> tags from <head> in source order.
     *
     * These stay as raw HTML Code blocks instead of JavaScript Code so inline
     * setup like tailwind.config is not delayed by Oxygen's DOMContentLoaded wrapper.
     */
    private function extractHeadScripts(\DOMDocument $doc, array $detectedIconLibraries = []): array
    {
        return $this->headAssetExtractor->extractScripts($doc, $detectedIconLibraries);
    }

    /**
     * Create a CSS Code element for extracted styles
     * Now always creates the element (inline styles mode doesn't disable it)
     */
    private function createCssCodeElement(string $css): ?array
    {
        if (empty(trim($css))) {
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
     * Enable inline styles mode - all CSS is applied directly to elements
     * instead of creating CSS Code elements
     */
    public function setInlineStyles(bool $enabled): void
    {
        $this->inlineStyles = $enabled;
    }

    /**
     * Enable safe mode conversion.
     *
     * Safe mode strips script tags, event handlers, and external head/link assets.
     */
    public function setSafeMode(bool $enabled): void
    {
        $this->safeMode = $enabled;
        $this->syncInteractionPolicy();
    }

    /**
     * Enable unsafe executable/code fallbacks. Safe Mode still overrides this.
     */
    public function setAllowExecutableCode(bool $enabled): void
    {
        $this->allowExecutableCode = $enabled;
        $this->syncInteractionPolicy();
    }

    private function canEmitExecutableCodeFallback(): bool
    {
        return !$this->safeMode && $this->allowExecutableCode;
    }

    private function syncInteractionPolicy(): void
    {
        $this->interactionDetector->setStripEventHandlers(!$this->canEmitExecutableCodeFallback());
    }

    /**
     * Enable debug mode - logs additional information during conversion
     */
    public function setDebugMode(bool $enabled): void
    {
        $this->debugMode = $enabled;
    }

    /**
     * Override auto element mapping to prefer EssentialElements button output.
     */
    public function setPreferEssentialElements(bool $enabled): void
    {
        $this->preferEssentialElements = $enabled;
        $this->mapper->setPreferEssentialElements($enabled);
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
