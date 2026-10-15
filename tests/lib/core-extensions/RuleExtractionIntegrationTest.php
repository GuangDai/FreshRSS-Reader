<?php
declare(strict_types=1);

require_once LIB_PATH . '/core-extensions/RuleEngine/extension.php';
require_once LIB_PATH . '/core-extensions/WebExtraction/extension.php';

final class RuleExtractionIntegrationTest extends \PHPUnit\Framework\TestCase {
	private ?FreshRSS_UserConfiguration $previousUser;
	private string $configurationPath;
	private Closure $restoreRegistry;
	private const SOURCE = '<time>not-a-date</time><h1> Release &amp; stable </h1><span class="author">Ada</span>'
		. '<a class="canonical" href="../articles/story?a=1&amp;b=2">Article</a><article><p>Body 1</p><p>Body 2</p></article>';

	#[\Override]
	public static function setUpBeforeClass(): void {
		FreshRSS_Context::initSystem();
	}

	#[\Override]
	protected function setUp(): void {
		self::resetManager();
		$this->previousUser = FreshRSS_Context::hasUserConf() ? FreshRSS_Context::userConf() : null;
		$registry = (new ReflectionProperty(Minz_Configuration::class, 'config_list'))->getValue();
		self::assertIsArray($registry);
		$this->restoreRegistry = static fn() => (new ReflectionProperty(Minz_Configuration::class, 'config_list'))->setValue(null, $registry);
		$this->configurationPath = sys_get_temp_dir() . '/freshrss-rule-extraction-' . bin2hex(random_bytes(8)) . '.php';
		file_put_contents($this->configurationPath, '<?php return ["extensions"=>[]];');
		FreshRSS_Context::setUserConf(FreshRSS_UserConfiguration::init($this->configurationPath, FRESHRSS_PATH . '/config-user.default.php'));
	}

	#[\Override]
	protected function tearDown(): void {
		self::resetManager();
		FreshRSS_Context::setUserConf($this->previousUser);
		($this->restoreRegistry)();
		unlink($this->configurationPath);
	}

	private static function resetManager(): void {
		(new ReflectionMethod(Minz_ExtensionManager::class, 'reset'))->invoke(null);
	}

	/** @return array<string,mixed> */
	private static function recipe(): array {
		return ['fields' => [
			'title' => [
				['type' => 'css', 'selector' => 'time', 'postprocess' => [['type' => 'date', 'input_format' => 'Y-m-d']]],
				['type' => 'css', 'selector' => 'h1', 'postprocess' => [['type' => 'normalize_whitespace']]],
			],
			'content' => [['type' => 'css', 'selector' => 'article > p', 'mode' => 'html', 'all' => true, 'unique' => true]],
			'author' => [['type' => 'css', 'selector' => '.author']],
			'link' => [['type' => 'css', 'selector' => 'a.canonical', 'mode' => 'attribute', 'attribute' => 'href',
				'postprocess' => [['type' => 'absolute_url']]]],
		], 'required' => ['title', 'content']];
	}

	private static function rules(int $feedId = 7, string $title = 'Release & stable'): string {
		return json_encode(['version' => 1, 'rules' => [[
			'id' => 'integrated', 'scope' => ['feed_ids' => [$feedId]],
			'when' => ['all' => [
				['field' => 'title', 'op' => 'equals', 'value' => $title],
				['field' => 'author', 'op' => 'equals', 'value' => 'Ada'],
				['field' => 'url', 'op' => 'equals', 'value' => 'https://example.test/articles/story?a=1&b=2'],
				['field' => 'content', 'op' => 'contains', 'value' => 'Body', 'min_matches' => 2],
			]], 'actions' => ['read' => true, 'star' => true, 'add_tags' => ['processed']],
		]]], JSON_THROW_ON_ERROR);
	}

	/** @param array<string,mixed> $recipe */
	private static function enableExtensions(array $recipe, string $rules): void {
		self::resetManager();
		$ruleExtension = new RuleEngineExtension(['name' => 'Rule Engine', 'entrypoint' => 'RuleEngine',
			'path' => LIB_PATH . '/core-extensions/RuleEngine']);
		$extraction = new WebExtractionExtension(['name' => 'Web Extraction', 'entrypoint' => 'WebExtraction',
			'path' => LIB_PATH . '/core-extensions/WebExtraction']);
		FreshRSS_Context::userConf()->extensions = ['Rule Engine' => ['rules_json' => $rules], 'Web Extraction' => ['recipes' => ['*' => $recipe]]];
		$register = new ReflectionMethod(Minz_ExtensionManager::class, 'register');
		// Deliberately register and enable the rule engine first: hook priority must still extract first.
		$register->invoke(null, $ruleExtension);
		$register->invoke(null, $extraction);
		Minz_ExtensionManager::enableByList(['Rule Engine' => true, 'Web Extraction' => true]);
		self::assertTrue($ruleExtension->isEnabled());
		self::assertTrue($extraction->isEnabled());
	}

	private static function entry(string $source = self::SOURCE, string $guid = 'same-guid'): FreshRSS_Entry {
		return new FreshRSS_Entry(7, $guid, 'Original RSS title', 'RSS author', $source,
			'https://example.test/news/start', 1704067200, tags: ['source']);
	}

