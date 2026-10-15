<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Rules;

use FreshRSS\Exceptions\Rules\RuleException;
use FreshRSS\Services\Rules\RuleEngine;
use FreshRSS_Entry;
use InvalidArgumentException;
use Minz_ExtensionManager;
use Minz_HookType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuleEngineExtension;

final class RuleEngineTest extends TestCase {
	/** @param array<string,mixed> $when
	 * @param array<string,mixed> $actions
	 */
	private static function rule(array $when, array $actions = ['star' => true]): string {
		return json_encode(['version' => 1, 'rules' => [['id' => 'test', 'when' => $when, 'actions' => $actions]]], JSON_THROW_ON_ERROR);
	}

	public function testUnicodeAndNestedGroups(): void {
		$engine = RuleEngine::fromJson(self::rule(['all' => [
			['any' => [['field' => 'title', 'op' => 'contains', 'value' => 'ÉTÉ'], ['field' => 'title', 'op' => 'contains', 'value' => '版本']]],
			['not' => ['field' => 'tags', 'op' => 'equals', 'value' => 'beta']],
		]]));
		self::assertSame(['test'], $engine->preview(['title' => 'été', 'tags' => ['release']])['matched']);
		self::assertSame(['test'], $engine->preview(['title' => '新版本'])['matched']);
		self::assertSame([], $engine->preview(['title' => '新版本', 'tags' => ['BETA']])['matched']);
		self::assertSame([], $engine->preview(['title' => 'other'])['matched']);
	}

	public function testCaseSensitivityAndAuthorArrays(): void {
		$engine = RuleEngine::fromJson(self::rule(['field' => 'author', 'op' => 'equals', 'value' => 'Ada', 'case_sensitive' => true]));
		self::assertSame(['test'], $engine->preview(['author' => ['Grace', 'Ada']])['matched']);
		self::assertSame([], $engine->preview(['author' => ['ada']])['matched']);
	}

