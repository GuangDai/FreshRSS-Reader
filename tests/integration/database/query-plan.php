<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';
require __DIR__ . '/fixtures/DatabaseFixture.php';

/** @return positive-int */
function queryPlanInteger(mixed $value, string $option, int $minimum): int {
	$integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]);
	if (!is_int($integer) || $integer < 1) {
		throw new InvalidArgumentException($option . ' must be an integer of at least ' . $minimum);
	}
	return $integer;
}

/** Both backends generate the same synthetic rows without a PHP array of every article. */
function queryPlanSeed(DatabaseFixture $fixture, int $entries, string $distribution): int {
	$perFeed = (int)ceil($entries / 100);
	$oldUnreadCount = intdiv($entries, 100);
	$unread = $distribution === 'uniform' ? 'i % 11 = 0' : 'i <= ' . $oldUnreadCount;
	$source = $fixture->backend === 'pgsql' ? 'generate_series(1, ' . $entries . ') AS seed(i)' : 'seed';
	$with = $fixture->backend === 'pgsql' ? '' : 'WITH RECURSIVE seed(i) AS '
		. '(SELECT 1 UNION ALL SELECT i+1 FROM seed WHERE i<' . $entries . ') ';
	$feeds = [];
	for ($id = 3; $id <= 100; ++$id) {
		$feeds[] = "({$id},'https://example.invalid/plan/{$id}','Plan feed {$id}',1)";
	}
	$fixture->pdo->exec('INSERT INTO feed(id,url,name,category) VALUES ' . implode(',', $feeds));
	$fixture->pdo->exec($with . "INSERT INTO entry(id,guid,title,content,link,date,is_read,id_feed)
		SELECT i,'plan-'||i,'Plan entry '||i,'Synthetic reader query plan body',
		'https://example.invalid/plan/entry/'||i,1700000000+i,CASE WHEN {$unread} THEN 0 ELSE 1 END,
		1+((i-1)/{$perFeed}) FROM {$source}");
	if ($fixture->backend === 'pgsql') {
		// Mark pages visible so covering index plans can be compared after bulk loading.
		$fixture->pdo->exec('VACUUM ANALYZE entry');
		$fixture->pdo->exec('ANALYZE feed');
	} else {
		$fixture->pdo->exec('ANALYZE');
	}
	return $perFeed;
}

/** @return list<array{name:string,definition:string}> */
function queryPlanIndexes(DatabaseFixture $fixture): array {
	$sql = $fixture->backend === 'pgsql'
		? "SELECT indexname AS name,indexdef AS definition FROM pg_indexes WHERE schemaname=current_schema() AND tablename='entry' ORDER BY indexname"
		: "SELECT name,sql AS definition FROM sqlite_master WHERE type='index' AND tbl_name='entry' AND sql IS NOT NULL ORDER BY name";
	$statement = $fixture->pdo->query($sql);
	if ($statement === false) {
		throw new RuntimeException('Cannot inspect fixture indexes');
	}
	/** @var list<array{name:string,definition:string}> */
	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Inspect the bounded ID selection that listSummaries() reuses before fetching metadata.
 * This is a query-plan experiment, not an end-to-end Reader throughput benchmark.
 * @param list<int|string> $parameters
 * @return array{rows:list<string>,report:array<string,mixed>}
 */
function queryPlanMeasure(DatabaseFixture $fixture, string $sql, array $parameters, int $iterations): array {
	$explain = $fixture->backend === 'pgsql' ? 'EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) ' : 'EXPLAIN QUERY PLAN ';
	$statement = $fixture->pdo->prepare($explain . $sql);
	if ($statement === false || !$statement->execute($parameters)) {
		throw new RuntimeException('Cannot explain fixture query');
	}
	$plan = $fixture->backend === 'pgsql'
		? json_decode((string)$statement->fetchColumn(), true, flags: JSON_THROW_ON_ERROR)
		: $statement->fetchAll(PDO::FETCH_ASSOC);
	$statement = $fixture->pdo->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Cannot prepare fixture query');
	}
	$rows = [];
	$start = hrtime(true);
	for ($i = 0; $i < $iterations; ++$i) {
		if (!$statement->execute($parameters)) {
			throw new RuntimeException('Cannot execute fixture query');
		}
		$rows = $statement->fetchAll(PDO::FETCH_COLUMN);
	}
	$elapsed = (hrtime(true) - $start) / 1e6;
	$ids = [];
	foreach ($rows as $row) {
		if (!is_int($row) && !is_string($row)) {
			throw new RuntimeException('Fixture query returned an invalid article ID');
		}
		$ids[] = (string)$row;
	}
	return [
		'rows' => $ids,
		'report' => [
			'sql' => $sql,
			'parameters' => $parameters,
			'rows' => count($rows),
			'client_mean_ms' => $elapsed / $iterations,
			'plan' => $plan,
		],
	];
}

try {
	$options = getopt('', ['backend:', 'entries:', 'iterations:', 'help']);
	if ($options === false) {
		throw new InvalidArgumentException('Cannot parse query-plan options');
	}
	if (isset($options['help'])) {
		echo "Usage: php tests/integration/database/query-plan.php [--backend sqlite|pgsql] [--entries 100000] [--iterations 10]\n"
			. "PostgreSQL uses FRESHRSS_TEST_PGSQL_DSN, FRESHRSS_TEST_PGSQL_USER and FRESHRSS_TEST_PGSQL_PASSWORD.\n"
			. "SQLite uses memory; PostgreSQL creates and removes a random schema. Index changes affect only that fixture.\n"
			. "Entries must be at least 100. Output compares uniform and concentrated old unread entries using JSON plans.\n"
			. "Phases: legacy two-column feed index, DAO-upgraded three-column feed index, then a candidate global unread index.\n";
		exit(0);
	}
	$backend = $options['backend'] ?? 'sqlite';
	if (!in_array($backend, ['sqlite', 'pgsql'], true)) {
		throw new InvalidArgumentException('--backend must be sqlite or pgsql');
	}
	$entries = queryPlanInteger($options['entries'] ?? 100000, '--entries', 100);
	$iterations = queryPlanInteger($options['iterations'] ?? 10, '--iterations', 1);
	$result = [
		'backend' => $backend,
		'entries' => $entries,
		'feeds' => 100,
		'iterations' => $iterations,
		'limit' => 50,
		'scope' => 'Reader ID selection; synthetic data, warm repeated queries, no concurrent writers',
		'index_phases' => [
			'legacy' => 'Restore the old (id_feed,is_read) feed index inside the fixture',
			'upgraded' => 'Run upgradeReaderIndexes() to replace the feed index with (id_feed,is_read,id)',
			'candidate_global' => 'Also add experimental (is_read,id); not part of the production migration',
		],
		'distributions' => [],
	];
	$selection = new ReflectionMethod(FreshRSS_EntryDAO::class, 'sqlListWhere');
	foreach (['uniform', 'old_unread'] as $distribution) {
		$fixture = new DatabaseFixture($backend);
		try {
			// New installations already have the upgraded index; restore the legacy baseline only in this fixture.
			$fixture->pdo->exec('DROP INDEX `_entry_feed_read_index`;
				CREATE INDEX `_entry_feed_read_index` ON `_entry` (id_feed,is_read)');
			$database = $backend === 'pgsql' ? new FreshRSS_DatabaseDAOPGSQL(currentPdo: $fixture->pdo)
				: new FreshRSS_DatabaseDAOSQLite(currentPdo: $fixture->pdo);
			$perFeed = queryPlanSeed($fixture, $entries, $distribution);
			$feedId = $distribution === 'uniform' ? 42 : 1;
			$cursor = (string)(($feedId - 1) * $perFeed + max(1, intdiv($perFeed, 2)));
			$queries = [
				'feed_unread' => ['f', $feedId, '0'],
				'global_unread' => ['Z', 0, '0'],
				'feed_keyset' => ['f', $feedId, $cursor],
			];
			$baseline = [];
			$phases = [];
			foreach (['legacy', 'upgraded', 'candidate_global'] as $phase) {
				if ($phase === 'upgraded') {
					if (!$database->upgradeReaderIndexes()) {
						throw new RuntimeException('Cannot upgrade fixture Reader indexes');
					}
					$fixture->pdo->exec('ANALYZE entry');
				} elseif ($phase === 'candidate_global') {
					$fixture->pdo->exec('CREATE INDEX candidate_read_id ON entry(is_read,id)');
					$fixture->pdo->exec('ANALYZE entry');
				}
				$reports = [];
				foreach ($queries as $name => [$type, $id, $idMax]) {
					/** @var array{list<int|string>,string} $query */
					$query = $selection->invokeArgs($fixture->entries, ['type' => $type, 'id' => $id,
						'state' => FreshRSS_Entry::STATE_NOT_READ, 'id_max' => $idMax, 'limit' => 50]);
					[$parameters, $sql] = $query;
					$measurement = queryPlanMeasure($fixture, $sql, $parameters, $iterations);
					if ($phase === 'legacy') {
						$baseline[$name] = $measurement['rows'];
					} elseif (($baseline[$name] ?? null) !== $measurement['rows']) {
						throw new RuntimeException('Index phase ' . $phase . ' changed the result of ' . $name);
					}
					$reports[$name] = $measurement['report'];
				}
				$phases[$phase] = ['indexes' => queryPlanIndexes($fixture), 'queries' => $reports];
			}
			$result['distributions'][$distribution] = [
				'unread_rule' => $distribution === 'uniform' ? 'Every eleventh entry' : 'Oldest one percent of entries',
				'phases' => $phases,
			];
		} finally {
			$fixture->close();
		}
	}
	echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
	fwrite(STDERR, 'Database query-plan experiment failed: ' . $error->getMessage() . "\n");
	exit(1);
}
