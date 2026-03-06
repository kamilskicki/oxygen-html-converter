<?php

namespace OxyHtmlConverter;

/**
 * Legacy alias for TreeBuilder.
 *
 * Inline style behavior is now controlled through TreeBuilder::setInlineStyles().
 */
class InlineStyleTreeBuilder extends TreeBuilder
{
    public function __construct()
    {
        parent::__construct();
        $this->setInlineStyles(true);
    }
}
