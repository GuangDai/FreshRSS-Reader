import { test, expect } from '@playwright/test';
import { chooseAppearance } from '../helpers/appearance.mjs';
import { readFile } from 'node:fs/promises';

const username = process.env.FRESHRSS_TEST_USER;
const password = process.env.FRESHRSS_TEST_PASSWORD;
let bootstrap;

async function selectSection(page, section) {
	const desktop = page.locator(`[data-settings-section="${section}"]`);
	if (await desktop.isVisible()) await desktop.click();
	else await page.locator('#settings-section-select').selectOption(section);
	await expect(page.locator(`[data-settings-panel="${section}"]`)).toBeVisible();
}

test.beforeEach(async ({ page }) => {
	test.skip(!username || !password, 'Use an isolated local test account with sample articles.');
	// These API and manual-state cases control every write; automatic reading has separate fixture coverage.
	await page.route(url => url.searchParams.get('c') === 'reader' && !url.searchParams.has('a'), async route => {
		const response = await route.fetch();
		const body = (await response.text()).replace(/(<script[^>]+id="reader-bootstrap"[^>]*>)([\s\S]*?)(<\/script>)/,
			(_, start, json, end) => start + JSON.stringify({ ...JSON.parse(json), autoMarkArticle: false, autoMarkSite: false }) + end);
		await route.fulfill({ response, body });
	});
	await page.goto('./?c=auth&a=login');
	await page.locator('[name=username]').fill(username);
	await page.locator('input[type=password]').fill(password);
	await Promise.all([page.waitForURL(url => url.searchParams.get('c') !== 'auth'), page.locator('button[type=submit]').click()]);
	await page.goto('./?c=reader');
	bootstrap = await page.locator('#reader-bootstrap').evaluate(node => JSON.parse(node.textContent));
	await expect(page.locator('.entry-card').first()).toBeVisible();
});

test.afterEach(async ({ page }) => {
	if (bootstrap) {
		await page.context().request.post('./?c=reader&a=preferences', { data: { _csrf: bootstrap.csrf, preferences: bootstrap.preferences } });
	}
	bootstrap = null;
});

test('layout changes reuse the current list and numeric preferences persist', async ({ page }) => {
	let listRequests = 0;
	page.on('request', request => { if (new URL(request.url()).searchParams.get('a') === 'items') listRequests++; });
	await page.locator('#quick-layout').selectOption('cards');
	await expect(page.locator('body')).toHaveAttribute('data-layout', 'cards');
	expect(listRequests).toBe(0);
	await page.locator('#open-settings').click();
	await chooseAppearance(page, 'theme', 'dark');
	await selectSection(page, 'reading');
	await page.locator('[name=pageSize]').fill('25');
	await selectSection(page, 'storage');
	await page.locator('[name=cacheArticles]').fill('0');
	await selectSection(page, 'automation');
	await page.locator('[name=requestTimeout]').fill('0');
	await page.locator('[name=refreshInterval]').fill('0');
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('#settings-page')).toBeVisible();
	await expect(page.locator('#save-reader-settings')).toBeDisabled();
	await page.locator('#cancel-settings').click();
	await expect(page.locator('#settings-page')).toBeHidden();
	await expect(page.locator('.entry-card')).toHaveCount(25);
	await page.reload();
	await expect(page.locator('.entry-card')).toHaveCount(25);
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'dark');
});

