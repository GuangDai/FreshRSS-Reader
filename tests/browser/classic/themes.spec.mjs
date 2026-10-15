import { test, expect } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';
import { THEME_NAMES } from '../../../p/scripts/reader/appearance/themes.js';

const username = process.env.FRESHRSS_TEST_USER;
const password = process.env.FRESHRSS_TEST_PASSWORD;
const stateFile = process.env.FRESHRSS_TEST_STORAGE_STATE;
const upstream = JSON.parse(readFileSync(new URL('../../fixtures/themes/daisyui-5.7.17-themes.json', import.meta.url), 'utf8'));
const representatives = ['nord', 'sunset', 'cyberpunk', 'black', 'wireframe', 'synthwave'];
const views = ['?c=index', '?c=configure&a=display', '?c=configure&a=reading'];
const paletteProperties = ['--font-color', '--font-color-grey', '--font-color-link', '--background-color-light',
	'--background-color-grey', '--contrast-background-color', '--border-color'];

test.use({ hasTouch: true, ...(stateFile && existsSync(stateFile) ? { storageState: stateFile } : {}) });

test.beforeEach(async ({ page }) => {
	test.skip((!username || !password) && !(stateFile && existsSync(stateFile)), 'Use an isolated test account or authenticated storage state.');
	await page.goto('./?c=auth&a=login');
	if (await page.locator('[name=username]').count()) {
		test.skip(!username || !password, 'The supplied storage state has expired.');
		await page.locator('[name=username]').fill(username);
		await page.locator('input[type=password]').fill(password);
		await Promise.all([page.waitForURL(url => url.searchParams.get('c') !== 'auth'), page.locator('button[type=submit]').click()]);
		await expect(page.locator('[name=username]')).toHaveCount(0);
	}
	// Theme inspection must never save appearance, reading rules or article state.
	await page.route(url => ['entry', 'reader'].includes(url.searchParams.get('c')) || url.searchParams.get('a') === 'actualize',
		route => route.abort());
	await page.route('**/*', route => route.request().method() === 'POST' ? route.abort() : route.fallback());
});

async function useTheme(page, name, rtl = false) {
	await page.locator('link[rel=stylesheet][href*="/themes/"], style[data-classic-theme-test]').evaluateAll(nodes => nodes.forEach(node => node.remove()));
	await page.evaluate(direction => {
		document.documentElement.dir = direction;
		document.documentElement.classList.add('darkMode_auto');
	}, rtl ? 'rtl' : 'ltr');
	const directory = new URL('../../../p/themes/MetaCube-' + name + '/', import.meta.url);
	const metadata = JSON.parse(readFileSync(new URL('metadata.json', directory), 'utf8'));
	for (const entry of metadata.files) {
		const file = entry === '_frss.css' ? '../base-theme/frss.css' : entry;
		const url = new URL('../themes/MetaCube-' + name + '/' + file.replace(/\.css$/, rtl ? '.rtl.css' : '.css'), page.url()).href;
		const style = await page.addStyleTag({ url });
		await style.evaluate(node => { node.dataset.classicThemeTest = ''; });
	}
}

async function expectFits(page) {
	const size = await page.evaluate(() => ({ width: window.innerWidth, scrollWidth: document.documentElement.scrollWidth }));
	expect(size.scrollWidth).toBeLessThanOrEqual(size.width + 1);
}

