import { test, expect } from '../fixtures/reader.fixture.mjs';

async function subscriptions(page) {
	if (await page.locator('#toggle-sidebar').isVisible() && await page.locator('body').getAttribute('data-sidebar-open') !== 'true') {
		await page.locator('#toggle-sidebar').click();
	}
}

function deferred() {
	let resolve;
	const promise = new Promise(_resolve => { resolve = _resolve; });
	return { promise, resolve };
}

async function continuousReader(page, reader, readerServer, { count = 150, mixed = false, preferences = {}, autoMarkArticle = false } = {}) {
	const categories = [{ id: 10, name: '科技' }, { id: 20, name: '生活' }];
	const feeds = [1, 2, 3].map(id => ({ ...reader.feeds[0], id, name: '订阅 ' + id, categoryId: id === 3 ? 20 : 10, unread: 0 }));
	const items = Array.from({ length: count }, (_, index) => ({
		id: String(1000 - index),
		title: '连续阅读文章 ' + (index + 1),
		feedId: index % 3 + 1,
		author: '作者',
		date: 1791800000 - index * 60,
		read: mixed && index % 4 === 0,
		starred: index % 5 === 0,
		link: '',
	}));
	for (const feed of feeds) feed.unread = items.filter(item => item.feedId === feed.id && !item.read).length;
	const requests = []; const bodies = []; const writes = []; const handlers = {};
	const bootstrap = { ...reader.bootstrap, autoMarkArticle, autoMarkSite: false, preferences: { ...reader.preferences, ...preferences } };
	const html = readerServer.html.replace(/(<script[^>]+id="reader-bootstrap"[^>]*>)[\s\S]*?(<\/script>)/,
		(_, start, end) => start + JSON.stringify(bootstrap).replaceAll('<', '\\u003c') + end);
	await page.route(url => url.pathname === '/i/' && !url.searchParams.has('a'),
		route => route.fulfill({ body: html, contentType: 'text/html; charset=utf-8' }));
	await page.route(url => url.searchParams.get('c') === 'reader' && ['items', 'feeds', 'article', 'state'].includes(url.searchParams.get('a')), async route => {
		const url = new URL(route.request().url()); const action = url.searchParams.get('a');
		if (action === 'items') requests.push(Object.fromEntries(url.searchParams));
		if (action === 'article') bodies.push(url.searchParams.get('id'));
		if (handlers[action] && await handlers[action](route, url) !== false) return;
		if (action === 'feeds') return route.fulfill({ json: { version: 1, feeds, categories } });
		if (action === 'items') {
			const get = url.searchParams.get('get'); const filter = url.searchParams.get('filter'); const cursor = url.searchParams.get('cursor');
			const matches = items.filter(item =>
				(!get?.startsWith('f_') || item.feedId === Number(get.slice(2))) &&
				(!get?.startsWith('c_') || feeds.find(feed => feed.id === item.feedId).categoryId === Number(get.slice(2))) &&
				(filter !== 'unread' || !item.read) && (filter !== 'starred' || item.starred) &&
				(!cursor || Number(item.id) < Number(cursor)));
			const limit = Number(url.searchParams.get('limit')); const pageItems = matches.slice(0, limit);
			return route.fulfill({ json: { version: 1, items: pageItems, next: matches.length > limit ? pageItems.at(-1).id : null } });
		}
		if (action === 'state') {
			const change = route.request().postDataJSON(); writes.push(change);
			const item = items.find(item => item.id === change.id);
			if (typeof change.read === 'boolean' && change.read !== item.read) {
				feeds.find(feed => feed.id === item.feedId).unread += change.read ? -1 : 1;
			}
			Object.assign(item, change);
			return route.fulfill({ json: { version: 1, read: item.read, starred: item.starred } });
		}
		const item = items.find(item => item.id === url.searchParams.get('id'));
		const content = '<p>连续阅读正文</p>'.repeat(20) + '<img src="https://example.org/preload-image.png" alt="文章图片">';
		return route.fulfill({ json: { version: 1, article: { ...item, content } } });
	});
	await page.reload();
	await expect(page.locator('#article-list')).toHaveAttribute('aria-busy', 'false');
	await expect(page.locator('.entry-card').first()).toHaveAttribute('data-id', '1000');
	return { requests, bodies, writes, items, handlers };
}

