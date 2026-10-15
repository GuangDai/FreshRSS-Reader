<?php
declare(strict_types=1);

class FreshRSS_EntryDAO extends Minz_ModelPdo {

	public static function isCompressed(): bool {
		return true;
	}

	public static function hasNativeHex(): bool {
		return true;
	}

	protected static function sqlConcat(string $s1, string $s2): string {
		return 'CONCAT(' . $s1 . ',' . $s2 . ')';	//MySQL
	}

	public static function sqlHexDecode(string $x): string {
		return 'unhex(' . $x . ')';
	}

	public static function sqlHexEncode(string $x): string {
		return 'hex(' . $x . ')';
	}

	public static function sqlIgnoreConflict(string $sql): string {
		return str_replace('INSERT INTO ', 'INSERT IGNORE INTO ', $sql);
	}

	protected static function sqlLimitAll(): string {
		// https://dev.mysql.com/doc/refman/26.7/en/select.html
		return '18446744073709551615';	// Maximum unsigned BIGINT in MySQL, which neither supports ALL nor -1
	}

	public static function sqlLimit(int $limit = -1, int $offset = 0): string {
		if ($limit < 0 && $offset <= 0) {
			return '';
		}
		if ($limit < 1) {
			$limit = static::sqlLimitAll();
		}
		return "LIMIT {$limit} OFFSET {$offset}";
	}

	public static function sqlRandom(): string {
		return 'RAND()';
	}

	/** @return array{pattern?:string,matchType?:string} */
	protected static function regexToSql(string $regex): array {
		if (preg_match('#^/(?P<pattern>.*)/(?P<matchType>[im]*)$#', $regex, $matches)) {
			return $matches;
		}
		return [];
	}

	/** @param list<int|string> $values */
	protected static function sqlRegex(string $expression, string $regex, array &$values): string {
		// The implementation of this function is solely for MySQL and MariaDB
		static $databaseDAOMySQL = null;
		if (!($databaseDAOMySQL instanceof FreshRSS_DatabaseDAO)) {
			$databaseDAOMySQL = new FreshRSS_DatabaseDAO();
		}

		$matches = static::regexToSql($regex);
		if (isset($matches['pattern'])) {
			$matchType = $matches['matchType'] ?? '';
			if ($databaseDAOMySQL->isMariaDB()) {
				if (str_contains($matchType, 'm')) {
					// multiline mode
					$matches['pattern'] = '(?m)' . $matches['pattern'];
				}
				if (str_contains($matchType, 'i')) {
					// case-insensitive match
					$matches['pattern'] = '(?i)' . $matches['pattern'];
				} else {
					$matches['pattern'] = '(?-i)' . $matches['pattern'];
				}
				$values[] = $matches['pattern'];
				return "{$expression} REGEXP ?";
			} else {	// MySQL
				if (!str_contains($matchType, 'i')) {
					// Case-sensitive matching
					$matchType .= 'c';
				}
				$values[] = $matches['pattern'];
				return "REGEXP_LIKE({$expression},?,'{$matchType}')";
			}
		}
		return '';
	}

	/** Register any needed SQL function for the query, e.g. application-defined functions for SQLite */
	protected function registerSqlFunctions(string $sql): void {
		// Nothing to do for MySQL
	}

	private function updateToMediumBlob(): bool {
		if ($this->pdo->dbType() !== 'mysql' || $this->pdo->inTransaction()) {
			return false;
		}
		Minz_Log::warning('Update MySQL table to use MEDIUMBLOB...');

		$sql = <<<'SQL'
			ALTER TABLE `_entry` MODIFY `content_bin` MEDIUMBLOB;
			ALTER TABLE `_entrytmp` MODIFY `content_bin` MEDIUMBLOB;
			SQL;
		try {
			$ok = $this->pdo->exec($sql) !== false;
		} catch (Exception $e) {
			$ok = false;
			Minz_Log::error(__METHOD__ . ' error: ' . $e->getMessage());
		}
		return $ok;
	}

	protected function addColumn(string $name): bool {
		if ($this->pdo->inTransaction()) {
			Minz_Log::error(__METHOD__ . ': cannot migrate the database inside a caller transaction');
			return false;
		}
		Minz_Log::warning(__METHOD__ . ': ' . $name);
		require APP_PATH . '/SQL/install.sql.' . $this->pdo->dbType() . '.php';
		$isMySQL = false;
		if ($this->pdo->dbType() === 'mysql') {
			$databaseDao = new FreshRSS_DatabaseDAO();
			$isMySQL = !$databaseDao->isMariaDB();
		}
		$result = false;
		try {
			if ($name === 'attributes') {	//v1.20.0
				$sql = <<<'SQL'
					ALTER TABLE `_entry` ADD COLUMN attributes TEXT;
					ALTER TABLE `_entrytmp` ADD COLUMN attributes TEXT;
					SQL;
				$result = $this->pdo->exec($sql);
			} elseif ($name === 'lastUserModified') {	//v1.28.0
				$sql = $GLOBALS['ALTER_TABLE_ENTRY_LAST_USER_MODIFIED'];
				if (!is_string($sql)) {
					throw new Exception('ALTER_TABLE_ENTRY_LAST_USER_MODIFIED is not a string!');
				}
				if ($isMySQL) { // Unlike MariaDB, MySQL does not support `IF NOT EXISTS` https://dev.mysql.com/doc/refman/26.7/en/alter-table.html
					$sql = str_replace(' IF NOT EXISTS ', ' ', $sql);
				}
				$result = $this->pdo->exec($sql);
			} elseif ($name === 'lastModified') {	//v1.29.0
				$sql = $GLOBALS['ALTER_TABLE_ENTRY_LAST_MODIFIED'] ?? null;
				if (!is_string($sql)) {
					throw new Exception('ALTER_TABLE_ENTRY_LAST_MODIFIED is not a string!');
				}
				if ($isMySQL) { // Unlike MariaDB, MySQL does not support `IF NOT EXISTS` https://dev.mysql.com/doc/refman/26.7/en/alter-table.html
					$sql = str_replace(' IF NOT EXISTS ', ' ', $sql);
				}
				$result = $this->pdo->exec($sql);
			}
			if ($result === false) {
				Minz_Log::error(__METHOD__ . ' error: ' . json_encode($this->pdo->errorInfo()));
			}
		} catch (Exception $e) {
			Minz_Log::error(__METHOD__ . ' error: ' . $e->getMessage());
		}
		return $result !== false;
	}

	//TODO: Move the database auto-updates to DatabaseDAO
	/** @param array{0:string,1:int,2:string} $errorInfo */
	protected function autoUpdateDb(array $errorInfo): bool {
		if (isset($errorInfo[0])) {
			if ($errorInfo[0] === FreshRSS_DatabaseDAO::ER_BAD_FIELD_ERROR || $errorInfo[0] === FreshRSS_DatabaseDAOPGSQL::UNDEFINED_COLUMN) {
				$errorLines = explode("\n", $errorInfo[2], 2);	// The relevant column name is on the first line, other lines are noise
				foreach (['attributes', 'lastUserModified', 'lastModified'] as $column) {
					if (str_contains($errorLines[0], $column)) {
						return $this->addColumn($column);
					}
				}
			}
		}
		if (isset($errorInfo[1])) {
			// May be a string or an int
			if ($errorInfo[1] == FreshRSS_DatabaseDAO::ER_DATA_TOO_LONG) {
				if (str_contains($errorInfo[2], 'content_bin')) {
					return $this->updateToMediumBlob();	//v1.15.0
				}
			}
		}
		return false;
	}

	private PDOStatement|null|false $addEntryPrepared = null;
	private bool $addEntryPreparedUsesTmpTable = true;

	/** @param array{id:string,guid:string,title:string,author:string,content:string,link:string,date:int,lastSeen:int,hash:string,
	 *		is_read:bool|int|null,is_favorite:bool|int|null,id_feed:int,tags:string,attributes?:null|string|array<string,mixed>} $valuesTmp */
	public function addEntry(array $valuesTmp, bool $useTmpTable = true): bool {
		$attributes = $valuesTmp['attributes'] ?? [];
		$attributes = is_string($attributes) ? $attributes : json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($attributes === false) {
			Minz_Log::error(__METHOD__ . ': invalid entry attributes: ' . json_last_error_msg());
			return false;
		}
		if ($this->addEntryPrepared == null || $this->addEntryPreparedUsesTmpTable !== $useTmpTable) {
			$sql = static::sqlIgnoreConflict(
				'INSERT INTO `_' . ($useTmpTable ? 'entrytmp' : 'entry') . '` (id, guid, title, author, '
				. (static::isCompressed() ? 'content_bin' : 'content')
				. ', link, date, `lastSeen`, hash, is_read, is_favorite, id_feed, tags, attributes) '
				. 'VALUES(:id, :guid, :title, :author, '
				. (static::isCompressed() ? 'COMPRESS(:content)' : ':content')
				. ', :link, :date, :last_seen, '
				. static::sqlHexDecode(':hash')
				. ', :is_read, :is_favorite, :id_feed, :tags, :attributes)');
			$this->addEntryPrepared = $this->pdo->prepare($sql);
			$this->addEntryPreparedUsesTmpTable = $useTmpTable;
		}
		if ($this->addEntryPrepared != false) {
			$this->addEntryPrepared->bindValue(':id', $valuesTmp['id']);
			$valuesTmp['guid'] = substr($valuesTmp['guid'], 0, 767);
			$valuesTmp['guid'] = safe_ascii($valuesTmp['guid']);
			$this->addEntryPrepared->bindValue(':guid', $valuesTmp['guid']);
			$valuesTmp['title'] = mb_strcut($valuesTmp['title'], 0, 8192, 'UTF-8');
			$valuesTmp['title'] = safe_utf8($valuesTmp['title']);
			$this->addEntryPrepared->bindValue(':title', $valuesTmp['title']);
			$valuesTmp['author'] = mb_strcut($valuesTmp['author'], 0, 1024, 'UTF-8');
			$valuesTmp['author'] = safe_utf8($valuesTmp['author']);
			$this->addEntryPrepared->bindValue(':author', $valuesTmp['author']);
			$valuesTmp['content'] = safe_utf8($valuesTmp['content']);
			$this->addEntryPrepared->bindValue(':content', $valuesTmp['content']);
			$valuesTmp['link'] = substr($valuesTmp['link'], 0, 16383);
			$valuesTmp['link'] = safe_ascii($valuesTmp['link']);
			$this->addEntryPrepared->bindValue(':link', $valuesTmp['link']);
			$this->addEntryPrepared->bindValue(':date', $valuesTmp['date'], PDO::PARAM_INT);
			if (empty($valuesTmp['lastSeen'])) {
				$valuesTmp['lastSeen'] = time();
			}
			$this->addEntryPrepared->bindValue(':last_seen', $valuesTmp['lastSeen'], PDO::PARAM_INT);
			$valuesTmp['is_read'] = $valuesTmp['is_read'] ? 1 : 0;
			$this->addEntryPrepared->bindValue(':is_read', $valuesTmp['is_read'], PDO::PARAM_INT);
			$valuesTmp['is_favorite'] = $valuesTmp['is_favorite'] ? 1 : 0;
			$this->addEntryPrepared->bindValue(':is_favorite', $valuesTmp['is_favorite'], PDO::PARAM_INT);
			$this->addEntryPrepared->bindValue(':id_feed', $valuesTmp['id_feed'], PDO::PARAM_INT);
			$valuesTmp['tags'] = mb_strcut($valuesTmp['tags'], 0, FreshRSS_Entry::MAX_TAGS_LENGTH, 'UTF-8');
			$valuesTmp['tags'] = safe_utf8($valuesTmp['tags']);
			$this->addEntryPrepared->bindValue(':tags', $valuesTmp['tags']);
			$this->addEntryPrepared->bindValue(':attributes', $attributes);

			if (static::hasNativeHex()) {
				$this->addEntryPrepared->bindValue(':hash', $valuesTmp['hash']);
			} else {
				$valuesTmp['hashBin'] = hex2bin($valuesTmp['hash']);
				$this->addEntryPrepared->bindValue(':hash', $valuesTmp['hashBin']);
			}
		}
		if ($this->addEntryPrepared != false && $this->addEntryPrepared->execute()) {
			return true;
		} else {
			$info = $this->addEntryPrepared == false ? $this->pdo->errorInfo() : $this->addEntryPrepared->errorInfo();
			/** @var array{id:string,guid:string,title:string,author:string,content:string,link:string,date:int,lastSeen:int,hash:string,
			 * 	is_read:bool|int|null,is_favorite:bool|int|null,id_feed:int,tags:string,attributes?:null|string|array<string,mixed>} $valuesTmp */
			/** @var array{0:string,1:int,2:string} $info */
			if ($this->autoUpdateDb($info)) {
				$this->addEntryPrepared = null;
				return $this->addEntry($valuesTmp, $useTmpTable);
			} elseif ((int)((int)$info[0] / 1000) !== 23) {	//Filter out "SQLSTATE Class code 23: Constraint Violation" because of expected duplicate entries
				Minz_Log::error('SQL error ' . __METHOD__ . json_encode($info)
					. ' while adding entry in feed ' . $valuesTmp['id_feed'] . ' with title: ' . $valuesTmp['title']);
			}
			return false;
		}
	}

