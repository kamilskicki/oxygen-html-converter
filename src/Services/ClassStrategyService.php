<?php

namespace OxyHtmlConverter\Services;

use OxyHtmlConverter\Report\ConversionReport;

/**
 * Strategy service for handling CSS classes in different modes
 */
class ClassStrategyService
{
    private EnvironmentService $environment;
    private ConversionReport $report;
    private TailwindDetector $tailwindDetector;

    public function __construct(EnvironmentService $environment, ConversionReport $report, TailwindDetector $tailwindDetector)
    {
        $this->environment = $environment;
        $this->report = $report;
        $this->tailwindDetector = $tailwindDetector;
    }

    /**
     * Process classes for an element based on the current mode
     *
     * @param array $classes Original class names
     * @param array &$element Reference to the Oxygen element structure
     */
    public function processClasses(array $classes, array &$element): void
    {
        if (empty($classes)) {
            return;
        }

        if ($this->environment->shouldUseWindPressMode()) {
            $this->processWindPressMode($classes, $element);
        } else {
            $this->processOxygenNativeMode($classes, $element);
        }
    }

    /**
     * WindPress Mode: Store all classes as-is
     */
    private function processWindPressMode(array $classes, array &$element): void
    {
        foreach ($classes as $className) {
            if ($this->tailwindDetector->isTailwindClass($className)) {
                $this->report->incrementTailwindClassCount();
            } else {
                $this->report->incrementCustomClassCount();
            }
        }

        $this->setElementClasses($element, $classes);
    }

    /**
     * Oxygen Native Mode: Separate Tailwind and custom classes
     */
    private function processOxygenNativeMode(array $classes, array &$element): void
    {
        $tailwindClasses = [];
        $customClasses = [];

        foreach ($classes as $className) {
            if ($this->tailwindDetector->isTailwindClass($className)) {
                $tailwindClasses[] = $className;
                $this->report->incrementTailwindClassCount();
            } else {
                $customClasses[] = $className;
                $this->report->incrementCustomClassCount();
            }
        }

        if (!empty($tailwindClasses)) {
            $this->report->addWarning("Oxygen Native Mode: Tailwind class conversion to properties not yet implemented. Classes preserved as-is for now.");
        }

        // Preserve all for now (matching current behavior but with clear separation)
        $this->setElementClasses($element, array_merge($customClasses, $tailwindClasses));
    }

    /**
     * Set classes in the Oxygen element structure
     */
    private function setElementClasses(array &$element, array $classes): void
    {
        if (!isset($element['data']['properties']['settings'])) {
            $element['data']['properties']['settings'] = [];
        }
        if (!isset($element['data']['properties']['settings']['advanced'])) {
            $element['data']['properties']['settings']['advanced'] = [];
        }
        $element['data']['properties']['settings']['advanced']['classes'] = array_values($classes);
    }

}