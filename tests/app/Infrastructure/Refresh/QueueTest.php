<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Infrastructure\Refresh;

use FreshRSS\Infrastructure\Refresh\Queue;
use Generator;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QueueTest extends TestCase {
	private string $path;
	private Queue $queue;

	#[\Override]
	protected function setUp(): void {
		if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
			self::markTestSkipped('PDO SQLite required');
		}
		$path = tempnam(sys_get_temp_dir(), 'refresh-queue-');
		self::assertIsString($path);
		$this->path = $path;
		$this->queue = new Queue($path, ['workers' => 3, 'per_host' => 1, 'retry_jitter_percent' => 0, 'history_limit' => 2]);
	}

	#[\Override]
	protected function tearDown(): void {
		if (isset($this->path)) {
			unset($this->queue);
			foreach ([$this->path, $this->path . '-wal', $this->path . '-shm'] as $file) {
				if (file_exists($file)) {
					unlink($file);
				}
			}
		}
	}

	public function testHostAndUserAdmissionAndGlobalLimit(): void {
		$this->queue->enqueue('alice', 1, 'feed', 'EXAMPLE.ORG', 0);
		$this->queue->enqueue('alice', 2, 'feed', 'other.org', 0);
		$this->queue->enqueue('bob', 3, 'feed', 'example.org', 0);
		$this->queue->enqueue('carol', 4, 'feed', 'else.org', 0);
		$this->queue->enqueue('dave', 5, 'feed', 'last.org', 0);
		$this->queue->enqueue('eve', 6, 'feed', 'extra.org', 0);
		$first = $this->queue->claim(100);
		self::assertNotNull($first);
		self::assertSame('alice', $first['username']);
		self::assertSame('carol', $this->queue->claim(100)['username'] ?? null);
		self::assertSame('dave', $this->queue->claim(100)['username'] ?? null);
		self::assertNull($this->queue->claim(100));
		$this->queue->finish($first['id'], $first['owner'], 'ok', 101);
		self::assertSame('alice', $this->queue->claim(101)['username'] ?? null);
	}

	public function testExpiredLeaseFencesOldCompletionAcrossConnections(): void {
		$this->queue->enqueue('alice', 1, 'feed', 'a.test', 0);
		$first = $this->queue->claim(100);
		self::assertNotNull($first);
		$other = new Queue($this->path);
		self::assertNull($other->claim(200));
		$second = $other->claim(341);
		self::assertNotNull($second);
		self::assertNotSame($first['owner'], $second['owner']);
		self::assertFalse($this->queue->finish($first['id'], $first['owner'], 'ok', 342));
		self::assertTrue($other->finish($second['id'], $second['owner'], 'ok', 343));
		self::assertSame(0, $this->queue->status(343)['pending']);
	}

	public function testReplanningKeepsRetryBackoffAndRespectsRetryAfter(): void {
		$this->queue->enqueue('alice', 1, 'feed', 'a.test', 0);
		$job = $this->queue->claim(100);
		self::assertNotNull($job);
		$this->queue->finish($job['id'], $job['owner'], 'fetch_error', 101, retryAfter: 500);
		$this->queue->enqueue('alice', 1, 'feed', 'a.test', 0);
		self::assertNull($this->queue->claim(499));
		$job = $this->queue->claim(500);
		self::assertNotNull($job);
		self::assertSame(2, $job['attempts']);
		$this->queue->finish($job['id'], $job['owner'], 'crashed', 500);
		self::assertNull($this->queue->claim(619));
		self::assertNotNull($this->queue->claim(620));
	}

	public function testReconcileDeletesOnlyPendingAndRetainsRunningOwnership(): void {
		$this->queue->enqueue('alice', 1, 'feed', 'a.test', 0);
		$this->queue->enqueue('alice', 2, 'feed', 'b.test', 0);
		$this->queue->enqueue('bob', 3, 'feed', 'c.test', 0);
		$job = $this->queue->claim(1);
		self::assertNotNull($job);
		$this->queue->reconcile('alice', []);
		$this->queue->reconcileUsers(['alice']);
		self::assertSame(0, $this->queue->status(1)['pending']);
		self::assertNotNull($this->queue->owned($job['id'], $job['owner']));
	}

	public function testMaintenanceIntervalSurvivesReplanningAndHistoryIsBounded(): void {
		$this->queue->enqueueMaintenance('alice', 100);
		$job = $this->queue->claim(100);
		self::assertNotNull($job);
		$this->queue->finish($job['id'], $job['owner'], 'ok', 101, 45, 1024);
		$this->queue->enqueueMaintenance('alice', 102);
		self::assertNull($this->queue->claim(3700));
		self::assertNotNull($this->queue->claim(3701));
		foreach ([1, 2, 3] as $id) {
			$this->queue->enqueue('bob', $id, 'feed', 'b.test', 0);
			$feed = $this->queue->claim(200);
			self::assertNotNull($feed);
			$this->queue->finish($feed['id'], $feed['owner'], 'ok', 201);
		}
		self::assertCount(2, $this->queue->status(202)['history']);
	}

	public function testParallelProcessesCannotBothClaimOneJob(): void {
		if (!function_exists('proc_open')) {
			self::markTestSkipped('proc_open required');
		}
		$this->queue->enqueue('alice', 1, 'feed', 'a.test', 0);
		$bootstrap = FRESHRSS_PATH . '/tests/bootstrap.php';
		$code = 'require ' . var_export($bootstrap, true) . '; $q=new FreshRSS\Infrastructure\Refresh\Queue(' . var_export($this->path, true) .
			'); echo $q->claim(100) === null ? "none" : "claimed";';
		$children = [];
		foreach ([1, 2, 3, 4] as $_) {
			$process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
			self::assertIsResource($process);
			fclose($pipes[0]);
			$children[] = [$process, $pipes];
		}
		$output = [];
		foreach ($children as [$process, $pipes]) {
			$output[] = stream_get_contents($pipes[1]);
			$error = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			self::assertSame(0, proc_close($process), $error ?: 'worker failed');
		}
		self::assertCount(1, array_filter($output, static fn($value) => $value === 'claimed'));
		self::assertCount(3, array_filter($output, static fn($value) => $value === 'none'));
	}

	public function testPausedQueuePreservesWorkAndCurrentOwners(): void {
		$this->queue->enqueue('alice', 1, 'feed', 'a.test', 0);
		$this->queue->enqueue('bob', 2, 'feed', 'b.test', 0);
		$first = $this->queue->claim(100);
		self::assertNotNull($first);
		$this->queue->setPaused(true);
		self::assertTrue($this->queue->status(100)['paused']);
		self::assertNull($this->queue->claim(100));
		self::assertTrue($this->queue->finish($first['id'], $first['owner'], 'ok', 100));
		$this->queue->setPaused(false);
		self::assertSame('bob', $this->queue->claim(100)['username'] ?? null);
	}

	public function testParallelUserFeedsStillExcludeMaintenance(): void {
		$queue = new Queue($this->path, ['per_user' => 2, 'workers' => 3, 'per_host' => 3]);
		$queue->enqueue('alice', 1, 'feed', 'a.test', 0);
		$queue->enqueue('alice', 2, 'feed', 'a.test', 0);
		$queue->enqueueMaintenance('alice', 0);
		$one = $queue->claim(100);
		$two = $queue->claim(100);
		self::assertNotNull($one);
		self::assertNotNull($two);
		self::assertSame('feed', $two['kind']);
		self::assertNull($queue->claim(100));
		$queue->finish($one['id'], $one['owner'], 'ok', 100);
		self::assertNull($queue->claim(100));
		$queue->finish($two['id'], $two['owner'], 'ok', 100);
		self::assertSame('maintenance', $queue->claim(100)['kind'] ?? null);
	}

	public function testIncompletePlanningDoesNotDeleteOldPendingJobs(): void {
		$queue = new Queue($this->path, ['plan_batch_size' => 1]);
		$queue->enqueue('alice', 1, 'feed', 'a.test', 0);
		$feeds = static function (): Generator {
			yield ['id' => 2, 'host' => 'b.test', 'due' => 0];
			throw new RuntimeException('Interrupted subscription scan');
		};
		try {
			$queue->synchronizeFeeds('alice', $feeds());
			self::fail('The interrupted plan must report its error');
		} catch (RuntimeException $e) {
			self::assertSame('Interrupted subscription scan', $e->getMessage());
		}
		self::assertSame(2, $queue->status(100)['pending']);
		self::assertSame(1, $queue->synchronizeFeeds('alice', [['id' => 3, 'host' => 'c.test', 'due' => 0]]));
		self::assertSame(1, $queue->status(100)['pending']);
		self::assertSame(3, $queue->claim(100)['feed_id'] ?? null);
	}

	public function testStatusPagesHistoryIndependentlyOfRetention(): void {
		$queue = new Queue($this->path, ['status_history_limit' => 2, 'history_limit' => 10]);
		for ($id = 1; $id <= 5; $id++) {
			$queue->enqueue('alice', $id, 'feed', 'a.test', 0);
			$job = $queue->claim(100);
			self::assertNotNull($job);
			$queue->finish($job['id'], $job['owner'], 'ok', 100);
		}
		$page = $queue->status(101);
		self::assertSame([5, 4], array_column($page['history'], 'feed_id'));
		self::assertNotNull($page['historyNext']);
		$page = $queue->status(101, $page['historyNext']);
		self::assertSame([3, 2], array_column($page['history'], 'feed_id'));
		$page = $queue->status(101, $page['historyNext']);
		self::assertSame([1], array_column($page['history'], 'feed_id'));
		self::assertNull($page['historyNext']);
		$hidden = new Queue($this->path, ['status_history_limit' => 0]);
		self::assertSame([], $hidden->status(101)['history']);
		self::assertSame([5, 4], array_column($queue->status(101)['history'], 'feed_id'));
	}
}
