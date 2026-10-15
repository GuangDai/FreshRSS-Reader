import { test, expect } from '@playwright/test';

const username = process.env.FRESHRSS_TEST_USER;
const password = process.env.FRESHRSS_TEST_PASSWORD;
const longName = 'VeryLongUnbrokenSubscriptionName'.repeat(12);
const feeds = [1, 2, 3].map(id => ({
	id,
	name: longName,
	categoryId: 1,
	unread: 12,
	error: false,
	version: `fixture-${id}`,
	priority: 0,
	ttl: 3600,
	refreshEnabled: true,
}));
const subscriptionFields = {
	name: { type: 'string', maxLength: 2048 },
	categoryId: { type: 'integer', min: 1, max: 100 },
	priority: { type: 'integer', min: 0, max: 2, options: [0, 1, 2] },
	ttl: { type: 'integer', min: 0, max: 86400 },
	refreshEnabled: { type: 'boolean' },
};
const items = Array.from({ length: 24 }, (_, index) => ({
	id: String(1700000000000000n + BigInt(index)),
	feedId: 1,
	title: `Article ${index}: ${longName}`,
	author: longName,
	date: 1700000000,
	read: false,
	starred: false,
}));

async function login(page) {
	await page.goto('./?c=auth&a=login');
	await page.locator('[name=username]').fill(username);
	await page.locator('input[type=password]').fill(password);
	await Promise.all([page.waitForURL(url => url.searchParams.get('c') !== 'auth'), page.locator('button[type=submit]').click()]);
}

// Real document, modules and styles; generated feed/article data and intercepted writes.
// A viewport test must never change the shared preview account's preferences or subscriptions.
async function openReader(page, preferences = {}, { next = null, listResponse = () => ({ items, next }) } = {}) {
	let listRequests = 0;
	await page.route(url => url.searchParams.get('c') === 'reader', async route => {
		const action = new URL(route.request().url()).searchParams.get('a');
		if (!action) {
			const response = await route.fetch();
			const body = (await response.text()).replace(/(<script type="application\/json" id="reader-bootstrap">)([\s\S]*?)(<\/script>)/,
				(_, start, json, end) => {
					const bootstrap = JSON.parse(json);
					// Viewport fixtures never write article state through inherited reading automation.
					Object.assign(bootstrap, { autoMarkArticle: false, autoMarkSite: false });
					bootstrap.preferences = { ...bootstrap.preferences, refreshInterval: 0, ...preferences };
					bootstrap.adjustments = [];
					bootstrap.subscription = { fields: subscriptionFields };
					return start + JSON.stringify(bootstrap) + end;
				});
			await route.fulfill({ response, body });
		} else if (action === 'feeds') {
			await route.fulfill({
				json: {
					version: 1,
					feeds,
					categories: [{ id: 1, name: longName }],
				}
			});
		} else if (action === 'items') {
			listRequests++;
			await route.fulfill({ json: { version: 1, ...listResponse(new URL(route.request().url())) } });
		} else if (action === 'article') {
			await route.fulfill({
				json: {
					version: 1,
					article: {
						...items[0],
						link: 'https://example.invalid/article',
						content: `<p>Responsive article fixture</p><figure><figcaption>${longName}</figcaption></figure>` +
					`<pre>${longName}</pre><table><tr>${'<td>wide table content</td>'.repeat(20)}</tr></table><blockquote>${longName}</blockquote>` +
					'<p>Reading paragraph fixture</p>'.repeat(50)
					}
				}
			});
		} else if (action === 'preferences' && route.request().method() === 'POST') {
			await route.fulfill({ json: { version: 1, preferences: route.request().postDataJSON().preferences } });
		} else if (action === 'feedBatch' && route.request().method() === 'POST') {
			const { ids, changes } = route.request().postDataJSON();
			await route.fulfill({ json: { version: 1, feeds: feeds.filter(feed => ids.includes(feed.id)).map(feed => ({ ...feed, ...changes })) } });
		} else if (route.request().method() !== 'GET') {
			await route.fulfill({ status: 400, json: { version: 1, error: 'unexpected_test_write' } });
		} else await route.continue();
	});
	await page.goto('./?c=reader');
	await expect(page.locator('html')).toHaveAttribute('dir', /^(ltr|rtl)$/);
	await expect(page.locator('meta[name=viewport]')).toHaveAttribute('content', /viewport-fit=cover/);
	await expect(page.locator('.entry-card')).toHaveCount(items.length);
	return () => listRequests;
}

