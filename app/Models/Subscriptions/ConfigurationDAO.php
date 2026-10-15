<?php
declare(strict_types=1);

namespace FreshRSS\Models\Subscriptions;

use FreshRSS\Exceptions\Reader\ApiException;
use FreshRSS_CategoryDAO;
use FreshRSS_DatabaseDAO;
use LogicException;
use Minz_Log;
use Minz_ModelPdo;
use Minz_User;
use PDO;
use PDOException;

/** Current-user subscription configuration. Dynamic counters never participate in revision checks.
 * @phpstan-type FeedRow array{id:int,name:string,category:int,priority:int,ttl:int,cache_nbUnreads:int,error:int}
 * @phpstan-type CategoryRow array{id:int,name:string,kind:int}
 * @phpstan-type Change array{type:'feed'|'category',id:int,expectedVersion:string,changes:array<string,int|string|bool>}
 * @phpstan-type FeedConfiguration array{id:int,name:string,categoryId:int,priority:int,ttl:int,enabled:bool,unread:int,error:bool,version:string}
 * @phpstan-type CategoryConfiguration array{id:int,name:string,version:string}
 */
final class ConfigurationDAO extends Minz_ModelPdo {
	/** @return array{feeds:list<FeedConfiguration>,categories:list<CategoryConfiguration>}
	 * @throws ApiException
	 */
	public function catalog(int $defaultTtl): array {
		$feeds = [];
		foreach ($this->rows('feed') as $row) {
			$feeds[] = self::feed($row, $defaultTtl);
		}
		$categories = [];
		foreach ($this->rows('category') as $row) {
			$categories[] = self::category($row);
		}
		return ['feeds' => $feeds, 'categories' => $categories];
	}

	/** The caller holds the user work lock; row locks additionally coordinate with classic database writers.
	 * @param list<Change> $changes
	 * @return array{feeds:list<FeedConfiguration>,categories:list<CategoryConfiguration>}
	 * @throws ApiException
	 */
	public function apply(array $changes, int $defaultTtl): array {
		$nested = $this->pdo->inTransaction();
		$finished = false;
		$started = false;
		try {
			$started = $nested ? $this->pdo->exec('SAVEPOINT freshrss_subscription_config') !== false : $this->pdo->beginTransaction();
			if (!$started) {
				throw new ApiException('write_failed', 500, ['atomic' => true]);
			}
			$ids = ['feed' => [], 'category' => []];
			foreach ($changes as $change) {
				$ids[$change['type']][] = $change['id'];
				if (isset($change['changes']['categoryId']) && is_int($change['changes']['categoryId'])) {
					$ids['category'][] = $change['changes']['categoryId'];
				}
			}
			// Always lock categories before feeds, with ascending IDs, for a consistent lock order.
			$categories = $this->rows('category', $ids['category'], true);
			$feeds = $this->rows('feed', $ids['feed'], true);
			$conflicts = [];
			foreach ($changes as $change) {
				$row = ($change['type'] === 'feed' ? $feeds : $categories)[$change['id']] ?? null;
				if ($row === null) {
					throw new ApiException('not_found', 404, ['atomic' => true]);
				}
				$current = $change['type'] === 'feed' ? self::feed($row, $defaultTtl) : self::category($row);
				if (!hash_equals($current['version'], $change['expectedVersion'])) {
					$conflicts[] = ['type' => $change['type'], 'id' => $change['id'], 'current' => $current];
				}
				$categoryId = $change['changes']['categoryId'] ?? null;
				if (is_int($categoryId) && !isset($categories[$categoryId])) {
					throw new ApiException('invalid_category', 400, ['atomic' => true]);
				}
				if ($change['type'] === 'category') {
					if ($change['id'] === FreshRSS_CategoryDAO::DEFAULTCATEGORYID) {
						throw new ApiException('default_category_read_only', 409, ['atomic' => true]);
					}
					$this->checkCategoryName($change['id'], $change['changes']['name'] ?? '');
				}
			}
			if ($conflicts !== []) {
				throw new ApiException('configuration_conflict', 409, ['conflicts' => $conflicts, 'atomic' => true]);
			}
			$result = ['feeds' => [], 'categories' => []];
			foreach ($changes as $change) {
				$id = $change['id'];
				if ($change['type'] === 'feed') {
					$row = $feeds[$id];
					$current = self::feed($row, $defaultTtl);
					$patch = $change['changes'];
					$ttl = is_int($patch['ttl'] ?? null) ? $patch['ttl'] : $current['ttl'];
					$enabled = is_bool($patch['enabled'] ?? null) ? $patch['enabled'] : $current['enabled'];
					$values = [];
					if (is_string($patch['name'] ?? null)) {
						$values['name'] = $patch['name'];
					}
					if (is_int($patch['categoryId'] ?? null)) {
						$values['category'] = $patch['categoryId'];
					}
					if (is_int($patch['priority'] ?? null)) {
						$values['priority'] = $patch['priority'];
					}
					if (array_key_exists('ttl', $patch) || array_key_exists('enabled', $patch)) {
						$values['ttl'] = $enabled ? $ttl : -max(1, $ttl === 0 ? $defaultTtl : $ttl);
					}
					$this->update('feed', $id, $values);
					$result['feeds'][] = self::feed(array_replace($row, $values), $defaultTtl);
				} else {
					$row = $categories[$id];
					$values = ['name' => is_string($change['changes']['name'] ?? null) ? $change['changes']['name'] : (string)$row['name']];
					$this->update('category', $id, $values);
					$result['categories'][] = self::category(array_replace($row, $values));
				}
			}
			$finished = $nested ? $this->pdo->exec('RELEASE SAVEPOINT freshrss_subscription_config') !== false : $this->pdo->commit();
			if (!$finished) {
				throw new ApiException('write_failed', 500, ['atomic' => true]);
			}
			return $result;
		} catch (PDOException $exception) {
			Minz_Log::error('Subscription configuration database failure: ' . $exception->getCode());
			throw new ApiException('write_failed', 500, ['atomic' => true], $exception);
		} finally {
			if ($started && !$finished && $this->pdo->inTransaction()) {
				if ($nested) {
					$this->pdo->exec('ROLLBACK TO SAVEPOINT freshrss_subscription_config');
					$this->pdo->exec('RELEASE SAVEPOINT freshrss_subscription_config');
				} else {
					$this->pdo->rollBack();
				}
			}
		}
	}

