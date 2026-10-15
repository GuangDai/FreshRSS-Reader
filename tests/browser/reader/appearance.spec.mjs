import { test, expect } from '../fixtures/reader.fixture.mjs';
import { THEME_NAMES, themeSpec } from '../../../p/scripts/reader/appearance/themes.js';
import { readFile, writeFile } from 'node:fs/promises';

/** Measure rendered sRGB colours, including translucent backgrounds, independently of the theme implementation. */
function textContrastSamples(selectors) {
	const canvas = document.createElement('canvas'); canvas.width = canvas.height = 1;
	const context = canvas.getContext('2d', { willReadFrequently: true });
	const rgb = colour => {
		context.clearRect(0, 0, 1, 1); context.fillStyle = colour; context.fillRect(0, 0, 1, 1);
		return Array.from(context.getImageData(0, 0, 1, 1).data);
	};
	const blend = (colour, background) => colour.slice(0, 3).map((value, index) => value * colour[3] / 255 + background[index] * (1 - colour[3] / 255));
	const luminance = colour => {
		const channels = colour.map(value => {
			value /= 255;
			return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
		});
		return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
	};
	return selectors.map(selector => {
		const node = document.querySelector(selector);
		const layers = [];
		for (let current = node; current; current = current.parentElement) layers.unshift(rgb(globalThis.getComputedStyle(current).backgroundColor));
		const background = layers.reduce((result, colour) => blend(colour, result), [255, 255, 255]);
		const foreground = blend(rgb(globalThis.getComputedStyle(node).color), background);
		const light = [luminance(background), luminance(foreground)].sort((a, b) => a - b);
		return { selector, foreground, background, ratio: (light[1] + 0.05) / (light[0] + 0.05) };
	});
}

async function openSettings(page) {
	if (!(await page.locator('#settings-page').isVisible())) await page.locator('#open-settings').click();
	await expect(page.locator('#settings-page')).toBeVisible();
	await selectSection(page, 'appearance');
}

async function selectSection(page, section) {
	const desktop = page.locator(`[data-settings-section="${section}"]`);
	if (await desktop.isVisible()) await desktop.click();
	else await page.locator('#settings-section-select').selectOption(section);
	await expect(page.locator(`[data-settings-panel="${section}"]`)).toBeVisible();
}

async function articleColours(page) {
	return page.frameLocator('#article-frame').locator('body').evaluate(node => ({
		surface: globalThis.getComputedStyle(node).backgroundColor,
		text: globalThis.getComputedStyle(node).color,
		scheme: globalThis.getComputedStyle(document.documentElement).colorScheme,
	}));
}

test('theme selection stays a draft without changing the page or article', async ({ page, reader }) => {
	const mobile = await page.locator('#toggle-sidebar').isVisible();
	let article;
	if (!mobile) {
		await page.locator('.entry-card').first().click();
		await expect(page.locator('#article-frame')).toBeVisible();
		article = await articleColours(page);
		await page.evaluate(() => { globalThis.originalArticleFrame = document.getElementById('article-frame'); });
	}
	await openSettings(page);
	expect(THEME_NAMES).toHaveLength(32);
	const select = page.locator('[name=theme]');
	expect(await select.locator('option').evaluateAll(nodes => nodes.map(node => node.value).sort())).toEqual(['auto', ...THEME_NAMES].sort());
	await expect(select.locator('optgroup')).toHaveCount(2);
	await expect(page.locator('[data-choose-theme], .appearance-preview, #reading-preview')).toHaveCount(0);
	for (const name of THEME_NAMES) {
		await select.selectOption(name);
		await expect(select).toHaveValue(name);
		await expect(page.locator('body')).toHaveAttribute('data-theme', reader.bootstrap.preferences.theme);
	}
	if (!mobile) {
		expect(await articleColours(page)).toEqual(article);
		expect(await page.evaluate(() => document.getElementById('article-frame') === globalThis.originalArticleFrame)).toBe(true);
	}
	expect(reader.writes).toHaveLength(0);
	await select.selectOption('nord');
	await page.locator('#cancel-settings').click();
	await expect(page.locator('body')).toHaveAttribute('data-theme', reader.bootstrap.preferences.theme);
	await expect(page.locator('#settings-page')).toBeHidden();
	await openSettings(page);
	await expect(select).toHaveValue('nord');
	await expect(page.locator('body')).toHaveAttribute('data-theme', reader.bootstrap.preferences.theme);
	await page.locator('#discard-settings').click();
	await expect(select).toHaveValue(reader.bootstrap.preferences.theme);
	expect(reader.writes).toHaveLength(0);
});