async function expectContrast(locator, minimum = 4.5, foregroundProperty = 'color', label = '', pseudoElement = null) {
	const ratio = await locator.evaluate((element, { property, pseudo }) => {
		const context = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
		const rgba = colour => {
			context.clearRect(0, 0, 1, 1);
			context.fillStyle = colour;
			context.fillRect(0, 0, 1, 1);
			return Array.from(context.getImageData(0, 0, 1, 1).data);
		};
		const layers = [];
		for (let node = element; node; node = node.parentElement) layers.unshift(rgba(window.getComputedStyle(node).backgroundColor));
		if (pseudo) layers.push(rgba(window.getComputedStyle(element, pseudo).backgroundColor));
		const composite = (backdrop, source) => backdrop.map((channel, index) => channel * (1 - source[3] / 255) + source[index] * source[3] / 255);
		const background = layers.reduce(composite, [255, 255, 255]);
		const foreground = composite(background, rgba(window.getComputedStyle(element, pseudo)[property]));
		const luminance = colour => colour.map(channel => channel / 255).map(channel => channel <= 0.04045
			? channel / 12.92
			: ((channel + 0.055) / 1.055) ** 2.4).reduce((sum, channel, index) => sum + channel * [0.2126, 0.7152, 0.0722][index], 0);
		const light = luminance(foreground);
		const dark = luminance(background);
		return (Math.max(light, dark) + 0.05) / (Math.min(light, dark) + 0.05);
	}, { property: foregroundProperty, pseudo: pseudoElement });
	expect.soft(ratio, label || await locator.getAttribute('id') || await locator.textContent()).toBeGreaterThanOrEqual(minimum);
}

test('Classic appearance lists all 32 real themes and resolves each preview without saving the account', async ({ page }) => {
	await page.goto('./?c=configure&a=display');
	expect(await page.locator('#theme option[value^="MetaCube-"]').evaluateAll(nodes => nodes.map(node => node.value)))
		.toEqual(THEME_NAMES.map(name => 'MetaCube-' + name));
	for (const name of THEME_NAMES) {
		await page.locator('#theme').selectOption('MetaCube-' + name);
		const preview = page.locator(`[data-theme-preview="MetaCube-${name}"]`);
		await expect(preview).toHaveClass(/picked/);
		await preview.scrollIntoViewIfNeeded();
		await expect.poll(() => preview.locator('img').evaluate(image => image.complete && image.naturalWidth > 0)).toBe(true);
	}
});

test('all Classic themes retain their original tokens and fixed palette under either system colour scheme', async ({ page }) => {
	await page.goto('./?c=configure&a=reading');
	for (const name of THEME_NAMES) {
		await test.step(name, async () => {
			await useTheme(page, name);
			const palettes = [];
			for (const scheme of ['light', 'dark']) {
				await page.emulateMedia({ colorScheme: scheme });
				const actual = await page.locator('html').evaluate((node, tokens) => {
					const style = window.getComputedStyle(node);
					return Object.fromEntries(tokens.map(property => [property, property === 'color-scheme'
						? style.colorScheme
						: style.getPropertyValue(property).trim()]));
				}, Object.keys(upstream.themes[name]));
				expect(actual).toEqual(upstream.themes[name]);
				palettes.push(await page.locator('html').evaluate((node, properties) => {
					const style = window.getComputedStyle(node);
					return properties.map(property => style.getPropertyValue(property).trim());
				}, paletteProperties));
			}
			expect(palettes[0]).toEqual(palettes[1]);
			await expectContrast(page.locator('#view_mode'), 4.5, 'color', name + ': select');
			await expectContrast(page.locator('.form-actions button[type=submit]'), 4.5, 'color', name + ': primary button');
			await expectContrast(page.locator('label[for=view_mode]'), 4.5, 'color', name + ': label');
		});
	}
});

test('every Classic theme keeps destructive-action text and notification states readable', async ({ page }) => {
	await page.goto('./?c=subscription');
	const href = await page.locator('.open-slider[href*="a=feed"]').first().getAttribute('href');
	await page.goto(new URL(href, page.url()).href);
	await expect(page.locator('.btn.btn-attention').last()).toBeVisible();
	for (const name of THEME_NAMES) {
		await useTheme(page, name);
		await expectContrast(page.locator('.btn.btn-attention').last(), 4.5, 'color', name + ': destructive button');
		for (const state of ['warn', 'error', 'success', 'info']) {
			const alert = page.locator('.alert').first();
			// Reuse the real feed-settings alert markup to render every transient state.
			await alert.evaluate((node, state) => { node.className = 'alert alert-' + state; }, state);
			await expectContrast(alert, 4.5, 'color', name + ': ' + state + ' notification');
		}
	}
});