test('articles display images automatically and saved privacy settings block external images', async ({ page }) => {
	expect(bootstrap.schema.remoteImages.default).toBe(true);
	await page.context().request.post('./?c=reader&a=preferences', { data: { _csrf: bootstrap.csrf, preferences: { remoteImages: true } } });
	await page.reload();
	let externalRequests = 0;
	await page.context().route('https://example.invalid/**', route => { externalRequests++; return route.abort(); });
	await page.route('**/*c=reader&a=article*', async route => {
		const response = await route.fetch();
		const data = await response.json();
		data.article.content = '<p>Isolation fixture</p><script>parent.document.body.dataset.intruded="yes"</script>' +
			'<img src="https://example.invalid/tracker.png" onerror="alert(1)"><iframe src="https://example.invalid/frame"></iframe>' +
			'<a href="javascript:alert(1)">unsafe</a><form><input></form>';
		await route.fulfill({ json: data });
	});
	await page.locator('.entry-card').first().click();
	const frame = page.frameLocator('#article-frame');
	await expect(frame.getByText('Isolation fixture')).toBeVisible();
	await expect(frame.locator('script,iframe,form,input')).toHaveCount(0);
	await expect(frame.locator('img')).toHaveCount(1);
	await expect(frame.locator('img')).not.toHaveAttribute('onerror');
	await expect(frame.getByText('unsafe', { exact: true })).not.toHaveAttribute('href');
	await expect(frame.locator('.article-image-notice')).toHaveCount(0);
	await expect(page.locator('#load-images')).toHaveCount(0);
	await expect.poll(() => externalRequests).toBe(1);
	expect(await page.locator('body').getAttribute('data-intruded')).toBeNull();
	const contentFrame = await (await page.locator('#article-frame').elementHandle()).contentFrame();
	expect(await contentFrame.evaluate(() => { try { return Boolean(globalThis.parent.document.body); } catch { return false; } })).toBe(false);
	await page.locator('#open-settings').click();
	await selectSection(page, 'privacy');
	await page.locator('[name=remoteImages]').uncheck();
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('#save-reader-settings')).toBeDisabled();
	await page.locator('#cancel-settings').click();
	await expect(frame.locator('img')).toHaveCount(0);
	await expect(frame.locator('.article-image-notice')).toBeVisible();
	await expect(frame.locator('.article-image-notice')).toContainText(/privacy|隐私/i);
	await expect(page.locator('#load-images')).toHaveCount(0);
	expect(externalRequests).toBe(1);
	await page.reload();
	await page.locator('.entry-card').first().click();
	await expect(frame.getByText('Isolation fixture')).toBeVisible();
	await expect(frame.locator('img')).toHaveCount(0);
	await expect(frame.locator('.article-image-notice')).toBeVisible();
	await expect(page.locator('#load-images')).toHaveCount(0);
	expect(externalRequests).toBe(1);
});

test('a pending article can be closed on mobile, and state writes do not rebuild its frame', async ({ page }) => {
	await page.setViewportSize({ width: 390, height: 844 });
	let release;
	const gate = new Promise(resolve => { release = resolve; });
	await page.route('**/*c=reader&a=article*', async route => { await gate; await route.continue().catch(() => {}); }, { times: 1 });
	await page.locator('.entry-card').first().click();
	await expect(page.locator('#close-article')).toBeVisible();
	await page.locator('#close-article').click();
	await expect(page.locator('body')).toHaveAttribute('data-article-open', 'false');
	release();
	await page.locator('.entry-card').first().click();
	await expect(page.locator('#article-frame')).toBeVisible();
	const before = await page.locator('#article-frame').getAttribute('srcdoc');
	const response = page.waitForResponse(r => new URL(r.url()).searchParams.get('a') === 'state');
	await page.locator('#mark-star').click();
	expect((await response).status()).toBe(200);
	expect(await page.locator('#article-frame').getAttribute('srcdoc')).toBe(before);
	expect(await page.evaluate(() => document.documentElement.scrollWidth <= globalThis.innerWidth)).toBe(true);
	// Restore the article's original favourite state.
	const restored = page.waitForResponse(r => new URL(r.url()).searchParams.get('a') === 'state');
	await page.locator('#mark-star').click(); await restored;
});

test('a cursor article changing read state does not drop the following page', async ({ page }) => {
	const request = page.context().request;
	const initial = await (await request.get('./?c=reader&a=items&filter=unread&limit=4')).json();
	test.skip(initial.items.length < 4, 'This scenario needs four unread sample articles.');
	const cursor = initial.items[1].id;
	try {
		const changed = await request.post('./?c=reader&a=state', { data: { _csrf: bootstrap.csrf, id: cursor, read: true } });
		expect(changed.status()).toBe(200);
		const next = await (await request.get('./?c=reader&a=items&filter=unread&limit=2&cursor=' + cursor)).json();
		expect(next.items.map(item => item.id)).toEqual(initial.items.slice(2).map(item => item.id));
	} finally {
		await request.post('./?c=reader&a=state', { data: { _csrf: bootstrap.csrf, id: cursor, read: false } });
	}
});

