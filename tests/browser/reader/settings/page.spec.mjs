import { test as base, expect } from '../../fixtures/reader.fixture.mjs';
import { chooseAppearance } from '../../helpers/appearance.mjs';

const mockAccount = () => ({
	schema: {
		atomic: true,
		fields: {
			'mark_when.article': { type: 'boolean', group: 'reading' },
			'mark_when.site': { type: 'boolean', group: 'reading' },
			'archiving.keep_period': { type: 'duration', group: 'cleanup', unit: 'iso8601', disabledValue: false },
			'archiving.keep_max': { type: 'integer_or_false', group: 'cleanup', min: 0, unit: 'articles', disabledValue: false },
			'archiving.keep_min': { type: 'integer_or_false', group: 'cleanup', min: 0, unit: 'articles', disabledValue: false },
			'archiving.keep_favourites': { type: 'boolean', group: 'cleanup' },
			'archiving.keep_labels': { type: 'boolean', group: 'cleanup' },
			'archiving.keep_unreads': { type: 'boolean', group: 'cleanup' },
			"ttl_default": { type: 'integer', group: 'refresh', min: 0, max: 100000000, unit: 'seconds' },
			"send_referrer_allowlist": { type: 'string_list', group: 'privacy' },
		},
	},
	values: {
		'mark_when.article': false,
		'mark_when.site': false,
		'archiving.keep_period': 'P3M',
		'archiving.keep_max': false,
		'archiving.keep_min': 50,
		'archiving.keep_favourites': true,
		'archiving.keep_labels': true,
		'archiving.keep_unreads': false,
		"ttl_default": 3600,
		"send_referrer_allowlist": [],
	},
});

const test = base.extend({
	pageErrors: [async ({ page }, use) => {
		const errors = []; page.on('pageerror', error => errors.push(error.message));
		await use(errors); expect(errors).toEqual([]);
	}, { auto: true }],
	settingsReader: async ({ page, reader, pageErrors }, use) => {
		const account = mockAccount(); const requests = [];
		const storage = {
			database: { type: 'sqlite', bytes: 1048576 },
			articles: { total: 4, read: 0, unread: 4, favourites: 0 },
			feeds: 2,
			backups: { sqliteAvailable: false, archives: [], bytes: 0 },
		};
		await page.route('**/i/**', async route => {
			const request = route.request(); const url = new URL(request.url()); const action = url.searchParams.get('a');
			if (url.searchParams.get('c') !== 'reader' || !['accountSettings', 'storage'].includes(action)) return route.fallback();
			const body = request.method() === 'POST' ? request.postDataJSON() : null; requests.push({ action, body });
			if (action === 'storage') return route.fulfill({ json: { version: 1, storage } });
			if (body) Object.assign(account.values, body.values);
			return route.fulfill({ json: { version: 1, ...account } });
		});
		await use({ ...reader, requests, account, storage, pageErrors });
	},
});

async function selectSection(page, section) {
	const desktop = page.locator(`[data-settings-section="${section}"]`);
	if (await desktop.isVisible()) await desktop.click();
	else await page.locator('#settings-section-select').selectOption(section);
	await expect(page.locator(`[data-settings-panel="${section}"]`)).toBeVisible();
}

test('reading sidebar contains only reading navigation and settings opens a full page', async ({ page, settingsReader }) => {
	await expect(page.locator('#reader-sidebar .subscriptions-tools, #reader-sidebar #toggle-feed-selection, .sidebar-bottom')).toHaveCount(0);
	await expect(page.locator('#reader-sidebar [data-select-feed]')).toHaveCount(2);
	await expect(page.locator('#reader-sidebar [data-select-feed]:visible')).toHaveCount(0);
	await page.locator('#open-settings').click();
	await expect(page.locator('#settings-page')).toBeVisible();
	await expect(page.locator('.reader-shell')).toBeHidden();
	await expect(page.locator('#settings-heading')).toHaveText('设置');
	await expect(page).toHaveURL(/view=settings&section=appearance/);
	await expect(page.locator('#settings-section-heading')).toBeFocused();
	expect(settingsReader.pageErrors).toEqual([]);
});

