<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Extensions;

use Closure;
use FreshRSS\Services\Extensions\ConfigurationService as ExtensionsConfigurationService;
use FreshRSS_Context;
use FreshRSS_UserConfiguration;
use FreshRSS_View;
use LogicException;
use Minz_Configuration;
use Minz_Extension;
use Minz_Translate;
use Minz_Url;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/** @phpstan-import-type SettingsSchema from ExtensionsConfigurationService */
final class ConfigurationServiceTest extends TestCase {
	private string $directory;
	private FreshRSS_UserConfiguration $configuration;
	private ?FreshRSS_UserConfiguration $previousUser;
	private Closure $restoreRegistry;

	#[\Override]
	protected function setUp(): void {
		$this->previousUser = FreshRSS_Context::hasUserConf() ? FreshRSS_Context::userConf() : null;
		$registry = (new ReflectionProperty(Minz_Configuration::class, 'config_list'))->getValue();
		self::assertIsArray($registry);
		$this->restoreRegistry = static fn() => (new ReflectionProperty(Minz_Configuration::class, 'config_list'))->setValue(null, $registry);
		$this->directory = sys_get_temp_dir() . '/freshrss-extension-settings-' . bin2hex(random_bytes(8));
		mkdir($this->directory);
		file_put_contents($this->directory . '/system.php',
			'<?php return ["title"=>"Settings fixture","log_level"=>"","salt"=>"test-only-extension-settings-salt"];');
		Minz_Configuration::register('system', $this->directory . '/system.php');
		file_put_contents($this->directory . '/config.php', '<?php return ["extensions"=>[]];');
		$this->configuration = FreshRSS_UserConfiguration::init($this->directory . '/config.php');
		FreshRSS_Context::setUserConf($this->configuration);
	}

	#[\Override]
	protected function tearDown(): void {
		FreshRSS_Context::setUserConf($this->previousUser);
		($this->restoreRegistry)();
		recursive_unlink($this->directory);
	}

	/** @param SettingsSchema $schema */
	private function extension(array $schema = []): Minz_Extension {
		$extension = new ExtensionConfigurationFixture(['name' => 'Settings fixture', 'entrypoint' => 'SettingsFixture', 'path' => __DIR__]);
		$extension->schema = $schema;
		return $extension;
	}

	public function testStrictTypesRangesAndUnknownFieldsProduceErrorsWithoutWrites(): void {
		$extension = $this->extension([
			'count' => ['type' => 'integer', 'default' => 4, 'min' => 1, 'max' => 10],
			'enabled' => ['type' => 'boolean', 'default' => false],
			'name' => ['type' => 'string', 'default' => 'reader', 'maxLength' => 10],
		]);
		$before = file_get_contents($this->directory . '/config.php');
		$result = ExtensionsConfigurationService::save($extension, ['count' => '5', 'enabled' => 1, 'name' => str_repeat('x', 11), 'extra' => true]);
		self::assertFalse($result['saved']);
		self::assertSame(['count', 'enabled', 'name', 'extra'], array_keys($result['errors']));
		self::assertSame($before, file_get_contents($this->directory . '/config.php'));
		self::assertSame([], $extension->getSettingsConfiguration());
		self::assertFalse(ExtensionsConfigurationService::save($extension, ['count' => 11])['saved']);
	}

	public function testDefaultsAndSemanticValidationAreAppliedBeforeSaving(): void {
		$extension = $this->extension(['name' => ['type' => 'string', 'default' => 'default'], 'count' => ['type' => 'integer', 'default' => 0]]);
		self::assertSame(['saved' => false, 'errors' => ['name' => 'Name is reserved']],
			ExtensionsConfigurationService::save($extension, ['name' => 'reserved']));
		self::assertTrue(ExtensionsConfigurationService::save($extension, ['name' => 'accepted'])['saved']);
		self::assertSame(['name' => 'accepted', 'count' => 0], $extension->getSettingsConfiguration());
		$stored = Minz_Configuration::load($this->directory . '/config.php');
		self::assertSame($this->configuration->extensions, $stored['extensions']);
	}

