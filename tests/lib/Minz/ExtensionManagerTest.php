<?php
declare(strict_types=1);

final class ExtensionManagerTest extends \PHPUnit\Framework\TestCase {
	#[\Override]
	protected function setUp(): void {
		self::resetManager();
	}

	#[\Override]
	protected function tearDown(): void {
		self::resetManager();
	}

	private static function resetManager(): void {
		(new ReflectionMethod(Minz_ExtensionManager::class, 'reset'))->invoke(null);
	}

	/** @param array<string,mixed> $metadata */
	private static function valid(array $metadata): mixed {
		return (new ReflectionMethod(Minz_ExtensionManager::class, 'isValidMetadata'))->invoke(null, $metadata);
	}

	public function testMalformedMetadataCannotCrashDiscovery(): void {
		self::assertFalse(self::valid(['name' => ['bad'], 'entrypoint' => []]));
		self::assertFalse(self::valid(['name' => 'test', 'entrypoint' => '9invalid']));
		self::assertFalse(self::valid(['name' => 'test', 'entrypoint' => 'Test', 'min_php' => []]));
		self::assertTrue(self::valid(['name' => 'test', 'entrypoint' => 'Test', 'version' => 1.2]));
	}

	public function testRequirementsAreOptInAndCheckedAgainstRuntime(): void {
		$method = new ReflectionMethod(Minz_ExtensionManager::class, 'compatibilityError');
		self::assertSame('', $method->invoke(null, ['name' => 'test', 'entrypoint' => 'Test']));
		self::assertSame('', $method->invoke(null, ['min_php' => '8.1', 'min_freshrss' => '1.0']));
		$error = $method->invoke(null, ['min_php' => '999.0']);
		self::assertIsString($error);
		self::assertStringContainsString('min_php', $error);
		$error = $method->invoke(null, ['max_freshrss' => '1.0']);
		self::assertIsString($error);
		self::assertStringContainsString('max_freshrss', $error);
	}

	public function testParseErrorInExtensionIsReportedWithoutAbortingDiscovery(): void {
		$path = sys_get_temp_dir() . '/freshrss-broken-extension-' . bin2hex(random_bytes(8));
		mkdir($path);
		file_put_contents($path . '/extension.php', '<?php this is not valid php;');
		try {
			$extension = (new ReflectionMethod(Minz_ExtensionManager::class, 'load'))->invoke(null,
				['name' => 'Broken', 'entrypoint' => 'BrokenFixture', 'path' => $path]);
			self::assertNull($extension);
			self::assertArrayHasKey('Broken', Minz_ExtensionManager::getLoadErrors());
		} finally {
			recursive_unlink($path);
		}
	}

	public function testFailedInitRollsBackHooksAndAutoloader(): void {
		$extension = new class (['name' => 'Failing', 'entrypoint' => 'Failing', 'path' => __DIR__]) extends Minz_Extension {
			public function autoload(string $class): void {}
			#[\Override]
			public function init(): void {
				$this->registerHook(Minz_HookType::EntriesRead, static function (): void {
					throw new RuntimeException('This hook should have been removed');
				});
				throw new TypeError('Incompatible plugin');
			}
		};
		(new ReflectionMethod(Minz_ExtensionManager::class, 'register'))->invoke(null, $extension);
		$autoloaders = spl_autoload_functions();
		Minz_ExtensionManager::enableByList(['Failing' => true]);
		self::assertFalse($extension->isEnabled());
		self::assertSame($autoloaders, spl_autoload_functions());
		self::assertArrayHasKey('Failing', Minz_ExtensionManager::getLoadErrors());
		Minz_ExtensionManager::callHook(Minz_HookType::EntriesRead, ['1'], true);
	}

