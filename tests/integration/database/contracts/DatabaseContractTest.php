<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname(__DIR__) . '/fixtures/DatabaseFixture.php';

final class DatabaseContractTest extends \PHPUnit\Framework\TestCase {
	private ?DatabaseFixture $fixture = null;

	/** @return array<string,array{string}> */
	public static function backends(): array {
		$backends = ['sqlite' => ['sqlite']];
		if (getenv('FRESHRSS_TEST_PGSQL_DSN') !== false) {
			$backends['pgsql'] = ['pgsql'];
		}
		return $backends;
	}

	private function database(string $backend): DatabaseFixture {
		$this->fixture = new DatabaseFixture($backend);
		return $this->fixture;
	}

	#[\Override]
	protected function tearDown(): void {
		$this->fixture?->close();
	}

	#[DataProvider('backends')]
	public function testBatchUpdatesAcrossParameterChunks(string $backend): void {
		$db = $this->database($backend);
		$ids = $db->seed();
		self::assertSame(1200, $db->entries->markRead(array_merge($ids, [$ids[0]])));
		self::assertSame(0, $db->integer('SELECT "cache_nbUnreads" FROM feed WHERE id=1'));
		self::assertSame(123, $db->integer('SELECT "cache_nbUnreads" FROM feed WHERE id=2'));
		self::assertSame(0, $db->entries->markRead($ids));
		self::assertSame(1200, $db->entries->markRead($ids, false));
		self::assertSame(1200, $db->integer('SELECT "cache_nbUnreads" FROM feed WHERE id=1'));
	}

	#[DataProvider('backends')]
	public function testLaterChunkFailureRollsBackSilentWrites(string $backend): void {
		$db = $this->database($backend);
		$ids = $db->seed();
		$db->failOnUpdate('is_read', '1100');
		$db->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
		self::assertFalse($db->entries->markRead($ids));
		self::assertFalse($db->pdo->inTransaction());
		self::assertSame(0, $db->integer('SELECT SUM(is_read) FROM entry'));
		self::assertSame(1200, $db->integer('SELECT "cache_nbUnreads" FROM feed WHERE id=1'));
	}

	#[DataProvider('backends')]
	public function testExceptionSavepointPreservesCallerWork(string $backend): void {
		$db = $this->database($backend);
		$ids = $db->seed();
		$db->failOnUpdate('is_favorite', '1100');
		$db->pdo->beginTransaction();
		$db->pdo->exec('UPDATE entry SET "lastUserModified"=42 WHERE id=1');
		try {
			$db->entries->markFavorite($ids);
			self::fail('Expected the injected database failure');
		} catch (PDOException $exception) {
			self::assertStringContainsString('injected contract failure', $exception->getMessage());
		}
		self::assertTrue($db->pdo->inTransaction());
		self::assertSame(0, $db->integer('SELECT SUM(is_favorite) FROM entry'));
		self::assertSame(42, $db->integer('SELECT "lastUserModified" FROM entry WHERE id=1'));
		self::assertSame(1, $db->entries->markRead('1'));
		$db->pdo->rollBack();
		self::assertSame(0, $db->integer('SELECT is_read FROM entry WHERE id=1'));
	}