	public function commitNewEntries(): bool {
		$sql = <<<'SQL'
			SET @maxrank=(SELECT MAX(id) FROM `_entrytmp`);
			SET @rank=GREATEST(COALESCE((SELECT MAX(id) FROM `_entry`), 0), (SELECT @maxrank - COUNT(*) FROM `_entrytmp`));

			INSERT IGNORE INTO `_entry` (
				id, guid, title, author, content_bin, link, date, `lastSeen`,
				hash, is_read, is_favorite, id_feed, tags, attributes
			)
			SELECT @rank:=@rank+1 AS id, guid, title, author, content_bin, link, date, `lastSeen`, hash, is_read, is_favorite, id_feed, tags, attributes
			FROM `_entrytmp` etmp
			ORDER BY etmp.date, etmp.id;

			DELETE FROM `_entrytmp` WHERE id <= @maxrank
				AND EXISTS (SELECT 1 FROM `_entry` e WHERE e.id_feed=`_entrytmp`.id_feed AND e.guid=`_entrytmp`.guid);
			SQL;
		return $this->executeAtomic($sql);
	}

	/** Execute a multi-statement write without committing partially completed work. */
	protected function executeAtomic(string $sql): bool {
		$hadTransaction = $this->pdo->inTransaction();
		$finished = false;
		$started = $hadTransaction ? $this->pdo->exec('SAVEPOINT freshrss_entry_write') !== false : $this->pdo->beginTransaction();
		if (!$started) {
			return false;
		}
		try {
			if ($this->pdo->exec($sql) === false) {
				Minz_Log::error('SQL error ' . __METHOD__ . json_encode($this->pdo->errorInfo()));
				return false;
			}
			// A conflicting ID or an ignored invalid row must never silently discard a staged article.
			if ($this->fetchInt('SELECT COUNT(*) FROM `_entrytmp`') !== 0) {
				Minz_Log::error(__METHOD__ . ': staged entries remain after commit; rolling back');
				return false;
			}
			$finished = $hadTransaction ? $this->pdo->exec('RELEASE SAVEPOINT freshrss_entry_write') !== false : $this->pdo->commit();
			return $finished;
		} finally {
			if (!$finished && $this->pdo->inTransaction()) {
				if ($hadTransaction) {
					$this->pdo->exec('ROLLBACK TO SAVEPOINT freshrss_entry_write');
					$this->pdo->exec('RELEASE SAVEPOINT freshrss_entry_write');
				} else {
					$this->pdo->rollBack();
				}
			}
		}
	}

	private PDOStatement|null|false $updateEntryPrepared = null;

	/**
	 * @param array{id:string,guid:string,title:string,author:string,content:string,link:string,
	 * 	date:int,lastSeen:int,lastModified?:?int,lastUserModified?:?int,hash:string,
	 * 	is_read:bool|int|null,is_favorite:bool|int|null,id_feed:int,tags:string,attributes:array<string,mixed>} $valuesTmp
	 */
	public function updateEntry(array $valuesTmp): bool {
		$attributes = $valuesTmp['attributes'] ?? [];
		$attributes = is_string($attributes) ? $attributes : json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($attributes === false) {
			Minz_Log::error(__METHOD__ . ': invalid entry attributes: ' . json_last_error_msg());
			return false;
		}
		if (!isset($valuesTmp['is_read'])) {
			$valuesTmp['is_read'] = null;
		}
		if (!isset($valuesTmp['is_favorite'])) {
			$valuesTmp['is_favorite'] = null;
		}
		if (!isset($valuesTmp['lastUserModified'])) {
			$valuesTmp['lastUserModified'] = null;
		}
		if (!isset($valuesTmp['lastModified'])) {
			$valuesTmp['lastModified'] = null;
		}
		if ($this->updateEntryPrepared == null) {
			$contentAssign = static::isCompressed() ? 'content_bin=COMPRESS(:content)' : 'content=:content';
			$hashExpr = static::sqlHexDecode(':hash');
			$sql = <<<SQL
				UPDATE `_entry`
				SET title=:title, author=:author, {$contentAssign}, link=:link, date=:date, `lastSeen`=:last_seen,
					`lastModified`=COALESCE(:last_modified, `lastModified`),
					`lastUserModified`=COALESCE(:last_user_modified, `lastUserModified`),
					hash={$hashExpr},
					is_read=COALESCE(:is_read, is_read),
					is_favorite=COALESCE(:is_favorite, is_favorite),
					tags=:tags, attributes=:attributes
				WHERE id_feed=:id_feed AND guid=:guid
				SQL;
			$this->updateEntryPrepared = $this->pdo->prepare($sql);
		}
		if ($this->updateEntryPrepared != false) {
			$valuesTmp['guid'] = substr($valuesTmp['guid'], 0, 767);
			$valuesTmp['guid'] = safe_ascii($valuesTmp['guid']);
			$this->updateEntryPrepared->bindValue(':guid', $valuesTmp['guid']);
			$valuesTmp['title'] = mb_strcut($valuesTmp['title'], 0, 8192, 'UTF-8');
			$valuesTmp['title'] = safe_utf8($valuesTmp['title']);
			$this->updateEntryPrepared->bindValue(':title', $valuesTmp['title']);
			$valuesTmp['author'] = mb_strcut($valuesTmp['author'], 0, 1024, 'UTF-8');
			$valuesTmp['author'] = safe_utf8($valuesTmp['author']);
			$this->updateEntryPrepared->bindValue(':author', $valuesTmp['author']);
			$valuesTmp['content'] = safe_utf8($valuesTmp['content']);
			$this->updateEntryPrepared->bindValue(':content', $valuesTmp['content']);
			$valuesTmp['link'] = substr($valuesTmp['link'], 0, 16383);
			$valuesTmp['link'] = safe_ascii($valuesTmp['link']);
			$this->updateEntryPrepared->bindValue(':link', $valuesTmp['link']);
			$this->updateEntryPrepared->bindValue(':date', $valuesTmp['date'], PDO::PARAM_INT);
			$this->updateEntryPrepared->bindValue(':last_seen', $valuesTmp['lastSeen'], PDO::PARAM_INT);
			if ($valuesTmp['lastModified'] === null) {
				$this->updateEntryPrepared->bindValue(':last_modified', null, PDO::PARAM_NULL);
			} else {
				$this->updateEntryPrepared->bindValue(':last_modified', $valuesTmp['lastModified'], PDO::PARAM_INT);
			}
			if ($valuesTmp['lastUserModified'] === null) {
				$this->updateEntryPrepared->bindValue(':last_user_modified', null, PDO::PARAM_NULL);
			} else {
				$this->updateEntryPrepared->bindValue(':last_user_modified', $valuesTmp['lastUserModified'], PDO::PARAM_INT);
			}
			if ($valuesTmp['is_read'] === null) {
				$this->updateEntryPrepared->bindValue(':is_read', null, PDO::PARAM_NULL);
			} else {
				$this->updateEntryPrepared->bindValue(':is_read', $valuesTmp['is_read'] ? 1 : 0, PDO::PARAM_INT);
			}
			if ($valuesTmp['is_favorite'] === null) {
				$this->updateEntryPrepared->bindValue(':is_favorite', null, PDO::PARAM_NULL);
			} else {
				$this->updateEntryPrepared->bindValue(':is_favorite', $valuesTmp['is_favorite'] ? 1 : 0, PDO::PARAM_INT);
			}
			$this->updateEntryPrepared->bindValue(':id_feed', $valuesTmp['id_feed'], PDO::PARAM_INT);
			$valuesTmp['tags'] = mb_strcut($valuesTmp['tags'], 0, FreshRSS_Entry::MAX_TAGS_LENGTH, 'UTF-8');
			$valuesTmp['tags'] = safe_utf8($valuesTmp['tags']);
			$this->updateEntryPrepared->bindValue(':tags', $valuesTmp['tags']);
			$this->updateEntryPrepared->bindValue(':attributes', $attributes);

			if (static::hasNativeHex()) {
				$this->updateEntryPrepared->bindValue(':hash', $valuesTmp['hash']);
			} else {
				$valuesTmp['hashBin'] = hex2bin($valuesTmp['hash']);
				$this->updateEntryPrepared->bindValue(':hash', $valuesTmp['hashBin']);
			}
		}

		if ($this->updateEntryPrepared != false && $this->updateEntryPrepared->execute()) {
			return true;
		} else {
			$info = $this->updateEntryPrepared == false ? $this->pdo->errorInfo() : $this->updateEntryPrepared->errorInfo();
			/** @var array{id:string,guid:string,title:string,author:string,content:string,link:string,
			 * 	date:int,lastSeen:int,lastModified:int,lastUserModified:int,hash:string,
			 * 	is_read:bool|int|null,is_favorite:bool|int|null,id_feed:int,tags:string,attributes:array<string,mixed>} $valuesTmp */
			/** @var array{0:string,1:int,2:string} $info */
			if ($this->autoUpdateDb($info)) {
				return $this->updateEntry($valuesTmp);
			}
			Minz_Log::error('SQL error ' . __METHOD__ . json_encode($info)
				. ' while updating entry with GUID ' . $valuesTmp['guid'] . ' in feed ' . $valuesTmp['id_feed']);
			return false;
		}
	}

	/**
	 * Count the number of new entries in the temporary table (which have not yet been committed).
	 */
	public function countNewEntries(): int {
		$sql = <<<'SQL'
			SELECT COUNT(id) AS nb_entries FROM `_entrytmp`
			SQL;
		return $this->fetchInt($sql) ?? -1;
	}

	/**
	 * Toggle favorite marker on one or more article
	 *
	 * @todo simplify the query by removing the str_repeat. I am pretty sure
	 * there is an other way to do that.
	 *
	 * @param numeric-string|list<numeric-string> $ids
	 * @throws Throwable
	 */
	public function markFavorite(string|array $ids, bool $is_favorite = true): int|false {
		return $this->markState(is_array($ids) ? $ids : [$ids], 'is_favorite', $is_favorite);
	}

	/**
	 * Change a batch atomically, including its unread counts. A savepoint preserves a caller's transaction.
	 * @param array<numeric-string> $ids
	 * @param 'is_read'|'is_favorite' $column
	 */
	protected function markState(array $ids, string $column, bool $state): int|false {
		$ids = array_values(array_unique($ids));
		if ($ids === []) {
			return 0;
		}
		FreshRSS_UserDAO::touch();
		$hadTransaction = $this->pdo->inTransaction();
		$finished = false;
		$affected = 0;
		$feedIds = [];
		$started = $hadTransaction ? $this->pdo->exec('SAVEPOINT freshrss_entry_state') !== false : $this->pdo->beginTransaction();
		if (!$started) {
			return false;
		}
		try {
			$values = [$state ? 1 : 0, time()];
			$condition = '';
			if ($column === 'is_read') {
				$condition = 'is_read<>? AND ';
				$values[] = $state ? 1 : 0;
			}
			$chunkSize = FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER - count($values);
			for ($offset = 0, $count = count($ids); $offset < $count; $offset += $chunkSize) {
				$chunk = array_slice($ids, $offset, $chunkSize);
				$placeholders = implode(',', array_fill(0, count($chunk), '?'));
				$sql = "UPDATE `_entry` SET {$column}=?, `lastUserModified`=? WHERE {$condition}id IN ({$placeholders})";
				$stm = $this->pdo->prepare($sql);
				if ($stm === false || !$stm->execute(array_merge($values, $chunk))) {
					$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
					Minz_Log::error('SQL error ' . __METHOD__ . json_encode($info));
					return false;
				}
				$changed = $stm->rowCount();
				$affected += $changed;
				if ($column === 'is_read' && $changed > 0) {
					$stm = $this->pdo->prepare("SELECT DISTINCT id_feed FROM `_entry` WHERE id IN ({$placeholders})");
					if ($stm === false || !$stm->execute($chunk)) {
						return false;
					}
					while (($feedId = $stm->fetchColumn()) !== false) {
						$feedIds[(int)$feedId] = (int)$feedId;
					}
				}
			}
			if ($feedIds !== []) {
				sort($feedIds, SORT_NUMERIC);
				foreach (array_chunk($feedIds, FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER) as $chunk) {
					if (!$this->updateCacheUnreads(feedIds: $chunk)) {
						return false;
					}
				}
			}
			$finished = $hadTransaction ? $this->pdo->exec('RELEASE SAVEPOINT freshrss_entry_state') !== false : $this->pdo->commit();
		} finally {
			if (!$finished && $this->pdo->inTransaction()) {
				if ($hadTransaction) {
					$this->pdo->exec('ROLLBACK TO SAVEPOINT freshrss_entry_state');
					$this->pdo->exec('RELEASE SAVEPOINT freshrss_entry_state');
				} else {
					$this->pdo->rollBack();
				}
			}
		}
		if (!$finished) {
			return false;
		}
		if ($affected > 0) {
			$hook = $column === 'is_read' ? Minz_HookType::EntriesRead : Minz_HookType::EntriesFavorite;
			Minz_ExtensionManager::callHook($hook, $ids, $state);
		}
		return $affected;
	}

