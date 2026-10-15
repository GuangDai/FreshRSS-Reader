<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Extraction;

use FreshRSS\Services\Extraction\ExtractionEngine;
use FreshRSS_Context;
use PHPUnit\Framework\TestCase;

final class ExtractionEngineTest extends TestCase {
	#[\Override]
	public static function setUpBeforeClass(): void {
		FreshRSS_Context::initSystem();
	}

	public function testCssFallbackAndUtf8(): void {
		$result = ExtractionEngine::extract('<h1>中文 &amp; café</h1>', [
			'fields' => ['title' => [
				['type' => 'css', 'selector' => '.missing'],
				['type' => 'css', 'selector' => 'h1'],
			]],
		]);
		self::assertSame(['title' => '中文 & café'], $result['values']);
		self::assertSame(['title' => 'text'], $result['formats']);
		self::assertSame([], $result['errors']);
	}

	public function testHtmlIsCleanedWithoutMutatingOtherFields(): void {
		$result = ExtractionEngine::extract('<article><h1>Title</h1><aside>Ad</aside><p onclick="evil()">Body'
			. '<a href="/next">next</a><script>alert(1)</script></p></article>', [
			'fields' => [
				'content' => [['type' => 'css', 'selector' => 'article', 'mode' => 'html']],
				'aside' => [['type' => 'xpath', 'selector' => '//aside/text()']],
			],
			'remove' => ['aside'],
		], 'https://example.org/news');
		self::assertSame([], $result['errors']);
		self::assertSame('Ad', $result['values']['aside']);
		self::assertStringNotContainsString('onclick', $result['values']['content']);
		self::assertStringNotContainsString('<script', $result['values']['content']);
		self::assertStringNotContainsString('<aside', $result['values']['content']);
		self::assertStringContainsString('https://example.org/next', $result['values']['content']);
	}

	public function testXpathAttributesAndAllMatches(): void {
		$result = ExtractionEngine::extract('<a href="/one">One</a><a href="/two">Two</a>', [
			'fields' => [
				'link' => [['type' => 'xpath', 'selector' => '//a/@href', 'all' => true, 'separator' => ',']],
				'title' => [['type' => 'css', 'selector' => 'a', 'mode' => 'attribute', 'attribute' => 'href']],
			],
		]);
		self::assertSame(['link' => '/one,/two', 'title' => '/one'], $result['values']);
	}

	public function testJsonDotNotation(): void {
		$result = ExtractionEngine::extract('{"items":[{"title":"A < B","zero":0}]}', [
			'format' => 'json', 'fields' => [
				'title' => [['type' => 'json', 'selector' => 'items[0].title']],
				'zero' => [['type' => 'json', 'selector' => 'items.0.zero']],
			],
		]);
		self::assertSame(['title' => 'A < B', 'zero' => '0'], $result['values']);
		self::assertSame([], $result['errors']);
	}

	public function testJsonLdGraphAndMalformedBlockFallback(): void {
		$result = ExtractionEngine::extract('<script type="application/ld+json">invalid</script>'
			. '<script type="application/ld+json">{"@graph":[{"headline":"News","author":{"name":"Writer"}}]}</script>', [
			'fields' => [
				'title' => [['type' => 'jsonld', 'selector' => 'headline']],
				'author' => [['type' => 'jsonld', 'selector' => 'author.name']],
			],
		]);
		self::assertSame(['title' => 'News', 'author' => 'Writer'], $result['values']);
	}

	public function testInvalidXpathHasDiagnosticsAndFallback(): void {
		$result = ExtractionEngine::extract('<h1>News</h1>', [
			'fields' => ['title' => [['type' => 'xpath', 'selector' => '//*[' ], ['type' => 'css', 'selector' => 'h1']]],
		]);
		self::assertSame('News', $result['values']['title']);
		self::assertNotEmpty($result['errors']);
	}

	public function testRequiredFieldAndOutputLimit(): void {
		$result = ExtractionEngine::extract('<p>12345</p>', [
			'fields' => ['content' => [['type' => 'css', 'selector' => 'p']]], 'required' => ['content'], 'max_output_bytes' => 4,
		]);
		self::assertSame([], $result['values']);
		self::assertCount(2, $result['errors']);
	}

