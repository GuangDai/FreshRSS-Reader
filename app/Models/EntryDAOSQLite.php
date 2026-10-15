<?php
declare(strict_types=1);

class FreshRSS_EntryDAOSQLite extends FreshRSS_EntryDAO {

	#[\Override]
	public static function isCompressed(): bool {
		return false;
	}

	#[\Override]
	public static function hasNativeHex(): bool {
		return false;
	}

	#[\Override]
	protected static function sqlConcat(string $s1, string $s2): string {
		return $s1 . '||' . $s2;
	}

	#[\Override]
	public static function sqlHexDecode(string $x): string {
		return $x;
	}

	#[\Override]
	public static function sqlIgnoreConflict(string $sql): string {
		return str_replace('INSERT INTO ', 'INSERT OR IGNORE INTO ', $sql);
	}

	#[\Override]
	protected static function sqlLimitAll(): string {
		// https://sqlite.org/lang_select.html#the_limit_clause
		return '-1';
	}

	#[\Override]
	public static function sqlRandom(): string {
		return 'RANDOM()';
	}

	#[\Override]
	protected static function sqlRegex(string $expression, string $regex, array &$values): string {
		$values[] = $regex;
		return "{$expression} REGEXP ?";
	}

	#[\Override]
	protected function registerSqlFunctions(string $sql): void {
		if (!str_contains($sql, ' REGEXP ')) {
			return;
		}
		// https://www.php.net/pdo.sqlitecreatefunction
		// https://www.sqlite.org/lang_expr.html#the_like_glob_regexp_match_and_extract_operators
		$this->pdo->sqliteCreateFunction('regexp',
			function (string $pattern, string $text): bool {
				return preg_match($pattern, $text) === 1;
			},
			2
		);
	}

	/** @param array{0:string,1:int,2:string} $errorInfo */
	#[\Override]
	protected function autoUpdateDb(array $errorInfo): bool {
		$columns = $this->fetchColumn("PRAGMA table_info('entry')", 1);
		if ($columns !== null) {
			foreach (['attributes', 'lastUserModified', 'lastModified'] as $column) {
				if (!in_array($column, $columns, true)) {
					return $this->addColumn($column);
				}
			}
		}
		return false;
	}

	#[\Override]
	public function commitNewEntries(): bool {
		$sql = <<<'SQL'
			DROP TABLE IF EXISTS temp.`freshrss_entry_commit`;
			CREATE TEMP TABLE temp.`freshrss_entry_commit` AS
				SELECT id, guid, title, author, content, link, date, `lastSeen`, hash, is_read, is_favorite, id_feed, tags, attributes
				FROM `_entrytmp`
				ORDER BY date, id;
			INSERT OR IGNORE INTO `_entry`
				(id, guid, title, author, content, link, date, `lastSeen`, hash, is_read, is_favorite, id_feed, tags, attributes)
				SELECT rowid + MAX(COALESCE((SELECT MAX(id) FROM `_entry`), 0), (SELECT MAX(id) - COUNT(*) FROM temp.`freshrss_entry_commit`)) AS id,
					guid, title, author, content, link, date, `lastSeen`, hash, is_read, is_favorite, id_feed, tags, attributes
				FROM temp.`freshrss_entry_commit` t
				ORDER BY t.date, t.id;
			DELETE FROM `_entrytmp` WHERE id <= (SELECT MAX(id) FROM temp.`freshrss_entry_commit`)
				AND EXISTS (SELECT 1 FROM `_entry` e WHERE e.id_feed=`_entrytmp`.id_feed AND e.guid=`_entrytmp`.guid);
			DROP TABLE IF EXISTS temp.`freshrss_entry_commit`;
			SQL;
		return $this->executeAtomic($sql);
	}

}
