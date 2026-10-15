<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Utils\Html;

use DOMDocument;
use DOMElement;
use DOMXPath;
use FreshRSS\Utils\Html\CssSelector;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CssSelectorTest extends TestCase {
	/** @return iterable<string,array{string,string,list<string>}> */
	public static function matchingCases(): iterable {
		$html = '<section><h2 id="heading">H</h2><p id="first">A</p><p id="second" class="hot">B</p>'
			. '<p id="third" class="hot special" title=" x ">C</p><a id="link" rel="other&#9;next" href="/x">D</a></section>';
		yield 'type position precedes class condition' => [$html, 'p.hot:first-of-type', []];
		yield 'nth of type with preceding class' => [$html, 'p.hot:nth-of-type(2)', ['second']];
		yield 'nth child counts other types' => [$html, 'p:nth-child(2)', ['first']];
		yield 'last of type precedes other type' => [$html, 'p:last-of-type', ['third']];
		yield 'first child requires first sibling' => [$html, 'p:first-child', []];
		yield 'last child requires last sibling' => [$html, 'a:last-child', ['link']];
		yield 'quoted spaces preserved' => [$html, '[title=" x "]', ['third']];
		yield 'quoted spaces not trimmed' => [$html, '[title="x"]', []];
		yield 'attribute word normalises spaces' => [$html, '[rel~=next]', ['link']];
		yield 'child and adjacent selectors' => [$html, 'section > p + p', ['second', 'third']];
		yield 'general sibling' => [$html, '#first ~ p', ['second', 'third']];
		yield 'compound not' => [$html, 'p:not(.hot.special)', ['first', 'second']];
		yield 'not selector list' => [$html, 'p:not(#first, .special)', ['second']];
		yield 'relative has child' => [$html, 'section:has(> p.hot) > a', ['link']];
		yield 'relative has adjacent' => [$html, 'p:has(+ p.special)', ['second']];
		yield 'selector list document order' => [$html, '#third, #first', ['first', 'third']];
		yield 'empty substring does not match missing attributes' => [$html, '[title*=""]', []];
		yield 'exact empty value needs attribute' => ['<p id="a" title=""></p><p id="b"></p>', '[title=""]', ['a']];
		yield 'attribute hyphen operator' => ['<p id="a" lang="en-US"></p><p id="b" lang="eng"></p>', '[lang|=en]', ['a']];
		yield 'apostrophe in attribute literal' => ['<p id="a" title="O&#39;Brien"></p>', '[title="O\'Brien"]', ['a']];
		yield 'contains descendant text' => ['<p id="a">A <b>B</b></p>', 'p:contains("A B")', ['a']];
	}

	/** @param list<string> $expected */
	#[DataProvider('matchingCases')]
	public function testSupportedSelectorsHavePreciseSemantics(string $html, string $selector, array $expected): void {
		$doc = new DOMDocument();
		$doc->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		$nodes = (new DOMXPath($doc))->query(CssSelector::toXPath($selector));
		self::assertNotFalse($nodes);
		$ids = [];
		foreach ($nodes as $node) {
			self::assertInstanceOf(DOMElement::class, $node);
			$ids[] = $node->getAttribute('id');
		}
		self::assertSame($expected, $ids);
	}

	/** @return iterable<array{string}> */
	public static function invalidCases(): iterable {
		foreach (['', 'div???', 'div;script', 'div,,p', ',div', 'div,', 'div>', '>div', 'div++p', 'div#', 'div.',
			'div[foo', 'div[foo?=bar]', 'div[foo="x" i]', 'div[foo=]', 'div[foo=1]', 'div()', 'div:unknown',
			'div:first-child()', 'div:nth-child(odd)', 'div:nth-child(0)', 'div:nth-child(2n+1)', '*:nth-of-type(2)',
			'div:not()', 'div:not(section p)', 'div:has()', 'div:has(p:has(a))', 'div:contains(unquoted)',
			'div:has(p', 'div::before', 'div\\:escaped', 'div[title="a\\b"]', 'div[title="unclosed]', 'p:checked'] as $selector) {
			yield [$selector];
		}
	}

	#[DataProvider('invalidCases')]
	public function testInvalidOrUnsupportedSyntaxNeverGetsPartiallyConsumed(string $selector): void {
		$this->expectException(InvalidArgumentException::class);
		CssSelector::toXPath($selector);
	}
}