	/**
	 * Update the unread article cache held on every feed details.
	 * Depending on the parameters, it updates the cache on one feed, on all
	 * feeds from one category or on all feeds.
	 * @param list<int>|null $feedIds
	 */
	protected function updateCacheUnreads(?int $catId = null, ?int $feedId = null, ?array $feedIds = null): bool {
		// Help MySQL/MariaDB's optimizer with the query plan.
		$useIndex = $this->pdo->dbType() === 'mysql' ? 'USE INDEX (entry_feed_read_index)' : '';
		$where = '1=1';
		$values = [];
		if ($feedId !== null) {
			$where .= ' AND id=?';
			$values[] = $feedId;
		}
		if ($catId !== null) {
			$where .= ' AND category=?';
			$values[] = $catId;
		}
		if ($feedIds !== null) {
			if ($feedIds === []) {
				return true;
			}
			$where .= ' AND id IN (' . implode(',', array_fill(0, count($feedIds), '?')) . ')';
			$values = array_merge($values, $feedIds);
		}
		if ($this->pdo->dbType() !== 'sqlite' && $this->pdo->inTransaction()) {
			// Obtain row locks before counting, so a waiter gets a fresh snapshot after the preceding writer commits.
			$stm = $this->pdo->prepare('SELECT id FROM `_feed` WHERE ' . $where . ' ORDER BY id FOR UPDATE');
			if ($stm === false || !$stm->execute($values)) {
				return false;
			}
			$stm->closeCursor();
		}
		$sql = <<<SQL
			UPDATE `_feed` SET `cache_nbUnreads`=(SELECT COUNT(*) FROM `_entry` e {$useIndex}
				WHERE e.id_feed=`_feed`.id AND e.is_read=0) WHERE {$where}
			SQL;
		$stm = $this->pdo->prepare($sql);
		if ($stm !== false && $stm->execute($values)) {
			return true;
		}
		$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
		Minz_Log::error('SQL error ' . __METHOD__ . json_encode($info));
		return false;
	}

	/** @param list<int|string> $values */
	private function executeReadState(string $sql, array $values, ?int $catId = null, ?int $feedId = null): int|false {
		$hadTransaction = $this->pdo->inTransaction();
		$finished = false;
		if (!($hadTransaction ? $this->pdo->exec('SAVEPOINT freshrss_read_state') !== false : $this->pdo->beginTransaction())) {
			return false;
		}
		try {
			$stm = $this->pdo->prepare($sql);
			if ($stm === false || !$stm->execute($values)) {
				$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
				Minz_Log::error('SQL error ' . __METHOD__ . json_encode($info));
				return false;
			}
			$affected = $stm->rowCount();
			if ($affected > 0 && !$this->updateCacheUnreads($catId, $feedId)) {
				return false;
			}
			$finished = $hadTransaction ? $this->pdo->exec('RELEASE SAVEPOINT freshrss_read_state') !== false : $this->pdo->commit();
			return $finished ? $affected : false;
		} finally {
			if (!$finished && $this->pdo->inTransaction()) {
				if ($hadTransaction) {
					$this->pdo->exec('ROLLBACK TO SAVEPOINT freshrss_read_state');
					$this->pdo->exec('RELEASE SAVEPOINT freshrss_read_state');
				} else {
					$this->pdo->rollBack();
				}
			}
		}
	}

	/**
	 * Toggle the read marker on one or more article.
	 * Then the cache is updated.
	 *
	 * @param numeric-string|list<numeric-string> $ids
	 * @return int|false affected rows
	 * @throws Throwable
	 */
	public function markRead(array|string $ids, bool $is_read = true): int|false {
		return $this->markState(is_array($ids) ? $ids : [$ids], 'is_read', $is_read);
	}

	/**
	 * Mark all entries as read depending on parameters.
	 * If $onlyFavorites is true, it is used when the user mark as read in
	 * the favorite pseudo-category.
	 * If $priorityMin is greater than 0, it is used when the user mark as
	 * read in the main feed pseudo-category.
	 * Then the cache is updated.
	 *
	 * If $idMax equals 0, a deprecated debug message is logged
	 *
	 * @param numeric-string $idMax fail safe article ID
	 * @return int|false affected rows
	 */
	public function markReadEntries(string $idMax = '0', bool $onlyFavorites = false, ?int $priorityMin = null, ?int $priorityMax = null,
		?FreshRSS_BooleanSearch $filters = null, int $state = 0, bool $is_read = true) {
		FreshRSS_UserDAO::touch();
		if ($idMax == '0') {
			$idMax = uTimeString();
			Minz_Log::debug('Calling markReadEntries(0) is deprecated!');
		}

		$sql = <<<'SQL'
			UPDATE `_entry` SET is_read = ?, `lastUserModified`=? WHERE is_read <> ? AND id <= ?
			SQL;
		$values = [$is_read ? 1 : 0, time(), $is_read ? 1 : 0, $idMax];
		if ($onlyFavorites) {
			$sql .= "\n" . <<<'SQL'
				AND is_favorite=1
				SQL;
		}
		if ($priorityMin !== null || $priorityMax !== null) {
			$sql .= "\n" . <<<'SQL'
				AND id_feed IN (SELECT f.id FROM `_feed` f WHERE 1=1
				SQL;
			if ($priorityMin !== null) {
				$sql .= "\n" . <<<'SQL'
					AND f.priority >= ?
					SQL;
				$values[] = $priorityMin;
			}
			if ($priorityMax !== null) {
				$sql .= "\n" . <<<'SQL'
					AND f.priority < ?
					SQL;
				$values[] = $priorityMax;
			}
			$sql .= "\n" . <<<'SQL'
				)
				SQL;
		}

		[$searchValues, $search] = $this->sqlListEntriesWhere(alias: '', state: $state, filters: $filters);

		return $this->executeReadState($sql . $search, array_merge($values, $searchValues));
	}

	/**
	 * Mark all the articles in a category as read.
	 * There is a fail safe to prevent to mark as read articles that are
	 * loaded during the mark as read action. Then the cache is updated.
	 *
	 * If $idMax equals 0, a deprecated debug message is logged
	 *
	 * @param int $id category ID
	 * @param numeric-string $idMax fail safe article ID
	 * @param int $priorityMin minimum feed priority to include
	 * @return int|false affected rows
	 */
	public function markReadCat(int $id, string $idMax = '0', int $priorityMin = FreshRSS_Feed::PRIORITY_CATEGORY,
		?FreshRSS_BooleanSearch $filters = null, int $state = 0, bool $is_read = true): int|false {
		FreshRSS_UserDAO::touch();
		if ($idMax == '0') {
			$idMax = uTimeString();
			Minz_Log::debug('Calling markReadCat(0) is deprecated!');
		}

		$sql = <<<'SQL'
			UPDATE `_entry`
			SET is_read = ?, `lastUserModified` = ?
			WHERE is_read <> ? AND id <= ?
			AND id_feed IN (SELECT f.id FROM `_feed` f WHERE f.category=? AND f.priority >= ? AND f.priority < ?)
			SQL;
		$values = [$is_read ? 1 : 0, time(), $is_read ? 1 : 0, $idMax, $id, $priorityMin, FreshRSS_Feed::PRIORITY_IMPORTANT];

		[$searchValues, $search] = $this->sqlListEntriesWhere(alias: '', state: $state, filters: $filters);

		return $this->executeReadState($sql . $search, array_merge($values, $searchValues), catId: $id);
	}

	/**
	 * Mark all the articles in a feed as read.
	 * There is a fail safe to prevent to mark as read articles that are
	 * loaded during the mark as read action. Then the cache is updated.
	 *
	 * If $idMax equals 0, a deprecated debug message is logged
	 *
	 * @param int $id_feed feed ID
	 * @param numeric-string $idMax fail safe article ID
	 * @return int|false affected rows
	 */
	public function markReadFeed(int $id_feed, string $idMax = '0', ?FreshRSS_BooleanSearch $filters = null, int $state = 0, bool $is_read = true): int|false {
		FreshRSS_UserDAO::touch();
		if ($idMax == '0') {
			$idMax = uTimeString();
			Minz_Log::debug('Calling markReadFeed(0) is deprecated!');
		}

		$sql = <<<'SQL'
			UPDATE `_entry`
			SET is_read=?, `lastUserModified`=?
			WHERE id_feed=? AND is_read <> ? AND id <= ?
			SQL;
		$values = [$is_read ? 1 : 0, time(), $id_feed, $is_read ? 1 : 0, $idMax];

		[$searchValues, $search] = $this->sqlListEntriesWhere(alias: '', state: $state, filters: $filters);

		return $this->executeReadState($sql . $search, array_merge($values, $searchValues), feedId: $id_feed);
	}

	/**
	 * Mark all the articles in a tag as read.
	 * @param int $id tag ID, or empty for targeting any tag
	 * @param numeric-string $idMax max article ID
	 * @return int|false affected rows
	 */
	public function markReadTag(int $id = 0, string $idMax = '0', ?FreshRSS_BooleanSearch $filters = null,
		int $state = 0, bool $is_read = true): int|false {
		FreshRSS_UserDAO::touch();
		if ($idMax === '0') {
			$idMax = uTimeString();
			Minz_Log::debug('Calling markReadTag(0) is deprecated!');
		}
		$tagCondition = $id === 0 ? '' : 'WHERE et.id_tag=?';
		$sql = <<<SQL
			UPDATE `_entry` SET is_read=?, `lastUserModified`=? WHERE is_read<>? AND id<=?
			AND id IN (SELECT et.id_entry FROM `_entrytag` et {$tagCondition})
			SQL;
		$values = [$is_read ? 1 : 0, time(), $is_read ? 1 : 0, $idMax];
		if ($id !== 0) {
			$values[] = $id;
		}
		[$searchValues, $search] = $this->sqlListEntriesWhere(alias: '', state: $state, filters: $filters);
		return $this->executeReadState($sql . $search, array_merge($values, $searchValues));
	}