async function expectContained(locator) {
	const dimensions = await locator.evaluate(node => ({ client: node.clientWidth, scroll: node.scrollWidth }));
	expect(dimensions.scroll).toBeLessThanOrEqual(dimensions.client + 1);
}

async function openSettings(page) {
	await page.locator('#open-settings').click();
	await expect(page.locator('#settings-page')).toBeVisible();
}

async function expandFeeds(page) {
	await page.locator('[data-toggle-category="1"]').click();
	await expect(page.locator('.feed-button').first()).toBeVisible();
}

test.beforeEach(async ({ page }) => {
	test.skip(!username || !password, 'Use an isolated local test account.');
	await login(page);
});

const dimensions = [
	{ width: 240, height: 568 }, { width: 280, height: 653 }, { width: 320, height: 568 },
	{ width: 390, height: 844 }, { width: 667, height: 320 }, { width: 844, height: 390 },
	{ width: 768, height: 1024 }, { width: 820, height: 1180 }, { width: 821, height: 600 },
	{ width: 1024, height: 768 }, { width: 1280, height: 720 }, { width: 1920, height: 1080 },
	{ width: 3840, height: 2160 },
];

for (const viewport of dimensions) {
	test(`reader reflows at ${viewport.width} × ${viewport.height}`, async ({ page }) => {
		await page.setViewportSize(viewport);
		const requests = await openReader(page, { sidebarWidth: 600, listWidth: 1000, fontSize: 96, readingWidth: 3840 });
		await expectContained(page.locator('html'));
		await expectContained(page.locator('.article-list'));
		expect(await page.locator('.article-list').evaluate(node => node.clientHeight)).toBeGreaterThan(40);
		await page.locator('.entry-card').first().click();
		const frame = page.frameLocator('#article-frame');
		await expect(frame.getByText('Responsive article fixture')).toBeVisible();
		await expectContained(frame.locator('html'));
		expect(await page.locator('#article-frame').evaluate(node => node.clientHeight)).toBeGreaterThan(80);
		await page.locator('#close-article').click();
		await openSettings(page);
		const dialog = page.locator('#appearance-dialog');
		await expectContained(dialog);
		const bounds = await dialog.boundingBox();
		expect(bounds.x).toBeGreaterThanOrEqual(0);
		expect(bounds.x + bounds.width).toBeLessThanOrEqual(viewport.width);
		expect(bounds.y).toBeGreaterThanOrEqual(0);
		expect(bounds.y + bounds.height).toBeLessThanOrEqual(viewport.height);
		await page.locator('#appearance-form [type=submit]').scrollIntoViewIfNeeded();
		await expect(page.locator('#appearance-form [type=submit]')).toBeInViewport();
		await page.keyboard.press('Escape');
		expect(requests()).toBe(1);
	});
}

test('custom widths are preserved when they fit and clamp only against available space', async ({ page }) => {
	await page.setViewportSize({ width: 1920, height: 1080 });
	const requests = await openReader(page, { sidebarWidth: 600, listWidth: 720 });
	await expect(page.locator('.sidebar')).toHaveJSProperty('clientWidth', 599);
	await expect(page.locator('.article-list-pane')).toHaveJSProperty('clientWidth', 719);
	await page.setViewportSize({ width: 1024, height: 768 });
	expect(await page.locator('.sidebar').evaluate(node => node.getBoundingClientRect().width)).toBeLessThanOrEqual(1024 - 240 - 280 + 1);
	await expectContained(page.locator('html'));
	await page.setViewportSize({ width: 1920, height: 1080 });
	await expect(page.locator('.sidebar')).toHaveJSProperty('clientWidth', 599);
	await expect(page.locator('.article-list-pane')).toHaveJSProperty('clientWidth', 719);
	await page.evaluate(() => document.documentElement.style.setProperty('--list-width', '240px'));
	await page.setViewportSize({ width: 1024, height: 768 });
	await expect(page.locator('.article-list-pane')).toHaveJSProperty('clientWidth', 239);
	expect(requests()).toBe(1);
});

