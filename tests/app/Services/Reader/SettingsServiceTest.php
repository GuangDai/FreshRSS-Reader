<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Reader;

use FreshRSS\Exceptions\Reader\ApiException;
use FreshRSS\Services\Reader\SettingsService;
use FreshRSS_Context;
use FreshRSS_Feed;
use FreshRSS_SystemConfiguration;
use FreshRSS_UserConfiguration;
use Minz_Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class SettingsServiceTest extends TestCase {
	private string $directory;
	private mixed $previousConfigurations;
	private mixed $previousSystemConfiguration;

	#[\Override]
	protected function setUp(): void {
		$this->previousConfigurations = (new ReflectionProperty(Minz_Configuration::class, 'config_list'))->getValue();
		$this->previousSystemConfiguration = (new ReflectionProperty(FreshRSS_Context::class, 'system_conf'))->getValue();
		$this->directory = sys_get_temp_dir() . '/freshrss-reader-settings-' . bin2hex(random_bytes(8));
		mkdir($this->directory);
		file_put_contents($this->directory . '/system.php', '<?php return [];');
		$system = FreshRSS_SystemConfiguration::init($this->directory . '/system.php', FRESHRSS_PATH . '/config.default.php');
		(new ReflectionProperty(FreshRSS_Context::class, 'system_conf'))->setValue(null, $system);
		file_put_contents($this->directory . '/config.php', '<?php return ' . var_export([
			'passwordHash' => 'private-account-secret',
			'extensions' => ['Fixture' => ['unknown' => 'preserved']],
			'mark_when' => ['article' => true, 'custom_extension_trigger' => true],
			'archiving' => ['keep_min' => 83, 'extension_policy' => 'preserved'],
		], true) . ';');
	}

	#[\Override]
	protected function tearDown(): void {
		(new ReflectionProperty(Minz_Configuration::class, 'config_list'))->setValue(null, $this->previousConfigurations);
		(new ReflectionProperty(FreshRSS_Context::class, 'system_conf'))->setValue(null, $this->previousSystemConfiguration);
		recursive_unlink($this->directory);
	}

	private function configuration(): FreshRSS_UserConfiguration {
		return FreshRSS_UserConfiguration::init($this->directory . '/config.php', FRESHRSS_PATH . '/config-user.default.php');
	}

	public function testDescriptionExposesOnlyEditableFieldsAndRealDefaults(): void {
		$description = SettingsService::describe($this->configuration());
		self::assertTrue($description['schema']['atomic']);
		self::assertSame(array_keys($description['schema']['fields']), array_keys($description['values']));
		self::assertSame('P3M', $description['values']['archiving.keep_period']);
		self::assertSame(83, $description['values']['archiving.keep_min']);
		self::assertFalse($description['values']['mark_when.max_n_unread']);
		self::assertArrayNotHasKey('passwordHash', $description['values']);
		self::assertArrayNotHasKey('extensions', $description['values']);
		self::assertArrayNotHasKey('keep_history_default', $description['schema']['fields']);
		self::assertSame(FreshRSS_Feed::TTL_MAX, $description['schema']['fields']['ttl_default']['max'] ?? null);
	}

	public function testPartialUpdatesPreserveConcurrentNestedValuesAndUnrelatedAccountConfiguration(): void {
		$first = $this->configuration();
		$second = $this->configuration();
		SettingsService::save($first, ['mark_when.site' => false, 'archiving.keep_max' => 150]);
		$result = SettingsService::save($second, ['mark_when.article' => false, 'archiving.keep_period' => 'P6M']);
		self::assertFalse($result['values']['mark_when.site']);
		self::assertFalse($result['values']['mark_when.article']);
		self::assertSame(150, $result['values']['archiving.keep_max']);
		self::assertSame('P6M', $result['values']['archiving.keep_period']);
		$data = $this->configuration()->toArray();
		self::assertIsArray($data['mark_when']);
		self::assertTrue($data['mark_when']['custom_extension_trigger']);
		self::assertIsArray($data['archiving']);
		self::assertSame('preserved', $data['archiving']['extension_policy']);
		self::assertSame('private-account-secret', $data['passwordHash']);
		self::assertSame(['Fixture' => ['unknown' => 'preserved']], $data['extensions']);
		self::assertSame(83, $result['values']['archiving.keep_min']);
	}

	/** @return iterable<array{array<string,mixed>,string}> */
	public static function invalidChanges(): iterable {
		yield [['is_admin' => true], 'invalid_field'];
		yield [['passwordHash' => 'replacement'], 'invalid_field'];
		yield [['archiving' => ['keep_min' => 0]], 'invalid_field'];
		yield [['mark_when.article' => 0], 'invalid_field'];
		yield [['posts_per_page' => '20'], 'invalid_field'];
		yield [['posts_per_page' => 20.0], 'invalid_field'];
		yield [['posts_per_page' => 0], 'field_out_of_range'];
		yield [['bad_notification_timeout' => 0], 'field_out_of_range'];
		yield [['ttl_default' => -1], 'field_out_of_range'];
		yield [['ttl_default' => FreshRSS_Feed::TTL_MAX + 1], 'field_out_of_range'];
		yield [['archiving.keep_max' => true], 'invalid_field'];
		yield [['archiving.keep_min' => -1], 'field_out_of_range'];
		yield [['archiving.keep_period' => true], 'invalid_field'];
		yield [['archiving.keep_period' => 'tomorrow'], 'invalid_field'];
		yield [['archiving.keep_period' => 'P-1D'], 'invalid_field'];
		yield [['archiving.keep_period' => 'PT-1H'], 'invalid_field'];
		yield [['archiving.keep_period' => 'P2H'], 'invalid_field'];
		yield [['archiving.keep_period' => 'PT1H\n'], 'invalid_field'];
		yield [['archiving.keep_period' => 'P'], 'invalid_field'];
		yield [['archiving.keep_period' => 'PT'], 'invalid_field'];
		yield [['archiving.keep_period' => 'P1DT'], 'invalid_field'];
		yield [['archiving.keep_period' => 'P1.5D'], 'invalid_field'];
		yield [['archiving.keep_period' => 'PT0.5S'], 'invalid_field'];
		yield [['archiving.keep_period' => 'P1D1Y'], 'invalid_field'];
		yield [['archiving.keep_period' => 'PT1M2H'], 'invalid_field'];
		yield [['archiving.keep_period' => 'PT1H1H'], 'invalid_field'];
		yield [['archiving.keep_period' => 'P' . PHP_INT_MAX . '0D'], 'invalid_field'];
		yield [['archiving.keep_period' => 'PT000' . PHP_INT_MAX . '0S'], 'invalid_field'];
		yield [['display_categories' => 'sometimes'], 'field_out_of_range'];
		yield [['filters.star' => ['foo' => 'bar']], 'invalid_field'];
		yield [['filters.read' => ['intitle:spam', false]], 'invalid_field'];
		yield [['send_referrer_allowlist' => 'example.com'], 'invalid_field'];
		yield [[], 'empty_update'];
	}

	/** @param array<string,mixed> $values */
	#[DataProvider('invalidChanges')]
	public function testInvalidPatchRejectsAllFieldsWithoutWriting(array $values, string $error): void {
		$config = $this->configuration();
		$original = file_get_contents($this->directory . '/config.php');
		try {
			SettingsService::save($config, $values === [] ? [] : ['hide_read_feeds' => false] + $values);
			self::fail('Invalid settings patch must fail');
		} catch (ApiException $exception) {
			self::assertSame($error, $exception->getMessage());
		}
		self::assertSame($original, file_get_contents($this->directory . '/config.php'));
		self::assertTrue($config->hide_read_feeds);
	}

	/** @return iterable<array{string}> */
	public static function validRetentionDurations(): iterable {
		yield ['P0D'];
		yield ['P1DT6H'];
		yield ['PT30M'];
		yield ['PT45S'];
		yield ['P1M14D'];
		yield ['P1Y2M3W4DT5H6M7S'];
		yield ['P001D'];
		yield ['P1Y0M0DT0H0M0S'];
	}

	#[DataProvider('validRetentionDurations')]
	public function testDurationSupportsAllExistingIntegerDateIntervalUnits(string $duration): void {
		SettingsService::save($this->configuration(), ['archiving.keep_period' => $duration]);
		self::assertSame($duration, SettingsService::describe($this->configuration())['values']['archiving.keep_period']);
	}

	#[DataProvider('validRetentionDurations')]
	public function testDescribingAndSavingOtherCleanupFieldsPreserveTheOriginalDuration(string $duration): void {
		$config = $this->configuration();
		self::assertTrue($config->savePatch(['archiving' => ['keep_period' => $duration, 'keep_max' => 200, 'keep_min' => 83]]));
		self::assertSame($duration, SettingsService::describe($config)['values']['archiving.keep_period']);
		SettingsService::save($config, ['archiving.keep_max' => 350]);
		$values = SettingsService::describe($this->configuration())['values'];
		self::assertSame($duration, $values['archiving.keep_period']);
		self::assertSame(350, $values['archiving.keep_max']);
		self::assertSame(83, $values['archiving.keep_min']);
	}

	public function testDisabledRetentionAndZeroValuesSurvivePersistence(): void {
		$config = $this->configuration();
		SettingsService::save($config, [
			'archiving.keep_period' => false,
			'archiving.keep_max' => false,
			'archiving.keep_min' => 0,
			'archiving.keep_favourites' => false,
			'mark_when.max_n_unread' => 0,
			'ttl_default' => 0,
			'html5_notif_timeout' => 0,
		]);
		$values = SettingsService::describe($this->configuration())['values'];
		self::assertFalse($values['archiving.keep_period']);
		self::assertFalse($values['archiving.keep_max']);
		self::assertSame(0, $values['archiving.keep_min']);
		self::assertFalse($values['archiving.keep_favourites']);
		self::assertSame(0, $values['mark_when.max_n_unread']);
		self::assertSame(0, $values['ttl_default']);
		self::assertSame(0, $values['html5_notif_timeout']);
	}

	public function testFilterActionChangesKeepOtherActionsAndTheirMetadata(): void {
		$config = $this->configuration();
		self::assertTrue($config->savePatch(['filters' => [
			['search' => 'intitle:shared', 'actions' => ['read', 'star', 'label'], 'extension_flag' => true],
			['search' => 'intitle:old', 'actions' => ['read']],
			['search' => 'intitle:star', 'actions' => ['star']],
		]]));
		$stale = $this->configuration();
		SettingsService::save($config, ['filters.read' => ['intitle:new', 'intitle:shared']]);
		$result = SettingsService::save($stale, ['filters.star' => []]);
		self::assertEqualsCanonicalizing(['intitle:new', 'intitle:shared'], $result['values']['filters.read']);
		self::assertSame([], $result['values']['filters.star']);
		$filters = $this->configuration()->toArray()['filters'];
		self::assertIsArray($filters);
		self::assertIsArray($filters[0]);
		self::assertSame('intitle:shared', $filters[0]['search']);
		self::assertEqualsCanonicalizing(['read', 'label'], $filters[0]['actions']);
		self::assertTrue($filters[0]['extension_flag']);
		self::assertCount(2, $filters);
	}

	public function testStringListsTrimDeduplicateAndAllowClearing(): void {
		$config = $this->configuration();
		$result = SettingsService::save($config, [
			'filters.read' => [' intitle:spam ', '', 'intitle:spam'],
			'send_referrer_allowlist' => [' example.com ', 'example.com', ''],
		]);
		self::assertSame(['intitle:spam'], $result['values']['filters.read']);
		self::assertSame(['example.com'], $result['values']['send_referrer_allowlist']);
		$result = SettingsService::save($config, ['send_referrer_allowlist' => []]);
		self::assertSame([], $result['values']['send_referrer_allowlist']);
	}

	public function testFiltersUseExistingSearchLengthLimitAndRejectTheWholePatch(): void {
		$config = $this->configuration();
		$original = file_get_contents($this->directory . '/config.php');
		try {
			SettingsService::save($config, [
				'filters.read' => [str_repeat('a', FreshRSS_Context::systemConf()->limits['max_search_length'] + 1)],
				'mark_when.article' => false,
			]);
			self::fail('Overlong filter must fail');
		} catch (ApiException $exception) {
			self::assertSame('invalid_filter', $exception->getMessage());
		}
		self::assertSame($original, file_get_contents($this->directory . '/config.php'));
	}

	public function testWriteFailureKeepsConfigurationMemoryAndFileUnchanged(): void {
		$config = $this->configuration();
		$original = $config->toArray();
		mkdir($this->directory . '/config.php.bak.php');
		try {
			SettingsService::save($config, ['mark_when.article' => false]);
			self::fail('Failed file replacement must be reported');
		} catch (ApiException $exception) {
			self::assertSame('write_failed', $exception->getMessage());
			self::assertSame(500, $exception->status);
			self::assertSame(['atomic' => true], $exception->details);
		}
		self::assertSame($original, $config->toArray());
		self::assertSame($original, $this->configuration()->toArray());
	}
}
