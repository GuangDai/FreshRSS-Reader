<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Refresh;

use FreshRSS\Services\Refresh\SchedulerConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SchedulerConfigurationTest extends TestCase {
	public function testBackoffSaturatesAndHasNoExponentialOverflow(): void {
		$config = SchedulerConfiguration::configuration(['retry_jitter_percent' => 0]);
		self::assertSame(60, SchedulerConfiguration::retryDelay(1, $config));
		self::assertSame(120, SchedulerConfiguration::retryDelay(2, $config));
		self::assertSame(21600, SchedulerConfiguration::retryDelay(PHP_INT_MAX, $config));
	}

	public function testUnknownConfigurationDoesNotSilentlyFallBack(): void {
		$this->expectException(InvalidArgumentException::class);
		SchedulerConfiguration::configuration(['worker' => 2]);
	}

	public function testInvalidLeaseCannotExpireBeforeWorkerTermination(): void {
		$this->expectException(InvalidArgumentException::class);
		SchedulerConfiguration::configuration(['lease_seconds' => 180]);
	}

	public function testNullIsNotSilentlyTreatedAsADefault(): void {
		$this->expectException(InvalidArgumentException::class);
		SchedulerConfiguration::configuration(['enabled' => null]);
	}

	public function testKernelAlarmDurationCannotWrapAndDisableTheDeadline(): void {
		$this->expectException(InvalidArgumentException::class);
		SchedulerConfiguration::configuration(['job_timeout' => 4294967295, 'kill_grace' => 1, 'lease_seconds' => 4294967300]);
	}

	public function testSqliteBusyTimeoutDoesNotOverflowIntoNoWait(): void {
		$this->expectException(InvalidArgumentException::class);
		SchedulerConfiguration::configuration(['busy_timeout_ms' => 2147483648]);
	}

	public function testRetentionMultiplicationAndLeaseAdditionCannotOverflow(): void {
		$this->expectException(InvalidArgumentException::class);
		SchedulerConfiguration::configuration(['history_days' => PHP_INT_MAX]);
	}

	public function testZeroDisablesHistoryAndRunDeadlineOnly(): void {
		$config = SchedulerConfiguration::configuration(['run_seconds' => 0, 'history_limit' => 0]);
		self::assertSame(0, $config['history_limit']);
		$this->expectException(InvalidArgumentException::class);
		SchedulerConfiguration::configuration(['job_timeout' => 0]);
	}

	public function testStatusPageLookaheadCannotOverflowTheSqlLimit(): void {
		$this->expectException(InvalidArgumentException::class);
		SchedulerConfiguration::configuration(['status_history_limit' => PHP_INT_MAX]);
	}
}
