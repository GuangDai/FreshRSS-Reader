<?php
declare(strict_types=1);

/**
 * This class is used to test database is well-constructed.
 */
class FreshRSS_DatabaseDAOPGSQL extends FreshRSS_DatabaseDAOSQLite {

	//PostgreSQL error codes
	public const UNDEFINED_COLUMN = '42703';
	public const UNDEFINED_TABLE = '42P01';

	#[\Override]
	public function tablesAreCorrect(): bool {
		$db = FreshRSS_Context::systemConf()->db;
		$sql = <<<'SQL'
			SELECT tablename FROM pg_catalog.pg_tables where tableowner=:tableowner
			SQL;
		$res = $this->fetchAssoc($sql, [':tableowner' => $db['user']]);
		if ($res == null) {
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
			$tables[array_pop($value)] = true;
		}

		return count(array_keys($tables, true, true)) === count($tables);
	}

	/** @return list<array{name:string,type:string,notnull:bool,default:mixed}> */
	#[\Override]
	public function getSchema(string $table): array {
		$sql = <<<'SQL'
			SELECT column_name AS field, data_type AS type, column_default AS default, is_nullable AS null
			FROM INFORMATION_SCHEMA.COLUMNS
			WHERE (table_schema, table_name) = (
				SELECT n.nspname, c.relname FROM pg_catalog.pg_class c
				INNER JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
				WHERE c.oid = to_regclass(quote_ident(:table_name))
			)
			ORDER BY ordinal_position
			SQL;
		$res = $this->fetchAssoc($sql, [':table_name' => $this->pdo->prefix() . $table]);
		return $res == null ? [] : $this->listDaoToSchema($res);
	}

	/**
	 * @param array<string,string|int|bool|null> $dao
	 * @return array{'name':string,'type':string,'notnull':bool,'default':mixed}
	 */
	#[\Override]
	public function daoToSchema(array $dao): array {
		return [
			'name' => is_string($dao['field'] ?? null) ? $dao['field'] : '',
			'type' => is_string($dao['type'] ?? null) ? strtolower($dao['type']) : '',
			'notnull' => ($dao['null'] ?? 'YES') === 'NO',
			'default' => is_scalar($dao['default'] ?? null) ? $dao['default'] : null,
		];
	}

	#[\Override]
	protected function selectVersion(): string {
		return $this->fetchString('SELECT version()') ?? '';
	}

	/** @return list<string>|null */
	#[\Override]
	protected function readerIndexColumns(): ?array {
		$sql = <<<'SQL'
			SELECT a.attname FROM pg_catalog.pg_index i
			CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS key(attnum, position)
			INNER JOIN pg_catalog.pg_attribute a ON a.attrelid=i.indrelid AND a.attnum=key.attnum
			WHERE i.indexrelid=to_regclass(quote_ident(:index_name))
				AND i.indrelid=to_regclass(quote_ident(:table_name))
				AND i.indisvalid AND i.indisready AND i.indpred IS NULL AND i.indexprs IS NULL
			ORDER BY key.position
			SQL;
		$columns = $this->fetchColumn($sql, 0, [':index_name' => $this->pdo->prefix() . 'entry_feed_read_index',
			':table_name' => $this->pdo->prefix() . 'entry']);
		return $columns === null ? null : array_map(static fn(int|string|null $column): string => (string)$column, $columns);
	}

	#[\Override]
	protected function lockReaderIndexUpgrade(): bool {
		// Serialise explicit upgrades, including the check after another upgrader commits.
		return $this->pdo->exec('LOCK TABLE `_entry` IN SHARE ROW EXCLUSIVE MODE') !== false;
	}

	#[\Override]
	public function size(bool $all = false): int {
		if ($all) {
			$db = FreshRSS_Context::systemConf()->db;
			$res = $this->fetchColumn('SELECT pg_database_size(:base)', 0, [':base' => $db['base']]);
		} else {
			$sql = <<<SQL
				SELECT
				pg_total_relation_size('`{$this->pdo->prefix()}category`') +
				pg_total_relation_size('`{$this->pdo->prefix()}feed`') +
				pg_total_relation_size('`{$this->pdo->prefix()}entry`') +
				pg_total_relation_size('`{$this->pdo->prefix()}entrytmp`') +
				pg_total_relation_size('`{$this->pdo->prefix()}tag`') +
				pg_total_relation_size('`{$this->pdo->prefix()}entrytag`')
				SQL;
			$res = $this->fetchColumn($sql, 0);
		}
		return (int)($res[0] ?? -1);
	}

	#[\Override]
	public function optimize(): bool {
		$ok = true;
		$tables = ['category', 'feed', 'entry', 'entrytmp', 'tag', 'entrytag'];

		foreach ($tables as $table) {
			$sql = <<<SQL
				VACUUM `_{$table}`
				SQL;
			if ($this->pdo->exec($sql) === false) {
				$ok = false;
				$info = $this->pdo->errorInfo();
				Minz_Log::warning(__METHOD__ . ' error: ' . $sql . ' : ' . json_encode($info));
			}
		}
		return $ok;
	}

	#[\Override]
	public static function strilike(string $haystack, string $needle, bool $contains = false): bool {
		if (function_exists('mb_stripos')) {
			return $contains ? (mb_stripos($haystack, $needle, 0, 'UTF-8') !== false) :
				(mb_strtolower($haystack, 'UTF-8') === mb_strtolower($needle, 'UTF-8'));
		}
		if (function_exists('transliterator_transliterate')) {
			$haystack_ = transliterator_transliterate('Lower', $haystack);
			$needle_ = transliterator_transliterate('Lower', $needle);
			if ($haystack_ !== false && $needle_ !== false) {
				return $contains ? str_contains($haystack_, $needle_) : ($haystack_ === $needle_);
			}
		}
		return $contains ? (stripos($haystack, $needle) !== false) : (strcasecmp($haystack, $needle) === 0);
	}
}