	public function testResetUnregistersAutoloadersAndEnableIsIdempotent(): void {
		$extension = new class (['name' => 'Working', 'entrypoint' => 'Working', 'path' => __DIR__]) extends Minz_Extension {
			public int $initialisations = 0;
			public function autoload(string $class): void {}
			#[\Override]
			public function init(): void {
				$this->initialisations++; }
		};
		(new ReflectionMethod(Minz_ExtensionManager::class, 'register'))->invoke(null, $extension);
		$autoloaders = spl_autoload_functions();
		Minz_ExtensionManager::enableByList(['Working' => true]);
		Minz_ExtensionManager::enableByList(['Working' => true]);
		self::assertSame(1, $extension->initialisations);
		self::assertCount(count($autoloaders) + 1, spl_autoload_functions());
		self::resetManager();
		self::assertSame($autoloaders, spl_autoload_functions());
		self::assertFalse($extension->isEnabled());
	}

	public function testHookPriorityCacheIsInvalidatedOnRegistration(): void {
		$called = [];
		Minz_ExtensionManager::addHook(Minz_HookType::EntriesRead, static function () use (&$called): void { $called[] = 10; }, 10);
		Minz_ExtensionManager::callHook(Minz_HookType::EntriesRead, [], true);
		Minz_ExtensionManager::addHook(Minz_HookType::EntriesRead, static function () use (&$called): void { $called[] = 1; }, 1);
		Minz_ExtensionManager::callHook(Minz_HookType::EntriesRead, [], true);
		self::assertSame([10, 1, 10], $called);
	}
	public function testDuplicateEntrypointIsRejectedBeforeIncludingTheSecondFile(): void {
		$path = sys_get_temp_dir() . '/freshrss-duplicate-' . bin2hex(random_bytes(8));
		mkdir($path . '/first', 0700, true);
		mkdir($path . '/second');
		$entrypoint = 'Collision' . bin2hex(random_bytes(8));
		$code = '<?php class ' . $entrypoint . 'Extension extends Minz_Extension {}';
		file_put_contents($path . '/first/extension.php', $code);
		file_put_contents($path . '/second/extension.php', $code);
		try {
			$load = new ReflectionMethod(Minz_ExtensionManager::class, 'load');
			self::assertInstanceOf(Minz_Extension::class, $load->invoke(null,
				['name' => 'First', 'entrypoint' => $entrypoint, 'path' => $path . '/first']));
			self::assertNull($load->invoke(null,
				['name' => 'Second', 'entrypoint' => $entrypoint, 'path' => $path . '/second']));
			self::assertStringContainsString('Duplicate entrypoint', Minz_ExtensionManager::getLoadErrors()['Second']);
		} finally {
			recursive_unlink($path);
		}
	}

	public function testConfigurationAssetsRequireExplicitOptIn(): void {
		$path = sys_get_temp_dir() . '/freshrss-configure-' . bin2hex(random_bytes(8));
		mkdir($path . '/static', 0700, true);
		file_put_contents($path . '/configure.phtml', '<form>Settings</form>');
		file_put_contents($path . '/static/configure.js', '// settings');
		file_put_contents($path . '/static/configure.css', 'form { color: red; }');
		try {
			foreach ([false, true] as $optIn) {
				$extension = new class (['name' => 'Assets', 'entrypoint' => 'Assets', 'path' => $path,
					'configure_assets' => $optIn]) extends Minz_Extension {};
				$html = $extension->getConfigureView();
				self::assertIsString($html);
				self::assertSame($optIn, str_contains($html, 'data-extension-configure'));
				self::assertSame($optIn, str_contains($html, 'rel="stylesheet"'));
			}
		} finally {
			recursive_unlink($path);
		}
	}

	/** @param array{version?:string,type?:'system'|'user',requires?:array<string,string>,conflicts?:list<string>,capabilities?:list<string>} $metadata */
	private static function registerFixture(string $name, array $metadata = []): Minz_Extension {
		$extension = new class (array_merge(['name' => $name, 'entrypoint' => $name, 'path' => __DIR__], $metadata)) extends Minz_Extension {
			#[\Override]
			public function init(): void {}
		};
		(new ReflectionMethod(Minz_ExtensionManager::class, 'register'))->invoke(null, $extension);
		return $extension;
	}

