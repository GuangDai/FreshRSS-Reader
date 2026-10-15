import { test, expect } from '../fixtures/reader.fixture.mjs';
import { writeFile } from 'node:fs/promises';

async function openSettings(page) {
	await page.locator('#open-settings').click();
	await expect(page.locator('#settings-page')).toBeVisible();
	await selectSection(page, 'appearance');
}

async function selectSection(page, section) {
	const desktop = page.locator(`[data-settings-section="${section}"]`);
	if (await desktop.isVisible()) await desktop.click();
	else await page.locator('#settings-section-select').selectOption(section);
	await expect(page.locator(`[data-settings-panel="${section}"]`)).toBeVisible();
}

async function observeSearch(page) {
	const searches = [];
	await page.route(url => url.searchParams.get('c') === 'reader' && url.searchParams.get('a') === 'items', async route => {
		const url = new URL(route.request().url());
		searches.push({ search: url.searchParams.get('search') || '', filter: url.searchParams.get('filter') || '' });
		await route.fallback();
	});
	const clockTime = new Date('2026-10-13T12:00:00Z');
	await page.clock.install({ time: clockTime });
	await page.clock.pauseAt(new Date(clockTime.getTime() + 1000));
	return searches;
}

async function readingAutomation(page, reader, readerServer, flags) {
	const bootstrap = { ...reader.bootstrap, preferences: reader.preferences, ...flags };
	const body = readerServer.html.replace(/(<script[^>]+id="reader-bootstrap"[^>]*>)[\s\S]*?(<\/script>)/,
		(_, start, end) => start + JSON.stringify(bootstrap).replaceAll('<', '\\u003c') + end);
	await page.route(url => url.pathname === '/i/' && [null, 'index', 'reader'].includes(url.searchParams.get('c')) && !url.searchParams.has('a'),
		route => route.fulfill({ body, contentType: 'text/html; charset=utf-8' }));
	await page.reload();
	await expect(page.locator('.entry-card')).toHaveCount(4);
}

test('public reading shows sign in and hides controls that require an account', async ({ page, reader, readerServer }) => {
	await readingAutomation(page, reader, readerServer, { readOnly: true, autoMarkArticle: false, autoMarkSite: false });
	await expect(page.locator('#sign-in')).toBeVisible();
	for (const id of ['open-settings', 'quick-layout', 'mark-page-read']) await expect(page.locator('#' + id)).toBeHidden();
	await expect(page.locator('[data-open-settings]')).toBeHidden();
	await page.locator('.entry-card').first().click();
	await expect(page.locator('#article-frame')).toBeVisible();
	await expect(page.locator('#mark-read')).toBeHidden();
	await expect(page.locator('#mark-star')).toBeHidden();
	await page.locator('#close-article').focus();
	await page.keyboard.press('m'); await page.keyboard.press('s');
	expect(reader.writes).toEqual([]);
});

test('reading starts below one toolbar and titles lead a single metadata row', async ({ page, reader }, testInfo) => {
	await expect(page.locator('.brand-caption, .toolbar .eyebrow, .list-toolbar')).toHaveCount(0);
	const mobile = await page.locator('#toggle-sidebar').isVisible();
	await expect(page.locator('#quick-layout')).toHaveValue(mobile && reader.preferences.layout === 'split' ? 'list' : reader.preferences.layout);
	await expect(page.locator('.view-heading #page-summary')).toBeVisible();
	const first = page.locator('.entry-card').first();
	const title = await first.locator('h2').boundingBox();
	const meta = await first.locator('.entry-meta').boundingBox();
	expect(title.y + title.height).toBeLessThanOrEqual(meta.y + 1);
	const metaStyle = await first.locator('.entry-meta').evaluate(node => ({
		height: node.getBoundingClientRect().height,
		lineHeight: parseFloat(globalThis.getComputedStyle(node).lineHeight) || parseFloat(globalThis.getComputedStyle(node).fontSize) * 1.5,
	}));
	expect(metaStyle.height).toBeLessThanOrEqual(metaStyle.lineHeight + 1);
	const toolbar = await page.locator('.toolbar').boundingBox();
	const list = await page.locator('#article-list').boundingBox();
	expect(list.y).toBeCloseTo(toolbar.y + toolbar.height, 0);
	expect(list.height).toBeGreaterThan(page.viewportSize().height * 0.55);
	expect(await page.locator('html').evaluate(node => node.scrollWidth <= globalThis.innerWidth)).toBe(true);
	const path = testInfo.outputPath('reader-space.json');
	const row = await first.boundingBox();
	await writeFile(path, JSON.stringify({ viewport: page.viewportSize(), toolbar, list, row, title, meta }, null, 2));
	await testInfo.attach('reader-space.json', { path, contentType: 'application/json' });
	await page.screenshot({ animations: 'disabled', path: testInfo.outputPath('reader.png') });
});