test('all 32 saved themes match visible article colours and preserve readable article text', async ({ page, reader }, testInfo) => {
	test.setTimeout(90000);
	const pageErrors = [];
	page.on('pageerror', error => pageErrors.push(error.message));
	await page.evaluate(() => {
		globalThis.readerThemeHistory = [];
		globalThis.addEventListener('popstate', () => globalThis.readerThemeHistory.push({
			url: globalThis.location.href,
			selectedId: globalThis.history.state?.freshrssReader?.snapshot.selectedId,
			panels: globalThis.history.state?.freshrssReader?.panels,
			articleOpen: document.body.dataset.articleOpen,
		}));
	});
	const contrast = [];
	const settingsContrast = [];
	for (const name of THEME_NAMES) {
		await openSettings(page);
		await page.locator('[name=theme]').selectOption(name);
		await page.locator('#save-reader-settings').click();
		await expect(page.locator('#save-reader-settings')).toBeDisabled();
		await expect(page.locator('body')).toHaveAttribute('data-theme', name);
		expect(await page.locator('body').evaluate(node => globalThis.getComputedStyle(node).getPropertyValue('--color-base-100').trim()))
			.toBe(themeSpec(name).tokens['--color-base-100']);
		const settingsSamples = await page.evaluate(textContrastSamples, ['body', '.setting-help', '#settings-section-description', '#save-reader-settings']);
		settingsContrast.push({ theme: name, samples: settingsSamples });
		for (const sample of settingsSamples) expect.soft(sample.ratio, `${name}: ${sample.selector}`).toBeGreaterThanOrEqual(4.5);
		await page.locator('#cancel-settings').click();
		await expect(page.locator('#settings-page')).toBeHidden();
		await page.locator('.entry-card').first().click();
		try { await expect(page.locator('#article-frame'), `${name}: reopened article`).toBeVisible(); } catch (error) {
			const history = await page.evaluate(() => ({
				url: globalThis.location.href, state: globalThis.history.state?.freshrssReader, events: globalThis.readerThemeHistory,
			}));
			const path = testInfo.outputPath('article-theme-history.json');
			await writeFile(path, JSON.stringify({ theme: name, pageErrors, ...history }, null, 2));
			await testInfo.attach('article-theme-history.json', { path, contentType: 'application/json' });
			throw error;
		}
		const shell = await page.locator('body').evaluate(node => ({
			surface: globalThis.getComputedStyle(node).backgroundColor, text: globalThis.getComputedStyle(node).color,
		}));
		await expect.poll(() => articleColours(page)).toEqual({ ...shell, scheme: themeSpec(name).dark ? 'dark' : 'light' });
		const frame = await (await page.locator('#article-frame').elementHandle()).contentFrame();
		const samples = await frame.evaluate(textContrastSamples, ['body', 'a', '.meta', 'pre']);
		contrast.push({ theme: name, samples });
		for (const sample of samples) expect.soft(sample.ratio, `${name}: article ${sample.selector}`).toBeGreaterThanOrEqual(4.5);
		await page.locator('#close-article').click();
		await expect(page.locator('body')).toHaveAttribute('data-article-open', 'false');
	}
	expect(pageErrors).toEqual([]);
	expect(reader.writes.filter(write => write.action === 'preferences')).toHaveLength(32);
	const path = testInfo.outputPath('article-theme-contrast.json');
	await writeFile(path, JSON.stringify(contrast, null, 2));
	await testInfo.attach('article-theme-contrast.json', { path, contentType: 'application/json' });
	const settingsPath = testInfo.outputPath('theme-contrast.json');
	await writeFile(settingsPath, JSON.stringify(settingsContrast, null, 2));
	await testInfo.attach('theme-contrast.json', { path: settingsPath, contentType: 'application/json' });
});

