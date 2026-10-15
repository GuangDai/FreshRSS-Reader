import { test, expect } from '@playwright/test';

const username = process.env.FRESHRSS_TEST_USER;
const password = process.env.FRESHRSS_TEST_PASSWORD;
const items = Array.from({ length: 50 }, (_, index) => ({
	id: String(index + 1),
	title: `Network fixture ${index + 1}`,
	feedId: 1,
	date: 1700000000,
	read: false,
	starred: false,
	link: '',
}));

function deferred() {
	let resolve;
	const promise = new Promise(_resolve => { resolve = _resolve; });
	return { promise, resolve };
}

async function fixture(page, overrides = {}) {
	const handlers = {};
	const requests = [];
	await page.context().route('**/*', async route => {
		const url = new URL(route.request().url());
		if (url.origin !== 'http://127.0.0.1:18080') return route.abort();
		if (url.searchParams.get('c') !== 'reader') return route.continue();
		const action = url.searchParams.get('a');
		if (!action) {
			const response = await route.fetch();
			let html = await response.text();
			html = html.replace(/(<script[^>]+id="reader-bootstrap"[^>]*>)([\s\S]*?)(<\/script>)/, (match, start, json, end) => {
				const data = JSON.parse(json);
				Object.assign(data, { autoMarkArticle: false, autoMarkSite: false });
				Object.assign(data.preferences, { refreshInterval: 0, pageSize: 50, layout: 'split', ...overrides });
				return start + JSON.stringify(data) + end;
			});
			return route.fulfill({ response, body: html });
		}
		requests.push(action);
		if (handlers[action]) return handlers[action](route, url);
		if (action === 'feeds') {
			return route.fulfill({
				json: {
					version: 1,
					categories: [{ id: 1, name: 'Network fixtures' }],
					feeds: [{ id: 1, name: 'Local fixture', categoryId: 1, unread: 50, error: false }],
				}
			});
		}
		if (action === 'items') return route.fulfill({ json: { version: 1, items, next: null } });
		if (action === 'article') {
			return route.fulfill({
				json: {
					version: 1, article: { ...items.find(item => item.id === url.searchParams.get('id')), content: '<p>Local article body</p>' },
				}
			});
		}
		// No test may write preferences, article state or subscriptions to the shared account.
		return route.abort();
	});
	await page.goto('./?c=auth&a=login');
	await page.locator('[name=username]').fill(username);
	await page.locator('input[type=password]').fill(password);
	await Promise.all([page.waitForURL(url => url.searchParams.get('c') !== 'auth'), page.locator('button[type=submit]').click()]);
	await page.goto('./?c=reader');
	await expect(page.locator('.entry-card')).toHaveCount(50);
	return { handlers, requests };
}

test.beforeEach(() => {
	test.skip(!username || !password, 'Use an isolated local test account.');
});

test('a stale preference response must not close a reopened settings draft', async ({ page }) => {
	const { handlers } = await fixture(page);
	const pending = deferred();
	const started = deferred();
	handlers.preferences = async route => {
		const preferences = route.request().postDataJSON().preferences;
		started.resolve();
		await pending.promise;
		await route.fulfill({ json: { version: 1, preferences } });
	};
	await page.locator('#open-settings').click();
	await page.locator('input[type=radio][name=theme][value="dark"]').check();
	await page.locator('#save-reader-settings').click();
	await started.promise;
	await page.locator('#cancel-settings').click();
	await page.locator('#open-settings').click();
	await page.locator('input[type=radio][name=theme][value="light"]').check();
	const response = page.waitForResponse(response => new URL(response.url()).searchParams.get('a') === 'preferences');
	pending.resolve();
	await response;
	await expect(page.locator('#save-reader-settings')).toBeEnabled();
	await expect(page.locator('#settings-page')).toBeVisible();
	await expect(page.locator('input[type=radio][name=theme]:checked')).toHaveValue('light');
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'dark');
	await expect(page.locator('.appearance-live-preview > [data-appearance-preview-surface]')).toHaveAttribute('data-theme', 'light');
});

