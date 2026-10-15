<?php
declare(strict_types=1);

class FreshRSS_TagDAO extends Minz_ModelPdo {

	public static function sqlIgnoreConflict(string $sql): string {
		return str_replace('INSERT INTO ', 'INSERT IGNORE INTO ', $sql);
	}

	public function sqlResetSequence(): bool {
		return true;	// Nothing to do for MySQL
	}

	/**
	 * @param array{id?:int,name:string,attributes?:array<string,mixed>} $valuesTmp
	 */
	public function addTag(array $valuesTmp): int|false {
		$attributes = $valuesTmp['attributes'] ?? [];
		$attributes = is_string($attributes) ? $attributes : json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($attributes === false) {
			Minz_Log::error(__METHOD__ . ': invalid tag attributes: ' . json_last_error_msg());
			return false;
		}
		if (empty($valuesTmp['id'])) {	// Auto-generated ID
			$sql = <<<'SQL'
				INSERT INTO `_tag`(name, attributes)
				SELECT * FROM (SELECT :name1 AS name, :attributes AS attributes) t2
				SQL;
		} else {
			$sql = <<<'SQL'
				INSERT INTO `_tag`(id, name, attributes)
				SELECT * FROM (SELECT 1*:id AS id, :name1 AS name, :attributes AS attributes) t2
				SQL;
		}
		// No category of the same name
		$sql .= "\n" . <<<'SQL'
			WHERE NOT EXISTS (SELECT 1 FROM `_category` WHERE name = :name2)
			SQL;
		$stm = $this->pdo->prepare($sql);

		$valuesTmp['name'] = mb_strcut(trim($valuesTmp['name']), 0, FreshRSS_DatabaseDAO::LENGTH_INDEX_UNICODE, 'UTF-8');

		if ($stm !== false &&
			(empty($valuesTmp['id']) || $stm->bindValue(':id', $valuesTmp['id'], PDO::PARAM_INT)) &&
			$stm->bindValue(':name1', $valuesTmp['name'], PDO::PARAM_STR) &&
			$stm->bindValue(':name2', $valuesTmp['name'], PDO::PARAM_STR) &&
			$stm->bindValue(':attributes', $attributes, PDO::PARAM_STR) &&
			$stm->execute() && $stm->rowCount() > 0) {
			if (empty($valuesTmp['id'])) {
				// Auto-generated ID
				$tagId = $this->pdo->lastInsertId('`_tag_id_seq`');
				return $tagId === false ? false : (int)$tagId;
			}
			$this->sqlResetSequence();
			return $valuesTmp['id'];
		} else {
			$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error ' . __METHOD__ . json_encode($info));
			return false;
		}
	}

	public function addTagObject(FreshRSS_Tag $tag): int|false {
		$tag0 = $this->searchByName($tag->name());
		if ($tag0 === null) {
			$values = [
				'name' => $tag->name(),
				'attributes' => $tag->attributes(),
			];
			return $this->addTag($values);
		}
		return $tag0->id();
	}

	public function updateTagName(int $id, string $name): int|false {
		// No category of the same name
		$sql = <<<'SQL'
			UPDATE `_tag` SET name = :name1 WHERE id = :id
			AND NOT EXISTS (SELECT 1 FROM `_category` WHERE name = :name2)
			SQL;

		$name = mb_strcut(trim($name), 0, FreshRSS_DatabaseDAO::LENGTH_INDEX_UNICODE, 'UTF-8');
		$stm = $this->pdo->prepare($sql);
		if ($stm !== false &&
			$stm->bindValue(':id', $id, PDO::PARAM_INT) &&
			$stm->bindValue(':name1', $name, PDO::PARAM_STR) &&
			$stm->bindValue(':name2', $name, PDO::PARAM_STR) &&
			$stm->execute()) {
			return $stm->rowCount();
		} else {
			$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error ' . __METHOD__ . json_encode($info));
			return false;
		}
	}