	public function testSourceLimitAndInvalidEncoding(): void {
		$recipe = ['fields' => ['title' => [['type' => 'css', 'selector' => 'p']]], 'max_source_bytes' => 8];
		self::assertSame(['Source exceeds max_source_bytes'], ExtractionEngine::extract('<p>12345</p>', $recipe)['errors']);
		self::assertSame([], ExtractionEngine::extract("\xFF", $recipe)['values']);
		self::assertSame([], ExtractionEngine::extract("x\0", $recipe)['values']);
	}

	public function testMatchLimitPreventsPartialResults(): void {
		$result = ExtractionEngine::extract('<p>A</p><p>B</p>', [
			'fields' => ['title' => [['type' => 'css', 'selector' => 'p']]], 'max_matches' => 1,
		]);
		self::assertSame([], $result['values']);
		self::assertNotEmpty($result['errors']);
	}

	public function testInvalidJsonAndRecipe(): void {
		$recipe = ['format' => 'json', 'fields' => ['title' => [['type' => 'json', 'selector' => 'title']]]];
		self::assertSame([], ExtractionEngine::extract('{', $recipe)['values']);
		self::assertNotEmpty(ExtractionEngine::validate(['fields' => ['title' => 'bad']]));
		self::assertNotEmpty(ExtractionEngine::validate($recipe + ['typo' => 1]));
		self::assertNotEmpty(ExtractionEngine::validate($recipe + ['max_matches' => '5']));
	}

	public function testExternalEntityIsNotExpanded(): void {
		$result = ExtractionEngine::extract('<!DOCTYPE html [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><p>&xxe;</p>', [
			'fields' => ['content' => [['type' => 'css', 'selector' => 'p']]],
		]);
		self::assertStringNotContainsString('root:', $result['values']['content'] ?? '');
	}

	public function testLibraryXmlErrorModeIsRestored(): void {
		$previous = libxml_use_internal_errors(false);
		try {
			ExtractionEngine::extract('<p>ok', ['fields' => ['title' => [['type' => 'css', 'selector' => 'p']]]]);
			self::assertFalse(libxml_use_internal_errors());
		} finally {
			libxml_use_internal_errors($previous);
		}
	}

	public function testMalformedJsonLdHasDiagnostics(): void {
		$result = ExtractionEngine::extract('<script type="application/ld+json">{bad</script><h1>Fallback</h1>', [
			'fields' => ['title' => [['type' => 'jsonld', 'selector' => 'headline'], ['type' => 'css', 'selector' => 'h1']]],
		]);
		self::assertSame('Fallback', $result['values']['title']);
		self::assertSame(['title: No valid JSON-LD block'], $result['errors']);
	}

	public function testUnknownCssPseudoClassHasDiagnostics(): void {
		$result = ExtractionEngine::extract('<p>Fallback</p>', [
			'fields' => ['title' => [['type' => 'css', 'selector' => 'p:unknown'], ['type' => 'css', 'selector' => 'p']]],
		]);
		self::assertSame('Fallback', $result['values']['title']);
		self::assertNotEmpty($result['errors']);
	}

	public function testJsonLdLimitAppliesAcrossBlocks(): void {
		$result = ExtractionEngine::extract('<script type="application/ld+json">[{"title":"A"},{"title":"B"}]</script>'
			. '<script type="application/ld+json">{"title":"C"}</script>', [
			'fields' => ['title' => [['type' => 'jsonld', 'selector' => 'title', 'all' => true]]], 'max_matches' => 2,
		]);
		self::assertSame([], $result['values']);
		self::assertSame(['title: JSON-LD objects exceed max_matches'], $result['errors']);
	}

	public function testNestedCleanupAndRequiredContent(): void {
		$result = ExtractionEngine::extract('<article><aside><p>Ad</p></aside><section>Keep</section></article>', [
			'fields' => ['content' => [['type' => 'css', 'selector' => 'article']]], 'remove' => ['aside, aside p'], 'required' => ['content'],
		]);
		self::assertSame(['content' => 'Keep'], $result['values']);
		self::assertSame([], $result['errors']);
	}