for (const change of ['edit', 'reopen']) {
	test(`a delayed server limit response preserves a ${change === 'reopen' ? 'reopened' : 'newer'} settings draft`, async ({ page }) => {
		const { handlers, requests } = await fixture(page);
		const originalTheme = await page.locator('body').getAttribute('data-theme');
		const bootstrap = await page.locator('#reader-bootstrap').evaluate(node => JSON.parse(node.textContent));
		const pending = deferred();
		const started = deferred();
		handlers.limits = async route => {
			started.resolve(); await pending.promise;
			await route.fulfill({
				json: { version: 1, limits: bootstrap.limits, schema: bootstrap.schema, preferences: bootstrap.preferences },
			});
		};
		await page.locator('#open-settings').click();
		await page.locator('input[type=radio][name=theme][value="dracula"]').check();
		const sections = page.locator('#settings-section-select');
		if (await sections.isVisible()) await sections.selectOption('advanced');
		else await page.locator('[data-settings-section=advanced]').click();
		await page.locator('.limits-details > summary').click();
		await page.locator('#save-server-limits').click();
		await started.promise;
		if (change === 'reopen') {
			await page.locator('#cancel-settings').click();
			await expect(page.locator('#settings-page')).not.toBeVisible();
			await page.locator('#open-settings').click();
		}
		if (await sections.isVisible()) await sections.selectOption('appearance');
		else await page.locator('[data-settings-section=appearance]').click();
		await page.locator('input[type=radio][name=theme][value="nord"]').check();
		const response = page.waitForResponse(response => new URL(response.url()).searchParams.get('a') === 'limits');
		pending.resolve(); await response;
		await expect(page.locator('#save-server-limits')).toBeEnabled();
		await expect(page.locator('#settings-page')).toBeVisible();
		await expect(page.locator('input[type=radio][name=theme]:checked')).toHaveValue('nord');
		await expect(page.locator('body')).toHaveAttribute('data-theme', originalTheme);
		await expect(page.locator('.appearance-live-preview > [data-appearance-preview-surface]')).toHaveAttribute('data-theme', 'nord');
		expect(requests.filter(action => action === 'limits')).toHaveLength(1);
		expect(requests.filter(action => action === 'preferences')).toHaveLength(0);
	});
}

test('a failed preference save keeps the local appearance draft without changing the saved theme', async ({ page }) => {
	const { handlers, requests } = await fixture(page);
	const originalTheme = await page.locator('body').getAttribute('data-theme');
	handlers.preferences = route => route.fulfill({ status: 500, json: { version: 1, error: 'write_failed' } });
	await page.locator('#open-settings').click();
	await page.locator('input[type=radio][name=theme][value="dracula"]').check();
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('#save-reader-settings')).toBeEnabled();
	await expect(page.locator('#settings-status')).toBeVisible();
	await expect(page.locator('#settings-page')).toBeVisible();
	await expect(page.locator('input[type=radio][name=theme]:checked')).toHaveValue('dracula');
	await expect(page.locator('body')).toHaveAttribute('data-theme', originalTheme);
	await expect(page.locator('.appearance-live-preview > [data-appearance-preview-surface]')).toHaveAttribute('data-theme', 'dracula');
	await expect(page.locator('[data-preference=theme] [data-choice-value=dracula]')).toHaveAttribute('data-selected', 'true');
	expect(requests.filter(action => action === 'preferences')).toHaveLength(1);
	await page.locator('#save-reader-settings').click();
	await expect.poll(() => requests.filter(action => action === 'preferences').length).toBe(2);
	await expect(page.locator('#save-reader-settings')).toBeEnabled();
	await expect(page.locator('#settings-status')).toBeVisible();
	await expect(page.locator('body')).toHaveAttribute('data-theme', originalTheme);
	await page.locator('#cancel-settings').click();
	await expect(page.locator('#settings-page')).not.toBeVisible();
	await expect(page.locator('body')).toHaveAttribute('data-theme', originalTheme);
});

test('rapid article changes ignore an older pending response and remain readable during a state write', async ({ page }) => {
	const { handlers, requests } = await fixture(page);
	const articlePending = deferred();
	const articleStarted = deferred();
	handlers.article = async (route, url) => {
		const id = url.searchParams.get('id');
		if (id === '1') { articleStarted.resolve(); await articlePending.promise; }
		await route.fulfill({ json: { version: 1, article: { ...items[Number(id) - 1], content: `<p>Body ${id}</p>` } } }).catch(() => {});
	};
	await page.locator('.entry-card').nth(0).click();
	await articleStarted.promise;
	await page.locator('.entry-card').nth(1).click();
	await expect(page.locator('#article-frame')).toHaveAttribute('title', 'Network fixture 2');
	articlePending.resolve();
	await expect(page.locator('#article-frame')).toHaveAttribute('title', 'Network fixture 2');
	const statePending = deferred();
	const stateStarted = deferred();
	handlers.state = async route => {
		stateStarted.resolve(); await statePending.promise;
		await route.fulfill({ json: { version: 1, id: '2', read: false, starred: true } });
	};
	await page.locator('#mark-star').click();
	await stateStarted.promise;
	await expect(page.locator('#mark-star')).toBeDisabled();
	await page.locator('.entry-card').nth(2).click();
	await expect(page.locator('#article-frame')).toHaveAttribute('title', 'Network fixture 3');
	await expect(page.locator('#mark-star')).toBeEnabled();
	statePending.resolve();
	expect(requests.filter(action => action === 'state')).toHaveLength(1);
});