test('all Classic palettes keep article text, links, selected subscriptions and status badges readable', async ({ page }) => {
	await page.goto('./?c=index');
	await expect(page.locator('.flux').first()).toBeVisible();
	await page.locator('.flux').first().evaluate(node => {
		node.classList.add('active', 'current');
		// The isolated seed has no body links; exercise the real article link styling locally.
		const link = document.createElement('a');
		link.id = 'classic-body-link';
		link.href = 'https://example.org/related';
		link.textContent = '阅读相关资料';
		node.querySelector('.text p').append(link);
	});
	const feed = page.locator('.aside_feed .item.feed[id^=f_]').first();
	const title = feed.locator('a.item-title');
	for (const name of THEME_NAMES) {
		await useTheme(page, name);
		await expectContrast(page.locator('.flux .title a').first(), 4.5, 'color', name + ': article title');
		await expectContrast(page.locator('.flux .text p').first(), 4.5, 'color', name + ': article body');
		await expectContrast(page.locator('#classic-body-link'), 4.5, 'color', name + ': article link');
		await expectContrast(page.locator('.aside_feed .tree-folder.active > .tree-folder-title .title').first(), 4.5, 'color', name + ': selected category');
		for (const state of ['normal', 'empty', 'error']) {
			await feed.evaluate((node, state) => { node.className = 'item feed active' + (state === 'normal' ? '' : ' ' + state); }, state);
			await expectContrast(title, 4.5, 'color', name + ': selected ' + state + ' subscription');
			await expectContrast(title, 4.5, 'color', name + ': ' + state + ' unread badge', '::after');
		}
	}
});

test('light, dark and extreme Classic palettes keep real reading and settings in bounds in LTR and RTL', async ({ page }) => {
	test.setTimeout(120000);
	for (const viewport of [{ width: 320, height: 568 }, { width: 568, height: 320 }, { width: 1440, height: 1000 }]) {
		await page.setViewportSize(viewport);
		for (const rtl of [false, true]) {
			for (const route of views) {
				await page.goto('./' + route);
				for (const name of representatives) {
					await test.step(`${name}, ${viewport.width} × ${viewport.height}, ${rtl ? 'RTL' : 'LTR'}, ${route}`, async () => {
						await useTheme(page, name, rtl);
						await expectFits(page);
						const configure = page.locator('.header > .configure > .dropdown > .btn');
						await expect(configure).toBeInViewport();
						await configure.click({ trial: true });
						if (route === '?c=index') {
							await expect(page.locator('.flux').first()).toBeVisible();
							await expectContrast(page.locator('.flux .title a').first());
						} else {
							const selector = route.includes('display') ? '#theme' : '#view_mode';
							await expectContrast(page.locator(selector));
							await expectContrast(page.locator('.form-actions button[type=submit]'));
						}
					});
				}
			}
		}
	}
});

test('touch targets, checkbox labels and keyboard focus remain usable in contrasting Classic palettes', async ({ page }) => {
	await page.setViewportSize({ width: 320, height: 568 });
	for (const name of representatives) {
		await page.goto('./?c=configure&a=reading');
		await useTheme(page, name);
		for (const selector of ['#view_mode', '#posts_per_page', '.form-actions button[type=submit]']) {
			const box = await page.locator(selector).boundingBox();
			expect(box.height).toBeGreaterThanOrEqual(44 - 1 / 64);
		}
		const label = page.locator('label[for=check_open_site]');
		expect((await label.boundingBox()).height).toBeGreaterThanOrEqual(44 - 1 / 64);
		const checkbox = page.locator('#check_open_site');
		const checked = await checkbox.isChecked();
		await label.tap();
		expect(await checkbox.isChecked()).toBe(!checked);
		await page.locator('#view_mode').focus();
		await page.keyboard.press('Tab');
		const focus = page.locator(':focus');
		await expect(focus).toBeInViewport();
		const outline = await focus.evaluate(node => {
			const style = window.getComputedStyle(node);
			return { width: parseFloat(style.outlineWidth), style: style.outlineStyle };
		});
		expect(outline.width).toBeGreaterThanOrEqual(2);
		expect(outline.style).not.toBe('none');
		await expectContrast(focus);
		await expectContrast(focus, 3, 'outlineColor', name + ': focus indicator');
	}
});
