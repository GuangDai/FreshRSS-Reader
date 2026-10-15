<?php
declare(strict_types=1);

namespace FreshRSS\Services\Subscriptions;

use FreshRSS\Exceptions\Reader\ApiException;
use FreshRSS\Infrastructure\Concurrency\UserWorkLock;
use FreshRSS\Models\Subscriptions\ConfigurationDAO;
use FreshRSS_DatabaseDAO;
use FreshRSS_Feed;

/** Validates and applies versioned, current-user subscription configuration batches.
 * @phpstan-import-type Change from ConfigurationDAO
 * @phpstan-import-type FeedConfiguration from ConfigurationDAO
 * @phpstan-import-type CategoryConfiguration from ConfigurationDAO
 * @phpstan-type FieldSpec array{type:'integer'|'boolean'|'string',min?:int,max?:int,
 *     minLength?:int,maxLength?:int,unit?:string,options?:list<int>,labels?:array<int,string>}
 */
final class ConfigurationService {
	public const BUSY_RETRY_AFTER = 1;
	public const VERSION_PATTERN = '^[a-f0-9]{64}$';

	public function __construct(private readonly ConfigurationDAO $dao, private readonly int $defaultTtl) {
	}

	/** @return array<string,FieldSpec> */
	public static function fields(): array {
		return [
			'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => FreshRSS_DatabaseDAO::LENGTH_INDEX_UNICODE, 'unit' => 'html-encoded-bytes'],
			'categoryId' => ['type' => 'integer', 'min' => 1],
			'ttl' => ['type' => 'integer', 'min' => FreshRSS_Feed::TTL_DEFAULT, 'max' => FreshRSS_Feed::TTL_MAX, 'unit' => 'seconds'],
			'enabled' => ['type' => 'boolean'],
			'priority' => ['type' => 'integer', 'options' => [FreshRSS_Feed::PRIORITY_HIDDEN, FreshRSS_Feed::PRIORITY_FEED,
				FreshRSS_Feed::PRIORITY_CATEGORY, FreshRSS_Feed::PRIORITY_MAIN_STREAM, FreshRSS_Feed::PRIORITY_IMPORTANT],
				'labels' => [FreshRSS_Feed::PRIORITY_HIDDEN => 'hidden', FreshRSS_Feed::PRIORITY_FEED => 'feed', FreshRSS_Feed::PRIORITY_CATEGORY => 'category',
					FreshRSS_Feed::PRIORITY_MAIN_STREAM => 'main', FreshRSS_Feed::PRIORITY_IMPORTANT => 'important']],
		];
	}

	/** @return array<string,mixed> */
	public static function schema(int $maximum): array {
		return ['fields' => self::fields(), 'categoryFields' => ['name' => self::fields()['name']], 'maxItems' => $maximum,
			'expectedVersionsRequired' => true, 'versionPattern' => self::VERSION_PATTERN, 'atomic' => true,
			'busyStatus' => 503, 'busyRetryAfter' => self::BUSY_RETRY_AFTER];
	}

	/** @param array<string,mixed> $params
	 * @return array{atomic:true,feeds:list<FeedConfiguration>,updated:list<int>}
	 * @throws ApiException
	 */
	public function feeds(array $params, int $maximum): array {
		$ids = $params['ids'] ?? null;
		$versions = $params['expectedVersions'] ?? null;
		if (!is_array($ids) || !array_is_list($ids) || $ids === [] || !is_array($versions)) {
			throw new ApiException('invalid_ids');
		}
		if (count($ids) > $maximum) {
			throw new ApiException('batch_too_large', 413, ['maxBatchItems' => $maximum]);
		}
		if (count($versions) !== count($ids)) {
			throw new ApiException('invalid_version');
		}
		$items = [];
		foreach ($ids as $id) {
			if (!is_int($id) || $id < 1) {
				throw new ApiException('invalid_id');
			}
			$items[] = ['type' => 'feed', 'id' => $id, 'changes' => $params['changes'] ?? null, 'expectedVersion' => $versions[$id] ?? null];
		}
		$result = $this->apply(self::validateItems($items, $maximum));
		return ['atomic' => true, 'feeds' => $result['feeds'], 'updated' => array_column($result['feeds'], 'id')];
	}

