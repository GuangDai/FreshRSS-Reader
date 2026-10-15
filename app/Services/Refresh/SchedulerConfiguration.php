<?php
declare(strict_types=1);

namespace FreshRSS\Services\Refresh;

use InvalidArgumentException;

/** Durable scheduler configuration, shared by CLI and administrator API. */
final class SchedulerConfiguration {
	/** @return array<string,array{default:int|bool,min?:int,max?:int,unit:string,description:string}> */
	public static function schema(): array {
		$schema = [
			'enabled' => ['default' => false, 'unit' => 'boolean', 'description' => 'Use queue from the standard actualize script'],
			'workers' => ['default' => 2, 'min' => 1, 'unit' => 'processes', 'description' => 'Global concurrent queue jobs'],
			'per_user' => ['default' => 1, 'min' => 1, 'unit' => 'processes', 'description' => 'Parallel source downloads per user; ingestion stays serial'],
			'per_host' => ['default' => 1, 'min' => 1, 'unit' => 'processes', 'description' => 'Concurrent feed jobs per original hostname'],
			'worker_memory_mib' => ['default' => 128, 'min' => 16, 'unit' => 'MiB', 'description' => 'PHP memory limit of each job process'],
			'job_timeout' => ['default' => 180, 'min' => 1, 'unit' => 'seconds', 'description' => 'Wall time before terminating a worker'],
			'kill_grace' => ['default' => 5, 'min' => 1, 'unit' => 'seconds', 'description' => 'Time between TERM and KILL'],
			'lease_seconds' => ['default' => 240, 'min' => 1, 'unit' => 'seconds', 'description' => 'Crash recovery; must exceed timeout plus grace'],
			'run_seconds' => ['default' => 300, 'min' => 0, 'unit' => 'seconds', 'description' => 'Stop claiming after this time; 0 drains due jobs'],
			'retry_base' => ['default' => 60, 'min' => 1, 'unit' => 'seconds', 'description' => 'First error delay; doubles to retry_max'],
			'retry_max' => ['default' => 21600, 'min' => 1, 'unit' => 'seconds', 'description' => 'Error delay cap; HTTP Retry-After may be later'],
			'retry_jitter_percent' => ['default' => 20, 'min' => 0, 'unit' => 'percent', 'description' => 'Random addition, capped by retry_max'],
			'maintenance_interval' => ['default' => 3600, 'min' => 1, 'unit' => 'seconds', 'description' => 'Per-user DB/OPML and shared cache maintenance'],
			'websub_poll_interval' => ['default' => 86400, 'min' => 1, 'unit' => 'seconds', 'description' => 'Fallback polling for active WebSub feeds'],
			'history_limit' => ['default' => 1000, 'min' => 0, 'unit' => 'events', 'description' => 'Maximum completed job records; 0 disables history'],
			'status_history_limit' => ['default' => 100, 'min' => 0, 'unit' => 'events', 'description' => 'History page size; 0 hides retained history'],
			'history_days' => ['default' => 7, 'min' => 0, 'unit' => 'days', 'description' => 'History age cap; 0 uses only history_limit'],
			'busy_timeout_ms' => ['default' => 5000, 'min' => 1, 'unit' => 'milliseconds', 'description' => 'SQLite writer lock wait'],
			'poll_ms' => ['default' => 200, 'min' => 1, 'unit' => 'milliseconds', 'description' => 'Supervisor process polling interval'],
			'plan_batch_size' => ['default' => 128, 'min' => 1, 'unit' => 'feeds', 'description' => 'Subscriptions per planning read page and queue transaction'],
		];
		// These ceilings come from the actual kernel/PHP/SQLite representations, not a feed-count policy.
		$alarmMaximum = PHP_INT_SIZE >= 8 ? 4294967295 : PHP_INT_MAX;
		foreach ($schema as $key => &$spec) {
			if ($spec['unit'] === 'seconds') {
				$spec['max'] = PHP_INT_MAX - time();
			} elseif ($spec['unit'] === 'days') {
				$spec['max'] = intdiv(PHP_INT_MAX, 86400);
			} elseif ($spec['unit'] === 'milliseconds') {
				$spec['max'] = $key === 'busy_timeout_ms' ? 2147483647 : intdiv(PHP_INT_MAX, 1000);
			}
		}
		unset($spec);
		$schema['job_timeout']['max'] = $alarmMaximum;
		$schema['kill_grace']['max'] = $alarmMaximum;
		// One extra row determines whether the status page has a successor.
		$schema['status_history_limit']['max'] = PHP_INT_MAX - 1;
		return $schema;
	}

	/** @param array<string,mixed> $overrides
	 * @return array<string,int|bool>
	 */
	public static function configuration(array $overrides = []): array {
		$schema = self::schema();
		foreach ($overrides as $key => $_value) {
			if (!isset($schema[$key])) {
				throw new InvalidArgumentException('Unknown scheduler option: ' . $key);
			}
		}
		$result = [];
		foreach ($schema as $key => $spec) {
			$value = array_key_exists($key, $overrides) ? $overrides[$key] : $spec['default'];
			if ((is_bool($spec['default']) && !is_bool($value)) ||
				(is_int($spec['default']) && (!is_int($value) || $value < ($spec['min'] ?? 0) || $value > ($spec['max'] ?? PHP_INT_MAX)))) {
				throw new InvalidArgumentException('Invalid scheduler option: ' . $key);
			}
			$result[$key] = $value;
		}
		if ((int)$result['job_timeout'] > ($schema['job_timeout']['max'] ?? PHP_INT_MAX) - (int)$result['kill_grace']) {
			throw new InvalidArgumentException('job_timeout + kill_grace exceeds the PCNTL alarm range');
		}
		if ($result['lease_seconds'] <= (int)$result['job_timeout'] + (int)$result['kill_grace']) {
			throw new InvalidArgumentException('lease_seconds must exceed job_timeout + kill_grace');
		}
		if ($result['retry_max'] < $result['retry_base'] || $result['retry_jitter_percent'] > 100) {
			throw new InvalidArgumentException('retry_max must be >= retry_base; retry_jitter_percent must be <= 100');
		}
		return $result;
	}

	/** @param array<string,int|bool> $config */
	public static function retryDelay(int $attempt, array $config): int {
		$delay = (int)$config['retry_base'];
		$maximum = (int)$config['retry_max'];
		// Saturate before multiplication; no overflowing exponent for long-lived failed feeds.
		for ($i = 1; $i < $attempt && $delay < $maximum; $i++) {
			$delay = $delay > intdiv($maximum, 2) ? $maximum : $delay * 2;
		}
		$jitter = (int)min($maximum - $delay, $delay * ((int)$config['retry_jitter_percent'] / 100));
		return $delay + ($jitter > 0 ? random_int(0, $jitter) : 0);
	}
}