	public function testDependencyMetadataRejectsAmbiguousConstraints(): void {
		$metadata = ['name' => 'Test', 'entrypoint' => 'Test'];
		self::assertFalse(self::valid($metadata + ['requires' => ['Dependency' => '^1.0']]));
		self::assertFalse(self::valid($metadata + ['conflicts' => 'Other']));
		self::assertFalse(self::valid($metadata + ['capabilities' => [42]]));
		self::assertTrue(self::valid($metadata + ['requires' => ['Dependency' => '1.2.0'], 'capabilities' => ['network']]));
	}

	public function testDependenciesMustBeExplicitlyEnabled(): void {
		$dependency = self::registerFixture('Dependency', ['version' => '2.0']);
		$extension = self::registerFixture('Consumer', ['requires' => ['Dependency' => '1.5']]);
		Minz_ExtensionManager::enableByList(['Consumer' => true]);
		self::assertFalse($extension->isEnabled());
		self::assertFalse($dependency->isEnabled());
		self::assertStringContainsString('disabled', Minz_ExtensionManager::getLoadErrors()['Consumer']);
		Minz_ExtensionManager::enableByList(['Consumer' => true, 'Dependency' => true]);
		self::assertTrue($extension->isEnabled());
		self::assertTrue($dependency->isEnabled());
		self::assertArrayNotHasKey('Consumer', Minz_ExtensionManager::getLoadErrors());
		self::assertSame(['Consumer'], Minz_ExtensionManager::getDependants('Dependency'));
	}

	public function testMissingAndOutdatedDependenciesHaveActionableErrors(): void {
		self::registerFixture('Consumer', ['requires' => ['Dependency' => '2.0']]);
		self::assertSame('Missing dependency: Dependency', Minz_ExtensionManager::getActivationError('Consumer'));
		self::registerFixture('Dependency', ['version' => '1.0']);
		self::assertSame('Dependency requires version 2.0: Dependency', Minz_ExtensionManager::getActivationError('Consumer'));
	}

	public function testCyclicDependenciesNeverBecomeEnabled(): void {
		$a = self::registerFixture('A', ['requires' => ['B' => '*']]);
		$b = self::registerFixture('B', ['requires' => ['A' => '*']]);
		Minz_ExtensionManager::enableByList(['A' => true, 'B' => true]);
		self::assertFalse($a->isEnabled());
		self::assertFalse($b->isEnabled());
		self::assertCount(2, Minz_ExtensionManager::getLoadErrors());
		self::assertContains('Cyclic extension dependency', Minz_ExtensionManager::getLoadErrors());
	}

	public function testConflictIsSymmetricAndIndependentOfEnableOrder(): void {
		$a = self::registerFixture('A', ['conflicts' => ['B']]);
		$b = self::registerFixture('B');
		Minz_ExtensionManager::enableByList(['B' => true, 'A' => true]);
		self::assertFalse($a->isEnabled());
		self::assertFalse($b->isEnabled());
		Minz_ExtensionManager::enableByList(['A' => true]);
		self::assertTrue($a->isEnabled());
		self::assertSame('Conflicting extension: A', Minz_ExtensionManager::getActivationError('B'));
	}

	public function testSystemDependencyCannotRequireUserScopedExtension(): void {
		self::registerFixture('UserPlugin');
		self::registerFixture('SystemPlugin', ['type' => 'system', 'requires' => ['UserPlugin' => '*']]);
		self::assertStringContainsString('System extension requires user extension',
			Minz_ExtensionManager::getActivationError('SystemPlugin', ['UserPlugin' => true]));
	}

	public function testPresentationFailureIsIsolatedAndDiagnosticsAreBounded(): void {
		Minz_ExtensionManager::observeHooks(true);
		Minz_ExtensionManager::addHook(Minz_HookType::NavMenu, static function (): never {
			throw new RuntimeException(str_repeat('x', 2000));
		});
		Minz_ExtensionManager::addHook(Minz_HookType::NavMenu, static fn(): string => '<li>Working</li>');
		for ($i = 0; $i < 3; $i++) {
			self::assertSame('<li>Working</li>', Minz_ExtensionManager::callHookString(Minz_HookType::NavMenu));
		}
		$metrics = Minz_ExtensionManager::getHookMetrics();
		self::assertCount(1, $metrics);
		self::assertSame(6, $metrics['nav_menu']['calls']);
		self::assertSame(3, $metrics['nav_menu']['failures']);
		self::assertGreaterThanOrEqual(0, $metrics['nav_menu']['duration_ns']);
		self::assertSame(1024, strlen(Minz_ExtensionManager::getHookErrors()['nav_menu']));
		Minz_ExtensionManager::observeHooks(false);
		self::assertSame([], Minz_ExtensionManager::getHookMetrics());
	}

