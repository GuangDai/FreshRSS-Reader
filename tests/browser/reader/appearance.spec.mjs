import { test, expect } from '../fixtures/reader.fixture.mjs';
import { THEME_NAMES, themeSpec } from '../../../p/scripts/reader/appearance/themes.js';
import { readFile, writeFile } from 'node:fs/promises';
import {
	appearanceGroup as choiceGroup, appearanceChoice as choiceTile, selectedAppearance as selectedChoice,
	chooseAppearance as choose, expandThemeChoices, revealAppearanceChoice
} from '../helpers/appearance.mjs';

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

function previewSurface(page) { return page.locator('[data-appearance-preview] > [data-appearance-preview-surface]'); }

async function articleColours(page) {
	return page.frameLocator('#article-frame').locator('body').evaluate(node => ({
		surface: globalThis.getComputedStyle(node).backgroundColor,
		text: globalThis.getComputedStyle(node).color,
		scheme: globalThis.getComputedStyle(document.documentElement).colorScheme,
	}));
}

test('visual theme choices update an explained local preview while preserving the page and article', async ({ page, reader }) => {
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
	const group = choiceGroup(page, 'theme');
	expect(await group.locator('input[type="radio"]').evaluateAll(nodes => nodes.map(node => node.value).sort())).toEqual(['auto', ...THEME_NAMES].sort());
	await expect(page.locator('select[name="theme"], select[name="lightTheme"], select[name="darkTheme"]')).toHaveCount(0);
	await expect(group).toHaveAccessibleName('主题配色');
	await expect(page.locator('[data-appearance-preview]')).toBeVisible();
	await expect(page.locator('[data-appearance-preview]')).toContainText(/预览/);
	await expect(page.locator('[data-appearance-preview]')).toContainText(/保存/);
	for (const name of THEME_NAMES) {
		const tile = choiceTile(page, 'theme', name);
		await choose(page, 'theme', name);
		await expect(tile.locator('.reader-miniature')).toHaveAttribute('data-theme', name);
		await expect(tile.locator('.mini-sidebar, .mini-list, .mini-article')).toHaveCount(3);
		await expect(selectedChoice(page, 'theme')).toHaveValue(name);
		await expect(tile).toHaveAttribute('data-selected', 'true');
		await expect(group.locator('input:checked')).toHaveCount(1);
		await expect(previewSurface(page)).toHaveAttribute('data-theme', name);
		await expect(page.locator('body')).toHaveAttribute('data-theme', reader.bootstrap.preferences.theme);
	}
	if (!mobile) {
		expect(await articleColours(page)).toEqual(article);
		expect(await page.evaluate(() => document.getElementById('article-frame') === globalThis.originalArticleFrame)).toBe(true);
	}
	expect(reader.writes).toHaveLength(0);
	await choose(page, 'theme', 'nord');
	const lightPreview = await previewSurface(page).locator('.reader-miniature').evaluate(node => globalThis.getComputedStyle(node).backgroundColor);
	await choose(page, 'theme', 'dracula');
	const darkPreview = await previewSurface(page).locator('.reader-miniature').evaluate(node => globalThis.getComputedStyle(node).backgroundColor);
	expect(darkPreview).not.toBe(lightPreview);
	await choose(page, 'theme', 'nord');
	const expand = page.locator('[data-appearance-preview] > header .appearance-preview-expand');
	await expand.click();
	const dialog = page.getByRole('dialog', { name: '外观预览', exact: true });
	await expect(dialog).toBeVisible();
	const enlarged = dialog.locator('[data-appearance-preview-surface]');
	await expect(enlarged).toHaveAttribute('data-theme', 'nord');
	for (const key of ['layout', 'density', 'style']) {
		await expect(enlarged).toHaveAttribute('data-' + key, await selectedChoice(page, key).inputValue());
	}
	await expect(dialog.locator('.mini-article')).toContainText(/\S+/);
	const paneControls = dialog.locator('.appearance-preview-areas');
	const narrowPreview = page.viewportSize().width <= 720;
	if (narrowPreview) {
		await expect(paneControls).toBeVisible();
		const originalChoices = await page.locator('input[type="radio"][form="appearance-form"]:checked')
			.evaluateAll(nodes => nodes.map(node => [node.name, node.value]));
		for (const pane of ['sidebar', 'list', 'article']) {
			await paneControls.locator(`[data-preview-pane="${pane}"]`).click();
			await expect(paneControls.locator('[aria-pressed="true"]')).toHaveAttribute('data-preview-pane', pane);
			await expect(enlarged).toHaveAttribute('data-preview-pane', pane);
			await expect(enlarged.locator('.reader-miniature')).toHaveAttribute('data-preview-pane', pane);
			for (const region of ['sidebar', 'list', 'article']) {
				const content = enlarged.locator('.mini-' + region);
				if (region === pane) {
					await expect(content).toBeVisible();
					const bounds = await content.boundingBox();
					expect(bounds.width).toBeGreaterThanOrEqual((await dialog.boundingBox()).width * 0.8);
				} else await expect(content).toBeHidden();
			}
			await expect(enlarged).toHaveAttribute('data-theme', 'nord');
		}
		expect(await page.locator('input[type="radio"][form="appearance-form"]:checked')
			.evaluateAll(nodes => nodes.map(node => [node.name, node.value]))).toEqual(originalChoices);
		expect(reader.writes).toHaveLength(0);
	} else {
		await expect(paneControls).toBeHidden();
		for (const pane of ['sidebar', 'list', 'article']) await expect(enlarged.locator('.mini-' + pane)).toBeVisible();
	}
	await expect(page.locator('body')).toHaveAttribute('data-theme', reader.bootstrap.preferences.theme);
	await page.keyboard.press('Escape');
	await expect(dialog).toBeHidden();
	await expect(expand).toBeFocused();
	await expect(page.locator('#settings-page')).toBeVisible();
	await expect(selectedChoice(page, 'theme')).toHaveValue('nord');
	await page.locator('#cancel-settings').click();
	await expect(page.locator('body')).toHaveAttribute('data-theme', reader.bootstrap.preferences.theme);
	await expect(page.locator('#settings-page')).toBeHidden();
	await openSettings(page);
	await expect(selectedChoice(page, 'theme')).toHaveValue('nord');
	await expect(previewSurface(page)).toHaveAttribute('data-theme', 'nord');
	await expect(page.locator('body')).toHaveAttribute('data-theme', reader.bootstrap.preferences.theme);
	await page.locator('#discard-settings').click();
	await expect(selectedChoice(page, 'theme')).toHaveValue(reader.bootstrap.preferences.theme);
	await expect(previewSurface(page)).toHaveAttribute('data-theme', reader.bootstrap.preferences.theme);
	await expect(page.locator('#save-reader-settings')).toBeDisabled();
	if (narrowPreview) {
		await expand.click();
		await paneControls.locator('[data-preview-pane="list"]').click();
		await expect(enlarged.locator('.mini-list')).toBeVisible();
		await page.keyboard.press('Escape');
		await expect(dialog).toBeHidden();
		await expect(selectedChoice(page, 'theme')).toHaveValue(reader.bootstrap.preferences.theme);
		await expect(page.locator('#save-reader-settings')).toBeDisabled();
	}
	expect(reader.writes).toHaveLength(0);
});