test('settings deep link, refresh, Back and Forward preserve the current category', async ({ page, settingsReader }) => {
	const url = new URL(page.url()); url.searchParams.set('view', 'settings'); url.searchParams.set('section', 'storage');
	await page.goto(url.href);
	await expect(page.locator('[data-settings-panel="storage"]')).toBeVisible();
	await expect(page.locator('#settings-storage-stats')).toContainText('数据库');
	const depth = await page.evaluate(() => globalThis.history.length);
	await page.reload();
	await expect(page.locator('[data-settings-panel="storage"]')).toBeVisible();
	expect(await page.evaluate(() => globalThis.history.length)).toBe(depth);
	await selectSection(page, 'subscriptions');
	await page.goBack(); await expect(page.locator('#settings-page')).toBeHidden();
	await expect(page.locator('.reader-shell')).toBeVisible();
	await page.goForward(); await expect(page.locator('[data-settings-panel="subscriptions"]')).toBeVisible();
	expect(settingsReader.pageErrors).toEqual([]);
});

test('reader draft survives section changes and save stays on the settings page', async ({ page, settingsReader }) => {
	await page.locator('#open-settings').click();
	await chooseAppearance(page, 'theme', 'nord');
	await selectSection(page, 'reading'); await page.locator('[name=fontSize]').fill('21');
	await selectSection(page, 'subscriptions');
	await expect(page.locator('#save-reader-settings')).toBeEnabled();
	await selectSection(page, 'appearance');
	await expect(page.locator('input[type=radio][name=theme]:checked')).toHaveValue('nord');
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('#save-reader-settings')).toBeDisabled();
	await expect(page.locator('#settings-page')).toBeVisible();
	await expect(page.locator('[data-settings-panel="appearance"]')).toBeVisible();
	expect(settingsReader.preferences.theme).toBe('nord'); expect(settingsReader.preferences.fontSize).toBe(21);
	const write = settingsReader.writes.find(write => write.action === 'preferences');
	expect(Object.keys(write.body.preferences).sort()).toEqual(Object.keys(settingsReader.bootstrap.schema).sort());
});

test('search finds a field in another category and focuses it', async ({ page, settingsReader }) => {
	await page.locator('#open-settings').click();
	await page.locator('#settings-search').fill('字号');
	await expect(page.locator('[data-focus-preference="fontSize"]')).toBeVisible();
	await page.locator('[data-focus-preference="fontSize"]').click();
	await expect(page.locator('[data-settings-panel="reading"]')).toBeVisible();
	await expect(page.locator('[name=fontSize]')).toBeFocused();
	await expect(page.locator('#settings-search')).toHaveValue('');
	expect(settingsReader.requests.filter(request => request.body)).toHaveLength(0);
});

test('choosing a category leaves search results and restores its actual controls', async ({ page, settingsReader }) => {
	await page.locator('#open-settings').click(); await page.locator('#settings-search').fill('字号');
	const navigation = page.locator('[data-settings-section="subscriptions"]');
	if (await navigation.isVisible()) await navigation.click(); else await page.locator('#settings-section-select').selectOption('subscriptions');
	await expect(page.locator('#settings-search')).toHaveValue('');
	await expect(page.locator('#settings-search-results')).toBeHidden();
	await expect(page.locator('#settings-feed-search')).toBeVisible();
	expect(settingsReader.pageErrors).toEqual([]);
});

test('invalid reader draft reveals its category and failed saves preserve the draft', async ({ page, settingsReader }) => {
	await page.locator('#open-settings').click(); await selectSection(page, 'reading');
	await page.locator('[name=fontSize]').fill('3'); await selectSection(page, 'appearance');
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('[data-settings-panel="reading"]')).toBeVisible();
	await expect(page.locator('[name=fontSize]')).toBeFocused();
	await expect(page.locator('[name=fontSize]')).toHaveAttribute('aria-invalid', 'true');
	expect(settingsReader.writes).toHaveLength(0);
	await page.locator('[name=fontSize]').fill('21');
	await page.route('**/i/?c=reader&a=preferences*', route => route.fulfill({ status: 503, json: { version: 1, error: 'write_failed' } }));
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('#save-reader-settings')).toBeEnabled();
	await expect(page.locator('[name=fontSize]')).toHaveValue('21');
	await expect(page.locator('#settings-page')).toBeVisible();
	await expect(page.locator('#settings-status')).toBeVisible();
});

