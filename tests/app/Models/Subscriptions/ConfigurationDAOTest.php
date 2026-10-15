<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Models\Subscriptions;

use FreshRSS\Exceptions\Reader\ApiException;
use FreshRSS\Models\Subscriptions\ConfigurationDAO;
use FreshRSS\Services\Subscriptions\ConfigurationService as SubscriptionConfigurationService;
use LogicException;
use Minz_PdoSqlite;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type Change from ConfigurationDAO */
final class ConfigurationDAOTest extends TestCase {
	private Minz_PdoSqlite $pdo;
	private ConfigurationDAO $dao;

	#[\Override]
	protected function setUp(): void {
		if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
			self::markTestSkipped('pdo_sqlite is required');
		}
		$this->pdo = new Minz_PdoSqlite('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
		$this->pdo->exec('PRAGMA foreign_keys=ON');
		$this->pdo->exec('CREATE TABLE feed (id INTEGER PRIMARY KEY,name TEXT,category INTEGER REFERENCES category(id) ON DELETE SET NULL,
			priority INTEGER,ttl INTEGER,
			cache_nbUnreads INTEGER,error INTEGER)');
		$this->pdo->exec('CREATE TABLE category (id INTEGER PRIMARY KEY,name TEXT COLLATE NOCASE UNIQUE,kind INTEGER)');
		$this->pdo->exec('CREATE TABLE tag (id INTEGER PRIMARY KEY,name TEXT)');
		$this->pdo->exec("INSERT INTO category VALUES (1,'Default',0),(2,'Second',0),(3,'Third',0)");
		$this->pdo->exec("INSERT INTO feed VALUES (1,'One',2,10,0,4,0),(2,'Two',3,20,-120,2,0)");
		$this->dao = new ConfigurationDAO(currentPdo: $this->pdo);
	}

	/** @param 'feed'|'category' $type
	 * @param array<string,mixed> $patch
	 * @return Change
	 */
	private function change(string $type, int $id, array $patch): array {
		$rows = $this->dao->catalog(3600)[$type === 'feed' ? 'feeds' : 'categories'];
		foreach ($rows as $row) {
			if ($row['id'] === $id) {
				return SubscriptionConfigurationService::validateItems([
					['type' => $type, 'id' => $id, 'expectedVersion' => $row['version'], 'changes' => $patch],
				], 2)[0];
			}
		}
		throw new LogicException('Fixture row not found');
	}

	private function value(string $sql): mixed {
		$statement = $this->pdo->query($sql);
		self::assertInstanceOf(PDOStatement::class, $statement);
		return $statement->fetchColumn();
	}

	public function testMixedFeedAndCategoryChangesCommitTogether(): void {
		$result = $this->dao->apply([
			$this->change('feed', 1, ['ttl' => 90, 'enabled' => false]),
			$this->change('feed', 2, ['categoryId' => 2, 'enabled' => true]),
			$this->change('category', 2, ['name' => 'Changed & encoded']),
		], 3600);
		self::assertSame(-90, $this->value('SELECT ttl FROM feed WHERE id=1'));
		self::assertSame(120, $this->value('SELECT ttl FROM feed WHERE id=2'));
		self::assertSame(2, $this->value('SELECT category FROM feed WHERE id=2'));
		self::assertSame('Changed &amp; encoded', $this->value('SELECT name FROM category WHERE id=2'));
		self::assertSame('Changed & encoded', $result['categories'][0]['name']);
		self::assertFalse($result['feeds'][0]['enabled']);
		self::assertFalse($this->pdo->inTransaction());
	}

	public function testStaleVersionRejectsEveryItemBeforeWriting(): void {
		$stale = $this->change('feed', 1, ['ttl' => 600]);
		$other = $this->change('feed', 2, ['name' => 'Should not persist']);
		$this->pdo->exec("UPDATE feed SET name='Another session' WHERE id=1");
		try {
			$this->dao->apply([$other, $stale], 3600);
			self::fail('Stale version should fail');
		} catch (ApiException $exception) {
			self::assertSame(409, $exception->status);
			self::assertSame('configuration_conflict', $exception->getMessage());
			self::assertArrayHasKey('conflicts', $exception->details);
		}
		self::assertSame('Two', $this->value('SELECT name FROM feed WHERE id=2'));
		self::assertSame(0, $this->value('SELECT ttl FROM feed WHERE id=1'));
	}

