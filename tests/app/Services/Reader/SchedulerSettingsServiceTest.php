<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Reader;

use FreshRSS\Exceptions\Reader\ApiException;
use FreshRSS\Infrastructure\Refresh\Queue;
use FreshRSS\Services\Reader\SchedulerSettingsService;
use FreshRSS\Services\Refresh\SchedulerConfiguration;
use FreshRSS_Auth;
use FreshRSS_Context;
use FreshRSS_SystemConfiguration;
use FreshRSS_UserConfiguration;
use Minz_Configuration;
use Minz_User;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class SchedulerSettingsServiceTest extends TestCase {
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
		$this->directory = sys_get_temp_dir() . '/freshrss-scheduler-settings-' . bin2hex(random_bytes(8));
		mkdir($this->directory);
		file_put_contents($this->directory . '/system.php', '<?php return ' . var_export([
			'salt' => 'private-system-secret', 'default_user' => 'owner', 'extensions' => ['fixture' => ['preserve' => true]],
			'scheduler' => ['workers' => 3],
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

	private function queue(): Queue {
		if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
			self::markTestSkipped('PDO SQLite required');
		}
		return new Queue($this->directory . '/queue.sqlite', $this->system->scheduler);
	}

	public function testDescriptionReturnsExistingContractAndNoSystemSecrets(): void {
		$description = SchedulerSettingsService::describe($this->system, $this->queue());
		self::assertSame(['configuration', 'schema', 'status'], array_keys($description));
		self::assertSame(SchedulerConfiguration::schema(), $description['schema']);
		self::assertSame(3, $description['configuration']['workers']);
		self::assertFalse($description['configuration']['enabled']);
		self::assertFalse($description['status']['paused']);
		self::assertSame(0, $description['status']['running']);
		self::assertSame([], $description['status']['history']);
		self::assertStringNotContainsString('private-system-secret', json_encode($description, JSON_THROW_ON_ERROR));
	}

	public function testPartialWritesMergeLatestSchedulerAndPreserveOtherSystemSettings(): void {
		$stale = $this->systemConfiguration();
		SchedulerSettingsService::save($this->system, ['enabled' => true, 'workers' => 6]);
		SchedulerSettingsService::save($stale, ['history_limit' => 0, 'retry_jitter_percent' => 0]);
		$data = $this->systemConfiguration()->toArray();
		self::assertIsArray($data['scheduler']);
		self::assertTrue($data['scheduler']['enabled']);
		self::assertSame(6, $data['scheduler']['workers']);
		self::assertSame(0, $data['scheduler']['history_limit']);
		self::assertSame(0, $data['scheduler']['retry_jitter_percent']);
		self::assertSame('private-system-secret', $data['salt']);
		self::assertSame(['fixture' => ['preserve' => true]], $data['extensions']);
	}

	public function testDescriptionKeepsRealHistoryPagination(): void {
		SchedulerSettingsService::save($this->system, ['status_history_limit' => 1]);
		$queue = $this->queue();
		for ($feedId = 1; $feedId <= 2; $feedId++) {
			$queue->enqueue('alice', $feedId, 'feed', 'example.org', 0);
			$job = $queue->claim(time());
			self::assertNotNull($job);
			self::assertTrue($queue->finish($job['id'], $job['owner'], 'ok', time()));
		}
		$first = SchedulerSettingsService::describe($this->system, $queue)['status'];
		self::assertCount(1, $first['history']);
		self::assertIsInt($first['historyNext']);
		$second = SchedulerSettingsService::describe($this->system, $queue, $first['historyNext'])['status'];
		self::assertCount(1, $second['history']);
		self::assertLessThan($first['history'][0]['id'], $second['history'][0]['id']);
		self::assertNull($second['historyNext']);
	}

	/** @return iterable<array{array<string,mixed>,string}> */
	public static function invalidChanges(): iterable {
		yield [[], 'empty_update'];
		yield [['salt' => 'replacement'], 'invalid_field'];
		yield [['paused' => true], 'invalid_field'];
		yield [['enabled' => 1], 'invalid_field'];
		yield [['workers' => '2'], 'invalid_field'];
		yield [['workers' => 2.0], 'invalid_field'];
		yield [['workers' => false], 'invalid_field'];
		yield [['workers' => 0], 'field_out_of_range'];
		yield [['history_limit' => -1], 'field_out_of_range'];
		yield [['worker_memory_mib' => 15], 'field_out_of_range'];
		yield [['busy_timeout_ms' => 2147483648], 'field_out_of_range'];
		yield [['retry_jitter_percent' => 101], 'invalid_scheduler_configuration'];
		yield [['retry_base' => 21601], 'invalid_scheduler_configuration'];
		yield [['lease_seconds' => 185], 'invalid_scheduler_configuration'];
	}

	/** @param array<string,mixed> $values */
	#[DataProvider('invalidChanges')]
	public function testInvalidUpdatesDoNotWriteAnySettings(array $values, string $error): void {
		$before = file_get_contents($this->directory . '/system.php');
		try {
			SchedulerSettingsService::save($this->system, $values);
			self::fail('Invalid scheduler configuration must fail');
		} catch (ApiException $exception) {
			self::assertSame($error, $exception->getMessage());
		}
		self::assertSame($before, file_get_contents($this->directory . '/system.php'));
		self::assertSame(['workers' => 3], $this->system->scheduler);
	}

	public function testCombinationValidationUsesLatestFileAndKeepsItOnFailure(): void {
		$stale = $this->systemConfiguration();
		SchedulerSettingsService::save($this->system, ['job_timeout' => 230, 'lease_seconds' => 300]);
		$before = file_get_contents($this->directory . '/system.php');
		try {
			SchedulerSettingsService::save($stale, ['kill_grace' => 80, 'workers' => 5]);
			self::fail('The changed lease constraint must be validated against current settings');
		} catch (ApiException $exception) {
			self::assertSame('invalid_scheduler_configuration', $exception->getMessage());
			self::assertSame('lease_must_exceed_timeout_and_grace', $exception->details['reason']);
			self::assertSame(['lease_seconds', 'job_timeout', 'kill_grace'], $exception->details['fields']);
		}
		self::assertSame($before, file_get_contents($this->directory . '/system.php'));
		self::assertSame(3, $this->systemConfiguration()->scheduler['workers']);
	}

	public function testNonAdministratorCannotReadWriteOrPauseScheduler(): void {
		$queue = $this->queue();
		self::assertTrue($this->user->savePatch(['is_admin' => false]));
		$before = file_get_contents($this->directory . '/system.php');
		foreach ([
			fn() => SchedulerSettingsService::describe($this->system, $queue),
			fn() => SchedulerSettingsService::save($this->system, ['enabled' => true]),
			static fn() => SchedulerSettingsService::setPaused($queue, true),
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
		self::assertFalse($queue->paused());
	}

	public function testLoggedOutAdministratorCannotWriteSettings(): void {
		(new ReflectionProperty(FreshRSS_Auth::class, 'login_ok'))->setValue(null, false);
		$this->expectExceptionMessage('administrator_required');
		SchedulerSettingsService::save($this->system, ['enabled' => true]);
	}

	public function testPauseTakesEffectAcrossConnectionsWithoutCancellingRunningWork(): void {
		$queue = $this->queue();
		$queue->enqueue('alice', 1, 'feed', 'first.example', 0);
		$queue->enqueue('bob', 2, 'feed', 'second.example', 0);
		$running = $queue->claim(time());
		self::assertNotNull($running);
		$other = $this->queue();
		SchedulerSettingsService::setPaused($other, true);
		self::assertNull($queue->claim(time()));
		self::assertNotNull($queue->owned($running['id'], $running['owner']));
		$status = $queue->status(time());
		self::assertTrue($status['paused']);
		self::assertSame(1, $status['running']);
		self::assertSame(1, $status['pending']);
		SchedulerSettingsService::setPaused($other, false);
		self::assertNotNull($queue->claim(time()));
	}

	public function testPauseRejectsCoercedBooleanWithoutChangingQueue(): void {
		$queue = $this->queue();
		try {
			SchedulerSettingsService::setPaused($queue, 1);
			self::fail('Pause requires a JSON boolean');
		} catch (ApiException $exception) {
			self::assertSame('invalid_field', $exception->getMessage());
			self::assertSame('paused', $exception->details['field']);
		}
		self::assertFalse($queue->paused());
	}

	public function testWriteFailurePreservesLoadedSettings(): void {
		(new ReflectionProperty(Minz_Configuration::class, 'config_filename'))->setValue($this->system, $this->directory . '/missing/config.php');
		$this->expectExceptionMessage('write_failed');
		try {
			SchedulerSettingsService::save($this->system, ['enabled' => true]);
		} finally {
			self::assertSame(['workers' => 3], $this->system->scheduler);
		}
	}
}
