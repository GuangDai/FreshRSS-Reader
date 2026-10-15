<?php
declare(strict_types=1);

final class retryAfterConcurrencyTest extends \PHPUnit\Framework\TestCase {
	private string $directory = '';

	#[\Override]
	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/retry-concurrent-' . uniqid();
		mkdir($this->directory);
		mkdir($this->directory . '/Retry-After');
	}

	#[\Override]
	protected function tearDown(): void {
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($iterator as $file) {
			if ($file instanceof SplFileInfo) {
				if ($file->isDir()) {
					rmdir($file->getPathname());
				} else {
					unlink($file->getPathname());
				}
			}
		}
		rmdir($this->directory);
	}

	/** @return array{resource,array<int,resource>} */
	private function startClient(string $operation): array {
		$process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/retry-after-client.php', $this->directory, $operation],
			[['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
		self::assertIsResource($process);
		return [$process, $pipes];
	}

	/** @param array{resource,array<int,resource>} $client */
	private function finishClient(array $client): string {
		[$process, $pipes] = $client;
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		self::assertSame(0, proc_close($process), is_string($error) ? $error : '');
		self::assertIsString($output);
		return $output;
	}

	public function testBoundedCleanupReachesExpiredRecordsAfterActivePrefixAcrossProcesses(): void {
		$path = $this->directory . '/Retry-After';
		for ($i = 0; $i < 6; $i++) {
			touch($path . '/record-' . $i . '.txt', time() + 300);
		}
		$names = [];
		foreach (new DirectoryIterator($path) as $file) {
			if (!$file->isDot()) {
				$names[] = $file->getFilename();
			}
		}
		$expired = array_pop($names);
		self::assertIsString($expired);
		touch($path . '/' . $expired, 1);
		// Unmanaged entries must not repeatedly consume the entire scan budget.
		file_put_contents($path . '/keep.bin', 'keep');
		$removed = 0;
		for ($i = 0; $i < 10; $i++) {
			$removed += (int)$this->finishClient($this->startClient('clean'));
		}
		self::assertSame(1, $removed);
		foreach ($names as $name) {
			self::assertTrue(is_file($path . '/0/' . $name) || is_file($path . '/1/' . $name));
		}
		self::assertSame('keep', file_get_contents($path . '/keep.bin'));
	}

	public function testConcurrentReadCannotMissRecordsDuringMigration(): void {
		$expiry = (int)$this->finishClient($this->startClient('set'));
		self::assertGreaterThan(time(), $expiry);
		$reader = $this->startClient('stress-read');
		$cleaner = $this->startClient('stress-clean');
		self::assertSame('0', $this->finishClient($reader));
		self::assertSame('', $this->finishClient($cleaner));
		self::assertSame((string)$expiry, $this->finishClient($this->startClient('read')));
	}
}
