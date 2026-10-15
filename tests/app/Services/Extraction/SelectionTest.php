<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Extraction;

use FreshRSS\Services\Extraction\ExtractionEngine;
use FreshRSS_Context;
use PHPUnit\Framework\TestCase;

final class SelectionTest extends TestCase {
	#[\Override]
	public static function setUpBeforeClass(): void {
		FreshRSS_Context::initSystem();
	}

	public function testSpecificDomIndexAndOutOfRangeFallback(): void {
		$result = ExtractionEngine::extract('<p>First</p><p>Second</p>', ['fields' => [
			'title' => [['type' => 'css', 'selector' => 'p', 'index' => 1]],
			'fallback' => [['type' => 'css', 'selector' => 'p', 'index' => 2], ['type' => 'css', 'selector' => 'p']],
		]]);
		self::assertTrue($result['success']);
		self::assertSame(['title' => 'Second', 'fallback' => 'First'], $result['values']);
		self::assertSame([1, 0, 2], array_column($result['diagnostics'], 'matches'));
	}

	public function testSanitisedEmptyFirstMatchDoesNotHideLaterMatches(): void {
		$result = ExtractionEngine::extract('<script>private()</script><p>Article</p>', ['fields' => [
			'content' => [['type' => 'css', 'selector' => 'script, p', 'mode' => 'html']],
		]]);
		self::assertSame('<p>Article</p>', $result['values']['content']);
		self::assertSame('matched', $result['diagnostics'][0]['status']);
		self::assertSame(2, $result['diagnostics'][0]['matches']);
	}

	public function testUniqueMultiNodeValuesPreserveDocumentOrder(): void {
		$result = ExtractionEngine::extract('<b>A</b><b>A</b><b>B</b><b>A</b>', ['fields' => [
			'title' => [['type' => 'css', 'selector' => 'b', 'all' => true, 'unique' => true, 'separator' => ',']],
		]]);
		self::assertSame('A,B', $result['values']['title']);
		self::assertSame(4, $result['diagnostics'][0]['matches']);
	}

	public function testSeparatorCountsTowardBudgetBeforeJoining(): void {
		$result = ExtractionEngine::extract('<b>A</b><b>B</b>', ['max_output_bytes' => 3, 'fields' => [
			'title' => [['type' => 'css', 'selector' => 'b', 'all' => true, 'separator' => '---']],
		]]);
		self::assertFalse($result['success']);
		self::assertSame([], $result['values']);
		self::assertSame(['title: Value exceeds max_output_bytes'], $result['errors']);
	}

	public function testSelectionOptionsAreStrictlyValidated(): void {
		foreach ([['index' => -1], ['index' => '1'], ['index' => 1, 'all' => true], ['index' => null],
			['unique' => true], ['unique' => 1, 'all' => true], ['unique' => null]] as $options) {
			self::assertNotEmpty(ExtractionEngine::validate(['fields' => [
				'title' => [['type' => 'css', 'selector' => 'p'] + $options],
			]]));
		}
		self::assertNotEmpty(ExtractionEngine::validate(['format' => 'json', 'fields' => [
			'title' => [['type' => 'json', 'selector' => 'title', 'index' => 0]],
		]]));
	}

	public function testInvalidCleanupCannotBeSilentlyIgnoredByAttributeCandidates(): void {
		$result = ExtractionEngine::extract('<p title="Title">Body</p>', ['fields' => [
			'title' => [['type' => 'css', 'selector' => 'p', 'mode' => 'attribute', 'attribute' => 'title']],
		], 'remove' => ['div???']]);
		self::assertFalse($result['success']);
		self::assertSame([], $result['values']);
		self::assertStringStartsWith('remove: ', $result['errors'][0]);
		self::assertNotEmpty(ExtractionEngine::validate(['format' => 'json', 'remove' => ['aside'],
			'fields' => ['title' => [['type' => 'json', 'selector' => 'title']]],
		]));
	}

	public function testJsonLdUniqueValuesAndGraphLimit(): void {
		$source = '<script type="application/ld+json">{"@graph":[{"name":"A"},{"name":"A"},{"name":"B"}]}</script>';
		$recipe = ['fields' => ['author' => [['type' => 'jsonld', 'selector' => 'name', 'all' => true, 'unique' => true]]]];
		$result = ExtractionEngine::extract($source, $recipe);
		self::assertSame("A\nB", $result['values']['author']);
		self::assertFalse(ExtractionEngine::extract($source, $recipe + ['max_matches' => 2])['success']);
	}
}