	#[DataProvider('backends')]
	public function testStagingCommitSortsDatesAndPreservesCallerTransaction(string $backend): void {
		$db = $this->database($backend);
		$db->seed(1, 9007199254740000);
		$db->pdo->exec("INSERT INTO entrytmp(id,guid,title,link,date,id_feed) VALUES
			(9007199254740100,'newer','Newer','https://example.invalid/newer',20,1),
			(9007199254740101,'older','Older','https://example.invalid/older',10,1)");
		$db->pdo->beginTransaction();
		self::assertTrue($db->entries->commitNewEntries());
		self::assertTrue($db->pdo->inTransaction());
		self::assertSame(0, $db->entries->countNewEntries());
		$rows = iterator_to_array($db->entries->listWhere('Z', limit: 10), false);
		self::assertSame(['Newer', 'Older', 'Contract title'], array_map(static fn(FreshRSS_Entry $entry): string => $entry->title(), $rows));
		$db->pdo->rollBack();
		self::assertSame(1, $db->integer('SELECT COUNT(*) FROM entry'));
		self::assertSame(2, $db->entries->countNewEntries());
		self::assertTrue($db->entries->commitNewEntries());
		self::assertSame(3, $db->integer('SELECT COUNT(*) FROM entry'));
		self::assertTrue($db->entries->commitNewEntries());
	}

	#[DataProvider('backends')]
	public function testStagingOverlapCannotDiscardAnUnrelatedArticle(string $backend): void {
		$db = $this->database($backend);
		$db->seed(2, 9007199254740000);
		$db->pdo->exec("INSERT INTO entrytmp(id,guid,title,link,date,id_feed) VALUES
			(9007199254740002,'new-guid','New article','https://example.invalid/new',10,1)");
		self::assertTrue($db->entries->commitNewEntries());
		self::assertSame(3, $db->integer('SELECT COUNT(*) FROM entry'));
		self::assertSame(1, $db->integer("SELECT COUNT(*) FROM entry WHERE guid='new-guid'"));
		self::assertSame(0, $db->entries->countNewEntries());
	}

	#[DataProvider('backends')]
	public function testFailedStagingCommitPreservesPendingArticlesAndCallerWork(string $backend): void {
		$db = $this->database($backend);
		$db->seed(1);
		$db->pdo->exec("INSERT INTO entrytmp(id,guid,title,link,date,id_feed) VALUES
			(100,'injected','Pending article','https://example.invalid/pending',10,1)");
		if ($backend === 'pgsql') {
			$db->pdo->exec("CREATE FUNCTION fail_insert_contract() RETURNS trigger LANGUAGE plpgsql AS
				'BEGIN RAISE EXCEPTION ''injected staging failure''; END';
				CREATE TRIGGER injected_insert BEFORE INSERT ON entry FOR EACH ROW EXECUTE FUNCTION fail_insert_contract()");
		} else {
			$db->pdo->exec("CREATE TRIGGER injected_insert BEFORE INSERT ON entry BEGIN SELECT RAISE(FAIL, 'injected staging failure'); END");
		}
		$db->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
		$db->pdo->beginTransaction();
		$db->pdo->exec('UPDATE entry SET "lastUserModified"=42 WHERE id=1');
		self::assertFalse($db->entries->commitNewEntries());
		self::assertTrue($db->pdo->inTransaction());
		self::assertSame(42, $db->integer('SELECT "lastUserModified" FROM entry WHERE id=1'));
		self::assertSame(1, $db->entries->countNewEntries());
		self::assertSame(1, $db->integer('SELECT COUNT(*) FROM entry'));
		$db->pdo->rollBack();
		$db->pdo->exec('DROP TRIGGER injected_insert' . ($backend === 'pgsql' ? ' ON entry' : ''));
		self::assertTrue($db->entries->commitNewEntries());
		self::assertSame(2, $db->integer('SELECT COUNT(*) FROM entry'));
		self::assertSame(0, $db->entries->countNewEntries());
	}

	#[DataProvider('backends')]
	public function testLargeIdsHaveGlobalOrderAcrossChunksAndStableUnreadPages(string $backend): void {
		$db = $this->database($backend);
		$ids = $db->seed(1200, 9007199254740000);
		$rows = iterator_to_array($db->entries->listByIds(array_merge($ids, [$ids[0]])), false);
		self::assertSame(array_reverse($ids), array_map(static fn(FreshRSS_Entry $entry): string => $entry->id(), $rows));
		$rows = iterator_to_array($db->entries->listByIds(array_reverse($ids), 'ASC'), false);
		self::assertSame($ids, array_map(static fn(FreshRSS_Entry $entry): string => $entry->id(), $rows));
		$rows = iterator_to_array($db->entries->listSummaries('Z', state: FreshRSS_Entry::STATE_NOT_READ, limit: 600), false);
		self::assertCount(600, $rows);
		self::assertSame('', $rows[0]->content());
		$cursor = $rows[599]->id();
		self::assertSame(1, $db->entries->markRead($cursor));
		$next = iterator_to_array($db->entries->listSummaries('Z', state: FreshRSS_Entry::STATE_NOT_READ,
			idMax: $ids[599], limit: 600), false);
		self::assertSame(array_reverse(array_slice($ids, 0, 600)), array_map(static fn(FreshRSS_Entry $entry): string => $entry->id(), $next));
	}

	#[DataProvider('backends')]
	public function testStarredOrTaggedRespectsUnreadStateAndCursor(string $backend): void {
		$db = $this->database($backend);
		$db->seed(3);
		$db->pdo->exec('UPDATE entry SET is_favorite=1');
		$db->pdo->exec('UPDATE entry SET is_read=1 WHERE id=1');
		$rows = iterator_to_array($db->entries->listWhere('ST', state: FreshRSS_Entry::STATE_NOT_READ, id_max: '2', limit: 10), false);
		self::assertSame(['2'], array_map(static fn(FreshRSS_Entry $entry): string => $entry->id(), $rows));
	}

	#[DataProvider('backends')]
	public function testAnyTagSortsByCategoryAndFeedWithoutDuplicatingEntries(string $backend): void {
		$db = $this->database($backend);
		$db->seed(3);
		$db->pdo->exec("INSERT INTO tag(id,name) VALUES (1,'First'),(2,'Second');
			INSERT INTO entrytag(id_tag,id_entry) VALUES (1,1),(2,1),(1,2),(2,3)");
		$rows = iterator_to_array($db->entries->listWhere('T', sort: 'c.name', secondary_sort: 'date', limit: 10), false);
		self::assertSame(['3', '2', '1'], array_map(static fn(FreshRSS_Entry $entry): string => $entry->id(), $rows));
		$rows = iterator_to_array($db->entries->listWhere('T', sort: 'f.name', secondary_sort: 'title', limit: 10), false);
		self::assertSame(['3', '2', '1'], array_map(static fn(FreshRSS_Entry $entry): string => $entry->id(), $rows));
	}

	#[DataProvider('backends')]
	public function testReadTagAndFeedRoundTripsMaintainCountsAndOuterTransaction(string $backend): void {
		$db = $this->database($backend);
		$db->seed(4);
		$db->pdo->exec("INSERT INTO tag(id,name) VALUES (1,'Contract');
			INSERT INTO entrytag(id_tag,id_entry) VALUES (1,1),(1,2)");
		$db->pdo->beginTransaction();
		self::assertSame(2, $db->entries->markReadTag(1, '4'));
		self::assertSame(2, $db->integer('SELECT "cache_nbUnreads" FROM feed WHERE id=1'));
		self::assertSame(2, $db->entries->markReadFeed(1, '4', is_read: false));
		self::assertSame(4, $db->integer('SELECT "cache_nbUnreads" FROM feed WHERE id=1'));
		self::assertSame(2, $db->entries->markReadFeed(1, '2'));
		self::assertSame(2, $db->integer('SELECT "cache_nbUnreads" FROM feed WHERE id=1'));
		self::assertTrue($db->pdo->inTransaction());
		$db->pdo->rollBack();
		self::assertSame(0, $db->integer('SELECT SUM(is_read) FROM entry'));
		self::assertSame(4, $db->integer('SELECT "cache_nbUnreads" FROM feed WHERE id=1'));
	}

	public function testMysqlSchemaNullabilityMetadata(): void {
		$db = $this->database('sqlite');
		// The mapper is independent of the connection; this does not simulate a MySQL server.
		$dao = new FreshRSS_DatabaseDAO(currentPdo: $db->pdo);
		self::assertTrue($dao->daoToSchema(['Field' => 'name', 'Type' => 'varchar(191)', 'Null' => 'NO'])['notnull']);
		self::assertFalse($dao->daoToSchema(['Field' => 'description', 'Type' => 'text', 'Null' => 'YES'])['notnull']);
	}

	private function indexDefinition(DatabaseFixture $db): string {
		$sql = $db->backend === 'pgsql' ? "SELECT pg_get_indexdef(to_regclass('entry_feed_read_index'))"
			: "SELECT sql FROM sqlite_master WHERE type='index' AND name='entry_feed_read_index'";
		$statement = $db->pdo->query($sql);
		self::assertInstanceOf(PDOStatement::class, $statement);
		$definition = $statement->fetchColumn();
		self::assertIsString($definition);
		return preg_replace('/[\s`"]+/', '', $definition) ?? '';
	}

	#[DataProvider('backends')]
	public function testReaderIndexUpgradeIsAtomicAndIdempotent(string $backend): void {
		$db = $this->database($backend);
		$db->seed(3);
		self::assertStringContainsString('(id_feed,is_read,id)', $this->indexDefinition($db));
		$db->pdo->exec('DROP INDEX entry_feed_read_index; CREATE INDEX entry_feed_read_index ON entry(id_feed,is_read)');
		$dao = $backend === 'pgsql' ? new FreshRSS_DatabaseDAOPGSQL(currentPdo: $db->pdo)
			: new FreshRSS_DatabaseDAOSQLite(currentPdo: $db->pdo);
		$db->pdo->beginTransaction();
		$db->pdo->exec('UPDATE entry SET "lastUserModified"=42 WHERE id=1');
		self::assertTrue($dao->upgradeReaderIndexes());
		self::assertTrue($db->pdo->inTransaction());
		self::assertStringContainsString('(id_feed,is_read,id)', $this->indexDefinition($db));
		$db->pdo->rollBack();
		self::assertStringContainsString('(id_feed,is_read)', $this->indexDefinition($db));
		self::assertSame(3, $db->integer('SELECT COUNT(*) FROM entry'));
		self::assertTrue($dao->upgradeReaderIndexes());
		self::assertStringContainsString('(id_feed,is_read,id)', $this->indexDefinition($db));
		$identitySql = $backend === 'pgsql' ? "SELECT to_regclass('entry_feed_read_index')::oid" : 'PRAGMA schema_version';
		$identity = $db->integer($identitySql);
		self::assertTrue($dao->upgradeReaderIndexes());
		self::assertSame($identity, $db->integer($identitySql), 'An already upgraded index must not be rebuilt');
	}

	#[DataProvider('backends')]
	public function testSchemaReportsNullabilityForOnlyTheVisibleTable(string $backend): void {
		$db = $this->database($backend);
		$schema = $db->schema . '_decoy';
		if ($backend === 'pgsql') {
			$db->pdo->exec("CREATE SCHEMA {$schema}; CREATE TABLE {$schema}.entry (decoy INTEGER)");
			// The first schema has no feed table; PostgreSQL must resolve it through the search path.
			$db->pdo->exec("SET search_path TO {$schema}, {$db->schema}");
		}
		try {
			$dao = $backend === 'pgsql' ? new FreshRSS_DatabaseDAOPGSQL(currentPdo: $db->pdo)
				: new FreshRSS_DatabaseDAOSQLite(currentPdo: $db->pdo);
			$columns = $dao->getSchema('feed');
			$names = array_column($columns, 'name');
			self::assertNotContains('decoy', $names);
			self::assertCount(16, $columns);
			$nullability = array_column($columns, 'notnull', 'name');
			self::assertTrue($nullability['name']);
			self::assertFalse($nullability['description']);
			if ($backend === 'pgsql') {
				self::assertSame(['decoy'], array_column($dao->getSchema('entry'), 'name'));
				$db->pdo->exec('SET search_path TO ' . $db->schema);
				self::assertNotContains('decoy', array_column($dao->getSchema('entry'), 'name'));
				$db->pdo->setPrefix('contract_user_');
				require APP_PATH . '/SQL/install.sql.pgsql.php';
				$sql = $GLOBALS['SQL_CREATE_TABLES'];
				self::assertIsString($sql);
				$db->pdo->exec($sql);
				self::assertTrue(array_column($dao->getSchema('entry'), 'notnull', 'name')['guid']);
				$db->pdo->exec('DROP INDEX contract_user_entry_feed_read_index;
					CREATE INDEX contract_user_entry_feed_read_index ON contract_user_entry(id_feed,is_read)');
				self::assertTrue($dao->upgradeReaderIndexes());
				self::assertTrue($dao->upgradeReaderIndexes());
			}
		} finally {
			if ($backend === 'pgsql') {
				$db->pdo->exec('DROP SCHEMA ' . $schema . ' CASCADE');
			}
		}
	}
}