test('one layout control saves immediately without fetching articles and rolls back a failed save', async ({ page, reader }) => {
	let listRequests = 0;
	page.on('request', request => { if (new URL(request.url()).searchParams.get('a') === 'items') listRequests++; });
	const layout = page.locator('#quick-layout');
	await expect(layout.locator('option')).toHaveCount(3);
	const mobile = await page.locator('#toggle-sidebar').isVisible();
	await expect(layout.locator('option:not([hidden])')).toHaveCount(mobile ? 2 : 3);
	if (mobile) await expect(layout.locator('option[value=split]')).toBeDisabled();
	for (const value of mobile ? ['cards', 'list', 'cards'] : ['list', 'split', 'cards']) {
		await layout.selectOption(value);
		await expect(layout).toBeEnabled();
		await expect(page.locator('body')).toHaveAttribute('data-layout', value);
		await expect.poll(() => reader.preferences.layout).toBe(value);
	}
	expect(reader.writes.filter(write => write.action === 'preferences')).toHaveLength(3);
	expect(listRequests).toBe(0);
	await page.route(url => url.searchParams.get('c') === 'reader' && url.searchParams.get('a') === 'preferences',
		route => route.fulfill({ status: 500, json: { version: 1, error: 'write_failed' } }));
	await layout.selectOption('list');
	await expect(layout).toBeEnabled();
	await expect(page.locator('#status')).toBeVisible();
	await expect(layout).toHaveValue('cards');
	await expect(page.locator('body')).toHaveAttribute('data-layout', 'cards');
	expect(reader.preferences.layout).toBe('cards');
	expect(listRequests).toBe(0);
});

test('responsive layout choices match the available views and preserve a saved split layout across resize', async ({ page, reader }) => {
	let listRequests = 0;
	page.on('request', request => { if (new URL(request.url()).searchParams.get('a') === 'items') listRequests++; });
	await page.setViewportSize({ width: 1440, height: 1000 });
	const layout = page.locator('#quick-layout');
	await expect(layout).toHaveValue('split');
	await expect(layout.locator('option[value=split]')).toBeEnabled();
	await page.setViewportSize({ width: 375, height: 812 });
	await expect(layout).toHaveValue('list');
	await expect(layout.locator('option[value=split]')).toBeDisabled();
	await expect(layout.locator('option[value=split]')).toHaveAttribute('hidden', '');
	await layout.selectOption('list');
	await expect(page.locator('body')).toHaveAttribute('data-layout', 'split');
	expect(reader.preferences.layout).toBe('split');
	expect(reader.writes).toHaveLength(0);
	await page.setViewportSize({ width: 1440, height: 1000 });
	await expect(layout).toHaveValue('split');
	await expect(layout.locator('option[value=split]')).toBeEnabled();
	await expect(layout.locator('option:not([hidden])')).toHaveCount(3);
	expect(reader.writes).toHaveLength(0);
	expect(listRequests).toBe(0);
});

test('choosing the current feed again preserves the article, list position, and loaded results', async ({ page, reader }) => {
	await page.setViewportSize({ width: 1440, height: 300 });
	const feed = page.locator('[data-feed="1"]');
	await feed.click();
	await expect(page.locator('#view-title')).toHaveText('少数派');
	await expect(page.locator('#article-list')).toHaveAttribute('aria-busy', 'false');
	await page.locator('.entry-card').first().click();
	await expect(page.locator('#article-frame')).toBeVisible();
	await page.locator('#article-list').evaluate(node => { node.scrollTop = 50; });
	await expect(page.locator('#article-list')).toHaveJSProperty('scrollTop', 50);
	const frame = await page.locator('#article-frame').getAttribute('srcdoc');
	let listRequests = 0;
	page.on('request', request => { if (new URL(request.url()).searchParams.get('a') === 'items') listRequests++; });
	await feed.click();
	await expect(page.locator('body')).toHaveAttribute('data-article-open', 'true');
	await expect(page.locator('#article-list')).toHaveJSProperty('scrollTop', 50);
	expect(await page.locator('#article-frame').getAttribute('srcdoc')).toBe(frame);
	expect(listRequests).toBe(0);
	expect(reader.writes).toHaveLength(0);
});