	public function testSecretValuesAndDefaultsAreAbsentFromDescriptionsAndExports(): void {
		$extension = $this->extension(['token' => ['type' => 'string', 'secret' => true, 'default' => 'default-secret'],
			'name' => ['type' => 'string', 'default' => 'public']]);
		self::assertTrue(ExtensionsConfigurationService::save($extension, ['token' => 'stored-secret'])['saved']);
		$description = ExtensionsConfigurationService::describe($extension);
		self::assertSame(['token'], $description['redacted']);
		self::assertSame(['name' => 'public'], $description['values']);
		self::assertArrayNotHasKey('default', $description['schema']['token']);
		self::assertStringNotContainsString('stored-secret', json_encode(ExtensionsConfigurationService::export($extension), JSON_THROW_ON_ERROR));
		self::assertStringNotContainsString('default-secret', json_encode($description, JSON_THROW_ON_ERROR));
	}

	public function testPartialUpdatesAndRedactedRoundTripsPreserveSecretsAndLegacyFields(): void {
		$extension = $this->extension(['token' => ['type' => 'string', 'secret' => true], 'name' => ['type' => 'string', 'default' => 'first']]);
		$this->configuration->extensions = ['Settings fixture' => ['legacy' => ['keep' => true], 'token' => 'secret']];
		self::assertTrue($this->configuration->save());
		$export = ExtensionsConfigurationService::export($extension);
		$export['configuration']['name'] = 'second';
		self::assertTrue(ExtensionsConfigurationService::import($extension, json_encode($export, JSON_THROW_ON_ERROR))['saved']);
		self::assertSame(['legacy' => ['keep' => true], 'token' => 'secret', 'name' => 'second'], $extension->getSettingsConfiguration());
		self::assertArrayNotHasKey('legacy', ExtensionsConfigurationService::describe($extension)['values']);
	}

	public function testImportRejectsWrongExtensionVersionAndOversizedDocuments(): void {
		$extension = $this->extension(['enabled' => ['type' => 'boolean', 'default' => false]]);
		foreach (['{', '{"version":2,"extension":"SettingsFixture","configuration":{}}',
			'{"version":1,"extension":"Other","configuration":{}}',
			'{"version":1,"extension":"SettingsFixture","configuration":{},"unknown":true}'] as $json) {
			self::assertFalse(ExtensionsConfigurationService::import($extension, $json)['saved']);
		}
		self::assertFalse(ExtensionsConfigurationService::import($extension, '{}', 1)['saved']);
		self::assertSame([], $extension->getSettingsConfiguration());
	}

	public function testSchemaRejectsInvalidDefaultsRangesAndInapplicableConstraints(): void {
		foreach ([['type' => 'integer', 'default' => '1'], ['type' => 'integer', 'min' => 2, 'max' => 1],
			['type' => 'string', 'min' => 1], ['type' => 'string', 'enum' => [false]], ['type' => 'boolean', 'unknown' => true]] as $spec) {
			self::assertArrayHasKey('setting', ExtensionsConfigurationService::schemaErrors(['setting' => $spec]));
		}
	}

	public function testCollectionsAndNullableFieldsHaveExplicitTypesAndBounds(): void {
		$schema = ['list' => ['type' => 'array', 'maxItems' => 2], 'map' => ['type' => 'object', 'maxBytes' => 20],
			'optional' => ['type' => 'string', 'nullable' => true], 'required' => ['type' => 'boolean', 'required' => true]];
		$errors = ExtensionsConfigurationService::validate($schema, ['list' => ['key' => 'x'], 'map' => ['a', 'b'], 'optional' => null]);
		self::assertSame(['list', 'map', 'required'], array_keys($errors));
		self::assertSame([], ExtensionsConfigurationService::validate($schema,
			['list' => [1, 2], 'map' => ['x' => 'y'], 'optional' => null, 'required' => false]));
		self::assertArrayHasKey('list', ExtensionsConfigurationService::validate($schema, ['list' => [1, 2, 3], 'required' => true]));
		self::assertArrayHasKey('map', ExtensionsConfigurationService::validate($schema, ['map' => ['x' => str_repeat('y', 30)], 'required' => true]));
	}