test('saved system mode follows the system while unsaved theme choices stay in the form', async ({ page, reader }, testInfo) => {
	await openSettings(page);
	await page.emulateMedia({ colorScheme: 'light' });
	await page.locator('[name=theme]').selectOption('auto');
	await expect(page.locator('[name=lightTheme]')).toBeVisible();
	await expect(page.locator('[name=darkTheme]')).toBeVisible();
	await page.locator('[name=lightTheme]').selectOption('cyberpunk');
	await page.locator('[name=darkTheme]').selectOption('dracula');
	await expect(page.locator('body')).toHaveAttribute('data-theme', reader.bootstrap.preferences.theme);
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'cyberpunk');
	await page.emulateMedia({ colorScheme: 'dark' });
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'dracula');
	const theme = page.locator('[name=theme]');
	await theme.focus(); await expect(theme).toBeFocused();
	await theme.selectOption('nord');
	await expect(page.locator('[name=theme]')).toHaveValue('nord');
	await expect(page.locator('[name=lightTheme]')).not.toBeVisible();
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'dracula');
	await page.emulateMedia({ colorScheme: 'light' });
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'cyberpunk');
	await expect(theme).toHaveValue('nord');
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('#settings-page')).toBeVisible();
	await expect(page.locator('#save-reader-settings')).toBeDisabled();
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'nord');
	expect(reader.writes).toHaveLength(2);
	expect(reader.preferences).toMatchObject({ theme: 'nord', lightTheme: 'cyberpunk', darkTheme: 'dracula' });
	await page.reload();
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'nord');
	await openSettings(page);
	await expect(page.locator('[name=theme]')).toHaveValue('nord');
	await expect(page.locator('[name=lightTheme]')).toHaveValue('cyberpunk');
	await expect(page.locator('[name=darkTheme]')).toHaveValue('dracula');
	await page.locator('#cancel-settings').click();
	await page.locator('.entry-card').first().click();
	await expect(page.locator('#article-frame')).toBeVisible();
	await page.screenshot({ animations: 'disabled', path: testInfo.outputPath('nord-reader.png') });
});

test('failed theme saves leave the current appearance intact and keep the selected choice for retry', async ({ page, reader }) => {
	await openSettings(page);
	await page.locator('[name=theme]').selectOption('nord');
	const fail = route => route.fulfill({ status: 503, json: { version: 1, error: 'write_failed' } });
	await page.route('**/i/?c=reader&a=preferences*', fail);
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('#settings-status')).toBeVisible();
	await expect(page.locator('[name=theme]')).toHaveValue('nord');
	await expect(page.locator('body')).toHaveAttribute('data-theme', reader.bootstrap.preferences.theme);
	await expect(page.locator('#save-reader-settings')).toBeEnabled();
	await page.unroute('**/i/?c=reader&a=preferences*', fail);
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'nord');
	expect(reader.preferences.theme).toBe('nord');
});

test('a delayed save applies its saved theme without overwriting a newer unsaved choice', async ({ page, reader }) => {
	await openSettings(page);
	await page.locator('[name=theme]').selectOption('nord');
	let release;
	let received;
	const pending = new Promise(resolve => { release = resolve; });
	const requestReceived = new Promise(resolve => { received = resolve; });
	const delay = async route => {
		const values = route.request().postDataJSON().preferences; received(); await pending;
		Object.assign(reader.preferences, values);
		await route.fulfill({ json: { version: 1, schema: reader.bootstrap.schema, preferences: reader.preferences } });
	};
	await page.route('**/i/?c=reader&a=preferences*', delay);
	await page.locator('#save-reader-settings').click(); await requestReceived;
	await page.locator('[name=theme]').selectOption('dracula');
	release();
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'nord');
	await expect(page.locator('[name=theme]')).toHaveValue('dracula');
	await expect(page.locator('#save-reader-settings')).toBeEnabled();
	await page.unroute('**/i/?c=reader&a=preferences*', delay);
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'dracula');
	expect(reader.preferences.theme).toBe('dracula');
});

test('export round-trips draft values, legacy imports stay unapplied until saved, and preferences survive reload', async ({ page, reader }) => {
	await openSettings(page);
	await page.locator('[name=theme]').selectOption('nord');
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'nord');
	await page.locator('[name=theme]').selectOption('dracula');
	await selectSection(page, 'reading');
	await page.locator('[name=fontSize]').fill('22');
	await selectSection(page, 'data');
	const downloaded = page.waitForEvent('download');
	await page.locator('#export-settings').click();
	const data = JSON.parse(await readFile(await (await downloaded).path(), 'utf8'));
	expect(data).toMatchObject({ format: 'freshrss-reader', version: 1, preferences: { theme: 'dracula', fontSize: 22 } });
	await page.locator('#reset-settings').click();
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'nord');
	await page.locator('#import-file').setInputFiles({ name: 'reader.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(data)) });
	await expect(page.locator('[name=theme]')).toHaveValue('dracula');
	await expect(page.locator('[name=fontSize]')).toHaveValue('22');
	const legacy = { format: 'freshrss-reader', version: 1, preferences: { theme: 'dark', style: 'paper', fontSize: 20 } };
	await page.locator('#import-file').setInputFiles({ name: 'legacy-reader.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(legacy)) });
	await expect(page.locator('[name=theme]')).toHaveValue('dark');
	await expect(page.locator('[name=fontSize]')).toHaveValue('20');
	await expect(page.locator('[name=lightTheme]')).toHaveValue('nord');
	await expect(page.locator('[name=darkTheme]')).toHaveValue('sunset');
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'nord');
	await expect(page.locator('body')).toHaveAttribute('data-style', reader.bootstrap.preferences.style);
	expect(reader.writes).toHaveLength(1);
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('#settings-page')).toBeVisible();
	await expect(page.locator('#save-reader-settings')).toBeDisabled();
	await page.reload();
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'dark');
	await expect(page.locator('body')).toHaveAttribute('data-style', 'paper');
	await page.locator('#cancel-settings').click();
	await page.locator('.entry-card').first().click();
	await expect(page.frameLocator('#article-frame').locator('body')).toHaveCSS('font-size', '20px');
	expect(reader.writes).toHaveLength(2);
});