	public function testHtmlSeparatorCannotInjectScript(): void {
		$result = ExtractionEngine::extract('<p>A</p><p>B</p>', [
			'fields' => ['content' => [['type' => 'css', 'selector' => 'p', 'mode' => 'html', 'all' => true,
				'separator' => '<script>alert(1)</script>']]],
		]);
		self::assertStringContainsString('<p>A</p>', $result['values']['content']);
		self::assertStringContainsString('<p>B</p>', $result['values']['content']);
		self::assertStringNotContainsString('<script', $result['values']['content']);
	}

	public function testSelectorsRejectNulAndInvalidEncoding(): void {
		foreach (["//p\0ignored", "//p\xFF"] as $selector) {
			$result = ExtractionEngine::extract('<p>Must not match</p>', [
				'fields' => ['title' => [['type' => 'xpath', 'selector' => $selector]]],
			]);
			self::assertSame([], $result['values']);
			self::assertNotEmpty($result['errors']);
		}
	}

	public function testScalarXpathAndExcessiveHtmlDepthAreRejected(): void {
		$result = ExtractionEngine::extract('<p>Title</p>', [
			'fields' => ['title' => [['type' => 'xpath', 'selector' => 'string(//p)']]],
		]);
		self::assertSame([], $result['values']);
		self::assertNotEmpty($result['errors']);
		$result = ExtractionEngine::extract(str_repeat('<div>', 300) . 'Tail', [
			'fields' => ['title' => [['type' => 'css', 'selector' => 'div']]],
		]);
		self::assertSame([], $result['values']);
		self::assertSame(['HTML exceeds parser limits or is fatally malformed'], $result['errors']);
	}

	public function testExplicitNullOptionsAreRejected(): void {
		$recipe = ['fields' => ['title' => [['type' => 'css', 'selector' => 'p']]]];
		foreach (['max_output_bytes', 'max_source_bytes', 'max_matches', 'remove', 'required', 'format'] as $key) {
			self::assertNotEmpty(ExtractionEngine::validate($recipe + [$key => null]));
		}
		foreach (['all', 'separator', 'mode', 'attribute'] as $key) {
			self::assertNotEmpty(ExtractionEngine::validate([
				'fields' => ['title' => [['type' => 'css', 'selector' => 'p', $key => null]]],
			]));
		}
	}

	public function testJsonConcatenationIsBoundedAndQuotedAmpersandsWork(): void {
		$recipe = ['format' => 'json', 'fields' => [
			'title' => [['type' => 'json', 'selector' => implode('&', array_fill(0, 256, 'a'))]],
		], 'max_output_bytes' => 32];
		$result = ExtractionEngine::extract(json_encode(['a' => str_repeat('x', 65536)], JSON_THROW_ON_ERROR), $recipe);
		self::assertSame([], $result['values']);
		self::assertSame(['title: Value exceeds max_output_bytes'], $result['errors']);
		$result = ExtractionEngine::extract('{"a":"World"}', ['format' => 'json', 'fields' => [
			'title' => [['type' => 'json', 'selector' => '\'Hello & \' & a']],
		]]);
		self::assertSame('Hello & World', $result['values']['title']);
	}

	public function testUnsupportedCssIsNotSilentlyBroadened(): void {
		foreach (['div???', 'div;script', 'div:nth-child(odd)', 'div:has(p:unknown)', 'div:has(p', 'div,,p'] as $selector) {
			$result = ExtractionEngine::extract('<div>Text</div>', [
				'fields' => ['title' => [['type' => 'css', 'selector' => $selector]]],
			]);
			self::assertSame([], $result['values'], $selector);
			self::assertNotEmpty($result['errors'], $selector);
		}
	}

	public function testSupportedCssPseudosAndQuotedUrls(): void {
		$result = ExtractionEngine::extract('<section><p class="ad">Ad</p><p>Keep</p></section>'
			. '<a href="https://example.org/?a=1;b=2">Link</a>', [
			'fields' => [
				'content' => [['type' => 'css', 'selector' => 'section:has(p) p:not(.ad)']],
				'title' => [['type' => 'css', 'selector' => 'a[href="https://example.org/?a=1;b=2"]']],
			],
		]);
		self::assertSame(['content' => 'Keep', 'title' => 'Link'], $result['values']);
		self::assertSame([], $result['errors']);
	}

	public function testPresetsAreValid(): void {
		foreach (ExtractionEngine::presets() as $recipe) {
			self::assertSame([], ExtractionEngine::validate($recipe));
		}
	}
}