	public function testFailedSaveRestoresInMemoryConfigurationAndCachedValues(): void {
		$extension = $this->extension(['name' => ['type' => 'string', 'default' => 'before']]);
		self::assertTrue(ExtensionsConfigurationService::save($extension, [])['saved']);
		self::assertSame('before', $extension->getUserConfigurationString('name'));
		$before = file_get_contents($this->directory . '/config.php');
		(new ReflectionProperty(Minz_Configuration::class, 'config_filename'))->setValue($this->configuration, $this->directory . '/missing/config.php');
		set_error_handler(static fn(): bool => true);
		try {
			try {
				ExtensionsConfigurationService::save($extension, ['name' => 'after']);
				self::fail('A failed write must be reported');
			} catch (RuntimeException $error) {
				self::assertSame('Extension configuration could not be saved', $error->getMessage());
			}
		} finally {
			restore_error_handler();
		}
		self::assertSame('before', $extension->getUserConfigurationString('name'));
		self::assertSame(['name' => 'before'], $extension->getSettingsConfiguration());
		self::assertSame($before, file_get_contents($this->directory . '/config.php'));
	}

	public function testSystemExtensionCannotUseUserSettingsService(): void {
		$extension = new class (['name' => 'System', 'entrypoint' => 'System', 'type' => 'system', 'path' => __DIR__]) extends Minz_Extension {};
		$this->expectException(LogicException::class);
		ExtensionsConfigurationService::describe($extension);
	}

	public function testExtensionsWithoutSchemaRemainUnsupportedWithoutChangingTheirValues(): void {
		$extension = $this->extension();
		self::assertFalse(ExtensionsConfigurationService::save($extension, ['name' => 'unused'])['saved']);
		self::assertSame([], ExtensionsConfigurationService::describe($extension)['schema']);
		self::assertSame([], $extension->getSettingsConfiguration());
	}
	public function testTypedGettersFollowCurrentUserAndDoNotExposePreviousUserSecrets(): void {
		$extension = $this->extension(['token' => ['type' => 'string', 'secret' => true]]);
		$this->configuration->extensions = ['Settings fixture' => ['token' => 'first-user-secret']];
		self::assertSame('first-user-secret', $extension->getUserConfigurationString('token'));
		file_put_contents($this->directory . '/second.php', '<?php return ["extensions"=>["Settings fixture"=>["token"=>"second-user-secret"]]];');
		$second = FreshRSS_UserConfiguration::init($this->directory . '/second.php');
		FreshRSS_Context::setUserConf($second);
		self::assertSame('second-user-secret', $extension->getUserConfigurationString('token'));
		FreshRSS_Context::setUserConf(null);
		self::assertNull($extension->getUserConfigurationString('token'));
	}

	public function testTypedGettersAndSettersDoNotOverwriteChangesFromAnotherExtensionInstance(): void {
		$first = $this->extension(['name' => ['type' => 'string'], 'count' => ['type' => 'integer']]);
		$second = $this->extension(['name' => ['type' => 'string'], 'count' => ['type' => 'integer']]);
		$this->configuration->extensions = ['Settings fixture' => ['name' => 'initial', 'count' => 1]];
		self::assertTrue($this->configuration->save());
		self::assertSame('initial', $first->getUserConfigurationString('name'));
		self::assertTrue(ExtensionsConfigurationService::save($second, ['name' => 'changed'])['saved']);
		self::assertSame('changed', $first->getUserConfigurationString('name'));
		self::assertTrue(ExtensionsConfigurationService::save($first, ['count' => 2])['saved']);
		self::assertSame(['name' => 'changed', 'count' => 2], $second->getSettingsConfiguration());
	}

