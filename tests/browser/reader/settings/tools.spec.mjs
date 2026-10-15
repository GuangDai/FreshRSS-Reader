import { test as base, expect } from '../../fixtures/reader.fixture.mjs';

const emptyRules = '{"version":1,"rules":[]}';
const recipe = { format: 'html', fields: { content: [{ type: 'css', selector: 'article', mode: 'html' }] } };
const snapshots = () => ({
	RuleEngine: {
		extension: 'RuleEngine',
		version: 'rule-v1',
		redacted: ['token'],
		errors: {},
		schema: {
			rules_json: { type: 'string', default: emptyRules, maxLength: 1048576 },
			hook_priority: { type: 'integer', default: 100, min: -10000, max: 10000 },
			token: { type: 'string', secret: true }
		},
		values: { rules_json: emptyRules, hook_priority: 100 },
	},
	WebExtraction: {
		extension: 'WebExtraction',
		version: 'extract-v1',
		redacted: [],
		errors: {},
		schema: { recipes: { type: 'object', default: [], maxItems: 128, maxBytes: 1048576 } },
		values: { recipes: {} },
	},
});

const schedulerConfiguration = () => ({
	enabled: true,
	workers: 2,
	per_user: 1,
	per_host: 1,
	worker_memory_mib: 128,
	job_timeout: 180,
	kill_grace: 5,
	lease_seconds: 240,
	run_seconds: 300,
	retry_base: 60,
	retry_max: 21600,
	retry_jitter_percent: 20,
	maintenance_interval: 3600,
	websub_poll_interval: 86400,
	history_limit: 1000,
	status_history_limit: 100,
	history_days: 7,
	busy_timeout_ms: 5000,
	poll_ms: 200,
	plan_batch_size: 128,
});

