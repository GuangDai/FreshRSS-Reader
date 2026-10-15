<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Rules;

use FreshRSS\Exceptions\Rules\RuleException;
use FreshRSS\Services\Rules\RuleEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RuleDocumentTest extends TestCase {
	private const BASE = '{"version":1,"limits":{},"rules":[
		{"id":"one","scope":{},"when":{"field":"title","op":"empty"},"actions":{"star":true}},
		{"id":"two","when":{"field":"title","op":"empty"},"actions":{"read":true}}
	]}';

	public function testBatchUpsertEnableMoveAndRemovePreserveOrder(): void {
		$result = RuleEngine::patch(self::BASE, [
			['op' => 'upsert', 'rule' => ['id' => 'three', 'when' => ['field' => 'title', 'op' => 'empty'], 'actions' => ['star' => true]]],
			['op' => 'move', 'id' => 'three', 'before' => 'one'],
			['op' => 'enable', 'id' => 'two', 'enabled' => false],
		]);
		self::assertSame(['three', 'one'], RuleEngine::fromJson($result)->preview([])['matched']);
		$result = RuleEngine::patch($result, [
			['op' => 'remove', 'id' => 'three'],
			['op' => 'upsert', 'rule' => ['id' => 'two', 'when' => ['field' => 'title', 'op' => 'empty'], 'actions' => ['read' => true]]],
			['op' => 'move', 'id' => 'one'],
		]);
		self::assertSame(['two', 'one'], RuleEngine::fromJson($result)->preview([])['matched']);
	}

	public function testPatchingRetainsJsonObjectIdentityAndNoopMoves(): void {
		$result = RuleEngine::patch(self::BASE, [['op' => 'move', 'id' => 'one', 'before' => 'one']]);
		self::assertStringContainsString('"limits":{}', $result);
		self::assertStringContainsString('"scope":{}', $result);
		self::assertSame(['one', 'two'], RuleEngine::fromJson($result)->preview([])['matched']);
		self::assertSame([], RuleEngine::validate(RuleEngine::patch(self::BASE, [])));
	}

	public function testWholeBatchMustValidateBeforeReturningAResult(): void {
		$this->expectException(RuleException::class);
		$this->expectExceptionMessage('.when.op:');
		RuleEngine::patch(self::BASE, [
			['op' => 'enable', 'id' => 'one', 'enabled' => false],
			['op' => 'upsert', 'rule' => ['id' => 'two', 'when' => ['field' => 'title', 'op' => 'arbitrary_code'], 'actions' => ['read' => true]]],
		]);
	}

	/** @return iterable<string,array{array<array-key,mixed>}> */
	public static function invalidOperations(): iterable {
		yield 'unknown operation' => [[['op' => 'run', 'code' => 'php']]];
		yield 'unknown property' => [[['op' => 'remove', 'id' => 'one', 'ignore' => true]]];
		yield 'unknown ID' => [[['op' => 'remove', 'id' => 'missing']]];
		yield 'non-string ID' => [[['op' => 'remove', 'id' => 1]]];
		yield 'missing move target' => [[['op' => 'move', 'id' => 'one', 'before' => 'missing']]];
		yield 'boolean move target' => [[['op' => 'move', 'id' => 'one', 'before' => false]]];
		yield 'non-boolean state' => [[['op' => 'enable', 'id' => 'one', 'enabled' => 'false']]];
		yield 'incomplete upsert' => [[['op' => 'upsert', 'rule' => ['id' => 'new']]]];
		yield 'scalar rule' => [[['op' => 'upsert', 'rule' => 1]]];
		yield 'list instead of object' => [[['remove', 'one']]];
		yield 'object instead of operation list' => [['op' => 'remove', 'id' => 'one']];
	}

	/** @param array<array-key,mixed> $operations */
	#[DataProvider('invalidOperations')]
	public function testInvalidOperationBatchesAreRejected(array $operations): void {
		$this->expectException(RuleException::class);
		RuleEngine::patch(self::BASE, $operations);
	}

	public function testOperationCountIsExplicitAndConfigurable(): void {
		$operations = [['op' => 'remove', 'id' => 'one'], ['op' => 'remove', 'id' => 'two']];
		self::assertSame([], RuleEngine::fromJson(RuleEngine::patch(self::BASE, $operations, 2))->preview([])['matched']);
		$this->expectException(RuleException::class);
		$this->expectExceptionMessage('at most 1 operations');
		RuleEngine::patch(self::BASE, $operations, 1);
	}

	public function testMalformedNonJsonValuesAreRejectedBeforeEditing(): void {
		$this->expectException(RuleException::class);
		$this->expectExceptionMessage('finite JSON data');
		RuleEngine::patch(self::BASE, [['op' => 'upsert', 'rule' => ['id' => INF]]]);
	}

	public function testPatchPreservesNonRuleConfiguration(): void {
		$json = '{"version":1,"limits":{"rules":1,"depth":3},"rules":[]}';
		$result = RuleEngine::patch($json, [['op' => 'upsert', 'rule' => [
			'id' => '中文规则', 'when' => ['field' => 'title', 'op' => 'empty'], 'actions' => ['star' => true],
		]]]);
		self::assertStringContainsString('"rules":1,"depth":3', $result);
		self::assertSame(['中文规则'], RuleEngine::fromJson($result)->preview([])['matched']);
	}
}
