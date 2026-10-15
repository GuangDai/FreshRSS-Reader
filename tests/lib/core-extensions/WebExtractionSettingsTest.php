<?php
declare(strict_types=1);

use FreshRSS\Services\Extensions\ConfigurationService as ExtensionsConfigurationService;

require_once LIB_PATH . '/core-extensions/WebExtraction/extension.php';

final class WebExtractionSettingsTest extends \PHPUnit\Framework\TestCase {
	private string $directory;
	private WebExtractionExtension $extension;
	private ?FreshRSS_UserConfiguration $previousUser;
	private Closure $restoreRegistry;
	/** @var array<string,mixed> */
	private array $previousParams;
	private mixed $previousMethod;

	#[\Override]
	protected function setUp(): void {
		$this->previousUser = FreshRSS_Context::hasUserConf() ? FreshRSS_Context::userConf() : null;
		$registry = (new ReflectionProperty(Minz_Configuration::class, 'config_list'))->getValue();
		self::assertIsArray($registry);
		$this->restoreRegistry = static fn() => (new ReflectionProperty(Minz_Configuration::class, 'config_list'))->setValue(null, $registry);
		$this->previousParams = Minz_Request::params();
		$this->previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
		$this->directory = sys_get_temp_dir() . '/freshrss-extraction-settings-' . bin2hex(random_bytes(8));
		mkdir($this->directory);
		file_put_contents($this->directory . '/system.php', '<?php return ["salt"=>"extraction-test-only-salt"];');
		Minz_Configuration::register('system', $this->directory . '/system.php');
		file_put_contents($this->directory . '/config.php', '<?php return ["extensions"=>[]];');
		FreshRSS_Context::setUserConf(FreshRSS_UserConfiguration::init($this->directory . '/config.php', FRESHRSS_PATH . '/config-user.default.php'));
		$this->extension = new WebExtractionExtension(['name' => 'Web Extraction', 'entrypoint' => 'WebExtraction',
			'path' => LIB_PATH . '/core-extensions/WebExtraction']);
	}

	#[\Override]
	protected function tearDown(): void {
		FreshRSS_Context::setUserConf($this->previousUser);
		($this->restoreRegistry)();
		Minz_Request::_params($this->previousParams);
		if ($this->previousMethod === null) {
			unset($_SERVER['REQUEST_METHOD']);
		} else {
			$_SERVER['REQUEST_METHOD'] = $this->previousMethod;
		}
		recursive_unlink($this->directory);
	}

	/** @return array<string,mixed> */
	private static function recipe(string $selector = 'h1'): array {
		return ['fields' => ['title' => [['type' => 'css', 'selector' => $selector]]]];
	}

	public function testBatchFeedRecipesAreValidatedBeforeAnySettingIsSaved(): void {
		$initial = [7 => self::recipe(), 8 => self::recipe('h2')];
		self::assertTrue(ExtensionsConfigurationService::save($this->extension, ['recipes' => $initial])['saved']);
		$before = file_get_contents($this->directory . '/config.php');
		$result = ExtensionsConfigurationService::save($this->extension, ['recipes' => [7 => self::recipe('h3'),
			8 => ['fields' => ['unsupported_field' => [['type' => 'css', 'selector' => 'h1']]]],
		]]);
		self::assertFalse($result['saved']);
		self::assertStringContainsString('Unsupported entry field: unsupported_field', $result['errors']['recipes']);
		self::assertSame($before, file_get_contents($this->directory . '/config.php'));
		self::assertSame($initial, $this->extension->getUserConfigurationArray('recipes'));
	}

	public function testSyntaxErrorsAndUnusableFeedIdsCannotEnterSavedConfiguration(): void {
		foreach ([['999999999999999999999999999999' => self::recipe()], [7 => self::recipe('p???')],
			[7 => ['fields' => ['title' => [['type' => 'xpath', 'selector' => 'string(//h1)']]]]],
			[7 => self::recipe() + ['remove' => ['div:not(']]]] as $recipes) {
			$result = ExtensionsConfigurationService::save($this->extension, ['recipes' => $recipes]);
			self::assertFalse($result['saved']);
			self::assertArrayHasKey('recipes', $result['errors']);
		}
		self::assertNull($this->extension->getUserConfigurationArray('recipes'));
	}

	public function testSchemaRejectsWrongTypesAndTooManyRecipes(): void {
		foreach ([null, 'invalid', array_fill(1, WebExtractionExtension::MAX_RECIPES + 1, self::recipe())] as $recipes) {
			self::assertFalse(ExtensionsConfigurationService::save($this->extension, ['recipes' => $recipes])['saved']);
		}
		self::assertFalse(ExtensionsConfigurationService::save($this->extension, ['unexpected_recipes' => []])['saved']);
		self::assertSame([], ExtensionsConfigurationService::schemaErrors($this->extension->getConfigurationSchema()));
	}

	public function testStaleRecipeWriteReturnsLatestValuesWithoutOverwritingThem(): void {
		$version = ExtensionsConfigurationService::describe($this->extension)['version'];
		$staleContext = clone FreshRSS_Context::userConf();
		$latest = [7 => self::recipe('h2'), 8 => self::recipe('h3')];
		self::assertTrue(ExtensionsConfigurationService::save($this->extension, ['recipes' => $latest], $version)['saved']);
		FreshRSS_Context::setUserConf($staleContext);
		$result = ExtensionsConfigurationService::save($this->extension, ['recipes' => [7 => self::recipe()]], $version);
		self::assertFalse($result['saved']);
		self::assertArrayHasKey('conflict', $result);
		self::assertSame($latest, $result['conflict']['values']['recipes']);
		$stored = Minz_Configuration::load($this->directory . '/config.php');
		self::assertSame(['Web Extraction' => ['recipes' => $latest]], $stored['extensions']);
	}

	public function testClassicFormConflictKeepsDraftAndUsesReturnedLatestVersion(): void {
		$version = ExtensionsConfigurationService::describe($this->extension)['version'];
		$latest = [7 => self::recipe('h2')];
		self::assertTrue(ExtensionsConfigurationService::save($this->extension, ['recipes' => $latest], $version)['saved']);
		$newVersion = ExtensionsConfigurationService::describe($this->extension)['version'];
		$draft = json_encode([8 => self::recipe()], JSON_THROW_ON_ERROR);
		$_SERVER['REQUEST_METHOD'] = 'POST';
		Minz_Request::_params(['configuration_version' => $version, 'recipes' => $draft, 'source' => '<h1>Draft source</h1>', 'feed_id' => '8']);
		$this->extension->handleConfigureAction();
		self::assertNotEmpty($this->extension->configurationErrors);
		self::assertSame($draft, $this->extension->configurationJson);
		self::assertSame('<h1>Draft source</h1>', $this->extension->previewSource);
		self::assertSame('8', $this->extension->previewFeed);
		self::assertSame($latest, json_decode($this->extension->latestConfigurationJson, true, flags: JSON_THROW_ON_ERROR));
		self::assertSame($newVersion, $this->extension->configurationVersion);
		self::assertSame($latest, $this->extension->getUserConfigurationArray('recipes'));
	}
}