	public function testStaleVersionReturnsCurrentRedactedConfigurationWithoutOverwriting(): void {
		$extension = $this->extension(['name' => ['type' => 'string', 'default' => 'initial'], 'token' => ['type' => 'string', 'secret' => true]]);
		$version = ExtensionsConfigurationService::describe($extension)['version'];
		self::assertTrue(ExtensionsConfigurationService::save($extension, ['name' => 'newer', 'token' => 'private'], $version)['saved']);
		$result = ExtensionsConfigurationService::save($extension, ['name' => 'stale'], $version);
		self::assertFalse($result['saved']);
		self::assertArrayHasKey('conflict', $result);
		self::assertSame(['name' => 'newer'], $result['conflict']['values']);
		self::assertSame(['token'], $result['conflict']['redacted']);
		self::assertSame('newer', $extension->getUserConfigurationString('name'));
	}

	public function testBatchValidationFailureDoesNotPartiallySaveEarlierExtensions(): void {
		$first = $this->extension(['name' => ['type' => 'string', 'default' => 'original']]);
		$second = new ExtensionConfigurationFixture(['name' => 'Second fixture', 'entrypoint' => 'SecondFixture', 'path' => __DIR__]);
		$second->schema = ['count' => ['type' => 'integer', 'default' => 1]];
		$before = file_get_contents($this->directory . '/config.php');
		$result = ExtensionsConfigurationService::saveBatch([
			['extension' => $first, 'configuration' => ['name' => 'changed']],
			['extension' => $second, 'configuration' => ['count' => 'invalid']],
		]);
		self::assertFalse($result['saved']);
		self::assertArrayHasKey('SecondFixture', $result['errors']);
		self::assertSame($before, file_get_contents($this->directory . '/config.php'));
		self::assertSame([], $first->getSettingsConfiguration());
	}

	public function testBatchReloadsLatestFileAndPreservesOtherPluginAndUserSettings(): void {
		$first = $this->extension(['name' => ['type' => 'string', 'default' => 'first']]);
		$firstVersion = ExtensionsConfigurationService::describe($first)['version'];
		// Simulate another request that writes after this configuration object was loaded.
		file_put_contents($this->directory . '/config.php', '<?php return ["language"=>"zh-CN","extensions"=>["Other"=>["token"=>"keep"]]];');
		self::assertTrue(ExtensionsConfigurationService::save($first, ['name' => 'updated'], $firstVersion)['saved']);
		self::assertSame('zh-CN', $this->configuration->language);
		self::assertSame(['token' => 'keep'], $this->configuration->extensions['Other']);
		self::assertSame('updated', $first->getUserConfigurationString('name'));
	}

	public function testSuccessfulBatchPublishesBothExtensionsWithSeparateVersions(): void {
		$first = $this->extension(['name' => ['type' => 'string']]);
		$second = new ExtensionConfigurationFixture(['name' => 'Second fixture', 'entrypoint' => 'SecondFixture', 'path' => __DIR__]);
		$second->schema = ['count' => ['type' => 'integer']];
		$result = ExtensionsConfigurationService::saveBatch([
			['extension' => $first, 'configuration' => ['name' => 'first']],
			['extension' => $second, 'configuration' => ['count' => 2]],
		]);
		self::assertTrue($result['saved']);
		self::assertSame(['SettingsFixture', 'SecondFixture'], array_keys($result['extensions']));
		self::assertSame(['name' => 'first'], $first->getSettingsConfiguration());
		self::assertSame(['count' => 2], $second->getSettingsConfiguration());
		self::assertNotSame($result['extensions']['SettingsFixture']['version'], $result['extensions']['SecondFixture']['version']);
	}

	public function testGenericFormDecodesDeclaredTypesAndPreservesBlankSecrets(): void {
		$schema = ['secret' => ['type' => 'string', 'secret' => true], 'count' => ['type' => 'integer'],
			'enabled' => ['type' => 'boolean'], 'mode' => ['type' => 'string', 'enum' => ['a', 'b']],
			'map' => ['type' => 'object'], 'optional' => ['type' => 'integer'], 'nullable' => ['type' => 'integer', 'nullable' => true]];
		$result = ExtensionsConfigurationService::formValues($schema,
			['secret' => '', 'count' => '5', 'enabled' => 'false', 'mode' => '"b"', 'map' => '{"a":1}', 'optional' => '', 'nullable' => '']);
		self::assertSame([], $result['errors']);
		self::assertSame(['count' => 5, 'enabled' => false, 'mode' => 'b', 'map' => ['a' => 1], 'nullable' => null], $result['configuration']);
		$invalid = ExtensionsConfigurationService::formValues($schema,
			['count' => '5.5', 'enabled' => 'yes', 'map' => '{bad', 'unknown' => 'ignored?']);
		self::assertSame(['count', 'enabled', 'map', 'unknown'], array_keys($invalid['errors']));
	}