test('choosing the current article again preserves its frame, reading position, history, and manual unread choice', async ({ page, reader, readerServer }) => {
	await page.setViewportSize({ width: 1440, height: 1000 });
	await readingAutomation(page, reader, readerServer, { autoMarkArticle: true, autoMarkSite: false });
	const entry = page.locator('.entry-card').first();
	await entry.click();
	await expect(page.locator('#article-frame')).toBeVisible();
	await expect(page.locator('#mark-read')).toHaveAttribute('aria-pressed', 'true');
	await expect(page.locator('#mark-read')).toBeEnabled();
	await page.locator('#mark-read').click();
	await expect(page.locator('#mark-read')).toHaveAttribute('aria-pressed', 'false');
	await expect(page.locator('#mark-read')).toBeEnabled();
	await page.frameLocator('#article-frame').locator('body').evaluate(() => globalThis.scrollTo(0, 500));
	await expect.poll(() => page.frameLocator('#article-frame').locator('html').evaluate(node => node.scrollTop)).toBe(500);
	await page.locator('#article-frame').evaluate(node => { globalThis.readerRefinementRepeatFrame = node; });
	const historyLength = await page.evaluate(() => globalThis.history.length);
	const writes = reader.writes.length;
	let articleRequests = 0;
	page.on('request', request => { if (new URL(request.url()).searchParams.get('a') === 'article') articleRequests++; });
	await entry.click();
	await expect(page.locator('#close-article')).toBeFocused();
	expect(await page.locator('#article-frame').evaluate(node => node === globalThis.readerRefinementRepeatFrame)).toBe(true);
	await expect.poll(() => page.frameLocator('#article-frame').locator('html').evaluate(node => node.scrollTop)).toBe(500);
	await expect(page.locator('#mark-read')).toHaveAttribute('aria-pressed', 'false');
	await expect(entry).toHaveAttribute('data-read', 'false');
	expect(await page.evaluate(() => globalThis.history.length)).toBe(historyLength);
	expect(reader.writes).toHaveLength(writes);
	expect(articleRequests).toBe(0);
});

test('choosing a pending article again reuses its request and accepts the eventual body', async ({ page, reader }) => {
	await page.setViewportSize({ width: 1440, height: 1000 });
	let release;
	let requested;
	const pending = new Promise(resolve => { release = resolve; });
	const started = new Promise(resolve => { requested = resolve; });
	let articleRequests = 0;
	await page.route(url => url.searchParams.get('a') === 'article', async route => {
		articleRequests++; requested(); await pending;
		await route.fallback().catch(() => {});
	});
	const entry = page.locator('.entry-card').first();
	try {
		await entry.click();
		await started;
		await expect(page.locator('#article-pane')).toHaveAttribute('aria-busy', 'true');
		const historyLength = await page.evaluate(() => globalThis.history.length);
		await entry.click();
		await expect(page.locator('#close-article')).toBeFocused();
		expect(articleRequests).toBe(1);
		expect(await page.evaluate(() => globalThis.history.length)).toBe(historyLength);
		release();
		await expect(page.locator('#article-frame')).toBeVisible();
		await expect(page.frameLocator('#article-frame').getByRole('heading', { name: '如何为长时间阅读建立舒适的数字环境' })).toBeVisible();
		expect(articleRequests).toBe(1);
		expect(reader.writes).toHaveLength(0);
	} finally { release(); }
});

