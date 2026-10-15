<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Infrastructure\Concurrency;

use FreshRSS\Infrastructure\Concurrency\RefreshLocks;
use PHPUnit\Framework\TestCase;

final class RefreshLocksTest extends TestCase {
	public function testExecutionSlotsExcludeExpiredButStillRunningWorkers(): void {
		$directory = sys_get_temp_dir() . '/refresh-locks-' . bin2hex(random_bytes(8));
		mkdir($directory, 0700);
		$first = new RefreshLocks($directory);
		$second = new RefreshLocks($directory);
		$third = new RefreshLocks($directory);
		try {
			self::assertTrue($first->acquire('alice', 'a.test', 2, 1));
			self::assertFalse($second->acquire('bob', 'a.test', 2, 1));
			self::assertFalse($second->acquire('alice', 'b.test', 2, 1));
			self::assertTrue($second->acquire('bob', 'b.test', 2, 1));
			self::assertFalse($third->acquire('carol', 'c.test', 2, 1));
			$first->release();
			self::assertTrue($third->acquire('carol', 'a.test', 2, 1));
		} finally {
			$first->release();
			$second->release();
			$third->release();
			recursive_unlink($directory);
		}
	}

	public function testAbruptProcessExitReleasesExecutionSlotWithoutDeletingFiles(): void {
		if (!function_exists('proc_open')) {
			self::markTestSkipped('proc_open required');
		}
		$directory = sys_get_temp_dir() . '/refresh-locks-' . bin2hex(random_bytes(8));
		mkdir($directory, 0700);
		$bootstrap = FRESHRSS_PATH . '/tests/bootstrap.php';
		$code = 'require ' . var_export($bootstrap, true) . '; $locks=new FreshRSS\Infrastructure\Concurrency\RefreshLocks(' . var_export($directory, true) .
			'); echo $locks->acquire("alice","a.test",1,1) ? "ready\\n" : "failed\\n"; flush(); sleep(60);';
		$process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		self::assertIsResource($process);
		$next = new RefreshLocks($directory);
		try {
			stream_set_timeout($pipes[1], 5);
			self::assertSame("ready\n", fgets($pipes[1]));
			self::assertFalse($next->acquire('bob', 'other.test', 1, 1));
			proc_terminate($process, 9);
			proc_close($process);
			$process = null;
			self::assertTrue($next->acquire('bob', 'other.test', 1, 1));
		} finally {
			if (is_resource($process)) {
				proc_terminate($process, 9);
				proc_close($process);
			}
			foreach ($pipes as $pipe) {
				if (is_resource($pipe)) {
					fclose($pipe);
				}
			}
			$next->release();
			recursive_unlink($directory);
		}
	}
}