test('theme browsing stays compact and pagination, filters and search never change the selected draft', async ({ page, reader }) => {
	await openSettings(page);
	const group = choiceGroup(page, 'theme');
	const toggle = group.locator('[data-toggle-theme-gallery=theme]');
	const tiles = group.locator('label.appearance-choice:visible');
	const current = await selectedChoice(page, 'theme').inputValue();
	await expect(toggle).toHaveAttribute('aria-expanded', 'false');
	await expect(tiles).toHaveCount(1);
	await expect(tiles.first()).toHaveAttribute('data-choice-value', current);
	await expect(page.locator('#save-reader-settings')).toBeDisabled();
	await expandThemeChoices(page);
	const search = group.locator('[data-theme-search=theme]');
	const previous = group.locator('[data-theme-page=previous]');
	const next = group.locator('[data-theme-page=next]');
	await search.fill('');
	for (let pageIndex = 0; pageIndex < 6 && await previous.isEnabled(); pageIndex++) await previous.click();
	await expect(tiles).toHaveCount(6);
	const firstPage = await tiles.evaluateAll(nodes => nodes.map(node => node.dataset.choiceValue));
	await expect(previous).toBeDisabled();
	await next.click();
	await expect(tiles).toHaveCount(6);
	expect(await tiles.evaluateAll(nodes => nodes.map(node => node.dataset.choiceValue))).not.toEqual(firstPage);
	await previous.click();
	expect(await tiles.evaluateAll(nodes => nodes.map(node => node.dataset.choiceValue))).toEqual(firstPage);
	for (const mode of ['light', 'dark']) {
		await group.locator(`[data-theme-filter="${mode}"]`).click();
		await expect(group.locator(`[data-theme-filter="${mode}"]`)).toHaveAttribute('aria-pressed', 'true');
		const filtered = await tiles.evaluateAll(nodes => nodes.map(node => node.dataset.choiceValue));
		expect(filtered.length).toBeGreaterThan(0); expect(filtered.length).toBeLessThanOrEqual(6);
		for (const value of filtered) expect(themeSpec(value).dark, value).toBe(mode === 'dark');
	}
	await group.locator('[data-theme-filter=all]').click();
	await search.fill('dracula');
	await expect(tiles).toHaveCount(1);
	await expect(tiles.first()).toHaveAttribute('data-choice-value', 'dracula');
	await search.fill('no-matching-theme');
	await expect(tiles).toHaveCount(0);
	await expect(previous).toBeDisabled(); await expect(next).toBeDisabled();
	await search.fill('nord');
	await expect(tiles).toHaveCount(1);
	await expect(tiles.first()).toHaveAttribute('data-choice-value', 'nord');
	await toggle.click();
	await expect(toggle).toHaveAttribute('aria-expanded', 'false');
	await expect(toggle).toBeFocused();
	await expect(tiles).toHaveCount(1);
	await expect(tiles.first()).toHaveAttribute('data-choice-value', current);
	await expect(selectedChoice(page, 'theme')).toHaveValue(current);
	await expect(page.locator('body')).toHaveAttribute('data-theme', reader.bootstrap.preferences.theme);
	await expect(page.locator('#save-reader-settings')).toBeDisabled();
	expect(reader.writes).toHaveLength(0);
});