test('card grids fit very narrow viewports and retain their scroll position on resize', async ({ page }) => {
	await page.setViewportSize({ width: 240, height: 568 });
	const requests = await openReader(page, { layout: 'cards' });
	await expectContained(page.locator('.article-list'));
	await page.locator('.article-list').evaluate(node => { node.scrollTop = 600; });
	await page.setViewportSize({ width: 390, height: 844 });
	expect(await page.locator('.article-list').evaluate(node => node.scrollTop)).toBeGreaterThan(0);
	await expectContained(page.locator('.article-list'));
	expect(requests()).toBe(1);
});

test('RTL drawer enters from the reading edge and article overlay respects the sidebar width', async ({ page }) => {
	await openReader(page, { layout: 'list', sidebarWidth: 400 });
	await page.locator('html').evaluate(node => { node.dir = 'rtl'; });
	await page.locator('.entry-card').first().click();
	await expect(page.frameLocator('#article-frame').locator('html')).toHaveAttribute('dir', 'rtl');
	await expect(page.frameLocator('#article-frame').locator('body')).toHaveAttribute('dir', 'auto');
	let sidebar = await page.locator('.sidebar').boundingBox();
	let article = await page.locator('.article-pane').boundingBox();
	expect(article.x + article.width).toBeLessThanOrEqual(sidebar.x + 1);
	await page.locator('#close-article').click();
	await page.setViewportSize({ width: 390, height: 844 });
	await page.locator('#toggle-sidebar').click();
	sidebar = await page.locator('.sidebar').boundingBox();
	expect(sidebar.x + sidebar.width).toBeCloseTo(390, 0);
	await page.keyboard.press('Escape');
	sidebar = await page.locator('.sidebar').boundingBox();
	expect(sidebar.x).toBeGreaterThanOrEqual(390);
	await page.locator('.entry-card').first().click();
	article = await page.locator('.article-pane').boundingBox();
	expect(article.x).toBe(0);
	expect(article.width).toBe(390);
});

test('200% text and desktop zoom equivalent retain reachable settings in a short viewport', async ({ page }) => {
	// 1280 × 800 at 200% browser zoom presents a 640 × 400 CSS layout viewport.
	await page.setViewportSize({ width: 640, height: 400 });
	await openReader(page);
	await page.locator('html').evaluate(node => { node.style.fontSize = '175%'; });
	await expectContained(page.locator('html'));
	await openSettings(page);
	await expectContained(page.locator('#appearance-dialog'));
	await page.locator('.limits-details summary').click();
	await expectContained(page.locator('#appearance-dialog'));
	await page.locator('#appearance-form [type=submit]').scrollIntoViewIfNeeded();
	await expect(page.locator('#appearance-form [type=submit]')).toBeInViewport();
	await page.keyboard.press('Escape');
	await page.locator('#toggle-sidebar').click();
	await page.locator('.sidebar-bottom a').scrollIntoViewIfNeeded();
	await expect(page.locator('.sidebar-bottom a')).toBeInViewport();
});

