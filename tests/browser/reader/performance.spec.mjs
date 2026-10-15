import { test, expect } from '@playwright/test';

test('large subscription trees reuse nodes while articles change and counts refresh', async ({ page }) => {
	const username = process.env.FRESHRSS_TEST_USER;
	const password = process.env.FRESHRSS_TEST_PASSWORD;
	test.skip(!username || !password, 'Use an isolated local test account.');
	await page.goto('./?c=auth&a=login');
	await page.locator('[name=username]').fill(username);
	await page.locator('input[type=password]').fill(password);
	await Promise.all([page.waitForURL(url => url.searchParams.get('c') !== 'auth'), page.locator('button[type=submit]').click()]);
	// Tree rendering measurements exclude article-state writes and remain safe for the shared fixture account.
	await page.route(url => url.searchParams.get('c') === 'reader' && !url.searchParams.has('a'), async route => {
		const response = await route.fetch();
		const body = (await response.text()).replace(/(<script[^>]+id="reader-bootstrap"[^>]*>)([\s\S]*?)(<\/script>)/,
			(_, start, json, end) => start + JSON.stringify({ ...JSON.parse(json), autoMarkArticle: false, autoMarkSite: false }) + end);
		await route.fulfill({ response, body });
	});
	const categories = Array.from({ length: 100 }, (_, index) => ({ id: index + 1, name: `Category ${index + 1}` }));
	const feeds = Array.from({ length: 5000 }, (_, index) => ({
		id: index + 1, name: `Feed ${index + 1}`, categoryId: Math.floor(index / 50) + 1, unread: 1, error: false, priority: 10, enabled: true,
	}));
	await page.route('**/*c=reader&a=feeds*', route => route.fulfill({ json: { version: 1, categories, feeds } }));
	await page.route('**/*c=reader&a=article*', route => {
		const id = new URL(route.request().url()).searchParams.get('id');
		return route.fulfill({
			json: {
				version: 1,
				article: { id, title: `Article ${id}`, content: '<p>Performance fixture</p>', link: '', feedId: 1, read: false, starred: false },
			}
		});
	});
	await page.goto('./?c=reader');
	await expect(page.locator('.feed-button')).toHaveCount(5000);
	await expect(page.locator('[data-toggle-category][aria-expanded=false]')).toHaveCount(100);
	await expect(page.locator('.feed-group-feeds:visible')).toHaveCount(0);
	const firstToggle = page.locator('[data-toggle-category="1"]');
	const secondToggle = page.locator('[data-toggle-category="2"]');
	await firstToggle.click();
	await expect(firstToggle).toHaveAttribute('aria-expanded', 'true');
	await firstToggle.click(); await secondToggle.click();
	await expect(firstToggle).toHaveAttribute('aria-expanded', 'false');
	await expect(secondToggle).toHaveAttribute('aria-expanded', 'true');
	await expect(page.locator('.entry-card').first()).toBeVisible();
	await page.evaluate(() => {
		globalThis.feedMutations = 0;
		globalThis.firstFeedNode = document.querySelector('.feed-button');
		const observer = new globalThis.MutationObserver(records => { globalThis.feedMutations += records.length; });
		observer.observe(document.getElementById('feed-tree'), { attributes: true, childList: true, subtree: true });
	});
	for (let index = 0; index < 20; index++) {
		await page.locator('.entry-card').nth(index % 5).click();
		await expect(page.locator('#article-frame')).toBeVisible();
		await page.locator('#close-article').click();
	}
	expect(await page.evaluate(() => globalThis.feedMutations)).toBe(0);
	feeds[0].unread = 2; feeds[0].name = 'Renamed feed';
	await page.locator('#refresh').click();
	await expect(page.locator('.feed-name').first()).toHaveText('Renamed feed');
	expect(await page.evaluate(() => globalThis.firstFeedNode === document.querySelector('.feed-button'))).toBe(true);
	await expect(firstToggle).toHaveAttribute('aria-expanded', 'false');
	await expect(secondToggle).toHaveAttribute('aria-expanded', 'true');
	await page.locator('#feed-search').fill('Renamed feed');
	await expect(page.locator('.feed-button:visible')).toHaveCount(1);
	await expect(firstToggle).toHaveAttribute('aria-expanded', 'true');
	await expect(page.locator('[data-category-unread="1"]')).toHaveText('51');
	await page.locator('#feed-search').fill('');
	await expect(firstToggle).toHaveAttribute('aria-expanded', 'false');
	await expect(secondToggle).toHaveAttribute('aria-expanded', 'true');
	await expect(page.locator('#article-frame')).toHaveCount(1);
	const pageSize = await page.locator('#reader-bootstrap').evaluate(node => JSON.parse(node.textContent).preferences.pageSize);
	await expect(page.locator('.entry-card')).toHaveCount(pageSize);
});