	public function testMutatingHookFailuresStillPropagate(): void {
		Minz_ExtensionManager::addHook(Minz_HookType::EntryBeforeInsert, static function (): never {
			throw new RuntimeException('Insert blocked');
		});
		$this->expectExceptionMessage('Insert blocked');
		Minz_ExtensionManager::callHook(Minz_HookType::EntryBeforeInsert, new stdClass());
	}

	public function testMetricsAreDisabledByDefaultAndCapabilitiesRemainInformational(): void {
		$extension = self::registerFixture('Capabilities', ['capabilities' => ['network', 'entry-filter']]);
		self::assertSame(['network', 'entry-filter'], $extension->getCapabilities());
		Minz_ExtensionManager::addHook(Minz_HookType::NavMenu, static fn(): string => 'ok');
		Minz_ExtensionManager::callHookString(Minz_HookType::NavMenu);
		self::assertSame([], Minz_ExtensionManager::getHookMetrics());
	}

	public function testExtensionFilesRejectTraversalAndControlCharacters(): void {
		$method = new ReflectionMethod(Minz_Extension::class, 'isSafeFilename');
		foreach (['../secret', '/etc/passwd', 'sub/../secret', 'sub/./secret', "line\nfeed", 'sub\\secret'] as $filename) {
			self::assertFalse($method->invoke(null, $filename), $filename);
		}
		self::assertTrue($method->invoke(null, 'settings.json'));
		self::assertTrue($method->invoke(null, 'nested/settings.json'));
	}

	public function testNestedFilesWorkAndExternalSymlinkTargetsAreRejected(): void {
		$entrypoint = 'StorageFixture' . bin2hex(random_bytes(8));
		$extension = new class (['name' => $entrypoint, 'entrypoint' => $entrypoint, 'path' => __DIR__]) extends Minz_Extension {
			public function storagePath(): string {
				return $this->getExtensionUserPath(); }
			/** @throws Minz_ExtensionException */
			public function store(string $name, string $value): void {
				$this->saveFile($name, $value); }
		};
		$path = $extension->storagePath();
		$createdParents = [];
		for ($parent = dirname($path); !is_dir($parent); $parent = dirname($parent)) {
			$createdParents[] = $parent;
		}
		$outside = tempnam(sys_get_temp_dir(), 'freshrss-extension-');
		self::assertIsString($outside);
		mkdir($path . '/nested', 0700, true);
		try {
			$extension->store('nested/content.txt', 'inside');
			self::assertSame('inside', $extension->getFile('nested/content.txt'));
			self::assertTrue($extension->hasFile('nested/content.txt'));
			if (PHP_OS_FAMILY !== 'Windows') {
				file_put_contents($outside, 'outside');
				symlink($outside, $path . '/link.txt');
				self::assertFalse($extension->hasFile('link.txt'));
				self::assertNull($extension->getFile('link.txt'));
				try {
					$extension->store('link.txt', 'changed');
					self::fail('Writing through a symlink must fail');
				} catch (Minz_ExtensionException) {
					self::assertSame('outside', file_get_contents($outside));
				}
			}
		} finally {
			recursive_unlink($path);
			unlink($outside);
			foreach ($createdParents as $parent) {
				rmdir($parent);
			}
		}
	}