test('returning to reading and Forward retain an unsaved reader draft without applying it to reading', async ({ page, settingsReader }) => {
	await page.locator('#open-settings').click(); await chooseAppearance(page, 'theme', 'nord');
	await selectSection(page, 'reading'); await page.locator('[name=fontSize]').fill('21');
	await page.locator('#cancel-settings').click();
	await expect(page.locator('#settings-page')).toBeHidden();
	await expect(page.locator('body')).toHaveAttribute('data-theme', settingsReader.preferences.theme);
	await page.goForward(); await expect(page.locator('[data-settings-panel="reading"]')).toBeVisible();
	await expect(page.locator('[name=fontSize]')).toHaveValue('21');
	await expect(page.locator('input[type=radio][name=theme]:checked')).toHaveValue('nord');
	await expect(page.locator('#save-reader-settings')).toBeEnabled();
	await expect(page.locator('body')).toHaveAttribute('data-theme', settingsReader.preferences.theme);
	expect(settingsReader.writes).toEqual([]);
});

test('native iframe drafts survive hiding and decline replacing the tool without losing its document', async ({ page, settingsReader }) => {
	const requests = [];
	await page.route('**/i/**', async route => {
		const url = new URL(route.request().url());
		if (!url.searchParams.has('readerSettings')) return route.fallback();
		requests.push(url.href);
		const config = JSON.stringify({ defaultController: 'index', defaultAction: 'index', pages: { configure: ['display'], user: ['profile'] }, submits: {} });
		return route.fulfill({
			contentType: 'text/html; charset=utf-8',
			body: '<!doctype html><html><head><title>Native tool</title></head><body>' +
			'<form method="post"><label for="native-field">快捷键</label><input id="native-field" value="n">' +
			'<input type="hidden" name="_csrf" value="fixture-csrf"></form>' +
			'<script>window.nativeFixtureIdentity = Math.random();</script>' +
			'<script type="application/json" id="reader-settings-embed-config">' + config + '</script>' +
			'<script src="/scripts/reader/embed/settings-embed.js"></script></body></html>'
		});
	});
	await page.locator('#open-settings').click(); await selectSection(page, 'appearance');
	await page.locator('[data-tool-id="language-timezone"]').click();
	const frame = page.frameLocator('#settings-tool-frame');
	await frame.locator('#native-field').fill('q');
	await expect(page.locator('[data-resume-settings-tool]')).toHaveCount(1);
	const identity = await frame.locator('body').evaluate(() => globalThis.nativeFixtureIdentity);
	await page.locator('#close-settings-tool').click(); await expect(page.locator('#settings-embedded')).toBeHidden();
	await expect(page.locator('#settings-tool-frame')).toHaveAttribute('src', /readerSettings=1/);
	await page.locator('[data-resume-settings-tool]').click();
	await expect(frame.locator('#native-field')).toHaveValue('q');
	expect(await frame.locator('body').evaluate(() => globalThis.nativeFixtureIdentity)).toBe(identity);
	expect(requests).toHaveLength(1);
	await page.locator('#close-settings-tool').click(); await selectSection(page, 'privacy');
	const prompts = []; page.once('dialog', async dialog => { prompts.push(dialog.message()); await dialog.dismiss(); });
	await page.locator('[data-tool-id="profile"]').click();
	expect(prompts).toHaveLength(1); await expect(page.locator('#settings-embedded')).toBeHidden();
	expect(await frame.locator('body').evaluate(() => globalThis.nativeFixtureIdentity)).toBe(identity);
	expect(requests).toHaveLength(1);
	await selectSection(page, 'appearance'); await page.locator('[data-resume-settings-tool]').click();
	await expect(frame.locator('#native-field')).toHaveValue('q');
	await page.locator('#cancel-settings').click(); await expect(page.locator('#settings-page')).toBeHidden();
	await page.goForward(); await expect(page.locator('[data-settings-panel="appearance"]')).toBeVisible();
	await page.locator('[data-resume-settings-tool]').click(); await expect(frame.locator('#native-field')).toHaveValue('q');
	expect(settingsReader.writes).toEqual([]);
});