	/**
	 * @param array<string,mixed> $attributes
	 */
	public function updateTagAttributes(int $id, array $attributes): int|false {
		$encoded = json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($encoded === false) {
			Minz_Log::error(__METHOD__ . ': invalid tag attributes: ' . json_last_error_msg());
			return false;
		}
		$sql = <<<'SQL'
			UPDATE `_tag` SET attributes=:attributes WHERE id=:id
			SQL;
		$stm = $this->pdo->prepare($sql);
		if ($stm !== false &&
			$stm->bindValue(':id', $id, PDO::PARAM_INT) &&
			$stm->bindValue(':attributes', $encoded, PDO::PARAM_STR) &&
			$stm->execute()) {
			return $stm->rowCount();
		}
		$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
		Minz_Log::error('SQL error ' . __METHOD__ . json_encode($info));
		return false;
	}

	/**
	 * @param non-empty-string $key
	 */
	public function updateTagAttribute(FreshRSS_Tag $tag, string $key, mixed $value): int|false {
		$tag->_attribute($key, $value);
		return $this->updateTagAttributes($tag->id(), $tag->attributes());
	}

	public function deleteTag(int $id): int|false {
		if ($id <= 0) {
			return false;
		}
		$sql = <<<'SQL'
			DELETE FROM `_tag` WHERE id=:id
			SQL;
		$stm = $this->pdo->prepare($sql);
		if ($stm !== false &&
			$stm->bindValue(':id', $id, PDO::PARAM_INT) &&
			$stm->execute()) {
			return $stm->rowCount();
		} else {
			$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error ' . __METHOD__ . json_encode($info));
			return false;
		}
	}

	/** @return Traversable<array{id:int,name:string,attributes?:array<string,mixed>}> */
	public function selectAll(): Traversable {
		$sql = <<<'SQL'
			SELECT id, name, attributes FROM `_tag`
			SQL;
		$stm = $this->pdo->query($sql);
		if ($stm === false) {
			Minz_Log::error('SQL error ' . __METHOD__ . json_encode($this->pdo->errorInfo()));
			return;
		}
		while (is_array($row = $stm->fetch(PDO::FETCH_ASSOC))) {
			/** @var array{id:int,name:string,attributes?:array<string,mixed>} $row */
			yield $row;
		}
	}

	/** @return Traversable<array{id_tag:int,id_entry:int|numeric-string}> */
	public function selectEntryTag(): Traversable {
		$sql = <<<'SQL'
			SELECT id_tag, id_entry FROM `_entrytag`
			SQL;
		$stm = $this->pdo->query($sql);
		if ($stm === false) {
			Minz_Log::error('SQL error ' . __METHOD__ . json_encode($this->pdo->errorInfo()));
			return;
		}
		while (is_array($row = $stm->fetch(PDO::FETCH_ASSOC))) {
			/** @var array{id_tag:int,id_entry:int|numeric-string} $row */
			yield $row;
		}
	}

	public function updateEntryTag(int $oldTagId, int $newTagId): int|false {
		if ($oldTagId <= 0 || $newTagId <= 0) {
			return false;
		}
		if ($oldTagId === $newTagId) {
			return 0;
		}
		return $this->atomicWrite(function () use ($oldTagId, $newTagId): int|false {
			$sql = static::sqlIgnoreConflict(<<<'SQL'
				INSERT INTO `_entrytag` (id_tag, id_entry)
				SELECT ?, id_entry FROM `_entrytag` WHERE id_tag=?
				SQL);
			$stm = $this->pdo->prepare($sql);
			if ($stm === false || !$stm->execute([$newTagId, $oldTagId])) {
				return false;
			}
			$affected = $stm->rowCount();
			// INSERT IGNORE on MySQL can also ignore invalid rows; only remove the source after every association exists at the target.
			$missing = $this->fetchInt('SELECT COUNT(*) FROM `_entrytag` source LEFT JOIN `_entrytag` target'
				. ' ON target.id_entry=source.id_entry AND target.id_tag=:new_tag_id'
				. ' WHERE source.id_tag=:old_tag_id AND target.id_entry IS NULL',
				[':new_tag_id' => $newTagId, ':old_tag_id' => $oldTagId]);
			if ($missing !== 0) {
				return false;
			}
			$stm = $this->pdo->prepare('DELETE FROM `_entrytag` WHERE id_tag=?');
			return $stm !== false && $stm->execute([$oldTagId]) ? $affected : false;
		});
	}

