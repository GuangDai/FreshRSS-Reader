<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Subscriptions;

use FreshRSS\Exceptions\Reader\ApiException;
use FreshRSS\Services\Subscriptions\NameValidator;
use FreshRSS_DatabaseDAO;
use PHPUnit\Framework\TestCase;

final class NameValidatorTest extends TestCase {
	public function testNamesUseTheEncodedDatabaseByteLimit(): void {
		self::assertSame('A &amp; B', NameValidator::validate(' A & B '));
		try {
			NameValidator::validate(str_repeat('&', FreshRSS_DatabaseDAO::LENGTH_INDEX_UNICODE));
			self::fail('Encoded name should not be silently truncated');
		} catch (ApiException $exception) {
			self::assertSame('name_too_long', $exception->getMessage());
			self::assertSame(FreshRSS_DatabaseDAO::LENGTH_INDEX_UNICODE, $exception->details['maxBytes']);
		}
	}
}