test('forced colours expose selected controls and reduced motion disables animation', async ({ page }) => {
	await page.emulateMedia({ forcedColors: 'active', reducedMotion: 'reduce' });
	await openReader(page);
	const layout = page.locator('#quick-layout');
	await layout.focus();
	expect(await layout.evaluate(node => globalThis.getComputedStyle(node).outlineStyle)).toBe('solid');
	await expect(layout).toHaveValue('split');
	for (const colorScheme of ['light', 'dark']) {
		await page.emulateMedia({ forcedColors: 'active', reducedMotion: 'reduce', colorScheme });
		await expect.poll(() => page.locator('.primary-nav .reader-icon').first().evaluate(node => {
			const icon = globalThis.getComputedStyle(node);
			return icon.maskImage !== 'none' && icon.backgroundColor !== globalThis.getComputedStyle(document.body).backgroundColor;
		})).toBe(true);
		await openSettings(page);
		await expect.poll(() => page.locator('#appearance-form .primary').evaluate(node => {
			const style = globalThis.getComputedStyle(node);
			return style.color !== style.backgroundColor;
		})).toBe(true);
		await page.keyboard.press('Escape');
		await page.locator('.entry-card').first().click();
		const shell = await page.locator('body').evaluate(node => ({
			background: globalThis.getComputedStyle(node).backgroundColor,
			color: globalThis.getComputedStyle(node).color,
		}));
		await expect(page.frameLocator('#article-frame').locator('body')).toHaveCSS('background-color', shell.background);
		await expect(page.frameLocator('#article-frame').locator('body')).toHaveCSS('color', shell.color);
		await page.locator('#close-article').click();
	}
	await page.addStyleTag({
		content: '.entry-card { animation: responsive-fixture 10s infinite; transition-duration: 3s; }' +
		'@keyframes responsive-fixture { from { opacity: .5; } to { opacity: 1; } }'
	});
	const motion = await page.locator('.entry-card').first().evaluate(node => ({
		animation: globalThis.getComputedStyle(node).animationDuration, transition: globalThis.getComputedStyle(node).transitionDuration,
	}));
	expect(parseFloat(motion.animation)).toBeLessThan(0.001);
	expect(parseFloat(motion.transition)).toBeLessThan(0.001);
});