	/** @param array<string,mixed> $params
	 * @return array{atomic:true,feeds:list<FeedConfiguration>,categories:list<CategoryConfiguration>}
	 * @throws ApiException
	 */
	public function subscriptions(array $params, int $maximum): array {
		return ['atomic' => true] + $this->apply(self::validateItems($params['items'] ?? null, $maximum));
	}

	/** @return list<Change>
	 * @throws ApiException
	 */
	public static function validateItems(mixed $items, int $maximum): array {
		if (!is_array($items) || !array_is_list($items) || $items === []) {
			throw new ApiException('invalid_items');
		}
		if (count($items) > $maximum) {
			throw new ApiException('batch_too_large', 413, ['maxBatchItems' => $maximum]);
		}
		$result = [];
		$seen = [];
		$names = [];
		foreach ($items as $item) {
			if (!is_array($item) || array_diff(array_keys($item), ['type', 'id', 'changes', 'expectedVersion']) !== [] ||
				!in_array($item['type'] ?? null, ['feed', 'category'], true) || !is_int($item['id'] ?? null) || $item['id'] < 1) {
				throw new ApiException('invalid_item');
			}
			$type = $item['type'];
			$id = $item['id'];
			if (isset($seen[$type . ':' . $id])) {
				throw new ApiException('duplicate_id');
			}
			$seen[$type . ':' . $id] = true;
			if (!is_string($item['expectedVersion'] ?? null) || preg_match('/' . self::VERSION_PATTERN . '/D', $item['expectedVersion']) !== 1) {
				throw new ApiException('invalid_version');
			}
			$patch = $item['changes'] ?? null;
			if (!is_array($patch) || $patch === [] || array_is_list($patch)) {
				throw new ApiException('empty_update');
			}
			$specs = $type === 'feed' ? self::fields() : ['name' => self::fields()['name']];
			$values = [];
			foreach ($patch as $field => $value) {
				if (!is_string($field) || !isset($specs[$field])) {
					throw new ApiException('invalid_field', details: ['field' => $field]);
				}
				$spec = $specs[$field];
				if ($spec['type'] === 'string') {
					$values[$field] = NameValidator::validate($value);
				} elseif (($spec['type'] === 'boolean' && !is_bool($value)) || ($spec['type'] === 'integer' && !is_int($value))) {
					throw new ApiException('invalid_field', details: ['field' => $field]);
				} else {
					if ((isset($spec['min']) && $value < $spec['min']) || (isset($spec['max']) && $value > $spec['max']) ||
						(isset($spec['options']) && !in_array($value, $spec['options'], true))) {
						throw new ApiException('field_out_of_range', details: ['field' => $field]);
					}
					$values[$field] = $value;
				}
			}
			if ($type === 'category') {
				$name = $values['name'] ?? '';
				if (!is_string($name) || isset($names[$name])) {
					throw new ApiException('name_conflict', 409, ['atomic' => true]);
				}
				$names[$name] = true;
			}
			$result[] = ['type' => $type, 'id' => $id, 'expectedVersion' => $item['expectedVersion'], 'changes' => $values];
		}
		return $result;
	}

	/** @param list<Change> $changes
	 * @return array{feeds:list<FeedConfiguration>,categories:list<CategoryConfiguration>}
	 * @throws ApiException
	 */
	private function apply(array $changes): array {
		$lock = UserWorkLock::tryAcquire();
		if ($lock === null) {
			throw new ApiException('processing_busy', 503, ['retryable' => true, 'atomic' => true]);
		}
		try {
			$result = $this->dao->apply($changes, $this->defaultTtl);
			invalidateHttpCache();
			return $result;
		} finally {
			$lock->release();
		}
	}
}
