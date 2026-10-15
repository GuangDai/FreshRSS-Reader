<?php
declare(strict_types=1);

final class ConfigurationMutationTest extends \PHPUnit\Framework\TestCase {
	private string $directory;
	private mixed $previousConfigurations;

	#[\Override]
	protected function setUp(): void {
		$this->previousConfigurations = (new ReflectionProperty(Minz_Configuration::class, 'config_list'))->getValue();
		$this->directory = sys_get_temp_dir() . '/freshrss-config-mutation-' . bin2hex(random_bytes(8));
		mkdir($this->directory);
		file_put_contents($this->directory . '/system.php', "<?php return ['salt' => 'test-only-configuration-version-secret'];");
		file_put_contents($this->directory . '/config.php', "<?php return ['title' => 'original'];");
		Minz_Configuration::register('system', $this->directory . '/system.php');
	}

	#[\Override]
	protected function tearDown(): void {
		(new ReflectionProperty(Minz_Configuration::class, 'config_list'))->setValue(null, $this->previousConfigurations);
		recursive_unlink($this->directory);
	}

	private function configuration(string $filename = 'config.php'): Minz_Configuration {
		Minz_Configuration::register('mutation-test', $this->directory . '/' . $filename);
		return Minz_Configuration::get('mutation-test');
	}

	public function testPatchesFromStaleObjectsPreserveIndependentFields(): void {
		$first = $this->configuration();
		$second = $this->configuration();
		self::assertTrue($first->savePatch(['title' => 'first']));
		self::assertTrue($second->savePatch(['language' => 'fr']));
		self::assertSame(['title' => 'first', 'language' => 'fr'], $second->toArray());
		self::assertSame($second->toArray(), $this->configuration()->toArray());
		self::assertTrue($second->savePatch(['language' => null]));
		self::assertFalse($second->hasParam('language'));
	}

	public function testExpectedVersionRejectsLostUpdatesWithoutChangingMemory(): void {
		$first = $this->configuration();
		$second = $this->configuration();
		$version = $first->version();
		self::assertSame($version, $second->version());
		self::assertTrue($first->savePatch(['title' => 'first'], $version));
		self::assertNotSame($version, $first->version());
		try {
			$second->savePatch(['title' => 'second'], $version);
			self::fail('A stale configuration version must be rejected');
		} catch (Minz_ConfigurationConflictException $e) {
			self::assertSame('original', $second->title);
			self::assertSame($version, $second->version());
			self::assertSame('first', $this->configuration()->title);
		}
	}

	public function testVersionsAreBoundToTheConfigurationFile(): void {
		copy($this->directory . '/config.php', $this->directory . '/other.php');
		self::assertNotSame($this->configuration()->version(), $this->configuration('other.php')->version());
	}

	public function testCallbackExceptionRestoresMemoryAndReleasesTheLock(): void {
		$config = $this->configuration();
		$version = $config->version();
		try {
			$config->mutate(static function (array $latest) use ($config): array {
				$config->title = 'incidental object change';
				throw new RuntimeException('rejected mutation');
			});
			self::fail('The callback exception must propagate');
		} catch (RuntimeException $e) {
			self::assertSame('rejected mutation', $e->getMessage());
		}
		self::assertSame('original', $config->title);
		self::assertSame($version, $config->version());
		self::assertTrue($config->savePatch(['title' => 'after exception'], $version));
	}

	public function testWriteFailureDoesNotPublishTheMutationInMemory(): void {
		$config = $this->configuration();
		$version = $config->version();
		mkdir($this->directory . '/config.php.bak.php');
		self::assertFalse($config->savePatch(['title' => 'not persisted']));
		self::assertSame('original', $config->title);
		self::assertSame($version, $config->version());
		rmdir($this->directory . '/config.php.bak.php');
		self::assertTrue($config->savePatch(['title' => 'retry'], $version));
	}

	public function testRecursiveSaveFailsWithoutDeadlocking(): void {
		$config = $this->configuration();
		try {
			$config->mutate(static function (array $latest) use ($config): array {
				$config->save();
				return $latest;
			});
			self::fail('Recursive save must fail');
		} catch (LogicException $e) {
			self::assertStringContainsString('Recursive', $e->getMessage());
		}
		self::assertTrue($config->savePatch(['title' => 'after recursive failure']));
	}

	public function testRemovingAnOverrideRestoresDefaultsAndKeepsVersionsStable(): void {
		file_put_contents($this->directory . '/defaults.php', "<?php return ['language' => 'en'];");
		Minz_Configuration::register('with-defaults', $this->directory . '/config.php', $this->directory . '/defaults.php');
		$config = Minz_Configuration::get('with-defaults');
		self::assertTrue($config->savePatch(['language' => 'fr']));
		self::assertTrue($config->savePatch(['language' => null], $config->version()));
		self::assertSame('en', $config->attributeString('language'));
		self::assertArrayNotHasKey('language', Minz_Configuration::load($this->directory . '/config.php'));
		Minz_Configuration::register('reloaded', $this->directory . '/config.php', $this->directory . '/defaults.php');
		self::assertSame($config->version(), Minz_Configuration::get('reloaded')->version());
	}

	public function testRecursiveMutationThroughASecondInstanceFails(): void {
		$first = $this->configuration();
		$second = $this->configuration();
		try {
			$first->mutate(static function (array $latest) use ($second): array {
				$second->save();
				return $latest;
			});
			self::fail('Recursive save through another instance must fail');
		} catch (LogicException $e) {
			self::assertStringContainsString('Recursive', $e->getMessage());
		}
		self::assertTrue($second->savePatch(['title' => 'released']));
	}

	public function testConcurrentMutationsDoNotLoseIncrements(): void {
		if (!function_exists('proc_open')) {
			self::markTestSkipped('proc_open is required');
		}
		$code = <<<'PHP'
			require $argv[1] . '/constants.php';
			require LIB_PATH . '/lib_rss.php';
			Minz_Configuration::register('writer', $argv[2]);
			$config = Minz_Configuration::get('writer');
			for ($i = 0; $i < 15; $i++) {
				if (!$config->mutate(static function (array $latest): array {
					$latest['count'] = ($latest['count'] ?? 0) + 1;
					return $latest;
				})) {
					exit(1);
				}
			}
			PHP;
		$processes = [];
		try {
			for ($i = 0; $i < 4; $i++) {
				$process = proc_open([PHP_BINARY, '-r', $code, FRESHRSS_PATH, $this->directory . '/config.php'],
					[1 => ['file', $this->directory . '/child.log', 'a'], 2 => ['file', $this->directory . '/child.log', 'a']], $pipes);
				self::assertIsResource($process);
				$processes[] = $process;
			}
			$deadline = microtime(true) + 15;
			while ($processes !== [] && microtime(true) < $deadline) {
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
			self::assertSame([], $processes, 'Concurrent mutations did not finish');
			self::assertSame(60, $this->configuration()->attributeInt('count'));
		} finally {
			foreach ($processes as $process) {
				proc_terminate($process, 9);
				proc_close($process);
			}
		}
	}
}
