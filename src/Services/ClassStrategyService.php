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
    private TailwindPropertyMapper $tailwindPropertyMapper;

    public function __construct(
        EnvironmentService $environment,
        ConversionReport $report,
        TailwindDetector $tailwindDetector,
        TailwindPropertyMapper $tailwindPropertyMapper
    )
    {
        $this->environment = $environment;
        $this->report = $report;
        $this->tailwindDetector = $tailwindDetector;
        $this->tailwindPropertyMapper = $tailwindPropertyMapper;
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
        $customClasses = [];
        $preservedTailwindClasses = [];
        $mappedProperties = [];
        $preservedUnsupportedTailwind = false;

        foreach ($classes as $className) {
            if ($this->tailwindDetector->isTailwindClass($className)) {
                $this->report->incrementTailwindClassCount();
                $mappedClassProperties = $this->tailwindPropertyMapper->mapClass($className);
                if ($mappedClassProperties !== []) {
                    $mappedProperties = $this->mergeAssociativeProperties($mappedProperties, $mappedClassProperties);
                    continue;
                }

                $preservedTailwindClasses[] = $className;
                $preservedUnsupportedTailwind = true;
            } else {
                $customClasses[] = $className;
                $this->report->incrementCustomClassCount();
            }
        }

        if ($mappedProperties !== []) {
            $element['data']['properties'] = $this->mergeAssociativeProperties(
                $element['data']['properties'] ?? [],
                ['design' => $mappedProperties]
            );
        }

        if ($preservedUnsupportedTailwind) {
            $this->report->addWarning('Native mode preserved unsupported Tailwind utilities as classes to maintain parity.');
        }

        $this->setElementClasses($element, array_merge($customClasses, $preservedTailwindClasses));
    }

    /**
     * Set classes in the Oxygen element structure
     */
    private function setElementClasses(array &$element, array $classes): void
    {
        $classes = array_values(array_unique(array_filter($classes, static fn ($className): bool => is_string($className) && trim($className) !== '')));

        if (!isset($element['data']['properties']['settings'])) {
            $element['data']['properties']['settings'] = [];
        }
        if (!isset($element['data']['properties']['settings']['advanced'])) {
            $element['data']['properties']['settings']['advanced'] = [];
        }

        if ($classes === []) {
            unset($element['data']['properties']['settings']['advanced']['classes']);
            return;
        }

        $element['data']['properties']['settings']['advanced']['classes'] = $classes;
    }

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
}