test('single-page reading hides unavailable page actions and removes the footer when every article is read', async ({ page, reader }) => {
	await expect(page.locator('#previous-page, #next-page')).toHaveCount(2);
	await expect(page.locator('#previous-page')).toBeHidden();
	await expect(page.locator('#next-page')).toBeHidden();
	await expect(page.locator('#mark-page-read')).toBeVisible();
	const batches = [];
	let listRequests = 0;
	const readItems = ['100', '99', '98', '97'].map(id => ({ id, title: `Read article ${id}`, feedId: 1, date: 1791800000, read: true, starred: false }));
	await page.route(url => url.searchParams.get('c') === 'reader', async route => {
		const action = new URL(route.request().url()).searchParams.get('a');
		if (action === 'batch') {
			batches.push(route.request().postDataJSON());
			return route.fulfill({ json: { version: 1, updated: readItems.length } });
		}
		if (action === 'items') {
			listRequests++;
			return route.fulfill({ json: { version: 1, items: readItems, next: null } });
		}
		if (action === 'feeds') return route.fulfill({ json: { version: 1, feeds: reader.feeds.map(feed => ({ ...feed, unread: 0 })), categories: [] } });
		await route.fallback();
	});
	await page.locator('#mark-page-read').click();
	await expect.poll(() => listRequests).toBe(1);
	await expect(page.locator('#article-list')).toHaveAttribute('aria-busy', 'false');
	await expect(page.locator('.entry-card[data-read=false]')).toHaveCount(0);
	await expect(page.locator('.pagination')).toBeHidden();
	expect(batches).toHaveLength(1);
	expect(batches[0]).toMatchObject({ ids: ['100', '99', '98', '97'], read: true });
	// The batch contract refreshes authoritative state once; hiding controls never fetches another page.
	await page.evaluate(() => new Promise(resolve => globalThis.requestAnimationFrame(() => globalThis.requestAnimationFrame(resolve))));
	expect(listRequests).toBe(1);
});

test('multi-page results keep both navigation boundaries and allow moving forward and back', async ({ page, reader }) => {
	const pages = [0, 1].map(index => [0, 1].map(offset => ({
		id: String(100 - index * 2 - offset),
		title: `Page ${index + 1} article ${offset + 1}`,
		feedId: 1,
		date: 1791800000,
		read: false,
		starred: false,
	})));
	await page.route(url => url.searchParams.get('a') === 'items', route => {
		const second = Boolean(new URL(route.request().url()).searchParams.get('cursor'));
		return route.fulfill({ json: { version: 1, items: pages[second ? 1 : 0], next: second ? null : '99' } });
	});
	await page.reload();
	await expect(page.locator('.entry-card')).toHaveCount(2);
	await expect(page.locator('#previous-page')).toBeVisible();
	await expect(page.locator('#previous-page')).toBeDisabled();
	await expect(page.locator('#next-page')).toBeVisible();
	await expect(page.locator('#next-page')).toBeEnabled();
	await page.locator('#next-page').click();
	await expect(page.locator('.entry-card').first()).toHaveAttribute('data-id', '98');
	await expect(page.locator('#previous-page')).toBeVisible();
	await expect(page.locator('#previous-page')).toBeEnabled();
	await expect(page.locator('#next-page')).toBeVisible();
	await expect(page.locator('#next-page')).toBeDisabled();
	await page.locator('#previous-page').click();
	await expect(page.locator('.entry-card').first()).toHaveAttribute('data-id', '100');
	await expect(page.locator('#previous-page')).toBeDisabled();
	await expect(page.locator('#next-page')).toBeEnabled();
	expect(reader.writes).toHaveLength(0);
});

test('duplicate author names stay out of metadata and favourites use the same icon in list and article', async ({ page, reader }) => {
	const article = {
		id: '100',
		title: '没有重复署名的文章',
		feedId: 1,
		author: ' 少数派 ',
		date: 1791800000,
		read: false,
		starred: true,
		link: '',
		content: '<p>阅读正文</p>',
	};
	await page.route(url => url.searchParams.get('c') === 'reader' && ['items', 'article'].includes(url.searchParams.get('a')), route => {
		const action = new URL(route.request().url()).searchParams.get('a');
		return route.fulfill({ json: action === 'items' ? { version: 1, items: [article], next: null } : { version: 1, article } });
	});
	await page.reload();
	const entry = page.locator('.entry-card').first();
	await expect(entry.locator('.entry-author')).toBeHidden();
	expect((await entry.locator('.entry-meta').innerText()).split('少数派')).toHaveLength(2);
	await expect(entry).toHaveAccessibleName(/收藏/);
	await expect(entry.locator('.entry-state')).toBeVisible();
	await expect(entry.locator('.entry-state')).toHaveText('');
	await entry.click();
	await expect(page.locator('#mark-star')).toHaveAttribute('aria-pressed', 'true');
	const listMask = await entry.locator('.entry-state').evaluate(node => globalThis.getComputedStyle(node).maskImage);
	const articleMask = await page.locator('#mark-star .reader-icon').evaluate(node => globalThis.getComputedStyle(node).maskImage);
	expect(listMask).not.toBe('none');
	expect(listMask).toBe(articleMask);
	const byline = await page.frameLocator('#article-frame').locator('body > .meta').innerText();
	expect(byline.split('少数派')).toHaveLength(2);
	expect(reader.writes).toHaveLength(0);
});

