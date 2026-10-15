<?php
declare(strict_types=1);

final class TagPersistenceTest extends \PHPUnit\Framework\TestCase {
	private Minz_PdoSqlite $pdo;
	private FreshRSS_TagDAOSQLite $dao;

	#[\Override]
	protected function setUp(): void {
		if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
			self::markTestSkipped('pdo_sqlite is required');
		}
		$this->pdo = new Minz_PdoSqlite('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
		$this->pdo->exec('CREATE TABLE tag (id INTEGER PRIMARY KEY, name TEXT UNIQUE, attributes TEXT)');
		$this->pdo->exec('CREATE TABLE category (id INTEGER PRIMARY KEY, name TEXT)');
		$this->pdo->exec('CREATE TABLE entry (id INTEGER PRIMARY KEY)');
		$this->pdo->exec('CREATE TABLE entrytag (id_tag INTEGER REFERENCES tag(id) ON DELETE CASCADE,
			id_entry INTEGER REFERENCES entry(id) ON DELETE CASCADE, PRIMARY KEY(id_tag, id_entry))');
		$this->pdo->exec("INSERT INTO tag VALUES (1, 'first', '{}'), (2, 'second', '{}')");
		$this->pdo->exec('WITH RECURSIVE ids(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM ids WHERE n<1200)
			INSERT INTO entry SELECT n FROM ids');
		$this->dao = new FreshRSS_TagDAOSQLite(currentPdo: $this->pdo);
	}

	/** @return Generator<array{id_tag:int,id_entry:int}> */
	private function labels(): Generator {
		for ($id = 1; $id <= 1200; $id++) {
			yield ['id_tag' => 1, 'id_entry' => $id];
		}
	}

	public function testStreamingBatchIsBoundedAndDuplicateSafe(): void {
		self::assertSame(1200, $this->dao->tagEntries($this->labels()));
		self::assertSame(0, $this->dao->tagEntries($this->labels()));
		self::assertSame(1200, $this->dao->countEntries(1));
		self::assertSame([], $this->dao->getTagsForEntries([]));
		self::assertSame([], $this->dao->getEntryIdsTagNames([]));
	}

	public function testEmptyValidLabelSetSucceedsWithoutOpeningATransactionOrExecutingSql(): void {
		$pdo = new class ('sqlite::memory:') extends Minz_PdoSqlite {
			public bool $rejectSql = false;

			#[\Override]
			protected function preSql(string $statement): string {
				if ($this->rejectSql) {
					throw new LogicException('An empty label batch must not execute SQL');
				}
				return parent::preSql($statement);
			}

			#[\Override]
			public function beginTransaction(): bool {
				throw new LogicException('An empty label batch must not open a transaction');
			}
		};
		$pdo->rejectSql = true;
		$dao = new FreshRSS_TagDAOSQLite(currentPdo: $pdo);
		$empty = static function (): Generator {
			yield from [];
		};
		self::assertSame(0, $dao->tagEntries([]));
		self::assertSame(0, $dao->tagEntries($empty()));
		self::assertSame(0, $dao->tagEntries([
			['id_tag' => 0, 'id_entry' => '1'],
			['id_tag' => 1, 'id_entry' => '0'],
			['id_tag' => 1, 'id_entry' => '-1'],
		]));
	}

	public function testLaterChunkFailureRollsBackEarlierLabels(): void {
		$this->pdo->exec("CREATE TRIGGER fail_tag BEFORE INSERT ON entrytag WHEN NEW.id_entry=1100
			BEGIN SELECT RAISE(FAIL, 'injected failure'); END");
		self::assertFalse($this->dao->tagEntries($this->labels()));
		self::assertSame(0, $this->dao->countEntries(1));
		self::assertFalse($this->pdo->inTransaction());
	}

	public function testGeneratorExceptionRollsBackWithoutCommittingCallerWork(): void {
		$this->pdo->beginTransaction();
		$this->pdo->exec("UPDATE tag SET name='uncommitted' WHERE id=2");
		$labels = function (): Generator {
			yield from $this->labels();
			throw new RuntimeException('producer failed');
		};
		try {
			$this->dao->tagEntries($labels());
			self::fail('Expected producer exception');
		} catch (RuntimeException $e) {
			self::assertSame('producer failed', $e->getMessage());
		}
		self::assertTrue($this->pdo->inTransaction());
		self::assertSame(0, $this->dao->countEntries(1));
		self::assertSame('uncommitted', $this->dao->fetchString('SELECT name FROM tag WHERE id=2'));
		$this->pdo->rollBack();
	}

	public function testMergePreservesUnionAndSelfMergeDoesNotDeleteLabels(): void {
		$this->pdo->exec('INSERT INTO entrytag VALUES (1,1), (1,2), (2,2), (2,3)');
		self::assertSame(0, $this->dao->updateEntryTag(1, 1));
		self::assertSame(2, $this->dao->countEntries(1));
		self::assertSame(1, $this->dao->updateEntryTag(1, 2));
		self::assertSame(0, $this->dao->countEntries(1));
		self::assertSame(3, $this->dao->countEntries(2));
	}

	public function testMergeDeleteFailureRestoresBothSets(): void {
		$this->pdo->exec('INSERT INTO entrytag VALUES (1,1), (1,2), (2,2)');
		$this->pdo->exec("CREATE TRIGGER fail_delete BEFORE DELETE ON entrytag
			BEGIN SELECT RAISE(FAIL, 'injected failure'); END");
		self::assertFalse($this->dao->updateEntryTag(1, 2));
		self::assertSame(2, $this->dao->countEntries(1));
		self::assertSame(1, $this->dao->countEntries(2));
	}

	public function testExistingTagObjectReturnsStoredId(): void {
		$tag = new FreshRSS_Tag('first');
		self::assertSame(1, $this->dao->addTagObject($tag));
	}

	public function testLargeEntryIdRemainsExactAndInvalidIdsAreSkipped(): void {
		$this->pdo->exec('INSERT INTO entry VALUES(9007199254740993)');
		self::assertSame(1, $this->dao->tagEntries([
			['id_tag' => 1, 'id_entry' => '9007199254740993'],
			['id_tag' => 1, 'id_entry' => '-1'],
			['id_tag' => 1, 'id_entry' => '0'],
		]));
		self::assertSame('9007199254740993', $this->dao->fetchString('SELECT id_entry FROM entrytag'));
	}

	public function testInvalidAttributesLeaveExistingTagUntouched(): void {
		self::assertFalse($this->dao->updateTagAttributes(1, ['invalid' => INF]));
		self::assertSame('{}', $this->dao->fetchString('SELECT attributes FROM tag WHERE id=1'));
		self::assertFalse($this->dao->addTag(['name' => 'invalid', 'attributes' => ['invalid' => INF]]));
		self::assertSame(2, $this->dao->count());
	}

	public function testIgnoredTargetInsertDoesNotDeleteSourceLabels(): void {
		$this->pdo->exec('INSERT INTO entrytag VALUES (1,1), (1,2)');
		$this->pdo->exec("CREATE TRIGGER ignore_label BEFORE INSERT ON entrytag WHEN NEW.id_tag=2
			BEGIN SELECT RAISE(IGNORE); END");
		self::assertFalse($this->dao->updateEntryTag(1, 2));
		self::assertSame(2, $this->dao->countEntries(1));
		self::assertSame(0, $this->dao->countEntries(2));
	}
}