test('theme pagination keeps both button slots fixed across pages, filters and empty searches', async ({ page, reader }) => {
	await openSettings(page);
	const group = await expandThemeChoices(page);
	const tiles = group.locator('label.appearance-choice:visible');
	const search = group.locator('[data-theme-search=theme]');
	const previous = group.locator('[data-theme-page=previous]');
	const next = group.locator('[data-theme-page=next]');
	const current = await selectedChoice(page, 'theme').inputValue();
	await group.locator('[data-theme-filter=all]').click();
	await search.fill('');
	await expect(tiles).toHaveCount(6);
	await expect(previous).toBeDisabled();
	await expect(next).toBeEnabled();
	// Settings scrolls on narrow screens; measure each button's real slot within its fieldset.
	const geometry = () => group.evaluate(node => {
		const origin = node.getBoundingClientRect();
		return ['previous', 'next'].map(direction => {
			const box = node.querySelector(`[data-theme-page="${direction}"]`).getBoundingClientRect();
			return { x: box.x - origin.x, y: box.y - origin.y, width: box.width, height: box.height };
		});
	});
	const firstPageGeometry = await geometry();
	const expectFixedSlots = async label => {
		await expect(previous, label).toBeVisible();
		await expect(next, label).toBeVisible();
		const boxes = await geometry();
		for (const [index, box] of boxes.entries()) {
			for (const key of ['x', 'y', 'width', 'height']) {
				expect(box[key], `${label}: ${index === 0 ? 'previous' : 'next'} ${key}`).toBeCloseTo(firstPageGeometry[index][key], 0);
			}
		}
		await expect(selectedChoice(page, 'theme')).toHaveValue(current);
		await expect(page.locator('#save-reader-settings')).toBeDisabled();
		expect(reader.writes).toHaveLength(0);
	};
	const advancePage = async () => {
		await next.scrollIntoViewIfNeeded();
		const before = await next.boundingBox();
		const content = page.locator('.settings-content');
		const scrollTop = await content.evaluate(node => node.scrollTop);
		await next.click();
		expect(await content.evaluate(node => node.scrollTop)).toBe(scrollTop);
		const after = await next.boundingBox();
		for (const key of ['x', 'y', 'width', 'height']) expect(after[key], `next page button ${key}`).toBeCloseTo(before[key], 0);
	};
	await expectFixedSlots('all themes, first page');
	await advancePage();
	await expect(tiles).toHaveCount(6);
	await expect(previous).toBeEnabled();
	await expect(next).toBeEnabled();
	await expectFixedSlots('all themes, middle page');
	for (let pageIndex = 0; pageIndex < 6 && await next.isEnabled(); pageIndex++) {
		await advancePage();
		await expectFixedSlots(`all themes, page ${pageIndex + 3}`);
	}
	await expect(tiles).toHaveCount(3);
	await expect(previous).toBeEnabled();
	await expect(next).toBeDisabled();
	await expectFixedSlots('all themes, partial last page');
	for (const mode of ['light', 'dark']) {
		await group.locator(`[data-theme-filter="${mode}"]`).click();
		await expect(previous).toBeDisabled();
		await expectFixedSlots(`${mode} themes, first page`);
		for (let pageIndex = 0; pageIndex < 6 && await next.isEnabled(); pageIndex++) {
			await advancePage();
			await expectFixedSlots(`${mode} themes, page ${pageIndex + 2}`);
		}
		await expect(next).toBeDisabled();
		const filtered = await tiles.evaluateAll(nodes => nodes.map(node => node.dataset.choiceValue));
		expect(filtered.length).toBeGreaterThan(0);
		expect(filtered.length).toBeLessThanOrEqual(6);
		for (const value of filtered) expect(themeSpec(value).dark).toBe(mode === 'dark');
		await expectFixedSlots(`${mode} themes, last page`);
	}
	await group.locator('[data-theme-filter=all]').click();
	await search.fill('dracula');
	await expect(tiles).toHaveCount(1);
	await expect(previous).toBeDisabled();
	await expect(next).toBeDisabled();
	await expectFixedSlots('single search result');
	await search.fill('no-matching-theme');
	await expect(tiles).toHaveCount(0);
	await expect(group.locator('.theme-gallery-empty')).toBeVisible();
	await expect(previous).toBeDisabled();
	await expect(next).toBeDisabled();
	await expectFixedSlots('empty search results');
	await expect(page.locator('body')).toHaveAttribute('data-theme', reader.bootstrap.preferences.theme);
});