	public function testDynamicCountersDoNotInvalidateConfigurationVersion(): void {
		$before = $this->dao->catalog(3600)['feeds'][0]['version'];
		$this->pdo->exec('UPDATE feed SET cache_nbUnreads=99,error=42 WHERE id=1');
		$after = $this->dao->catalog(3600)['feeds'][0];
		self::assertSame($before, $after['version']);
		self::assertSame(99, $after['unread']);
		self::assertTrue($after['error']);
	}

	public function testLaterDatabaseFailureRollsBackEarlierUpdatesAndPreservesOuterTransaction(): void {
		$this->pdo->exec("CREATE TRIGGER fail_second BEFORE UPDATE ON feed WHEN NEW.id=2 BEGIN SELECT RAISE(FAIL,'test failure'); END");
		$this->pdo->beginTransaction();
		$this->pdo->exec("UPDATE feed SET name='Outer transaction' WHERE id=1");
		try {
			$this->dao->apply([$this->change('feed', 1, ['ttl' => 60]), $this->change('feed', 2, ['ttl' => 60])], 3600);
			self::fail('SQL failure expected');
		} catch (ApiException $exception) {
			self::assertSame(500, $exception->status);
			self::assertTrue($exception->details['atomic']);
		}
		self::assertTrue($this->pdo->inTransaction());
		self::assertSame(0, $this->value('SELECT ttl FROM feed WHERE id=1'));
		self::assertSame('Outer transaction', $this->value('SELECT name FROM feed WHERE id=1'));
		$this->pdo->rollBack();
	}

	public function testDatabaseNameCollationConflictRollsBackWholeMixedBatch(): void {
		try {
			$this->dao->apply([$this->change('feed', 1, ['ttl' => 60]), $this->change('category', 2, ['name' => 'Same']),
				$this->change('category', 3, ['name' => 'same'])], 3600);
			self::fail('Database uniqueness failure expected');
		} catch (ApiException $exception) {
			self::assertSame(409, $exception->status);
			self::assertSame('name_conflict', $exception->getMessage());
		}
		self::assertSame(0, $this->value('SELECT ttl FROM feed WHERE id=1'));
		self::assertSame('Second', $this->value('SELECT name FROM category WHERE id=2'));
	}

	public function testDefaultCategoryCannotBeRenamedInMixedBatch(): void {
		try {
			$this->dao->apply([$this->change('feed', 1, ['ttl' => 60]), $this->change('category', 1, ['name' => 'Renamed'])], 3600);
			self::fail('Default category must remain unchanged');
		} catch (ApiException $exception) {
			self::assertSame('default_category_read_only', $exception->getMessage());
		}
		self::assertSame(0, $this->value('SELECT ttl FROM feed WHERE id=1'));
	}

	public function testDisablingAndEnablingInheritedTtlKeepsDefaultSemantics(): void {
		$disabled = $this->dao->apply([$this->change('feed', 1, ['enabled' => false])], 3600)['feeds'][0];
		self::assertSame(0, $disabled['ttl']);
		self::assertFalse($disabled['enabled']);
		self::assertSame(-3600, $this->value('SELECT ttl FROM feed WHERE id=1'));
		$enabled = $this->dao->apply([$this->change('feed', 1, ['enabled' => true])], 3600)['feeds'][0];
		self::assertSame(0, $enabled['ttl']);
		self::assertTrue($enabled['enabled']);
		self::assertSame(0, $this->value('SELECT ttl FROM feed WHERE id=1'));
	}
	public function testUnrelatedPatchPreservesAnOrphanedNullableCategory(): void {
		$this->pdo->exec('DELETE FROM category WHERE id=3');
		self::assertNull($this->value('SELECT category FROM feed WHERE id=2'));
		$this->dao->apply([$this->change('feed', 2, ['name' => 'Still editable'])], 3600);
		self::assertNull($this->value('SELECT category FROM feed WHERE id=2'));
		self::assertSame('Still editable', $this->value('SELECT name FROM feed WHERE id=2'));
	}

}
