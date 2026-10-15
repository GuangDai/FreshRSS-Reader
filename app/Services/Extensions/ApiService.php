<?php
declare(strict_types=1);

namespace FreshRSS\Services\Extensions;

use FreshRSS\Exceptions\Reader\ApiException;
use FreshRSS\Infrastructure\Concurrency\UserWorkLock;
use Minz_Extension;
use Minz_Log;
use RuntimeException;

/** Reader boundary for the generic plugin settings service; single and batch writes share validation and concurrency rules.
 * @phpstan-import-type SettingsDescription from ConfigurationService
 * @phpstan-import-type SettingsChange from ConfigurationService
 */
final class ApiService {
	/** @param array<Minz_Extension> $extensions */
	public function __construct(private readonly array $extensions) {
	}

	/** @return SettingsDescription
	 * @throws ApiException
	 */
	public function describe(mixed $entrypoint): array {
		return ConfigurationService::describe($this->extension($entrypoint));
	}

	/** @return list<SettingsDescription> */
	public function catalog(): array {
		$result = [];
		foreach ($this->extensions as $extension) {
			if ($extension->getType() === 'user' && $extension->getConfigurationSchema() !== []) {
				$result[] = ConfigurationService::describe($extension);
			}
		}
		return $result;
	}

	/** @return list<SettingsChange>
	 * @throws ApiException
	 */
	public function prepare(mixed $items, int $maximum): array {
		if (!is_array($items) || !array_is_list($items) || $items === []) {
			throw new ApiException('invalid_items');
		}
		if (count($items) > $maximum) {
			throw new ApiException('batch_too_large', 413, ['maxBatchItems' => $maximum]);
		}
		$changes = [];
		$seen = [];
		foreach ($items as $item) {
			if (!is_array($item) || array_diff(array_keys($item), ['extension', 'configuration', 'expectedVersion']) !== []) {
				throw new ApiException('invalid_item');
			}
			$extension = $this->extension($item['extension'] ?? null);
			$id = $extension->getEntrypoint();
			if (isset($seen[$id])) {
				throw new ApiException('duplicate_extension');
			}
			$seen[$id] = true;
			$version = $item['expectedVersion'] ?? null;
			if (!is_string($version) || $version === '') {
				throw new ApiException('invalid_version');
			}
			$configuration = $item['configuration'] ?? null;
			if (!is_array($configuration) || ($configuration !== [] && array_is_list($configuration))) {
				throw new ApiException('invalid_configuration');
			}
			$changes[] = ['extension' => $extension, 'configuration' => $configuration, 'expectedVersion' => $version];
		}
		return $changes;
	}

	/** @return array{atomic:true,extensions:array<string,SettingsDescription>}
	 * @throws ApiException
	 */
	public function save(mixed $items, int $maximum): array {
		$changes = $this->prepare($items, $maximum);
		$lock = UserWorkLock::tryAcquire();
		if ($lock === null) {
			throw new ApiException('processing_busy', 503, ['retryable' => true, 'atomic' => true]);
		}
		try {
			$result = ConfigurationService::saveBatch($changes, $maximum);
		} catch (RuntimeException $exception) {
			Minz_Log::error('Extension configuration write failed: ' . get_class($exception));
			throw new ApiException('write_failed', 500, ['atomic' => true], $exception);
		} finally {
			$lock->release();
		}
		if ($result['conflicts'] !== []) {
			throw new ApiException('configuration_conflict', 409,
				['conflicts' => $result['conflicts'], 'fields' => $result['errors'], 'atomic' => true]);
		}
		if (!$result['saved']) {
			throw new ApiException('invalid_configuration', details: ['fields' => $result['errors'], 'atomic' => true]);
		}
		return ['atomic' => true, 'extensions' => $result['extensions']];
	}

	/** @throws ApiException */
	private function extension(mixed $entrypoint): Minz_Extension {
		if (!is_string($entrypoint) || $entrypoint === '') {
			throw new ApiException('invalid_extension');
		}
		foreach ($this->extensions as $extension) {
			if ($extension->getEntrypoint() !== $entrypoint) {
				continue;
			}
			if ($extension->getType() !== 'user') {
				throw new ApiException('user_extension_required', 403);
			}
			if ($extension->getConfigurationSchema() === []) {
				throw new ApiException('configuration_schema_unavailable', 409);
			}
			return $extension;
		}
		throw new ApiException('not_found', 404);
	}
}