test('appearance choice groups support native keyboard selection and explain layout differences visually', async ({ page, reader }) => {
	await openSettings(page);
	for (const [key, count] of [['theme', 33], ['layout', 3], ['density', 2], ['style', 3]]) {
		const group = choiceGroup(page, key);
		await expect(group.locator('input[type="radio"]')).toHaveCount(count);
		await expect(group.locator('select')).toHaveCount(0);
		if (key === 'theme') {
			await expandThemeChoices(page);
			await group.locator('[data-theme-filter=all]').click();
			await group.locator('[data-theme-search=theme]').fill('');
			await group.locator('label.appearance-choice:visible').first().click();
		}
		const inputs = group.locator('label.appearance-choice:visible input[type="radio"]');
		const values = await inputs.evaluateAll(nodes => nodes.map(node => node.value));
		const current = await selectedChoice(page, key).inputValue();
		const next = values[(values.indexOf(current) + 1) % values.length];
		await selectedChoice(page, key).focus();
		await page.keyboard.press('ArrowRight');
		await expect(selectedChoice(page, key)).toHaveValue(next);
		await expect(selectedChoice(page, key)).toBeFocused();
		await expect(group.locator('input:checked')).toHaveCount(1);
		await page.keyboard.press('ArrowLeft');
		await expect(selectedChoice(page, key)).toHaveValue(current);
		await expect(selectedChoice(page, key)).toBeFocused();
		if (key === 'theme') {
			await page.keyboard.press('ArrowLeft');
			await expect(selectedChoice(page, key)).toHaveValue(values.at(-1));
			await page.keyboard.press('ArrowRight');
			await expect(selectedChoice(page, key)).toHaveValue(current);
		}
	}
	await page.locator('#discard-settings').click();
	await expect(page.locator('#save-reader-settings')).toBeDisabled();
	for (const layout of ['split', 'list', 'cards']) {
		await choose(page, 'layout', layout);
		await expect(previewSurface(page)).toHaveAttribute('data-layout', layout);
		await expect(choiceTile(page, 'layout', layout).locator('.reader-miniature')).toHaveAttribute('data-layout', layout);
		await expect(page.locator('body')).toHaveAttribute('data-layout', reader.bootstrap.preferences.layout);
	}
	await choose(page, 'density', 'compact');
	await expect(previewSurface(page)).toHaveAttribute('data-density', 'compact');
	await expect(page.locator('body')).toHaveAttribute('data-density', reader.bootstrap.preferences.density);
	await choose(page, 'style', 'paper');
	await expect(previewSurface(page)).toHaveAttribute('data-style', 'paper');
	await expect(page.locator('body')).toHaveAttribute('data-style', reader.bootstrap.preferences.style);
	expect(await page.evaluate(() => document.documentElement.scrollWidth <= globalThis.innerWidth)).toBe(true);
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
		await choose(page, 'theme', name);
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
	await choose(page, 'theme', 'auto');
	await expect(choiceGroup(page, 'lightTheme')).toBeVisible();
	await expect(choiceGroup(page, 'darkTheme')).toBeVisible();
	const modes = page.locator('[data-appearance-preview] > .appearance-preview-modes');
	await expect(modes).toBeVisible();
	const day = modes.locator('[data-preview-mode="light"]');
	const night = modes.locator('[data-preview-mode="dark"]');
	await choose(page, 'lightTheme', 'cyberpunk');
	await expect(previewSurface(page)).toHaveAttribute('data-theme', 'cyberpunk');
	await choose(page, 'darkTheme', 'dracula');
	await expect(previewSurface(page)).toHaveAttribute('data-theme', 'dracula');
	await expect(night).toHaveAttribute('aria-pressed', 'true');
	await expect(day).toHaveAttribute('aria-pressed', 'false');
	await expect(page.locator('body')).toHaveAttribute('data-theme', reader.bootstrap.preferences.theme);
	await day.click();
	await expect(previewSurface(page)).toHaveAttribute('data-theme', 'cyberpunk');
	await expect(day).toHaveAttribute('aria-pressed', 'true');
	await page.emulateMedia({ colorScheme: 'dark' });
	await expect(previewSurface(page)).toHaveAttribute('data-theme', 'cyberpunk');
	await night.click();
	await expect(previewSurface(page)).toHaveAttribute('data-theme', 'dracula');
	await expect(page.locator('body')).toHaveAttribute('data-theme', reader.bootstrap.preferences.theme);
	await page.emulateMedia({ colorScheme: 'light' });
	await expect(previewSurface(page)).toHaveAttribute('data-theme', 'dracula');
	await expect(selectedChoice(page, 'lightTheme')).toHaveValue('cyberpunk');
	await expect(selectedChoice(page, 'darkTheme')).toHaveValue('dracula');
	await expect(page.locator('body')).toHaveAttribute('data-theme', reader.bootstrap.preferences.theme);
	expect(reader.writes).toHaveLength(0);
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'cyberpunk');
	await expect(previewSurface(page)).toHaveAttribute('data-theme', 'dracula');
	await page.emulateMedia({ colorScheme: 'dark' });
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'dracula');
	await expect(previewSurface(page)).toHaveAttribute('data-theme', 'dracula');
	await day.click();
	await expect(previewSurface(page)).toHaveAttribute('data-theme', 'cyberpunk');
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'dracula');
	await expect(page.locator('#save-reader-settings')).toBeDisabled();
	expect(reader.writes).toHaveLength(1);
	await choose(page, 'theme', 'nord');
	await expect(selectedChoice(page, 'theme')).toHaveValue('nord');
	await expect(choiceGroup(page, 'lightTheme')).not.toBeVisible();
	await expect(choiceGroup(page, 'darkTheme')).not.toBeVisible();
	await expect(modes).toBeHidden();
	await expect(selectedChoice(page, 'lightTheme')).toHaveValue('cyberpunk');
	await expect(selectedChoice(page, 'darkTheme')).toHaveValue('dracula');
	await expect(previewSurface(page)).toHaveAttribute('data-theme', 'nord');
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'dracula');
	await page.emulateMedia({ colorScheme: 'light' });
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'cyberpunk');
	await expect(selectedChoice(page, 'theme')).toHaveValue('nord');
	await expect(previewSurface(page)).toHaveAttribute('data-theme', 'nord');
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('#settings-page')).toBeVisible();
	await expect(page.locator('#save-reader-settings')).toBeDisabled();
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'nord');
	expect(reader.writes).toHaveLength(2);
	expect(reader.preferences).toMatchObject({ theme: 'nord', lightTheme: 'cyberpunk', darkTheme: 'dracula' });
	await page.reload();
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'nord');
	await openSettings(page);
	await expect(selectedChoice(page, 'theme')).toHaveValue('nord');
	await expect(selectedChoice(page, 'lightTheme')).toHaveValue('cyberpunk');
	await expect(selectedChoice(page, 'darkTheme')).toHaveValue('dracula');
	await choose(page, 'theme', 'auto');
	await expect(previewSurface(page)).toHaveAttribute('data-theme', 'cyberpunk');
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'nord');
	await page.emulateMedia({ colorScheme: 'dark' });
	await expect(previewSurface(page)).toHaveAttribute('data-theme', 'dracula');
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'nord');
	await page.locator('#discard-settings').click();
	await expect(selectedChoice(page, 'theme')).toHaveValue('nord');
	await expect(previewSurface(page)).toHaveAttribute('data-theme', 'nord');
	await expect(selectedChoice(page, 'lightTheme')).toHaveValue('cyberpunk');
	await expect(selectedChoice(page, 'darkTheme')).toHaveValue('dracula');
	await page.locator('#cancel-settings').click();
	await page.locator('.entry-card').first().click();
	await expect(page.locator('#article-frame')).toBeVisible();
	await page.screenshot({ animations: 'disabled', path: testInfo.outputPath('nord-reader.png') });
});