	public function testStopAndMergedActionsUseOriginalSample(): void {
		$json = '{"version":1,"rules":[
			{"id":"one","when":{"field":"tags","op":"empty"},"actions":{"add_tags":["a","a"]}},
			{"id":"two","when":{"field":"tags","op":"empty"},"actions":{"read":true,"add_tags":["b","a"]},"stop":true},
			{"id":"three","when":{"field":"tags","op":"empty"},"actions":{"star":true}}
		]}';
		$result = RuleEngine::fromJson($json)->preview([]);
		self::assertSame(['one', 'two'], $result['matched']);
		self::assertSame(['add_tags' => ['a', 'b'], 'read' => true], $result['actions']);
	}

	/** @return iterable<string,array{string}> */
	public static function invalidConfigurations(): iterable {
		yield 'syntax' => ['{'];
		yield 'object required' => ['[]'];
		yield 'numeric version required' => ['{"version":"1","rules":[]}'];
		yield 'unknown field' => ['{"version":1,"rules":[],"silent_typo":true}'];
		yield 'null limits' => ['{"version":1,"rules":[],"limits":null}'];
		yield 'null limit' => ['{"version":1,"rules":[],"limits":{"depth":null}}'];
		yield 'zero limit' => ['{"version":1,"rules":[],"limits":{"depth":0}}'];
		yield 'object instead of list' => ['{"version":1,"rules":{}}'];
		yield 'empty group' => [self::rule(['all' => []])];
		yield 'unknown operation' => [self::rule(['field' => 'title', 'op' => 'eval', 'value' => 'x'])];
		yield 'unknown sample field' => [self::rule(['field' => 'password', 'op' => 'empty'])];
		yield 'ambiguous group' => [self::rule(['not' => ['field' => 'title', 'op' => 'empty'], 'field' => 'title'])];
		yield 'null case sensitivity' => [self::rule(['field' => 'title', 'op' => 'empty', 'case_sensitive' => null])];
		yield 'value forbidden for empty' => [self::rule(['field' => 'title', 'op' => 'empty', 'value' => ''])];
		yield 'no destructive actions' => [self::rule(['field' => 'title', 'op' => 'empty'], ['read' => false])];
		yield 'invalid tag type' => [self::rule(['field' => 'title', 'op' => 'empty'], ['add_tags' => [12]])];
	}

	#[DataProvider('invalidConfigurations')]
	public function testInvalidConfigurationsReportPath(string $json): void {
		$errors = RuleEngine::validate($json);
		self::assertCount(1, $errors);
		self::assertStringStartsWith('rules', $errors[0]);
	}

	public function testExplicitLimitsAndDuplicateIds(): void {
		$json = self::rule(['not' => ['field' => 'title', 'op' => 'empty']]);
		self::assertNotEmpty(RuleEngine::validate(str_replace('"version":1', '"version":1,"limits":{"depth":1}', $json)));
		self::assertNotEmpty(RuleEngine::validate(str_replace('"version":1', '"version":1,"limits":{"nodes":1}', $json)));
		self::assertNotEmpty(RuleEngine::validate($json, 1));
		self::assertSame([], RuleEngine::validate($json, strlen($json)));
		$duplicate = '{"version":1,"rules":[{"id":"same","when":{"field":"title","op":"empty"},"actions":{"read":true}},'
			. '{"id":"same","when":{"field":"title","op":"empty"},"actions":{"read":true}}]}';
		self::assertStringContainsString('.id:', RuleEngine::validate($duplicate)[0]);
	}

	public function testPreviewRejectsOversizeInsteadOfSilentlyTruncating(): void {
		$engine = RuleEngine::fromJson('{"version":1,"limits":{"text_bytes":3},"rules":[]}');
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('text_bytes');
		$engine->preview(['title' => 'abcd']);
	}

	public function testPreviewRejectsInvalidSampleTypes(): void {
		$engine = RuleEngine::fromJson('{"version":1,"rules":[]}');
		$this->expectException(InvalidArgumentException::class);
		$engine->preview(['title' => [12]]);
	}

	public function testPresetsAreUsableAndEmptyRulesAreNoop(): void {
		foreach (RuleEngine::presets() as $json) {
			self::assertSame([], RuleEngine::validate($json));
		}
		self::assertSame(['matched' => [], 'actions' => []], RuleEngine::fromJson('{"version":1,"rules":[]}')->preview([]));
	}

	public function testDisabledRulesAreValidatedButNotApplied(): void {
		$json = str_replace('"id":"test"', '"id":"test","enabled":false', self::rule(['field' => 'title', 'op' => 'empty']));
		self::assertSame([], RuleEngine::fromJson($json)->preview([])['matched']);
	}
	public function testApplicationDecodesHtmlAndProtectsUpdatedStarsAndTags(): void {
		$entry = $this->getMockBuilder(FreshRSS_Entry::class)->setConstructorArgs([
			0, 'guid', 'News &amp; release', 'Ada', '<p>Body &amp; detail</p>', 'https://example.org/',
		])->onlyMethods(['feed'])->getMock();
		$entry->method('feed')->willReturn(null);
		$engine = RuleEngine::fromJson(self::rule(['all' => [
			['field' => 'title', 'op' => 'contains', 'value' => 'News & release'],
			['field' => 'content', 'op' => 'equals', 'value' => 'Body & detail'],
		]], ['star' => true, 'add_tags' => ['A & B', '<tag>']]));
		$sourceHash = $entry->hash();
		self::assertSame($entry, $engine->apply($entry));
		self::assertSame($sourceHash, $entry->hash());
		self::assertTrue($entry->isFavorite());
		self::assertSame(['A &amp; B', '&lt;tag&gt;'], $entry->tags());
		$entry->_isUpdated(true);
		$entry->_isFavorite(false);
		$entry->_tags(['manual']);
		$engine->apply($entry);
		self::assertFalse($entry->isFavorite());
		self::assertSame(['manual'], $entry->tags());
	}

	public function testReadActionEmitsHookOnlyOnStateChange(): void {
		(new ReflectionMethod(Minz_ExtensionManager::class, 'reset'))->invoke(null);
		$entry = $this->getMockBuilder(FreshRSS_Entry::class)->onlyMethods(['feed'])->getMock();
		$entry->method('feed')->willReturn(null);
		$called = [];
		Minz_ExtensionManager::addHook(Minz_HookType::EntryAutoRead, static function (FreshRSS_Entry $entry, string $why) use (&$called): void {
			$called[] = $why;
		});
		try {
			$engine = RuleEngine::fromJson(self::rule(['field' => 'title', 'op' => 'empty'], ['read' => true]));
			$engine->apply($entry);
			$engine->apply($entry);
			self::assertTrue($entry->isRead());
			self::assertSame(['rule_engine'], $called);
		} finally {
			(new ReflectionMethod(Minz_ExtensionManager::class, 'reset'))->invoke(null);
		}
	}

	public function testEmptyEngineDoesNotLoadFeedOrContent(): void {
		$entry = $this->createMock(FreshRSS_Entry::class);
		$entry->expects(self::never())->method('feed');
		$entry->expects(self::never())->method('content');
		self::assertSame($entry, RuleEngine::fromJson('{"version":1,"rules":[]}')->apply($entry));
	}

	public function testExtensionDoesNotSwallowThirdPartyHookExceptions(): void {
		require_once LIB_PATH . '/core-extensions/RuleEngine/extension.php';
		(new ReflectionMethod(Minz_ExtensionManager::class, 'reset'))->invoke(null);
		$extension = new RuleEngineExtension(['name' => 'Rule Engine', 'entrypoint' => 'RuleEngine', 'path' => LIB_PATH . '/core-extensions/RuleEngine']);
		(new ReflectionProperty($extension, 'engine'))->setValue($extension,
			RuleEngine::fromJson(self::rule(['field' => 'title', 'op' => 'empty'], ['read' => true])));
		$entry = $this->getMockBuilder(FreshRSS_Entry::class)->onlyMethods(['feed'])->getMock();
		$entry->method('feed')->willReturn(null);
		Minz_ExtensionManager::addHook(Minz_HookType::EntryAutoRead, static function (): void {
			throw new InvalidArgumentException('third-party failure');
		});
		try {
			$this->expectException(InvalidArgumentException::class);
			$this->expectExceptionMessage('third-party failure');
			$extension->filter($entry);
		} finally {
			(new ReflectionMethod(Minz_ExtensionManager::class, 'reset'))->invoke(null);
		}
	}

	public function testUpdateHookProtectsImportedArticleWithoutUpdateFlag(): void {
		require_once LIB_PATH . '/core-extensions/RuleEngine/extension.php';
		$extension = new RuleEngineExtension(['name' => 'Rule Engine', 'entrypoint' => 'RuleEngine', 'path' => LIB_PATH . '/core-extensions/RuleEngine']);
		(new ReflectionProperty($extension, 'engine'))->setValue($extension,
			RuleEngine::fromJson(self::rule(['field' => 'title', 'op' => 'empty'], ['star' => true, 'add_tags' => ['auto']])));
		$entry = $this->getMockBuilder(FreshRSS_Entry::class)->onlyMethods(['feed'])->getMock();
		$entry->method('feed')->willReturn(null);
		$extension->filterUpdated($entry);
		self::assertFalse($entry->isFavorite());
		self::assertSame([], $entry->tags());
		self::assertTrue($entry->isUpdated());
	}

	public function testEmptyStringsCannotBypassSampleValueLimit(): void {
		$engine = RuleEngine::fromJson('{"version":1,"limits":{"text_bytes":1,"sample_values":3},"rules":[]}');
		$this->expectException(RuleException::class);
		$this->expectExceptionMessage('sample.tags: total sample_values limit exceeded (3)');
		$engine->preview(['tags' => array_fill(0, 20000, '')]);
	}

	public function testSampleValuesCountAcrossFieldsAndAllowConfiguredBoundary(): void {
		$engine = RuleEngine::fromJson('{"version":1,"limits":{"sample_values":3},"rules":[]}');
		self::assertSame(['matched' => [], 'actions' => []], $engine->preview(['title' => '', 'tags' => ['', '']]));
		$this->expectException(RuleException::class);
		$this->expectExceptionMessage('sample_values');
		$engine->preview(['title' => '', 'tags' => ['', '', '']]);
	}

	public function testSampleValueLimitCanBeRaisedExplicitly(): void {
		$engine = RuleEngine::fromJson('{"version":1,"limits":{"sample_values":20000},"rules":[]}');
		self::assertSame(['matched' => [], 'actions' => []], $engine->preview(['tags' => array_fill(0, 20000, '')]));
		self::assertNotEmpty(RuleEngine::validate('{"version":1,"limits":{"sample_values":0},"rules":[]}'));
	}

	public function testAddedTagsSurviveModelSerialisation(): void {
		$entry = $this->getMockBuilder(FreshRSS_Entry::class)->onlyMethods(['feed'])->getMock();
		$entry->method('feed')->willReturn(null);
		$engine = RuleEngine::fromJson(self::rule(['field' => 'title', 'op' => 'empty'], [
			'add_tags' => ["Author's notes", 'A & B', '<tag>'],
		]));
		$engine->apply($entry);
		$reloaded = new FreshRSS_Entry(tags: $entry->tags(true));
		self::assertSame($entry->tags(), $reloaded->tags());
		self::assertSame(["Author&apos;s notes", 'A &amp; B', '&lt;tag&gt;'], $reloaded->tags());
		foreach (['a#b', 'a,b'] as $tag) {
			$errors = RuleEngine::validate(self::rule(['field' => 'title', 'op' => 'empty'], ['add_tags' => [$tag]]));
			self::assertStringContainsString('RSS category storage separators', $errors[0]);
		}
	}

	public function testNumericDatesAndUnicodeContentLength(): void {
		$engine = RuleEngine::fromJson(self::rule(['all' => [
			['field' => 'content_length', 'op' => 'gte', 'value' => 3],
			['field' => 'content_length', 'op' => 'lte', 'value' => 3],
			['field' => 'published_at', 'op' => 'gt', 'value' => 100],
			['field' => 'published_at', 'op' => 'lt', 'value' => 102],
		]]));
		self::assertSame(['test'], $engine->preview(['content' => '中文é', 'published_at' => '101'])['matched']);
		self::assertSame([], $engine->preview(['content' => '中文é'])['matched']);
		self::assertSame([], $engine->preview(['content' => 'four', 'published_at' => '101'])['matched']);
		self::assertSame(['test'], $engine->preview(['content_length' => '3', 'published_at' => '101'])['matched']);
	}

	public function testOccurrencesAreLiteralNonOverlappingAndSumAcrossValues(): void {
		$engine = RuleEngine::fromJson(self::rule(['field' => 'tags', 'op' => 'contains', 'value' => 'aa', 'min_matches' => 3]));
		self::assertSame([], $engine->preview(['tags' => ['aaa', 'AA']])['matched']);
		$result = $engine->preview(['tags' => ['aaaa', 'AA']], explain: true);
		self::assertSame(['test'], $result['matched']);
		self::assertArrayHasKey('explanation', $result);
		self::assertSame(3, $result['explanation']['conditions'][0]['actual'] ?? null);
		self::assertFalse($result['explanation']['truncated']);
	}

	public function testExplanationsAreBoundedAndDoNotChangeDecisions(): void {
		$json = self::rule(['all' => [
			['field' => 'title', 'op' => 'contains', 'value' => 'private'],
			['not' => ['field' => 'tags', 'op' => 'equals', 'value' => 'skip']],
		]]);
		$json = str_replace('"version":1', '"version":1,"limits":{"explanation_nodes":1}', $json);
		$engine = RuleEngine::fromJson($json);
		$plain = $engine->preview(['title' => 'private customer text']);
		$explained = $engine->preview(['title' => 'private customer text'], explain: true);
		self::assertSame($plain['matched'], $explained['matched']);
		self::assertSame($plain['actions'], $explained['actions']);
		self::assertArrayNotHasKey('explanation', $plain);
		self::assertArrayHasKey('explanation', $explained);
		self::assertCount(1, $explained['explanation']['conditions']);
		self::assertTrue($explained['explanation']['truncated']);
		self::assertSame(['path' => 'rules.rules[0].when.all[0]', 'op' => 'contains', 'matched' => true,
			'field' => 'title', 'actual' => 1], $explained['explanation']['conditions'][0]);
		self::assertStringNotContainsString('private', json_encode($explained['explanation'], JSON_THROW_ON_ERROR));
	}

	/** @return iterable<string,array{string}> */
	public static function invalidNumericSamples(): iterable {
		yield 'float' => ['1.5'];
		yield 'exponent' => ['1e3'];
		yield 'overflow' => ['922337203685477580899'];
		yield 'not number' => ['yesterday'];
		yield 'leading zeros' => ['001'];
		yield 'whitespace' => [' 1'];
	}

	#[DataProvider('invalidNumericSamples')]
	public function testNumericSamplesRejectLossyCoercion(string $value): void {
		$engine = RuleEngine::fromJson('{"version":1,"rules":[]}');
		$this->expectException(RuleException::class);
		$this->expectExceptionMessage('sample.published_at:');
		$engine->preview(['published_at' => $value]);
	}

	public function testNumericConditionsAndCountOptionsAreStrict(): void {
		foreach ([
			['field' => 'title', 'op' => 'lt', 'value' => 2],
			['field' => 'content_length', 'op' => 'gt', 'value' => '2'],
			['field' => 'content_length', 'op' => 'lt', 'value' => -1],
			['field' => 'content_length', 'op' => 'lt', 'value' => 2, 'case_sensitive' => false],
			['field' => 'title', 'op' => 'contains', 'value' => 'x', 'min_matches' => 0],
			['field' => 'title', 'op' => 'equals', 'value' => 'x', 'min_matches' => 2],
			] as $condition) {
			self::assertNotEmpty(RuleEngine::validate(self::rule($condition)));
		}
	}

	public function testApplyUsesSameNumericAndCountSemanticsAsPreview(): void {
		$entry = $this->getMockBuilder(FreshRSS_Entry::class)->setConstructorArgs([
			0, 'numeric-guid', '', '', '<p>中文 中文</p>', '', 101,
		])->onlyMethods(['feed'])->getMock();
		$entry->method('feed')->willReturn(null);
		$engine = RuleEngine::fromJson(self::rule(['all' => [
			['field' => 'content_length', 'op' => 'lte', 'value' => 5],
			['field' => 'content', 'op' => 'contains', 'value' => '中文', 'min_matches' => 2],
			['field' => 'published_at', 'op' => 'gt', 'value' => 100],
		]]));
		self::assertSame(['star' => true], $engine->preview(['content' => '中文 中文', 'published_at' => '101'], true)['actions']);
		$engine->apply($entry);
		self::assertTrue($entry->isFavorite());
	}

	public function testExtensionSettingsSchemaRunsSemanticValidation(): void {
		require_once LIB_PATH . '/core-extensions/RuleEngine/extension.php';
		$extension = new RuleEngineExtension(['name' => 'Rule Engine', 'entrypoint' => 'RuleEngine', 'path' => LIB_PATH . '/core-extensions/RuleEngine']);
		self::assertSame(['rules_json', 'hook_priority'], array_keys($extension->getConfigurationSchema()));
		self::assertSame([], $extension->validateConfiguration(['rules_json' => RuleEngineExtension::EMPTY_RULES]));
		self::assertArrayHasKey('rules_json', $extension->validateConfiguration(['rules_json' => '{"version":99,"rules":[]}']));
	}

	public function testUpdatedPreviewReportsOnlyActionsThatApplyWillPerform(): void {
		$engine = RuleEngine::fromJson(self::rule(['field' => 'title', 'op' => 'empty'],
			['read' => true, 'star' => true, 'add_tags' => ['new']]));
		$result = $engine->preview([], explain: true, updated: true);
		self::assertSame(['test'], $result['matched']);
		self::assertSame(['read' => true], $result['actions']);
		self::assertSame(['star', 'add_tags'], $result['explanation']['suppressed_actions'] ?? null);
		$entry = $this->getMockBuilder(FreshRSS_Entry::class)->onlyMethods(['feed'])->getMock();
		$entry->method('feed')->willReturn(null);
		$entry->_isUpdated(true);
		self::assertSame($result['actions'], $engine->previewEntry($entry)['actions']);
		$engine->apply($entry);
		self::assertTrue($entry->isRead());
		self::assertFalse($entry->isFavorite());
		self::assertSame([], $entry->tags());
	}

	public function testUrlRulesDecodeStoredQueryEntities(): void {
		$entry = $this->getMockBuilder(FreshRSS_Entry::class)->setConstructorArgs([
			0, '', '', '', '', 'https://example.org/?a=1&amp;b=2',
		])->onlyMethods(['feed'])->getMock();
		$entry->method('feed')->willReturn(null);
		$engine = RuleEngine::fromJson(self::rule(['field' => 'url', 'op' => 'equals', 'value' => 'https://example.org/?a=1&b=2']));
		self::assertSame(['test'], $engine->previewEntry($entry)['matched']);
		$engine->apply($entry);
		self::assertTrue($entry->isFavorite());
	}

	public function testTagStorageChecksEncodedCombinedBytesBeforeAnyMutation(): void {
		$entry = $this->getMockBuilder(FreshRSS_Entry::class)->onlyMethods(['feed'])->getMock();
		$entry->method('feed')->willReturn(null);
		$entry->_tags([str_repeat('a', FreshRSS_Entry::MAX_TAGS_LENGTH - 2)]);
		$original = $entry->tags();
		$engine = RuleEngine::fromJson(self::rule(['field' => 'title', 'op' => 'empty'],
			['read' => true, 'star' => true, 'add_tags' => ['&']]));
		try {
			$engine->apply($entry);
			self::fail('Expected encoded tag storage limit error');
		} catch (RuleException $exception) {
			self::assertStringContainsString('tag_bytes', $exception->getMessage());
		}
		self::assertFalse($entry->isRead());
		self::assertFalse($entry->isFavorite());
		self::assertSame($original, $entry->tags());
	}

	public function testTagLimitAcceptsExactEncodedBoundaryAndRejectsFalseCapacity(): void {
		$json = self::rule(['field' => 'title', 'op' => 'empty'], ['add_tags' => ['&']]);
		$engine = RuleEngine::fromJson(str_replace('"version":1', '"version":1,"limits":{"tag_bytes":6}', $json));
		self::assertSame(['add_tags' => ['&']], $engine->preview([])['actions']);
		try {
			RuleEngine::fromJson(str_replace('"version":1', '"version":1,"limits":{"tag_bytes":5}', $json));
			self::fail('Expected encoded tag limit error');
		} catch (RuleException $exception) {
			self::assertStringContainsString('tag_bytes (5)', $exception->getMessage());
		}
		self::assertNotEmpty(RuleEngine::validate('{"version":1,"rules":[],"limits":{"tag_bytes":2049}}'));
		self::assertSame([], $engine->preview(['tags' => [str_repeat('a', 20)]], updated: true)['actions']);
	}

	public function testFeedScopesExcludeBeforeMatchingAndDoNotStopOtherFeeds(): void {
		$json = '{"version":1,"rules":[
			{"id":"scoped","scope":{"feed_ids":[1,2],"exclude_feed_ids":[2]},
			 "when":{"field":"title","op":"empty"},"actions":{"star":true},"stop":true},
			{"id":"fallback","when":{"field":"title","op":"empty"},"actions":{"read":true}}
		]}';
		$engine = RuleEngine::fromJson($json);
		self::assertSame(['scoped'], $engine->preview(['feed_id' => '1'])['matched']);
		self::assertSame(['fallback'], $engine->preview(['feed_id' => '2'])['matched']);
		self::assertSame(['fallback'], $engine->preview(['feed_id' => '3'])['matched']);
		self::assertSame(['fallback'], $engine->preview([])['matched']);
		$result = $engine->preview(['feed_id' => '2'], explain: true);
		self::assertSame(['path' => 'rules.rules[0].scope', 'op' => 'scope', 'matched' => false, 'field' => 'feed_id'],
			$result['explanation']['conditions'][0] ?? null);
	}

	public function testScopesUseTheSameUpdateStateForPreviewAndApply(): void {
		$json = str_replace('"id":"test"', '"id":"test","scope":{"states":["new"],"feed_ids":[7]}',
			self::rule(['field' => 'title', 'op' => 'empty'], ['star' => true]));
		$engine = RuleEngine::fromJson($json);
		self::assertSame(['test'], $engine->preview(['feed_id' => '7'])['matched']);
		self::assertSame([], $engine->preview(['feed_id' => '7'], updated: true)['matched']);
		$entry = $this->getMockBuilder(FreshRSS_Entry::class)->setConstructorArgs([7])->onlyMethods(['feed'])->getMock();
		$entry->method('feed')->willReturn(null);
		$engine->apply($entry);
		self::assertTrue($entry->isFavorite());
		$entry->_isUpdated(true);
		self::assertSame([], $engine->previewEntry($entry)['matched']);
	}

	public function testScopeValidationAndTotalScopeItemLimit(): void {
		foreach ([
			'null', '{"feed_ids":[]}', '{"feed_ids":[0]}', '{"feed_ids":["1"]}',
			'{"states":["other"]}', '{"states":[1]}', '{"category_ids":[1]}',
			] as $scope) {
			$json = str_replace('"id":"test"', '"id":"test","scope":' . $scope, self::rule(['field' => 'title', 'op' => 'empty']));
			self::assertNotEmpty(RuleEngine::validate($json));
		}
		$json = str_replace('"id":"test"', '"id":"test","scope":{"feed_ids":[1],"states":["new"]}',
			self::rule(['field' => 'title', 'op' => 'empty']));
		self::assertSame([], RuleEngine::validate(str_replace('"version":1', '"version":1,"limits":{"scope_items":2}', $json)));
		self::assertStringContainsString('scope_items', RuleEngine::validate(
			str_replace('"version":1', '"version":1,"limits":{"scope_items":1}', $json))[0]);
	}

	public function testFeedScopeRejectsAmbiguousSampleIdentity(): void {
		$json = str_replace('"id":"test"', '"id":"test","scope":{"feed_ids":[1]}', self::rule(['field' => 'title', 'op' => 'empty']));
		$engine = RuleEngine::fromJson($json);
		$this->expectException(RuleException::class);
		$this->expectExceptionMessage('sample.feed_id:');
		$engine->preview(['feed_id' => ['1', '2']]);
	}

	public function testTitleOnlyRulesDoNotReadUnrelatedLargeContentOrFeed(): void {
		$json = str_replace('"version":1', '"version":1,"limits":{"text_bytes":3}',
			self::rule(['field' => 'title', 'op' => 'equals', 'value' => 'yes']));
		$entry = $this->getMockBuilder(FreshRSS_Entry::class)->setConstructorArgs([
			0, '', 'yes', '', str_repeat('x', 10000),
		])->onlyMethods(['feed', 'content', 'authors', 'tags'])->getMock();
		$entry->expects(self::never())->method('feed');
		$entry->expects(self::never())->method('content');
		$entry->expects(self::never())->method('authors');
		$entry->expects(self::never())->method('tags');
		RuleEngine::fromJson($json)->apply($entry);
		self::assertTrue($entry->isFavorite());
	}

	public function testOutOfScopeEntriesDoNotLoadConditionFields(): void {
		$json = str_replace('"id":"test"', '"id":"test","scope":{"feed_ids":[2]}',
			self::rule(['field' => 'content', 'op' => 'contains', 'value' => 'x']));
		$entry = $this->getMockBuilder(FreshRSS_Entry::class)->setConstructorArgs([1])->onlyMethods(['feed', 'content'])->getMock();
		$entry->expects(self::never())->method('feed');
		$entry->expects(self::never())->method('content');
		self::assertSame([], RuleEngine::fromJson($json)->previewEntry($entry)['matched']);
	}

	public function testReferencedFieldsAreLoadedOnceAcrossConditions(): void {
		$entry = $this->getMockBuilder(FreshRSS_Entry::class)->onlyMethods(['feed', 'content'])->getMock();
		$entry->expects(self::never())->method('feed');
		$entry->expects(self::once())->method('content')->with(false)->willReturn('<p>中文 release</p>');
		$engine = RuleEngine::fromJson(self::rule(['all' => [
			['field' => 'content', 'op' => 'contains', 'value' => '中文'],
			['field' => 'content_length', 'op' => 'gte', 'value' => 2],
			['field' => 'content', 'op' => 'ends_with', 'value' => 'RELEASE'],
		]]));
		self::assertSame(['test'], $engine->previewEntry($entry)['matched']);
	}

	public function testComparisonBudgetIsSharedAcrossRulesAndExplanationModes(): void {
		$json = '{"version":1,"limits":{"match_checks":3},"rules":[
			{"id":"one","when":{"field":"tags","op":"contains","value":"x"},"actions":{"star":true}},
			{"id":"two","when":{"field":"tags","op":"contains","value":"x"},"actions":{"read":true}}
		]}';
		$engine = RuleEngine::fromJson($json);
		foreach ([false, true] as $explain) {
			try {
				$engine->preview(['tags' => ['x', 'x']], $explain);
				self::fail('Expected shared comparison budget error');
			} catch (RuleException $exception) {
				self::assertStringContainsString('match_checks', $exception->getMessage());
			}
		}
		$larger = RuleEngine::fromJson(str_replace('"match_checks":3', '"match_checks":4', $json));
		self::assertSame(['one', 'two'], $larger->preview(['tags' => ['x', 'x']])['matched']);
	}

	public function testModelAuthorArraysAreNormalisedWithoutWeakeningPreviewValidation(): void {
		$entry = $this->getMockBuilder(FreshRSS_Entry::class)->onlyMethods(['feed'])->getMock();
		$entry->_authors(['lead' => 'Ada', 'guest' => 'Grace']);
		$entry->expects(self::never())->method('feed');
		$engine = RuleEngine::fromJson(self::rule(['field' => 'author', 'op' => 'equals', 'value' => 'Grace']));
		self::assertSame(['test'], $engine->previewEntry($entry)['matched']);
		$this->expectException(RuleException::class);
		$engine->preview(['author' => ['lead' => 'Ada']]);
	}

}
