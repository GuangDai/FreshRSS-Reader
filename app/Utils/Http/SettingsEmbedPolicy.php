<?php
declare(strict_types=1);

namespace FreshRSS\Utils\Http;

use FreshRSS_Auth;
use Minz_Request;

/** Same-origin presentation of authenticated configuration tools inside Reader settings. */
final class SettingsEmbedPolicy {
	/** @return array<string,list<string>> */
	public static function pageRoutes(): array {
		return [
			'configure' => ['display', 'reading', 'shortcut', 'archiving', 'privacy', 'queries', 'query', 'integration', 'system', 'viewFilter'],
			'subscription' => ['index', 'add', 'feed', 'viewFilter'],
			'category' => ['update', 'viewFilter'],
			'tag' => ['index', 'update'],
			'importExport' => ['index', 'import', 'export', 'sqlite'],
			'user' => ['profile', 'manage', 'details', 'validateEmail'],
			'api' => ['updatePassword'],
			'extension' => ['index', 'catalog', 'configure', 'settings', 'enable', 'disable'],
			'auth' => ['index', 'reauth'],
			'stats' => ['index', 'feed', 'idle', 'repartition', 'unreadDates'],
			'index' => ['logs'],
			'update' => ['index', 'checkInstall', 'apply'],
		];
	}

	/** Companion form actions keep their original permission checks and CSRF validation.
	 * @return array<string,list<string>>
	 */
	public static function submitRoutes(): array {
		return [
			'configure' => ['bookmarkQuery', 'deleteQuery'],
			'category' => ['create', 'delete', 'empty', 'refreshOpml'],
			'tag' => ['add', 'delete', 'rename'],
			'feed' => ['add', 'delete', 'truncate', 'move', 'clearCache', 'reload', 'actualize', 'contentSelectorPreview'],
			'entry' => ['purge', 'optimize'],
			'extension' => ['remove'],
			'user' => ['create', 'update', 'purge', 'promote', 'demote', 'enable', 'disable', 'sendValidationEmail'],
			'update' => ['check'],
		];
	}

	public static function allowed(string $controller, string $action, bool $post = false): bool {
		return in_array($action, self::pageRoutes()[$controller] ?? [], true) ||
			($post && in_array($action, self::submitRoutes()[$controller] ?? [], true));
	}

	/**
	 * The flag never authorises a route or grants anonymous access.
	 * @param array{c:string,a:string,params:array<string,mixed>} $request
	 */
	public static function enabled(array $request, bool $hasAccess, bool $post = false): bool {
		return $hasAccess && ($request['params']['readerSettings'] ?? null) === '1' && self::allowed($request['c'], $request['a'], $post);
	}

	public static function active(): bool {
		return self::enabled(Minz_Request::currentRequest(), FreshRSS_Auth::hasAccess(), Minz_Request::isPost());
	}

	/** Distinguish a full tool document from the existing AJAX fragments. */
	public static function documentRequest(): bool {
		if (!self::active() || Minz_Request::paramBoolean('ajax')) {
			return false;
		}
		$destination = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? null;
		if (is_string($destination)) {
			return in_array($destination, ['document', 'iframe'], true);
		}
		return is_string($_SERVER['HTTP_ACCEPT'] ?? null) && str_contains($_SERVER['HTTP_ACCEPT'], 'text/html');
	}

	/**
	 * Used for generated links, form actions and HTTP redirects. Unrelated parameters are preserved.
	 * @param array{c:string,a:string,params:array<string,mixed>} $url
	 * @return array{c:string,a:string,params:array<string,mixed>}
	 */
	public static function propagate(array $url, bool $enabled): array {
		$id = $url['params']['id'] ?? null;
		if ($enabled && ((is_int($id) && $id >= 0) || (is_string($id) && ctype_digit($id)))) {
			// Render editor documents directly instead of restoring the classic side panel.
			if ($url['c'] === 'configure' && $url['a'] === 'queries') {
				$url['a'] = 'query';
			} elseif ((int)$id > 0 && $url['c'] === 'subscription' && $url['a'] === 'index') {
				$url['c'] = ($url['params']['type'] ?? null) === 'category' ? 'category' : 'subscription';
				$url['a'] = $url['c'] === 'category' ? 'update' : 'feed';
			} elseif ((int)$id > 0 && $url['c'] === 'tag' && $url['a'] === 'index') {
				$url['a'] = 'update';
			}
		}
		if ($enabled && self::allowed($url['c'], $url['a'], true)) {
			$url['params']['readerSettings'] = '1';
		} elseif (!self::allowed($url['c'], $url['a'], true)) {
			unset($url['params']['readerSettings']);
		}
		return $url;
	}

	/** @param array{c:string,a:string,params:array<string,mixed>} $url
	 * @return array{c:string,a:string,params:array<string,mixed>}
	 */
	public static function amendUrl(array $url): array {
		return self::propagate($url, self::active());
	}

	/** @param array<string,string> $policies
	 * @return array<string,string>
	 */
	public static function framePolicy(array $policies, bool $enabled): array {
		if ($enabled) {
			$policies['frame-ancestors'] = "'self'";
		}
		return $policies;
	}
}
