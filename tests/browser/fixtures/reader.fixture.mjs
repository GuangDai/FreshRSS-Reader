import { test as base, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { dirname, extname, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const publicRoot = resolve(root, 'p');
const mimeTypes = { '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml' };

// Execute the real PHP schema and shell without loading accounts, databases or sessions.
function renderShell() {
	let php = process.env.FRESHRSS_TEST_PHP || 'php';
	if (!process.env.FRESHRSS_TEST_PHP) {
		try { execFileSync(php, ['-v'], { stdio: 'ignore' }); } catch {
			if (existsSync(resolve(root, 'bin/php'))) php = resolve(root, 'bin/php');
		}
	}
	const source = `
require 'tests/bootstrap.php';
function _t(string $key): string { return $key === 'gen.dir' ? 'ltr' : $key; }
final class FreshRSS_Entry { public const STATE_NOT_READ = 2; }
final class FreshRSS_DatabaseDAO { public const LENGTH_INDEX_UNICODE = 191; }
final class FreshRSS_Feed {
	public const TTL_DEFAULT = 0;
	public const TTL_MAX = 100000000;
	public const PRIORITY_HIDDEN = -10;
	public const PRIORITY_FEED = -5;
	public const PRIORITY_CATEGORY = 0;
	public const PRIORITY_MAIN_STREAM = 10;
	public const PRIORITY_IMPORTANT = 20;
}
final class FreshRSS_UserConfiguration {
	public function __construct(private array $values) {}
	public function toArray(): array { return $this->values; }
}
final class FreshRSS_Context {
	public static function userConf(): object { return (object)['language' => 'zh-CN']; }
}
final class FreshRSS_View {
	public array $readerBootstrap;
	public string $readerScriptNonce;
	public array $readerModuleAssets;
	public function render(): void { include 'app/views/reader/index.phtml'; }
}
$schema = FreshRSS\\Services\\Reader\\Preferences::schema();
$preferences = FreshRSS\\Services\\Reader\\Preferences::preferences([]);
$preferences['refreshInterval'] = 0;
$preferences['requestTimeout'] = 0;
$accountDefaults = require 'config-user.default.php';
$accountDefaults['mark_when']['article'] = false;
$accountDefaults['mark_when']['site'] = false;
$account = FreshRSS\\Services\\Reader\\SettingsService::describe(new FreshRSS_UserConfiguration($accountDefaults));
$bootstrap = [
	'schema' => $schema, 'preferences' => $preferences, 'csrf' => 'local-design-fixture', 'language' => 'zh-CN',
	'canManageLimits' => false, 'adjustments' => [],
	'limits' => ['max_search_chars' => 1000, 'max_settings_bytes' => 16384],
	'apiLimits' => ['maxBatchItems' => 100, 'maxRequestBytes' => 65536],
	'subscription' => FreshRSS\\Services\\Subscriptions\\ConfigurationService::schema(100),
];
$view = new FreshRSS_View();
$view->readerBootstrap = $bootstrap;
$view->readerScriptNonce = bin2hex(random_bytes(16));
$view->readerModuleAssets = FreshRSS\\Services\\Reader\\ModuleAssets::importMap();
$csp = "default-src 'self'; script-src 'self' 'nonce-" . $view->readerScriptNonce . "'; style-src 'self' 'unsafe-inline'; "
	. "img-src 'self' https: http: data:; media-src 'none'; connect-src 'self'; frame-src 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'none'";
ob_start(); $view->render(); $html = ob_get_clean();
echo json_encode(['html' => $html, 'bootstrap' => $bootstrap, 'account' => $account, 'csp' => $csp], JSON_THROW_ON_ERROR);
`;
	return JSON.parse(execFileSync(php, ['-r', source], { cwd: root, encoding: 'utf8' }));
}

export const test = base.extend({
	readerServer: [async ({ playwright }, use) => {
		const shell = renderShell();
		const server = createServer(async (request, response) => {
			const pathname = new URL(request.url, 'http://localhost').pathname;
			if (pathname === '/i/') {
				response.setHeader('Content-Security-Policy', shell.csp);
				response.setHeader('Content-Type', 'text/html; charset=utf-8'); response.end(shell.html); return;
			}
			const file = resolve(publicRoot, '.' + pathname);
			try {
				if (!file.startsWith(publicRoot + sep) || !mimeTypes[extname(file)]) throw new Error('Not a public asset');
				response.setHeader('Content-Type', mimeTypes[extname(file)]); response.end(await readFile(file));
			} catch { response.writeHead(404); response.end(); }
		});
		await new Promise(_resolve => server.listen(0, '127.0.0.1', _resolve));
		const origin = `http://127.0.0.1:${server.address().port}`;
		try { await use({ ...shell, origin }); } finally { await new Promise(_resolve => server.close(_resolve)); }
	}, { scope: 'worker' }],
	reader: async ({ page, readerServer }, use) => {
		const { origin, bootstrap, html, csp } = readerServer;
		const feeds = [
			{ id: 1, name: '少数派', categoryId: 1, unread: 5, error: false, enabled: true, ttl: 3600, priority: 10, version: 'd'.repeat(64) },
			{ id: 2, name: '阮一峰的网络日志', categoryId: 1, unread: 2, error: true, enabled: false, ttl: 0, priority: 10, version: 'd'.repeat(64) },
		];
		const items = [
			'如何为长时间阅读建立舒适的数字环境', '在城市街头重新发现步行的乐趣',
			'Designing for attention in a noisy digital world', '留出一点空间，重新发现阅读的乐趣',
		].map((title, index) => ({
			id: String(100 - index),
			title,
			feedId: index % 2 + 1,
			author: '编辑部',
			date: 1791800000 - index * 86400,
			read: false,
			starred: false,
			link: 'https://example.org/article/' + index,
		}));
		const preferences = { ...bootstrap.preferences };
		const account = structuredClone(readerServer.account);
		const writes = [];
		await page.route('**/*', async route => {
			const request = route.request();
			const url = new URL(request.url());
			if (url.origin !== origin) return route.abort();
			const action = url.searchParams.get('a');
			const controller = url.searchParams.get('c');
			const home = url.pathname === '/i/' && (!controller || controller === 'index') && (!action || action === 'index');
			if (controller !== 'reader' && !home) return route.continue();
			if (!action || home) {
				const current = JSON.stringify({ ...bootstrap, preferences }).replaceAll('<', '\\u003c');
				const body = html.replace(/(<script[^>]+id="reader-bootstrap"[^>]*>)[\s\S]*?(<\/script>)/, (_, start, end) => start + current + end);
				return route.fulfill({ body, contentType: 'text/html; charset=utf-8', headers: { 'Content-Security-Policy': csp } });
			}
			if (request.method() === 'POST') writes.push({ action, body: request.postDataJSON() });
			if (action === 'feeds') {
				return route.fulfill({ json: { version: 1, feeds, categories: [{ id: 1, name: '中文资讯' }] } });
			}
			if (action === 'items') return route.fulfill({ json: { version: 1, items, next: null } });
			if (action === 'article') {
				const item = items.find(item => item.id === url.searchParams.get('id'));
				const paragraph = '<p>明确的文字层级、恰当的行长和足够的留白，让阅读更轻松，也让内容之间的关系更容易理解。</p>';
				return route.fulfill({
					json: {
						version: 1,
						article: {
							...item, content: paragraph.repeat(12) + '<p><a href="https://example.org">阅读相关资料</a></p><pre>const reader = true;</pre>',
						}
					}
				});
			}
			if (action === 'preferences') {
				Object.assign(preferences, request.postDataJSON()?.preferences);
				return route.fulfill({ json: { version: 1, schema: bootstrap.schema, preferences } });
			}
			if (action === 'accountSettings') {
				Object.assign(account.values, request.postDataJSON()?.values);
				return route.fulfill({ json: { version: 1, ...account } });
			}
			if (action === 'storage') {
				return route.fulfill({
					json: {
						version: 1,
						database: { type: 'sqlite', bytes: 1048576 },
						articles: {
							total: items.length,
							read: items.filter(item => item.read).length,
							unread: items.filter(item => !item.read).length,
							favourites: items.filter(item => item.starred).length
						},
						feeds: feeds.length,
						backups: { sqliteAvailable: false, archives: [], bytes: 0 },
					},
				});
			}
			if (action === 'feedBatch') {
				const { ids, changes } = request.postDataJSON();
				const updated = feeds.filter(feed => ids.includes(feed.id));
				for (const feed of updated) Object.assign(feed, changes, { version: 'e'.repeat(64) });
				return route.fulfill({ json: { version: 1, atomic: true, updated: ids, feeds: updated } });
			}
			if (action === 'state') {
				const values = request.postDataJSON();
				const item = items.find(item => item.id === values.id);
				Object.assign(item, values);
				return route.fulfill({ json: { version: 1, read: item.read, starred: item.starred } });
			}
			return route.fulfill({ status: 400, json: { version: 1, error: 'unsupported_fixture_action' } });
		});
		await page.goto(origin + '/i/?c=reader');
		await expect(page.locator('.entry-card')).toHaveCount(items.length);
		await expect(page.locator('#article-list')).toHaveAttribute('aria-busy', 'false');
		await use({ bootstrap, preferences, writes, feeds, account });
	},
});

export { expect };
