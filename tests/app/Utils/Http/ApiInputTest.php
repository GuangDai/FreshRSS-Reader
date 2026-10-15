<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Utils\Http;

use FreshRSS\Utils\Http\ApiInput;
use InvalidArgumentException;
use LengthException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class ApiInputTest extends TestCase {
	/** @return iterable<string,array{mixed,int,int}> */
	public static function sizes(): iterable {
		yield 'normal' => ['50', 1000, 50];
		yield 'default' => [null, 1000, 20];
		yield 'array input' => [['100'], 1000, 20];
		yield 'negative' => [-10, 1000, 1];
		yield 'zero' => [0, 1000, 1];
		yield 'above limit' => [9000000, 1000, 1000];
		yield 'integer overflow' => ['99999999999999999999999999999', 1000, 1000];
		yield 'exponent' => ['1e100', 1000, 1000];
		yield 'small configured limit' => [null, 5, 5];
		yield 'invalid configured limit' => [10, -1, 1];
	}

	#[DataProvider('sizes')]
	public function testBoundedPageSize(mixed $input, int $maximum, int $expected): void {
		self::assertSame($expected, ApiInput::pageSize($input, $maximum));
	}
	public function testFormBatchPreservesOrderAndDecodesNames(): void {
		self::assertSame(['1', 'two words', '+3'], ApiInput::formValues('x=no&i=1&%69=two+words&i=%2B3&last=x', 'i', 3));
		self::assertSame([], ApiInput::formValues('x=1&nothing&ii=2', 'i', 1));
		self::assertSame(['last'], ApiInput::formValues(str_repeat('no-equals&', 10000) . 'i=last', 'i', 1));
	}

	public function testFormBatchRejectsOverflowRatherThanReturningPartialMutation(): void {
		$this->expectException(LengthException::class);
		ApiInput::formValues('i=1&i=2', 'i', 1);
	}

	public function testBodyLimitAllowsExactlyTheMaximum(): void {
		$stream = fopen('php://memory', 'w+b');
		self::assertIsResource($stream);
		try {
			fwrite($stream, '1234');
			rewind($stream);
			self::assertSame('1234', ApiInput::readBody($stream, 4));
			rewind($stream);
			$this->expectException(LengthException::class);
			ApiInput::readBody($stream, 3);
		} finally {
			fclose($stream);
		}
	}

	public function testFeverIdsFilterInvalidAndOverflowValues(): void {
		self::assertSame(['1', '002', '9223372036854775807'],
			ApiInput::commaIds('1,002,bad,9223372036854775807,9223372036854775808', 5, 100));
	}

	public function testFeverBatchCountsInvalidIdsAgainstResourceLimit(): void {
		$this->expectException(LengthException::class);
		ApiInput::commaIds('bad,bad,bad', 2, 100);
	}

	public function testFeverBatchChecksBytesBeforeSplitting(): void {
		$this->expectException(LengthException::class);
		ApiInput::commaIds('123456', 2, 5);
	}

	public function testReaderBatchDeduplicatesButPreservesStringIds(): void {
		self::assertSame(['ids' => ['12', '13'], 'field' => 'read', 'value' => false],
			ApiInput::stateBatch(['ids' => ['12', '12', '13'], 'read' => false], 3));
	}

	/** @return iterable<array{array<string,mixed>}> */
	public static function invalidBatches(): iterable {
		yield [['ids' => [], 'read' => true]];
		yield [['ids' => ['1'], 'read' => 1]];
		yield [['ids' => ['1'], 'read' => true, 'starred' => false]];
		yield [['ids' => ['1']]];
		yield [['ids' => [1], 'read' => true]];
		yield [['ids' => ['01'], 'read' => true]];
		yield [['ids' => ['a' => '1'], 'read' => true]];
		yield [['ids' => ['9223372036854775808'], 'read' => true]];
	}

	/** @param array<string,mixed> $batch */
	#[DataProvider('invalidBatches')]
	public function testInvalidReaderBatch(array $batch): void {
		$this->expectException(InvalidArgumentException::class);
		ApiInput::stateBatch($batch, 10);
	}

	public function testReaderBatchCountsDuplicatesBeforeDeduplicating(): void {
		$this->expectException(LengthException::class);
		ApiInput::stateBatch(['ids' => ['1', '1'], 'read' => true], 1);
	}

	public function testSqlIdsNormaliseDecimalValuesAndDoNotUseBindings(): void {
		self::assertSame('1,2,9223372036854775807', ApiInput::sqlIdList(['001', 2, '9223372036854775807', '2']));
		self::assertSame('NULL', ApiInput::sqlIdList([]));
		self::assertSame('0', ApiInput::sqlIdList(['000']));
		self::assertSame(1999, substr_count(ApiInput::sqlIdList(range(1, 2000)), ','));
	}

	public function testSqlIdsRejectUntrustedFragments(): void {
		$this->expectException(InvalidArgumentException::class);
		ApiInput::sqlIdList(['1) OR 1=1 --']);
	}

	public function testContentLengthCannotOverflow(): void {
		ApiInput::checkRequestHeaders(['CONTENT_LENGTH' => '000100'], 100);
		$this->expectException(LengthException::class);
		ApiInput::checkRequestHeaders(['CONTENT_LENGTH' => '99999999999999999999999999'], 100);
	}

	public function testMultipartWithoutLengthIsRejected(): void {
		$this->expectException(UnexpectedValueException::class);
		ApiInput::checkRequestHeaders(['CONTENT_TYPE' => 'Multipart/Form-Data; boundary=x'], 100);
	}

}
