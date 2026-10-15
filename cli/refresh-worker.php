#!/usr/bin/env php
<?php
declare(strict_types=1);

use FreshRSS\Infrastructure\Concurrency\RefreshLocks;
use FreshRSS\Infrastructure\Concurrency\UserWorkLock;
use FreshRSS\Infrastructure\Refresh\Queue;
use FreshRSS\Services\Refresh\SchedulerConfiguration;

// Install a kernel deadline before bootstrapping user code. It survives supervisor loss
// and uses the default signal action, so blocked PHP/C callbacks cannot defer termination.
$watchdog = filter_var($argv[3] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_int($watchdog) || !function_exists('pcntl_alarm') || !function_exists('pcntl_signal')) {
	fwrite(STDERR, "Refresh worker requires a positive watchdog duration and PCNTL\n");
	exit(1);
}
pcntl_signal(SIGALRM, SIG_DFL);
pcntl_alarm($watchdog);
require __DIR__ . '/_cli.php';

$id = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$owner = $argv[2] ?? '';
if (!is_int($id) || preg_match('/^[a-f0-9]{32}$/D', $owner) !== 1) {
	fail('Usage: refresh-worker.php JOB_ID OWNER WATCHDOG_SECONDS (internal scheduler command)');
}
$queue = Queue::open();
$job = $queue->owned($id, $owner);
if ($job === null || $job['lease_until'] <= time()) {
	exit(0);
}
// A lease can expire after a supervisor crash. The physical lock still excludes its surviving child.
$config = SchedulerConfiguration::configuration(FreshRSS_Context::systemConf()->scheduler);
$locks = new RefreshLocks(DATA_PATH . '/scheduler');
if (!$locks->acquire($job['username'], $job['host'], (int)$config['workers'], (int)$config['per_host'],
	(int)$config['per_user'], $job['kind'] !== 'feed')) {
	$queue->finish($id, $owner, 'busy', time());
	exit(0);
}
$start = microtime(true);
$outcome = 'ok';
$retryAfter = 0;
try {
	if ($queue->owned($id, $owner) === null) {
		exit(0);
	}
	if ($job['kind'] === 'cache') {
		cleanCache(CLEANCACHE_HOURS);
		FreshRSS_http_Util::cleanRetryAfters();
	} elseif (!FreshRSS_user_Controller::userExists($job['username'])) {
		$outcome = 'removed';
	} else {
		cliInitUser($job['username']);
		if (!FreshRSS_Context::userConf()->enabled) {
			$outcome = 'removed';
		} elseif ($job['kind'] === 'maintenance') {
			$applicationLock = UserWorkLock::acquire();
			FreshRSS_Factory::createDatabaseDAO()->minorDbMaintenance();
			Minz_ExtensionManager::callHookVoid(Minz_HookType::FreshrssUserMaintenance);
			FreshRSS_feed_Controller::commitNewEntries();
			if (FreshRSS_Factory::createFeedDao()->updateCachedValues() === false) {
				throw new RuntimeException('Failed to refresh feed counters');
			}
			$result = FreshRSS_category_Controller::refreshDynamicOpmls();
			$outcome = !empty($result['errors']) ? 'fetch_error' : 'ok';
			invalidateHttpCache($job['username']);
		} else {
			$feed = FreshRSS_Factory::createFeedDao()->searchById($job['feed_id']);
			if ($feed === null || $feed->mute()) {
				$outcome = 'removed';
			} else {
				$retryAfter = FreshRSS_http_Util::getRetryAfter(htmlspecialchars_decode($feed->url(), ENT_QUOTES), $feed->proxyParam());
				if ($retryAfter > time()) {
					$outcome = 'fetch_error';
				} else {
					[$updated, $result] = FreshRSS_feed_Controller::actualizeFeedsAndCommit($job['feed_id'], cleanCache: false, expectedHost: $job['host']);
					$outcome = $updated > 0 ? 'ok' : (($result !== null && $result->inError()) ? 'fetch_error' : 'busy');
					$retryAfter = FreshRSS_http_Util::getRetryAfter(htmlspecialchars_decode($feed->url(), ENT_QUOTES), $feed->proxyParam());
					invalidateHttpCache($job['username']);
				}
			}
		}
	}
} catch (Throwable $e) {
	$outcome = 'exception';
	// Detailed errors remain in the existing user log. Queue history deliberately has no raw exception text.
	Minz_Log::error('Refresh worker: ' . get_class($e));
} finally {
	$queue->finish($id, $owner, $outcome, time(), (int)((microtime(true) - $start) * 1000), memory_get_peak_usage(true), $retryAfter);
	$locks->release();
}
