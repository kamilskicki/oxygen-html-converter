<?php

namespace OxyHtmlConverter\Services;

use OxyHtmlConverter\Report\ConversionReport;

/**
 * Service to detect and process framework-specific attributes (Alpine.js, HTMX, Stimulus)
 */
class FrameworkDetector
{
    private ConversionReport $report;

    public function __construct(ConversionReport $report)
    {
        $this->report = $report;
    }

    /**
     * Detect frameworks on a node and return detected frameworks
     */
    public function detect(\DOMElement $node): array
    {
        $detected = [];

        if ($this->hasAlpineAttributes($node)) {
            $detected[] = 'Alpine.js';
            $this->report->addWarning('Alpine.js detected. Ensure Alpine.js script is included in your WordPress site.');
        }

        if ($this->hasHtmxAttributes($node)) {
            $detected[] = 'HTMX';
            $this->report->addWarning('HTMX detected. Ensure HTMX script is included in your WordPress site.');
        }

        if ($this->hasStimulusAttributes($node)) {
            $detected[] = 'Stimulus.js';
            $this->report->addWarning('Stimulus.js detected. Ensure Stimulus.js is properly initialized in your project.');
        }

        return $detected;
    }

    /**
     * Check if a node has Alpine.js attributes
     */
    public function hasAlpineAttributes(\DOMElement $node): bool
    {
        $alpinePrefixes = ['x-', '@', ':', 'data-oxy-at-'];
        foreach ($node->attributes as $attr) {
            $name = $attr->name;
            foreach ($alpinePrefixes as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Check if a node has HTMX attributes
     */
    public function hasHtmxAttributes(\DOMElement $node): bool
    {
        foreach ($node->attributes as $attr) {
            if (str_starts_with($attr->name, 'hx-')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a node has Stimulus.js attributes
     */
    public function hasStimulusAttributes(\DOMElement $node): bool
    {
        $stimulusAttrs = ['data-controller', 'data-action', 'data-target'];
        foreach ($stimulusAttrs as $attrName) {
            if ($node->hasAttribute($attrName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if an attribute name belongs to a supported framework
     */
    public function isFrameworkAttribute(string $name): bool
    {
        // Alpine.js
        if (str_starts_with($name, 'x-') || str_starts_with($name, '@') || str_starts_with($name, ':') || str_starts_with($name, 'data-oxy-at-')) {
            return true;
        }

        // HTMX
        if (str_starts_with($name, 'hx-')) {
            return true;
        }

        // Stimulus.js
        if (in_array($name, ['data-controller', 'data-action', 'data-target'])) {
            return true;
        }

        return false;
    }
}
