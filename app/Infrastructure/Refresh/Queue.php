<?php
declare(strict_types=1);

namespace FreshRSS\Infrastructure\Refresh;

use FreshRSS\Services\Refresh\SchedulerConfiguration;
use FreshRSS_Context;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Local durable work queue. Never contains feed URLs, credentials or article bodies.
 * @phpstan-type RefreshJob array{id:int,username:string,feed_id:int,kind:string,host:string,due:int,retry_at:int,attempts:int,
 *   owner:string,lease_until:int,started:int}
 */
final class Queue {
	private PDO $db;
	private PDOStatement $enqueueStatement;
	/** @var array<string,int|bool> */
	private array $config;

	/** @param array<string,int|bool> $config */
	public function __construct(string $path, array $config = []) {
		$this->config = SchedulerConfiguration::configuration($config);
		$this->db = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$this->db->exec('PRAGMA busy_timeout=' . (int)$this->config['busy_timeout_ms']);
		$this->db->exec('PRAGMA journal_mode=WAL');
		$this->db->exec('PRAGMA synchronous=NORMAL');
		$this->db->exec('CREATE TABLE IF NOT EXISTS jobs (
			id INTEGER PRIMARY KEY, username TEXT NOT NULL, feed_id INTEGER NOT NULL, kind TEXT NOT NULL, host TEXT NOT NULL,
			due INTEGER NOT NULL, retry_at INTEGER NOT NULL DEFAULT 0, attempts INTEGER NOT NULL DEFAULT 0,
			owner TEXT NOT NULL DEFAULT \'\', lease_until INTEGER NOT NULL DEFAULT 0, started INTEGER NOT NULL DEFAULT 0,
			UNIQUE(username,feed_id,kind))');
		$this->db->exec('CREATE INDEX IF NOT EXISTS jobs_due ON jobs(owner,due,retry_at)');
		$this->db->exec('CREATE INDEX IF NOT EXISTS jobs_owner ON jobs(owner,lease_until)');
		$this->db->exec('CREATE TABLE IF NOT EXISTS history (
			id INTEGER PRIMARY KEY, username TEXT NOT NULL, feed_id INTEGER NOT NULL, kind TEXT NOT NULL,
			finished INTEGER NOT NULL, outcome TEXT NOT NULL, duration_ms INTEGER NOT NULL, peak_bytes INTEGER NOT NULL)');
		$this->db->exec('CREATE TABLE IF NOT EXISTS schedule (username TEXT PRIMARY KEY, maintenance_due INTEGER NOT NULL)');
		$this->db->exec('CREATE TABLE IF NOT EXISTS control (id INTEGER PRIMARY KEY CHECK(id=1), paused INTEGER NOT NULL)');
		$this->db->exec('INSERT OR IGNORE INTO control(id,paused) VALUES(1,0)');
		$this->enqueueStatement = $this->prepare('INSERT INTO jobs(username,feed_id,kind,host,due) VALUES(?,?,?,?,?)
			ON CONFLICT(username,feed_id,kind) DO UPDATE SET host=excluded.host,due=excluded.due WHERE jobs.owner=\'\'');
	}

	private function prepare(string $sql): PDOStatement {
		$statement = $this->db->prepare($sql);
		if ($statement === false) {
			throw new RuntimeException('Cannot prepare queue query');
		}
		return $statement;
	}

	private function query(string $sql): PDOStatement {
		$statement = $this->prepare($sql);
		$statement->execute();
		return $statement;
	}

	private static function integer(mixed $value): int {
		if (!is_int($value)) {
			throw new UnexpectedValueException('Invalid queue integer');
		}
		return $value;
	}

	private static function text(mixed $value): string {
		if (!is_string($value)) {
			throw new UnexpectedValueException('Invalid queue text');
		}
		return $value;
	}