test('subscription management owns filtering and selection, independent of reading feeds', async ({ page, settingsReader }) => {
	await page.locator('#open-settings').click(); await selectSection(page, 'subscriptions');
	await expect(page.locator('#settings-feed-list [data-select-feed]')).toHaveCount(2);
	const target = page.locator('#settings-feed-list [data-select-feed-target="1"]'); const box = await target.boundingBox();
	expect(box.width).toBeGreaterThanOrEqual(44); expect(box.height).toBeGreaterThanOrEqual(44);
	await target.click({ position: { x: 2, y: 2 } }); await expect(page.locator('#settings-feed-list [data-select-feed="1"]')).toBeChecked();
	await target.click({ position: { x: 2, y: 2 } }); await expect(page.locator('#settings-feed-list [data-select-feed="1"]')).not.toBeChecked();
	await page.locator('#settings-feed-search').fill('阮一峰');
	await expect(page.locator('#settings-feed-list [data-settings-feed-row]:visible')).toHaveCount(1);
	await page.locator('#select-visible-feeds').click();
	await expect(page.locator('#feed-selection-count')).toContainText('1');
	await expect(page.locator('#settings-feed-list [data-select-feed="2"]')).toBeChecked();
	await page.locator('#settings-feed-search').fill('');
	await expect(page.locator('#settings-feed-list [data-select-feed="1"]')).not.toBeChecked();
	await page.locator('#edit-selected-feeds').click(); await expect(page.locator('#feed-settings-dialog')).toBeVisible();
	await page.goBack(); await expect(page.locator('#feed-settings-dialog')).toBeHidden();
	await expect(page.locator('[data-settings-panel="subscriptions"]')).toBeVisible();
	await page.locator('#cancel-settings').click(); await expect(page.locator('#settings-page')).toBeHidden();
	await expect(page.locator('#reader-sidebar [data-select-feed]:visible')).toHaveCount(0);
	expect(settingsReader.writes).toEqual([]);
});

test('subscription batch editing saves only the changed field with the selected version', async ({ page, settingsReader }) => {
	await page.locator('#open-settings').click(); await selectSection(page, 'subscriptions');
	await page.locator('#settings-feed-list [data-select-feed="1"]').check(); await page.locator('#edit-selected-feeds').click();
	await expect(page.locator('#feed-settings-fields .feed-setting-row')).toHaveCount(5);
	await page.locator('#feed-setting-ttl').fill('7200'); await page.locator('#save-feed-settings').click();
	await expect(page.locator('#save-feed-settings')).toBeDisabled();
	const write = settingsReader.writes.find(write => write.action === 'feedBatch');
	expect(write.body.ids).toEqual([1]); expect(write.body.changes).toEqual({ ttl: 7200 });
	expect(write.body.expectedVersions).toEqual({ 1: 'd'.repeat(64) });
	expect(write.body._csrf).toBe(settingsReader.bootstrap.csrf);
	await page.locator('#cancel-feed-settings').click();
	await expect(page.locator('[data-settings-panel="subscriptions"]')).toBeVisible();
	await expect(page.locator('[data-settings-feed-row="1"]')).toContainText('2 小时');
});

test('one save applies appearance and account changes across categories', async ({ page, settingsReader }) => {
	await page.locator('#open-settings').click(); await chooseAppearance(page, 'theme', 'nord');
	await selectSection(page, 'reading');
	await page.locator('[data-account-field="mark_when.article"] input[type=checkbox]').check();
	await selectSection(page, 'storage');
	await expect(page.locator('[data-account-field="archiving.keep_period"]')).toBeVisible();
	await expect(page.locator('#settings-storage-stats .settings-storage-stat')).toHaveCount(4);
	await selectSection(page, 'reading');
	await expect(page.locator('[data-account-field="mark_when.article"] input[type=checkbox]')).toBeChecked();
	await expect(page.locator('[data-account-section="reading"] button[type=submit]')).toHaveCount(0);
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('#settings-save-state')).toHaveText('设置已同步');
	await expect(page.locator('#save-reader-settings')).toBeDisabled();
	await expect(page.locator('#settings-page')).toBeVisible();
	const write = settingsReader.requests.find(request => request.body);
	expect(write.body.values).toEqual({ 'mark_when.article': true });
	await selectSection(page, 'appearance'); await expect(page.locator('input[type=radio][name=theme]:checked')).toHaveValue('nord');
	await expect(page.locator('#save-reader-settings')).toBeDisabled();
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'nord');
});