const test = base.extend({
	toolPage: async ({ page, readerServer }, use) => {
		const configuration = snapshots(); const requests = []; const errors = [];
		const state = {
			conflict: false,
			extractionDelay: false,
			extractionFinish: null,
			failLoad: false,
			queue: schedulerConfiguration(),
			paused: false,
			saveFailure: false,
			saveDelay: false,
			saveFinish: null,
			schedulerFailure: false,
		};
		page.on('pageerror', error => errors.push(error.message));
		await page.route('**/*', async route => {
			const request = route.request(); const url = new URL(request.url());
			if (url.origin !== readerServer.origin) return route.abort();
			if (url.pathname !== '/i/') return route.continue();
			const action = url.searchParams.get('a');
			if (!action) {
				return route.fulfill({
					body: '<!doctype html><html lang="zh-CN"><head><meta name="viewport" content="width=device-width"></head>' +
				'<body><main><div id="tools-filters"></div><div id="tools-feeds"></div><div id="tools-automation"></div></main></body></html>',
					contentType: 'text/html; charset=utf-8'
				});
			}
			const body = request.method() === 'POST' ? request.postDataJSON() : null;
			requests.push({ action, body, params: Object.fromEntries(url.searchParams) });
			if (action === 'extensionConfig') {
				if (state.failLoad) { state.failLoad = false; return route.fulfill({ status: 503, json: { version: 1, error: 'write_failed' } }); }
				const extension = body?.extension || url.searchParams.get('extension'); const current = configuration[extension];
				if (body) {
					if (state.saveDelay) await new Promise(resolve => { state.saveFinish = resolve; });
					if (state.saveFailure) {
						return route.fulfill({ status: 400, json: { version: 1, error: 'invalid_configuration', fields: { hook_priority: 'toolsFieldInvalid' } } });
					}
					if (state.conflict) {
						state.conflict = false; current.version = 'rule-v2'; current.values.hook_priority = 150;
						return route.fulfill({ status: 409, json: { version: 1, error: 'configuration_conflict', conflicts: { [extension]: current } } });
					}
					Object.assign(current.values, body.configuration); current.version += '-saved';
				}
				return route.fulfill({ json: { version: 1, configuration: current } });
			}
			if (action === 'schema') {
				return route.fulfill({
					json: {
						version: 1,
						rulePresets: {
							author_watch: JSON.stringify({
								version: 1,
								rules: [
									{ id: 'author_watch', when: { field: 'author', op: 'equals', value: 'Example Author' }, actions: { star: true } },
								]
							})
						},
						extractionPresets: { article: recipe },
					}
				});
			}
			if (action === 'rulePreview') return route.fulfill({ json: { version: 1, preview: { matched: ['author_watch'], actions: { star: true } } } });
			if (action === 'extractionPreview') {
				if (state.extractionDelay) await new Promise(resolve => { state.extractionFinish = resolve; });
				return route.fulfill({ json: { version: 1, preview: { success: true, values: { content: '<p>Extracted</p>' }, errors: [] } } });
			}
			if (action === 'scheduler') {
				if (body && state.schedulerFailure) {
					return route.fulfill({ status: 400, json: { version: 1, error: 'invalid_field', field: 'workers' } });
				}
				if (body?.configuration) Object.assign(state.queue, body.configuration);
				if (typeof body?.paused === 'boolean') state.paused = body.paused;
				const schema = Object.fromEntries(Object.entries(schedulerConfiguration()).map(([key, value]) => [key, {
					default: value,
					min: key === 'enabled' ? undefined : 0,
					unit: key === 'enabled' ? 'boolean' : ['workers', 'per_user', 'per_host'].includes(key) ? 'processes' : 'seconds',
				}]));
				return route.fulfill({
					json: {
						version: 1,
						configuration: state.queue,
						schema,
						status: {
							paused: state.paused,
							pending: 7,
							running: 2,
							retrying: 1,
							due: 3,
							history: [{
								username: 'test-account', feed_id: 1, kind: 'feed', outcome: 'ok', finished: 1791800000, duration_ms: 12, peak_bytes: 1048576,
							}],
							historyNext: url.searchParams.has('history_before') ? null : 10
						},
					}
				});
			}
			return route.fulfill({ status: 404, json: { version: 1, error: 'unknown_action' } });
		});
		await page.goto(readerServer.origin + '/i/');
		await page.evaluate(async () => {
			const { ConfigurationSettings } = await import('/scripts/reader/settings/configuration.js');
			const { ReaderAPI } = await import('/scripts/reader/core/api.js');
			const element = (tag, className, text) => {
				const node = document.createElement(tag); if (className) node.className = className;
				if (text !== undefined) node.textContent = text; return node;
			};
			window.settingsTools = new ConfigurationSettings({
				api: new ReaderAPI('local-test-csrf', { timeoutSeconds: 0 }),
				bootstrap: { language: 'zh-CN', canManageLimits: true, apiLimits: { maxRequestBytes: 65536 } },
				store: { feeds: [{ id: 1, name: '测试订阅' }] },
				t: key => key,
				element
			}).mount();
		});
		await use({ requests, configuration, state });
		expect(errors).toEqual([]);
	},
});

async function activate(page, section) {
	await page.evaluate(section => window.settingsTools.activate(section), section);
	await expect(page.locator('#tools-' + section + ' .settings-tool-panel')).toBeVisible();
}

test('filters load lazily, expose semantic labels and save only the changed field with CSRF and the original version', async ({ page, toolPage }) => {
	expect(toolPage.requests).toEqual([]); await activate(page, 'filters');
	await page.locator('#tools-filters .advanced-settings').first().locator('summary').click();
	await page.getByLabel('处理顺序', { exact: true }).fill('200');
	await expect(page.getByRole('button', { name: '保存更改' })).toBeEnabled();
	await page.getByRole('button', { name: '保存更改' }).click();
	await expect(page.locator('#tools-filters .settings-tool-status').first()).toHaveText('更改已保存。');
	const post = toolPage.requests.find(request => request.action === 'extensionConfig' && request.body);
	expect(post.body).toEqual({ extension: 'RuleEngine', expectedVersion: 'rule-v1', configuration: { hook_priority: 200 }, _csrf: 'local-test-csrf' });
	await expect(page.getByRole('button', { name: '保存更改' })).toBeDisabled();
});

test('invalid numeric fields keep the draft, focus the invalid control and send no write', async ({ page, toolPage }) => {
	await activate(page, 'filters'); await page.locator('#tools-filters .advanced-settings').first().locator('summary').click();
	await page.getByLabel('处理顺序', { exact: true }).fill('1.5');
	await page.getByRole('button', { name: '保存更改' }).click();
	await expect(page.getByLabel('处理顺序', { exact: true })).toBeFocused();
	await expect(page.getByLabel('处理顺序', { exact: true })).toHaveAttribute('aria-invalid', 'true');
	expect(toolPage.requests.some(request => request.body)).toBe(false);
});

