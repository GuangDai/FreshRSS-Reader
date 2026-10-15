import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

const username = process.env.FRESHRSS_TEST_USER;
const password = process.env.FRESHRSS_TEST_PASSWORD;
const themes = ['Calm', 'MetaCube-nord', 'MetaCube-sunset', 'MetaCube-wireframe'];
const tolerance = 1 / 64;

test.use({ hasTouch: true });

test.beforeEach(async ({ page }) => {
	test.skip(!username || !password, 'Use an isolated local test account.');
	await page.goto('./?c=auth&a=login');
	await page.locator('[name=username]').fill(username);
	await page.locator('input[type=password]').fill(password);
	await Promise.all([page.waitForURL(url => url.searchParams.get('c') !== 'auth'), page.locator('button[type=submit]').click()]);
	// Appearance and geometry reviews never save preferences or change article state.
	await page.route(url => ['entry', 'reader'].includes(url.searchParams.get('c')) || url.searchParams.get('a') === 'actualize',
		route => route.abort());
	await page.route('**/*', route => route.request().method() === 'POST' ? route.abort() : route.fallback());
});

async function useTheme(page, name, rtl = false) {
	await page.locator('link[rel=stylesheet][href*="/themes/"], style[data-classic-refinement]').evaluateAll(nodes => nodes.forEach(node => node.remove()));
	await page.evaluate(direction => { document.documentElement.dir = direction; }, rtl ? 'rtl' : 'ltr');
	const directory = new URL('../../../p/themes/' + name + '/', import.meta.url);
	const metadata = JSON.parse(readFileSync(new URL('metadata.json', directory), 'utf8'));
	for (const entry of metadata.files) {
		const file = (entry === '_frss.css' ? '../base-theme/frss.css' : entry).replace(/\.css$/, rtl ? '.rtl.css' : '.css');
		const style = await page.addStyleTag({ url: new URL('../themes/' + name + '/' + file, page.url()).href });
		await style.evaluate(node => { node.dataset.classicRefinement = ''; });
	}
}

async function expectFits(page) {
	const size = await page.evaluate(() => ({ width: window.innerWidth, scrollWidth: document.documentElement.scrollWidth }));
	expect(size.scrollWidth).toBeLessThanOrEqual(size.width + 1);
}

test('phone settings share one header and keep both navigation controls operable in either direction', async ({ page }) => {
	await page.setViewportSize({ width: 320, height: 568 });
	for (const theme of themes) {
		for (const rtl of [false, true]) {
			await page.goto('./?c=configure&a=reading');
			await useTheme(page, theme, rtl);
			const drawer = page.locator('.nav_menu.nav_mobile #nav_menu_toggle_aside .btn');
			const configure = page.locator('.header > .configure .dropdown > .btn');
			const header = await page.locator('.header').boundingBox();
			const trigger = await drawer.boundingBox();
			expect(trigger.height).toBeGreaterThanOrEqual(44 - tolerance);
			expect(trigger.y).toBeGreaterThanOrEqual(header.y);
			expect(trigger.y + trigger.height).toBeLessThanOrEqual(header.y + header.height + tolerance);
			await drawer.focus();
			await page.keyboard.press('Enter');
			await expect(page.locator('#aside_feed')).toHaveClass(/visible/);
			await page.locator('#aside_feed > .toggle_aside').tap();
			await expect(page.locator('#aside_feed')).not.toHaveClass(/visible/);
			await configure.click({ trial: true });
			await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
			await expectFits(page);
			expect((await page.locator('.header .logo').boundingBox()).width).toBeGreaterThanOrEqual(96);
			await drawer.click({ trial: true });
			await configure.click({ trial: true });
			await page.locator('#view_mode').focus();
			for (let field = 0; field < 5; field++) {
				await expect.poll(async () => {
					const focus = await page.locator(':focus').boundingBox();
					const actions = await page.locator('.form-actions').boundingBox();
					return focus.y + focus.height - actions.y;
				}).toBeLessThanOrEqual(tolerance);
				await page.keyboard.press('Tab');
			}
			await page.evaluate(() => { document.documentElement.style.fontSize = ''; });
		}
	}
});

test('short landscape screens show the first article and retain every toolbar action at touch size', async ({ page }) => {
	await page.setViewportSize({ width: 568, height: 320 });
	for (const theme of themes) {
		for (const rtl of [false, true]) {
			await page.goto('./?c=index');
			await useTheme(page, theme, rtl);
			await expectFits(page);
			const title = page.locator('.flux_header .title').first();
			await expect(title).toBeInViewport();
			const article = await title.boundingBox();
			const pagination = await page.locator('#nav_entries').boundingBox();
			if (pagination) expect(article.y + article.height).toBeLessThanOrEqual(pagination.y + tolerance);
			for (const button of await page.locator('.nav_menu:not(.nav_mobile) .btn:visible').all()) {
				const target = await button.boundingBox();
				expect(target.width).toBeGreaterThanOrEqual(44 - tolerance);
				expect(target.height).toBeGreaterThanOrEqual(44 - tolerance);
				await button.click({ trial: true });
			}
		}
	}
});

test('desktop article titles and dates remain readable with a bounded sidebar and keyboard focus', async ({ page }) => {
	await page.setViewportSize({ width: 1440, height: 900 });
	for (const theme of themes) {
		await page.goto('./?c=index');
		await useTheme(page, theme);
		const title = page.locator('.flux_header .title').first();
		const date = page.locator('.flux_header .date').first();
		expect(await title.evaluate(node => parseFloat(window.getComputedStyle(node).fontSize))).toBeGreaterThanOrEqual(16);
		expect(await date.evaluate(node => parseFloat(window.getComputedStyle(node).fontSize))).toBeGreaterThanOrEqual(12);
		const sidebar = await page.locator('.aside_feed').boundingBox();
		expect(sidebar.width).toBeLessThanOrEqual(280);
		await title.focus();
		const outline = await title.evaluate(node => {
			const style = window.getComputedStyle(node);
			return { width: parseFloat(style.outlineWidth), style: style.outlineStyle };
		});
		expect(outline.width).toBeGreaterThanOrEqual(2);
		expect(outline.style).not.toBe('none');
		await expectFits(page);
	}
});
