<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Reader;

use FreshRSS\Exceptions\Reader\ApiException;
use FreshRSS\Services\Reader\BackupPolicyService;
use FreshRSS_Auth;
use FreshRSS_Context;
use FreshRSS_SystemConfiguration;
use FreshRSS_UserConfiguration;
use Minz_Configuration;
use Minz_User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class BackupPolicyServiceTest extends TestCase {
	private string $directory;
	private mixed $previousConfigurations;
	private mixed $previousSystemConfiguration;
	private mixed $previousLogin;
	private mixed $previousSession;
	private ?FreshRSS_UserConfiguration $previousUser;
	private FreshRSS_SystemConfiguration $system;
	private FreshRSS_UserConfiguration $user;

	#[\Override]
	protected function setUp(): void {
		$this->previousConfigurations = (new ReflectionProperty(Minz_Configuration::class, 'config_list'))->getValue();
		$this->previousSystemConfiguration = (new ReflectionProperty(FreshRSS_Context::class, 'system_conf'))->getValue();
		$this->previousLogin = (new ReflectionProperty(FreshRSS_Auth::class, 'login_ok'))->getValue();
		$this->previousSession = $_SESSION ?? null;
		$this->previousUser = FreshRSS_Context::hasUserConf() ? FreshRSS_Context::userConf() : null;
		$this->directory = sys_get_temp_dir() . '/freshrss-backup-policy-' . bin2hex(random_bytes(8));
		mkdir($this->directory);
		file_put_contents($this->directory . '/system.php', '<?php return ' . var_export([
			'salt' => 'private-system-secret', 'default_user' => 'owner', 'scheduler' => ['enabled' => true],
			'auto_sqlite_export' => ['enabled' => false, 'retention' => 7, 'extension_metadata' => 'preserved'],
		], true) . ';');
		$this->system = $this->systemConfiguration();
		(new ReflectionProperty(FreshRSS_Context::class, 'system_conf'))->setValue(null, $this->system);
		file_put_contents($this->directory . '/user.php', '<?php return ["is_admin" => true];');
		$this->user = FreshRSS_UserConfiguration::init($this->directory . '/user.php', FRESHRSS_PATH . '/config-user.default.php');
		FreshRSS_Context::setUserConf($this->user);
		$_SESSION = [Minz_User::CURRENT_USER => 'manager'];
		(new ReflectionProperty(FreshRSS_Auth::class, 'login_ok'))->setValue(null, true);
	}

	#[\Override]
	protected function tearDown(): void {
		FreshRSS_Context::setUserConf($this->previousUser);
		(new ReflectionProperty(Minz_Configuration::class, 'config_list'))->setValue(null, $this->previousConfigurations);
		(new ReflectionProperty(FreshRSS_Context::class, 'system_conf'))->setValue(null, $this->previousSystemConfiguration);
		(new ReflectionProperty(FreshRSS_Auth::class, 'login_ok'))->setValue(null, $this->previousLogin);
		if ($this->previousSession === null) {
			unset($_SESSION);
		} else {
			$_SESSION = $this->previousSession;
		}
		recursive_unlink($this->directory);
	}

	private function systemConfiguration(): FreshRSS_SystemConfiguration {
		return FreshRSS_SystemConfiguration::init($this->directory . '/system.php', FRESHRSS_PATH . '/config.default.php');
	}

	public function testDescriptionReturnsOnlyEffectiveBackupPolicyAndItsSchema(): void {
		$data = BackupPolicyService::describe($this->system);
		self::assertSame(['enabled' => false, 'retention' => 7], $data['configuration']);
		self::assertTrue($data['atomic']);
		self::assertSame(['enabled', 'retention'], array_keys($data['schema']));
		self::assertSame(1, $data['schema']['retention']['min']);
		self::assertStringNotContainsString('private-system-secret', json_encode($data, JSON_THROW_ON_ERROR));
		self::assertStringNotContainsString('extension_metadata', json_encode($data, JSON_THROW_ON_ERROR));
	}

	public function testPartialWritesMergeLatestPolicyWithoutChangingSchedulerOrOtherSettings(): void {
		$stale = $this->systemConfiguration();
		BackupPolicyService::save($this->system, ['enabled' => true]);
		$result = BackupPolicyService::save($stale, ['retention' => 12]);
		self::assertSame(['enabled' => true, 'retention' => 12], $result['configuration']);
		$data = $this->systemConfiguration()->toArray();
		self::assertSame(['enabled' => true], $data['scheduler']);
		self::assertSame('private-system-secret', $data['salt']);
		self::assertIsArray($data['auto_sqlite_export']);
		self::assertSame('preserved', $data['auto_sqlite_export']['extension_metadata']);
		self::assertSame(['enabled' => false, 'retention' => 12],
			BackupPolicyService::save($stale, ['enabled' => false])['configuration']);
	}

	public function testLegacyZeroRetentionIsShownAsOneAsTheCliActuallyUses(): void {
		self::assertTrue($this->system->savePatch(['auto_sqlite_export' => ['enabled' => true, 'retention' => 0]]));
		self::assertSame(['enabled' => true, 'retention' => 1], BackupPolicyService::describe($this->system)['configuration']);
	}

	/** @return iterable<array{array<string,mixed>,string}> */
	public static function invalidChanges(): iterable {
		yield [[], 'empty_update'];
		yield [['enabled' => 1], 'invalid_field'];
		yield [['enabled' => 'false'], 'invalid_field'];
		yield [['retention' => '7'], 'invalid_field'];
		yield [['retention' => 7.0], 'invalid_field'];
		yield [['retention' => false], 'invalid_field'];
		yield [['retention' => 0], 'field_out_of_range'];
		yield [['retention' => -1], 'field_out_of_range'];
		yield [['cron' => '0 * * * *'], 'invalid_field'];
		yield [['salt' => 'replacement'], 'invalid_field'];
		yield [['scheduler' => ['enabled' => false]], 'invalid_field'];
	}

	/** @param array<string,mixed> $values */
	#[DataProvider('invalidChanges')]
	public function testInvalidUpdatesAreAtomicAndDoNotTouchFiles(array $values, string $error): void {
		$before = file_get_contents($this->directory . '/system.php');
		try {
			BackupPolicyService::save($this->system, $values);
			self::fail('Invalid backup policy must fail');
		} catch (ApiException $exception) {
			self::assertSame($error, $exception->getMessage());
		}
		self::assertSame($before, file_get_contents($this->directory . '/system.php'));
		self::assertSame(['enabled' => false, 'retention' => 7], BackupPolicyService::describe($this->system)['configuration']);
	}

	public function testNonAdministratorCannotReadOrWritePolicy(): void {
		self::assertTrue($this->user->savePatch(['is_admin' => false]));
		$before = file_get_contents($this->directory . '/system.php');
		foreach ([
			fn() => BackupPolicyService::describe($this->system),
			fn() => BackupPolicyService::save($this->system, ['enabled' => true]),
			] as $operation) {
			try {
				$operation();
				self::fail('Administrator access is required');
			} catch (ApiException $exception) {
				self::assertSame('administrator_required', $exception->getMessage());
				self::assertSame(403, $exception->status);
			}
		}
		self::assertSame($before, file_get_contents($this->directory . '/system.php'));
	}

	public function testLoggedOutAdministratorCannotSave(): void {
		(new ReflectionProperty(FreshRSS_Auth::class, 'login_ok'))->setValue(null, false);
		$this->expectExceptionMessage('administrator_required');
		BackupPolicyService::save($this->system, ['enabled' => true]);
	}

	public function testWriteFailureDoesNotReplaceLoadedPolicy(): void {
		(new ReflectionProperty(Minz_Configuration::class, 'config_filename'))->setValue($this->system, $this->directory . '/missing/config.php');
		$this->expectExceptionMessage('write_failed');
		try {
			BackupPolicyService::save($this->system, ['enabled' => true]);
		} finally {
			self::assertSame(['enabled' => false, 'retention' => 7], BackupPolicyService::describe($this->system)['configuration']);
		}
	}
}