	/** @param callable(): (int|false) $write */
	private function atomicWrite(callable $write): int|false {
		$hadTransaction = $this->pdo->inTransaction();
		$finished = false;
		if (!($hadTransaction ? $this->pdo->exec('SAVEPOINT freshrss_tag_write') !== false : $this->pdo->beginTransaction())) {
			return false;
		}
		try {
			$affected = $write();
			if ($affected === false) {
				Minz_Log::error('SQL error ' . __METHOD__ . json_encode($this->pdo->errorInfo()));
				return false;
			}
			$finished = $hadTransaction ? $this->pdo->exec('RELEASE SAVEPOINT freshrss_tag_write') !== false : $this->pdo->commit();
			return $finished ? $affected : false;
		} finally {
			if (!$finished && $this->pdo->inTransaction()) {
				if ($hadTransaction) {
					$this->pdo->exec('ROLLBACK TO SAVEPOINT freshrss_tag_write');
					$this->pdo->exec('RELEASE SAVEPOINT freshrss_tag_write');
				} else {
					$this->pdo->rollBack();
				}
			}
		}
	}

	public function searchById(int $id): ?FreshRSS_Tag {
		$res = $this->fetchAssoc('SELECT * FROM `_tag` WHERE id=:id', [':id' => $id]);
		/** @var list<array{id:int,name:string,attributes?:string}>|null $res */
		return $res === null ? null : (current(self::daoToTags($res)) ?: null);
	}

	public function searchByName(string $name): ?FreshRSS_Tag {
		$res = $this->fetchAssoc('SELECT * FROM `_tag` WHERE name=:name', [':name' => $name]);
		/** @var list<array{id:int,name:string,attributes?:string}>|null $res */
		return $res === null ? null : (current(self::daoToTags($res)) ?: null);
	}

	/** @return array<int,FreshRSS_Tag> where the key is the label ID */
	public function listTags(bool $precounts = false): array {
		if ($precounts) {
			$sql = <<<'SQL'
				SELECT t.id, t.name, count(e.id) AS unreads
				FROM `_tag` t
				LEFT OUTER JOIN `_entrytag` et ON et.id_tag = t.id
				LEFT OUTER JOIN `_entry` e ON et.id_entry = e.id AND e.is_read = 0
				GROUP BY t.id
				ORDER BY t.name
				SQL;
		} else {
			$sql = <<<'SQL'
				SELECT * FROM `_tag` ORDER BY name
				SQL;
		}

		$res = $this->fetchAssoc($sql);
		if ($res !== null) {
			/** @var list<array{id:int,name:string,unreads:int}> $res */
			$tags = self::daoToTags($res);
			uasort($tags, static fn(FreshRSS_Tag $a, FreshRSS_Tag $b) => FreshRSS_Context::localeCompare($a->name(), $b->name()));
			return $tags;
		} else {
			$info = $this->pdo->errorInfo();
			Minz_Log::error('SQL error ' . __METHOD__ . json_encode($info));
			return [];
		}
	}

	/** @return array<string,string> */
	public function listTagsNewestItemUsec(?int $id_tag = null): array {
		$sql = <<<'SQL'
			SELECT t.id AS id_tag, MAX(e.id) AS newest_item_us
			FROM `_tag` t
			LEFT OUTER JOIN `_entrytag` et ON et.id_tag = t.id
			LEFT OUTER JOIN `_entry` e ON et.id_entry = e.id
			SQL;
		if ($id_tag === null) {
			$sql .= "\n" . <<<'SQL'
				GROUP BY t.id
				SQL;
		} else {
			$sql .= "\n" . <<<SQL
				WHERE t.id={$id_tag}
				SQL;
		}
		$res = $this->fetchAssoc($sql);
		if ($res == null) {
			return [];
		}
		$newestItemUsec = [];
		foreach ($res as $line) {
			$newestItemUsec['t_' . $line['id_tag']] = (string)($line['newest_item_us']);
		}
		return $newestItemUsec;
	}