test('failed theme saves leave the current appearance intact and keep the selected choice for retry', async ({ page, reader }) => {
	await openSettings(page);
	await choose(page, 'theme', 'nord');
	const fail = route => route.fulfill({ status: 503, json: { version: 1, error: 'write_failed' } });
	await page.route('**/i/?c=reader&a=preferences*', fail);
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('#settings-status')).toBeVisible();
	await expect(selectedChoice(page, 'theme')).toHaveValue('nord');
	await expect(choiceTile(page, 'theme', 'nord')).toHaveAttribute('data-selected', 'true');
	await expect(choiceTile(page, 'theme', 'nord')).toHaveAttribute('data-saved', 'false');
	await expect(choiceTile(page, 'theme', 'nord').locator('.appearance-choice-state')).toHaveText('已选择');
	await expect(previewSurface(page)).toHaveAttribute('data-theme', 'nord');
	await expect(page.locator('body')).toHaveAttribute('data-theme', reader.bootstrap.preferences.theme);
	await expect(page.locator('#save-reader-settings')).toBeEnabled();
	await page.unroute('**/i/?c=reader&a=preferences*', fail);
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'nord');
	await expect(choiceTile(page, 'theme', 'nord')).toHaveAttribute('data-saved', 'true');
	await expect(choiceTile(page, 'theme', 'nord').locator('.appearance-choice-state')).toHaveText('已保存');
	expect(reader.preferences.theme).toBe('nord');
});

