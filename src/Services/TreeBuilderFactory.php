<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use OxyHtmlConverter\TreeBuilder;

/**
 * Centralizes TreeBuilder creation and option application for AJAX flows.
 */
class TreeBuilderFactory
{
    /**
     * @param array<string, mixed> $options
     */
    public function createForConvert(array $options, string $html): TreeBuilder
    {
        return $this->create($options, 'convert', $html);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function create(array $options, string $context = 'generic', string $html = ''): TreeBuilder
    {
        $builder = apply_filters('oxy_html_converter_tree_builder', new TreeBuilder(), $options, $html, $context);
        if (!($builder instanceof TreeBuilder)) {
            $builder = new TreeBuilder();
        }

        $this->configure($builder, $options);

        return $builder;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function configure(TreeBuilder $builder, array $options): void
    {
        if (isset($options['startingNodeId']) && (int) $options['startingNodeId'] > 1) {
            $builder->setStartingNodeId((int) $options['startingNodeId']);
        }

        $builder->setInlineStyles(!empty($options['inlineStyles']));
        $builder->setSafeMode(!empty($options['safeMode']));
        $builder->setDebugMode(!empty($options['debugMode']));
        $builder->enableValidation();
    }
}