	/**
	 * Remember to call updateCachedValues($id_feed) or updateCachedValues() just after.
	 * @param array<string,bool|int|string> $options
	 */
	public function cleanOldEntries(int $id_feed, array $options = []): int|false {
		$sql = <<<'SQL'
			DELETE FROM `_entry` WHERE id_feed = :id_feed1
			SQL;	//No alias for MySQL / MariaDB
		$params = [];
		$params[':id_feed1'] = $id_feed;

		//==Exclusions==
		if (!empty($options['keep_favourites'])) {
			$sql .= "\n" . <<<'SQL'
				AND is_favorite = 0
				SQL;
		}
		if (!empty($options['keep_unreads'])) {
			$sql .= "\n" . <<<'SQL'
				AND is_read = 1
				SQL;
		}
		if (!empty($options['keep_labels'])) {
			$sql .= "\n" . <<<'SQL'
				AND NOT EXISTS (SELECT 1 FROM `_entrytag` WHERE id_entry = id)
				SQL;
		}
		if (!empty($options['keep_min']) && $options['keep_min'] > 0) {
			//Double SELECT for MySQL workaround ERROR 1093 (HY000)
			$sql .= "\n" . <<<'SQL'
				AND `lastSeen` < (SELECT `lastSeen`
				FROM (SELECT e2.`lastSeen` FROM `_entry` e2 WHERE e2.id_feed = :id_feed2
				ORDER BY e2.`lastSeen` DESC LIMIT 1 OFFSET :keep_min) last_seen2)
				SQL;
			$params[':id_feed2'] = $id_feed;
			$params[':keep_min'] = (int)$options['keep_min'];
		}
		//Keep at least the articles seen at the last refresh
		$sql .= "\n" . <<<'SQL'
			AND `lastSeen` < (SELECT maxlastseen
			FROM (SELECT MAX(e3.`lastSeen`) AS maxlastseen FROM `_entry` e3 WHERE e3.id_feed = :id_feed3) last_seen3)
			SQL;
		$params[':id_feed3'] = $id_feed;

		//==Inclusions==
		$sql .= "\n" . <<<'SQL'
			AND (1=0
			SQL;
		if (!empty($options['keep_period']) && is_string($options['keep_period'])) {
			$sql .= "\n" . <<<'SQL'
				OR `lastSeen` < :max_last_seen
				SQL;
			$now = new DateTime('now');
			$now->sub(new DateInterval($options['keep_period']));
			$params[':max_last_seen'] = $now->format('U');
		}
		if (!empty($options['keep_max']) && $options['keep_max'] > 0) {
			$sql .= "\n" . <<<'SQL'
				OR `lastSeen` <= (SELECT `lastSeen`
				FROM (SELECT e4.`lastSeen` FROM `_entry` e4 WHERE e4.id_feed = :id_feed4
				ORDER BY e4.`lastSeen` DESC LIMIT 1 OFFSET :keep_max) last_seen4)
				SQL;
			$params[':id_feed4'] = $id_feed;
			$params[':keep_max'] = (int)$options['keep_max'];
		}
		$sql .= "\n" . <<<'SQL'
			)
			SQL;

		$stm = $this->pdo->prepare($sql);

		if ($stm !== false && $stm->execute($params)) {
			return $stm->rowCount();
		} else {
			$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
			/** @var array{0:string,1:int,2:string} $info */
			if ($this->autoUpdateDb($info)) {
				return $this->cleanOldEntries($id_feed, $options);
			}
			Minz_Log::error(__METHOD__ . ' error:' . json_encode($info));
			return false;
		}
	}

	/**
	 * @param 'ASC'|'DESC' $order
	 * @return Traversable<array{id:string,guid:string,title:string,author:string,content:string,link:string,
	 * 	date:int,lastSeen:int,lastModified:int,lastUserModified:int,
	 *	hash:string,is_read:bool,is_favorite:bool,id_feed:int,tags:string,attributes:?string}>
	 */
	public function selectAll(string $order = 'ASC', int $limit = -1, int $offset = 0): Traversable {
		$content = static::isCompressed() ? 'UNCOMPRESS(content_bin) AS content' : 'content';
		$hash = static::sqlHexEncode('hash');
		$order = in_array($order, ['ASC', 'DESC'], true) ? $order : 'ASC';
		$sqlLimit = static::sqlLimit($limit, $offset);
		$sql = <<<SQL
			SELECT id, guid, title, author, {$content}, link, date, `lastSeen`, `lastModified`, `lastUserModified`,
				{$hash} AS hash, is_read, is_favorite, id_feed, tags, attributes
			FROM `_entry`
			ORDER BY id {$order} {$sqlLimit}
			SQL;
		$stm = $this->pdo->query($sql);
		if ($stm !== false) {
			while (is_array($row = $stm->fetch(PDO::FETCH_ASSOC))) {
				/** @var array{id:string,guid:string,title:string,author:string,content:string,link:string,date:int,lastSeen:int,lastModified:int,lastUserModified:int,
				 *	hash:string,is_read:bool,is_favorite:bool,id_feed:int,tags:string,attributes:?string} $row */
				yield $row;
			}
		} else {
			$info = $this->pdo->errorInfo();
			/** @var array{0:string,1:int,2:string} $info */
			if ($this->autoUpdateDb($info)) {
				yield from $this->selectAll($order, $limit, $offset);
			} else {
				Minz_Log::error(__METHOD__ . ' error: ' . json_encode($info));
			}
		}
	}

	public function searchByGuid(int $id_feed, string $guid): ?FreshRSS_Entry {
		$content = static::isCompressed() ? 'UNCOMPRESS(content_bin) AS content' : 'content';
		$contentLength = 'LENGTH(' . (static::isCompressed() ? 'content_bin' : 'content') . ') AS content_length';
		$hash = static::sqlHexEncode('hash');
		$sql = <<<SQL
			SELECT id, guid, title, author, {$content}, link, date, `lastSeen`, `lastModified`, `lastUserModified`,
				{$hash} AS hash, is_read, is_favorite, id_feed, tags, attributes,
				{$contentLength}
			FROM `_entry` WHERE id_feed=:id_feed AND guid=:guid
			SQL;
		$res = $this->fetchAssoc($sql, [':id_feed' => $id_feed, ':guid' => $guid]);
		/** @var list<array{id:string,guid:string,title:string,author:string,content:string,link:string,date:int,
		 *		lastSeen:int,lastModified:int,lastUserModified:int,hash:string,is_read:int,is_favorite:int,id_feed:int,
		 * 		tags:string,attributes:?string,
		 * 		content_length:int}> $res */
		return isset($res[0]) ? FreshRSS_Entry::fromArray($res[0]) : null;
	}

	public function searchById(string $id): ?FreshRSS_Entry {
		$content = static::isCompressed() ? 'UNCOMPRESS(content_bin) AS content' : 'content';
		$contentLength = 'LENGTH(' . (static::isCompressed() ? 'content_bin' : 'content') . ') AS content_length';
		$hash = static::sqlHexEncode('hash');
		$sql = <<<SQL
			SELECT id, guid, title, author, {$content}, link, date, `lastSeen`, `lastModified`, `lastUserModified`,
				{$hash} AS hash, is_read, is_favorite, id_feed, tags, attributes,
				{$contentLength}
			FROM `_entry` WHERE id=:id
			SQL;
		$res = $this->fetchAssoc($sql, [':id' => $id]);
		/** @var list<array{id:string,guid:string,title:string,author:string,content:string,link:string,date:int,
		 *		lastSeen:int,lastModified:int,lastUserModified:int,hash:string,is_read:int,is_favorite:int,id_feed:int,
		 * 		tags:string,attributes:?string,
		 * 		content_length:int}> $res */
		return isset($res[0]) ? FreshRSS_Entry::fromArray($res[0]) : null;
	}

	public function searchIdByGuid(int $id_feed, string $guid): ?string {
		$sql = <<<'SQL'
			SELECT id FROM `_entry` WHERE id_feed=:id_feed AND guid=:guid
			SQL;
		$res = $this->fetchColumn($sql, 0, [':id_feed' => $id_feed, ':guid' => $guid]);
		return empty($res[0]) ? null : (string)($res[0]);
	}

