<?php
declare(strict_types=1);

/** Real installation schemas, isolated from the configured FreshRSS database. */
final class DatabaseFixture {
	public Minz_Pdo $pdo;
	public FreshRSS_EntryDAO $entries;
	public readonly string $schema;

	public function __construct(public readonly string $backend) {
		$this->schema = 'freshrss_contract_' . bin2hex(random_bytes(8));
		$this->pdo = $this->connect();
		if ($backend === 'pgsql') {
			$this->pdo->exec('CREATE SCHEMA ' . $this->schema);
			$this->pdo->exec('SET search_path TO ' . $this->schema);
		} else {
			$this->pdo->exec('PRAGMA foreign_keys=ON');
		}
		require APP_PATH . '/SQL/install.sql.' . $backend . '.php';
		$sql = $GLOBALS['SQL_CREATE_TABLES'];
		if (!is_string($sql)) {
			throw new RuntimeException('Installation SQL is missing');
		}
		$this->pdo->exec($sql);
		$this->pdo->exec("INSERT INTO feed(id,url,category,name,\"cache_nbUnreads\") VALUES
			(1,'https://example.invalid/one',1,'One',0), (2,'https://example.invalid/two',1,'Two',123)");
		$this->entries = $backend === 'pgsql' ? new FreshRSS_EntryDAOPGSQL(currentPdo: $this->pdo)
			: new FreshRSS_EntryDAOSQLite(currentPdo: $this->pdo);
	}

	public function connect(): Minz_Pdo {
		$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
		if ($this->backend === 'sqlite') {
			return new Minz_PdoSqlite('sqlite::memory:', options: $options);
		}
		$dsn = getenv('FRESHRSS_TEST_PGSQL_DSN');
		if ($dsn === false || !str_starts_with($dsn, 'pgsql:')) {
			throw new RuntimeException('FRESHRSS_TEST_PGSQL_DSN must be a dedicated test database');
		}
		$pdo = new Minz_PdoPgsql($dsn, getenv('FRESHRSS_TEST_PGSQL_USER') ?: null,
			getenv('FRESHRSS_TEST_PGSQL_PASSWORD') ?: null, $options);
		$pdo->exec('SET statement_timeout=10000');
		$pdo->exec('SET search_path TO ' . $this->schema);
		return $pdo;
	}

	/** @return list<numeric-string> */
	public function seed(int $count = 1200, int $base = 0): array {
		$statement = $this->pdo->prepare("INSERT INTO entry(id,guid,title,author,content,link,date,\"lastSeen\",id_feed,tags,attributes)
			VALUES(?,?,'Contract title','Contract author','Contract body','https://example.invalid/article',?,0,1,'','{}')");
		if ($statement === false) {
			throw new RuntimeException('Cannot prepare fixture entries');
		}
		$ids = [];
		$this->pdo->beginTransaction();
		for ($i = 1; $i <= $count; ++$i) {
			$id = (string)($base + $i);
			$ids[] = $id;
			$statement->execute([$id, 'contract-' . $id, $i]);
		}
		$this->pdo->exec('UPDATE feed SET "cache_nbUnreads"=' . $count . ' WHERE id=1');
		$this->pdo->commit();
		return $ids;
	}

	public function integer(string $sql): int {
		$statement = $this->pdo->query($sql);
		if ($statement === false) {
			throw new RuntimeException('Fixture query failed');
		}
		return (int)$statement->fetchColumn();
	}

	public function failOnUpdate(string $column, string $id): void {
		if (!in_array($column, ['is_read', 'is_favorite'], true) || !ctype_digit($id)) {
			throw new InvalidArgumentException('Unsupported failure fixture');
		}
		if ($this->backend === 'sqlite') {
			$this->pdo->exec("CREATE TRIGGER injected_failure BEFORE UPDATE OF {$column} ON entry WHEN NEW.id={$id}
				BEGIN SELECT RAISE(FAIL, 'injected contract failure'); END");
		} else {
			$this->pdo->exec("CREATE FUNCTION fail_contract() RETURNS trigger LANGUAGE plpgsql AS
				'BEGIN IF NEW.id={$id} THEN RAISE EXCEPTION ''injected contract failure''; END IF; RETURN NEW; END';
				CREATE TRIGGER injected_failure BEFORE UPDATE OF {$column} ON entry FOR EACH ROW EXECUTE FUNCTION fail_contract()");
		}
	}

	public function close(): void {
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		if ($this->pdo->inTransaction()) {
			$this->pdo->rollBack();
		}
		if ($this->backend === 'pgsql') {
			$this->pdo->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
		}
	}
}
