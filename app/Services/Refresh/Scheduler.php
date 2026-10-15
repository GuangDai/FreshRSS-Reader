<?php
declare(strict_types=1);

namespace FreshRSS\Services\Refresh;

use FreshRSS\Infrastructure\Refresh\Queue;
use FreshRSS_Context;
use FreshRSS_Factory;
use FreshRSS_Feed;
use FreshRSS_UserDAO;
use FreshRSS_user_Controller;
use Minz_Log;
use RuntimeException;
use Throwable;
use Traversable;

/** Process isolation keeps parser/extension allocations out of the long-lived supervisor.
 * @phpstan-import-type RefreshJob from Queue
 */
final class Scheduler {
	/** @var array<string,int|bool> */
	private array $config;
	private Queue $queue;

	public function __construct() {
		$this->config = SchedulerConfiguration::configuration(FreshRSS_Context::systemConf()->scheduler);
		$this->queue = Queue::open();
	}

	/** Sync current feed settings; retry backoff stays independent of configured feed TTL.
	 * @throws Throwable
	 */
	public function plan(): int {
		$lock = actualize_acquire_lock(DATA_PATH . '/scheduler/planning.lock');
		if ($lock === false) {
			return 0;
		}
		try {
			$now = time();
			$count = 0;
			$eligible = [];
			foreach (FreshRSS_user_Controller::listUsers() as $user) {
				FreshRSS_Context::initUser($user);
				if (!FreshRSS_Context::hasUserConf() || !FreshRSS_Context::userConf()->enabled ||
					($user !== FreshRSS_Context::systemConf()->default_user &&
						FreshRSS_UserDAO::mtime($user) < $now - FreshRSS_Context::systemConf()->limits['max_inactivity'])) {
					continue;
				}
				$eligible[] = $user;
				$this->queue->enqueueMaintenance($user, $now);
				$count += $this->queue->synchronizeFeeds($user, $this->feedSchedule());
			}
			$this->queue->reconcileUsers($eligible);
			$this->queue->enqueueMaintenance('', $now);
			return $count;
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	/** @return Traversable<array{id:int,host:string,due:int}> */
	private function feedSchedule(): Traversable {
		$dao = FreshRSS_Factory::createFeedDao();
		foreach ($dao->iterateRefreshFeeds((int)$this->config['plan_batch_size']) as $feed) {
			if ($feed->mute()) {
				continue;
			}
			$ttl = $feed->ttl() === FreshRSS_Feed::TTL_DEFAULT ? FreshRSS_Context::userConf()->ttl_default : $feed->ttl();
			if (FreshRSS_Context::systemConf()->pubsubhubbub_enabled && $feed->pubSubHubbubEnabled()) {
				$ttl = max($ttl, (int)$this->config['websub_poll_interval']);
			}
			$host = parse_url(htmlspecialchars_decode($feed->url(), ENT_QUOTES), PHP_URL_HOST);
			yield ['id' => $feed->id(), 'host' => is_string($host) ? $host : '', 'due' => $feed->lastUpdate() + $ttl];
		}
	}

	/** Supervisor terminates only its own children and never accumulates their stdout/stderr.
	 * @throws Throwable
	 */
	public function run(): int {
		if (!function_exists('proc_open') || !function_exists('pcntl_alarm') || !function_exists('pcntl_signal')) {
			throw new RuntimeException('Scheduler requires proc_open and the PCNTL extension');
		}
		$started = hrtime(true) / 1e9;
		/** @var array<int,array{process:resource,job:RefreshJob,start:float,term:bool}> $running */
		$running = [];
		$completed = 0;
		$stopRequested = false;
		$forceStop = false;
		$previousAsync = pcntl_async_signals();
		$previousHandlers = [SIGTERM => pcntl_signal_get_handler(SIGTERM), SIGINT => pcntl_signal_get_handler(SIGINT)];
		pcntl_async_signals(true);
		foreach ($previousHandlers as $signal => $_handler) {
			pcntl_signal($signal, static function () use (&$stopRequested, &$forceStop): void {
				$forceStop = $stopRequested;
				$stopRequested = true;
			});
		}
		try {
			do {
				if ($forceStop) {
					break;
				}
				foreach ($running as $id => &$child) {
					$status = proc_get_status($child['process']);
					$elapsed = (hrtime(true) / 1e9) - $child['start'];
					if (!$status['running']) {
						proc_close($child['process']);
						// Successful jobs acknowledge themselves. A fenced fallback records crashes/timeouts only.
						unset($running[$id]);
						$timedOut = $child['term'] || ($status['signaled'] && $status['termsig'] === SIGALRM);
						$this->queue->finish($id, $child['job']['owner'], $timedOut ? 'timeout' : 'crashed', time(), (int)($elapsed * 1000));
						$completed++;
					} elseif ($elapsed > (int)$this->config['job_timeout']) {
						$signal = $elapsed > (int)$this->config['job_timeout'] + (int)$this->config['kill_grace'] ? 9 : 15;
						proc_terminate($child['process'], $signal);
						$child['term'] = true;
					}
				}
				unset($child);
				$accept = (int)$this->config['run_seconds'] === 0 || (hrtime(true) / 1e9) - $started < (int)$this->config['run_seconds'];
				while (!$stopRequested && $accept && count($running) < (int)$this->config['workers'] && ($job = $this->queue->claim(time())) !== null) {
					$command = [PHP_BINARY, '-d', 'memory_limit=' . (int)$this->config['worker_memory_mib'] . 'M',
						APP_PATH . '/../cli/refresh-worker.php', (string)$job['id'], $job['owner'],
						(string)((int)$this->config['job_timeout'] + (int)$this->config['kill_grace'])];
					$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
					$process = proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
					if ($process === false) {
						$this->queue->finish($job['id'], $job['owner'], 'crashed', time());
						throw new RuntimeException('Cannot launch refresh worker');
					}
					$running[$job['id']] = ['process' => $process, 'job' => $job, 'start' => (hrtime(true) / 1e9), 'term' => false];
				}
				if ($running !== []) {
					$pause = (int)$this->config['poll_ms'] / 1000;
					foreach ($running as $child) {
						$deadline = $child['start'] + (int)$this->config['job_timeout'] + ($child['term'] ? (int)$this->config['kill_grace'] : 0);
						$pause = min($pause, max(0, $deadline - (hrtime(true) / 1e9)));
					}
					usleep((int)($pause * 1000000));
				}
			} while ($running !== []);
		} finally {
			// Terminate every owned process before any queue operation can throw.
			foreach ($running as $child) {
				if (is_resource($child['process'])) {
					proc_terminate($child['process'], 9);
				}
			}
			foreach ($running as $child) {
				if (is_resource($child['process'])) {
					proc_close($child['process']);
				}
				try {
					$this->queue->finish($child['job']['id'], $child['job']['owner'], 'crashed', time());
				} catch (Throwable $e) {
					// Lease expiry recovers the task if the queue itself is unavailable.
					Minz_Log::error('Cannot acknowledge terminated refresh worker: ' . get_class($e));
				}
			}
			foreach ($previousHandlers as $signal => $handler) {
				pcntl_signal($signal, $handler);
			}
			pcntl_async_signals($previousAsync);
		}
		return $completed;
	}
}
