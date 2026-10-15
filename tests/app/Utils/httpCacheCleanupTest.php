<?php
declare(strict_types=1);

final class httpCacheCleanupTest extends \PHPUnit\Framework\TestCase {
	private string $directory = '';

	#[\Override]
	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/http-cleanup-' . uniqid();
		mkdir($this->directory);
	}

	#[\Override]
	protected function tearDown(): void {
		foreach (new DirectoryIterator($this->directory) as $file) {
			if (!$file->isDot()) {
				unlink($file->getPathname());
			}
		}
		rmdir($this->directory);
	}

	public function testCleanupPreservesActiveResponseAndStableLock(): void {
		$cache = $this->directory . '/entry.json';
		$lockPath = $this->directory . '/.entry.json.lock';
		file_put_contents($cache, 'complete cached body');
		touch($cache, 1);
		$held = fopen($lockPath, 'c');
		self::assertIsResource($held);
		touch($lockPath, 1);
		self::assertTrue(flock($held, LOCK_EX));
		try {
			cleanCache(0, $this->directory);
			self::assertSame('complete cached body', file_get_contents($cache));
			self::assertFileExists($lockPath);
			$contender = fopen($lockPath, 'c');
			self::assertIsResource($contender);
			try {
				self::assertFalse(flock($contender, LOCK_EX | LOCK_NB));
			} finally {
				fclose($contender);
			}
		} finally {
			fclose($held);
		}
		cleanCache(0, $this->directory);
		self::assertFileDoesNotExist($cache);
		self::assertFileExists($lockPath);
	}

	public function testExpirationIsRecheckedAfterAnotherProcessPublishesNewBody(): void {
		$cache = $this->directory . '/entry.json';
		file_put_contents($cache, 'old body');
		touch($cache, 1);
		self::assertSame(1, filemtime($cache)); // Populate this process's stale stat cache.
		$code = '$temporary = $argv[1] . ".tmp"; file_put_contents($temporary, "new body");'
			. ' touch($temporary, time() + 10); rename($temporary, $argv[1]);';
		$process = proc_open([PHP_BINARY, '-r', $code, $cache],
			[['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['pipe', 'w']], $pipes);
		self::assertIsResource($process);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		self::assertSame(0, proc_close($process), is_string($error) ? $error : '');
		self::assertFalse(FreshRSS_http_Util::removeExpiredCacheFile($cache, time()));
		self::assertSame('new body', file_get_contents($cache));
	}
}
