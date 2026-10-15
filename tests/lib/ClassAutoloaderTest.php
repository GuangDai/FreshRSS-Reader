<?php
declare(strict_types=1);

use FreshRSS\Infrastructure\Concurrency\UserWorkLock;
use FreshRSS\Models\Rules\RuleSample;
use FreshRSS\Services\Reader\ApiContract;
use FreshRSS\Services\Rules\RuleEngine;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ClassAutoloaderTest extends TestCase {
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testRuleSampleLoadsBeforeTheRuleEngine(): void {
		self::assertFalse(class_exists(RuleSample::class, false));
		self::assertFalse(class_exists(RuleEngine::class, false));
		self::assertTrue(class_exists(RuleSample::class));
		self::assertSame(APP_PATH . '/Models/Rules/RuleSample.php', (new ReflectionClass(RuleSample::class))->getFileName());
		self::assertFalse(class_exists(RuleEngine::class, false));
	}

	public function testNamespacedClassesUseTheirApplicationPaths(): void {
		self::assertSame(APP_PATH . '/Services/Reader/ApiContract.php', (new ReflectionClass(ApiContract::class))->getFileName());
		self::assertSame(APP_PATH . '/Infrastructure/Concurrency/UserWorkLock.php', (new ReflectionClass(UserWorkLock::class))->getFileName());
		self::assertFalse(class_exists('FreshRSS\\Services\\Reader\\MissingClass'));
	}

	public function testLegacyClassesKeepTheirExistingPaths(): void {
		self::assertSame(APP_PATH . '/Models/Entry.php', (new ReflectionClass(FreshRSS_Entry::class))->getFileName());
		self::assertSame(APP_PATH . '/Controllers/readerController.php', (new ReflectionClass(FreshRSS_reader_Controller::class))->getFileName());
	}
}
