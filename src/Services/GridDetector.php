<?php

namespace OxyHtmlConverter\Services;

/**
 * Service to detect and map Tailwind grid classes to Oxygen grid properties
 */
class GridDetector
{
    /**
     * Map grid-cols-* classes to repeat syntax
     */
    public function getGridTemplateColumns(array $classNames): ?string
    {
        foreach ($classNames as $className) {
            if (preg_match('/^grid-cols-(\d+)$/', $className, $matches)) {
                $cols = $matches[1];
                return "repeat({$cols}, minmax(0, 1fr))";
            }
            
            // Handle arbitrary values like grid-cols-[200px_1fr]
            if (preg_match('/^grid-cols-\[(.+)\]$/', $className, $matches)) {
                $value = str_replace('_', ' ', $matches[1]);
                return $value;
            }
        }

        return null;
    }

    /**
     * Map gap-* classes to Oxygen gap properties
     */
    public function getGridGap(array $classNames): array
    {
        $gaps = [];
        
        foreach ($classNames as $className) {
            // gap-4 -> 1rem
            if (preg_match('/^gap-(\d+)$/', $className, $matches)) {
                $val = $matches[1] * 0.25;
                $gaps['gap'] = "{$val}rem";
            }
            // gap-x-4
            if (preg_match('/^gap-x-(\d+)$/', $className, $matches)) {
                $val = $matches[1] * 0.25;
                $gaps['column-gap'] = "{$val}rem";
            }
            // gap-y-4
            if (preg_match('/^gap-y-(\d+)$/', $className, $matches)) {
                $val = $matches[1] * 0.25;
                $gaps['row-gap'] = "{$val}rem";
            }
        }

        return $gaps;
    }

    /**
     * Get all grid properties for an element
     */
    public function getGridProperties(string $classes): array
    {
        $classNames = array_filter(array_map('trim', explode(' ', $classes)));
        $properties = [];
        $isGrid = false;

        foreach ($classNames as $className) {
            if (preg_match('/^grid-(?:cols|rows)-/', $className) || $className === 'grid') {
                $isGrid = true;
                break;
            }
        }

        if ($isGrid) {
            $properties['display'] = 'grid';

            foreach ($classNames as $className) {
                if (preg_match('/^grid-cols-(\d+)$/', $className, $matches)) {
                    $properties['grid']['simple_grid_template_columns'] = $matches[1];
                    continue;
                }

                if (preg_match('/^grid-rows-(\d+)$/', $className, $matches)) {
                    $properties['grid']['simple_grid_template_rows'] = $matches[1];
                }
            }
            
            $cols = $this->getGridTemplateColumns($classNames);
            if ($cols && !isset($properties['grid']['simple_grid_template_columns'])) {
                $properties['grid']['enable_advanced_mode'] = true;
                $properties['grid_template_columns'][0]['size'] = $cols;
            }
            
            $gaps = $this->getGridGap($classNames);
            foreach ($gaps as $key => $val) {
                if ($key === 'gap') {
                    $properties['gap']['row'] = $val;
                    $properties['gap']['column'] = $val;
                    continue;
                }

                if ($key === 'column-gap') {
                    $properties['gap']['column'] = $val;
                    continue;
                }

                if ($key === 'row-gap') {
                    $properties['gap']['row'] = $val;
                    continue;
                }

                $properties[$key] = $val;
            }
        }

        return $properties;
    }
}
