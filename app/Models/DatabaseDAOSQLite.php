<?php
declare(strict_types=1);

/**
 * This class is used to test database is well-constructed (SQLite).
 */
class FreshRSS_DatabaseDAOSQLite extends FreshRSS_DatabaseDAO {

	#[\Override]
	public function tablesAreCorrect(): bool {
		$sql = "SELECT name FROM sqlite_master WHERE type='table'";
		$res = $this->fetchAssoc($sql);
		if ($res === null) {
			return false;
		}

		$tables = [
			$this->pdo->prefix() . 'category' => false,
			$this->pdo->prefix() . 'feed' => false,
			$this->pdo->prefix() . 'entry' => false,
			$this->pdo->prefix() . 'entrytmp' => false,
			$this->pdo->prefix() . 'tag' => false,
			$this->pdo->prefix() . 'entrytag' => false,
		];
		foreach ($res as $value) {
			if (is_array($value) && is_string($value['name'] ?? null)) {
				$tables[$value['name']] = true;
			}
		}

		return count(array_keys($tables, true, true)) == count($tables);
	}

	/** @return list<array{name:string,type:string,notnull:bool,default:mixed}> */
	#[\Override]
	public function getSchema(string $table): array {
		$sql = <<<SQL
			PRAGMA table_info('{$table}')
			SQL;
		$res = $this->fetchAssoc($sql);
		if ($res !== null) {
			/** @var list<array{name:string,type:string,notnull:bool|int,dflt_value:string|int|bool|null}> $res */
			return $this->listDaoToSchema($res);
		}
		return [];
	}

	/**
	 * @param array<string,string|int|bool|null> $dao
	 * @return array{'name':string,'type':string,'notnull':bool,'default':mixed}
	 */
	#[\Override]
	public function daoToSchema(array $dao): array {
		return [
			'name'    => is_string($dao['name'] ?? null) ? $dao['name'] : '',
			'type'    => is_string($dao['type'] ?? null) ? strtolower($dao['type']) : '',
			'notnull' => !empty($dao['notnull']),
			'default' => is_scalar($dao['dflt_value'] ?? null) ? $dao['dflt_value'] : null,
		];
	}

	#[\Override]
	protected function selectVersion(): string {
		return $this->fetchString('SELECT sqlite_version()') ?? '';
	}

	/** @return list<string>|null */
	protected function readerIndexColumns(): ?array {
		$columns = $this->fetchColumn("PRAGMA index_info('entry_feed_read_index')", 2);
		return $columns === null ? null : array_map(static fn(int|string|null $column): string => (string)$column, $columns);
	}

	protected function lockReaderIndexUpgrade(): bool {
		return true;
	}

	/** Rebuild the old two-column index atomically; callers choose their maintenance window. */
	#[\Override]
	public function upgradeReaderIndexes(): bool {
		$expected = ['id_feed', 'is_read', 'id'];
		$columns = $this->readerIndexColumns();
		if ($columns === null || $columns === $expected) {
			return $columns !== null;
		}
		$hadTransaction = $this->pdo->inTransaction();
		$finished = false;
		$started = $hadTransaction ? $this->pdo->exec('SAVEPOINT freshrss_reader_index_upgrade') !== false : $this->pdo->beginTransaction();
		if (!$started) {
			return false;
		}
		try {
			if (!$this->lockReaderIndexUpgrade()) {
				return false;
			}
			$columns = $this->readerIndexColumns();
			if ($columns === null) {
				return false;
			}
			if ($columns !== $expected && $this->pdo->exec('DROP INDEX IF EXISTS `_entry_feed_read_index`;
				CREATE INDEX `_entry_feed_read_index` ON `_entry` (id_feed,is_read,id)') === false) {
				Minz_Log::error('Reader index upgrade failed: ' . json_encode($this->pdo->errorInfo()));
				return false;
			}
			$finished = $hadTransaction ? $this->pdo->exec('RELEASE SAVEPOINT freshrss_reader_index_upgrade') !== false : $this->pdo->commit();
			return $finished;
		} finally {
			if (!$finished && $this->pdo->inTransaction()) {
				if ($hadTransaction) {
					$this->pdo->exec('ROLLBACK TO SAVEPOINT freshrss_reader_index_upgrade');
					$this->pdo->exec('RELEASE SAVEPOINT freshrss_reader_index_upgrade');
				} else {
					$this->pdo->rollBack();
				}
			}
		}
	}

	#[\Override]
	public function size(bool $all = false): int {
		$sum = 0;
		if ($all) {
			foreach (glob(DATA_PATH . '/users/*/db.sqlite') ?: [] as $filename) {
				$sum += (@filesize($filename) ?: 0);
			}
		} else {
			$sum = (@filesize(DATA_PATH . '/users/' . $this->current_user . '/db.sqlite') ?: 0);
		}
		return $sum;
	}

	#[\Override]
	public function optimize(): bool {
		$ok = $this->pdo->exec('VACUUM') !== false;
		if (!$ok) {
			$info = $this->pdo->errorInfo();
			Minz_Log::warning(__METHOD__ . ' error : ' . json_encode($info));
		}
		return $ok;
	}

	#[\Override]
	public static function strilike(string $haystack, string $needle, bool $contains = false): bool {
		return $contains ? (stripos($haystack, $needle) !== false) : (strcasecmp($haystack, $needle) === 0);
	}
}
