<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname(__DIR__) . '/fixtures/DatabaseFixture.php';

/** @phpstan-import-type Change from FreshRSS\Models\Subscriptions\ConfigurationDAO */
final class SubscriptionConfigDatabaseTest extends \PHPUnit\Framework\TestCase {
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
		$this->fixture->pdo->exec("INSERT INTO category(id,name) VALUES(2,'Contract category')");
		return $this->fixture;
	}

	#[\Override]
	protected function tearDown(): void {
		$this->fixture?->close();
	}

	/** @return list<Change> */
	private function changes(FreshRSS\Models\Subscriptions\ConfigurationDAO $dao): array {
		$catalog = $dao->catalog(3600);
		$items = [];
		foreach ($catalog['categories'] as $category) {
			if ($category['id'] === 2) {
				$items[] = ['type' => 'category', 'id' => 2, 'expectedVersion' => $category['version'], 'changes' => ['name' => 'Changed & category']];
			}
		}
		foreach ($catalog['feeds'] as $feed) {
			$items[] = ['type' => 'feed', 'id' => $feed['id'], 'expectedVersion' => $feed['version'], 'changes' => ['ttl' => 60, 'categoryId' => 2]];
		}
		return FreshRSS\Services\Subscriptions\ConfigurationService::validateItems($items, 3);
	}

	#[DataProvider('backends')]
	public function testCatalogUsesRealSchemaAndDynamicCountersDoNotConflict(string $backend): void {
		$db = $this->database($backend);
		$dao = new FreshRSS\Models\Subscriptions\ConfigurationDAO(currentPdo: $db->pdo);
		$changes = $this->changes($dao);
		$db->pdo->exec('UPDATE feed SET "cache_nbUnreads"=77,error=42 WHERE id=1');
		$result = $dao->apply($changes, 3600);
		self::assertCount(2, $result['feeds']);
		self::assertSame(77, $result['feeds'][0]['unread']);
		self::assertTrue($result['feeds'][0]['error']);
		self::assertSame('Changed & category', $result['categories'][0]['name']);
		self::assertSame(120, $db->integer('SELECT SUM(ttl) FROM feed'));
		self::assertFalse($db->pdo->inTransaction());
	}

	#[DataProvider('backends')]
	public function testEveryVersionIsCheckedBeforeAnyWrite(string $backend): void {
		$db = $this->database($backend);
		$dao = new FreshRSS\Models\Subscriptions\ConfigurationDAO(currentPdo: $db->pdo);
		$changes = $this->changes($dao);
		self::assertArrayHasKey(2, $changes);
		$changes[2]['expectedVersion'] = str_repeat('0', 64);
		if ($backend === 'pgsql') {
			// PostgreSQL sequence increments survive transaction rollback, exposing premature writes.
			$db->pdo->exec("CREATE SEQUENCE subscription_write_probe;
				CREATE FUNCTION probe_subscription_write() RETURNS trigger LANGUAGE plpgsql AS
				'BEGIN PERFORM nextval(''subscription_write_probe''); RETURN NEW; END';
				CREATE TRIGGER probe_feed_write BEFORE UPDATE ON feed FOR EACH ROW EXECUTE FUNCTION probe_subscription_write();
				CREATE TRIGGER probe_category_write BEFORE UPDATE ON category FOR EACH ROW EXECUTE FUNCTION probe_subscription_write()");
		} else {
			$db->pdo->exec("CREATE TRIGGER probe_feed_write BEFORE UPDATE ON feed BEGIN SELECT RAISE(FAIL,'unexpected early write'); END;
				CREATE TRIGGER probe_category_write BEFORE UPDATE ON category BEGIN SELECT RAISE(FAIL,'unexpected early write'); END");
		}
		try {
			$dao->apply($changes, 3600);
			self::fail('A stale final item must reject the whole batch');
		} catch (FreshRSS\Exceptions\Reader\ApiException $exception) {
			self::assertSame(409, $exception->status);
			self::assertSame('configuration_conflict', $exception->getMessage());
			self::assertTrue($exception->details['atomic']);
		}
		if ($backend === 'pgsql') {
			$attemptedWrites = $db->integer('SELECT CASE WHEN is_called THEN last_value ELSE 0 END FROM subscription_write_probe');
			self::assertSame(0, $attemptedWrites, 'Validation must precede every feed and category write');
		}
		self::assertSame(0, $db->integer('SELECT SUM(ttl) FROM feed'));
		self::assertFalse($db->pdo->inTransaction());
	}

	/** @return iterable<string,array{string,int,bool}> */
	public static function failureModes(): iterable {
		foreach (self::backends() as $backend => $_) {
			foreach ([PDO::ERRMODE_SILENT, PDO::ERRMODE_EXCEPTION] as $mode) {
				foreach ([false, true] as $nested) {
					yield $backend . '-' . $mode . '-' . (int)$nested => [$backend, $mode, $nested];
				}
			}
		}
	}

	#[DataProvider('failureModes')]
	public function testLaterDatabaseFailureRollsBackAllConfigurationChanges(string $backend, int $errorMode, bool $nested): void {
		$db = $this->database($backend);
		$dao = new FreshRSS\Models\Subscriptions\ConfigurationDAO(currentPdo: $db->pdo);
		if ($backend === 'pgsql') {
			$db->pdo->exec("CREATE FUNCTION fail_subscription_write() RETURNS trigger LANGUAGE plpgsql AS
				'BEGIN IF NEW.id=2 THEN RAISE EXCEPTION ''injected subscription failure''; END IF; RETURN NEW; END';
				CREATE TRIGGER fail_subscription_write BEFORE UPDATE ON feed FOR EACH ROW EXECUTE FUNCTION fail_subscription_write()");
		} else {
			$db->pdo->exec("CREATE TRIGGER fail_subscription_write BEFORE UPDATE ON feed WHEN NEW.id=2
				BEGIN SELECT RAISE(FAIL,'injected subscription failure'); END");
		}
		$db->pdo->setAttribute(PDO::ATTR_ERRMODE, $errorMode);
		if ($nested) {
			$db->pdo->beginTransaction();
			$db->pdo->exec("UPDATE feed SET name='Caller work' WHERE id=1");
		}
		$changes = $this->changes($dao);
		try {
			$dao->apply($changes, 3600);
			self::fail('The injected failure must reject every item');
		} catch (FreshRSS\Exceptions\Reader\ApiException $exception) {
			self::assertSame(500, $exception->status);
			self::assertSame('write_failed', $exception->getMessage());
			self::assertTrue($exception->details['atomic']);
		}
		self::assertSame($nested, $db->pdo->inTransaction());
		self::assertSame(0, $db->integer('SELECT SUM(ttl) FROM feed'));
		self::assertSame(2, $db->integer('SELECT COUNT(*) FROM feed WHERE category=1'));
		self::assertSame(1, $db->integer("SELECT COUNT(*) FROM category WHERE id=2 AND name='Contract category'"));
		self::assertSame(1, $db->integer("SELECT COUNT(*) FROM feed WHERE id=1 AND name='" . ($nested ? 'Caller work' : 'One') . "'"));
		$db->pdo->exec('DROP TRIGGER fail_subscription_write' . ($backend === 'pgsql' ? ' ON feed' : ''));
		$result = $dao->apply($changes, 3600);
		self::assertCount(2, $result['feeds']);
		self::assertSame(120, $db->integer('SELECT SUM(ttl) FROM feed'));
		if ($nested) {
			self::assertTrue($db->pdo->inTransaction());
			$db->pdo->rollBack();
			self::assertSame(0, $db->integer('SELECT SUM(ttl) FROM feed'));
		}
	}

	#[DataProvider('backends')]
	public function testNameOnlyChangePreservesNullableCategory(string $backend): void {
		$db = $this->database($backend);
		$db->pdo->exec('UPDATE feed SET category=NULL WHERE id=1');
		$dao = new FreshRSS\Models\Subscriptions\ConfigurationDAO(currentPdo: $db->pdo);
		$feed = $dao->catalog(3600)['feeds'][0];
		$changes = FreshRSS\Services\Subscriptions\ConfigurationService::validateItems([
			['type' => 'feed', 'id' => 1, 'expectedVersion' => $feed['version'], 'changes' => ['name' => 'Renamed feed']],
		], 1);
		self::assertSame('Renamed feed', $dao->apply($changes, 3600)['feeds'][0]['name']);
		self::assertSame(1, $db->integer('SELECT COUNT(*) FROM feed WHERE id=1 AND category IS NULL'));
	}
}
