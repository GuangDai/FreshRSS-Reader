<?php
declare(strict_types=1);

namespace FreshRSS\Services\Network;

use InvalidArgumentException;

final class BrowserNetworkConfiguration {
	// Browser timers take signed 32-bit milliseconds. Keep conversion and jitter below that boundary.
	private const MAX_TIMER_SECONDS = 2147483;

	/** @return array<string,array{default:int,min:int,max:int,unit:string,description:string}> */
	public static function schema(): array {
		$fields = [
			'request_timeout' => [30, 'Classic read/star/list timeout; 0 disables client timeout'],
			'slider_timeout' => [15, 'Settings panel timeout; 0 disables client timeout'],
			'unread_timeout' => [15, 'Unread count request timeout; 0 disables client timeout'],
			'unread_interval' => [120, 'Automatic unread polling period; 0 disables polling including desktop notifications'],
			'unread_backoff_max' => [900, 'Maximum error backoff, never below the base interval; 0 disables backoff'],
			'unread_jitter' => [10, 'Maximum random addition to each polling interval; 0 disables jitter'],
			'unread_resume_min_interval' => [15, 'Minimum spacing when visibility/network resumes; 0 disables extra spacing'],
		];
		$schema = [];
		foreach ($fields as $field => [$default, $description]) {
			$schema[$field] = ['default' => $default, 'min' => 0, 'max' => self::MAX_TIMER_SECONDS, 'unit' => 'seconds', 'description' => $description];
		}
		return $schema;
	}

	/** @param array<string,mixed> $overrides
	 * @return array<string,int>
	 */
	public static function configuration(array $overrides = []): array {
		$schema = self::schema();
		foreach ($overrides as $key => $_value) {
			if (!isset($schema[$key])) {
				throw new InvalidArgumentException('Unknown network option: ' . $key);
			}
		}
		$result = [];
		foreach ($schema as $key => $spec) {
			$value = array_key_exists($key, $overrides) ? $overrides[$key] : $spec['default'];
			if (!is_int($value) || $value < $spec['min'] || $value > $spec['max']) {
				throw new InvalidArgumentException('Invalid network option: ' . $key . ' (0..' . $spec['max'] . ' seconds)');
			}
			$result[$key] = $value;
		}
		if (max($result['unread_interval'], $result['unread_backoff_max']) + $result['unread_jitter'] > self::MAX_TIMER_SECONDS) {
			throw new InvalidArgumentException('Unread period plus jitter exceeds the browser timer limit');
		}
		return $result;
	}
}
