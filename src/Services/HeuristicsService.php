<?php

namespace OxyHtmlConverter\Services;

use DOMElement;

/**
 * Service for optional heuristic conversions
 *
 * These are template-specific optimizations that may improve conversion quality
 * for certain types of HTML but could produce unexpected results for others.
 * All heuristics are disabled by default for predictable, general-purpose conversion.
 *
 * Enable specific heuristics via the WordPress option 'oxy_html_converter_heuristics'
 * or by calling enableHeuristic() programmatically.
 */
class HeuristicsService
{
    /**
     * Available heuristics with their default state and description
     */
    private const HEURISTICS = [
        'sticky_navbar' => [
            'default' => false,
            'description' => 'Auto-configure sticky settings for nav#navbar elements',
        ],
        'nav_link_white' => [
            'default' => false,
            'description' => 'Default nav links to white text color',
        ],
        'rounded_full_centering' => [
            'default' => false,
            'description' => 'Auto-center span.rounded-full elements (icon containers)',
        ],
        'button_centering' => [
            'default' => false,
            'description' => 'Auto-center button and button-like elements',
        ],
        'fixed_header_spacing' => [
            'default' => false,
            'description' => 'Add 80px top padding after fixed/sticky headers',
        ],
        'nav_scrolled_css_rewrite' => [
            'default' => false,
            'description' => 'Rewrite .nav-scrolled to .oxy-header-sticky in CSS',
        ],
    ];

    /**
     * Current heuristic states
     */
    private array $enabledHeuristics = [];

    public function __construct()
    {
        $this->loadFromOptions();
    }

    /**
     * Load heuristic settings from WordPress options
     */
    private function loadFromOptions(): void
    {
        // Set defaults
        foreach (self::HEURISTICS as $key => $config) {
            $this->enabledHeuristics[$key] = $config['default'];
        }

        // Load from WordPress option if available
        if (function_exists('get_option')) {
            $saved = get_option('oxy_html_converter_heuristics', []);
            if (is_array($saved)) {
                foreach ($saved as $key => $enabled) {
                    if (isset($this->enabledHeuristics[$key])) {
                        $this->enabledHeuristics[$key] = (bool)$enabled;
                    }
                }
            }
        }
    }

    /**
     * Check if a heuristic is enabled
     */
    public function isEnabled(string $heuristic): bool
    {
        return $this->enabledHeuristics[$heuristic] ?? false;
    }

    /**
     * Enable a heuristic
     */
    public function enableHeuristic(string $heuristic): void
    {
        if (isset(self::HEURISTICS[$heuristic])) {
            $this->enabledHeuristics[$heuristic] = true;
        }
    }

    /**
     * Disable a heuristic
     */
    public function disableHeuristic(string $heuristic): void
    {
        if (isset(self::HEURISTICS[$heuristic])) {
            $this->enabledHeuristics[$heuristic] = false;
        }
    }

    /**
     * Enable all heuristics (for template-specific conversion)
     */
    public function enableAll(): void
    {
        foreach (array_keys(self::HEURISTICS) as $key) {
            $this->enabledHeuristics[$key] = true;
        }
    }

    /**
     * Disable all heuristics (for general-purpose conversion)
     */
    public function disableAll(): void
    {
        foreach (array_keys(self::HEURISTICS) as $key) {
            $this->enabledHeuristics[$key] = false;
        }
    }

    /**
     * Get all available heuristics with their descriptions
     */
    public static function getAvailableHeuristics(): array
    {
        return self::HEURISTICS;
    }

    /**
     * Get current heuristic states
     */
    public function getCurrentStates(): array
    {
        return $this->enabledHeuristics;
    }

    // =========================================================================
    // HEURISTIC METHODS - Apply specific transformations
    // =========================================================================

    /**
     * Apply sticky navbar heuristic
     * Configures Oxygen sticky settings for nav#navbar elements
     */
    public function applyStickyNavbar(DOMElement $node, array &$element): bool
    {
        if (!$this->isEnabled('sticky_navbar')) {
            return false;
        }

        $tag = strtolower($node->tagName);
        $id = $node->getAttribute('id');

        if ($tag === 'nav' && $id === 'navbar') {
            $element['data']['properties']['design'] = $element['data']['properties']['design'] ?? [];
            $element['data']['properties']['design']['sticky'] = $element['data']['properties']['design']['sticky'] ?? [];
            $sticky = &$element['data']['properties']['design']['sticky'];
            $sticky['position'] = 'top';
            $sticky['relative_to'] = 'viewport';
            $sticky['offset'] = '0';
            return true;
        }

        return false;
    }