test('offline article requests show an actionable retry and recover without reloading', async ({ page }) => {
	const { handlers, requests } = await fixture(page);
	handlers.article = route => route.abort('internetdisconnected');
	await page.context().setOffline(true);
	await page.locator('.entry-card').first().click();
	await expect(page.locator('#article-status')).toBeVisible();
	await expect(page.locator('#article-pane')).toHaveAttribute('aria-busy', 'false');
	await expect(page.locator('#article-status [data-retry-reader]')).toBeVisible();
	await page.context().setOffline(false);
	delete handlers.article;
	await page.locator('#article-status [data-retry-reader]').click();
	await expect(page.locator('#article-frame')).toBeVisible();
	await page.context().setOffline(true);
	await expect(page.locator('#mark-star')).toBeDisabled();
	await expect(page.locator('#mark-read')).toBeDisabled();
	expect(requests.filter(action => action === 'state')).toHaveLength(0);
	await page.context().setOffline(false);
});

test('CSRF expiry preserves reading and stops further writes until a reload', async ({ page }) => {
	const { handlers, requests } = await fixture(page);
	handlers.state = route => route.fulfill({ status: 403, json: { version: 1, error: 'csrf_invalid' } });
	await page.locator('.entry-card').first().click();
	await expect(page.locator('#article-frame')).toBeVisible();
	await page.locator('#mark-star').click();
	await expect(page.locator('#article-status [data-reload-reader]')).toBeVisible();
	await expect(page.locator('.entry-card')).toHaveCount(50);
	await expect(page.locator('.feed-button')).toHaveCount(1);
	await expect(page.locator('#mark-star')).toBeDisabled();
	await expect(page.locator('#mark-page-read')).toBeDisabled();
	await page.locator('#next-article').click();
	await expect(page.locator('#article-frame')).toHaveAttribute('title', 'Network fixture 2');
	await expect(page.locator('#article-status [data-reload-reader]')).toBeVisible();
	await expect(page.locator('#mark-read')).toBeDisabled();
	expect(requests.filter(action => action === 'state')).toHaveLength(1);
	await page.locator('#close-article').click();
	await page.locator('#open-settings').click();
	await expect(page.locator('#save-reader-settings')).toBeDisabled();
});

test('simulated page lifecycle releases pending content and ignores an interrupted preference save', async ({ page }) => {
	const { handlers, requests } = await fixture(page);
	const pending = deferred(); const started = deferred();
	handlers.preferences = async route => {
		const preferences = route.request().postDataJSON().preferences;
		started.resolve(); await pending.promise;
		await route.fulfill({ json: { version: 1, preferences } }).catch(() => {});
	};
	await page.locator('.entry-card').first().click();
	await expect(page.locator('#article-frame')).toBeVisible();
	// Keep the article in the background when phone reading covers the main toolbar.
	await page.locator('#open-settings').dispatchEvent('click');
	await page.locator('input[type=radio][name=theme][value="dark"]').check();
	await page.locator('#save-reader-settings').click();
	await started.promise;
	await page.evaluate(() => window.dispatchEvent(new window.PageTransitionEvent('pagehide', { persisted: true })));
	await expect(page.locator('#article-frame')).toBeHidden();
	await expect(page.locator('#article-frame')).not.toHaveAttribute('srcdoc');
	pending.resolve();
	await expect(page.locator('#save-reader-settings')).toBeEnabled();
	await expect(page.locator('#settings-page')).toBeVisible();
	await expect(page.locator('#settings-status')).toBeHidden();
	await page.evaluate(() => window.dispatchEvent(new window.PageTransitionEvent('pageshow', { persisted: true })));
	await page.locator('#cancel-settings').click();
	await expect(page.locator('#settings-page')).toBeHidden();
	await expect(page.locator('#article-frame')).toBeVisible();
	await expect(page.locator('#article-frame')).toHaveAttribute('title', 'Network fixture 1');
	await expect(page.locator('#article-pane')).toHaveAttribute('aria-busy', 'false');
	expect(requests.filter(action => action === 'preferences')).toHaveLength(1);
	expect(requests.filter(action => action === 'article').length).toBeGreaterThanOrEqual(2);
});