	public function testFallbackAndMultiSelectionRunBeforeRulesRegardlessOfActivationOrder(): void {
		self::enableExtensions(self::recipe(), self::rules());
		$entry = self::entry();
		$sourceHash = $entry->hash();
		$result = Minz_ExtensionManager::callHook(Minz_HookType::EntryBeforeAdd, $entry);
		self::assertSame($entry, $result);
		self::assertSame('Release &amp; stable', $entry->title());
		self::assertSame(['Ada'], $entry->authors());
		self::assertSame('https://example.test/articles/story?a=1&amp;b=2', $entry->link(true));
		self::assertTrue($entry->isRead());
		self::assertTrue($entry->isFavorite());
		self::assertSame(['source', 'processed'], $entry->tags());
		self::assertSame($sourceHash, $entry->hash());
		self::assertSame($sourceHash, $entry->toArray()['hash']);
	}

	public function testIdenticalRssAndChangedRecipesDoNotCreateFalseSourceUpdates(): void {
		self::enableExtensions(self::recipe(), self::rules());
		$stored = self::entry();
		Minz_ExtensionManager::callHook(Minz_HookType::EntryBeforeAdd, $stored);
		$duplicate = self::entry();
		self::assertSame($stored->hash(), $duplicate->hash());
		$recipe = self::recipe();
		$recipe['fields'] = ['title' => [['type' => 'css', 'selector' => '.author']]];
		$recipe['required'] = ['title'];
		self::enableExtensions($recipe, self::rules());
		// feedController compares source hashes before these hooks: a recipe edit is not an RSS update.
		self::assertSame($stored->hash(), self::entry()->hash());
		$new = self::entry(guid: 'new-guid');
		Minz_ExtensionManager::callHook(Minz_HookType::EntryBeforeAdd, $new);
		self::assertSame('Ada', $new->title());
		self::assertFalse($new->isFavorite());
		self::assertSame($duplicate->hash(), $new->hash());
	}

	public function testSourceUpdatePreservesUserStateWhileRulesSeeFreshExtractedFields(): void {
		self::enableExtensions(self::recipe(), self::rules());
		$original = self::entry();
		$updated = self::entry(self::SOURCE . '<footer>Source update</footer>');
		$updatedHash = $updated->hash();
		self::assertNotSame($original->hash(), $updatedHash);
		$updated->_isUpdated(true);
		$updated->_isFavorite(null);
		$updated->_isRead(null);
		Minz_ExtensionManager::callHook(Minz_HookType::EntryBeforeUpdate, $updated);
		self::assertSame('Release &amp; stable', $updated->title());
		self::assertTrue($updated->isRead());
		self::assertNull($updated->isFavorite());
		self::assertSame(['source'], $updated->tags());
		self::assertSame($updatedHash, $updated->hash());
	}

	public function testImportedUpdateWithoutFlagStillSuppressesStarAndTagActions(): void {
		self::enableExtensions(self::recipe(), self::rules());
		$entry = self::entry();
		$sourceHash = $entry->hash();
		Minz_ExtensionManager::callHook(Minz_HookType::EntryBeforeUpdate, $entry);
		self::assertTrue($entry->isUpdated());
		self::assertTrue($entry->isRead());
		self::assertFalse($entry->isFavorite());
		self::assertSame(['source'], $entry->tags());
		self::assertSame($sourceHash, $entry->hash());
	}

	public function testScopeChangesAffectNewArticlesWithoutChangingExtractionOrSourceIdentity(): void {
		self::enableExtensions(self::recipe(), self::rules(8));
		$entry = self::entry();
		$sourceHash = $entry->hash();
		Minz_ExtensionManager::callHook(Minz_HookType::EntryBeforeAdd, $entry);
		self::assertSame('Release &amp; stable', $entry->title());
		self::assertFalse($entry->isRead());
		self::assertFalse($entry->isFavorite());
		self::assertSame(['source'], $entry->tags());
		self::assertSame($sourceHash, $entry->hash());
	}

	public function testExtractionFailureLeavesOriginalFieldsAvailableToRules(): void {
		$rules = json_encode(['version' => 1, 'rules' => [['id' => 'original',
			'when' => ['field' => 'title', 'op' => 'equals', 'value' => 'Original RSS title'],
			'actions' => ['star' => true]]]], JSON_THROW_ON_ERROR);
		self::enableExtensions(['fields' => ['title' => [['type' => 'css', 'selector' => 'h1']]], 'max_output_bytes' => 1], $rules);
		$entry = self::entry();
		$sourceHash = $entry->hash();
		Minz_ExtensionManager::callHook(Minz_HookType::EntryBeforeAdd, $entry);
		self::assertSame('Original RSS title', $entry->title());
		self::assertSame(self::SOURCE, $entry->content(false));
		self::assertTrue($entry->isFavorite());
		self::assertSame($sourceHash, $entry->hash());
	}
	public function testMultipleExtractedAuthorsRemainIndividuallyMatchable(): void {
		$recipe = self::recipe();
		$recipe['fields'] = ['author' => [['type' => 'css', 'selector' => '.author', 'all' => true,
			'postprocess' => [['type' => 'normalize_whitespace']]]]];
		$recipe['required'] = ['author'];
		$rules = json_encode(['version' => 1, 'rules' => [['id' => 'coauthor',
			'when' => ['field' => 'author', 'op' => 'equals', 'value' => 'Grace'], 'actions' => ['star' => true]]]], JSON_THROW_ON_ERROR);
		self::enableExtensions($recipe, $rules);
		$entry = self::entry('<span class="author">Ada, Countess of Lovelace</span><span class="author"> Grace </span>');
		$sourceHash = $entry->hash();
		Minz_ExtensionManager::callHook(Minz_HookType::EntryBeforeAdd, $entry);
		self::assertSame(['Ada, Countess of Lovelace', 'Grace'], $entry->authors());
		self::assertTrue($entry->isFavorite());
		self::assertSame($sourceHash, $entry->hash());
	}

}
