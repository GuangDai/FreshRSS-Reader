import { test, expect } from '@playwright/test';
import { chooseAppearance } from '../helpers/appearance.mjs';

const username = process.env.FRESHRSS_TEST_USER;
const password = process.env.FRESHRSS_TEST_PASSWORD;
const items = Array.from({ length: 100 }, (_, index) => ({
	id: String(index + 1),
	title: `Navigation fixture ${index + 1}`,
	feedId: 1,
	author: 'Local fixture',
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

async function fixture(page, path = './?c=reader') {
	const handlers = {};
	const listRequests = [];
	const origin = new URL(page.url()).origin;
	await page.context().route('**/*', async route => {
		const url = new URL(route.request().url());
		if (url.origin !== origin) return route.abort();
		if (url.searchParams.get('c') !== 'reader') return route.continue();
		const action = url.searchParams.get('a');
		if (!action) {
			const response = await route.fetch();
			const html = (await response.text()).replace(
				/(<script[^>]+id="reader-bootstrap"[^>]*>)([\s\S]*?)(<\/script>)/,
				(match, start, json, end) => {
					const data = JSON.parse(json);
					// These navigation cases isolate manual state changes from inherited reading automation.
					Object.assign(data, { autoMarkArticle: false, autoMarkSite: false });
					Object.assign(data.preferences, { refreshInterval: 0, requestTimeout: 0, pageSize: 50, cacheArticles: 0, layout: 'split' });
					return start + JSON.stringify(data) + end;
				}
			);
			return route.fulfill({ response, body: html });
		}
		if (handlers[action]) return handlers[action](route, url);
		if (action === 'feeds') {
			return route.fulfill({
				json: {
					version: 1,
					categories: [{ id: 1, name: 'Navigation fixtures' }],
					feeds: [{
						id: 1,
						name: 'Local fixture',
						categoryId: 1,
						unread: 100,
						error: false,
						ttl: 3600,
						enabled: true,
						priority: 10,
						version: 'navigation-v1',
					}],
				}
			});
		}
		if (action === 'items') {
			listRequests.push(Object.fromEntries(url.searchParams));
			const secondPage = url.searchParams.get('cursor') === '50';
			return route.fulfill({ json: { version: 1, items: items.slice(secondPage ? 50 : 0, secondPage ? 100 : 50), next: secondPage ? null : '50' } });
		}
		if (action === 'article') {
			const item = items.find(item => item.id === url.searchParams.get('id'));
			return route.fulfill({ json: { version: 1, article: { ...item, content: `<p>Body for article ${item.id}</p>` } } });
		}
		// Preferences and article state stay local to this browser fixture.
		return route.abort();
	});
	await page.goto(path);
	await expect(page.locator('.entry-card')).toHaveCount(50);
	await expect(page.locator('#article-list')).toHaveAttribute('aria-busy', 'false');
	return { handlers, listRequests };
}

async function expectArticle(page, id) {
	await expect(page).toHaveURL(url => url.searchParams.get('article') === id);
	await expect(page.locator('body')).toHaveAttribute('data-article-open', 'true');
	await expect(page.locator('#article-frame')).toBeVisible();
	await expect(page.locator('#article-frame')).toHaveAttribute('title', `Navigation fixture ${id}`);
	await expect(page.frameLocator('#article-frame').getByText(`Body for article ${id}`, { exact: true })).toBeVisible();
}

async function expectClosed(page, listURL) {
	await expect(page).toHaveURL(listURL);
	await expect(page.locator('body')).toHaveAttribute('data-article-open', 'false');
	await expect(page.locator('#article-frame')).not.toBeVisible();
	await expect(page.locator('#article-pane')).toHaveAttribute('aria-busy', 'false');
	await expect(page.locator('.article-list-pane')).toHaveJSProperty('inert', false);
}

async function settingsSection(page, section) {
	const mobileSelect = page.locator('#settings-section-select');
	if (await mobileSelect.isVisible()) await mobileSelect.selectOption(section);
	else await page.locator(`[data-settings-section="${section}"]`).click();
	await expect(page.locator(`[data-settings-panel="${section}"]`)).toBeVisible();
	await expect(page).toHaveURL(url => url.searchParams.get('view') === 'settings' && url.searchParams.get('section') === section);
}

test.beforeEach(async ({ page }) => {
	test.skip(!username || !password, 'Use an isolated local test account.');
	await page.goto('./?c=auth&a=login');
	await page.locator('[name=username]').fill(username);
	await page.locator('input[type=password]').fill(password);
	await Promise.all([page.waitForURL(url => url.searchParams.get('c') !== 'auth'), page.locator('button[type=submit]').click()]);
});

test('mobile Back preserves the filtered page and scroll position, and Forward reopens the article', async ({ page }) => {
	const { listRequests } = await fixture(page);
	await page.locator('[data-filter=unread]').click();
	await expect(page.locator('[data-filter=unread]')).toHaveAttribute('aria-current', 'page');
	await page.locator('#article-search [name=search]').fill('Navigation');
	await page.locator('#article-search [name=search]').press('Enter');
	await expect(page.locator('#article-list')).toHaveAttribute('aria-busy', 'false');
	await page.locator('#next-page').click();
	await expect(page.locator('.entry-card').first()).toHaveAttribute('data-id', '51');
	await expect(page).toHaveURL(url => url.searchParams.get('cursor') === '50');
	expect(listRequests.at(-1)).toMatchObject({ get: 'a', filter: 'unread', search: 'Navigation', cursor: '50' });
	await page.setViewportSize({ width: 390, height: 844 });
	const entry = page.locator('.entry-card[data-id="63"]');
	await entry.evaluate(node => node.scrollIntoView({ block: 'center' }));
	const scrollTop = await page.locator('#article-list').evaluate(node => node.scrollTop);
	expect(scrollTop).toBeGreaterThan(0);
	const listURL = page.url();
	const pageIds = await page.locator('.entry-card').evaluateAll(nodes => nodes.map(node => node.dataset.id));
	await entry.click();
	await expectArticle(page, '63');
	await page.goBack();
	await expectClosed(page, listURL);
	await expect(page.locator('#article-search [name=search]')).toHaveValue('Navigation');
	await expect(page.locator('[data-filter=unread]')).toHaveAttribute('aria-current', 'page');
	expect(await page.locator('.entry-card').evaluateAll(nodes => nodes.map(node => node.dataset.id))).toEqual(pageIds);
	await expect.poll(async () => Math.abs(await page.locator('#article-list').evaluate(node => node.scrollTop) - scrollTop)).toBeLessThanOrEqual(1);
	await expect(entry).toBeFocused();
	await page.goForward();
	await expectArticle(page, '63');
	await expect(page.locator('#article-pane')).toBeFocused();
});

test('J and K replace the current article without adding browser history entries', async ({ page }) => {
	await fixture(page);
	const listURL = page.url();
	const listHistoryLength = await page.evaluate(() => globalThis.history.length);
	await page.locator('.entry-card[data-id="6"]').click();
	await expectArticle(page, '6');
	expect(await page.evaluate(() => globalThis.history.length)).toBe(listHistoryLength + 1);
	for (const [key, id] of [['j', '7'], ['j', '8'], ['k', '7']]) {
		await page.keyboard.press(key);
		await expectArticle(page, id);
		expect(await page.evaluate(() => globalThis.history.length)).toBe(listHistoryLength + 1);
	}
	await page.goBack();
	await expectClosed(page, listURL);
	await page.goForward();
	await expectArticle(page, '7');
});

test('J and K across page boundaries keep the latest list page without growing history', async ({ page }) => {
	await fixture(page);
	const listHistoryLength = await page.evaluate(() => globalThis.history.length);
	await page.locator('.entry-card[data-id="50"]').click();
	await expectArticle(page, '50');
	for (const [key, id, firstId] of [['j', '51', '51'], ['k', '50', '1'], ['j', '51', '51']]) {
		await page.keyboard.press(key);
		await expectArticle(page, id);
		await expect(page.locator('.entry-card').first()).toHaveAttribute('data-id', firstId);
		expect(await page.evaluate(() => globalThis.history.length)).toBe(listHistoryLength + 1);
	}
	const listURL = new URL(page.url());
	listURL.searchParams.delete('article');
	expect(listURL.searchParams.get('cursor')).toBe('50');
	await page.goBack();
	await expectClosed(page, listURL.href);
	await expect(page.locator('.entry-card').first()).toHaveAttribute('data-id', '51');
	await page.goForward();
	await expectArticle(page, '51');
});

test('closing an article and immediately opening another survives the pending Back event', async ({ page }) => {
	await fixture(page);
	const listURL = page.url();
	await page.locator('.entry-card[data-id="1"]').click();
	await expectArticle(page, '1');
	await page.evaluate(() => {
		globalThis.document.getElementById('close-article').click();
		globalThis.document.querySelector('.entry-card[data-id="2"]').click();
	});
	await expectArticle(page, '2');
	await page.goBack();
	await expectClosed(page, listURL);
	await page.goForward();
	await expectArticle(page, '2');
});

test('Back closes a pending mobile article and its late response does not reopen it', async ({ page }) => {
	await page.setViewportSize({ width: 390, height: 844 });
	const { handlers } = await fixture(page);
	const pending = deferred();
	const started = deferred();
	const delivered = deferred();
	const settled = deferred();
	const onSettled = request => {
		if (new URL(request.url()).searchParams.get('a') === 'article') settled.resolve();
	};
	page.on('requestfinished', onSettled);
	page.on('requestfailed', onSettled);
	handlers.article = async route => {
		started.resolve();
		await pending.promise;
		try {
			await route.fulfill({ json: { version: 1, article: { ...items[0], content: '<p>Body for article 1</p>' } } });
		} catch { /* A cancelled request may no longer accept a response. */ } finally { delivered.resolve(); }
	};
	const listURL = page.url();
	try {
		await page.locator('.entry-card').first().click();
		await started.promise;
		await expect(page.locator('#close-article')).toBeVisible();
		await expect(page).toHaveURL(url => url.searchParams.get('article') === '1');
		await page.goBack();
		await expectClosed(page, listURL);
		pending.resolve();
		await Promise.all([delivered.promise, settled.promise]);
		await page.evaluate(() => new Promise(resolve => globalThis.requestAnimationFrame(() => globalThis.requestAnimationFrame(resolve))));
		await expectClosed(page, listURL);
		await expect(page.locator('.entry-card').first()).toBeFocused();
	} finally {
		pending.resolve();
		page.off('requestfinished', onSettled);
		page.off('requestfailed', onSettled);
	}
});

test('an initial article deep link restores its query even when the article is outside the current page', async ({ page }) => {
	await page.setViewportSize({ width: 390, height: 844 });
	const { listRequests } = await fixture(page, './?c=reader&get=f_1&filter=unread&search=Navigation&cursor=50&article=7');
	await expectArticle(page, '7');
	expect(listRequests.at(-1)).toMatchObject({ get: 'f_1', filter: 'unread', search: 'Navigation', cursor: '50' });
	await expect(page.locator('.entry-card').first()).toHaveAttribute('data-id', '51');
	await expect(page.locator('.entry-card[data-id="7"]')).toHaveCount(0);
	await expect(page.locator('#article-search [name=search]')).toHaveValue('Navigation');
	const listURL = new URL(page.url());
	listURL.searchParams.delete('article');
	await page.goBack();
	await expectClosed(page, listURL.href);
	await expect(page.locator('#article-list')).toBeFocused();
});

test('reloading an article deep link preserves the article and does not grow history', async ({ page }) => {
	await page.setViewportSize({ width: 390, height: 844 });
	await fixture(page, './?c=reader&get=a&filter=unread&search=Navigation&cursor=50');
	const listURL = page.url();
	await page.locator('.entry-card[data-id="53"]').click();
	await expectArticle(page, '53');
	const articleURL = page.url();
	const articleHistoryLength = await page.evaluate(() => globalThis.history.length);
	await page.reload();
	await expectArticle(page, '53');
	await expect(page).toHaveURL(articleURL);
	expect(await page.evaluate(() => globalThis.history.length)).toBe(articleHistoryLength);
	await expect(page.locator('.entry-card[data-id="53"]')).toHaveAttribute('aria-current', 'true');
	await expect(page.locator('#article-search [name=search]')).toHaveValue('Navigation');
	await page.goBack();
	await expectClosed(page, listURL);
	await expect(page.locator('.entry-card').first()).toHaveAttribute('data-id', '51');
	await page.goForward();
	await expectArticle(page, '53');
});

test('returning from another page restores the article and leaves Back and Forward usable', async ({ page }) => {
	await page.setViewportSize({ width: 390, height: 844 });
	await page.addInitScript(() => {
		globalThis.addEventListener('pageshow', event => { globalThis.readerTestPageShowPersisted = event.persisted; });
	});
	await fixture(page, './?c=reader&filter=unread&search=Navigation&cursor=50');
	const listURL = page.url();
	await page.locator('.entry-card[data-id="53"]').click();
	await expectArticle(page, '53');
	const articleURL = page.url();
	await page.route('**/navigation-away', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><h1>Away page</h1>' }));
	await page.goto(new URL('./navigation-away', page.url()).href);
	await expect(page.getByRole('heading', { name: 'Away page' })).toBeVisible();
	await page.goBack();
	await expectArticle(page, '53');
	await expect(page).toHaveURL(articleURL);
	await expect(page.locator('#article-list')).toHaveAttribute('aria-busy', 'false');
	await expect(page.locator('#article-pane')).toHaveAttribute('aria-busy', 'false');
	await expect(page.locator('.entry-card').first()).toHaveAttribute('data-id', '51');
	test.info().annotations.push({ type: 'pageshow.persisted', description: String(await page.evaluate(() => globalThis.readerTestPageShowPersisted)) });
	await page.goBack();
	await expectClosed(page, listURL);
	await page.goForward();
	await expectArticle(page, '53');
});

test('mobile Back closes the sidebar before leaving the list', async ({ page }) => {
	await page.setViewportSize({ width: 390, height: 844 });
	await fixture(page);
	const listURL = page.url();
	await page.locator('#toggle-sidebar').click();
	await expect(page.locator('#feed-search')).toBeFocused();
	await expect(page.locator('body')).toHaveAttribute('data-sidebar-open', 'true');
	await page.goBack();
	await expect(page).toHaveURL(listURL);
	await expect(page.locator('body')).toHaveAttribute('data-sidebar-open', 'false');
	await expect(page.locator('#reader-sidebar')).toHaveJSProperty('inert', true);
	await expect(page.locator('.workspace')).toHaveJSProperty('inert', false);
	await expect(page.locator('#toggle-sidebar')).toBeFocused();
});

test('Back restores the saved appearance and Forward restores the settings draft without fetching the list again', async ({ page }) => {
	const { listRequests } = await fixture(page);
	const listURL = page.url();
	const requestCount = listRequests.length;
	const originalTheme = await page.locator('body').getAttribute('data-theme');
	await page.locator('#open-settings').click();
	await expect(page.locator('#settings-page')).toBeVisible();
	const originalPreference = await page.locator('input[type=radio][name=theme]:checked').inputValue();
	const draftTheme = originalTheme === 'dark' ? 'light' : 'dark';
	await chooseAppearance(page, 'theme', draftTheme);
	await expect(page.locator('body')).toHaveAttribute('data-theme', originalTheme);
	await expect(page.locator('.appearance-live-preview > [data-appearance-preview-surface]')).toHaveAttribute('data-theme', draftTheme);
	await page.goBack();
	await expect(page).toHaveURL(listURL);
	await expect(page.locator('#settings-page')).not.toBeVisible();
	await expect(page.locator('body')).toHaveAttribute('data-theme', originalTheme);
	await expect(page.locator('#open-settings')).toBeFocused();
	expect(listRequests).toHaveLength(requestCount);
	await page.goForward();
	await expect(page.locator('#settings-page')).toBeVisible();
	await expect(page.locator('input[type=radio][name=theme]:checked')).toHaveValue(draftTheme);
	await expect(page.locator('body')).toHaveAttribute('data-theme', originalTheme);
	await expect(page.locator('.appearance-live-preview > [data-appearance-preview-surface]')).toHaveAttribute('data-theme', draftTheme);
	await page.locator('#discard-settings').click();
	await expect(page.locator('input[type=radio][name=theme]:checked')).toHaveValue(originalPreference);
	await expect(page.locator('body')).toHaveAttribute('data-theme', originalTheme);
	await expect(page.locator('#discard-settings')).toBeDisabled();
	expect(listRequests).toHaveLength(requestCount);
});

for (const change of ['reopen', 'edit']) {
	test(`a delayed settings import cannot replace a ${change === 'reopen' ? 'reopened' : 'newer'} appearance draft`, async ({ page }) => {
		const { handlers, listRequests } = await fixture(page);
		const requestCount = listRequests.length;
		const originalTheme = await page.locator('body').getAttribute('data-theme');
		const writes = [];
		handlers.preferences = route => {
			writes.push(route.request().postDataJSON());
			return route.fulfill({ json: { version: 1, preferences: writes.at(-1).preferences } });
		};
		await page.locator('#open-settings').click();
		await settingsSection(page, 'data');
		await page.evaluate(() => {
			const original = globalThis.File.prototype.text;
			globalThis.readerTestImportStarted = false;
			globalThis.File.prototype.text = function () {
				if (this.name !== 'delayed-reader.json') return original.call(this);
				globalThis.readerTestImportStarted = true;
				return new Promise(resolve => {
					globalThis.readerTestFinishImport = async () => resolve(await original.call(this));
				});
			};
		});
		await page.locator('#import-file').setInputFiles({
			name: 'delayed-reader.json',
			mimeType: 'application/json',
			buffer: Buffer.from(JSON.stringify({ format: 'freshrss-reader', version: 1, preferences: { theme: 'nord' } })),
		});
		await expect.poll(() => page.evaluate(() => globalThis.readerTestImportStarted)).toBe(true);
		if (change === 'reopen') {
			await page.locator('#cancel-settings').click();
			await expect(page.locator('#settings-page')).not.toBeVisible();
			await page.locator('#open-settings').click();
		}
		await settingsSection(page, 'appearance');
		await chooseAppearance(page, 'theme', 'dracula');
		await page.evaluate(async () => {
			await globalThis.readerTestFinishImport();
			await new Promise(resolve => globalThis.requestAnimationFrame(resolve));
		});
		await expect(page.locator('#settings-page')).toBeVisible();
		await expect(page.locator('input[type=radio][name=theme]:checked')).toHaveValue('dracula');
		await expect(page.locator('body')).toHaveAttribute('data-theme', originalTheme);
		await expect(page.locator('.appearance-live-preview > [data-appearance-preview-surface]')).toHaveAttribute('data-theme', 'dracula');
		await expect(page.locator('#settings-status')).toBeHidden();
		expect(writes).toHaveLength(0);
		expect(listRequests).toHaveLength(requestCount);
	});
}

test('a local theme draft preserves article scroll until saving applies the selected appearance', async ({ page }) => {
	const { handlers, listRequests } = await fixture(page);
	const requestCount = listRequests.length;
	const writes = [];
	handlers.article = (route, url) => {
		const article = items.find(item => item.id === url.searchParams.get('id'));
		return route.fulfill({ json: { version: 1, article: { ...article, content: '<p>Reading position fixture</p>'.repeat(100) } } });
	};
	handlers.preferences = route => {
		const preferences = route.request().postDataJSON().preferences;
		writes.push(preferences);
		return route.fulfill({ json: { version: 1, preferences } });
	};
	await page.locator('.entry-card').first().click();
	await expect(page.locator('#article-frame')).toBeVisible();
	await page.frameLocator('#article-frame').locator('body').evaluate(() => globalThis.scrollTo(0, 800));
	await expect.poll(() => page.frameLocator('#article-frame').locator('html').evaluate(node => node.scrollTop)).toBe(800);
	await page.locator('#article-frame').evaluate(node => { globalThis.readerTestOriginalFrame = node; });
	const originalTheme = await page.locator('body').getAttribute('data-theme');
	const draftTheme = originalTheme === 'dracula' ? 'nord' : 'dracula';
	// Exercise the background article state while phone reading covers the main toolbar.
	await page.locator('#open-settings').dispatchEvent('click');
	await chooseAppearance(page, 'theme', draftTheme);
	await expect(page.locator('body')).toHaveAttribute('data-theme', originalTheme);
	await expect(page.locator('.appearance-live-preview > [data-appearance-preview-surface]')).toHaveAttribute('data-theme', draftTheme);
	await page.locator('#cancel-settings').click();
	await expect(page.locator('body')).toHaveAttribute('data-theme', originalTheme);
	await expect(page.locator('#article-pane iframe')).toHaveCount(1);
	expect(await page.locator('#article-frame').evaluate(node => node === globalThis.readerTestOriginalFrame)).toBe(true);
	await expect.poll(() => page.frameLocator('#article-frame').locator('html').evaluate(node => node.scrollTop)).toBe(800);
	expect(writes).toHaveLength(0);
	await page.locator('#open-settings').dispatchEvent('click');
	await expect(page.locator('input[type=radio][name=theme]:checked')).toHaveValue(draftTheme);
	await expect(page.locator('body')).toHaveAttribute('data-theme', originalTheme);
	await expect(page.locator('.appearance-live-preview > [data-appearance-preview-surface]')).toHaveAttribute('data-theme', draftTheme);
	expect(await page.locator('#article-frame').evaluate(node => node === globalThis.readerTestOriginalFrame)).toBe(true);
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('#settings-page')).toBeVisible();
	await expect(page.locator('#save-reader-settings')).toBeDisabled();
	await expect(page.locator('#article-pane iframe')).toHaveCount(1);
	await page.locator('#cancel-settings').click();
	await expect(page.locator('#settings-page')).toBeHidden();
	await expect(page.locator('body')).toHaveAttribute('data-theme', draftTheme);
	expect(writes).toHaveLength(1);
	expect(listRequests).toHaveLength(requestCount);
});

test('opening settings immediately after closing the mobile sidebar survives its pending close', async ({ page }) => {
	await page.setViewportSize({ width: 390, height: 844 });
	const { listRequests } = await fixture(page);
	const listURL = page.url();
	const listHistoryLength = await page.evaluate(() => globalThis.history.length);
	const requestCount = listRequests.length;
	await page.locator('#toggle-sidebar').click();
	await expect(page.locator('body')).toHaveAttribute('data-sidebar-open', 'true');
	await page.evaluate(() => {
		globalThis.readerTestSidebarPopped = false;
		globalThis.addEventListener('popstate', () => { globalThis.readerTestSidebarPopped = true; }, { once: true });
		globalThis.document.getElementById('close-sidebar').click();
		globalThis.document.getElementById('open-settings').click();
	});
	await expect.poll(() => page.evaluate(() => globalThis.readerTestSidebarPopped)).toBe(true);
	await expect(page.locator('#settings-page')).toBeVisible();
	await expect(page.locator('body')).toHaveAttribute('data-sidebar-open', 'false');
	await expect(page.locator('#reader-sidebar')).toHaveJSProperty('inert', true);
	expect(await page.evaluate(() => globalThis.history.length)).toBe(listHistoryLength + 1);
	await page.goBack();
	await expect(page).toHaveURL(listURL);
	await expect(page.locator('#settings-page')).not.toBeVisible();
	await expect(page.locator('body')).toHaveAttribute('data-sidebar-open', 'false');
	await expect(page.locator('.workspace')).toHaveJSProperty('inert', false);
	await expect(page.locator('#open-settings')).toBeFocused();
	await page.goForward();
	await expect(page.locator('#settings-page')).toBeVisible();
	await expect(page.locator('body')).toHaveAttribute('data-sidebar-open', 'false');
	expect(listRequests).toHaveLength(requestCount);
});

test('Back and Forward preserve the feed settings draft without fetching the list again', async ({ page }) => {
	const { listRequests } = await fixture(page);
	const requestCount = listRequests.length;
	await page.locator('#open-settings').click();
	await settingsSection(page, 'subscriptions');
	const settingsURL = page.url();
	await page.locator('#settings-feed-list [data-select-feed="1"]').check();
	await page.locator('#edit-selected-feeds').click();
	await expect(page.locator('#feed-settings-dialog')).toBeVisible();
	await page.locator('[data-change-feed-field=ttl]').check();
	await page.locator('#feed-setting-ttl').fill('60');
	await page.goBack();
	await expect(page).toHaveURL(settingsURL);
	await expect(page.locator('#settings-page')).toBeVisible();
	await expect(page.locator('#feed-settings-dialog')).not.toBeVisible();
	await expect(page.locator('#settings-feed-list [data-select-feed="1"]')).toBeChecked();
	await page.goForward();
	await expect(page.locator('#feed-settings-dialog')).toBeVisible();
	await expect(page.locator('[data-change-feed-field=ttl]')).toBeChecked();
	await expect(page.locator('#feed-setting-ttl')).toHaveValue('60');
	await expect(page.locator('#save-feed-settings')).toBeEnabled();
	expect(listRequests).toHaveLength(requestCount);
});

test('immediately reopening feed settings ignores the previous delayed close event', async ({ page }) => {
	await fixture(page);
	await page.locator('#open-settings').click();
	await settingsSection(page, 'subscriptions');
	const settingsURL = page.url();
	await page.locator('#settings-feed-list [data-select-feed="1"]').check();
	await page.locator('#edit-selected-feeds').click();
	await expect(page.locator('#feed-settings-dialog')).toBeVisible();
	await page.locator('[data-change-feed-field=ttl]').check();
	await page.locator('#feed-setting-ttl').fill('120');
	const modalHistoryLength = await page.evaluate(() => globalThis.history.length);
	await page.evaluate(() => {
		globalThis.readerTestFeedClosed = false;
		globalThis.document.getElementById('feed-settings-dialog').addEventListener('close', () => {
			globalThis.readerTestFeedClosed = true;
		}, { once: true });
		globalThis.document.getElementById('cancel-feed-settings').click();
		globalThis.document.getElementById('edit-selected-feeds').click();
	});
	await expect.poll(() => page.evaluate(() => globalThis.readerTestFeedClosed)).toBe(true);
	await expect(page.locator('#feed-settings-dialog')).toBeVisible();
	await expect(page.locator('[data-change-feed-field=ttl]')).toBeChecked();
	await expect(page.locator('#feed-setting-ttl')).toHaveValue('120');
	expect(await page.evaluate(() => globalThis.history.length)).toBe(modalHistoryLength);
	await page.goBack();
	await expect(page).toHaveURL(settingsURL);
	await expect(page.locator('#feed-settings-dialog')).not.toBeVisible();
	await page.goForward();
	await expect(page.locator('#feed-settings-dialog')).toBeVisible();
	await expect(page.locator('#feed-setting-ttl')).toHaveValue('120');
});