test('settings layout contains focused controls and no horizontal overflow at all sizes', async ({ page, settingsReader }, testInfo) => {
	await page.locator('#open-settings').click();
	for (const section of ['appearance', 'reading', 'subscriptions', 'filters', 'feeds', 'data', 'storage', 'privacy', 'extensions', 'automation', 'advanced']) {
		await selectSection(page, section);
		expect(await page.evaluate(() => document.documentElement.scrollWidth <= globalThis.innerWidth)).toBe(true);
		const box = await page.locator('#cancel-settings').boundingBox();
		expect(box.width).toBeGreaterThanOrEqual(44); expect(box.height).toBeGreaterThanOrEqual(44);
		await expect(page.locator('#settings-section-heading')).toBeFocused();
		await page.screenshot({ animations: 'disabled', path: testInfo.outputPath(section + '.png') });
	}
	expect(settingsReader.pageErrors).toEqual([]);
});

async function loginReviewAccount(page) {
	test.skip(!process.env.FRESHRSS_TEST_USER || !process.env.FRESHRSS_TEST_PASSWORD, 'Requires the isolated UI review account.');
	await page.goto('./?c=auth&a=login');
	await page.locator('[name=username]').fill(process.env.FRESHRSS_TEST_USER);
	await page.locator('input[type=password]').fill(process.env.FRESHRSS_TEST_PASSWORD);
	await Promise.all([
		page.waitForURL(url => url.searchParams.get('c') !== 'auth'),
		page.locator('button[type=submit]').click(),
	]);
}

async function responseJSON(response) {
	expect(response.ok()).toBe(true); return response.json();
}

test('real account exposes compatible settings and renders only supported reading controls', async ({ page }) => {
	await loginReviewAccount(page);
	const account = await responseJSON(await page.request.get('./?c=reader&a=accountSettings'));
	expect(account.schema.atomic).toBe(true);
	expect(Object.keys(account.values).sort()).toEqual(Object.keys(account.schema.fields).sort());
	for (const secret of ['password', 'api_password', 'token', 'csrf', 'auth_type']) expect(account.values).not.toHaveProperty(secret);
	await page.goto('./?view=settings&section=reading');
	await expect(page.locator('[data-account-section="reading"]')).toBeVisible();
	for (const section of ['reading', 'filters', 'storage', 'privacy', 'automation']) {
		await selectSection(page, section);
		await expect(page.locator(`[data-account-section="${section}"]`)).toBeVisible();
	}
	const names = await page.locator('[data-account-field]').evaluateAll(rows => rows.map(row => row.dataset.accountField));
	const supported = Object.entries(account.schema.fields).filter(([key, spec]) =>
		['filters', 'cleanup', 'storage', 'refresh', 'privacy'].includes(spec.group) ||
		['default_view', 'mark_when.article', 'mark_when.site', 'mark_updated_article_unread'].includes(key)).map(([key]) => key);
	expect(names.sort()).toEqual(supported.sort());
	await expect(page.locator('[data-account-section] button[type=submit]')).toHaveCount(0);
	await selectSection(page, 'storage');
	await expect(page.locator('#settings-storage-stats .settings-storage-stat')).toHaveCount(4);
	const storage = await responseJSON(await page.request.get('./?c=reader&a=storage'));
	expect(storage.database).toHaveProperty('bytes'); expect(storage.articles).toHaveProperty('total');
	expect(storage).not.toHaveProperty('path');
	await selectSection(page, 'subscriptions');
	await expect(page.locator('#settings-feed-list [data-settings-feed-row]').first()).toBeVisible();
	await expect(page.locator('#settings-page')).toBeVisible();
});