	public function testGenericFormEscapesValuesAndNeverRendersSavedOrSubmittedSecrets(): void {
		$extension = $this->extension(['name' => ['type' => 'string', 'label' => '<b>Name</b>', 'default' => '</textarea><script>bad()</script>'],
			'token' => ['type' => 'string', 'secret' => true, 'default' => 'default-secret'],
			'count' => ['type' => 'integer', 'default' => 3, 'min' => 1, 'max' => 9]]);
		$this->configuration->extensions = ['Settings fixture' => ['token' => 'stored-secret']];
		class_exists(Minz_Url::class);
		class_exists(Minz_Translate::class);
		$level = ob_get_level();
		$session = $_SESSION ?? [];
		$_SESSION['csrf'] = 'test-settings-csrf';
		try {
			$view = new FreshRSS_View();
			$view->extension = $extension;
			$view->extensionSettingsSubmitted = ['token' => 'submitted-secret'];
			$html = $view->helperToString('extension/settings');
			self::assertStringContainsString('settings_version', $html);
			self::assertStringContainsString('test-settings-csrf', $html);
			self::assertStringContainsString('&lt;b&gt;Name&lt;/b&gt;', $html);
			self::assertStringContainsString('&lt;/textarea&gt;&lt;script&gt;', $html);
			self::assertStringNotContainsString('stored-secret', $html);
			self::assertStringNotContainsString('submitted-secret', $html);
			self::assertStringNotContainsString('default-secret', $html);
			self::assertStringContainsString('min="1" max="9"', $html);
		} finally {
			while (ob_get_level() > $level) {
				ob_end_clean();
			}
			$_SESSION = $session;
		}
	}

	public function testOneVersionConflictPreventsAllOtherBatchWrites(): void {
		$first = $this->extension(['name' => ['type' => 'string', 'default' => 'first']]);
		$second = new ExtensionConfigurationFixture(['name' => 'Second fixture', 'entrypoint' => 'SecondFixture', 'path' => __DIR__]);
		$second->schema = ['count' => ['type' => 'integer', 'default' => 1]];
		$result = ExtensionsConfigurationService::saveBatch([
			['extension' => $first, 'configuration' => ['name' => 'must-not-write']],
			['extension' => $second, 'configuration' => ['count' => 2], 'expectedVersion' => 'stale'],
		]);
		self::assertFalse($result['saved']);
		self::assertSame(['SecondFixture'], array_keys($result['conflicts']));
		self::assertSame([], $first->getSettingsConfiguration());
		self::assertSame([], $second->getSettingsConfiguration());
	}

	public function testGenericPasswordControlUsesTheDeclaredSecretTypeWithoutPublishingOptions(): void {
		$schema = ['token' => ['type' => 'string', 'secret' => true, 'enum' => ['one', 'two']]];
		$form = ExtensionsConfigurationService::formValues($schema, ['token' => 'two']);
		self::assertSame([], $form['errors']);
		self::assertSame(['token' => 'two'], $form['configuration']);
		$extension = $this->extension($schema);
		self::assertTrue(ExtensionsConfigurationService::save($extension, $form['configuration'])['saved']);
		self::assertArrayNotHasKey('enum', ExtensionsConfigurationService::describe($extension)['schema']['token']);
	}

}

/** @phpstan-import-type SettingsSchema from ExtensionsConfigurationService */
final class ExtensionConfigurationFixture extends Minz_Extension {
	/** @var SettingsSchema */
	public array $schema = [];

	#[\Override]
	public function getConfigurationSchema(): array {
		return $this->schema;
	}

	#[\Override]
	public function validateConfiguration(array $configuration): array {
		return ($configuration['name'] ?? '') === 'reserved' ? ['name' => 'Name is reserved'] : [];
	}
}