test('article search waits for a typing pause, Enter submits once, and clearing immediately restores articles', async ({ page, reader }) => {
	const searches = await observeSearch(page);
	const search = page.locator('#article-search input');
	await search.fill('阅读');
	await page.clock.fastForward(349);
	expect(searches).toHaveLength(0);
	await page.clock.fastForward(1);
	await expect.poll(() => searches.length).toBe(1);
	expect(searches.at(-1).search).toBe('阅读');
	await search.fill('城市');
	await search.press('Enter');
	await expect.poll(() => searches.length).toBe(2);
	expect(searches.at(-1).search).toBe('城市');
	await page.clock.fastForward(600);
	expect(searches).toHaveLength(2);
	await search.fill('');
	await expect.poll(() => searches.length).toBe(3);
	expect(searches.at(-1).search).toBe('');
	await page.clock.fastForward(600);
	expect(searches).toHaveLength(3);
	expect(reader.writes).toHaveLength(0);
});

test('Chinese composition waits for completion and switching streams cancels pending search', async ({ page, reader }) => {
	const searches = await observeSearch(page);
	const search = page.locator('#article-search input');
	await search.focus();
	await search.evaluate(node => {
		node.dispatchEvent(new globalThis.CompositionEvent('compositionstart', { bubbles: true }));
		node.value = '阅读';
		node.dispatchEvent(new globalThis.InputEvent('input', { bubbles: true, data: '阅读', isComposing: true }));
	});
	await page.clock.fastForward(1000);
	expect(searches).toHaveLength(0);
	await search.evaluate(node => node.dispatchEvent(new globalThis.CompositionEvent('compositionend', { bubbles: true, data: '阅读' })));
	await page.clock.fastForward(350);
	await expect.poll(() => searches.length).toBe(1);
	expect(searches.at(-1).search).toBe('阅读');
	await search.fill('尚未提交');
	await page.clock.fastForward(100);
	if (await page.locator('#toggle-sidebar').isVisible()) await page.locator('#toggle-sidebar').click();
	await page.locator('[data-filter=unread]').click();
	await expect.poll(() => searches.length).toBe(2);
	await page.clock.fastForward(600);
	expect(searches).toHaveLength(2);
	expect(searches.at(-1)).toMatchObject({ filter: 'unread', search: '阅读' });
	await expect(search).toHaveValue('阅读');
	expect(reader.writes).toHaveLength(0);
});

test('an empty search offers one clear action that recovers the current stream in one click', async ({ page, reader }) => {
	const searches = [];
	await page.route(url => url.searchParams.get('c') === 'reader' && url.searchParams.get('a') === 'items', async route => {
		const url = new URL(route.request().url());
		searches.push(url.searchParams.get('search') || '');
		if (url.searchParams.get('search')) return route.fulfill({ json: { version: 1, items: [], next: null } });
		await route.fallback();
	});
	await page.locator('#article-search input').fill('不存在的内容');
	await page.locator('#article-search input').press('Enter');
	await expect(page.locator('.entry-card')).toHaveCount(0);
	await expect(page.locator('[data-clear-search]')).toBeVisible();
	await expect(page.locator('.empty-state button')).toHaveCount(1);
	await page.locator('[data-clear-search]').click();
	await expect(page.locator('#article-search input')).toHaveValue('');
	await expect(page.locator('.entry-card')).toHaveCount(4);
	await expect(page.locator('[data-clear-search]')).toHaveCount(0);
	expect(searches).toEqual(['不存在的内容', '']);
	expect(reader.writes).toHaveLength(0);
});