	public function count(): int {
		$sql = <<<'SQL'
			SELECT COUNT(*) AS count FROM `_tag`
			SQL;
		return $this->fetchInt($sql) ?? -1;
	}

	public function countEntries(int $id): int {
		$sql = <<<'SQL'
			SELECT COUNT(*) AS count FROM `_entrytag` WHERE id_tag=:id_tag
			SQL;
		return $this->fetchInt($sql, [':id_tag' => $id]) ?? -1;
	}

	public function countNotRead(?int $id = null): int {
		$sql = <<<'SQL'
			SELECT COUNT(*) AS count FROM `_entrytag` et
			INNER JOIN `_entry` e ON et.id_entry=e.id
			WHERE e.is_read=0
			SQL;
		$values = [];
		if (null !== $id) {
			$sql .= "\n" . <<<'SQL'
				AND et.id_tag=:id_tag
				SQL;
			$values[':id_tag'] = $id;
		}
		return $this->fetchInt($sql, $values) ?? -1;
	}

	public function tagEntry(int $id_tag, string $id_entry, bool $checked = true): bool {
		if ($checked) {
			$sql = static::sqlIgnoreConflict(<<<'SQL'
				INSERT INTO `_entrytag`(id_tag, id_entry) VALUES(:id_tag, :id_entry)
				SQL);
		} else {
			$sql = <<<'SQL'
				DELETE FROM `_entrytag` WHERE id_tag=:id_tag AND id_entry=:id_entry
				SQL;
		}
		$stm = $this->pdo->prepare($sql);

		if ($stm !== false &&
			$stm->bindValue(':id_tag', $id_tag, PDO::PARAM_INT) &&
			$stm->bindValue(':id_entry', $id_entry, PDO::PARAM_STR) &&
			$stm->execute()) {
			return true;
		}
		$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
		Minz_Log::error('SQL error ' . __METHOD__ . json_encode($info));
		return false;
	}

	/**
	 * @param iterable<array{id_tag:int,id_entry:numeric-string|int}> $addLabels Labels to insert as batch
	 * @return int|false Number of new entries or false in case of error
	 */
	public function tagEntries(iterable $addLabels): int|false {
		$validLabels = (static function () use ($addLabels): Generator {
			foreach ($addLabels as $addLabel) {
				$id_tag = (int)($addLabel['id_tag'] ?? 0);
				$id_entry = (string)($addLabel['id_entry'] ?? '');
				if ($id_tag <= 0 || !ctype_digit($id_entry) || trim($id_entry, '0') === '') {
					continue;
				}
				yield [$id_tag, $id_entry];
			}
		})();
		if (!$validLabels->valid()) {
			return 0;
		}
		return $this->atomicWrite(function () use ($validLabels): int|false {
			$affected = 0;
			$values = [];
			$chunkSize = intdiv(FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER, 2) * 2;
			foreach ($validLabels as [$id_tag, $id_entry]) {
				$values[] = $id_tag;
				$values[] = $id_entry;
				if (count($values) === $chunkSize) {
					$count = $this->insertLabels($values);
					if ($count === false) {
						return false;
					}
					$affected += $count;
					$values = [];
				}
			}
			$count = $values === [] ? 0 : $this->insertLabels($values);
			return $count === false ? false : $affected + $count;
		});
	}

	/** @param non-empty-list<int|string> $values */
	private function insertLabels(array $values): int|false {
		$sql = static::sqlIgnoreConflict('INSERT INTO `_entrytag` (id_tag, id_entry) VALUES '
			. implode(',', array_fill(0, intdiv(count($values), 2), '(?,?)')));
		$stm = $this->pdo->prepare($sql);
		return $stm !== false && $stm->execute($values) ? $stm->rowCount() : false;
	}