test('touch controls remain usable on tablets beyond the mobile breakpoint', async ({ browser }) => {
	const context = await browser.newContext({ hasTouch: true, viewport: { width: 1024, height: 768 } });
	const page = await context.newPage();
	try {
		await login(page);
		await openReader(page);
		await expandFeeds(page);
		for (const selector of ['.feed-button', '#quick-layout', '#open-settings', '#article-search input']) {
			const target = await page.locator(selector).first().boundingBox();
			expect(target.height, `${selector} touch height`).toBeGreaterThanOrEqual(44);
			expect(target.width, `${selector} touch width`).toBeGreaterThanOrEqual(44);
		}
		await expect(page.locator('#article-search input')).toHaveCSS('font-size', '16px');
		await page.locator('.entry-card').first().click();
		await expect(page.frameLocator('#article-frame').getByText('Responsive article fixture')).toBeVisible();
		const original = await page.locator('#original-link').boundingBox();
		expect(original.width).toBeGreaterThanOrEqual(44);
		expect(original.height).toBeGreaterThanOrEqual(44);
		await expect(page.locator('#load-images')).toHaveCount(0);
		await page.locator('#close-article').click();
		await openSettings(page);
		await page.locator('[data-settings-section=reading]').click();
		await expect(page.locator('[name=fontSize]')).toBeVisible();
		expect(await page.locator('[name=fontSize]').evaluate(node => node.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
	} finally { await context.close(); }
});

test('minimum width sidebar and long active feed names do not displace the reader controls', async ({ page }) => {
	await openReader(page, { sidebarWidth: 160, listWidth: 240, fontSize: 8, readingWidth: 280, density: 'compact' });
	await expectContained(page.locator('.sidebar'));
	await expandFeeds(page);
	await page.locator('.feed-button').first().click();
	await expectContained(page.locator('.toolbar'));
	expect(await page.locator('.toolbar').evaluate(node => node.getBoundingClientRect().height)).toBeLessThan(120);
	await page.setViewportSize({ width: 320, height: 568 });
	await page.locator('#toggle-sidebar').click();
	await expectContained(page.locator('.sidebar'));
	await page.locator('#close-sidebar').click();
	await page.locator('#open-settings').click();
	await expectContained(page.locator('#settings-page'));
});

for (const viewport of [{ width: 240, height: 568 }, { width: 667, height: 320 }, { width: 821, height: 600 }, { width: 1440, height: 900 }]) {
	test(`batch subscription settings remain usable at ${viewport.width} × ${viewport.height}`, async ({ page }) => {
		await page.setViewportSize(viewport);
		await openReader(page, { sidebarWidth: 160 });
		if (await page.locator('#toggle-sidebar').isVisible()) await page.locator('#toggle-sidebar').click();
		await expandFeeds(page);
		await page.locator('#toggle-feed-selection').click();
		await expectContained(page.locator('.sidebar'));
		await page.locator('#select-visible-feeds').click();
		await expect(page.locator('.feed-select:checked')).toHaveCount(feeds.length);
		await page.locator('#edit-selected-feeds').click();
		const dialog = page.locator('#feed-settings-dialog');
		await expect(dialog).toBeVisible();
		await expectContained(dialog);
		await page.locator('[data-change-feed-field=name]').check();
		await page.locator('#feed-setting-name').fill(longName.repeat(2));
		await page.locator('[data-change-feed-field=categoryId]').check();
		await page.locator('#feed-setting-categoryId').selectOption('1');
		await expectContained(dialog);
		const saved = page.waitForResponse(response => new URL(response.url()).searchParams.get('a') === 'feedBatch');
		await page.locator('#save-feed-settings').click();
		expect((await saved).ok()).toBe(true);
		await expect(page.locator('#feed-settings-status')).toBeVisible();
		await expectContained(dialog);
		await page.keyboard.press('Escape');
		await expect(dialog).not.toBeVisible();
		await expect(page.locator(viewport.width <= 820 ? '#toggle-sidebar' : '#edit-selected-feeds')).toBeFocused();
	});
}

test.describe('touch reading controls and long forms', () => {
	test.use({ hasTouch: true });

	for (const viewport of [{ width: 320, height: 568 }, { width: 667, height: 320 }]) {
		test(`reading actions stay in reach without covering the article at ${viewport.width} × ${viewport.height}`, async ({ page }) => {
			await page.setViewportSize(viewport);
			await openReader(page);
			await page.locator('.entry-card').first().click();
			await expect(page.frameLocator('#article-frame').getByText('Responsive article fixture')).toBeVisible();
			const close = await page.locator('#close-article').boundingBox();
			const navigation = await page.locator('.article-navigation').boundingBox();
			const frame = await page.locator('#article-frame').boundingBox();
			expect(close.y).toBeLessThanOrEqual(12);
			expect(navigation.y + navigation.height).toBeCloseTo(viewport.height, 0);
			expect(frame.height).toBeGreaterThan(viewport.height * 0.55);
			expect(frame.y + frame.height).toBeLessThanOrEqual(navigation.y + 1);
			let previous = null;
			for (const id of ['previous-article', 'mark-read', 'mark-star', 'next-article']) {
				const button = page.locator('#' + id);
				await expect(button).toBeInViewport();
				const bounds = await button.boundingBox();
				expect(bounds.height).toBeGreaterThanOrEqual(44 - 1 / 64);
				expect(bounds.width).toBeGreaterThanOrEqual(44 - 1 / 64);
				if (previous) expect(bounds.x).toBeGreaterThanOrEqual(previous.x + previous.width);
				previous = bounds;
			}
			await expect(page.locator('#original-link')).toBeVisible();
			await expect(page.locator('#original-link')).toBeInViewport();
			await expectContained(page.locator('.article-header'));
			await expect(page.locator('#load-images')).toHaveCount(0);
			const next = await page.locator('#next-article').boundingBox();
			await page.frameLocator('#article-frame').locator('html').evaluate(node => {
				node.ownerDocument.scrollingElement.scrollTop = node.scrollHeight;
			});
			await expect.poll(() => page.frameLocator('#article-frame').locator('html').evaluate(node =>
				node.ownerDocument.scrollingElement.scrollTop)).toBeGreaterThan(0);
			const scrolledNext = await page.locator('#next-article').boundingBox();
			for (const key of ['x', 'y', 'width', 'height']) expect(scrolledNext[key]).toBeCloseTo(next[key], 1);
			expect((await page.locator('.article-navigation').boundingBox()).y).toBeCloseTo(navigation.y, 1);
			await page.locator('#close-article').click();
			await expect(page.locator('body')).toHaveAttribute('data-article-open', 'false');
		});

		test(`settings keep close and save visible while scrolling and validating at ${viewport.width} × ${viewport.height}`, async ({ page }) => {
			await page.setViewportSize(viewport);
			await openReader(page);
			await openSettings(page);
			const body = page.locator('#settings-page .settings-content');
			const close = page.locator('#cancel-settings');
			const save = page.locator('#save-reader-settings');
			await expect(close).toBeInViewport();
			await expect(save).toBeInViewport();
			const initialClose = await close.boundingBox();
			const initialSave = await save.boundingBox();
			for (const key of ['layout', 'density']) {
				const group = page.locator(`fieldset[data-preference="${key}"]`);
				const choice = group.locator('label[data-choice-value]').first();
				await choice.scrollIntoViewIfNeeded();
				const bounds = await choice.boundingBox();
				expect(bounds.height).toBeGreaterThanOrEqual(44);
				expect(bounds.width).toBeGreaterThanOrEqual(44);
				await expectContained(group);
			}
			await body.evaluate(node => { node.scrollTop = node.scrollHeight; });
			await expect(close).toBeInViewport();
			await expect(save).toBeInViewport();
			expect((await close.boundingBox()).y).toBeCloseTo(initialClose.y, 0);
			expect((await save.boundingBox()).y).toBeCloseTo(initialSave.y, 0);
			await expectContained(body);
			const sections = page.locator('#settings-section-select');
			if (await sections.isVisible()) await sections.selectOption('automation');
			else await page.locator('[data-settings-section=automation]').click();
			const refresh = page.locator('[name=refreshInterval]');
			await refresh.fill('');
			await save.click();
			await expect(refresh).toBeFocused();
			await expect(refresh).toHaveAttribute('aria-invalid', 'true');
			const inputBounds = await refresh.boundingBox();
			const bodyBounds = await body.boundingBox();
			expect(inputBounds.y).toBeGreaterThanOrEqual(bodyBounds.y - 1);
			expect(inputBounds.y + inputBounds.height).toBeLessThanOrEqual(bodyBounds.y + bodyBounds.height + 1);
			await close.click();
			await expect(page.locator('#settings-page')).not.toBeVisible();
		});
	}

	test('a slow article keeps close at the top and touch actions at the bottom', async ({ page }) => {
		await page.setViewportSize({ width: 667, height: 320 });
		await openReader(page);
		let release;
		const gate = new Promise(resolve => { release = resolve; });
		await page.route(url => url.searchParams.get('a') === 'article', async route => {
			await gate; await route.fallback().catch(() => {});
		});
		try {
			await page.locator('.entry-card').first().click();
			await expect(page.locator('#article-pane')).toHaveAttribute('aria-busy', 'true');
			expect((await page.locator('#close-article').boundingBox()).y).toBeLessThanOrEqual(12);
			await expect(page.locator('#article-empty small')).not.toBeVisible();
			await expect(page.locator('#next-article')).toBeInViewport();
			await page.locator('#close-article').click();
			await expect(page.locator('body')).toHaveAttribute('data-article-open', 'false');
		} finally { release(); }
	});

	test('selected subscriptions have row feedback and a visible primary batch action', async ({ page }) => {
		await page.setViewportSize({ width: 390, height: 844 });
		await openReader(page);
		await page.locator('#toggle-sidebar').click();
		await expandFeeds(page);
		await page.locator('#toggle-feed-selection').click();
		const row = page.locator('.feed-row').first();
		const before = await row.evaluate(node => globalThis.getComputedStyle(node).backgroundColor);
		await row.locator('.feed-select').check();
		expect(await row.evaluate(node => globalThis.getComputedStyle(node).backgroundColor)).not.toBe(before);
		await expect(page.locator('#edit-selected-feeds')).toBeEnabled();
		await expect(page.locator('#edit-selected-feeds')).toBeInViewport();
		await expect(page.locator('.primary-nav')).not.toBeVisible();
		await page.locator('#toggle-feed-selection').click();
		await expect(page.locator('.primary-nav')).toBeVisible();
	});
});

for (const width of [240, 320, 667, 1440]) {
	test(`page navigation and page-read action share one reachable row at ${width}px`, async ({ page }) => {
		await page.setViewportSize({ width, height: 568 });
		await openReader(page, {}, {
			listResponse: url => url.searchParams.get('search') === 'empty'
				? { items: [], next: null }
				: url.searchParams.get('cursor')
					? { items: items.map(item => ({ ...item, read: true })), next: null }
					: { items, next: 'fixture-next-page' },
		});
		const bar = page.locator('.pagination');
		await expect(bar).toBeVisible();
		await expect(bar.locator('#mark-page-read')).toHaveCount(1);
		await expect(page.locator('.batch-toolbar')).toHaveCount(0);
		await expectContained(bar);
		const initial = new Map();
		let previous = null;
		for (const id of ['previous-page', 'mark-page-read', 'next-page']) {
			const button = page.locator('#' + id);
			await expect(button).toBeInViewport();
			const bounds = await button.boundingBox();
			initial.set(id, bounds);
			expect(bounds.height).toBeGreaterThanOrEqual(44 - 1 / 64);
			if (previous) {
				expect(bounds.x).toBeGreaterThanOrEqual(previous.x + previous.width);
				expect(bounds.y).toBeCloseTo(previous.y, 0);
			}
			previous = bounds;
		}
		await expect(page.locator('#previous-page')).toBeDisabled();
		await expect(page.locator('#next-page')).toBeEnabled();
		await expect(page.locator('#mark-page-read')).toBeEnabled();
		await page.locator('#next-page').click();
		await expect(page.locator('#previous-page')).toBeEnabled();
		await expect(page.locator('#next-page')).toBeDisabled();
		await expect(page.locator('#mark-page-read')).toBeDisabled();
		for (const [id, bounds] of initial) {
			const button = page.locator('#' + id);
			await expect(button).toBeInViewport();
			const current = await button.boundingBox();
			for (const key of ['x', 'y', 'width', 'height']) expect(current[key]).toBeCloseTo(bounds[key], 1);
		}
		await page.locator('#article-search input').fill('empty');
		await page.locator('#article-search').evaluate(node => node.requestSubmit());
		await expect(page.locator('.entry-card')).toHaveCount(0);
		await expect(bar).toBeVisible();
		for (const [id, bounds] of initial) {
			const button = page.locator('#' + id);
			await expect(button).toBeInViewport();
			await expect(button).toBeDisabled();
			const current = await button.boundingBox();
			for (const key of ['x', 'y', 'width', 'height']) expect(current[key]).toBeCloseTo(bounds[key], 1);
		}
	});
}

test('the original article action remains directly reachable in the desktop article header', async ({ page }) => {
	await page.setViewportSize({ width: 1440, height: 900 });
	await openReader(page);
	await page.locator('.entry-card').first().click();
	await expect(page.frameLocator('#article-frame').getByText('Responsive article fixture')).toBeVisible();
	const original = await page.locator('#original-link').boundingBox();
	const toolbar = await page.locator('#article-actions').boundingBox();
	expect(original.y).toBeGreaterThanOrEqual(toolbar.y);
	expect(original.y + original.height).toBeLessThanOrEqual(toolbar.y + toolbar.height);
	await expect(page.locator('.article-menu')).toHaveCount(0);
	const reachable = await page.evaluate(({ x, y }) => Boolean(document.elementFromPoint(x, y)?.closest('#original-link')),
		{ x: original.x + original.width / 2, y: original.y + original.height / 2 });
	expect(reachable).toBe(true);
});
