/**
 * Capture real Classic article previews with every registered MetaCubeXD palette.
 * Run: node tests/browser/tools/capture-classic-themes.mjs
 * Requires an isolated populated account in FRESHRSS_TEST_USER / FRESHRSS_TEST_PASSWORD,
 * or an authenticated FRESHRSS_TEST_STORAGE_STATE; FRESHRSS_TEST_URL selects the server.
 */
import { chromium } from '@playwright/test';
import { mkdirSync, readFileSync } from 'node:fs';
import { THEME_NAMES, themeSpec } from '../../../p/scripts/reader/appearance/themes.js';

const baseURL = process.env.FRESHRSS_TEST_URL || 'http://127.0.0.1:18080/i/';
const stateFile = process.env.FRESHRSS_TEST_STORAGE_STATE;
const username = process.env.FRESHRSS_TEST_USER;
const password = process.env.FRESHRSS_TEST_PASSWORD;
if (!stateFile && (!username || !password)) throw new Error('An isolated test account or authenticated storage state is required.');

const browser = await chromium.launch({ args: ['--no-sandbox'] });
try {
	const page = await browser.newPage({ viewport: { width: 1200, height: 800 }, ...(stateFile ? { storageState: stateFile } : {}) });
	if (!stateFile) {
		await page.goto(new URL('?c=auth&a=login', baseURL).href);
		await page.locator('[name=username]').fill(username);
		await page.locator('input[type=password]').fill(password);
		await Promise.all([page.waitForURL(url => url.searchParams.get('c') !== 'auth'), page.locator('button[type=submit]').click()]);
	}
	// Inspection and article expansion must not change account preferences or article state.
	await page.route('**/*', route => route.request().method() === 'POST' ? route.abort() : route.fallback());
	await page.route(url => ['entry', 'reader'].includes(url.searchParams.get('c')) || url.searchParams.get('a') === 'actualize', route => route.abort());
	await page.goto(new URL('?c=index&state=3', baseURL).href);
	await page.evaluate(() => { window.context.auto_mark_article = false; window.context.auto_load_more = false; });
	await page.locator('.flux .flux_header .title').first().click();
	await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
	await page.locator('link[rel=stylesheet][href*="/themes/"]').evaluateAll(nodes => nodes.forEach(node => node.remove()));
	await page.evaluate(() => document.documentElement.classList.add('darkMode_auto'));
	for (const name of THEME_NAMES) {
		await page.locator('style[data-classic-preview]').evaluateAll(nodes => nodes.forEach(node => node.remove()));
		const directory = new URL(`../../../p/themes/MetaCube-${name}/`, import.meta.url);
		const metadata = JSON.parse(readFileSync(new URL('metadata.json', directory), 'utf8'));
		for (const entry of metadata.files) {
			const file = entry === '_frss.css' ? '../base-theme/frss.css' : entry;
			const style = await page.addStyleTag({ url: new URL(`../themes/MetaCube-${name}/${file}`, baseURL).href });
			await style.evaluate(node => { node.dataset.classicPreview = ''; });
		}
		// Fixed themes must render the same even when the OS requests the opposite scheme.
		await page.emulateMedia({ colorScheme: themeSpec(name).dark ? 'light' : 'dark' });
		mkdirSync(new URL('thumbs/', directory), { recursive: true });
		await page.screenshot({ path: new URL('thumbs/original.png', directory).pathname });
	}
	console.log(`Captured ${THEME_NAMES.length} real Classic theme previews.`);
} finally {
	await browser.close();
}