	/**
	 * @return list<array{id:int,name:string,checked:bool}>
	 */
	public function getTagsForEntry(string $id_entry): array {
		$sql = <<<'SQL'
			SELECT t.id, t.name, et.id_entry IS NOT NULL as checked
			FROM `_tag` t
			LEFT OUTER JOIN `_entrytag` et ON et.id_tag = t.id AND et.id_entry=:id_entry
			ORDER BY t.name
			SQL;
		$lines = $this->fetchAssoc($sql, [':id_entry' => $id_entry]);
		if ($lines !== null) {
			$result = [];
			foreach ($lines as $line) {
				/** @var array{id:int,name:string,checked:int} $line */
				$result[] = [
					'id' => (int)($line['id']),
					'name' => $line['name'],
					'checked' => !empty($line['checked']),
				];
			}
			return $result;
		}
		$info = $this->pdo->errorInfo();
		Minz_Log::error('SQL error ' . __METHOD__ . json_encode($info));
		return [];
	}

	/**
	 * @param list<FreshRSS_Entry|numeric-string> $entries
	 * @return list<array{id_entry:int|numeric-string,id_tag:int,name:string}>|null
	 */
	public function getTagsForEntries(array $entries): array|null {
		if ($entries === []) {
			return [];
		}
		$sql = <<<'SQL'
		SELECT et.id_entry, et.id_tag, t.name
		FROM `_tag` t
		INNER JOIN `_entrytag` et ON et.id_tag = t.id
		SQL;

		$values = [];
		if (count($entries) > 0) {
			if (count($entries) > FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER) {
				// Split a query with too many variables parameters
				$idsChunks = array_chunk($entries, FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER);
				foreach ($idsChunks as $idsChunk) {
					$valuesChunk = $this->getTagsForEntries($idsChunk);
					if (!is_array($valuesChunk)) {
						return null;
					}
					$values = array_merge($values, $valuesChunk);
				}
				return $values;
			}
			$idPlaceholders = str_repeat('?,', count($entries) - 1) . '?';
			$sql .= "\n" . <<<SQL
				AND et.id_entry IN ({$idPlaceholders})
				SQL;
			foreach ($entries as $entry) {
				$values[] = is_object($entry) ? $entry->id() : $entry;
			}
		}
		$stm = $this->pdo->prepare($sql);

		if ($stm !== false && $stm->execute($values)) {
			$result = $stm->fetchAll(PDO::FETCH_ASSOC);
			/** @var list<array{id_entry:int|numeric-string,id_tag:int,name:string}> $result; */
			return $result;
		}
		$info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
		Minz_Log::error('SQL error ' . __METHOD__ . json_encode($info));
		return null;
	}

	/**
	 * Produces an array: for each entry ID (prefixed by `e_`), associate a list of labels.
	 * Used by API and by JSON export, to speed up queries (would be very expensive to perform a label look-up on each entry individually).
	 * @param list<FreshRSS_Entry|numeric-string> $entries the list of entries for which to retrieve the labels.
	 * @return array<string,array<string>> An array of the shape `[e_id_entry => ["label 1", "label 2"]]`
	 */
	public function getEntryIdsTagNames(array $entries): array {
		$result = [];
		foreach ($this->getTagsForEntries($entries) ?? [] as $line) {
			$entryId = 'e_' . $line['id_entry'];
			$tagName = $line['name'];
			if (empty($result[$entryId])) {
				$result[$entryId] = [];
			}
			$result[$entryId][] = $tagName;
		}
		return $result;
	}

	/**
	 * @param iterable<array{id:int,name:string,attributes?:string,unreads?:int}> $listDAO
	 * @return array<int,FreshRSS_Tag> where the key is the label ID
	 */
	private static function daoToTags(iterable $listDAO): array {
		$list = [];
		foreach ($listDAO as $dao) {
			if (empty($dao['id']) || empty($dao['name'])) {
				continue;
			}
			$tag = new FreshRSS_Tag($dao['name']);
			$tag->_id($dao['id']);
			if (!empty($dao['attributes'])) {
				$tag->_attributes($dao['attributes']);
			}
			if (isset($dao['unreads'])) {
				$tag->_nbUnread($dao['unreads']);
			}
			$list[$tag->id()] = $tag;
		}
		return $list;
	}
}