test('real retention save patches one protective value and restores the account afterward', async ({ page }) => {
	await loginReviewAccount(page);
	const original = await responseJSON(await page.request.get('./?c=reader&a=accountSettings'));
	const keepMinimum = original.values['archiving.keep_min'];
	await page.goto('./?view=settings&section=storage');
	await expect(page.locator('[data-account-section="storage"]')).toBeVisible();
	const csrf = await page.locator('#reader-bootstrap').evaluate(node => JSON.parse(node.textContent).csrf);
	const value = typeof keepMinimum === 'number' ? keepMinimum + 1 : 1;
	try {
		const row = page.locator('[data-account-field="archiving.keep_min"]');
		await row.locator('input[type=checkbox]').check(); await row.locator('input[type=number]').fill(String(value));
		const save = page.locator('#save-reader-settings');
		await expect(save).toBeEnabled();
		const postPromise = page.waitForResponse(response => response.request().method() === 'POST' &&
			new URL(response.url()).searchParams.get('a') === 'accountSettings');
		await save.click(); const post = await postPromise;
		expect(post.request().postDataJSON().values).toEqual({ 'archiving.keep_min': value });
		const saved = await responseJSON(post); expect(saved.values['archiving.keep_min']).toBe(value);
		await expect(save).toBeDisabled(); await expect(page.locator('#settings-page')).toBeVisible();
		const retained = await responseJSON(await page.request.get('./?c=reader&a=accountSettings'));
		expect(retained.values['archiving.keep_min']).toBe(value);
		for (const [key, expected] of Object.entries(original.values)) {
			if (key !== 'archiving.keep_min') expect(retained.values[key]).toEqual(expected);
		}
	} finally {
		const restored = await responseJSON(await page.request.post('./?c=reader&a=accountSettings', {
			data: { values: { 'archiving.keep_min': keepMinimum }, _csrf: csrf },
		}));
		expect(restored.values['archiving.keep_min']).toEqual(keepMinimum);
	}
});

test('real native tools remain inside settings with their original form, CSRF and phone layout', async ({ page }) => {
	await loginReviewAccount(page); await page.goto('./?view=settings&section=appearance');
	await page.locator('[data-tool-id="language-timezone"]').click();
	await expect(page.locator('#settings-embedded')).toBeVisible();
	const frame = page.frameLocator('#settings-tool-frame');
	await expect(frame.locator('html')).toHaveClass(/reader-settings-embedded/);
	await expect(page.locator('#settings-tool-status')).toBeHidden();
	await expect(frame.locator('.header')).toHaveCount(0);
	await expect(frame.locator('.post > form:first-of-type input[name=_csrf]')).toHaveCount(1);
	await expect(frame.locator('.post > form:first-of-type')).toHaveAttribute('action', /readerSettings=1/);
	await expect(frame.locator('select[name=language]')).toBeVisible();
	await expect(frame.locator('select[name=timezone]')).toBeVisible();
	await expect(frame.locator('[name=theme], [name=fontSize], [name=view_mode]')).toHaveCount(0);
	const geometry = await frame.locator('html').evaluate(html => ({ width: globalThis.innerWidth, scrollWidth: html.scrollWidth }));
	expect(geometry.scrollWidth).toBeLessThanOrEqual(geometry.width + 1);
	const saved = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).searchParams.get('a') === 'display');
	await frame.locator('button[type=submit]').first().click(); expect((await saved).status()).toBeLessThan(400);
	await expect(frame.locator('html')).toHaveClass(/reader-settings-embedded/);
	await expect(page.locator('#settings-page')).toBeVisible();
	await page.locator('#close-settings-tool').click();
	await expect(page.locator('[data-settings-panel="appearance"]')).toBeVisible();
	await expect(page.locator('#settings-embedded')).toBeHidden();
});

test('real scheduler access follows account privileges and administrator fields stay editable', async ({ page }) => {
	await loginReviewAccount(page); await page.goto('./?view=settings&section=automation');
	const administrator = await page.locator('#reader-bootstrap').evaluate(node => JSON.parse(node.textContent).canManageLimits);
	const response = await page.request.get('./?c=reader&a=scheduler');
	const tools = page.locator('[data-settings-tools="automation"]');
	if (!administrator) {
		expect(response.status()).toBe(403); await expect(tools).toContainText('仅向管理员开放');
		await expect(tools.locator('.settings-tool-form')).toHaveCount(0); return;
	}
	const data = await responseJSON(response);
	await expect(tools.locator('.settings-tool-form input, .settings-tool-form select')).toHaveCount(Object.keys(data.schema).length);
	const workers = tools.getByLabel('并发任务数', { exact: true });
	await expect(workers).toBeEnabled(); const original = await workers.inputValue();
	await workers.fill(String(Number(original) + 1));
	await expect(tools.getByRole('button', { name: '保存更改', exact: true })).toBeEnabled();
	await workers.fill(original); await expect(tools.getByRole('button', { name: '保存更改', exact: true })).toBeDisabled();
});