    /**
     * Apply nav link white color heuristic
     * Sets white text color for links inside nav elements
     */
    public function applyNavLinkWhite(DOMElement $node, array &$element): bool
    {
        if (!$this->isEnabled('nav_link_white')) {
            return false;
        }

        $isInNav = ($node->parentNode instanceof DOMElement && strtolower($node->parentNode->tagName) === 'nav');
        $hasNavClass = strpos($node->getAttribute('class'), 'nav') !== false;

        if ($isInNav || $hasNavClass) {
            $element['data']['properties']['design'] = $element['data']['properties']['design'] ?? [];
            $element['data']['properties']['design']['typography'] = $element['data']['properties']['design']['typography'] ?? [];
            $element['data']['properties']['design']['typography']['color'] = '#ffffff';
            return true;
        }

        return false;
    }

    /**
     * Apply rounded-full centering heuristic
     * Centers span.rounded-full elements (typically icon containers)
     */
    public function applyRoundedFullCentering(DOMElement $node, array &$element): bool
    {
        if (!$this->isEnabled('rounded_full_centering')) {
            return false;
        }

        $tag = strtolower($node->tagName);
        $classes = $node->getAttribute('class');

        if ($tag === 'span' && strpos($classes, 'rounded-full') !== false) {
            $element['data']['properties']['design'] = $element['data']['properties']['design'] ?? [];
            $element['data']['properties']['design']['layout'] = $element['data']['properties']['design']['layout'] ?? [];
            $layout = &$element['data']['properties']['design']['layout'];
            $layout['display'] = 'flex';
            $layout['justify-content'] = 'center';
            $layout['align-items'] = 'center';

            $element['data']['properties']['design']['typography'] = $element['data']['properties']['design']['typography'] ?? [];
            $element['data']['properties']['design']['typography']['line-height'] = '0';
            return true;
        }

        return false;
    }

    /**
     * Apply button centering heuristic
     * Auto-centers button and button-like container elements
     */
    public function applyButtonCentering(string $tag, string $elementType, array &$element): bool
    {
        if (!$this->isEnabled('button_centering')) {
            return false;
        }

        // Only apply to buttons that are Container type
        $isButton = $tag === 'button';
        $isContainer = strpos($elementType, 'Container') !== false && strpos($elementType, 'ContainerLink') === false;

        if ($isButton && $isContainer) {
            $element['data']['properties']['design'] = $element['data']['properties']['design'] ?? [];
            $element['data']['properties']['design']['layout'] = $element['data']['properties']['design']['layout'] ?? [];
            $layout = &$element['data']['properties']['design']['layout'];

            $layout['display'] = $layout['display'] ?? 'flex';
            $layout['justify-content'] = $layout['justify-content'] ?? 'center';
            $layout['align-items'] = $layout['align-items'] ?? 'center';

            $element['data']['properties']['design']['typography'] = $element['data']['properties']['design']['typography'] ?? [];
            $element['data']['properties']['design']['typography']['text-align'] = $element['data']['properties']['design']['typography']['text-align'] ?? 'center';
            return true;
        }

        return false;
    }

    /**
     * Apply fixed header spacing heuristic
     * Adds padding to elements following fixed/sticky headers
     *
     * @param DOMElement $node
     * @param array &$element
     * @param bool &$headerDetected Reference to track if header was detected
     * @param bool &$firstProcessed Reference to track if first element was processed
     * @return bool Whether spacing was applied
     */
    public function applyFixedHeaderSpacing(
        DOMElement $node,
        array &$element,
        bool &$headerDetected,
        bool &$firstProcessed
    ): bool {
        if (!$this->isEnabled('fixed_header_spacing')) {
            return false;
        }

        $tag = strtolower($node->tagName);
        $classes = $node->getAttribute('class');
        $id = $node->getAttribute('id');

        // First element: check if it's a fixed/sticky header
        if (!$firstProcessed) {
            if (strpos($classes, 'fixed') !== false ||
                strpos($classes, 'sticky') !== false ||
                $id === 'navbar') {
                $headerDetected = true;
            }
            $firstProcessed = true;
            return false;
        }

        // Second major element after fixed header: add top padding
        if ($headerDetected && $firstProcessed) {
            if ($tag === 'header' || $tag === 'section' || $tag === 'div') {
                $element['data']['properties']['design'] = $element['data']['properties']['design'] ?? [];
                $element['data']['properties']['design']['spacing'] = $element['data']['properties']['design']['spacing'] ?? [];
                $element['data']['properties']['design']['spacing']['padding-top'] =
                    $element['data']['properties']['design']['spacing']['padding-top'] ?? '80px';
                $headerDetected = false; // Only apply once
                return true;
            }
        }

        return false;
    }

    /**
     * Apply nav-scrolled CSS rewrite heuristic
     * Adds .oxy-header-sticky alongside .nav-scrolled for Oxygen compatibility
     */
    public function applyNavScrolledCssRewrite(string $css): string
    {
        if (!$this->isEnabled('nav_scrolled_css_rewrite')) {
            return $css;
        }

        return str_replace('.nav-scrolled', '.nav-scrolled, .oxy-header-sticky', $css);
    }
}
