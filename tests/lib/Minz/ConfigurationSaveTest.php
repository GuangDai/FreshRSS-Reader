<?php
declare(strict_types=1);

final class ConfigurationSaveTest extends \PHPUnit\Framework\TestCase {
	private string $directory;

	#[\Override]
	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/freshrss-config-save-' . bin2hex(random_bytes(8));
		mkdir($this->directory);
		file_put_contents($this->directory . '/config.php', "<?php return ['title' => 'original'];");
	}

	#[\Override]
	protected function tearDown(): void {
		recursive_unlink($this->directory);
	}

	private function configuration(string $path): Minz_Configuration {
		Minz_Configuration::register('save-test', $path);
		return Minz_Configuration::get('save-test');
	}

	public function testSuccessfulSavePreservesPermissionsAndPreviousConfiguration(): void {
		$path = $this->directory . '/config.php';
		chmod($path, 0600);
		$config = $this->configuration($path);
		$config->title = 'updated';
		self::assertTrue($config->save());
		self::assertSame('updated', Minz_Configuration::load($path)['title']);
		self::assertSame('original', Minz_Configuration::load($path . '.bak.php')['title']);
		clearstatcache(true, $path);
		self::assertSame(0600, fileperms($path) & 0777);
		self::assertSame(0600, fileperms($path . '.bak.php') & 0777);
		self::assertSame([], glob($path . '.*.tmp.php'));
	}

	public function testRenameFailureIsReportedWithoutReplacingOrLeakingTemporaryConfiguration(): void {
		ConfigurationSaveStream::$directory = $this->directory;
		ConfigurationSaveStream::$renameAttempts = 0;
		stream_wrapper_register('config-save-test', ConfigurationSaveStream::class);
		try {
			$config = $this->configuration('config-save-test://config.php');
			$config->title = 'must not become visible';
			self::assertFalse($config->save());
			self::assertSame(1, ConfigurationSaveStream::$renameAttempts);
			self::assertSame('original', Minz_Configuration::load($this->directory . '/config.php')['title']);
			self::assertSame([], glob($this->directory . '/config.php.*.tmp.php'));
		} finally {
			stream_wrapper_unregister('config-save-test');
		}
	}

	public function testBackupFailureLeavesTheCurrentConfigurationReadable(): void {
		$path = $this->directory . '/config.php';
		mkdir($path . '.bak.php');
		$config = $this->configuration($path);
		$config->title = 'not saved';
		self::assertFalse($config->save());
		self::assertSame('original', Minz_Configuration::load($path)['title']);
		self::assertSame([], glob($path . '.*.tmp.php'));
	}

	public function testConcurrentSavesPublishCompleteReadableConfigurations(): void {
		if (!function_exists('proc_open')) {
			self::markTestSkipped('proc_open is required');
		}
		$code = <<<'PHP'
			require $argv[1] . '/constants.php';
			require LIB_PATH . '/lib_rss.php';
			Minz_Configuration::register('writer', $argv[2]);
			$config = Minz_Configuration::get('writer');
			for ($i = 0; $i < 12; $i++) {
				$config->title = str_repeat($argv[3], 8192);
				if (!$config->save()) {
					exit(1);
				}
			}
			PHP;
		$processes = [];
		$path = $this->directory . '/config.php';
		try {
			foreach (['a', 'b', 'c', 'd'] as $writer) {
				$process = proc_open([PHP_BINARY, '-r', $code, FRESHRSS_PATH, $path, $writer],
					[1 => ['file', $this->directory . '/child.log', 'a'], 2 => ['file', $this->directory . '/child.log', 'a']], $pipes);
				self::assertIsResource($process);
				$processes[] = $process;
			}
			$deadline = microtime(true) + 15;
			while ($processes !== [] && microtime(true) < $deadline) {
				$value = Minz_Configuration::load($path)['title'];
				self::assertIsString($value);
				self::assertTrue($value === 'original' || preg_match('/^(a{8192}|b{8192}|c{8192}|d{8192})$/D', $value) === 1);
				foreach ($processes as $key => $process) {
					$status = proc_get_status($process);
					if (!$status['running']) {
						proc_close($process);
						unset($processes[$key]);
						self::assertSame(0, $status['exitcode'], (string)file_get_contents($this->directory . '/child.log'));
					}
				}
				usleep(1000);
			}
			self::assertSame([], $processes, 'Concurrent saves did not finish');
			$backup = Minz_Configuration::load($path . '.bak.php')['title'];
			self::assertIsString($backup);
			self::assertSame(str_repeat($backup[0], 8192), $backup);
			self::assertSame([], glob($path . '.*.tmp.php'));
		} finally {
			foreach ($processes as $process) {
				proc_terminate($process, 9);
				proc_close($process);
			}
		}
	}
}

/** Local filesystem wrapper that fails only the final rename, independent of root privileges. */
final class ConfigurationSaveStream {
	public static string $directory = '';
	public static int $renameAttempts = 0;
	/** @var resource|null */
	public $context;
	/** @var resource */
	private $stream;

	private static function path(string $path): string {
		return self::$directory . '/' . substr($path, strlen('config-save-test://'));
	}

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$stream = fopen(self::path($path), $mode);
		if ($stream === false) {
			return false;
		}
		$this->stream = $stream;
		return true;
	}

	public function stream_read(int $count): string|false {
		return $count > 0 ? fread($this->stream, $count) : '';
	}

	public function stream_write(string $data): int|false {
		return fwrite($this->stream, $data);
	}

	public function stream_eof(): bool {
		return feof($this->stream);
	}

	public function stream_close(): void {
		fclose($this->stream);
	}

	public function stream_lock(int $operation): bool {
		return $operation >= 0 && $operation <= (LOCK_SH | LOCK_EX | LOCK_UN | LOCK_NB) && flock($this->stream, $operation);
	}

	/** @return array<int|string,int>|false */
	public function stream_stat(): array|false {
		return fstat($this->stream);
	}

	/** @return array<int|string,int>|false */
	public function url_stat(string $path, int $flags): array|false {
		return @stat(self::path($path));
	}

	public function stream_metadata(string $path, int $option, mixed $value): bool {
		return $option === STREAM_META_ACCESS && is_int($value) && chmod(self::path($path), $value);
	}

	public function stream_set_option(int $option, int $arg1, int $arg2): bool {
		return false;
	}

	public function unlink(string $path): bool {
		return unlink(self::path($path));
	}

	public function rename(string $from, string $to): bool {
		self::$renameAttempts++;
		return false;
	}
}
