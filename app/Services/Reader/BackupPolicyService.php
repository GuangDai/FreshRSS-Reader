<?php
declare(strict_types=1);

namespace FreshRSS\Services\Reader;

use FreshRSS\Exceptions\Reader\ApiException;
use FreshRSS_Auth;
use FreshRSS_SystemConfiguration;

/** Settings consumed by cli/export-sqlite-auto.php; changing them does not schedule or run exports.
 * @phpstan-type BackupPolicy array{configuration:array{enabled:bool,retention:int},
 *   schema:array{enabled:array{type:'boolean',default:false},retention:array{type:'integer',default:7,min:1,unit:'backups'}},atomic:true}
 */
final class BackupPolicyService {

	/** @return BackupPolicy
	 * @throws ApiException
	 */
	public static function describe(FreshRSS_SystemConfiguration $configuration): array {
		self::requireAdministrator();
		$policy = $configuration->auto_sqlite_export;
		return [
			'configuration' => ['enabled' => (bool)($policy['enabled'] ?? false), 'retention' => max(1, (int)($policy['retention'] ?? 7))],
			'schema' => [
				'enabled' => ['type' => 'boolean', 'default' => false],
				'retention' => ['type' => 'integer', 'default' => 7, 'min' => 1, 'unit' => 'backups'],
			],
			'atomic' => true,
		];
	}

	/** @param array<string,mixed> $values
	 * @return BackupPolicy
	 * @throws ApiException
	 */
	public static function save(FreshRSS_SystemConfiguration $configuration, array $values): array {
		self::requireAdministrator();
		if ($values === [] || array_is_list($values)) {
			throw new ApiException('empty_update');
		}
		foreach ($values as $field => $value) {
			if (($field !== 'enabled' && $field !== 'retention') ||
				($field === 'enabled' && !is_bool($value)) || ($field === 'retention' && !is_int($value))) {
				throw new ApiException('invalid_field', details: ['field' => $field]);
			}
			if ($field === 'retention' && is_int($value) && $value < 1) {
				throw new ApiException('field_out_of_range', details: ['field' => $field]);
			}
		}
		$saved = $configuration->mutate(static function (array $latest) use ($values): array {
			$policy = $latest['auto_sqlite_export'] ?? [];
			$latest['auto_sqlite_export'] = array_replace(is_array($policy) ? $policy : [], $values);
			return $latest;
		});
		if (!$saved) {
			throw new ApiException('write_failed', 500, ['atomic' => true]);
		}
		return self::describe($configuration);
	}

	/** @throws ApiException */
	private static function requireAdministrator(): void {
		if (!FreshRSS_Auth::hasAccess('admin')) {
			throw new ApiException('administrator_required', 403);
		}
	}
}