test('rule presets and toggles are drafts until saved; conflict review preserves local changes', async ({ page, toolPage }) => {
	await activate(page, 'filters'); await page.getByRole('button', { name: '加入草稿' }).click();
	await expect(page.getByRole('checkbox', { name: '启用 author_watch' })).toBeChecked();
	await page.getByRole('checkbox', { name: '启用 author_watch' }).uncheck();
	expect(toolPage.configuration.RuleEngine.values.rules_json).toBe(emptyRules);
	toolPage.state.conflict = true; await page.getByRole('button', { name: '保存更改' }).click();
	await expect(page.locator('.settings-tool-conflict')).toBeVisible();
	await expect(page.getByRole('button', { name: '保存更改' })).toBeDisabled();
	await page.getByRole('button', { name: '按最新配置重新审阅草稿' }).click();
	await expect(page.getByLabel('处理顺序', { exact: true })).toHaveValue('150');
	await page.getByRole('button', { name: '保存更改' }).click();
	const posts = toolPage.requests.filter(request => request.action === 'extensionConfig' && request.body);
	expect(posts[1].body.expectedVersion).toBe('rule-v2'); expect(posts[1].body.configuration).not.toHaveProperty('hook_priority');
	expect(JSON.parse(posts[1].body.configuration.rules_json).rules[0].enabled).toBe(false);
});

test('imports stay drafts and exports omit secrets; malformed documents do not replace a draft', async ({ page, toolPage }) => {
	await activate(page, 'filters'); await page.locator('#tools-filters .advanced-settings').first().locator('summary').click();
	await page.getByLabel('处理顺序', { exact: true }).fill('250');
	await page.locator('#tools-filters .advanced-settings').nth(1).locator('summary').click();
	const downloadPromise = page.waitForEvent('download'); await page.getByRole('button', { name: '导出配置', exact: true }).click();
	const download = await downloadPromise; const stream = await download.createReadStream(); const buffers = [];
	for await (const chunk of stream) buffers.push(chunk);
	const exported = JSON.parse(Buffer.concat(buffers).toString());
	expect(exported.configuration.hook_priority).toBe(250); expect(exported.configuration).not.toHaveProperty('token');
	await page.getByLabel('配置 JSON 文件').setInputFiles({
		name: 'settings.json',
		mimeType: 'application/json',
		buffer: Buffer.from(JSON.stringify({ version: 1, extension: 'RuleEngine', configuration: { hook_priority: 300 } }))
	});
	await expect(page.getByLabel('处理顺序', { exact: true })).toHaveValue('300');
	await page.getByLabel('配置 JSON 文件').setInputFiles({
		name: 'invalid.json',
		mimeType: 'application/json',
		buffer: Buffer.from(JSON.stringify({ version: 1, extension: 'Other', configuration: {} }))
	});
	await expect(page.getByLabel('处理顺序', { exact: true })).toHaveValue('300');
	expect(toolPage.requests.some(request => request.action === 'extensionConfig' && request.body)).toBe(false);
});

test('rule tests post the actual draft without saving it and stale extraction responses cannot display old results', async ({ page, toolPage }) => {
	await activate(page, 'filters'); await page.getByRole('button', { name: '加入草稿' }).click();
	await page.locator('#tools-filters .settings-tool-preview summary').click();
	await page.getByLabel('测试文章 JSON').fill('{"author":["Example Author"]}');
	await page.getByRole('checkbox', { name: '模拟更新已有文章' }).check();
	await page.locator('#tools-filters').getByRole('button', { name: '运行测试' }).click();
	await expect(page.locator('#tools-filters .settings-tool-output')).toContainText('author_watch');
	const preview = toolPage.requests.find(request => request.action === 'rulePreview');
	expect(preview.body.updated).toBe(true); expect(preview.body.entry).toEqual({ author: ['Example Author'] });
	await activate(page, 'feeds'); await page.locator('#tools-feeds').getByRole('button', { name: '加入草稿' }).click();
	await page.locator('#tools-feeds .settings-tool-preview summary').click();
	await page.getByLabel('源 HTML 或 JSON').fill('<article>Original</article>'); toolPage.state.extractionDelay = true;
	await page.locator('#tools-feeds').getByRole('button', { name: '运行测试' }).click();
	await expect.poll(() => Boolean(toolPage.state.extractionFinish)).toBe(true);
	await page.getByLabel('源 HTML 或 JSON').fill('<article>New source</article>'); toolPage.state.extractionFinish();
	await expect(page.locator('#tools-feeds .settings-tool-preview [role=status]')).toHaveText('配置或测试内容已更改，请重新运行测试。');
	await expect(page.locator('#tools-feeds .settings-tool-output')).toBeHidden();
	expect(toolPage.requests.some(request => request.action === 'extensionConfig' && request.body)).toBe(false);
});

