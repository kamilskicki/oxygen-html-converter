<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Tests\Unit\Services;

use DOMElement;
use OxyHtmlConverter\HtmlParser;
use OxyHtmlConverter\Services\SelectorMatcher;
use PHPUnit\Framework\TestCase;

final class SelectorMatcherTest extends TestCase
{
    private function selectorMatches(string $html, string $selector, string $id): bool
    {
        $parser = new HtmlParser();
        $parser->parse($html);
        $node = $parser->getDom()->getElementById($id);
        self::assertInstanceOf(DOMElement::class, $node);

        return (new SelectorMatcher())->matchesElement(
            $selector,
            preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [],
            $id,
            $node,
            ['data' => ['properties' => ['design' => ['tag' => $node->tagName]]]]
        );
    }

    public function testChildCombinatorDoesNotMatchGrandchildren(): void
    {
        self::assertFalse($this->selectorMatches('<div class="parent"><div><p id="target"></p></div></div>', '.parent > p', 'target'));
        self::assertTrue($this->selectorMatches('<div class="parent"><p id="target"></p></div>', '.parent > p', 'target'));
    }

    public function testAdjacentSiblingSkipsTextAndCommentsButNotElements(): void
    {
        self::assertTrue($this->selectorMatches('<h2>Heading</h2> text <!-- comment --><p id="target"></p>', 'h2 + p', 'target'));
        self::assertFalse($this->selectorMatches('<h2>Heading</h2><div></div><p id="target"></p>', 'h2 + p', 'target'));
        self::assertFalse($this->selectorMatches('<div class="parent"><p id="target"></p></div>', '.parent + p', 'target'));
    }

    public function testGeneralSiblingMatchesEarlierSiblingsOnly(): void
    {
        self::assertTrue($this->selectorMatches('<h2></h2><div></div><p id="target"></p>', 'h2 ~ p', 'target'));
        self::assertFalse($this->selectorMatches('<p id="target"></p><h2></h2>', 'h2 ~ p', 'target'));
    }

    public function testDescendantMatchingBacktracksAcrossCandidates(): void
    {
        self::assertTrue($this->selectorMatches('<main><div class="a"><section><div class="a"><p id="target"></p></div></section></div></main>', 'main > .a p', 'target'));
    }

    public function testMissingAncestorSearchDoesNotGrowExponentially(): void
    {
        $html = str_repeat('<div>', 30) . '<span id="target"></span>' . str_repeat('</div>', 30);
        $started = microtime(true);
        self::assertFalse($this->selectorMatches($html, '.missing ' . str_repeat('div ', 10) . 'span', 'target'));
        self::assertLessThan(1.0, microtime(true) - $started);
    }

    public function testAttributeValuesDoNotBecomeCombinatorsOrPseudoClasses(): void
    {
        self::assertTrue($this->selectorMatches('<p data-label="a > b:hello" id="target"></p>', '[data-label="a > b:hello"]', 'target'));
        self::assertFalse($this->selectorMatches('<p data-label="other" id="target"></p>', '[data-label="a > b:hello"]', 'target'));
    }

    public function testUnsupportedAttributeOperatorsAreNotTreatedAsMatchAll(): void
    {
        self::assertFalse($this->selectorMatches('<p id="target"></p>', '[data-label^="prefix"]', 'target'));
        self::assertFalse($this->selectorMatches('<p id="target"></p>', '[data-label=""]', 'target'));
        self::assertTrue($this->selectorMatches('<p data-label="" id="target"></p>', '[data-label=""]', 'target'));
    }

    public function testPseudoClassesAreNotAppliedUnconditionally(): void
    {
        self::assertFalse($this->selectorMatches('<p class="card" id="target"></p>', '.card:not(.card)', 'target'));
        self::assertFalse($this->selectorMatches('<p class="card" id="target"></p>', '.card:hover', 'target'));
    }

    public function testEscapedPunctuationAndWhitespaceClassLists(): void
    {
        self::assertTrue($this->selectorMatches("<div class=\"parent\"><p class=\"one\tcard\" id=\"target\"></p></div>", '.parent .card', 'target'));
        self::assertFalse((new SelectorMatcher())->containsPseudo('.hover\\:card'));
        self::assertTrue((new SelectorMatcher())->containsPseudo('.card:hover'));
    }

    public function testCssOwnershipPolicyPreservesSiblingAndArbitraryUtilityFallbacks(): void
    {
        $matcher = new SelectorMatcher();
        self::assertTrue($matcher->requiresCssFallback('.card + .badge'));
        self::assertTrue($matcher->requiresCssFallback('.card ~ .badge'));
        self::assertTrue($matcher->requiresCssFallback('.leading-\\[0\\.9\\]'));
        self::assertFalse($matcher->requiresCssFallback('.card > .badge'));
        self::assertFalse($matcher->requiresCssFallback('[data-label="a+b~c"]'));
    }
}