	/** @return array{0:list<int|string>,1:string} */
	public static function sqlBooleanSearch(string $alias, FreshRSS_BooleanSearch $filters, int $level = 0): array {
		$search = '';
		$values = [];

		$isOpen = false;
		foreach ($filters->searches() as $filter) {
			if ($filter == null) {
				continue;
			}
			if ($filter instanceof FreshRSS_BooleanSearch) {
				// BooleanSearches are combined by AND (default) or OR (special case) operator and are recursive
				[$filterValues, $filterSearch] = self::sqlBooleanSearch($alias, $filter, $level + 1);
				$filterSearch = trim($filterSearch);

				if ($filterSearch !== '') {
					if ($search !== '') {
						$search = rtrim($search) . ' ' . $filter->operator();
					} elseif (in_array($filter->operator(), ['AND NOT', 'OR NOT'], true)) {
						// Special case if we start with a negation (there is already the default AND before)
						$search .= ' NOT';
					}
					$search .= ' (' . $filterSearch . ') ';
					$values = array_merge($values, $filterValues);
				}
				continue;
			}
			// Searches are combined by OR and are not recursive
			$sub_search = '';
			if ($filter->getEntryIds() !== null) {
				$sub_search .= 'AND ' . $alias . 'id IN (';
				foreach ($filter->getEntryIds() as $entry_id) {
					$sub_search .= '?,';
					$values[] = $entry_id;
				}
				$sub_search = rtrim($sub_search, ',');
				$sub_search .= ') ';
			}
			if ($filter->getNotEntryIds() !== null) {
				$sub_search .= 'AND ' . $alias . 'id NOT IN (';
				foreach ($filter->getNotEntryIds() as $entry_id) {
					$sub_search .= '?,';
					$values[] = $entry_id;
				}
				$sub_search = rtrim($sub_search, ',');
				$sub_search .= ') ';
			}

			if ($filter->getMinDate() !== null) {
				$sub_search .= 'AND ' . $alias . 'id >= ? ';
				$values[] = "{$filter->getMinDate()}000000";
			}
			if ($filter->getMaxDate() !== null) {
				$sub_search .= 'AND ' . $alias . 'id <= ? ';
				$values[] = "{$filter->getMaxDate()}000000";
			}
			if ($filter->getMinPubdate() !== null) {
				$sub_search .= 'AND ' . $alias . 'date >= ? ';
				$values[] = $filter->getMinPubdate();
			}
			if ($filter->getMaxPubdate() !== null) {
				$sub_search .= 'AND ' . $alias . 'date <= ? ';
				$values[] = $filter->getMaxPubdate();
			}
			if ($filter->getMinModifiedDate() !== null) {
				$sub_search .= 'AND ' . $alias . '`lastModified` >= ? ';
				$values[] = $filter->getMinModifiedDate();
			}
			if ($filter->getMaxModifiedDate() !== null) {
				$sub_search .= 'AND COALESCE(' . $alias . '`lastModified`, 0) <= ? ';
				$values[] = $filter->getMaxModifiedDate();
			}
			if ($filter->getMinUserdate() !== null) {
				$sub_search .= 'AND ' . $alias . '`lastUserModified` >= ? ';
				$values[] = $filter->getMinUserdate();
			}
			if ($filter->getMaxUserdate() !== null) {
				$sub_search .= 'AND ' . $alias . '`lastUserModified` <= ? ';
				$values[] = $filter->getMaxUserdate();
			}

			//Negation of date intervals must be combined by OR
			if ($filter->getNotMinDate() !== null || $filter->getNotMaxDate() !== null) {
				$sub_search .= 'AND (';
				if ($filter->getNotMinDate() !== null) {
					$sub_search .= $alias . 'id < ?';
					$values[] = "{$filter->getNotMinDate()}000000";
					if ($filter->getNotMaxDate()) {
						$sub_search .= ' OR ';
					}
				}
				if ($filter->getNotMaxDate() !== null) {
					$sub_search .= $alias . 'id > ?';
					$values[] = "{$filter->getNotMaxDate()}000000";
				}
				$sub_search .= ') ';
			}
			if ($filter->getNotMinPubdate() !== null || $filter->getNotMaxPubdate() !== null) {
				$sub_search .= 'AND (';
				if ($filter->getNotMinPubdate() !== null) {
					$sub_search .= $alias . 'date < ?';
					$values[] = $filter->getNotMinPubdate();
					if ($filter->getNotMaxPubdate()) {
						$sub_search .= ' OR ';
					}
				}
				if ($filter->getNotMaxPubdate() !== null) {
					$sub_search .= $alias . 'date > ?';
					$values[] = $filter->getNotMaxPubdate();
				}
				$sub_search .= ') ';
			}
			if ($filter->getNotMinModifiedDate() !== null || $filter->getNotMaxModifiedDate() !== null) {
				$sub_search .= 'AND (';
				if ($filter->getNotMinModifiedDate() !== null) {
					$sub_search .= 'COALESCE(' . $alias . '`lastModified`, 0) < ?';
					$values[] = $filter->getNotMinModifiedDate();
					if ($filter->getNotMaxModifiedDate()) {
						$sub_search .= ' OR ';
					}
				}
				if ($filter->getNotMaxModifiedDate() !== null) {
					$sub_search .= $alias . '`lastModified` > ?';
					$values[] = $filter->getNotMaxModifiedDate();
				}
				$sub_search .= ') ';
			}
			if ($filter->getNotMinUserdate() !== null || $filter->getNotMaxUserdate() !== null) {
				$sub_search .= 'AND (';
				if ($filter->getNotMinUserdate() !== null) {
					$sub_search .= 'COALESCE(' . $alias . '`lastUserModified`, 0) < ?';
					$values[] = $filter->getNotMinUserdate();
					if ($filter->getNotMaxUserdate()) {
						$sub_search .= ' OR ';
					}
				}
				if ($filter->getNotMaxUserdate() !== null) {
					$sub_search .= $alias . '`lastUserModified` > ?';
					$values[] = $filter->getNotMaxUserdate();
				}
				$sub_search .= ') ';
			}

			if ($filter->getFeedIds() !== null) {
				$sub_search .= 'AND ' . $alias . 'id_feed IN (';
				foreach ($filter->getFeedIds() as $feed_id) {
					$sub_search .= '?,';
					$values[] = $feed_id;
				}
				$sub_search = rtrim($sub_search, ',');
				$sub_search .= ') ';
			}
			if ($filter->getNotFeedIds() !== null) {
				$sub_search .= 'AND ' . $alias . 'id_feed NOT IN (';
				foreach ($filter->getNotFeedIds() as $feed_id) {
					$sub_search .= '?,';
					$values[] = $feed_id;
				}
				$sub_search = rtrim($sub_search, ',');
				$sub_search .= ') ';
			}

			if ($filter->getCategoryIds() !== null) {
				$sub_search .= 'AND ' . $alias . 'id_feed IN (SELECT f.id FROM `_feed` f WHERE f.category IN (';
				foreach ($filter->getCategoryIds() as $category_id) {
					$sub_search .= '?,';
					$values[] = $category_id;
				}
				$sub_search = rtrim($sub_search, ',');
				$sub_search .= ')) ';
			}
			if ($filter->getNotCategoryIds() !== null) {
				$sub_search .= 'AND ' . $alias . 'id_feed NOT IN (SELECT f.id FROM `_feed` f WHERE f.category IN (';
				foreach ($filter->getNotCategoryIds() as $category_id) {
					$sub_search .= '?,';
					$values[] = $category_id;
				}
				$sub_search = rtrim($sub_search, ',');
				$sub_search .= ')) ';
			}

			if ($filter->getLabelIds() !== null) {
				foreach ($filter->getLabelIds() as $label_ids) {
					if ($label_ids === '*') {
						$sub_search .= 'AND EXISTS (SELECT et.id_tag FROM `_entrytag` et WHERE et.id_entry = ' . $alias . 'id) ';
					} else {
						$sub_search .= 'AND ' . $alias . 'id IN (SELECT et.id_entry FROM `_entrytag` et WHERE et.id_tag IN (';
						foreach ($label_ids as $label_id) {
							$sub_search .= '?,';
							$values[] = $label_id;
						}
						$sub_search = rtrim($sub_search, ',');
						$sub_search .= ')) ';
					}
				}
			}
			if ($filter->getNotLabelIds() !== null) {
				foreach ($filter->getNotLabelIds() as $label_ids) {
					if ($label_ids === '*') {
						$sub_search .= 'AND NOT EXISTS (SELECT et.id_tag FROM `_entrytag` et WHERE et.id_entry = ' . $alias . 'id) ';
					} else {
						$sub_search .= 'AND ' . $alias . 'id NOT IN (SELECT et.id_entry FROM `_entrytag` et WHERE et.id_tag IN (';
						foreach ($label_ids as $label_id) {
							$sub_search .= '?,';
							$values[] = $label_id;
						}
						$sub_search = rtrim($sub_search, ',');
						$sub_search .= ')) ';
					}
				}
			}

			if ($filter->getLabelNames() !== null) {
				foreach ($filter->getLabelNames() as $label_names) {
					$sub_search .= 'AND ' . $alias . 'id IN (SELECT et.id_entry FROM `_entrytag` et, `_tag` t WHERE et.id_tag = t.id AND t.name IN (';
					foreach ($label_names as $label_name) {
						$sub_search .= '?,';
						$values[] = $label_name;
					}
					$sub_search = rtrim($sub_search, ',');
					$sub_search .= ')) ';
				}
			}
			if ($filter->getNotLabelNames() !== null) {
				foreach ($filter->getNotLabelNames() as $label_names) {
					$sub_search .= 'AND ' . $alias . 'id NOT IN (SELECT et.id_entry FROM `_entrytag` et, `_tag` t WHERE et.id_tag = t.id AND t.name IN (';
					foreach ($label_names as $label_name) {
						$sub_search .= '?,';
						$values[] = $label_name;
					}
					$sub_search = rtrim($sub_search, ',');
					$sub_search .= ')) ';
				}
			}

			if ($filter->getAuthor() !== null) {
				foreach ($filter->getAuthor() as $author) {
					$sub_search .= 'AND ' . $alias . 'author LIKE ? ';
					$values[] = "%{$author}%";
				}
			}
			if ($filter->getAuthorRegex() !== null) {
				foreach ($filter->getAuthorRegex() as $author) {
					$sub_search .= 'AND ' . static::sqlRegex("REPLACE({$alias}author, ';', '\n')", $author, $values) . ' ';
				}
			}
			if ($filter->getIntitle() !== null) {
				foreach ($filter->getIntitle() as $title) {
					$sub_search .= 'AND ' . $alias . 'title LIKE ? ';
					$values[] = "%{$title}%";
				}
			}
			if ($filter->getIntitleRegex() !== null) {
				foreach ($filter->getIntitleRegex() as $title) {
					$sub_search .= 'AND ' . static::sqlRegex($alias . 'title', $title, $values) . ' ';
				}
			}
			if ($filter->getIntext() !== null) {
				if (static::isCompressed()) {	// MySQL-only
					foreach ($filter->getIntext() as $content) {
						$sub_search .= "AND UNCOMPRESS({$alias}content_bin) LIKE ? ";
						$values[] = "%{$content}%";
					}
				} else {
					foreach ($filter->getIntext() as $content) {
						$sub_search .= 'AND ' . $alias . 'content LIKE ? ';
						$values[] = "%{$content}%";
					}
				}
			}
			if ($filter->getIntextRegex() !== null) {
				if (static::isCompressed()) {	// MySQL-only
					foreach ($filter->getIntextRegex() as $content) {
						$sub_search .= 'AND ' . static::sqlRegex("UNCOMPRESS({$alias}content_bin)", $content, $values) . ' ';
					}
				} else {
					foreach ($filter->getIntextRegex() as $content) {
						$sub_search .= 'AND ' . static::sqlRegex($alias . 'content', $content, $values) . ' ';
					}
				}
			}
			if ($filter->getTags() !== null) {
				foreach ($filter->getTags() as $tag) {
					$sub_search .= 'AND ' . static::sqlConcat('TRIM(' . $alias . 'tags) ', " ' #'") . ' LIKE ? ';
					$values[] = "%{$tag} #%";
				}
			}
			if ($filter->getTagsRegex() !== null) {
				foreach ($filter->getTagsRegex() as $tag) {
					$sub_search .= 'AND ' . static::sqlRegex("REPLACE(REPLACE({$alias}tags, ' #', '#'), '#', '\n')", $tag, $values) . ' ';
				}
			}
			if ($filter->getInurl() !== null) {
				foreach ($filter->getInurl() as $url) {
					$sub_search .= 'AND ' . $alias . 'link LIKE ? ';
					$values[] = "%{$url}%";
				}
			}
			if ($filter->getInurlRegex() !== null) {
				foreach ($filter->getInurlRegex() as $url) {
					$sub_search .= 'AND ' . static::sqlRegex($alias . 'link', $url, $values) . ' ';
				}
			}

			if ($filter->getNotAuthor() !== null) {
				foreach ($filter->getNotAuthor() as $author) {
					$sub_search .= 'AND ' . $alias . 'author NOT LIKE ? ';
					$values[] = "%{$author}%";
				}
			}
			if ($filter->getNotAuthorRegex() !== null) {
				foreach ($filter->getNotAuthorRegex() as $author) {
					$sub_search .= 'AND NOT ' . static::sqlRegex("REPLACE({$alias}author, ';', '\n')", $author, $values) . ' ';
				}
			}
			if ($filter->getNotIntitle() !== null) {
				foreach ($filter->getNotIntitle() as $title) {
					$sub_search .= 'AND ' . $alias . 'title NOT LIKE ? ';
					$values[] = "%{$title}%";
				}
			}
			if ($filter->getNotIntitleRegex() !== null) {
				foreach ($filter->getNotIntitleRegex() as $title) {
					$sub_search .= 'AND NOT ' . static::sqlRegex($alias . 'title', $title, $values) . ' ';
				}
			}
			if ($filter->getNotIntext() !== null) {
				if (static::isCompressed()) {	// MySQL-only
					foreach ($filter->getNotIntext() as $content) {
						$sub_search .= "AND UNCOMPRESS({$alias}content_bin) NOT LIKE ? ";
						$values[] = "%{$content}%";
					}
				} else {
					foreach ($filter->getNotIntext() as $content) {
						$sub_search .= 'AND ' . $alias . 'content NOT LIKE ? ';
						$values[] = "%{$content}%";
					}
				}
			}
			if ($filter->getNotIntextRegex() !== null) {
				if (static::isCompressed()) {	// MySQL-only
					foreach ($filter->getNotIntextRegex() as $content) {
						$sub_search .= 'AND NOT ' . static::sqlRegex("UNCOMPRESS({$alias}content_bin)", $content, $values) . ' ';
					}
				} else {
					foreach ($filter->getNotIntextRegex() as $content) {
						$sub_search .= 'AND NOT ' . static::sqlRegex($alias . 'content', $content, $values) . ' ';
					}
				}
			}
			if ($filter->getNotTags() !== null) {
				foreach ($filter->getNotTags() as $tag) {
					$sub_search .= 'AND ' . static::sqlConcat('TRIM(' . $alias . 'tags) ', " ' #'") . ' NOT LIKE ? ';
					$values[] = "%{$tag} #%";
				}
			}
			if ($filter->getNotTagsRegex() !== null) {
				foreach ($filter->getNotTagsRegex() as $tag) {
					$sub_search .= 'AND NOT ' . static::sqlRegex("REPLACE(REPLACE({$alias}tags, ' #', '#'), '#', '\n')", $tag, $values) . ' ';
				}
			}
			if ($filter->getNotInurl() !== null) {
				foreach ($filter->getNotInurl() as $url) {
					$sub_search .= 'AND ' . $alias . 'link NOT LIKE ? ';
					$values[] = "%{$url}%";
				}
			}
			if ($filter->getNotInurlRegex() !== null) {
				foreach ($filter->getNotInurlRegex() as $url) {
					$sub_search .= 'AND NOT ' . static::sqlRegex($alias . 'link', $url, $values) . ' ';
				}
			}

			if ($filter->getSearch() !== null) {
				foreach ($filter->getSearch() as $search_value) {
					if (static::isCompressed()) {	// MySQL-only
						$sub_search .= "AND CONCAT({$alias}title, '\\n', UNCOMPRESS({$alias}content_bin)) LIKE ? ";
						$values[] = "%{$search_value}%";
					} else {
						$sub_search .= 'AND (' . $alias . 'title LIKE ? OR ' . $alias . 'content LIKE ?) ';
						$values[] = "%{$search_value}%";
						$values[] = "%{$search_value}%";
					}
				}
			}
			if ($filter->getSearchRegex() !== null) {
				foreach ($filter->getSearchRegex() as $search_value) {
					if (static::isCompressed()) {	// MySQL-only
						$sub_search .= 'AND (' . static::sqlRegex($alias . 'title', $search_value, $values) .
							' OR ' . static::sqlRegex("UNCOMPRESS({$alias}content_bin)", $search_value, $values) . ') ';
					} else {
						$sub_search .= 'AND (' . static::sqlRegex($alias . 'title', $search_value, $values) .
							' OR ' . static::sqlRegex($alias . 'content', $search_value, $values) . ') ';
					}
				}
			}
			if ($filter->getNotSearch() !== null) {
				foreach ($filter->getNotSearch() as $search_value) {
					if (static::isCompressed()) {	// MySQL-only
						$sub_search .= "AND CONCAT({$alias}title, '\\n', UNCOMPRESS({$alias}content_bin)) NOT LIKE ? ";
						$values[] = "%{$search_value}%";
					} else {
						$sub_search .= 'AND ' . $alias . 'title NOT LIKE ? AND ' . $alias . 'content NOT LIKE ? ';
						$values[] = "%{$search_value}%";
						$values[] = "%{$search_value}%";
					}
				}
			}
			if ($filter->getNotSearchRegex() !== null) {
				foreach ($filter->getNotSearchRegex() as $search_value) {
					if (static::isCompressed()) {	// MySQL-only
						$sub_search .= 'AND NOT ' . static::sqlRegex($alias . 'title', $search_value, $values) .
							' AND NOT ' . static::sqlRegex("UNCOMPRESS({$alias}content_bin)", $search_value, $values) . ' ';
					} else {
						$sub_search .= 'AND NOT ' . static::sqlRegex($alias . 'title', $search_value, $values) .
							' AND NOT ' . static::sqlRegex($alias . 'content', $search_value, $values) . ' ';
					}
				}
			}

			if ($sub_search != '') {
				if ($isOpen) {
					$search .= ' OR ';
				} else {
					$isOpen = true;
				}
				// Remove superfluous leading 'AND '
				$search .= '(' . trim(substr($sub_search, 4)) . ')';
			}
		}

		return [ $values, $search ];
	}