test('appearance has labelled form controls and invalid values reveal the field in its category', async ({ page, reader }, testInfo) => {
	await openSettings(page);
	await expect(page.locator('[data-settings-panel=storage]')).toBeHidden();
	await expect(page.locator('[name=cacheArticles]')).not.toBeVisible();
	const theme = await page.locator('[name=theme]').boundingBox();
	const settings = await page.locator('#settings-page').boundingBox();
	expect(theme.y).toBeGreaterThanOrEqual(settings.y);
	expect(theme.y + theme.height).toBeLessThanOrEqual(settings.y + settings.height);
	await expect(page.locator('[name=theme]')).toHaveAccessibleName('主题配色');
	await expect(page.locator('[name=sidebarWidth]')).not.toBeVisible();
	await page.screenshot({ animations: 'disabled', path: testInfo.outputPath('appearance.png') });
	await selectSection(page, 'storage');
	await page.locator('[name=cacheArticles]').fill('');
	await selectSection(page, 'appearance');
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('[data-settings-panel=storage]')).toBeVisible();
	await expect(page.locator('[name=cacheArticles]')).toBeFocused();
	await expect(page.locator('[name=cacheArticles]')).toHaveAttribute('aria-invalid', 'true');
	expect(reader.writes).toHaveLength(0);
});

test('subscription failure has text feedback and touch navigation remains usable in portrait and landscape', async ({ page, reader }, testInfo) => {
	const mobile = await page.locator('#toggle-sidebar').isVisible();
	if (testInfo.project.use.isMobile) expect(mobile).toBe(true);
	if (mobile) {
		await expect(page.locator('#reader-sidebar')).toHaveJSProperty('inert', true);
		await page.locator('#toggle-sidebar').click();
		await expect(page.locator('#feed-search')).toBeFocused();
		await expect(page.locator('#reader-sidebar #toggle-feed-selection, #reader-sidebar .sidebar-bottom')).toHaveCount(0);
	}
	await expect(page.locator('[data-feed="2"]')).toHaveAccessibleName(/抓取失败/);
	await expect(page.locator('[data-feed="2"]')).toHaveAttribute('title', /抓取失败/);
	await expect(page.locator('[data-feed="2"] .feed-dot')).toHaveText('▲');
	if (mobile) { await page.keyboard.press('Escape'); await expect(page.locator('#toggle-sidebar')).toBeFocused(); }
	const settings = await page.locator('#open-settings').boundingBox();
	if (mobile) { expect(settings.width).toBeGreaterThanOrEqual(44); expect(settings.height).toBeGreaterThanOrEqual(44); }
	const first = page.locator('.entry-card').first();
	await first.focus(); await page.keyboard.press('Enter');
	await expect(page.locator('#close-article')).toBeFocused();
	await expect(page.locator('#article-frame')).toBeVisible();
	if (mobile) {
		await expect(page.locator('.article-list-pane')).toHaveJSProperty('inert', true);
		const frame = await page.locator('#article-frame').boundingBox();
		expect(frame.x).toBe(0); expect(frame.width).toBe(page.viewportSize().width);
	}
	expect(await page.evaluate(() => document.documentElement.scrollWidth <= globalThis.innerWidth)).toBe(true);
	await page.screenshot({ animations: 'disabled', path: testInfo.outputPath('article.png') });
	await page.keyboard.press('Escape');
	await expect(first).toBeFocused();
	await expect(page.locator('body')).toHaveAttribute('data-article-open', 'false');
});