test('unread, all and starred combine with category, subscription and global scopes', async ({ page, reader, readerServer }) => {
	const { requests } = await continuousReader(page, reader, readerServer, { count: 240, mixed: true });
	await subscriptions(page); await page.locator('[data-filter=unread]').click();
	await expect.poll(() => requests.at(-1)).toMatchObject({ get: 'a', filter: 'unread' });
	await expect(page.locator('.entry-card[data-read=true]')).toHaveCount(0);
	await subscriptions(page); await page.locator('[data-category="10"]').click();
	await expect.poll(() => requests.at(-1)).toMatchObject({ get: 'c_10', filter: 'unread' });
	await expect(page.locator('#view-title')).toHaveText('科技 · 未读');
	await expect(page.locator('.entry-card[data-read=true]')).toHaveCount(0);
	await subscriptions(page); await page.locator('[data-toggle-category="10"]').click();
	await page.locator('[data-feed="1"]').click();
	await expect.poll(() => requests.at(-1)).toMatchObject({ get: 'f_1', filter: 'unread' });
	await expect(page.locator('.entry-card[data-read=true]')).toHaveCount(0);
	await subscriptions(page); await page.locator('[data-filter=all]').click();
	await expect.poll(() => requests.at(-1)).toMatchObject({ get: 'f_1', filter: 'all' });
	await expect(page.locator('.entry-card[data-read=true]').first()).toBeVisible();
	await subscriptions(page); await page.locator('[data-filter=starred]').click();
	await expect.poll(() => requests.at(-1)).toMatchObject({ get: 'f_1', filter: 'starred' });
	await subscriptions(page); await page.locator('[data-category="20"]').click();
	await expect.poll(() => requests.at(-1)).toMatchObject({ get: 'c_20', filter: 'starred' });
	await subscriptions(page); await page.locator('[data-filter=unread]').click();
	await expect.poll(() => requests.at(-1)).toMatchObject({ get: 'c_20', filter: 'unread' });
	await subscriptions(page); await page.locator('#all-subscriptions').click();
	await expect.poll(() => requests.at(-1)).toMatchObject({ get: 'a', filter: 'unread' });
	await expect(page.locator('[data-filter=unread]')).toHaveAttribute('aria-current', 'page');
	await expect(page.locator('.entry-card[data-read=true]')).toHaveCount(0);
	await page.reload();
	await expect(page.locator('#view-title')).toHaveText('未读');
	await expect.poll(() => requests.at(-1)).toMatchObject({ get: 'a', filter: 'unread' });
});

test('keyboard reading crosses 100 and restores the continuous list after reload and Back', async ({ page, reader, readerServer }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	const { requests, writes } = await continuousReader(page, reader, readerServer);
	await page.locator('.entry-card').first().click();
	await expect(page.locator('#article-frame')).toHaveAttribute('title', '连续阅读文章 1');
	for (let index = 0; index < 105; index++) await page.keyboard.press('j');
	await expect(page.locator('#article-frame')).toHaveAttribute('title', '连续阅读文章 106');
	await expect(page.locator('.entry-card')).toHaveCount(150);
	expect(requests.map(request => request.cursor)).toEqual(['', '951', '901']);
	expect(writes).toHaveLength(0);
	await page.keyboard.press('k');
	await expect(page.locator('#article-frame')).toHaveAttribute('title', '连续阅读文章 105');
	await page.reload();
	await expect(page.locator('.entry-card')).toHaveCount(150);
	await expect(page.locator('#article-frame')).toHaveAttribute('title', '连续阅读文章 105');
	await page.goBack();
	await expect(page.locator('body')).toHaveAttribute('data-article-open', 'false');
	await expect(page.locator('.entry-card[data-id="896"]')).toBeInViewport();
	await expect(page.locator('.entry-card')).toHaveCount(150);
	await page.goForward();
	await expect(page.locator('#article-frame')).toHaveAttribute('title', '连续阅读文章 105');
	expect(errors).toEqual([]);
});

test('scrolling appends without replacing nodes, moving focus or marking unseen articles read', async ({ page, reader, readerServer }) => {
	const { requests, writes } = await continuousReader(page, reader, readerServer);
	await page.locator('#article-list').evaluate(node => {
		globalThis.firstContinuousEntry = node.firstElementChild;
		node.lastElementChild.focus({ preventScroll: true }); node.scrollTop = node.scrollHeight;
	});
	const before = await page.locator('#article-list').evaluate(node => node.scrollTop);
	await expect(page.locator('.entry-card')).toHaveCount(100);
	expect(await page.locator('#article-list').evaluate(node => node.firstElementChild === globalThis.firstContinuousEntry)).toBe(true);
	await expect(page.locator('.entry-card[data-id="951"]')).toBeFocused();
	await expect(page.locator('#article-list')).toHaveJSProperty('scrollTop', before);
	expect(requests).toHaveLength(2); expect(writes).toHaveLength(0);
	await expect(page.locator('#article-frame')).toBeHidden();
});

