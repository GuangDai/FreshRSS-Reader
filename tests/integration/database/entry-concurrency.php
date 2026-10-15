<?php
declare(strict_types=1);

putenv('FRESHRSS_ENV=silent');
require dirname(__DIR__, 2) . '/bootstrap.php';
require __DIR__ . '/fixtures/DatabaseFixture.php';

$dsn = getenv('FRESHRSS_TEST_PGSQL_DSN');
if ($dsn === false || !str_starts_with($dsn, 'pgsql:')) {
	fwrite(STDERR, "FRESHRSS_TEST_PGSQL_DSN is required; use matrix.py for a temporary local server.\n");
	exit(2);
}

if (($argv[1] ?? '') === '--worker') {
	$schema = $argv[2] ?? '';
	if (preg_match('/^freshrss_contract_[a-f0-9]{16}$/D', $schema) !== 1) {
		throw new InvalidArgumentException('Invalid fixture schema');
	}
	$pdo = new Minz_PdoPgsql($dsn, getenv('FRESHRSS_TEST_PGSQL_USER') ?: null,
		getenv('FRESHRSS_TEST_PGSQL_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$pdo->exec("SET statement_timeout=10000; SET search_path TO {$schema}; SET application_name='{$schema}'");
	$dao = new FreshRSS_EntryDAOPGSQL(currentPdo: $pdo);
	echo json_encode(['changed' => $dao->markRead('2')], JSON_THROW_ON_ERROR), "\n";
	exit(0);
}

$fixture = new DatabaseFixture('pgsql');
$process = null;
try {
	$fixture->seed(2);
	$fixture->pdo->beginTransaction();
	if ($fixture->entries->markRead('1') !== 1) {
		throw new RuntimeException('First reader could not mark its entry');
	}
	$process = proc_open([PHP_BINARY, __FILE__, '--worker', $fixture->schema],
		[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	if (!is_resource($process)) {
		throw new RuntimeException('Cannot start concurrent reader');
	}
	fclose($pipes[0]);
	stream_set_timeout($pipes[1], 15);
	$observer = $fixture->connect();
	$waiting = false;
	$deadline = microtime(true) + 5;
	do {
		$statement = $observer->query("SELECT wait_event_type FROM pg_stat_activity WHERE application_name='{$fixture->schema}'");
		if ($statement !== false && $statement->fetchColumn() === 'Lock') {
			$waiting = true;
			break;
		}
		usleep(10000);
	} while (microtime(true) < $deadline);
	if (!$waiting) {
		throw new RuntimeException('Concurrent reader did not reach the required row-lock interleaving');
	}
	$fixture->pdo->commit();
	$output = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$exit = proc_close($process);
	$process = null;
	if ($exit !== 0 || $output === false || json_decode($output, true, flags: JSON_THROW_ON_ERROR) !== ['changed' => 1]) {
		throw new RuntimeException('Concurrent reader failed: ' . $error);
	}
	$actual = $fixture->integer('SELECT COUNT(*) FROM entry WHERE is_read=0');
	$cached = $fixture->integer('SELECT "cache_nbUnreads" FROM feed WHERE id=1');
	if ($cached !== $actual || $actual !== 0) {
		throw new RuntimeException("Concurrent reads corrupted the unread cache: actual={$actual}, cached={$cached}");
	}
	echo json_encode(['backend' => 'pgsql', 'case' => 'same_feed_concurrent_read',
		'row_lock_observed' => $waiting, 'actual_unread' => $actual, 'cached_unread' => $cached], JSON_THROW_ON_ERROR), "\n";
} finally {
	if (is_resource($process)) {
		proc_terminate($process);
		proc_close($process);
	}
	$fixture->close();
}
