<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Utils\Http;

use FreshRSS\Utils\Http\SettingsEmbedPolicy;
use Minz_Url;
use PHPUnit\Framework\TestCase;

final class SettingsEmbedPolicyTest extends TestCase {
	public function testFlagRequiresAuthenticatedWhitelistedRoute(): void {
		$request = ['c' => 'configure', 'a' => 'reading', 'params' => ['readerSettings' => '1']];
		self::assertTrue(SettingsEmbedPolicy::enabled($request, true));
		self::assertFalse(SettingsEmbedPolicy::enabled($request, false));
		foreach (['', 'true', 1, true, ['1']] as $flag) {
			$request['params']['readerSettings'] = $flag;
			self::assertFalse(SettingsEmbedPolicy::enabled($request, true));
		}
		foreach ([['index', 'normal'], ['reader', 'index'], ['entry', 'show'], ['auth', 'login'], ['extension', 'serve']] as [$controller, $action]) {
			$request = ['c' => $controller, 'a' => $action, 'params' => ['readerSettings' => '1']];
			self::assertFalse(SettingsEmbedPolicy::enabled($request, true));
		}
	}

	public function testCompanionMutationsOnlyEnableEmbeddingForPost(): void {
		foreach ([['feed', 'add'], ['entry', 'purge'], ['category', 'create'], ['configure', 'bookmarkQuery']] as [$controller, $action]) {
			$request = ['c' => $controller, 'a' => $action, 'params' => ['readerSettings' => '1']];
			self::assertFalse(SettingsEmbedPolicy::enabled($request, true));
			self::assertTrue(SettingsEmbedPolicy::enabled($request, true, true));
			self::assertFalse(SettingsEmbedPolicy::enabled($request, false, true));
		}
	}

	public function testFormActionsAndRedirectsPreserveOnlyEligiblePresentationFlag(): void {
		$url = ['c' => 'subscription', 'a' => 'feed', 'params' => ['id' => '12', 'ajax' => '1']];
		self::assertSame($url, SettingsEmbedPolicy::propagate($url, false));
		self::assertSame(['id' => '12', 'ajax' => '1', 'readerSettings' => '1'],
			SettingsEmbedPolicy::propagate($url, true)['params']);
		$url = ['c' => 'feed', 'a' => 'add', 'params' => []];
		self::assertSame('1', SettingsEmbedPolicy::propagate($url, true)['params']['readerSettings']);
		$url = ['c' => 'index', 'a' => 'normal', 'params' => ['get' => 'f_12', 'readerSettings' => '1']];
		self::assertSame(['get' => 'f_12'], SettingsEmbedPolicy::propagate($url, true)['params']);
		self::assertSame(['get' => 'f_12'], SettingsEmbedPolicy::propagate($url, false)['params']);
	}

	public function testUrlTransformerAppliesToNormalisedRoutesAndLeavesAssetsUnchanged(): void {
		Minz_Url::setControllerUrlTransformer(fn(array $url): array => SettingsEmbedPolicy::propagate($url, true));
		try {
			$url = Minz_Url::display(['c' => 'configure', 'a' => 'privacy', 'params' => ['#' => 'images']], 'php');
			self::assertStringContainsString('readerSettings=1', $url);
			self::assertStringEndsWith('#images', $url);
			self::assertSame(['c' => 'user', 'a' => 'profile', 'params' => ['readerSettings' => '1']],
				Minz_Url::checkControllerUrl(['c' => 'user', 'a' => 'profile']));
			self::assertStringNotContainsString('readerSettings', Minz_Url::display('/scripts/main.js'));
			self::assertStringNotContainsString('readerSettings', Minz_Url::display(['c' => 'reader', 'a' => 'index']));
		} finally {
			Minz_Url::setControllerUrlTransformer(null);
		}
	}

	public function testCspAmendmentRestrictsAncestorsWithoutLosingOtherPolicies(): void {
		$policies = ['default-src' => "'self'", 'frame-ancestors' => "'none'", 'img-src' => "'self' data: blob:"];
		self::assertSame($policies, SettingsEmbedPolicy::framePolicy($policies, false));
		$expected = $policies;
		$expected['frame-ancestors'] = "'self'";
		self::assertSame($expected, SettingsEmbedPolicy::framePolicy($policies, true));
		$policies['frame-ancestors'] = '*';
		self::assertSame("'self'", SettingsEmbedPolicy::framePolicy($policies, true)['frame-ancestors']);
	}

	public function testAdministrativeAndEmailValidationRoutesRetainExistingControllerActions(): void {
		foreach ([['user', 'manage'], ['user', 'details'], ['user', 'validateEmail'], ['update', 'index'], ['update', 'checkInstall'],
			['update', 'apply'], ['auth', 'reauth']] as [$controller, $action]) {
			self::assertTrue(SettingsEmbedPolicy::allowed($controller, $action));
			self::assertTrue(method_exists('FreshRSS_' . $controller . '_Controller', $action . 'Action'));
		}
		foreach ([['user', 'create'], ['user', 'sendValidationEmail'], ['update', 'check']] as [$controller, $action]) {
			self::assertFalse(SettingsEmbedPolicy::allowed($controller, $action));
			self::assertTrue(SettingsEmbedPolicy::allowed($controller, $action, true));
			self::assertTrue(method_exists('FreshRSS_' . $controller . '_Controller', $action . 'Action'));
		}
		self::assertFalse(SettingsEmbedPolicy::allowed('user', 'delete', true));
	}

	public function testClassicEditorReturnUrlsUseFullDocumentsOnlyInsideSettings(): void {
		foreach ([
			['configure', 'queries', '0', [], 'configure', 'query'],
			['subscription', 'index', 12, [], 'subscription', 'feed'],
			['subscription', 'index', '12', ['type' => 'category'], 'category', 'update'],
			['tag', 'index', '12', [], 'tag', 'update'],
			] as [$controller, $action, $id, $params, $expectedController, $expectedAction]) {
			$url = ['c' => $controller, 'a' => $action, 'params' => ['id' => $id] + $params];
			self::assertSame($url, SettingsEmbedPolicy::propagate($url, false));
			$embedded = SettingsEmbedPolicy::propagate($url, true);
			self::assertSame($expectedController, $embedded['c']);
			self::assertSame($expectedAction, $embedded['a']);
			self::assertSame($id, $embedded['params']['id']);
			self::assertSame('1', $embedded['params']['readerSettings']);
		}
		$url = ['c' => 'subscription', 'a' => 'index', 'params' => ['error' => 1]];
		self::assertSame('index', SettingsEmbedPolicy::propagate($url, true)['a']);
	}
}
