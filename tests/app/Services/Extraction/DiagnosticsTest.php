<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Extraction;

use FreshRSS\Services\Extraction\ExtractionEngine;
use PHPUnit\Framework\TestCase;

final class DiagnosticsTest extends TestCase {
	public function testFallbackTraceExplainsEveryAttemptWithoutSourceContent(): void {
		$result = ExtractionEngine::extract('<p>  </p><h1>Private source text</h1>', ['fields' => ['title' => [
			['type' => 'css', 'selector' => '.missing'], ['type' => 'css', 'selector' => 'p'],
			['type' => 'xpath', 'selector' => '//*['], ['type' => 'css', 'selector' => 'h1'],
		]]]);
		self::assertTrue($result['success']);
		self::assertSame('Private source text', $result['values']['title']);
		self::assertCount(1, $result['errors']);
		self::assertSame([0, 1, 2, 3], array_column($result['diagnostics'], 'candidate'));
		self::assertSame(['empty', 'empty', 'error', 'matched'], array_column($result['diagnostics'], 'status'));
		self::assertSame([0, 1, 0, 1], array_column($result['diagnostics'], 'matches'));
		self::assertSame('no_match', $result['diagnostics'][0]['reason']);
		self::assertSame('empty_after_processing', $result['diagnostics'][1]['reason']);
		self::assertStringNotContainsString('Private source text', json_encode($result['diagnostics'], JSON_THROW_ON_ERROR));
	}

	public function testUnrecoveredErrorAndMissingRequiredFieldFailTheResult(): void {
		$result = ExtractionEngine::extract('<h1>Title</h1>', ['fields' => [
			'title' => [['type' => 'css', 'selector' => 'h1']],
			'content' => [['type' => 'xpath', 'selector' => '//*[']],
		]]);
		self::assertFalse($result['success']);
		self::assertSame('Title', $result['values']['title']);
		$result = ExtractionEngine::extract('<h1>Title</h1>', ['fields' => [
			'content' => [['type' => 'css', 'selector' => 'article']],
		], 'required' => ['content']]);
		self::assertFalse($result['success']);
		self::assertSame('no_match', $result['diagnostics'][0]['reason']);
	}

	public function testDiagnosticsAreBoundedByRecipeLimits(): void {
		$fields = [];
		for ($index = 0; $index < ExtractionEngine::MAX_FIELDS; $index++) {
			$fields['field' . $index] = array_fill(0, ExtractionEngine::MAX_CANDIDATES, ['type' => 'css', 'selector' => '.missing']);
		}
		$result = ExtractionEngine::extract('<p>Content</p>', ['fields' => $fields]);
		self::assertTrue($result['success']);
		self::assertCount(ExtractionEngine::MAX_FIELDS * ExtractionEngine::MAX_CANDIDATES, $result['diagnostics']);
		self::assertSame([], $result['values']);
	}

	public function testValidationErrorsAreBoundedEvenWithLongUnknownKeys(): void {
		$recipe = ['fields' => ['title' => [['type' => 'css', 'selector' => 'h1']]]];
		for ($index = 0; $index < ExtractionEngine::MAX_ERRORS + 10; $index++) {
			$recipe[$index . str_repeat('long_key', 100)] = null;
		}
		$result = ExtractionEngine::extract('<h1>Content</h1>', $recipe);
		self::assertFalse($result['success']);
		self::assertCount(ExtractionEngine::MAX_ERRORS, $result['errors']);
		self::assertSame('Further errors omitted', $result['errors'][ExtractionEngine::MAX_ERRORS - 1]);
		foreach ($result['errors'] as $error) {
			self::assertLessThanOrEqual(ExtractionEngine::MAX_ERROR_BYTES, strlen($error));
		}
	}
}