test('opening a nearby pending preload uses one request and only displayed articles get marked read', async ({ page, reader, readerServer }) => {
	const { handlers, bodies, writes } = await continuousReader(page, reader, readerServer, { autoMarkArticle: true });
	const gate = deferred(); const started = deferred();
	handlers.article = async (route, url) => {
		if (url.searchParams.get('id') !== '999') return false;
		started.resolve(); await gate.promise;
		return route.fulfill({ json: { version: 1, article: { id: '999', title: '连续阅读文章 2', feedId: 2, read: false, starred: false, content: '<p>已预取正文</p>' } } });
	};
	try {
		await page.locator('.entry-card').first().click();
		await expect(page.locator('#article-frame')).toBeVisible(); await started.promise;
		expect(writes.map(write => write.id)).toEqual(['1000']);
		await page.locator('#next-article').click();
		await expect(page.locator('#article-pane')).toHaveAttribute('aria-busy', 'true');
		expect(bodies.filter(id => id === '999')).toHaveLength(1);
		gate.resolve();
		await expect(page.locator('#article-frame')).toHaveAttribute('title', '连续阅读文章 2');
		await expect.poll(() => writes.map(write => write.id)).toEqual(['1000', '999']);
		expect(bodies.filter(id => id === '999')).toHaveLength(1);
	} finally { gate.resolve(); }
});

test('a failed automatic continuation leaves reading available and retries the same cursor at the boundary', async ({ page, reader, readerServer }) => {
	const { handlers, requests } = await continuousReader(page, reader, readerServer);
	let failed = false;
	handlers.items = (route, url) => {
		if (!url.searchParams.get('cursor') || failed) return false;
		failed = true; return route.fulfill({ status: 503, json: { version: 1, error: 'temporary_failure' } });
	};
	await page.locator('.entry-card').nth(45).click();
	await expect(page.locator('#article-frame')).toHaveAttribute('title', '连续阅读文章 46');
	await expect(page.locator('#next-page')).toHaveText('重试加载');
	await expect(page.locator('#article-status')).toBeHidden();
	for (let index = 0; index < 5; index++) await page.keyboard.press('j');
	await expect(page.locator('#article-frame')).toHaveAttribute('title', '连续阅读文章 51');
	await expect(page.locator('.entry-card')).toHaveCount(100);
	expect(requests.map(request => request.cursor)).toEqual(['', '951', '951']);
});

test('active rows stay centred across continuations and resize, while manual list scrolling is respected', async ({ page, reader, readerServer }) => {
	await page.setViewportSize({ width: 1440, height: 900 });
	await page.emulateMedia({ reducedMotion: 'reduce' });
	await continuousReader(page, reader, readerServer);
	await page.locator('.entry-card').nth(46).click();
	await expect(page.locator('.entry-card')).toHaveCount(100);
	const centreError = () => page.locator('#article-list').evaluate(list => {
		const item = list.querySelector('[aria-current="true"]').getBoundingClientRect(); const bounds = list.getBoundingClientRect();
		return Math.abs(item.top + item.height / 2 - bounds.top - list.clientHeight / 2);
	});
	await expect.poll(centreError).toBeLessThan(2);
	await page.keyboard.press('j'); await expect.poll(centreError).toBeLessThan(2);
	await page.setViewportSize({ width: 1200, height: 720 }); await expect.poll(centreError).toBeLessThan(2);
	await page.locator('#article-list').dispatchEvent('wheel', { deltaY: -200 });
	await page.locator('#article-list').evaluate(node => { node.scrollTop = 200; });
	await page.locator('#mark-star').click();
	await expect(page.locator('#mark-star')).toBeEnabled();
	await expect(page.locator('#article-list')).toHaveJSProperty('scrollTop', 200);
	await page.locator('#next-article').click(); await expect.poll(centreError).toBeLessThan(2);
	// A manual scroll in the same frame as navigation must cancel its queued centring.
	await page.locator('#article-list').evaluate(list => {
		document.getElementById('next-article').click();
		list.dispatchEvent(new globalThis.WheelEvent('wheel', { deltaY: -200 })); list.scrollTop = 200;
	});
	await page.evaluate(() => new Promise(resolve => globalThis.requestAnimationFrame(() => globalThis.requestAnimationFrame(resolve))));
	await expect(page.locator('#article-list')).toHaveJSProperty('scrollTop', 200);
	expect(await page.locator('html').evaluate(node => node.scrollWidth <= globalThis.innerWidth)).toBe(true);
});

