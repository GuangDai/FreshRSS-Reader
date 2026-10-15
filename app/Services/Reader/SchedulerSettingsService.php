<?php
declare(strict_types=1);

namespace FreshRSS\Services\Reader;

use FreshRSS\Exceptions\Reader\ApiException;
use FreshRSS\Infrastructure\Refresh\Queue;
use FreshRSS\Services\Refresh\SchedulerConfiguration;
use FreshRSS_Auth;
use FreshRSS_SystemConfiguration;
use InvalidArgumentException;

/** Administrator settings for the existing durable scheduler; never starts a worker. */
final class SchedulerSettingsService {

	/**
	 * @return array{configuration:array<string,int|bool>,
	 *   schema:array<string,array{default:int|bool,min?:int,max?:int,unit:string,description:string}>,
	 *   status:array{paused:bool,pending:int,running:int,retrying:int,due:int,oldestDue:?int,
	 *     history:list<array<string,int|string>>,historyNext:?int}}
	 * @throws ApiException
	 */
	public static function describe(FreshRSS_SystemConfiguration $configuration, Queue $queue, ?int $historyBefore = null): array {
		self::requireAdministrator();
		return [
			'configuration' => SchedulerConfiguration::configuration($configuration->scheduler),
			'schema' => SchedulerConfiguration::schema(),
			'status' => $queue->status(time(), $historyBefore),
		];
	}

	/**
	 * Apply only changed scheduler fields to the latest system file while holding its write lock.
	 * Runtime controls and other system settings are not part of this configuration patch.
	 * @param array<string,mixed> $values
	 * @throws ApiException
	 */
	public static function save(FreshRSS_SystemConfiguration $configuration, array $values): void {
		self::requireAdministrator();
		$changes = self::validatePatch($values);
		$saved = $configuration->mutate(static function (array $latest) use ($changes): array {
			$current = $latest['scheduler'] ?? [];
			if (!is_array($current) || !is_array_keys_string($current)) {
				throw new ApiException('invalid_scheduler_configuration');
			}
			$latest['scheduler'] = self::configuration(array_replace($current, $changes));
			return $latest;
		});
		if (!$saved) {
			throw new ApiException('write_failed', 500, ['atomic' => true]);
		}
	}

	/** Pause admission without cancelling running jobs or deleting pending jobs.
	 * @throws ApiException
	 */
	public static function setPaused(Queue $queue, mixed $paused): void {
		self::requireAdministrator();
		if (!is_bool($paused)) {
			throw new ApiException('invalid_field', details: ['field' => 'paused']);
		}
		$queue->setPaused($paused);
	}

	/** @throws ApiException */
	private static function requireAdministrator(): void {
		if (!FreshRSS_Auth::hasAccess('admin')) {
			throw new ApiException('administrator_required', 403);
		}
	}

	/** @param array<string,mixed> $values
	 * @return array<string,int|bool>
	 * @throws ApiException
	 */
	private static function validatePatch(array $values): array {
		if ($values === [] || array_is_list($values)) {
			throw new ApiException('empty_update');
		}
		$schema = SchedulerConfiguration::schema();
		$result = [];
		foreach ($values as $field => $value) {
			if (!isset($schema[$field]) ||
				(is_bool($schema[$field]['default']) && !is_bool($value)) ||
				(is_int($schema[$field]['default']) && !is_int($value))) {
				throw new ApiException('invalid_field', details: ['field' => $field]);
			}
			$spec = $schema[$field];
			if (is_int($value) && ($value < ($spec['min'] ?? 0) || $value > ($spec['max'] ?? PHP_INT_MAX))) {
				throw new ApiException('field_out_of_range', details: ['field' => $field]);
			}
			$result[$field] = $value;
		}
		return $result;
	}

	/** @param array<string,mixed> $values
	 * @return array<string,int|bool>
	 * @throws ApiException
	 */
	private static function configuration(array $values): array {
		try {
			/** @throws InvalidArgumentException */
			return SchedulerConfiguration::configuration($values);
		} catch (InvalidArgumentException $exception) {
			$details = match ($exception->getMessage()) {
				'lease_seconds must exceed job_timeout + kill_grace' => [
					'fields' => ['lease_seconds', 'job_timeout', 'kill_grace'], 'reason' => 'lease_must_exceed_timeout_and_grace',
				],
				'job_timeout + kill_grace exceeds the PCNTL alarm range' => [
					'fields' => ['job_timeout', 'kill_grace'], 'reason' => 'timeout_and_grace_exceed_alarm_range',
				],
				'retry_max must be >= retry_base; retry_jitter_percent must be <= 100' => [
					'fields' => ['retry_max', 'retry_base', 'retry_jitter_percent'], 'reason' => 'invalid_retry_bounds',
				],
				default => [],
			};
			throw new ApiException('invalid_scheduler_configuration', details: $details, previous: $exception);
		}
	}
}