	/** @return RefreshJob|null */
	private function fetchJob(PDOStatement $statement): ?array {
		$row = $statement->fetch(PDO::FETCH_ASSOC);
		if ($row === false) {
			return null;
		}
		return [
			'id' => self::integer($row['id']), 'username' => self::text($row['username']), 'feed_id' => self::integer($row['feed_id']),
			'kind' => self::text($row['kind']), 'host' => self::text($row['host']), 'due' => self::integer($row['due']),
			'retry_at' => self::integer($row['retry_at']), 'attempts' => self::integer($row['attempts']), 'owner' => self::text($row['owner']),
			'lease_until' => self::integer($row['lease_until']), 'started' => self::integer($row['started']),
		];
	}

	public static function open(): self {
		$directory = DATA_PATH . '/scheduler';
		if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
			throw new RuntimeException('Cannot create scheduler directory');
		}
		return new self($directory . '/queue.sqlite', FreshRSS_Context::systemConf()->scheduler);
	}

	public function enqueue(string $user, int $feedId, string $kind, string $host, int $due): void {
		if (!in_array($kind, ['feed', 'maintenance', 'cache'], true) || ($kind === 'feed' && $feedId <= 0)) {
			throw new InvalidArgumentException('Invalid refresh job');
		}
		$this->enqueueStatement->execute([$user, $feedId, $kind, strtolower($host), $due]);
	}

	/** Bounded transactions and a connection-local seen set avoid per-feed commits and a PHP array of every ID.
	 * On an incomplete scan, preserve old pending work; never reconcile from a partial view.
	 * @param iterable<array{id:int,host:string,due:int}> $feeds
	 */
	public function synchronizeFeeds(string $user, iterable $feeds): int {
		$this->db->exec('CREATE TEMP TABLE planning_feed_ids(id INTEGER PRIMARY KEY)');
		$seen = $this->prepare('INSERT OR IGNORE INTO temp.planning_feed_ids(id) VALUES(?)');
		$count = 0;
		try {
			foreach ($feeds as $feed) {
				if (!$this->db->inTransaction()) {
					$this->db->beginTransaction();
				}
				$this->enqueue($user, $feed['id'], 'feed', $feed['host'], $feed['due']);
				$seen->execute([$feed['id']]);
				$count++;
				if ($count % (int)$this->config['plan_batch_size'] === 0) {
					$this->db->commit();
				}
			}
			if ($this->db->inTransaction()) {
				$this->db->commit();
			}
			$this->prepare('DELETE FROM jobs WHERE username=? AND kind=\'feed\' AND owner=\'\'
				AND feed_id NOT IN (SELECT id FROM temp.planning_feed_ids)')->execute([$user]);
			return $count;
		} finally {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			$this->db->exec('DROP TABLE temp.planning_feed_ids');
		}
	}

	/** Remove pending work for deleted/muted feeds. Never delete a running worker's lease.
	 * @param list<int> $feedIds
	 */
	public function reconcile(string $user, array $feedIds): void {
		$ids = array_fill_keys($feedIds, true);
		$select = $this->prepare('SELECT id,feed_id FROM jobs WHERE username=? AND kind=\'feed\' AND owner=\'\'');
		$select->execute([$user]);
		$remove = $this->prepare('DELETE FROM jobs WHERE id=? AND owner=\'\'');
		while (($row = $select->fetch(PDO::FETCH_ASSOC)) !== false) {
			if (!isset($ids[self::integer($row['feed_id'])])) {
				$remove->execute([self::integer($row['id'])]);
			}
		}
	}

	/** @param list<string> $users */
	public function reconcileUsers(array $users): void {
		$known = array_fill_keys($users, true);
		$select = $this->query('SELECT DISTINCT username FROM jobs');
		$remove = $this->prepare('DELETE FROM jobs WHERE username=? AND owner=\'\'');
		foreach ($select->fetchAll(PDO::FETCH_COLUMN) as $rawUser) {
			$user = self::text($rawUser);
			if ($user !== '' && !isset($known[$user])) {
				$remove->execute([$user]);
			}
		}
	}

	public function enqueueMaintenance(string $user, int $now): void {
		$select = $this->prepare('SELECT maintenance_due FROM schedule WHERE username=?');
		$select->execute([$user]);
		$due = $select->fetchColumn();
		$this->enqueue($user, 0, $user === '' ? 'cache' : 'maintenance', '', $due === false ? $now : self::integer($due));
	}

	/** Atomic global/host/user admission. A random owner fences completions from expired workers.
	 * @return RefreshJob|null
	 * @phpstan-impure
	 */
	public function claim(int $now): ?array {
		$this->db->exec('BEGIN IMMEDIATE');
		try {
			if ($this->paused()) {
				$this->db->exec('COMMIT');
				return null;
			}
			$this->prepare('UPDATE jobs SET owner=\'\',lease_until=0,retry_at=? WHERE owner<>\'\' AND lease_until<=?')
				->execute([$now, $now]);
			$count = (int)$this->query('SELECT COUNT(*) FROM jobs WHERE owner<>\'\'')->fetchColumn();
			if ($count >= (int)$this->config['workers']) {
				$this->db->exec('COMMIT');
				return null;
			}
			$select = $this->prepare('SELECT j.* FROM jobs j WHERE j.owner=\'\' AND j.due<=:now AND j.retry_at<=:now
				AND (SELECT COUNT(*) FROM jobs active WHERE active.owner<>\'\' AND active.username=j.username)<:users
				AND NOT EXISTS (SELECT 1 FROM jobs active WHERE active.owner<>\'\' AND active.username=j.username
					AND (j.kind<>\'feed\' OR active.kind<>\'feed\'))
				AND (j.host=\'\' OR (SELECT COUNT(*) FROM jobs active WHERE active.owner<>\'\' AND active.host=j.host)<:hosts)
				ORDER BY MAX(j.due,j.retry_at),j.id LIMIT 1');
			$select->bindValue(':now', $now, PDO::PARAM_INT);
			$select->bindValue(':hosts', (int)$this->config['per_host'], PDO::PARAM_INT);
			$select->bindValue(':users', (int)$this->config['per_user'], PDO::PARAM_INT);
			$select->execute();
			$job = $this->fetchJob($select);
			if ($job === null) {
				$this->db->exec('COMMIT');
				return null;
			}
			$job['owner'] = bin2hex(random_bytes(16));
			$job['started'] = $now;
			$job['lease_until'] = $now + (int)$this->config['lease_seconds'];
			$job['attempts']++;
			$this->prepare('UPDATE jobs SET owner=?,started=?,lease_until=?,attempts=? WHERE id=?')
				->execute([$job['owner'], $now, $job['lease_until'], $job['attempts'], $job['id']]);
			$this->db->exec('COMMIT');
			return $job;
		} catch (Throwable $e) {
			$this->db->exec('ROLLBACK');
			throw $e;
		}
	}

	public function paused(): bool {
		return $this->query('SELECT paused FROM control WHERE id=1')->fetchColumn() === 1;
	}

	/** Pause admission without revoking leases or discarding queued work. */
	public function setPaused(bool $paused): void {
		$this->prepare('UPDATE control SET paused=? WHERE id=1')->execute([$paused ? 1 : 0]);
	}

	/** @return RefreshJob|null */
	public function owned(int $id, string $owner): ?array {
		$select = $this->prepare('SELECT * FROM jobs WHERE id=? AND owner=? AND owner<>\'\'');
		$select->execute([$id, $owner]);
		return $this->fetchJob($select);
	}

	/** A bounded machine-readable outcome only, never exception messages containing URLs or secrets.
	 * @throws PDOException
	 */
	public function finish(int $id, string $owner, string $outcome, int $now, int $durationMs = 0, int $peakBytes = 0, int $retryAfter = 0): bool {
		if (!in_array($outcome, ['ok', 'removed', 'busy', 'fetch_error', 'exception', 'timeout', 'crashed'], true)) {
			throw new InvalidArgumentException('Unknown refresh outcome');
		}
		$this->db->exec('BEGIN IMMEDIATE');
		try {
			$job = $this->owned($id, $owner);
			if ($job === null) {
				$this->db->exec('COMMIT');
				return false;
			}
			$success = $outcome === 'ok' || $outcome === 'removed';
			if ($success) {
				$this->prepare('DELETE FROM jobs WHERE id=?')->execute([$id]);
				if ($job['kind'] !== 'feed') {
					$this->prepare('INSERT INTO schedule VALUES(?,?) ON CONFLICT(username) DO UPDATE SET maintenance_due=excluded.maintenance_due')
						->execute([$job['username'], $now + (int)$this->config['maintenance_interval']]);
				}
			} else {
				$retry = max($retryAfter, $now + SchedulerConfiguration::retryDelay($job['attempts'], $this->config));
				$this->prepare('UPDATE jobs SET owner=\'\',lease_until=0,retry_at=? WHERE id=?')->execute([$retry, $id]);
			}
			if ((int)$this->config['history_limit'] > 0) {
				$this->prepare('INSERT INTO history(username,feed_id,kind,finished,outcome,duration_ms,peak_bytes) VALUES(?,?,?,?,?,?,?)')
					->execute([$job['username'], $job['feed_id'], $job['kind'], $now, $outcome, $durationMs, $peakBytes]);
			}
			$this->prune($now);
			$this->db->exec('COMMIT');
			return true;
		} catch (Throwable $e) {
			$this->db->exec('ROLLBACK');
			throw $e;
		}
	}

	private function prune(int $now): void {
		$limit = (int)$this->config['history_limit'];
		$this->db->exec('DELETE FROM history WHERE id NOT IN (SELECT id FROM history ORDER BY id DESC LIMIT ' . $limit . ')');
		if ((int)$this->config['history_days'] > 0) {
			$this->prepare('DELETE FROM history WHERE finished<?')->execute([$now - (int)$this->config['history_days'] * 86400]);
		}
	}

	/** @return array{paused:bool,pending:int,running:int,retrying:int,due:int,oldestDue:?int,history:list<array<string,int|string>>,historyNext:?int} */
	public function status(int $now, ?int $historyBefore = null): array {
		if ($historyBefore !== null && $historyBefore < 1) {
			throw new InvalidArgumentException('History cursor must be a positive integer');
		}
		$select = $this->prepare('SELECT SUM(owner=\'\' OR lease_until<=:now) pending,SUM(owner<>\'\' AND lease_until>:now) running,
			SUM((owner=\'\' OR lease_until<=:now) AND attempts>0) retrying,
			SUM(due<=:now AND retry_at<=:now AND (owner=\'\' OR lease_until<=:now)) due,
			MIN(CASE WHEN due<=:now AND retry_at<=:now AND (owner=\'\' OR lease_until<=:now) THEN MAX(due,retry_at) END) oldest_due FROM jobs');
		$select->execute([':now' => $now]);
		$row = $select->fetch(PDO::FETCH_ASSOC);
		if ($row === false) {
			throw new RuntimeException('Cannot read queue status');
		}
		$pageSize = min((int)$this->config['status_history_limit'], (int)$this->config['history_limit']);
		$history = $this->prepare('SELECT id,username,feed_id,kind,finished,outcome,duration_ms,peak_bytes FROM history ' .
			($historyBefore === null ? '' : 'WHERE id<? ') . 'ORDER BY id DESC LIMIT ' . ($pageSize > 0 ? $pageSize + 1 : 0));
		$history->execute($historyBefore === null ? [] : [$historyBefore]);
		$events = [];
		while (($event = $history->fetch(PDO::FETCH_ASSOC)) !== false) {
			$events[] = [
				'id' => self::integer($event['id']),
				'username' => self::text($event['username']), 'feed_id' => self::integer($event['feed_id']),
				'kind' => self::text($event['kind']), 'finished' => self::integer($event['finished']), 'outcome' => self::text($event['outcome']),
				'duration_ms' => self::integer($event['duration_ms']), 'peak_bytes' => self::integer($event['peak_bytes']),
			];
		}
		$next = null;
		if (count($events) > $pageSize) {
			array_pop($events);
			$last = end($events);
			$next = $last === false ? null : self::integer($last['id']);
		}
		return ['paused' => $this->paused(), 'pending' => self::integer($row['pending'] ?? 0), 'running' => self::integer($row['running'] ?? 0),
			'retrying' => self::integer($row['retrying'] ?? 0),
			'due' => self::integer($row['due'] ?? 0), 'oldestDue' => $row['oldest_due'] === null ? null : self::integer($row['oldest_due']),
			'history' => $events, 'historyNext' => $next];
	}
}