test('mobile navigation, article focus and invalid numeric settings remain keyboard accessible', async ({ page }) => {
	await page.setViewportSize({ width: 390, height: 844 });
	await expect(page.locator('#reader-sidebar')).toHaveJSProperty('inert', true);
	await page.locator('#toggle-sidebar').click();
	await expect(page.locator('#feed-search')).toBeFocused();
	await page.keyboard.press('Escape');
	await expect(page.locator('#toggle-sidebar')).toBeFocused();
	await expect(page.locator('#reader-sidebar')).toHaveJSProperty('inert', true);
	const first = page.locator('.entry-card').first();
	await first.focus(); await page.keyboard.press('Enter');
	await expect(page.locator('#close-article')).toBeFocused();
	await expect(page.locator('.article-list-pane')).toHaveJSProperty('inert', true);
	await page.keyboard.press('Escape'); await expect(first).toBeFocused();
	await page.locator('#open-settings').click();
	await selectSection(page, 'storage');
	const cache = page.locator('[name=cacheArticles]');
	await cache.fill('');
	await selectSection(page, 'appearance');
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('#settings-page')).toBeVisible();
	await expect(page.locator('[data-settings-panel=storage]')).toBeVisible();
	await expect(cache).toHaveAttribute('aria-invalid', 'true');
	await expect(cache).toBeFocused();
	await cache.fill('0'); await expect(cache).toHaveAttribute('aria-invalid', 'false');
	await page.locator('#cancel-settings').click();
});

test('export validates numeric drafts before producing a reusable settings file', async ({ page }) => {
	const downloads = [];
	page.on('download', download => downloads.push(download));
	await page.locator('#open-settings').click();
	await selectSection(page, 'storage');
	const cache = page.locator('[name=cacheArticles]');
	await cache.fill('');
	await selectSection(page, 'data');
	await page.locator('#export-settings').click();
	await expect(cache).toHaveAttribute('aria-invalid', 'true');
	await expect(cache).toBeFocused();
	expect(downloads).toHaveLength(0);
	await cache.fill('0');
	await selectSection(page, 'appearance');
	await chooseAppearance(page, 'theme', 'nord');
	await selectSection(page, 'data');
	const downloaded = page.waitForEvent('download');
	await page.locator('#export-settings').click();
	const settings = JSON.parse(await readFile(await (await downloaded).path(), 'utf8'));
	expect(settings).toMatchObject({ format: 'freshrss-reader', version: 1, preferences: { theme: 'nord', cacheArticles: 0 } });
	await page.locator('#reset-settings').click();
	await page.locator('#import-file').setInputFiles({
		name: 'reader-settings.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(settings)),
	});
	await expect(page.locator('input[type=radio][name=theme]:checked')).toHaveValue('nord');
	await expect(cache).toHaveValue('0');
	await page.locator('#cancel-settings').click();
});

test('batch read applies to this page and offline mode disables state writes', async ({ page }) => {
	const request = page.context().request;
	const data = await (await request.get('./?c=reader&a=items&limit=' + bootstrap.preferences.pageSize)).json();
	const unread = data.items.filter(item => !item.read).map(item => item.id);
	test.skip(!unread.length, 'This scenario needs unread sample articles.');
	try {
		const response = page.waitForResponse(r => new URL(r.url()).searchParams.get('a') === 'batch');
		await page.locator('#mark-page-read').click();
		expect((await response).status()).toBe(200);
		await expect(page.locator('.entry-card[data-read=false]')).toHaveCount(0);
		await page.locator('.entry-card').first().click();
		await expect(page.locator('#mark-read')).toBeVisible();
		await page.context().setOffline(true);
		await expect(page.locator('#mark-read')).toBeDisabled();
		await expect(page.locator('#mark-star')).toBeDisabled();
	} finally {
		await page.context().setOffline(false);
		for (let start = 0; start < unread.length; start += bootstrap.apiLimits.maxBatchItems) {
			await request.post('./?c=reader&a=batch', {
				data: { _csrf: bootstrap.csrf, ids: unread.slice(start, start + bootstrap.apiLimits.maxBatchItems), read: false },
			});
		}
	}
});