test('a delayed save applies its saved theme without overwriting a newer unsaved choice', async ({ page, reader }) => {
	await openSettings(page);
	await choose(page, 'theme', 'nord');
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
	await choose(page, 'theme', 'dracula');
	release();
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'nord');
	await expect(selectedChoice(page, 'theme')).toHaveValue('dracula');
	await expect(previewSurface(page)).toHaveAttribute('data-theme', 'dracula');
	await expect(choiceTile(page, 'theme', 'nord')).toHaveAttribute('data-saved', 'true');
	await expect(choiceTile(page, 'theme', 'dracula')).toHaveAttribute('data-saved', 'false');
	await expect(page.locator('#save-reader-settings')).toBeEnabled();
	await page.unroute('**/i/?c=reader&a=preferences*', delay);
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'dracula');
	expect(reader.preferences.theme).toBe('dracula');
});

test('export round-trips draft values, legacy imports stay unapplied until saved, and preferences survive reload', async ({ page, reader }) => {
	await openSettings(page);
	await choose(page, 'theme', 'nord');
	await page.locator('#save-reader-settings').click();
	await expect(page.locator('body')).toHaveAttribute('data-theme', 'nord');
	await choose(page, 'theme', 'dracula');
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
	await expect(selectedChoice(page, 'theme')).toHaveValue('dracula');
	await expect(page.locator('[name=fontSize]')).toHaveValue('22');
	const legacy = { format: 'freshrss-reader', version: 1, preferences: { theme: 'dark', style: 'paper', fontSize: 20 } };
	await page.locator('#import-file').setInputFiles({ name: 'legacy-reader.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(legacy)) });
	await expect(selectedChoice(page, 'theme')).toHaveValue('dark');
	await expect(page.locator('[name=fontSize]')).toHaveValue('20');
	await expect(selectedChoice(page, 'lightTheme')).toHaveValue('nord');
	await expect(selectedChoice(page, 'darkTheme')).toHaveValue('sunset');
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
	const first = choiceGroup(page, 'theme').locator('label.appearance-choice:visible').first();
	await first.scrollIntoViewIfNeeded();
	const theme = await first.boundingBox();
	const settings = await page.locator('#settings-page').boundingBox();
	expect(theme.y).toBeGreaterThanOrEqual(settings.y);
	expect(theme.y + theme.height).toBeLessThanOrEqual(settings.y + settings.height);
	expect(theme.width).toBeGreaterThanOrEqual(44);
	expect(theme.height).toBeGreaterThanOrEqual(44);
	await expect(choiceGroup(page, 'theme')).toHaveAccessibleName('主题配色');
	await revealAppearanceChoice(page, 'theme', 'nord');
	await expect(choiceTile(page, 'theme', 'nord').locator('input')).toHaveAccessibleName('Nord');
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