	/**
	 * @param numeric-string $id_min
	 * @param numeric-string $id_max
	 * @param 'id'|'c.name'|'date'|'f.name'|'lastModified'|'lastUserModified'|'length'|'link'|'rand'|'title' $sort
	 * @param 'ASC'|'DESC' $order
	 * @param numeric-string $continuation_id
	 * @param list<string|int> $continuation_values
	 * @param 'id'|'date'|'link'|'title' $secondary_sort
	 * @param 'ASC'|'DESC' $secondary_sort_order
	 * @return array{0:list<int|string>,1:string}
	 */
	protected function sqlListEntriesWhere(string $alias = '', int $state = FreshRSS_Entry::STATE_ALL, ?FreshRSS_BooleanSearch $filters = null,
		string $id_min = '0', string $id_max = '0', string $sort = 'id', string $order = 'DESC',
		string $continuation_id = '0', array $continuation_values = [],
		string $secondary_sort = 'id', string $secondary_sort_order = 'DESC'): array {
		$search = ' ';
		$values = [];
		if ($state & FreshRSS_Entry::STATE_ANDS) {
			if ($state & FreshRSS_Entry::STATE_NOT_READ) {
				if (!($state & FreshRSS_Entry::STATE_READ)) {
					$search .= 'AND (' . $alias . 'is_read=0) ';
				}
			} elseif ($state & FreshRSS_Entry::STATE_READ) {
				$search .= 'AND (' . $alias . 'is_read=1) ';
			}
			if ($state & FreshRSS_Entry::STATE_FAVORITE) {
				if (!($state & FreshRSS_Entry::STATE_NOT_FAVORITE)) {
					$search .= 'AND (' . $alias . 'is_favorite=1) ';
				}
			} elseif ($state & FreshRSS_Entry::STATE_NOT_FAVORITE) {
				$search .= 'AND (' . $alias . 'is_favorite=0) ';
			}
		}
		if ($state & FreshRSS_Entry::STATE_ORS) {
			if (trim($search) === '') {
				$search = 'AND (1=0) ';
			}
			if ($state & FreshRSS_Entry::STATE_OR_NOT_READ) {
				$search = rtrim($search, ') ');
				$search .= ' OR ' . $alias . 'is_read=0) ';
			}
			if ($state & FreshRSS_Entry::STATE_OR_FAVORITE) {
				$search = rtrim($search, ') ');
				$search .= ' OR ' . $alias . 'is_favorite=1) ';
			}
		}

		if (!ctype_digit($id_min)) {
			$id_min = '0';
		}
		if (!ctype_digit($id_max)) {
			$id_max = '0';
		}
		if (!ctype_digit($continuation_id)) {
			$continuation_id = '0';
		}

		if ($continuation_id !== '0' && $sort === 'id') {
			if ($order === 'ASC') {
				$id_min = $id_min === '0' ? $continuation_id : max($id_min, $continuation_id);
			} else {
				$id_max = $id_max === '0' ? $continuation_id : min($id_max, $continuation_id);
			}
		}

		if ($id_max !== '0') {
			$search .= 'AND ' . $alias . 'id <= ? ';
			$values[] = $id_max;
		}
		if ($id_min !== '0') {
			$search .= 'AND ' . $alias . 'id >= ? ';
			$values[] = $id_min;
		}

		if ($continuation_id !== '0' && in_array($sort, ['c.name', 'date', 'f.name', 'lastModified', 'lastUserModified', 'length', 'link', 'title'], true)) {
			$sign = $order === 'ASC' ? '>' : '<';
			$sign2 = $secondary_sort_order === 'ASC' ? '>' : '<';
			$orderBy = match ($sort) {
				'c.name' => 'c.name',
				'date' => $alias . 'date',
				'f.name' => 'f.name',
				'lastModified' => $alias . '`lastModified`',
				'lastUserModified' => $alias . '`lastUserModified`',
				'length' => 'LENGTH(' . $alias . (static::isCompressed() ? 'content_bin' : 'content') . ')',
				'link' => $alias . 'link',
				'title' => $alias . 'title',
			};
			$orderBy2 = match ($secondary_sort) {
				'id' => $alias . 'id',
				'date' => $alias . 'date',
				'link' => $alias . 'link',
				'title' => $alias . 'title',
			};
			// Keyset pagination (Compatibility syntax due to poor performance of tuple syntax in MySQL https://bugs.mysql.com/bug.php?id=104128)
			if ($sort === 'c.name') {
				// Includes the feed-name sort and a user secondary sort
				$search .= "AND ((c.name {$sign} ?) OR (c.name = ? AND f.name {$sign} ?) OR (c.name = ? AND f.name = ? AND {$orderBy2} {$sign2} ?) " .
					"OR (c.name = ? AND f.name = ? AND {$orderBy2} = ? AND {$alias}id {$sign}= ?)) ";
				$values[] = $continuation_values[0];	// c.name (primary sort)
				$values[] = $continuation_values[0];	// c.name (primary sort)
				$values[] = $continuation_values[1];	// f.name (internal secondary sort)
				$values[] = $continuation_values[0];	// c.name (primary sort)
				$values[] = $continuation_values[1];	// f.name (internal secondary sort)
				$values[] = $continuation_values[2];	// secondary sort
				$values[] = $continuation_values[0];	// c.name (primary sort)
				$values[] = $continuation_values[1];	// f.name (internal secondary sort)
				$values[] = $continuation_values[2];	// secondary sort
				$values[] = $continuation_id;
			} elseif ($sort === 'f.name') {
				// Includes the user secondary sort
				$search .= "AND ((f.name {$sign} ?) OR (f.name = ? AND {$orderBy2} {$sign2} ?) " .
					"OR (f.name = ? AND {$orderBy2} = ? AND {$alias}id {$sign}= ?)) ";
				$values[] = $continuation_values[0];	// f.name (primary sort)
				$values[] = $continuation_values[0];	// f.name (primary sort)
				$values[] = $continuation_values[1];	// secondary sort
				$values[] = $continuation_values[0];	// f.name (primary sort)
				$values[] = $continuation_values[1];	// secondary sort
				$values[] = $continuation_id;
			} else {
				$search .= "AND ({$orderBy} {$sign} ? OR ({$orderBy} = ? AND {$alias}id {$sign}= ?)) ";
				$values[] = $continuation_values[0];	// primary sort
				$values[] = $continuation_values[0];	// primary sort
				$values[] = $continuation_id;
			}
		}

		if ($filters !== null && count($filters->searches()) > 0) {
			[$filterValues, $filterSearch] = self::sqlBooleanSearch($alias, $filters);
			$filterSearch = trim($filterSearch);
			if ($filterSearch !== '') {
				$search .= 'AND (' . $filterSearch . ') ';
				$values = array_merge($values, $filterValues);
				$this->registerSqlFunctions($search);
			}
		}
		return [$values, $search];
	}

	/**
	 * @param 'a'|'A'|'c'|'f'|'i'|'s'|'S'|'ST'|'t'|'T'|'Z' $type
	 * @param int $id category/feed/tag ID
	 * @param numeric-string $id_min
	 * @param numeric-string $id_max
	 * @param 'id'|'c.name'|'date'|'f.name'|'lastModified'|'lastUserModified'|'length'|'link'|'rand'|'title' $sort
	 * @param 'ASC'|'DESC' $order
	 * @param numeric-string $continuation_id
	 * @param list<string|int> $continuation_values
	 * @param 'id'|'date'|'link'|'title' $secondary_sort
	 * @param 'ASC'|'DESC' $secondary_sort_order
	 * @return array{0:list<int|string>,1:string}
	 * @throws FreshRSS_EntriesGetter_Exception
	 */
	private function sqlListWhere(string $type = 'a', int $id = 0, int $state = FreshRSS_Entry::STATE_ALL, ?FreshRSS_BooleanSearch $filters = null,
			string $id_min = '0', string $id_max = '0', string $sort = 'id', string $order = 'DESC',
			string $continuation_id = '0', array $continuation_values = [], int $limit = 1, int $offset = 0,
			string $secondary_sort = 'id', string $secondary_sort_order = 'DESC'): array {
		if (!$state) {
			$state = FreshRSS_Entry::STATE_ALL;
		}
		$where = '';
		$values = [];
		switch ($type) {
			case 'a':	// All PRIORITY_MAIN_STREAM
				$where .= 'f.priority >= ' .
					min(FreshRSS_Feed::PRIORITY_MAIN_STREAM, FreshRSS_Context::$search->needVisibility() ?? FreshRSS_Feed::PRIORITY_IMPORTANT) . ' ';
				break;
			case 'A':	// All except PRIORITY_HIDDEN
				$where .= 'f.priority >= ' .
					min(FreshRSS_Feed::PRIORITY_CATEGORY, FreshRSS_Context::$search->needVisibility() ?? FreshRSS_Feed::PRIORITY_IMPORTANT) . ' ';
				break;
			case 'Z':	// All including PRIORITY_HIDDEN
				$where .= '1=1 ';
				break;
			case 'i':	// Priority important feeds
				$where .= 'f.priority >= ' .
					min(FreshRSS_Feed::PRIORITY_IMPORTANT, FreshRSS_Context::$search->needVisibility() ?? FreshRSS_Feed::PRIORITY_IMPORTANT) . ' ';
				break;
			case 's':	// Starred. Deprecated: use $state instead
				$where .= 'f.priority > ' .
					min(FreshRSS_Feed::PRIORITY_HIDDEN, FreshRSS_Context::$search->needVisibility() ?? FreshRSS_Feed::PRIORITY_IMPORTANT) . ' ';
				$where .= 'AND e.is_favorite=1 ';
				break;
			case 'S':	// Starred
				$where .= 'e.is_favorite=1 ';
				break;
			case 'c':	// Category
				$where .= 'f.priority >= ' .
					min(FreshRSS_Feed::PRIORITY_CATEGORY, FreshRSS_Context::$search->needVisibility() ?? FreshRSS_Feed::PRIORITY_IMPORTANT) . ' ';
				$where .= 'AND f.category=? ';
				$values[] = $id;
				break;
			case 'f':	// Feed
				$where .= 'e.id_feed=? ';
				$values[] = $id;
				break;
			case 't':	// Tag (label)
				$where .= 'et.id_tag=? ';
				$values[] = $id;
				break;
			case 'T':	// Any tag (label)
				$where .= '1=1 ';
				break;
			case 'ST':	// Starred or tagged (label)
				$where .= '(e.is_favorite=1 OR EXISTS (SELECT et2.id_tag FROM `_entrytag` et2 WHERE et2.id_entry = e.id)) ';
				break;
			default:
				throw new FreshRSS_EntriesGetter_Exception('Bad type in Entry->listByType: [' . $type . ']!');
		}

		$order = in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC';
		$order2 = in_array($secondary_sort_order, ['ASC', 'DESC'], true) ? $secondary_sort_order : 'DESC';
		$orderBy = match ($sort) {
			'id' => 'e.id',
			'c.name' => 'c.name',
			'date' => 'e.date',
			'f.name' => 'f.name',
			'lastModified' => 'e.`lastModified`',
			'lastUserModified' => 'e.`lastUserModified`',
			'length' => 'LENGTH(e.' . (static::isCompressed() ? 'content_bin' : 'content') . ')',
			'link' => 'e.link',
			'title' => 'e.title',
			'rand' => static::sqlRandom(),
		};
		$orderBy2 = match ($secondary_sort) {
			'id' => 'e.id',
			'date' => 'e.date',
			'link' => 'e.link',
			'title' => 'e.title',
		};
		if (in_array($sort, ['lastModified', 'lastUserModified'], true)) {
			$where = $orderBy . ' IS NOT NULL AND ' . $where;
		}
		[$searchValues, $search] = $this->sqlListEntriesWhere(alias: 'e.', state: $state, filters: $filters, id_min: $id_min, id_max: $id_max,
			sort: $sort, order: $order, continuation_id: $continuation_id, continuation_values: $continuation_values,
			secondary_sort: $secondary_sort, secondary_sort_order: $secondary_sort_order);

		// Help MySQL/MariaDB's optimizer with the query plan:
		$useEntryIndex = ($this->pdo->dbType() === 'mysql' &&	// Only relevant for MySQL/MariaDB,
				in_array($type, ['a', 'A', 'Z', 'i', 's', 'f'], true) &&	// for some of the queries using the feed table,
				preg_match('/is_read\\s*=\\s*[01]\\b/', $search))	// where is_read is a criteria
			? 'USE INDEX (entry_feed_read_index) ' : '';

		return [array_merge($values, $searchValues), 'SELECT '
			. ($type === 'T' && $sort !== 'rand' ? 'DISTINCT ' : '')
			. 'e.id'
			. ($type === 'T' && !in_array($sort, ['id', 'rand'], true) ? ', ' . $orderBy . ' AS sort_value' : '')
			. ($type === 'T' && $sort === 'c.name' ? ', f.name AS feed_sort_value' : '')
			. ($type === 'T' && in_array($sort, ['c.name', 'f.name'], true) && $secondary_sort !== 'id' ? ', ' . $orderBy2 . ' AS secondary_sort_value' : '')
			. ' FROM `_entry` e ' . $useEntryIndex
			. 'INNER JOIN `_feed` f ON f.id = e.id_feed '
			. ($sort === 'c.name' ? 'INNER JOIN `_category` c ON c.id = f.category ' : '')
			. ($type === 't' || $type === 'T' ? 'INNER JOIN `_entrytag` et ON et.id_entry = e.id ' : '')
			. 'WHERE ' . $where
			. $search
			. ($type === 'T' && $sort === 'rand' ? ' GROUP BY e.id ' : '')	// DISTINCT does not work with RAND()
			. 'ORDER BY ' . $orderBy . ' ' . $order
			. ($sort === 'c.name' ? ', f.name ' . $order : '')	// Internal secondary sort
			. (in_array($sort, ['c.name', 'f.name'], true) ? ', ' . $orderBy2 . ' ' . $order2 : '')	// User secondary sort
			. ($sort === 'id' ? '' : ', e.id ' . $order)	// For keyset pagination
			. ($limit > 0 ? ' LIMIT ' . $limit : '')	// https://explainextended.com/2009/10/23/mysql-order-by-limit-performance-late-row-lookups/
			. ($offset > 0 ? ' OFFSET ' . $offset : '')
		];
	}