test('original articles open directly and image controls only appear for blocked images', async ({ page, reader }) => {
	await page.locator('.entry-card').first().click();
	await expect(page.locator('#article-frame')).toBeVisible();
	await expect(page.locator('.article-menu')).toHaveCount(0);
	await expect(page.locator('#original-link')).toBeVisible();
	await expect(page.locator('#load-images')).toBeHidden();
	await expect(page.locator('#original-link')).toHaveAttribute('href', 'https://example.org/article/0');
	await page.context().route('https://example.org/article/*', route => route.fulfill({ contentType: 'text/html', body: '<p>Original article fixture</p>' }));
	const opened = page.waitForEvent('popup');
	await page.locator('#original-link').click();
	const popup = await opened;
	await expect.poll(() => popup.url()).toBe('https://example.org/article/0');
	await popup.close();
	await page.route(url => url.searchParams.get('c') === 'reader' && url.searchParams.get('a') === 'article', route =>
		route.fulfill({
			json: {
				version: 1,
				article: {
					id: '99',
					title: '图片文章',
					feedId: 2,
					author: '',
					date: 1791800000,
					read: false,
					starred: false,
					link: '',
					content: '<p>本地图片文章</p><img src="https://example.org/image.jpg" alt="照片">'
				}
			}
		}));
	await page.locator('#close-article').click();
	await page.locator('.entry-card').nth(1).click();
	await expect(page.locator('#original-link')).toBeHidden();
	await expect(page.locator('#load-images')).toBeVisible();
	await expect(page.frameLocator('#article-frame').locator('img')).toHaveCount(0);
	await page.locator('#load-images').click();
	await expect(page.frameLocator('#article-frame').locator('img')).toHaveCount(1);
	await expect(page.locator('#load-images')).toBeHidden();
	expect(reader.writes).toHaveLength(0);
});

test('unchanged settings need no save and reverting a draft disables saving again', async ({ page, reader }) => {
	await openSettings(page);
	const save = page.locator('#save-reader-settings');
	await expect(save).toBeDisabled();
	await expect(page.locator('.preview-mark')).toHaveCount(0);
	await expect(page.locator('[data-settings-panel=data]')).toBeHidden();
	for (const id of ['export-settings', 'import-settings', 'reset-settings']) await expect(page.locator('#' + id)).toBeHidden();
	const originalTheme = reader.preferences.theme;
	const changedTheme = originalTheme === 'dark' ? 'nord' : 'dark';
	await page.locator(`input[type=radio][name=theme][value="${changedTheme}"]`).check();
	await expect(save).toBeEnabled();
	await expect(page.locator('body')).toHaveAttribute('data-theme', originalTheme);
	await page.locator(`input[type=radio][name=theme][value="${originalTheme}"]`).check();
	await expect(save).toBeDisabled();
	await expect(page.locator('body')).toHaveAttribute('data-theme', originalTheme);
	await page.locator(`input[type=radio][name=theme][value="${changedTheme}"]`).check();
	await save.click();
	await expect(page.locator('#settings-page')).toBeVisible();
	await expect(save).toBeDisabled();
	expect(reader.writes.filter(write => write.action === 'preferences')).toHaveLength(1);
	await page.locator('#cancel-settings').click();
	await expect(page.locator('#settings-page')).toBeHidden();
	await openSettings(page);
	await expect(save).toBeDisabled();
	await page.locator('#cancel-settings').click();
	expect(reader.writes.filter(write => write.action === 'preferences')).toHaveLength(1);
});

test('inherited automatic reading marks a successful article once and preserves a manual unread choice', async ({ page, reader, readerServer }) => {
	await readingAutomation(page, reader, readerServer, { autoMarkArticle: true, autoMarkSite: false });
	await page.locator('.entry-card').first().click();
	await expect(page.locator('#article-frame')).toBeVisible();
	await expect(page.locator('#mark-read')).toHaveAttribute('aria-pressed', 'true');
	await expect(page.locator('#mark-read')).toBeEnabled();
	expect(reader.writes.filter(write => write.action === 'state').map(write => write.body)).toEqual([
		{ _csrf: reader.bootstrap.csrf, id: '100', read: true },
	]);
	await page.locator('#mark-read').click();
	await expect(page.locator('#mark-read')).toHaveAttribute('aria-pressed', 'false');
	await expect(page.locator('#mark-read')).toBeEnabled();
	await page.locator('#mark-star').click();
	await expect(page.locator('#mark-star')).toHaveAttribute('aria-pressed', 'true');
	await expect(page.locator('#mark-star')).toBeEnabled();
	await expect(page.locator('#mark-read')).toHaveAttribute('aria-pressed', 'false');
	await expect(page.locator('.entry-card').first()).toHaveAttribute('data-read', 'false');
	expect(reader.writes.filter(write => write.action === 'state').map(write => write.body.read).filter(value => value !== undefined)).toEqual([true, false]);
});

