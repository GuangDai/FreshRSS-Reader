<?php
declare(strict_types=1);

use FreshRSS\Services\Extraction\ExtractionEngine;

require_once LIB_PATH . '/core-extensions/WebExtraction/extension.php';

final class WebExtractionTest extends \PHPUnit\Framework\TestCase {
	private ?FreshRSS_UserConfiguration $previousUser;
	private Closure $restoreRegistry;
	#[\Override]
	protected function setUp(): void {
		FreshRSS_Context::initSystem();
		$this->previousUser = FreshRSS_Context::hasUserConf() ? FreshRSS_Context::userConf() : null;
		$registry = (new ReflectionProperty(Minz_Configuration::class, 'config_list'))->getValue();
		self::assertIsArray($registry);
		$this->restoreRegistry = static fn() => (new ReflectionProperty(Minz_Configuration::class, 'config_list'))->setValue(null, $registry);
		FreshRSS_Context::setUserConf(clone FreshRSS_UserConfiguration::init(FRESHRSS_PATH . '/config-user.default.php'));
		(new ReflectionMethod(Minz_ExtensionManager::class, 'reset'))->invoke(null);
	}

	#[\Override]
	protected function tearDown(): void {
		FreshRSS_Context::setUserConf($this->previousUser);
		($this->restoreRegistry)();
		(new ReflectionMethod(Minz_ExtensionManager::class, 'reset'))->invoke(null);
	}

	public function testActualAddAndUpdateHooksApplyOnlyAfterInsertHook(): void {
		$extension = new WebExtractionExtension(['name' => 'Web Extraction', 'entrypoint' => 'WebExtraction',
			'path' => LIB_PATH . '/core-extensions/WebExtraction']);
		FreshRSS_Context::userConf()->extensions = ['Web Extraction' => ['recipes' => [7 => [
			'fields' => ['title' => [['type' => 'css', 'selector' => 'h1']]],
		]]]];
		$extension->init();
		foreach ([Minz_HookType::EntryBeforeAdd, Minz_HookType::EntryBeforeUpdate] as $hook) {
			$entry = FreshRSS_Entry::fromArray(['id_feed' => 7, 'title' => 'Original', 'content' => '<h1>A &amp; B</h1>']);
			self::assertSame($entry, Minz_ExtensionManager::callHook(Minz_HookType::EntryBeforeInsert, $entry));
			self::assertSame('Original', $entry->title());
			self::assertSame($entry, Minz_ExtensionManager::callHook($hook, $entry));
			self::assertSame('A &amp; B', $entry->title());
		}
		$other = FreshRSS_Entry::fromArray(['id_feed' => 8, 'title' => 'Original', 'content' => '<h1>Ignored</h1>']);
		Minz_ExtensionManager::callHook(Minz_HookType::EntryBeforeAdd, $other);
		self::assertSame('Original', $other->title());
	}

	public function testFailedExtractionPreservesAllFields(): void {
		$entry = FreshRSS_Entry::fromArray(['title' => 'Original', 'content' => '<p>Original</p>']);
		$errors = WebExtractionExtension::applyRecipe($entry, [
			'fields' => [
				'title' => [['type' => 'css', 'selector' => 'p']],
				'content' => [['type' => 'xpath', 'selector' => '//*[']],
			],
		]);
		self::assertNotEmpty($errors);
		self::assertSame('Original', $entry->title());
		self::assertSame('<p>Original</p>', $entry->content(false));
	}

	public function testJsonTextContentIsEscaped(): void {
		$entry = FreshRSS_Entry::fromArray(['link' => 'https://example.org/news',
			'content' => '{"body":"<img src=x onerror=alert(1)>","link":"javascript:alert(1)"}']);
		$errors = WebExtractionExtension::applyRecipe($entry, [
			'format' => 'json', 'fields' => [
				'content' => [['type' => 'json', 'selector' => 'body']],
			],
		]);
		self::assertSame([], $errors);
		self::assertSame('&lt;img src=x onerror=alert(1)&gt;', $entry->content(false));
		self::assertSame('https://example.org/news', $entry->link(true));
	}

	public function testRelativeCanonicalLinkAndAuthorAreEncoded(): void {
		$entry = FreshRSS_Entry::fromArray(['link' => 'https://example.org/news',
			'content' => '<a href="/story?a=1&amp;b=2">Author &amp; Friend</a>']);
		$errors = WebExtractionExtension::applyRecipe($entry, [
			'fields' => [
				'author' => [['type' => 'css', 'selector' => 'a']],
				'link' => [['type' => 'css', 'selector' => 'a', 'mode' => 'attribute', 'attribute' => 'href']],
			],
		]);
		self::assertSame([], $errors);
		self::assertSame('https://example.org/story?a=1&amp;b=2', $entry->link(true));
		self::assertSame(['Author &amp; Friend'], $entry->authors());
	}

