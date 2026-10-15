<?php
declare(strict_types=1);

final class EntryPersistenceTest extends \PHPUnit\Framework\TestCase {
	private Minz_PdoSqlite $pdo;
	private FreshRSS_EntryDAOSQLite $dao;

	#[\Override]
	protected function setUp(): void {
		if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
			self::markTestSkipped('pdo_sqlite is required');
		}
		$this->pdo = new class ('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]) extends Minz_PdoSqlite {
			#[\Override]
			public function prepare(string $query, array $options = []): PDOStatement|false {
				// Exercise the supported parameter ceiling even with a newer SQLite build that permits more.
				return substr_count($query, '?') > FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER ? false : parent::prepare($query, $options);
			}
		};
		require APP_PATH . '/SQL/install.sql.sqlite.php';
		$schema = $GLOBALS['SQL_CREATE_TABLES'];
		self::assertIsString($schema);
		self::assertNotFalse($this->pdo->exec($schema));
		$this->pdo->exec("INSERT INTO feed(id, url, category, name) VALUES (1, 'https://example.net/1', 1, 'one'),
			(2, 'https://example.net/2', 1, 'two')");
		$this->dao = new FreshRSS_EntryDAOSQLite(currentPdo: $this->pdo);
	}

	/** @param numeric-string $id */
	private function entry(string $id, int $feed = 1, string $guid = ''): FreshRSS_Entry {
		$entry = new FreshRSS_Entry($feed, $guid === '' ? 'guid-' . $id : $guid, 'Title', '', 'Content', 'https://example.net/item');
		$entry->_id($id);
		return $entry;
	}

	private function tableCount(string $table): int {
		$statement = $this->pdo->query('SELECT COUNT(*) FROM ' . $table);
		self::assertInstanceOf(PDOStatement::class, $statement);
		return (int)$statement->fetchColumn();
	}

	public function testPreparedInsertCanSwitchBetweenStagingAndDestination(): void {
		self::assertTrue($this->dao->addEntry($this->entry('101')->toArray()));
		self::assertTrue($this->dao->addEntry($this->entry('102')->toArray(), false));
		self::assertTrue($this->dao->addEntry($this->entry('103')->toArray()));
		self::assertSame(2, $this->tableCount('entrytmp'));
		self::assertSame(1, $this->tableCount('entry'));
		self::assertNotNull($this->dao->searchById('102'));
	}

	public function testLegacySchemaMigrationPreservesDirectInsertDestination(): void {
		$this->pdo->exec('ALTER TABLE entry DROP COLUMN attributes');
		$this->pdo->exec('ALTER TABLE entrytmp DROP COLUMN attributes');
		self::assertTrue($this->dao->addEntry($this->entry('101')->toArray(), false));
		self::assertSame(1, $this->tableCount('entry'));
		self::assertSame(0, $this->tableCount('entrytmp'));
	}

	public function testMigrationDoesNotCommitCallerTransaction(): void {
		$this->pdo->exec('ALTER TABLE entry DROP COLUMN attributes');
		$this->pdo->exec('ALTER TABLE entrytmp DROP COLUMN attributes');
		$this->pdo->beginTransaction();
		$this->pdo->exec("UPDATE feed SET name='uncommitted' WHERE id=1");
		self::assertFalse($this->dao->addEntry($this->entry('101')->toArray(), false));
		self::assertTrue($this->pdo->inTransaction());
		$this->pdo->rollBack();
		self::assertSame('one', $this->dao->fetchString('SELECT name FROM feed WHERE id=1'));
		self::assertTrue($this->dao->addEntry($this->entry('101')->toArray(), false));
	}

	public function testGuidDeduplicationIsScopedToFeedAndDeletionCascadesToLabels(): void {
		self::assertTrue($this->dao->addEntry($this->entry('9007199254740993', 1, 'shared-guid')->toArray(), false));
		self::assertTrue($this->dao->addEntry($this->entry('9007199254740994', 2, 'shared-guid')->toArray(), false));
		self::assertTrue($this->dao->addEntry($this->entry('9007199254740995', 1, 'shared-guid')->toArray(), false));
		self::assertSame(2, $this->tableCount('entry'));
		$this->pdo->exec("INSERT INTO tag(id, name) VALUES(1, 'label')");
		$this->pdo->exec('INSERT INTO entrytag(id_tag, id_entry) VALUES(1, 9007199254740993), (1, 9007199254740994)');
		$this->pdo->exec('DELETE FROM feed WHERE id=1');
		self::assertSame(1, $this->tableCount('entry'));
		self::assertSame(1, $this->tableCount('entrytag'));
		self::assertSame('9007199254740994', $this->dao->fetchString('SELECT id_entry FROM entrytag'));
	}

	public function testStagingCommitAvoidsExistingIdsAndPreservesChronologicalOrder(): void {
		self::assertTrue($this->dao->addEntry($this->entry('9007199254740993', 2)->toArray(), false));
		$older = $this->entry('9007199254740992');
		$older->_date(100);
		$newer = $this->entry('9007199254740991');
		$newer->_date(200);
		self::assertTrue($this->dao->addEntry($newer->toArray()));
		self::assertTrue($this->dao->addEntry($older->toArray()));
		self::assertTrue($this->dao->commitNewEntries());
		self::assertSame(3, $this->tableCount('entry'));
		self::assertSame(0, $this->tableCount('entrytmp'));
		self::assertSame($older->guid(), $this->dao->searchById('9007199254740994')?->guid());
		self::assertSame($newer->guid(), $this->dao->searchById('9007199254740995')?->guid());
	}

