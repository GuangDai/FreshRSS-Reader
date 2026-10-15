import { test, expect } from '@playwright/test';

const username = process.env.FRESHRSS_TEST_USER;
const password = process.env.FRESHRSS_TEST_PASSWORD;
const feedLinks = '.open-slider[href*="a=feed"]';
const isPanelRequest = url => url.searchParams.get('ajax') === '1' && url.searchParams.get('a') === 'feed';
let errors;

test.beforeEach(async ({ page }) => {
	test.skip(!username || !password, 'Use an isolated local test account.');
	errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('./?c=auth&a=login');
	await page.locator('[name=username]').fill(username);
	await page.locator('input[type=password]').fill(password);
	await Promise.all([page.waitForURL(url => url.searchParams.get('c') !== 'auth'), page.locator('button[type=submit]').click()]);
});
test.afterEach(() => { expect(errors).toEqual([]); });

test('feed scripts wait for the shared context when assets arrive out of order', async ({ page }) => {
	let mainScript;
	await page.route('**/scripts/main.js?*', route => { mainScript = route; });
	await page.goto('./?c=subscription', { waitUntil: 'domcontentloaded' });
	await expect.poll(() => Boolean(mainScript)).toBe(true);
	await expect.poll(() => page.evaluate(() => typeof window.init_slider)).toBe('function');
	await mainScript.continue();
	await expect(page.locator('#slider')).toHaveAttribute('data-slider-initialised', '1');
	await page.locator(feedLinks).first().click();
	await expect(page.locator('#slider #feed_update')).toBeVisible();
	await expect(page).toHaveURL(/c=subscription#slider$/);
});

test('a stalled settings panel opens immediately, closes with Escape, and ignores late network completion', async ({ page }) => {
	await page.goto('./?c=subscription');
	let pending;
	await page.route(isPanelRequest, route => { pending = route; });
	await page.evaluate(() => { window.context.ajax_loading = true; });
	const trigger = page.locator(feedLinks).first();
	await trigger.click();
	await expect(page.locator('#slider-content')).toHaveAttribute('aria-busy', 'true');
	await expect(page.locator('#slider-content [role=status]')).toBeVisible();
	await expect(page.locator('#slider .toggle_aside')).toBeFocused();
	await expect.poll(() => Boolean(pending)).toBe(true);
	await page.keyboard.press('Escape');
	await expect(page.locator('#slider')).not.toHaveClass(/active/);
	await expect(page.locator('#slider')).toHaveAttribute('inert', '');
	await expect(trigger).toBeFocused();
	await pending.fulfill({ contentType: 'text/html', body: '<p id="late-settings">Late settings</p>' }).catch(() => {});
	await expect(page.locator('#late-settings')).toHaveCount(0);
	expect(await page.evaluate(() => window.context.ajax_loading)).toBe(true);
});

test('changing settings targets cancels the older request, and failure retries inside the panel', async ({ page }) => {
	await page.goto('./?c=subscription');
	test.skip(await page.locator(feedLinks).count() < 2, 'Two local subscriptions are required.');
	const pending = [];
	await page.route(isPanelRequest, route => { pending.push(route); });
	await page.locator(feedLinks).first().click();
	await expect.poll(() => pending.length).toBe(1);
	await page.locator(feedLinks).nth(1).evaluate(link => link.click());
	await expect.poll(() => pending.length).toBe(2);
	await pending[0].fulfill({ contentType: 'text/html', body: '<p id="stale-settings">Old settings</p>' }).catch(() => {});
	await pending[1].fulfill({ status: 503, body: 'Temporary failure' });
	await expect(page.locator('#slider [role=alert]')).toBeVisible();
	await expect(page.locator('#stale-settings')).toHaveCount(0);
	await page.locator('#slider [data-panel-retry]').click();
	await expect.poll(() => pending.length).toBe(3);
	await pending[2].fulfill({ contentType: 'text/html', body: '<h1 id="current-settings">Current settings</h1>' });
	await expect(page.locator('#current-settings')).toBeVisible();
	await expect(page.locator('#slider-content')).toHaveAttribute('aria-busy', 'false');
});

test('settings drafts survive rejected close and target changes; closing restores keyboard focus', async ({ page }) => {
	await page.goto('./?c=subscription');
	const links = page.locator(feedLinks);
	test.skip(await links.count() < 2, 'Two local subscriptions are required.');
	await links.first().click();
	await expect(page.locator('#feed_update')).toBeVisible();
	await page.locator('#name').fill('Unsaved local draft');
	page.once('dialog', dialog => dialog.dismiss());
	await page.keyboard.press('Escape');
	await expect(page.locator('#name')).toHaveValue('Unsaved local draft');
	page.once('dialog', dialog => dialog.dismiss());
	await links.nth(1).evaluate(link => link.click());
	await expect(page.locator('#name')).toHaveValue('Unsaved local draft');
	await links.first().evaluate(link => link.click());
	await expect(page.locator('#name')).toHaveValue('Unsaved local draft');
	page.once('dialog', dialog => dialog.dismiss());
	await page.evaluate(() => { window.location.hash = 'close'; });
	await expect(page).toHaveURL(/#slider$/);
	await expect(page.locator('#name')).toHaveValue('Unsaved local draft');
	await page.locator('#slider .toggle_aside').focus();
	await page.keyboard.press('Shift+Tab');
	expect(await page.evaluate(() => document.getElementById('slider').contains(document.activeElement))).toBe(true);
	page.once('dialog', dialog => dialog.accept());
	await page.keyboard.press('Escape');
	await expect(links.first()).toBeFocused();
	await expect(page.locator('#slider')).not.toHaveClass(/active/);
});

test('Back closes settings and Forward reloads the same settings without adding history entries', async ({ page }) => {
	await page.goto('./?c=subscription');
	await page.locator(feedLinks).first().click();
	await expect(page.locator('#feed_update')).toBeVisible();
	const feed = await page.locator('#feed_update').getAttribute('data-feed-id');
	const historyLength = await page.evaluate(() => window.history.length);
	await page.goBack();
	await expect(page).toHaveURL(/c=subscription$/);
	await expect(page.locator('#slider')).not.toHaveClass(/active/);
	await page.goForward();
	await expect(page.locator('#feed_update')).toHaveAttribute('data-feed-id', feed);
	await expect(page.locator('#slider')).toHaveClass(/active/);
	expect(await page.evaluate(() => window.history.length)).toBe(historyLength);
});

test('preview loading is local, can be cancelled without closing settings, and stale frames cannot replace a new preview', async ({ page }) => {
	await page.goto('./?c=subscription');
	await page.locator(feedLinks).first().click();
	await expect(page.locator('#feed_update')).toBeVisible();
	const pending = [];
	await page.route(url => url.searchParams.get('a') === 'contentSelectorPreview', route => { pending.push(route); });
	const preview = page.locator('#popup-preview-selector');
	await preview.click();
	await expect(page.locator('#popup [role=status]')).toBeVisible();
	await expect(page.locator('#popup [data-panel-retry]')).toBeHidden();
	await expect(page.locator('#popup-close')).toBeFocused();
	await expect.poll(() => pending.length).toBe(1);
	await page.keyboard.press('Escape');
	await expect(page.locator('#popup')).toBeHidden();
	await expect(page.locator('#slider')).toHaveClass(/active/);
	await expect(preview).toBeFocused();
	await preview.click();
	await expect.poll(() => pending.length).toBe(2);
	await pending[0].fulfill({ contentType: 'text/html', body: '<p id="old-preview">Old preview</p>' }).catch(() => {});
	await pending[1].fulfill({ contentType: 'text/html', body: '<button id="current-preview">Current preview</button>' });
	await expect(page.frameLocator('#popup-iframe').locator('#current-preview')).toBeVisible();
	await expect(page.locator('#popup-iframe-container')).toHaveAttribute('aria-busy', 'false');
	await expect(page.locator('#popup [role=status]')).toBeHidden();
	await page.frameLocator('#popup-iframe').locator('#current-preview').focus();
	await page.keyboard.press('Tab');
	await expect(page.locator('#popup-close')).toBeFocused();
	await page.frameLocator('#popup-iframe').locator('#current-preview').focus();
	await page.keyboard.press('Escape');
	await expect(page.locator('#popup')).toBeHidden();
	await expect(preview).toBeFocused();
});

test('a timed out preview offers retry without clearing selector input', async ({ page }) => {
	await page.goto('./?c=subscription');
	await page.locator(feedLinks).first().click();
	await expect(page.locator('#feed_update')).toBeVisible();
	await page.evaluate(() => { window.context.network.slider_timeout = 0.1; });
	await page.locator('#path_entries').fill('article');
	let pending;
	await page.route(url => url.searchParams.get('a') === 'contentSelectorPreview', route => { pending = route; });
	await page.locator('#popup-preview-selector').click();
	await expect(page.locator('#popup [role=alert]')).toBeVisible();
	await expect(page.locator('#popup [data-panel-retry]')).toBeVisible();
	await page.locator('#popup-close').press('Enter');
	await expect(page.locator('#popup')).toBeHidden();
	await expect(page.locator('#path_entries')).toHaveValue('article');
	await pending?.abort().catch(() => {});
});

for (const width of [320, 390]) {
	test(`reading rules have one accessible heading per group at ${width}px`, async ({ page }) => {
		await page.setViewportSize({ width, height: 844 });
		await page.goto('./?c=configure&a=reading');
		const reading = page.locator('fieldset.form-group').filter({ has: page.locator('#check_open_article') });
		const received = page.locator('fieldset.form-group').filter({ has: page.locator('#mark_upon_reception') });
		await expect(reading.locator('legend')).toHaveCount(1);
		await expect(reading.locator('label.checkbox')).toHaveCount(4);
		await expect(received.locator('legend')).toHaveCount(1);
		await expect(received.locator('label.checkbox')).toHaveCount(3);
		for (const group of [reading, received]) {
			await group.scrollIntoViewIfNeeded();
			const box = await group.boundingBox();
			expect(box.x).toBeGreaterThanOrEqual(0);
			expect(box.x + box.width).toBeLessThanOrEqual(width + 1);
		}
		const old = await page.locator('#check_open_site').isChecked();
		await reading.locator('label[for=check_open_site]').click();
		expect(await page.locator('#check_open_site').isChecked()).toBe(!old);
	});
}

for (const viewport of [{ width: 320, height: 844 }, { width: 568, height: 320 }]) {
	test(`settings close remains reachable after scrolling at ${viewport.width} × ${viewport.height}`, async ({ page }) => {
		await page.setViewportSize(viewport);
		await page.goto('./?c=subscription');
		await page.locator(feedLinks).first().click();
		await expect(page.locator('#feed_update')).toBeVisible();
		await page.locator('#path_entries').scrollIntoViewIfNeeded();
		const close = page.locator('#slider > .toggle_aside');
		await expect(close).toBeInViewport();
		const box = await close.boundingBox();
		expect(box.width).toBeGreaterThanOrEqual(44);
		expect(box.height).toBeGreaterThanOrEqual(44);
		await close.click();
		await expect(page.locator('#slider')).not.toHaveClass(/active/);
	});
}