	public function testUnsafeLinkFailsBeforeAnyEntryFieldChanges(): void {
		$entry = FreshRSS_Entry::fromArray(['title' => 'Original', 'link' => 'https://example.org/original',
			'content' => '{"title":"Replacement","link":"javascript:alert(1)"}']);
		$originalContent = $entry->content(false);
		$errors = WebExtractionExtension::applyRecipe($entry, ['format' => 'json', 'fields' => [
			'title' => [['type' => 'json', 'selector' => 'title']], 'link' => [['type' => 'json', 'selector' => 'link']],
		]]);
		self::assertNotEmpty($errors);
		self::assertSame('Original', $entry->title());
		self::assertSame($originalContent, $entry->content(false));
		self::assertSame('https://example.org/original', $entry->link(true));
	}

	public function testDerivedFieldsDoNotMakeTheNextIdenticalFeedLookUpdated(): void {
		$original = ['id_feed' => 7, 'title' => 'Feed title', 'content' => '<h1>Derived title</h1><p>Body</p>',
			'link' => 'https://example.org/item'];
		$entry = FreshRSS_Entry::fromArray($original);
		$sourceHash = $entry->hash();
		$errors = WebExtractionExtension::applyRecipe($entry, ['fields' => [
			'title' => [['type' => 'css', 'selector' => 'h1']], 'content' => [['type' => 'css', 'selector' => 'p', 'mode' => 'html']],
		]]);
		self::assertSame([], $errors);
		self::assertSame('Derived title', $entry->title());
		self::assertSame('<p>Body</p>', $entry->content(false));
		self::assertSame($sourceHash, $entry->toArray()['hash']);
		self::assertSame(FreshRSS_Entry::fromArray($original)->hash(), $entry->hash());
		$updated = FreshRSS_Entry::fromArray(array_replace($original, ['content' => '<h1>Updated source</h1><p>Body</p>']));
		self::assertNotSame($entry->hash(), $updated->hash());
	}

	public function testRecoveredFallbackIsAppliedAndUnmatchedOptionalFieldsArePreserved(): void {
		$entry = FreshRSS_Entry::fromArray(['title' => 'Original', 'author' => 'Original author',
			'content' => '<h1>Fallback title</h1>']);
		$errors = WebExtractionExtension::applyRecipe($entry, ['fields' => [
			'title' => [['type' => 'xpath', 'selector' => '//*['], ['type' => 'css', 'selector' => 'h1']],
			'author' => [['type' => 'css', 'selector' => '.missing']],
		]]);
		self::assertSame([], $errors);
		self::assertSame('Fallback title', $entry->title());
		self::assertSame(['Original author'], $entry->authors());
	}

	public function testExtractionRunsBeforeRulesAndSpecificRecipeOverridesDefault(): void {
		$extension = new WebExtractionExtension(['name' => 'Web Extraction', 'entrypoint' => 'WebExtraction',
			'path' => LIB_PATH . '/core-extensions/WebExtraction']);
		FreshRSS_Context::userConf()->extensions = ['Web Extraction' => ['recipes' => [
			'*' => ['fields' => ['title' => [['type' => 'css', 'selector' => 'p']]]],
			7 => ['fields' => ['title' => [['type' => 'css', 'selector' => 'h1']]]],
		]]];
		$extension->init();
		$seenTitles = [];
		Minz_ExtensionManager::addHook(Minz_HookType::EntryBeforeAdd, static function (FreshRSS_Entry $entry) use (&$seenTitles): FreshRSS_Entry {
			$seenTitles[] = $entry->title();
			return $entry;
		}, 100);
		foreach ([7, 8] as $feed) {
			$entry = FreshRSS_Entry::fromArray(['id_feed' => $feed, 'content' => '<h1>Specific</h1><p>Default</p>']);
			Minz_ExtensionManager::callHook(Minz_HookType::EntryBeforeAdd, $entry);
		}
		self::assertSame(['Specific', 'Default'], $seenTitles);
	}

	public function testAllAuthorNodesPreserveIndividualNamesIncludingCommas(): void {
		$entry = FreshRSS_Entry::fromArray(['content' => '<span class="author">Ada, Countess of Lovelace</span>'
			. '<span class="author">Grace</span>']);
		$errors = WebExtractionExtension::applyRecipe($entry, ['fields' => ['author' => [[
			'type' => 'css', 'selector' => '.author', 'all' => true, 'separator' => ', ',
			'postprocess' => [['type' => 'normalize_whitespace']],
		]]]]);
		self::assertSame([], $errors);
		self::assertSame(['Ada, Countess of Lovelace', 'Grace'], $entry->authors());
	}

	public function testConfigurationValidation(): void {
		$recipe = ExtractionEngine::presets()['article'];
		self::assertSame([], WebExtractionExtension::validateRecipes(['*' => $recipe, 1 => $recipe]));
		self::assertNotEmpty(WebExtractionExtension::validateRecipes(['oops' => $recipe]));
		self::assertNotEmpty(WebExtractionExtension::validateRecipes([0 => $recipe]));
		self::assertNotEmpty(WebExtractionExtension::validateRecipes([1 => ['fields' => []]]));
	}
}
