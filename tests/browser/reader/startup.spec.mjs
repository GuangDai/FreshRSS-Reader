import { test, expect, renderShell } from '../fixtures/reader.fixture.mjs';

test('reading starts without hidden preference or subscription rows and quick layout still saves', async ({ page, reader }) => {
	await expect(page.locator('[name][form=appearance-form]')).toHaveCount(0);
	await expect(page.locator('[data-settings-feed-row]')).toHaveCount(0);
	await expect(page.locator('.reader-miniature')).toHaveCount(0);
	await page.locator('#quick-layout').selectOption('cards');
	await expect(page.locator('body')).toHaveAttribute('data-layout', 'cards');
	expect(reader.preferences.layout).toBe('cards');
	await expect(page.locator('[name][form=appearance-form]')).toHaveCount(0);
	await page.locator('#open-settings').click();
	await expect(page.locator('input[name=layout][value=cards]')).toBeChecked();
	await expect(page.locator('[data-settings-feed-row]')).toHaveCount(reader.feeds.length);
	await expect(page.locator('#save-reader-settings')).toBeDisabled();
});

test('the saved automatic palette is applied before delayed modules execute under the nonce CSP', async ({ page, readerServer, reader }) => {
	await page.emulateMedia({ colorScheme: 'dark' });
	let release;
	const blocked = new Promise(resolve => { release = resolve; });
	const holdApp = async route => { await blocked; await route.continue(); };
	await page.route('**/scripts/reader/app.js*', holdApp);
	const htmlRoute = async route => {
		const response = await route.fetch();
		const body = (await response.text()).replace(/(<script[^>]+id="reader-bootstrap"[^>]*>)([\s\S]*?)(<\/script>)/,
			(_, start, json, end) => {
				const bootstrap = JSON.parse(json);
				Object.assign(bootstrap.preferences, { theme: 'auto', lightTheme: 'nord', darkTheme: 'cyberpunk' });
				return start + JSON.stringify(bootstrap) + end;
			});
		await route.fulfill({ response, body });
	};
	await page.route(readerServer.origin + '/i/?startup-theme', htmlRoute);
	let readingTop;
	try {
		await page.goto(readerServer.origin + '/i/?startup-theme', { waitUntil: 'commit' });
		await expect(page.locator('.reader-shell')).toBeVisible();
		await expect(page.locator('body')).toHaveAttribute('data-theme', 'cyberpunk');
		// A light palette deliberately assigned to night mode keeps its own colour scheme.
		await expect(page.locator('body')).toHaveCSS('color-scheme', 'light');
		await expect(page.locator('[name][form=appearance-form]')).toHaveCount(0);
		readingTop = await page.locator('.reading-workspace').evaluate(node => node.getBoundingClientRect().top);
		const preloads = await page.locator('link[rel=modulepreload]').evaluateAll(nodes => nodes.map(node => node.href));
		expect(preloads.length).toBeGreaterThan(10);
		expect(preloads.some(url => new URL(url).pathname.includes('/embed/'))).toBe(false);
		for (const url of preloads) expect(new URL(url).searchParams.get('v')).toMatch(/^[a-f0-9]{64}$/);
	} finally {
		release();
		await page.unroute('**/scripts/reader/app.js*', holdApp);
		await page.unroute(readerServer.origin + '/i/?startup-theme', htmlRoute);
	}
	await expect(page.locator('.entry-card')).toHaveCount(4);
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'cyberpunk');
	expect(Math.abs(await page.locator('.reading-workspace').evaluate(node => node.getBoundingClientRect().top) - readingTop)).toBeLessThan(1);
});

test('English navigation and the saved dark colour scheme render before modules execute', async ({ page, readerServer, reader }) => {
	const shell = renderShell('en');
	let release;
	const blocked = new Promise(resolve => { release = resolve; });
	const holdApp = async route => { await blocked; await route.continue(); };
	const documentURL = readerServer.origin + '/i/?startup-en';
	const htmlRoute = route => route.fulfill({ body: shell.html, contentType: 'text/html; charset=utf-8', headers: { 'Content-Security-Policy': shell.csp } });
	await page.route('**/scripts/reader/app.js*', holdApp);
	await page.route(documentURL, htmlRoute);
	const visibleCopy = '.primary-nav [data-i18n], .subscriptions-heading [data-i18n], .toolbar [data-i18n], .article-empty [data-i18n]';
	let initial;
	try {
		await page.goto(documentURL, { waitUntil: 'commit' });
		await expect(page.locator('.reader-shell')).toBeVisible();
		await expect(page.locator('html')).toHaveAttribute('lang', 'en');
		await expect(page.locator('[data-filter=all]')).toHaveText('All articles');
		await expect(page.locator('#open-settings')).toHaveText('Settings');
		await expect(page.locator('#feed-search')).toHaveAttribute('placeholder', 'Filter subscriptions');
		await expect(page.locator('#article-search input')).toHaveAttribute('placeholder', 'Search articles');
		await expect(page.locator('body')).toHaveAttribute('data-theme', 'sunset');
		await expect(page.locator('body')).toHaveCSS('color-scheme', 'dark');
		initial = {
			copy: await page.locator(visibleCopy).allTextContents(),
			top: await page.locator('.reading-workspace').evaluate(node => node.getBoundingClientRect().top),
		};
	} finally {
		release();
		await page.unroute('**/scripts/reader/app.js*', holdApp);
		await page.unroute(documentURL, htmlRoute);
	}
	await expect(page.locator('.entry-card')).toHaveCount(4);
	expect(await page.locator(visibleCopy).allTextContents()).toEqual(initial.copy);
	await expect(page.locator('body')).toHaveCSS('color-scheme', 'dark');
	expect(Math.abs(await page.locator('.reading-workspace').evaluate(node => node.getBoundingClientRect().top) - initial.top)).toBeLessThan(1);
});
