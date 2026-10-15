<?php
declare(strict_types=1);

final class ActualizeMutexTest extends \PHPUnit\Framework\TestCase {
	public function testMutexFilesAreUniquePerDataPathAndUseTheConfiguredTemporaryPath(): void {
		$testPath = sys_get_temp_dir() . '/freshrss-actualize-mutex-' . bin2hex(random_bytes(8));
		$tmpPath = $testPath . '/tmp';
		$firstDataPath = $testPath . '/first/data';
		$secondDataPath = $testPath . '/second/data';
		mkdir($tmpPath, 0700, true);
		mkdir($firstDataPath, 0700, true);
		mkdir($secondDataPath, 0700, true);

		try {
			$firstMutex = actualize_mutex_file($tmpPath, $firstDataPath);
			$secondMutex = actualize_mutex_file($tmpPath, $secondDataPath);

			self::assertSame($firstMutex, actualize_mutex_file($tmpPath, $firstDataPath . '/.'));
			self::assertNotSame($firstMutex, $secondMutex);
			self::assertStringStartsWith($tmpPath . '/actualize.', $firstMutex);
			self::assertStringEndsWith('.freshrss.lock', $firstMutex);

			$firstHandle = fopen($firstMutex, 'x');
			$secondHandle = fopen($secondMutex, 'x');
			self::assertIsResource($firstHandle);
			self::assertIsResource($secondHandle);
			fclose($firstHandle);
			fclose($secondHandle);

			self::assertFalse(@fopen($firstMutex, 'x'));
		} finally {
			recursive_unlink($testPath);
		}
	}
	public function testLockLivesUntilTheHandleClosesRegardlessOfFileAge(): void {
		$path = tempnam(sys_get_temp_dir(), 'freshrss-lock-');
		self::assertIsString($path);
		$first = actualize_acquire_lock($path);
		self::assertIsResource($first);
		try {
			touch($path, time() - 86400);
			self::assertFalse(actualize_acquire_lock($path));
		} finally {
			fclose($first);
		}
		$next = actualize_acquire_lock($path);
		self::assertIsResource($next);
		fclose($next);
		unlink($path);
	}

}