	/**
	 * @param 'a'|'A'|'c'|'f'|'i'|'s'|'S'|'ST'|'t'|'T'|'Z' $type
	 * @param int $id category/feed/tag ID
	 * @param numeric-string $id_min
	 * @param numeric-string $id_max
	 * @param 'id'|'c.name'|'date'|'f.name'|'lastModified'|'lastUserModified'|'length'|'link'|'rand'|'title' $sort
	 * @param 'ASC'|'DESC' $order
	 * @param numeric-string $continuation_id
	 * @param list<string|int> $continuation_values
	 * @param 'id'|'date'|'link'|'title' $secondary_sort
	 * @param 'ASC'|'DESC' $secondary_sort_order
	 * @throws FreshRSS_EntriesGetter_Exception
	 */
	private function listWhereRaw(string $type = 'a', int $id = 0, int $state = FreshRSS_Entry::STATE_ALL, ?FreshRSS_BooleanSearch $filters = null,
		string $id_min = '0', string $id_max = '0', string $sort = 'id', string $order = 'DESC',
		string $continuation_id = '0', array $continuation_values = [], int $limit = 1, int $offset = 0,
		string $secondary_sort = 'id', string $secondary_sort_order = 'DESC', bool $withContent = true): PDOStatement|false {
		$order = in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC';
		$secondary_sort_order = in_array($secondary_sort_order, ['ASC', 'DESC'], true) ? $secondary_sort_order : 'DESC';

		[$values, $sql] = $this->sqlListWhere($type, $id, $state, $filters, id_min: $id_min, id_max: $id_max, sort: $sort, order: $order,
			continuation_id: $continuation_id, continuation_values: $continuation_values, limit: $limit, offset: $offset,
			secondary_sort: $secondary_sort, secondary_sort_order: $secondary_sort_order);

		$orderBy = match ($sort) {
			'id' => 'e0.id',
			'c.name' => 'c0.name',
			'date' => 'e0.date',
			'f.name' => 'f0.name',
			'lastModified' => 'e0.`lastModified`',
			'lastUserModified' => 'e0.`lastUserModified`',
			'length' => 'LENGTH(e0.' . (static::isCompressed() ? 'content_bin' : 'content') . ')',
			'link' => 'e0.link',
			'title' => 'e0.title',
			'rand' => static::sqlRandom(),
		};
		$orderBy2 = match ($secondary_sort) {
			'id' => 'e0.id',
			'date' => 'e0.date',
			'link' => 'e0.link',
			'title' => 'e0.title',
		};
		$content = $withContent ? (static::isCompressed() ? 'UNCOMPRESS(e0.content_bin) AS content' : 'e0.content') : "'' AS content";
		$attributes = $withContent ? 'e0.attributes' : 'NULL AS attributes';
		$hash = static::sqlHexEncode('e0.hash');
		$sql = <<<SQL
			SELECT e0.id, e0.guid, e0.title, e0.author, {$content}, e0.link,
				e0.date, e0.`lastSeen`, e0.`lastModified`, e0.`lastUserModified`, {$hash} AS hash, e0.is_read, e0.is_favorite, e0.id_feed, e0.tags, {$attributes}
			FROM `_entry` e0 INNER JOIN ({$sql}) e2 ON e2.id=e0.id
			SQL;
		if ($sort === 'f.name' || $sort === 'c.name') {
			$sql .= "\n" . <<<'SQL'
				INNER JOIN `_feed` f0 ON f0.id = e0.id_feed
				SQL;
		}
		if ($sort === 'c.name') {
			$sql .= "\n" . <<<'SQL'
				INNER JOIN `_category` c0 ON c0.id = f0.category
				SQL;
		}
		$sql .= "\n" . <<<SQL
			ORDER BY {$orderBy} {$order}
			SQL;
		if ($sort === 'c.name') {
			$sql .= "\n" . <<<SQL
				, f0.name {$order}
				SQL;	// Internal secondary sort
		}
		if (in_array($sort, ['c.name', 'f.name'], true)) {
			$sql .= "\n" . <<<SQL
				, {$orderBy2} {$secondary_sort_order}
				SQL;	// User secondary sort
		}
		if ($sort !== 'id') {
			// For keyset pagination
			$sql .= "\n" . <<<SQL
				, e0.id {$order}
				SQL;
		}
		$stm = $this->pdo->prepare($sql);
		if ($stm !== false) {
			foreach ($values as $index => $value) {
				$paramType = PDO::PARAM_STR;
				if (is_null($value)) {
					$paramType = PDO::PARAM_NULL;
				} elseif (is_int($value) || is_bool($value)) {
					$paramType = PDO::PARAM_INT;
				}
				$stm->bindValue($index + 1, $value, $paramType);
			}
		}
		if ($stm !== false && $stm->execute()) {
			// TODO: Consider adding an option for SQL debugging
			// Minz_Log::debug('SQL params ' . __METHOD__ . ' ' . json_encode($values));
			// Minz_Log::debug('SQL query ' . __METHOD__ . ' ' . json_encode($sql));
			return $stm;
		} else {
			$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
			/** @var array{0:string,1:int,2:string} $info */
			if ($this->autoUpdateDb($info)) {
				return $this->listWhereRaw($type, $id, $state, $filters, id_min: $id_min, id_max: $id_max, sort: $sort, order: $order,
					continuation_id: $continuation_id, continuation_values: $continuation_values, limit: $limit, offset: $offset,
					secondary_sort: $secondary_sort, secondary_sort_order: $secondary_sort_order, withContent: $withContent);
			}
			Minz_Log::error('SQL error ' . __METHOD__ . ' ' . json_encode($info));
			Minz_Log::error('SQL error ' . __METHOD__ . ' ' . json_encode($sql));
			return false;
		}
	}

	/**
	 * @param 'a'|'A'|'c'|'f'|'i'|'s'|'S'|'ST'|'t'|'T'|'Z' $type
	 * @param int $id category/feed/tag ID
	 * @param numeric-string $id_min
	 * @param numeric-string $id_max
	 * @param 'id'|'c.name'|'date'|'f.name'|'lastUserModified'|'length'|'link'|'rand'|'title' $sort
	 * @param 'ASC'|'DESC' $order
	 * @param numeric-string $continuation_id
	 * @param list<string|int> $continuation_values
	 * @param 'id'|'date'|'link'|'title' $secondary_sort
	 * @param 'ASC'|'DESC' $secondary_sort_order
	 * @return Traversable<FreshRSS_Entry>
	 * @throws FreshRSS_EntriesGetter_Exception
	 */
	public function listWhere(string $type = 'a', int $id = 0, int $state = FreshRSS_Entry::STATE_ALL, ?FreshRSS_BooleanSearch $filters = null,
			string $id_min = '0', string $id_max = '0', string $sort = 'id', string $order = 'DESC',
			string $continuation_id = '0', array $continuation_values = [], int $limit = 1, int $offset = 0,
			string $secondary_sort = 'id', string $secondary_sort_order = 'DESC'): Traversable {
		$stm = $this->listWhereRaw($type, $id, $state, $filters, id_min: $id_min, id_max: $id_max, sort: $sort, order: $order,
			continuation_id: $continuation_id, continuation_values: $continuation_values, limit: $limit, offset: $offset,
			secondary_sort: $secondary_sort, secondary_sort_order: $secondary_sort_order);
		if ($stm !== false) {
			while (is_array($row = $stm->fetch(PDO::FETCH_ASSOC))) {
				/** @var array{id:string,guid:string,title:string,author:string,content:string,link:string,date:int,
				 * 		lastSeen:int,lastModified:int,lastUserModified:int,hash:string,is_read:int,is_favorite:int,id_feed:int,
				 * 		tags:string,attributes:?string} $row */
				yield FreshRSS_Entry::fromArray($row);
			}
		}
	}

	/**
	 * Metadata-only result projection for the independent reader. Full-text search filters may still inspect bodies.
	 * @param 'a'|'A'|'c'|'f'|'i'|'s'|'S'|'ST'|'t'|'T'|'Z' $type
	 * @param numeric-string $idMax
	 * @return Traversable<FreshRSS_Entry>
	 * @throws FreshRSS_EntriesGetter_Exception
	 */
	public function listSummaries(string $type = 'a', int $id = 0, int $state = FreshRSS_Entry::STATE_ALL,
		?FreshRSS_BooleanSearch $filters = null, string $idMax = '0', int $limit = 50): Traversable {
		$stm = $this->listWhereRaw($type, $id, $state, $filters, id_max: $idMax, limit: $limit, withContent: false);
		if ($stm !== false) {
			while (is_array($row = $stm->fetch(PDO::FETCH_ASSOC))) {
				/** @var array{id:string,guid:string,title:string,author:string,content:string,link:string,date:int,
				 * lastSeen:int,lastModified:int,lastUserModified:int,hash:string,is_read:int,is_favorite:int,id_feed:int,
				 * tags:string,attributes:?string} $row */
				yield FreshRSS_Entry::fromArray($row);
			}
		}
	}

	/**
	 * @param array<numeric-string> $ids
	 * @return list<numeric-string>|null null on a database error
	 */
	public function existingIds(array $ids): ?array {
		$result = [];
		$ids = array_values(array_unique($ids));
		$chunkSize = FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER;
		for ($offset = 0, $count = count($ids); $offset < $count; $offset += $chunkSize) {
			$chunk = array_slice($ids, $offset, $chunkSize);
			$placeholders = implode(',', array_fill(0, count($chunk), '?'));
			$stm = $this->pdo->prepare("SELECT id FROM `_entry` WHERE id IN ({$placeholders})");
			if ($stm === false || !$stm->execute($chunk)) {
				$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
				Minz_Log::error('SQL error ' . __METHOD__ . json_encode($info));
				return null;
			}
			while (($id = $stm->fetchColumn()) !== false) {
				/** @var numeric-string $id */
				$result[] = (string)$id;
			}
		}
		return $result;
	}

