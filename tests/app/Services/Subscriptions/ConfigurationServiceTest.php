<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Subscriptions;

use FreshRSS\Exceptions\Reader\ApiException;
use FreshRSS\Services\Subscriptions\ConfigurationService as SubscriptionConfigurationService;
use FreshRSS_Feed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigurationServiceTest extends TestCase {
	/** @return iterable<array{array<string,mixed>,string}> */
	public static function invalidChanges(): iterable {
		yield [['ttl' => '60'], 'invalid_field'];
		yield [['ttl' => -1], 'field_out_of_range'];
		yield [['ttl' => FreshRSS_Feed::TTL_MAX + 1], 'field_out_of_range'];
		yield [['enabled' => 1], 'invalid_field'];
		yield [['priority' => 123], 'field_out_of_range'];
		yield [['categoryId' => 0], 'field_out_of_range'];
		yield [['url' => 'http://127.0.0.1/'], 'invalid_field'];
		yield [['name' => ' '], 'invalid_name'];
		yield [[], 'empty_update'];
	}

	/** @param array<string,mixed> $changes */
	#[DataProvider('invalidChanges')]
	public function testPatchTypesAndAllowListAreStrict(array $changes, string $error): void {
		$this->expectException(ApiException::class);
		$this->expectExceptionMessage($error);
		SubscriptionConfigurationService::validateItems([
			['type' => 'feed', 'id' => 1, 'expectedVersion' => str_repeat('a', 64), 'changes' => $changes],
		], 10);
	}

	public function testDuplicateTargetsCannotApplyTwiceInOneBatch(): void {
		$item = ['type' => 'feed', 'id' => 1, 'expectedVersion' => str_repeat('a', 64), 'changes' => ['enabled' => false]];
		$this->expectExceptionMessage('duplicate_id');
		SubscriptionConfigurationService::validateItems([$item, $item], 2);
	}

	public function testCategoryFieldsCannotBeUsedToChangeFeedConfiguration(): void {
		$this->expectExceptionMessage('invalid_field');
		SubscriptionConfigurationService::validateItems([
			['type' => 'category', 'id' => 2, 'expectedVersion' => str_repeat('a', 64), 'changes' => ['ttl' => 60]],
		], 10);
	}

	public function testVersionIsRequiredAndBatchLimitIsEnforcedBeforeWork(): void {
		try {
			SubscriptionConfigurationService::validateItems([['type' => 'feed', 'id' => 1, 'changes' => ['ttl' => 60]]], 10);
			self::fail('Missing version must fail');
		} catch (ApiException $exception) {
			self::assertSame('invalid_version', $exception->getMessage());
		}
		try {
			SubscriptionConfigurationService::validateItems([[], []], 1);
			self::fail('Oversized batch must fail');
		} catch (ApiException $exception) {
			self::assertSame(413, $exception->status);
		}
	}

	public function testSchemaUsesFeedModelBoundsAndPreservesBooleanFalse(): void {
		$schema = SubscriptionConfigurationService::fields();
		self::assertSame(FreshRSS_Feed::TTL_MAX, $schema['ttl']['max'] ?? null);
		self::assertSame('seconds', $schema['ttl']['unit'] ?? null);
		$items = SubscriptionConfigurationService::validateItems([
			['type' => 'feed', 'id' => 1, 'expectedVersion' => str_repeat('a', 64), 'changes' => ['ttl' => 0, 'enabled' => false]],
		], 1);
		self::assertSame(['ttl' => 0, 'enabled' => false], $items[0]['changes']);
	}
}
