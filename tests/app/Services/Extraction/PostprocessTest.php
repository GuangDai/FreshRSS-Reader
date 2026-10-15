<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Extraction;

use FreshRSS\Services\Extraction\ExtractionEngine;
use PHPUnit\Framework\TestCase;

final class PostprocessTest extends TestCase {
	/** @param list<array<string,mixed>> $operations
	 * @return array<string,mixed>
	 */
	private static function recipe(array $operations): array {
		return ['format' => 'json', 'fields' => ['value' => [['type' => 'json', 'selector' => 'value', 'postprocess' => $operations]]]];
	}

	public function testWhitespaceNormalisationHandlesUnicodeWithoutTreatingTextAsHtml(): void {
		$result = ExtractionEngine::extract('{"value":"  中文\u00a0\n & <b> text  "}', self::recipe([
			['type' => 'normalize_whitespace'], ['type' => 'trim'],
		]));
		self::assertSame('中文 & <b> text', $result['values']['value']);
		self::assertSame('text', $result['formats']['value']);
	}

	public function testRelativeUrlsResolveLocallyAndRejectUnsafeSchemes(): void {
		$recipe = self::recipe([['type' => 'absolute_url']]);
		$result = ExtractionEngine::extract('{"value":"../story?a=1&b=2"}', $recipe, 'https://example.org/news/index.html');
		self::assertSame('https://example.org/story?a=1&b=2', $result['values']['value']);
		foreach (['javascript:alert(1)', 'https://example.org/a b', 'https://example.org/\\bad'] as $value) {
			$result = ExtractionEngine::extract(json_encode(['value' => $value], JSON_THROW_ON_ERROR), $recipe);
			self::assertSame([], $result['values']);
			self::assertNotEmpty($result['errors']);
		}
		self::assertSame([], ExtractionEngine::extract('{"value":"/relative"}', $recipe)['values']);
	}

	public function testDateFormatIsDeterministicAndRejectsCalendarOverflow(): void {
		$recipe = self::recipe([['type' => 'date', 'input_format' => 'Y-m-d', 'timezone' => 'Asia/Shanghai']]);
		$result = ExtractionEngine::extract('{"value":"2024-02-29"}', $recipe);
		self::assertSame('2024-02-29T00:00:00+08:00', $result['values']['value']);
		$result = ExtractionEngine::extract('{"value":"2023-02-29"}', $recipe);
		self::assertSame([], $result['values']);
		self::assertSame(['value: Date does not match input_format'], $result['errors']);
		$result = ExtractionEngine::extract('{"value":"2024-03-01 trailing"}', $recipe);
		self::assertSame([], $result['values']);
	}

	public function testPostprocessingCannotBypassOutputBudget(): void {
		$recipe = self::recipe([['type' => 'absolute_url']]) + ['max_output_bytes' => 8];
		$result = ExtractionEngine::extract('{"value":"/x"}', $recipe, 'https://example.org/');
		self::assertSame([], $result['values']);
		self::assertSame(['value: Transformed value exceeds max_output_bytes'], $result['errors']);
	}

	public function testInvalidOperationsAndHtmlTransformsAreRejected(): void {
		foreach ([['type' => 'unknown'], ['type' => 'trim', 'typo' => true], ['type' => 'date'],
			['type' => 'date', 'input_format' => 'Y-m-d', 'timezone' => 'NoSuch/Zone'],
			['type' => 'date', 'input_format' => 'Y-m-d', 'output_format' => null]] as $operation) {
			self::assertNotEmpty(ExtractionEngine::validate(self::recipe([$operation])));
		}
		self::assertNotEmpty(ExtractionEngine::validate(['fields' => ['content' => [[
			'type' => 'css', 'selector' => 'pre', 'mode' => 'html', 'postprocess' => [['type' => 'normalize_whitespace']],
		]]]]));
		self::assertNotEmpty(ExtractionEngine::validate(self::recipe(array_fill(0, 9, ['type' => 'trim']))));
	}
}