	public function testConfigurationViewFailureDoesNotLeakItsOutputBuffer(): void {
		$path = sys_get_temp_dir() . '/freshrss-configure-error-' . bin2hex(random_bytes(8));
		mkdir($path);
		file_put_contents($path . '/configure.phtml', '<?php echo "partial"; throw new RuntimeException("Invalid view");');
		$extension = new class (['name' => 'View', 'entrypoint' => 'View', 'path' => $path]) extends Minz_Extension {};
		$level = ob_get_level();
		try {
			try {
				$extension->getConfigureView();
				self::fail('The original template error must propagate');
			} catch (RuntimeException $error) {
				self::assertSame('Invalid view', $error->getMessage());
				self::assertSame($level, ob_get_level());
			}
		} finally {
			recursive_unlink($path);
		}
	}

	public function testTypeFilteredEnableIgnoresUnselectedScopes(): void {
		$system = self::registerFixture('SystemPlugin', ['type' => 'system', 'conflicts' => ['UserPlugin']]);
		$user = self::registerFixture('UserPlugin');
		Minz_ExtensionManager::enableByList(['SystemPlugin' => true, 'UserPlugin' => true], 'system');
		self::assertTrue($system->isEnabled());
		self::assertFalse($user->isEnabled());
	}

	public function testInstallationSuccessCannotHideInitialisationFailure(): void {
		$extension = new class (['name' => 'InstallThenFail', 'entrypoint' => 'InstallThenFail', 'path' => __DIR__]) extends Minz_Extension {
			public int $installs = 0;
			#[\Override]
			public function install(): bool|string {
				$this->installs++;
				$this->registerHook(Minz_HookType::NavMenu, static fn(): string => 'must not survive');
				return true;
			}
			#[\Override]
			public function init(): void {
				throw new RuntimeException('Initialisation failed');
			}
		};
		(new ReflectionMethod(Minz_ExtensionManager::class, 'register'))->invoke(null, $extension);
		self::assertSame('Initialisation failed', Minz_ExtensionManager::installAndEnable($extension->getName()));
		self::assertFalse($extension->isEnabled());
		self::assertSame(1, $extension->installs);
		self::assertSame('', Minz_ExtensionManager::callHookString(Minz_HookType::NavMenu));
	}

	public function testInstallationFailureRestoresHooksAndExistingExtensionsKeepWorking(): void {
		Minz_ExtensionManager::addHook(Minz_HookType::NavMenu, static fn(): string => 'existing');
		$extension = new class (['name' => 'InstallFail', 'entrypoint' => 'InstallFail', 'path' => __DIR__]) extends Minz_Extension {
			#[\Override]
			public function install(): bool|string {
				$this->registerHook(Minz_HookType::NavMenu, static fn(): string => 'failed');
				throw new TypeError('Install failed');
			}
		};
		(new ReflectionMethod(Minz_ExtensionManager::class, 'register'))->invoke(null, $extension);
		self::assertSame('Install failed', Minz_ExtensionManager::installAndEnable($extension->getName()));
		self::assertSame('existing', Minz_ExtensionManager::callHookString(Minz_HookType::NavMenu));
		self::assertFalse($extension->isEnabled());
	}

	public function testDependencyPreflightRunsBeforeInstallationSideEffects(): void {
		$extension = new class (['name' => 'Preflight', 'entrypoint' => 'Preflight', 'path' => __DIR__,
			'requires' => ['Missing' => '*']]) extends Minz_Extension {
			public bool $installed = false;
			#[\Override]
			public function install(): bool|string {
				$this->installed = true;
				return true;
			}
		};
		(new ReflectionMethod(Minz_ExtensionManager::class, 'register'))->invoke(null, $extension);
		self::assertSame('Missing dependency: Missing', Minz_ExtensionManager::installAndEnable($extension->getName()));
		self::assertFalse($extension->installed);
	}

	public function testSuccessfulInstallIsInitialisedAndRepeatedEnableIsIdempotent(): void {
		$extension = self::registerFixture('InstallSuccess');
		self::assertTrue(Minz_ExtensionManager::installAndEnable($extension->getName()));
		self::assertTrue($extension->isEnabled());
		self::assertTrue(Minz_ExtensionManager::installAndEnable($extension->getName()));
	}

}