	public function testIgnoredStagedInsertRollsBackWithoutLosingAnyArticle(): void {
		self::assertTrue($this->dao->addEntry($this->entry('101')->toArray()));
		self::assertTrue($this->dao->addEntry($this->entry('102')->toArray()));
		$this->pdo->exec("CREATE TRIGGER ignore_entry BEFORE INSERT ON entry WHEN NEW.guid='guid-102'
			BEGIN SELECT RAISE(IGNORE); END");
		self::assertFalse($this->dao->commitNewEntries());
		self::assertSame(0, $this->tableCount('entry'));
		self::assertSame(2, $this->tableCount('entrytmp'));
		self::assertFalse($this->pdo->inTransaction());
		$this->pdo->exec('DROP TRIGGER ignore_entry');
		self::assertTrue($this->dao->commitNewEntries());
		self::assertSame(2, $this->tableCount('entry'));
	}

	public function testStagingCommitCannotDropAPersistentTableNamedTmp(): void {
		$this->pdo->exec('CREATE TABLE tmp (value INTEGER)');
		$this->pdo->exec('INSERT INTO tmp VALUES(42)');
		self::assertTrue($this->dao->addEntry($this->entry('101')->toArray()));
		self::assertTrue($this->dao->commitNewEntries());
		self::assertSame(42, $this->dao->fetchInt('SELECT value FROM tmp'));
	}

	public function testStarredOrTaggedAppliesReadStateAndIdBoundaryToBothBranches(): void {
		foreach (['101', '102', '103'] as $id) {
			self::assertTrue($this->dao->addEntry($this->entry($id)->toArray(), false));
		}
		$this->pdo->exec('UPDATE entry SET is_favorite=1');
		$this->pdo->exec('UPDATE entry SET is_read=1 WHERE id=101');
		self::assertSame(['102'], $this->dao->listIdsWhere('ST', state: FreshRSS_Entry::STATE_NOT_READ, id_max: '102', limit: 10));
	}

	public function testMarkFeedUnreadRestoresItsUnreadCount(): void {
		self::assertTrue($this->dao->addEntry($this->entry('101')->toArray(), false));
		self::assertSame(1, $this->dao->markReadFeed(1, '101'));
		self::assertSame(0, $this->dao->fetchInt('SELECT cache_nbUnreads FROM feed WHERE id=1'));
		self::assertSame(1, $this->dao->markReadFeed(1, '101', is_read: false));
		self::assertSame(1, $this->dao->fetchInt('SELECT cache_nbUnreads FROM feed WHERE id=1'));
	}

	public function testBulkCounterFailureRollsBackTheStateAndPreservesCallerTransaction(): void {
		self::assertTrue($this->dao->addEntry($this->entry('101')->toArray(), false));
		$this->pdo->exec("INSERT INTO tag(id, name) VALUES(1, 'label')");
		$this->pdo->exec('INSERT INTO entrytag(id_tag, id_entry) VALUES(1, 101)');
		$this->pdo->exec("CREATE TRIGGER fail_counter BEFORE UPDATE OF cache_nbUnreads ON feed
			BEGIN SELECT RAISE(FAIL, 'injected counter failure'); END");
		$this->pdo->beginTransaction();
		$this->pdo->exec("UPDATE feed SET name='uncommitted' WHERE id=1");
		self::assertFalse($this->dao->markReadFeed(1, '101'));
		self::assertFalse($this->dao->markReadTag(1, '101'));
		self::assertFalse($this->dao->markReadCat(1, '101'));
		self::assertFalse($this->dao->markReadEntries('101'));
		self::assertSame(0, $this->dao->fetchInt('SELECT is_read FROM entry WHERE id=101'));
		self::assertTrue($this->pdo->inTransaction());
		self::assertSame('uncommitted', $this->dao->fetchString('SELECT name FROM feed WHERE id=1'));
		$this->pdo->rollBack();
	}

	public function testGuidBatchesReserveNonGuidParametersAndRollBackLaterFailure(): void {
		$guids = [];
		$this->pdo->beginTransaction();
		for ($id = 1; $id <= 1100; $id++) {
			$entry = $this->entry((string)$id);
			self::assertTrue($this->dao->addEntry($entry->toArray(), false));
			$guids[] = $entry->guid();
		}
		$this->pdo->commit();
		self::assertCount(1100, $this->dao->listHashForFeedGuids(1, $guids));
		self::assertSame(1100, $this->dao->updateLastSeen(1, $guids, 100));
		$this->pdo->exec("CREATE TRIGGER fail_seen BEFORE UPDATE OF lastSeen ON entry WHEN NEW.id=1100
			BEGIN SELECT RAISE(FAIL, 'injected last seen failure'); END");
		self::assertFalse($this->dao->updateLastSeen(1, $guids, 200));
		self::assertSame(1100, $this->dao->fetchInt('SELECT COUNT(*) FROM entry WHERE lastSeen=100'));
		self::assertFalse($this->pdo->inTransaction());
	}

	public function testInvalidAttributesCannotInsertOrOverwriteAnArticle(): void {
		$entry = $this->entry('101');
		self::assertTrue($this->dao->addEntry($entry->toArray(), false));
		$entry->_title('must not persist');
		$entry->_attributes(['invalid' => INF]);
		self::assertFalse($this->dao->updateEntry($entry->toArray()));
		self::assertSame('Title', $this->dao->searchById('101')?->title());
		$entry->_id('102');
		self::assertFalse($this->dao->addEntry($entry->toArray()));
		self::assertSame(0, $this->tableCount('entrytmp'));
	}
}