test('queue history uses the real pagination cursor and regular users never request administrator status', async ({ page, toolPage }) => {
	await activate(page, 'automation'); await expect(page.getByRole('definition').filter({ hasText: /^7$/ })).toBeVisible();
	await page.getByRole('button', { name: '查看更早的任务' }).click();
	await expect(page.getByRole('button', { name: '查看更早的任务' })).toHaveCount(0);
	expect(toolPage.requests.filter(request => request.action === 'scheduler')[1].params.history_before).toBe('10');
	await page.evaluate(() => { window.settingsTools.dispose(); window.settingsTools.bootstrap.canManageLimits = false; });
	await activate(page, 'automation'); await expect(page.locator('#tools-automation')).toContainText('刷新队列状态仅向管理员开放');
	expect(toolPage.requests.filter(request => request.action === 'scheduler')).toHaveLength(2);
});

test('a failed load provides a local retry and session disposal clears tool content', async ({ page, toolPage }) => {
	toolPage.state.failLoad = true; await activate(page, 'filters'); await page.getByRole('button', { name: '重试', exact: true }).click();
	await expect(page.getByRole('button', { name: '保存更改' })).toBeVisible();
	await page.evaluate(() => window.settingsTools.dispose()); await expect(page.locator('[data-tool-section]')).toHaveCount(0);
});

test('all queue options are editable and pausing or refreshing status preserves an unsaved configuration', async ({ page, toolPage }) => {
	await activate(page, 'automation');
	await expect(page.locator('#tools-automation .settings-tool-form input, #tools-automation .settings-tool-form select')).toHaveCount(20);
	await page.getByLabel('并发任务数', { exact: true }).fill('4');
	await page.getByRole('button', { name: '暂停队列', exact: true }).click();
	await expect(page.getByRole('button', { name: '恢复队列', exact: true })).toBeVisible();
	await expect(page.getByLabel('并发任务数', { exact: true })).toHaveValue('4');
	expect(toolPage.state.queue.workers).toBe(2);
	await page.getByRole('button', { name: '刷新状态', exact: true }).click();
	await expect(page.getByLabel('并发任务数', { exact: true })).toHaveValue('4');
	await page.getByRole('button', { name: '保存更改', exact: true }).click();
	await expect(page.getByRole('button', { name: '保存更改', exact: true })).toBeDisabled();
	const writes = toolPage.requests.filter(request => request.action === 'scheduler' && request.body);
	expect(writes.map(request => request.body)).toEqual([
		{ paused: true, _csrf: 'local-test-csrf' }, { configuration: { workers: 4 }, _csrf: 'local-test-csrf' },
	]);
});

test('queue constraint errors reveal the advanced field and focus it without sending invalid configuration', async ({ page, toolPage }) => {
	await activate(page, 'automation'); await page.getByLabel('任务超时', { exact: true }).fill('300');
	await page.getByRole('button', { name: '保存更改', exact: true }).click();
	await expect(page.getByLabel('崩溃恢复等待', { exact: true })).toBeFocused();
	await expect(page.getByLabel('崩溃恢复等待', { exact: true })).toHaveAttribute('aria-invalid', 'true');
	await page.getByLabel('崩溃恢复等待', { exact: true }).fill('310');
	await page.getByRole('button', { name: '保存更改', exact: true }).click();
	const writes = toolPage.requests.filter(request => request.action === 'scheduler' && request.body);
	expect(writes).toHaveLength(1); expect(writes[0].body.configuration).toEqual({ job_timeout: 300, lease_seconds: 310 });
});

