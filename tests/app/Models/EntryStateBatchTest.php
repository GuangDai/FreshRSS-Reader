<?php
declare(strict_types=1);

final class EntryStateBatchTest extends \PHPUnit\Framework\TestCase {
	private Minz_PdoSqlite $pdo;
	private FreshRSS_EntryDAOSQLite $dao;

	#[\Override]
	protected function setUp(): void {
		if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
			self::markTestSkipped('pdo_sqlite is required');
		}
		$this->pdo = new Minz_PdoSqlite('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
		$this->pdo->exec('CREATE TABLE entry (id INTEGER PRIMARY KEY, id_feed INTEGER, is_read INTEGER DEFAULT 0,
			is_favorite INTEGER DEFAULT 0, lastUserModified INTEGER DEFAULT 0)');
		$this->pdo->exec('CREATE TABLE feed (id INTEGER PRIMARY KEY, cache_nbUnreads INTEGER DEFAULT 0)');
		$this->pdo->exec('INSERT INTO feed VALUES (1, 1200), (2, 123)');
		$this->pdo->exec('WITH RECURSIVE ids(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM ids WHERE n<1200)
			INSERT INTO entry(id, id_feed) SELECT n, 1 FROM ids');
		$this->dao = new FreshRSS_EntryDAOSQLite(currentPdo: $this->pdo);
	}

	private function queryInteger(string $sql): int {
		$statement = $this->pdo->query($sql);
		self::assertInstanceOf(PDOStatement::class, $statement);
		return (int)$statement->fetchColumn();
	}

	/** @return list<numeric-string> */
	private function ids(): array {
		return array_map(static fn(int $id): string => (string)$id, range(1, 1200));
	}

	public function testReadBatchUpdatesOnlyTouchedFeedsAndSupportsRepeatedIds(): void {
		self::assertSame(1200, $this->dao->markRead(array_merge($this->ids(), ['1'])));
		self::assertSame(0, $this->queryInteger('SELECT cache_nbUnreads FROM feed WHERE id=1'));
		self::assertSame(123, $this->queryInteger('SELECT cache_nbUnreads FROM feed WHERE id=2'));
		self::assertSame(0, $this->dao->markRead($this->ids()));
	}

	public function testLaterChunkFailureRollsBackAllReadChangesAndCounts(): void {
		$this->pdo->exec("CREATE TRIGGER fail_read BEFORE UPDATE OF is_read ON entry WHEN NEW.id=1100
			BEGIN SELECT RAISE(FAIL, 'injected write failure'); END");
		self::assertFalse($this->dao->markRead($this->ids()));
		self::assertSame(0, $this->queryInteger('SELECT SUM(is_read) FROM entry'));
		self::assertSame(1200, $this->queryInteger('SELECT cache_nbUnreads FROM feed WHERE id=1'));
		self::assertFalse($this->pdo->inTransaction());
	}

	public function testDatabaseExceptionAlsoRollsBackTheBatch(): void {
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo->exec("CREATE TRIGGER fail_read BEFORE UPDATE OF is_read ON entry WHEN NEW.id=1100
			BEGIN SELECT RAISE(FAIL, 'injected exception'); END");
		try {
			$this->dao->markRead($this->ids());
			self::fail('Expected the database exception');
		} catch (PDOException $e) {
			self::assertStringContainsString('injected exception', $e->getMessage());
		}
		self::assertFalse($this->pdo->inTransaction());
		self::assertSame(0, $this->queryInteger('SELECT SUM(is_read) FROM entry'));
	}

	public function testFavoriteFailurePreservesOuterTransactionAndEarlierWork(): void {
		$this->pdo->beginTransaction();
		$this->pdo->exec('UPDATE entry SET lastUserModified=42 WHERE id=1');
		$this->pdo->exec("CREATE TRIGGER fail_favorite BEFORE UPDATE OF is_favorite ON entry WHEN NEW.id=1100
			BEGIN SELECT RAISE(FAIL, 'injected write failure'); END");
		self::assertFalse($this->dao->markFavorite($this->ids()));
		self::assertTrue($this->pdo->inTransaction());
		self::assertSame(0, $this->queryInteger('SELECT SUM(is_favorite) FROM entry'));
		self::assertSame(42, $this->queryInteger('SELECT lastUserModified FROM entry WHERE id=1'));
		$this->pdo->rollBack();
	}

	public function testSuccessfulStateChangeDoesNotCommitCallerTransaction(): void {
		$this->pdo->beginTransaction();
		self::assertSame(1, $this->dao->markRead('1'));
		self::assertSame(1, $this->dao->markFavorite('1'));
		self::assertTrue($this->pdo->inTransaction());
		$this->pdo->rollBack();
		self::assertSame(0, $this->queryInteger('SELECT is_read+is_favorite FROM entry WHERE id=1'));
	}
	public function testExistingIdsSelectsOnlyPresentUniqueIdsAcrossChunks(): void {
		$ids = $this->dao->existingIds(array_merge($this->ids(), ['1', '9999']));
		self::assertNotNull($ids);
		self::assertCount(1200, $ids);
		self::assertSame([], $this->dao->existingIds([]));
		self::assertNotContains('9999', $ids);
		$this->pdo->exec('DROP TABLE entry');
		self::assertNull($this->dao->existingIds(['1']));
	}

	public function testFailedCommitNewEntriesRollsBackItsTemporaryTable(): void {
		$this->pdo->exec('CREATE TABLE entrytmp (id INTEGER, guid TEXT, title TEXT, author TEXT, content TEXT,
			link TEXT, date INTEGER, lastSeen INTEGER, hash TEXT, is_read INTEGER, is_favorite INTEGER,
			id_feed INTEGER, tags TEXT, attributes TEXT)');
		// The destination intentionally lacks required columns; the temporary table must not survive the failed insert.
		self::assertFalse($this->dao->commitNewEntries());
		self::assertFalse($this->pdo->inTransaction());
		self::assertSame(0, $this->queryInteger("SELECT COUNT(*) FROM sqlite_temp_master WHERE name='freshrss_entry_commit'"));
		$this->pdo->beginTransaction();
		$this->pdo->exec('UPDATE entry SET lastUserModified=84 WHERE id=1');
		self::assertFalse($this->dao->commitNewEntries());
		self::assertTrue($this->pdo->inTransaction());
		self::assertSame(84, $this->queryInteger('SELECT lastUserModified FROM entry WHERE id=1'));
		self::assertSame(0, $this->queryInteger("SELECT COUNT(*) FROM sqlite_temp_master WHERE name='freshrss_entry_commit'"));
		$this->pdo->rollBack();
	}

	public function testListByIdsKeepsGlobalOrderAcrossChunksWithoutLosingLargeIdPrecision(): void {
		foreach (['guid', 'title', 'author', 'link', 'tags', 'attributes', 'content', 'hash'] as $column) {
			$this->pdo->exec("ALTER TABLE entry ADD COLUMN {$column} TEXT DEFAULT ''");
		}
		$this->pdo->exec('ALTER TABLE entry ADD COLUMN date INTEGER DEFAULT 0');
		$this->pdo->exec('ALTER TABLE entry ADD COLUMN lastModified INTEGER DEFAULT 0');
		$this->pdo->exec('UPDATE entry SET id=id+9007199254740000');
		$ids = array_map(static fn(int $id): string => (string)($id + 9007199254740000), range(1, 1200));
		$entries = iterator_to_array($this->dao->listByIds(array_merge($ids, [$ids[0]])), false);
		self::assertSame(array_reverse($ids), array_map(static fn(FreshRSS_Entry $entry): string => $entry->id(), $entries));
		$entries = iterator_to_array($this->dao->listByIds(array_reverse($ids), 'ASC'), false);
		self::assertSame($ids, array_map(static fn(FreshRSS_Entry $entry): string => $entry->id(), $entries));
	}

}
