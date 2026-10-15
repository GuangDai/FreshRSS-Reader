<?php
declare(strict_types=1);

putenv('FRESHRSS_ENV=silent');
require dirname(__DIR__, 2) . '/bootstrap.php';
require __DIR__ . '/fixtures/DatabaseFixture.php';

$dsn = getenv('FRESHRSS_TEST_PGSQL_DSN');
if ($dsn === false || !str_starts_with($dsn, 'pgsql:')) {
	fwrite(STDERR, "FRESHRSS_TEST_PGSQL_DSN is required; use matrix.py --suite subscriptions.\n");
	exit(2);
}

if (($argv[1] ?? '') === '--worker') {
	$schema = $argv[2] ?? '';
	$worker = $argv[3] ?? '';
	if (preg_match('/^freshrss_contract_[a-f0-9]{16}$/D', $schema) !== 1 || !in_array($worker, ['a', 'b'], true)) {
		throw new InvalidArgumentException('Invalid concurrency fixture');
	}
	$pdo = new Minz_PdoPgsql($dsn, getenv('FRESHRSS_TEST_PGSQL_USER') ?: null,
		getenv('FRESHRSS_TEST_PGSQL_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$pdo->exec("SET statement_timeout=10000; SET search_path TO {$schema}; SET application_name='{$schema}_{$worker}'");
	$payload = stream_get_contents(STDIN);
	if ($payload === false) {
		throw new RuntimeException('Cannot read subscription fixture payload');
	}
	$changes = FreshRSS\Services\Subscriptions\ConfigurationService::validateItems(json_decode($payload, true, flags: JSON_THROW_ON_ERROR), 3);
	$dao = new FreshRSS\Models\Subscriptions\ConfigurationDAO(currentPdo: $pdo);
	try {
		$result = $dao->apply($changes, 3600);
		$outcome = ['status' => 200, 'feeds' => count($result['feeds']), 'categories' => count($result['categories'])];
	} catch (FreshRSS\Exceptions\Reader\ApiException $exception) {
		$outcome = ['status' => $exception->status, 'error' => $exception->getMessage(), 'atomic' => $exception->details['atomic'] ?? false];
	}
	echo json_encode($outcome, JSON_THROW_ON_ERROR), "\n";
	exit(0);
}

$fixture = new DatabaseFixture('pgsql');
$processes = [];
$streams = [];
try {
	$fixture->pdo->exec("INSERT INTO category(id,name) VALUES(2,'Before race')");
	$dao = new FreshRSS\Models\Subscriptions\ConfigurationDAO(currentPdo: $fixture->pdo);
	$catalog = $dao->catalog(3600);
	$changes = [];
	foreach ($catalog['categories'] as $category) {
		if ($category['id'] === 2) {
			$changes[] = ['type' => 'category', 'id' => 2, 'expectedVersion' => $category['version'], 'changes' => ['name' => 'Race winner']];
		}
	}
	foreach ($catalog['feeds'] as $feed) {
		$changes[] = ['type' => 'feed', 'id' => $feed['id'], 'expectedVersion' => $feed['version'], 'changes' => ['ttl' => 60, 'categoryId' => 2]];
	}
	$payload = json_encode($changes, JSON_THROW_ON_ERROR);
	$fixture->pdo->beginTransaction();
	// Hold feeds before either worker starts, so both compete using the same original versions.
	$fixture->pdo->query('SELECT id FROM feed ORDER BY id FOR UPDATE');
	foreach (['a', 'b'] as $worker) {
		$process = proc_open([PHP_BINARY, __FILE__, '--worker', $fixture->schema, $worker],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if (!is_resource($process)) {
			throw new RuntimeException('Cannot start concurrent subscription writer');
		}
		$processes[$worker] = $process;
		fwrite($pipes[0], $payload);
		fclose($pipes[0]);
		stream_set_timeout($pipes[1], 15);
		$streams[$worker] = [$pipes[1], $pipes[2]];
	}
	$observer = $fixture->connect();
	$waiting = 0;
	$deadline = microtime(true) + 5;
	do {
		$statement = $observer->query("SELECT COUNT(*) FROM pg_stat_activity
			WHERE application_name IN ('{$fixture->schema}_a','{$fixture->schema}_b') AND wait_event_type='Lock'");
		$waiting = $statement === false ? 0 : (int)$statement->fetchColumn();
		if ($waiting === 2) {
			break;
		}
		usleep(10000);
	} while (microtime(true) < $deadline);
	if ($waiting !== 2) {
		throw new RuntimeException('Both subscription writers must reach the row-lock barrier');
	}
	$fixture->pdo->commit();
	$outcomes = [];
	foreach ($processes as $worker => $process) {
		$output = stream_get_contents($streams[$worker][0]);
		$error = stream_get_contents($streams[$worker][1]);
		fclose($streams[$worker][0]);
		fclose($streams[$worker][1]);
		$exit = proc_close($process);
		unset($processes[$worker]);
		if ($exit !== 0 || $output === false) {
			throw new RuntimeException('Subscription writer failed: ' . $error);
		}
		$outcomes[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
	}
	$successes = array_filter($outcomes, static fn(mixed $outcome): bool => $outcome === ['status' => 200, 'feeds' => 2, 'categories' => 1]);
	$conflicts = array_filter($outcomes, static fn(mixed $outcome): bool =>
		$outcome === ['status' => 409, 'error' => 'configuration_conflict', 'atomic' => true]);
	if (count($successes) !== 1 || count($conflicts) !== 1 ||
		$fixture->integer('SELECT COUNT(*) FROM feed WHERE ttl=60 AND category=2') !== 2 ||
		$fixture->integer("SELECT COUNT(*) FROM category WHERE id=2 AND name='Race winner'") !== 1) {
		throw new RuntimeException('Concurrent subscription batches violated compare-and-set: ' . json_encode($outcomes));
	}
	echo json_encode(['backend' => 'pgsql', 'case' => 'subscription_batch_compare_and_set',
		'blocked_workers' => $waiting, 'successful_batches' => count($successes), 'conflicting_batches' => count($conflicts)], JSON_THROW_ON_ERROR), "\n";
} finally {
	foreach ($processes as $process) {
		proc_terminate($process);
		proc_close($process);
	}
	$fixture->close();
}
