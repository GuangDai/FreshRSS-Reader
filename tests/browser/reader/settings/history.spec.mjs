import { test as liveTest, expect } from '@playwright/test';
import { test as fixtureTest } from '../../fixtures/reader.fixture.mjs';
import { THEME_NAMES } from '../../../../p/scripts/reader/appearance/themes.js';
import { writeFile } from 'node:fs/promises';

async function traceNavigation(page) {
	await page.evaluate(async () => {
		const { ReaderNavigation } = await import('/scripts/reader/core/navigation.js');
		globalThis.readerNavigationTrace = [];
		for (const name of ['transition', 'handlePopState', 'openArticle', 'openPanel', 'closePanel', 'handleFrameNavigation']) {
			const original = ReaderNavigation.prototype[name];
			ReaderNavigation.prototype[name] = function (...args) {
				globalThis.readerNavigationUnderTest = this;
				const describe = state => state ? { selectedId: state.snapshot.selectedId, panels: state.panels, section: state.settingsSection } : null;
				const entry = {
					name,
					current: describe(this.current),
					pending: describe(this.pending?.desired),
					actual: describe(globalThis.history.state?.freshrssReader),
					url: globalThis.location.href,
				};
				if (name === 'handlePopState') entry.target = describe(args[0].state?.freshrssReader);
				if (name === 'transition') entry.next = describe(args[0]);
				if (name === 'handleFrameNavigation') {
					entry.frameHref = args[1];
					entry.sameDocument = this.lastFrameNavigation?.document === args[0];
					entry.sameHref = this.lastFrameNavigation?.href === args[1];
					entry.sameAnchor = JSON.stringify(this.read(globalThis.history.state)) === JSON.stringify(this.pending?.anchor);
				}
				globalThis.readerNavigationTrace.push(entry);
				return original.apply(this, args);
			};
		}
		globalThis.addEventListener('message', event => {
			if (event.data?.type === 'reader-settings-embed') globalThis.readerNavigationTrace.push({ name: 'frameMessage', event: event.data.event });
		});
		const frame = document.getElementById('settings-tool-frame');
		frame.addEventListener('load', () => globalThis.readerNavigationTrace.push({ name: 'frameLoad', href: frame.contentWindow.location.href }));
	});
}

async function attachTrace(page, testInfo) {
	const trace = await page.evaluate(() => globalThis.readerNavigationTrace);
	const path = testInfo.outputPath('navigation-history.json');
	await writeFile(path, JSON.stringify(trace, null, 2));
	await testInfo.attach('navigation-history.json', { path, contentType: 'application/json' });
}

fixtureTest('rapid settings saves and article opens retain the latest article through delayed history events', async ({ page, reader }, testInfo) => {
	fixtureTest.setTimeout(120000);
	await traceNavigation(page);
	try {
		for (const theme of THEME_NAMES) {
			await page.locator('#open-settings').click();
			await expect(page.locator('#settings-page')).toBeVisible();
			await page.locator(`input[type=radio][name=theme][value="${theme}"]`).check();
			await page.locator('#save-reader-settings').click();
			await expect(page.locator('#save-reader-settings')).toBeDisabled();
			await page.locator('#cancel-settings').click();
			await expect(page.locator('#settings-page')).toBeHidden();
			await page.locator('.entry-card').first().click();
			await expect(page.locator('#article-frame'), theme).toBeVisible();
			await expect.poll(() => page.evaluate(() => globalThis.readerNavigationUnderTest.pending === null)).toBe(true);
			await expect(page).toHaveURL(/article=100/);
			await page.locator('#close-article').click();
			await expect(page.locator('body')).toHaveAttribute('data-article-open', 'false');
		}
		expect(reader.writes.filter(write => write.action === 'preferences')).toHaveLength(32);
	} finally { await attachTrace(page, testInfo); }
});

