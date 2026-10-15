<?php
declare(strict_types=1);

final class FeedCommitFailureTest extends \PHPUnit\Framework\TestCase {
	private Minz_PdoSqlite $pdo;
	private ?FreshRSS_SystemConfiguration $previousSystem = null;
	private ?string $previousUser = null;
	/** @var array<string,mixed> */
	private array $previousConnection = [];

	#[\Override]
	protected function setUp(): void {
		if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
			self::markTestSkipped('pdo_sqlite is required');
		}
		$this->previousSystem = FreshRSS_Context::hasSystemConf() ? FreshRSS_Context::systemConf() : null;
		FreshRSS_Context::initSystem();
		$config = clone FreshRSS_Context::systemConf();
		$db = $config->db;
		$db['type'] = 'sqlite';
		$config->db = $db;
		(new ReflectionProperty(FreshRSS_Context::class, 'system_conf'))->setValue(null, $config);
		$this->previousUser = Minz_User::name();
		Minz_User::change(Minz_User::INTERNAL_USER);
		$this->pdo = new Minz_PdoSqlite('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
		foreach (['sharedPdo' => $this->pdo, 'sharedCurrentUser' => Minz_User::INTERNAL_USER,
			'usesSharedPdo' => true, 'dummyConnection' => false] as $name => $value) {
			$property = new ReflectionProperty(Minz_ModelPdo::class, $name);
			$this->previousConnection[$name] = $property->getValue();
			$property->setValue(null, $value);
		}
	}

	#[\Override]
	protected function tearDown(): void {
		if ($this->previousConnection === []) {
			return;
		}
		foreach ($this->previousConnection as $name => $value) {
			(new ReflectionProperty(Minz_ModelPdo::class, $name))->setValue(null, $value);
		}
		(new ReflectionProperty(FreshRSS_Context::class, 'system_conf'))->setValue(null, $this->previousSystem);
		Minz_User::change($this->previousUser ?? '');
	}

	public function testCountingFailureIsReportedInsteadOfReturningMinusOne(): void {
		$this->expectException(RuntimeException::class);
		FreshRSS_feed_Controller::commitNewEntries();
	}

	public function testStagedWriteFailureIsReportedAndKeepsPendingEntries(): void {
		$this->pdo->exec('CREATE TABLE entrytmp (id INTEGER)');
		$this->pdo->exec('INSERT INTO entrytmp VALUES (123)');
		try {
			FreshRSS_feed_Controller::commitNewEntries();
			self::fail('The failed write must be reported to the queue worker');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('commit staged entries', $e->getMessage());
		}
		self::assertSame(1, FreshRSS_Factory::createEntryDao()->countNewEntries());
		self::assertFalse($this->pdo->inTransaction());
	}

	public function testEmptyStagingTableIsASuccessfulNoOp(): void {
		$this->pdo->exec('CREATE TABLE entrytmp (id INTEGER)');
		self::assertSame(0, FreshRSS_feed_Controller::commitNewEntries());
		self::assertFalse($this->pdo->inTransaction());
	}
}