test('changes from another settings tool refresh untouched fields while preserving local drafts for review', async ({ page, toolPage }) => {
	await activate(page, 'filters'); await page.locator('#tools-filters .advanced-settings').first().locator('summary').click();
	toolPage.configuration.RuleEngine.values.hook_priority = 150; toolPage.configuration.RuleEngine.version = 'rule-v2';
	await page.evaluate(() => window.settingsTools.refreshExtension('filters'));
	await expect(page.getByLabel('处理顺序', { exact: true })).toHaveValue('150');
	await page.getByLabel('处理顺序', { exact: true }).fill('200');
	toolPage.configuration.RuleEngine.values.hook_priority = 175; toolPage.configuration.RuleEngine.version = 'rule-v3';
	await page.evaluate(() => window.settingsTools.refreshExtension('filters'));
	await expect(page.getByLabel('处理顺序', { exact: true })).toHaveValue('200');
	await expect(page.getByRole('button', { name: '保存更改', exact: true })).toBeDisabled();
	await expect(page.locator('.settings-tool-conflict')).toContainText('175');
	await page.getByRole('button', { name: '按最新配置重新审阅草稿' }).click();
	await expect(page.getByLabel('处理顺序', { exact: true })).toHaveValue('200');
	await expect(page.getByRole('button', { name: '保存更改', exact: true })).toBeEnabled();
	expect(toolPage.requests.some(request => request.body)).toBe(false);
});

test('server field errors focus the control after it is enabled again', async ({ page, toolPage }) => {
	await activate(page, 'filters'); await page.locator('#tools-filters .advanced-settings').first().locator('summary').click();
	await page.getByLabel('处理顺序', { exact: true }).fill('200'); toolPage.state.saveFailure = true;
	await page.getByRole('button', { name: '保存更改', exact: true }).click();
	await expect(page.getByLabel('处理顺序', { exact: true })).toBeEnabled();
	await expect(page.getByLabel('处理顺序', { exact: true })).toBeFocused();
	await expect(page.getByLabel('处理顺序', { exact: true })).toHaveAttribute('aria-invalid', 'true');
});

test('an error from a hidden section waits for that section to be opened and never steals focus', async ({ page, toolPage }) => {
	await activate(page, 'filters'); await page.locator('#tools-filters .advanced-settings').first().locator('summary').click();
	await page.getByLabel('处理顺序', { exact: true }).fill('200'); toolPage.state.saveFailure = true; toolPage.state.saveDelay = true;
	await page.getByRole('button', { name: '保存更改', exact: true }).click();
	await expect.poll(() => Boolean(toolPage.state.saveFinish)).toBe(true);
	await activate(page, 'feeds'); await page.evaluate(() => { document.getElementById('tools-filters').hidden = true; });
	await page.locator('#tools-feeds .settings-tool-preview summary').click();
	await page.getByLabel('源 HTML 或 JSON').focus(); toolPage.state.saveFinish();
	await expect(page.getByLabel('处理顺序', { exact: true })).toHaveAttribute('aria-invalid', 'true');
	await expect(page.getByLabel('源 HTML 或 JSON')).toBeFocused();
	await page.evaluate(() => { document.getElementById('tools-filters').hidden = false; }); await activate(page, 'filters');
	await expect(page.getByLabel('处理顺序', { exact: true })).toBeFocused();
});

test('server queue errors keep the original draft and focus the reenabled field', async ({ page, toolPage }) => {
	await activate(page, 'automation'); await page.getByLabel('并发任务数', { exact: true }).fill('4');
	toolPage.state.schedulerFailure = true; await page.getByRole('button', { name: '保存更改', exact: true }).click();
	await expect(page.getByLabel('并发任务数', { exact: true })).toBeEnabled();
	await expect(page.getByLabel('并发任务数', { exact: true })).toBeFocused();
	await expect(page.getByLabel('并发任务数', { exact: true })).toHaveValue('4');
	await expect(page.getByLabel('并发任务数', { exact: true })).toHaveAttribute('aria-invalid', 'true');
	expect(toolPage.state.queue.workers).toBe(2);
});