test('disabling automatic article reading keeps opening and rerendering free of read writes', async ({ page, reader, readerServer }) => {
	await readingAutomation(page, reader, readerServer, { autoMarkArticle: false, autoMarkSite: false });
	await page.locator('.entry-card').first().click();
	await expect(page.locator('#article-frame')).toBeVisible();
	await expect(page.locator('#mark-read')).toHaveAttribute('aria-pressed', 'false');
	expect(reader.writes).toHaveLength(0);
	await page.locator('#mark-star').click();
	await expect(page.locator('#mark-star')).toBeEnabled();
	await expect(page.locator('#mark-star')).toHaveAttribute('aria-pressed', 'true');
	await expect(page.locator('#mark-read')).toHaveAttribute('aria-pressed', 'false');
	expect(reader.writes.filter(write => write.action === 'state')).toHaveLength(1);
	expect(reader.writes.at(-1).body).toMatchObject({ id: '100', starred: true });
	expect(reader.writes.at(-1).body).not.toHaveProperty('read');
});

test('a failed article never triggers inherited automatic reading', async ({ page, reader, readerServer }) => {
	await readingAutomation(page, reader, readerServer, { autoMarkArticle: true, autoMarkSite: false });
	await page.route(url => url.searchParams.get('a') === 'article', route => route.fulfill({ status: 500, json: { version: 1, error: 'fixture_failed' } }));
	await page.locator('.entry-card').first().click();
	await expect(page.locator('#article-status [data-retry-reader]')).toBeVisible();
	await expect(page.locator('#article-frame')).toBeHidden();
	await expect(page.locator('.entry-card').first()).toHaveAttribute('data-read', 'false');
	expect(reader.writes).toHaveLength(0);
});

test('closing a pending article prevents a late response from automatically marking it read', async ({ page, reader, readerServer }) => {
	await readingAutomation(page, reader, readerServer, { autoMarkArticle: true, autoMarkSite: false });
	let release;
	let requested;
	let delivered;
	const pending = new Promise(resolve => { release = resolve; });
	const started = new Promise(resolve => { requested = resolve; });
	const settled = new Promise(resolve => { delivered = resolve; });
	await page.route(url => url.searchParams.get('a') === 'article', async route => {
		requested(); await pending;
		try {
			await route.fulfill({ json: { version: 1, article: { id: '100', title: '迟到正文', read: false, starred: false, content: '<p>迟到正文</p>' } } });
		} catch {
			// Closing the article may abort its request before the delayed response is delivered.
		} finally { delivered(); }
	});
	try {
		await page.locator('.entry-card').first().click();
		await started;
		await page.locator('#close-article').click();
		await expect(page.locator('body')).toHaveAttribute('data-article-open', 'false');
		release(); await settled;
		await page.evaluate(() => new Promise(resolve => globalThis.requestAnimationFrame(() => globalThis.requestAnimationFrame(resolve))));
		await expect(page.locator('#article-frame')).toBeHidden();
		await expect(page.locator('.entry-card').first()).toHaveAttribute('data-read', 'false');
		expect(reader.writes).toHaveLength(0);
	} finally { release(); }
});

for (const enabled of [false, true]) {
	const name = `the original article action ${enabled ? 'marks read' : 'keeps unread'} according to inherited site preferences`;
	test(name, async ({ page, reader, readerServer }) => {
		await readingAutomation(page, reader, readerServer, { autoMarkArticle: false, autoMarkSite: enabled });
		await page.context().route('https://example.org/article/*', route => route.fulfill({ contentType: 'text/html', body: '<p>Original article fixture</p>' }));
		await page.locator('.entry-card').first().click();
		await expect(page.locator('#original-link')).toBeVisible();
		expect(reader.writes).toHaveLength(0);
		const opened = page.waitForEvent('popup');
		await page.locator('#original-link').click();
		const popup = await opened;
		await expect.poll(() => popup.url()).toBe('https://example.org/article/0');
		await popup.close();
		await expect(page.locator('#mark-read')).toBeEnabled();
		await expect(page.locator('#mark-read')).toHaveAttribute('aria-pressed', String(enabled));
		const writes = reader.writes.filter(write => write.action === 'state');
		expect(writes).toHaveLength(enabled ? 1 : 0);
		if (enabled) expect(writes[0].body).toMatchObject({ id: '100', read: true });
	});
}