	/** @param 'feed'|'category' $type
	 * @param list<int>|null $ids
	 * @return array<int,array<string,int|string|null>>
	 * @throws ApiException
	 */
	private function rows(string $type, ?array $ids = null, bool $lock = false): array {
		if ($ids === []) {
			return [];
		}
		$columns = $type === 'feed' ? 'id,name,category,priority,ttl,`cache_nbUnreads`,error' : 'id,name,kind';
		if ($ids !== null) {
			$ids = array_values(array_unique($ids));
			sort($ids, SORT_NUMERIC);
		}
		$chunks = $ids === null ? [null] : array_chunk($ids, FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER);
		$result = [];
		foreach ($chunks as $chunk) {
			$sql = "SELECT {$columns} FROM `_{$type}`";
			if ($chunk !== null) {
				$sql .= ' WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')';
			}
			$sql .= $ids === null ? ' ORDER BY name,id' : ' ORDER BY id';
			if ($lock && $this->pdo->dbType() !== 'sqlite') {
				$sql .= ' FOR UPDATE';
			}
			$statement = $this->pdo->prepare($sql);
			if ($statement === false || !$statement->execute($chunk ?? [])) {
				throw new ApiException('read_failed', 500);
			}
			while (is_array($row = $statement->fetch(PDO::FETCH_ASSOC))) {
				/** @var array<string,int|string|null> $row */
				foreach ($row as $key => $value) {
					if ($key !== 'name' && $value !== null) {
						$row[$key] = (int)$value;
					}
				}
				$result[(int)$row['id']] = $row;
			}
		}
		return $result;
	}

	/** @param array<string,int|string|null> $row
	 * @return FeedConfiguration
	 */
	private static function feed(array $row, int $defaultTtl): array {
		$ttl = (int)$row['ttl'];
		$configuration = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'categoryId' => (int)$row['category'],
			'priority' => (int)$row['priority'], 'ttl' => $ttl];
		$version = hash('sha256', json_encode([Minz_User::name(), $configuration], JSON_THROW_ON_ERROR));
		return ['id' => $configuration['id'], 'name' => html_entity_decode($configuration['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
			'categoryId' => $configuration['categoryId'], 'priority' => $configuration['priority'],
			'ttl' => $ttl < 0 && -$ttl === $defaultTtl ? 0 : abs(max(-PHP_INT_MAX, $ttl)), 'enabled' => $ttl >= 0,
			'unread' => (int)$row['cache_nbUnreads'], 'error' => (int)$row['error'] > 0, 'version' => $version];
	}

	/** @param array<string,int|string|null> $row
	 * @return CategoryConfiguration
	 */
	private static function category(array $row): array {
		$id = (int)$row['id'];
		$name = (string)$row['name'];
		return ['id' => $id, 'name' => html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
			'version' => hash('sha256', json_encode([Minz_User::name(), $id, $row['name'], $row['kind']], JSON_THROW_ON_ERROR))];
	}

	/** @throws ApiException */
	private function checkCategoryName(int $id, int|string|bool $name): void {
		foreach (['SELECT id FROM `_category` WHERE name=? AND id<>?', 'SELECT id FROM `_tag` WHERE name=?'] as $index => $sql) {
			$statement = $this->pdo->prepare($sql);
			if ($statement === false || !$statement->execute($index === 0 ? [$name, $id] : [$name])) {
				throw new ApiException('read_failed', 500);
			}
			if ($statement->fetchColumn() !== false) {
				throw new ApiException('name_conflict', 409, ['atomic' => true]);
			}
		}
	}

	/** @param 'feed'|'category' $type
	 * @param array<string,int|string|bool> $values
	 * @throws ApiException
	 */
	private function update(string $type, int $id, array $values): void {
		$allowed = $type === 'feed' ? ['name', 'category', 'priority', 'ttl'] : ['name'];
		$columns = array_keys($values);
		if ($columns === [] || array_diff($columns, $allowed) !== []) {
			throw new LogicException('Subscription configuration update contains unsupported columns');
		}
		$set = implode(',', array_map(static fn(string $column): string => '`' . $column . '`=?', $columns));
		$statement = $this->pdo->prepare("UPDATE `_{$type}` SET {$set} WHERE id=?");
		$parameters = array_map(static fn(string $column): int|string|bool => $values[$column], $columns);
		$parameters[] = $id;
		try {
			$written = $statement !== false && $statement->execute($parameters);
		} catch (PDOException $exception) {
			if ($type === 'category' && in_array((string)$exception->getCode(), ['23000', '23505'], true)) {
				throw new ApiException('name_conflict', 409, ['atomic' => true], $exception);
			}
			throw $exception;
		}
		if (!$written) {
			$info = $statement === false ? $this->pdo->errorInfo() : $statement->errorInfo();
			if ($type === 'category' && in_array($info[0] ?? '', ['23000', '23505'], true)) {
				throw new ApiException('name_conflict', 409, ['atomic' => true]);
			}
			throw new ApiException('write_failed', 500, ['atomic' => true]);
		}
	}
}
