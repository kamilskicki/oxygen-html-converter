<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use OxyHtmlConverter\ElementTypes;

/**
 * Owns the native element mapping boundary from IR body nodes to an Oxygen root tree.
 */
final class NativeElementMapper
{
    public function __construct(
        private readonly NativeNodeMapper $nodeMapper
    ) {
    }

    /**
     * @param callable(): int $nodeIdGenerator
     */
    public function map(
        ConversionIr $ir,
        callable $nodeIdGenerator
    ): NativeMappingResult {
        $children = [];

        foreach ($ir->bodyNodes() as $node) {
            $element = $this->nodeMapper->mapNode($node);
            if ($element !== null) {
                $children[] = $element;
            }
        }

        $rootElement = null;
        if (count($children) === 1) {
            $rootElement = $children[0];
        } elseif (count($children) > 1) {
            $rootElement = [
                'id' => $nodeIdGenerator(),
                'data' => [
                    'type' => ElementTypes::CONTAINER,
                    'properties' => [],
                ],
                'children' => $children,
            ];
        }

        return new NativeMappingResult($rootElement, $children);
    }
}