liveTest('returning from a saved native settings form clears joint history before opening an article', async ({ page }, testInfo) => {
	liveTest.skip(!process.env.FRESHRSS_TEST_USER || !process.env.FRESHRSS_TEST_PASSWORD, 'Requires the isolated UI review account.');
	await page.goto('./?c=auth&a=login');
	await page.locator('[name=username]').fill(process.env.FRESHRSS_TEST_USER);
	await page.locator('input[type=password]').fill(process.env.FRESHRSS_TEST_PASSWORD);
	await Promise.all([
		page.waitForURL(url => url.searchParams.get('c') !== 'auth'),
		page.locator('button[type=submit]').click(),
	]);
	await page.goto('./?c=reader&view=settings&section=reading');
	await traceNavigation(page);
	try {
		await page.locator('[data-tool-id="shortcuts"]').click();
		const frame = page.frameLocator('#settings-tool-frame');
		await expect(frame.locator('html')).toHaveClass(/reader-settings-embedded/);
		const response = page.waitForResponse(value => value.request().method() === 'POST' && new URL(value.url()).searchParams.get('a') === 'shortcut');
		await frame.locator('button[type=submit]').first().click();
		expect((await response).status()).toBeLessThan(400);
		await expect(frame.locator('html')).toHaveClass(/reader-settings-embedded/);
		await page.locator('#cancel-settings').click();
		await expect(page.locator('#settings-page')).toBeHidden();
		await page.locator('.entry-card').first().click();
		await expect(page.locator('#article-frame')).toBeVisible();
		await expect.poll(() => page.evaluate(() => globalThis.readerNavigationUnderTest.pending === null)).toBe(true);
		await expect(page).not.toHaveURL(/view=settings/);
		await expect(page).toHaveURL(/article=\d+/);
	} finally { await attachTrace(page, testInfo); }
});

liveTest('a real native form draft survives returning, closing its underlying article and Forward after a completed POST', async ({ page }, testInfo) => {
	liveTest.skip(!process.env.FRESHRSS_TEST_USER || !process.env.FRESHRSS_TEST_PASSWORD, 'Requires the isolated UI review account.');
	await page.goto('./?c=auth&a=login');
	await page.locator('[name=username]').fill(process.env.FRESHRSS_TEST_USER);
	await page.locator('input[type=password]').fill(process.env.FRESHRSS_TEST_PASSWORD);
	await Promise.all([
		page.waitForURL(url => url.searchParams.get('c') !== 'auth'),
		page.locator('button[type=submit]').click(),
	]);
	await page.goto('./?c=reader');
	await page.locator('.entry-card').first().click(); await expect(page.locator('#article-frame')).toBeVisible();
	await page.locator('#open-settings').click(); await page.locator('[data-settings-section="reading"]').click();
	await traceNavigation(page);
	const dialogs = [];
	page.on('dialog', async dialog => { dialogs.push(dialog.type()); await dialog.dismiss(); });
	try {
		await page.locator('[data-tool-id="shortcuts"]').click();
		const frame = page.frameLocator('#settings-tool-frame');
		await expect(frame.locator('html')).toHaveClass(/reader-settings-embedded/);
		const navigated = page.waitForEvent('framenavigated', value => value.parentFrame() && new URL(value.url()).searchParams.get('a') === 'shortcut');
		await frame.locator('button[type=submit]').first().click(); await navigated;
		const input = frame.locator('#normal_view_shortcut');
		const original = await input.inputValue(); const draft = original === 'q' ? 'w' : 'q';
		await input.fill(draft);
		const identity = await frame.locator('body').evaluate(() => {
			globalThis.readerSettingsDraftIdentity = Math.random(); return globalThis.readerSettingsDraftIdentity;
		});
		await expect(page.locator('[data-resume-settings-tool]')).toHaveCount(1);
		await page.locator('#cancel-settings').click();
		await expect(page.locator('#settings-page')).toBeHidden();
		await expect.poll(() => page.evaluate(() => globalThis.readerNavigationUnderTest.pending === null)).toBe(true);
		await expect(page).not.toHaveURL(/view=settings/);
		await expect(input).toHaveValue(draft);
		await expect(page.locator('#article-frame')).toBeVisible();
		await page.locator('#close-article').click();
		await expect(page.locator('body')).toHaveAttribute('data-article-open', 'false');
		await expect.poll(() => page.evaluate(() => globalThis.readerNavigationUnderTest.pending === null)).toBe(true);
		await expect(input).toHaveValue(draft);
		await page.locator('#open-settings').click(); await page.locator('[data-resume-settings-tool]').click();
		await expect(input).toHaveValue(draft);
		await page.locator('#cancel-settings').click();
		await expect.poll(() => page.evaluate(() => globalThis.readerNavigationUnderTest.pending === null)).toBe(true);
		await page.goForward(); await expect(page.locator('[data-settings-panel="reading"]')).toBeVisible();
		await page.locator('[data-resume-settings-tool]').click();
		await expect(input).toHaveValue(draft);
		expect(await frame.locator('body').evaluate(() => globalThis.readerSettingsDraftIdentity)).toBe(identity);
		expect(dialogs).toEqual([]);
	} finally { await attachTrace(page, testInfo); }
});
