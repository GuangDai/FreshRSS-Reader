#!/usr/bin/env php
<?php
declare(strict_types=1);

use FreshRSS\Infrastructure\Refresh\Queue;
use FreshRSS\Services\Refresh\Scheduler;
use FreshRSS\Services\Refresh\SchedulerConfiguration;

require __DIR__ . '/_cli.php';

$command = $argv[1] ?? 'status';
try {
	if ($command === 'schema') {
		echo json_encode(SchedulerConfiguration::schema(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
	} elseif ($command === 'status') {
		$before = isset($argv[2]) ? filter_var($argv[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
		if ($before === false) {
			fail('History cursor must be a positive integer');
		}
		echo json_encode(Queue::open()->status(time(), $before), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
	} elseif ($command === 'pause' || $command === 'resume') {
		$queue = Queue::open();
		$queue->setPaused($command === 'pause');
		echo json_encode(['paused' => $queue->paused()], JSON_THROW_ON_ERROR), "\n";
	} elseif ($command === 'run' || $command === 'enqueue') {
		$scheduler = new Scheduler();
		$planned = $scheduler->plan();
		$completed = $command === 'run' ? $scheduler->run() : 0;
		echo json_encode(['feeds' => $planned, 'completed' => $completed], JSON_THROW_ON_ERROR), "\n";
	} else {
		fail('Usage: refresh-queue.php [status [HISTORY_BEFORE_ID]|schema|enqueue|run|pause|resume]');
	}
} catch (Throwable $e) {
	fail('Scheduler: ' . $e->getMessage());
}