test('saving data disables speculative bodies while demand navigation still continues beyond a batch', async ({ page, reader, readerServer }) => {
	await page.addInitScript(() => Object.defineProperty(globalThis.navigator, 'connection', { value: { saveData: true, addEventListener() {} } }));
	const { bodies, requests } = await continuousReader(page, reader, readerServer);
	await page.locator('.entry-card').first().click();
	await expect(page.locator('#article-frame')).toBeVisible();
	await page.waitForTimeout(400);
	expect(bodies).toEqual(['1000']); expect(requests).toHaveLength(1);
	for (let index = 0; index < 50; index++) await page.keyboard.press('j');
	await expect(page.locator('#article-frame')).toHaveAttribute('title', '连续阅读文章 51');
	expect(requests.map(request => request.cursor)).toEqual(['', '951']);
});

test('a long reading session updates only nearby rows and keeps one article frame', async ({ page, reader, readerServer }, testInfo) => {
	await continuousReader(page, reader, readerServer, { count: 1000, preferences: { pageSize: 200 } });
	for (let index = 0; index < 4; index++) {
		await page.locator('#next-page').click();
		await expect(page.locator('.entry-card')).toHaveCount((index + 2) * 200);
	}
	await page.locator('#article-list').evaluate(list => {
		globalThis.longListFirst = list.firstElementChild; globalThis.longListRows = new Set(); globalThis.longListChildChanges = 0;
		new globalThis.MutationObserver(records => {
			for (const record of records) {
				if (record.type === 'childList') globalThis.longListChildChanges++;
				if (record.target.dataset.id) globalThis.longListRows.add(record.target.dataset.id);
			}
		}).observe(list, { attributes: true, childList: true, subtree: true });
	});
	await page.locator('.entry-card').nth(700).click();
	await expect(page.locator('#article-frame')).toHaveAttribute('title', '连续阅读文章 701');
	for (let index = 0; index < 5; index++) await page.keyboard.press('j');
	await expect(page.locator('#article-frame')).toHaveAttribute('title', '连续阅读文章 706');
	const changes = await page.locator('#article-list').evaluate(list => ({
		stable: list.firstElementChild === globalThis.longListFirst,
		rows: globalThis.longListRows.size,
		children: globalThis.longListChildChanges,
	}));
	expect(changes).toEqual({ stable: true, rows: 6, children: 0 });
	await expect(page.locator('#article-frame')).toHaveCount(1);
	await page.screenshot({ path: testInfo.outputPath('continuous-reader.png'), animations: 'disabled' });
});

test('finishing unread articles and refreshing stays in unread and never falls back to all', async ({ page, reader, readerServer }) => {
	const { requests } = await continuousReader(page, reader, readerServer, { count: 9, autoMarkArticle: true });
	await subscriptions(page); await page.locator('[data-filter=unread]').click();
	await expect(page.locator('#view-title')).toHaveText('未读');
	await page.locator('.entry-card').first().click();
	for (let index = 0; index < 9; index++) {
		await expect(page.locator('#article-frame')).toHaveAttribute('title', '连续阅读文章 ' + (index + 1));
		await expect(page.locator('#mark-read')).toHaveAttribute('aria-pressed', 'true');
		await expect(page.locator('#mark-read')).toBeEnabled();
		if (index < 8) await page.keyboard.press('j');
	}
	await expect(page.locator('#total-unread')).toHaveText('0');
	await expect(page.locator('#next-article')).toBeDisabled();
	await page.locator('#close-article').click();
	await expect(page.locator('body')).toHaveAttribute('data-article-open', 'false');
	await page.locator('#refresh').click();
	await expect(page.locator('.entry-card')).toHaveCount(0);
	await expect(page.locator('#view-title')).toHaveText('未读');
	expect(requests.at(-1).filter).toBe('unread');
});

for (const layout of ['list', 'cards']) {
	test(`${layout} layout loads the next batch while scrolling without replacing the first entries`, async ({ page, reader, readerServer }) => {
		await continuousReader(page, reader, readerServer, { preferences: { layout } });
		await page.locator('#article-list').evaluate(list => { list.scrollTop = list.scrollHeight; });
		await expect(page.locator('.entry-card')).toHaveCount(100);
		await expect(page.locator('.entry-card').first()).toHaveAttribute('data-id', '1000');
		await expect(page.locator('.entry-card').last()).toHaveAttribute('data-id', '901');
	});
}