	/**
	 * For API.
	 * @param array<numeric-string> $ids
	 * @param 'ASC'|'DESC' $order
	 * @return Traversable<FreshRSS_Entry>
	 */
	public function listByIds(array $ids, string $order = 'DESC'): Traversable {
		if (count($ids) < 1) {
			return;
		}
		$order = in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC';
		if (count($ids) > FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER) {
			$ids = array_values(array_unique($ids));
			// Order all positive integer IDs before splitting; never convert microsecond IDs to floats.
			usort($ids, static function (string $a, string $b) use ($order): int {
				$comparison = (strlen($a) <=> strlen($b)) ?: strcmp($a, $b);
				return $order === 'ASC' ? $comparison : -$comparison;
			});
			// Split a query with too many variables parameters
			$idsChunks = array_chunk($ids, FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER);
			foreach ($idsChunks as $idsChunk) {
				foreach ($this->listByIds($idsChunk, order: $order) as $entry) {
					yield $entry;
				}
			}
			return;
		}
		$content = static::isCompressed() ? 'UNCOMPRESS(content_bin) AS content' : 'content';
		$hash = static::sqlHexEncode('hash');
		$idPlaceholders = str_repeat('?,', count($ids) - 1) . '?';
		$sql = <<<SQL
			SELECT id, guid, title, author, link, date, `lastModified`, `lastUserModified`,
				{$hash} AS hash, is_read, is_favorite, id_feed, tags, attributes, {$content}
			FROM `_entry`
			WHERE id IN ({$idPlaceholders})
			ORDER BY id {$order}
			SQL;
		$stm = $this->pdo->prepare($sql);
		if ($stm === false || !$stm->execute($ids)) {
			$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error ' . __METHOD__ . json_encode($info));
			return;
		}
		while (is_array($row = $stm->fetch(PDO::FETCH_ASSOC))) {
			/** @var array{id:string,guid:string,title:string,author:string,content:string,link:string,date:int,
			 * 		lastSeen:int,lastModified:int,lastUserModified:int,hash:string,is_read:int,is_favorite:int,id_feed:int,
			 * 		tags:string,attributes:?string} $row */
			yield FreshRSS_Entry::fromArray($row);
		}
	}

	/**
	 * @param 'a'|'A'|'c'|'f'|'i'|'s'|'S'|'ST'|'t'|'T'|'Z' $type
	 * @param int $id category/feed/tag ID
	 * @param numeric-string $id_min
	 * @param numeric-string $id_max
	 * @param 'ASC'|'DESC' $order
	 * @param numeric-string $continuation_id
	 * @param list<string|int> $continuation_values
	 * @param 'id'|'date'|'link'|'title' $secondary_sort
	 * @param 'ASC'|'DESC' $secondary_sort_order
	 * @return list<numeric-string>|null
	 * @throws FreshRSS_EntriesGetter_Exception
	 */
	public function listIdsWhere(string $type = 'a', int $id = 0, int $state = FreshRSS_Entry::STATE_ALL, ?FreshRSS_BooleanSearch $filters = null,
		string $id_min = '0', string $id_max = '0', string $order = 'DESC',
		string $continuation_id = '0', array $continuation_values = [], int $limit = 1, int $offset = 0,
		string $secondary_sort = 'id', string $secondary_sort_order = 'DESC'): ?array {
		[$values, $sql] = $this->sqlListWhere($type, $id, $state, $filters, id_min: $id_min, id_max: $id_max, order: $order,
			continuation_id: $continuation_id, continuation_values: $continuation_values, limit: $limit, offset: $offset,
			secondary_sort: $secondary_sort, secondary_sort_order: $secondary_sort_order);
		$stm = $this->pdo->prepare($sql);
		if ($stm !== false && $stm->execute($values)) {
			/** @var list<int|numeric-string> $res */
			$res = $stm->fetchAll(PDO::FETCH_COLUMN, 0);
			$res = array_map('strval', $res);
			/** @var list<numeric-string> $res */
			return $res;
		}
		$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
		Minz_Log::error('SQL error ' . __METHOD__ . json_encode($info));
		return null;
	}

	/**
	 * Get feed IDs that have entries matching the given state and search filters.
	 * @return array<int,int> The keys are feed IDs, and the values are the number of matching entries for that feed.
	 */
	public function listFeedIdsMatching(int $state, ?FreshRSS_BooleanSearch $filters = null): array {
		[$values, $search] = $this->sqlListEntriesWhere(alias: 'e.', state: $state, filters: $filters);
		$sql = <<<SQL
			SELECT e.id_feed, COUNT(*) AS count FROM `_entry` e
			WHERE 1=1 {$search}
			GROUP BY e.id_feed
			SQL;
		$stm = $this->pdo->prepare($sql);
		if ($stm !== false && $stm->execute($values)) {
			/** @var list<array{id_feed:int|string,count:int}> $res */
			$res = $stm->fetchAll(PDO::FETCH_ASSOC);
			$result = [];
			foreach ($res as $row) {
				$result[(int)$row['id_feed']] = (int)$row['count'];
			}
			return $result;
		}
		$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
		Minz_Log::error('SQL error ' . __METHOD__ . ' ' . json_encode($info));
		return [];
	}

	/**
	 * @param array<string> $guids
	 * @return array<string,string> guid => hash
	 */
	public function listHashForFeedGuids(int $id_feed, array $guids): array {
		$result = [];
		if (count($guids) < 1) {
			return $result;
		} elseif (count($guids) > FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER - 1) {
			// Split a query with too many variables parameters
			$guidsChunks = array_chunk($guids, FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER - 1);
			foreach ($guidsChunks as $guidsChunk) {
				$result += $this->listHashForFeedGuids($id_feed, $guidsChunk);
			}
			return $result;
		}
		$guids = array_unique($guids);
		$hexHashExpr = static::sqlHexEncode('hash');
		$guidPlaceholders = str_repeat('?,', count($guids) - 1) . '?';
		$sql = <<<SQL
			SELECT guid, {$hexHashExpr} AS hex_hash
			FROM `_entry`
			WHERE id_feed=? AND guid IN ({$guidPlaceholders})
			SQL;
		$stm = $this->pdo->prepare($sql);
		$values = [$id_feed];
		$values = array_merge($values, $guids);
		if ($stm !== false && $stm->execute($values)) {
			$rows = $stm->fetchAll(PDO::FETCH_ASSOC);
			foreach ($rows as $row) {
				/** @var array{guid:string,hex_hash:string} $row */
				$result[$row['guid']] = $row['hex_hash'];
			}
			return $result;
		} else {
			$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error ' . __METHOD__ . json_encode($info)
				. ' while querying feed ' . $id_feed);
			return [];
		}
	}

	/**
	 * @param array<string> $guids
	 * @return int|false The number of affected entries, or false if error
	 */
	public function updateLastSeen(int $id_feed, array $guids, int $mtime = 0): int|false {
		if ($guids === []) {
			return 0;
		}
		$guids = array_values(array_unique($guids));
		$mtime = $mtime > 0 ? $mtime : time();
		$hadTransaction = $this->pdo->inTransaction();
		$finished = false;
		if (!($hadTransaction ? $this->pdo->exec('SAVEPOINT freshrss_entry_seen') !== false : $this->pdo->beginTransaction())) {
			return false;
		}
		try {
			$affected = 0;
			// Reserve two placeholders for the timestamp and feed ID.
			foreach (array_chunk($guids, FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER - 2) as $chunk) {
				$orderBy = $this->pdo->dbType() === 'mysql' ? ' ORDER BY id DESC' : '';
				$placeholders = implode(',', array_fill(0, count($chunk), '?'));
				$sql = "UPDATE `_entry` SET `lastSeen`=? WHERE id_feed=? AND guid IN ({$placeholders}){$orderBy}";
				$stm = $this->pdo->prepare($sql);
				if ($stm === false || !$stm->execute(array_merge([$mtime, $id_feed], $chunk))) {
					$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
					Minz_Log::error('SQL error ' . __METHOD__ . json_encode($info) . ' while updating feed ' . $id_feed);
					return false;
				}
				$affected += $stm->rowCount();
			}
			$finished = $hadTransaction ? $this->pdo->exec('RELEASE SAVEPOINT freshrss_entry_seen') !== false : $this->pdo->commit();
			return $finished ? $affected : false;
		} finally {
			if (!$finished && $this->pdo->inTransaction()) {
				if ($hadTransaction) {
					$this->pdo->exec('ROLLBACK TO SAVEPOINT freshrss_entry_seen');
					$this->pdo->exec('RELEASE SAVEPOINT freshrss_entry_seen');
				} else {
					$this->pdo->rollBack();
				}
			}
		}
	}

	/**
	 * Update (touch) the last seen attribute of the latest entries of a given feed.
	 * Useful when a feed is unchanged / cached.
	 * To be performed just before {@see FreshRSS_FeedDAO::updateLastUpdate()}
	 * @return int|false The number of affected entries, or false in case of error
	 */
	public function updateLastSeenUnchanged(int $id_feed, int $mtime = 0): int|false {
		$sql = <<<'SQL'
			UPDATE `_entry` SET `lastSeen` = :mtime
			WHERE id_feed = :id_feed1 AND `lastSeen` >= (
				SELECT `lastUpdate` FROM `_feed` f
				WHERE f.id = :id_feed2
			)
			SQL;
		$stm = $this->pdo->prepare($sql);
		if ($mtime <= 0) {
			$mtime = time();
		}
		if ($stm !== false &&
			$stm->bindValue(':mtime', $mtime, PDO::PARAM_INT) &&
			$stm->bindValue(':id_feed1', $id_feed, PDO::PARAM_INT) &&
			$stm->bindValue(':id_feed2', $id_feed, PDO::PARAM_INT) &&
			$stm->execute()) {
			return $stm->rowCount();
		} else {
			$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error ' . __METHOD__ . json_encode($info) . ' while updating feed ' . $id_feed);
			return false;
		}
	}

	/** @return array{all:int,unread:int,read:int,favorites:int} */
	public function countAsStates(?int $minPriority = null): array {
		$values = [];
		$sql = <<<'SQL'
			SELECT
				COUNT(*) AS total,
				COUNT(CASE WHEN e.is_read = 0 THEN 1 END) AS unread,
				COUNT(CASE WHEN e.is_favorite = 1 THEN 1 END) AS favorites
			FROM `_entry` e
			SQL;
		if ($minPriority !== null) {
			$sql .= "\n" . <<<'SQL'
				INNER JOIN `_feed` f ON e.id_feed = f.id
				WHERE f.priority > :priority
				SQL;
			$values[':priority'] = $minPriority;
		}
		$res = $this->fetchAssoc($sql, $values);
		if ($res === null || !isset($res[0])) {
			return ['all' => -1, 'unread' => -1, 'read' => -1, 'favorites' => -1];
		}
		$all = (int)($res[0]['total'] ?? 0);
		$unread = (int)($res[0]['unread'] ?? 0);
		$favorites = (int)($res[0]['favorites'] ?? 0);
		return ['all' => $all, 'unread' => $unread, 'read' => $all - $unread, 'favorites' => $favorites];
	}

	public function count(?int $minPriority = null): int {
		$sql = <<<'SQL'
			SELECT COUNT(*) AS count FROM `_entry` e
			SQL;
		$values = [];
		if ($minPriority !== null) {
			$sql .= "\n" . <<<'SQL'
				INNER JOIN `_feed` f ON e.id_feed=f.id
				WHERE f.priority > :priority
				SQL;
			$values[':priority'] = $minPriority;
		}
		return $this->fetchInt($sql, $values) ?? -1;
	}

	/** @return array{'all':int,'read':int,'unread':int} */
	public function countUnreadReadFavorites(): array {
		$sql = <<<'SQL'
			SELECT
				COUNT(*) AS total,
				COUNT(CASE WHEN e.is_read = 0 THEN 1 END) AS unread
			FROM `_entry` e
			JOIN `_feed` f ON e.id_feed = f.id
			WHERE e.is_favorite = 1 AND f.priority > :priority
			SQL;
		$res = $this->fetchAssoc($sql, [':priority' => FreshRSS_Feed::PRIORITY_HIDDEN]);
		if ($res === null || !isset($res[0])) {
			return ['all' => -1, 'unread' => -1, 'read' => -1];
		}
		$all = (int)($res[0]['total'] ?? 0);
		$unread = (int)($res[0]['unread'] ?? 0);
		return ['all' => $all, 'unread' => $unread, 'read' => $all - $unread];
	}
}
