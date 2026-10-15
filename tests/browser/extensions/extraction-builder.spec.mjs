import { test, expect } from '@playwright/test';

const username = process.env.FRESHRSS_TEST_USER;
const password = process.env.FRESHRSS_TEST_PASSWORD;
const configure = './?c=extension&a=configure&e=Web%20Extraction';
const settings = './?c=reader&a=extensionConfig&extension=WebExtraction';
let original; let csrf; let errors; let external;

test.beforeEach(async ({ page, baseURL }) => {
	test.skip(username !== 'reader' || !password, 'Use the isolated local reader account.');
	test.skip(!['127.0.0.1', 'localhost', '[::1]'].includes(new URL(baseURL).hostname), 'Only mutate a local test account.');
	errors = []; external = [];
	page.on('pageerror', error => errors.push(error.message));
	page.on('dialog', dialog => dialog.accept());
	await page.context().route('**/*', route => {
		const url = new URL(route.request().url());
		if (url.origin !== new URL(baseURL).origin) { external.push(url.href); return route.abort(); }
		if (url.searchParams.get('a') === 'catalog') return route.fulfill({ body: '' });
		return route.continue();
	});
	await page.goto('./?c=auth&a=login');
	await page.locator('[name=username]').fill(username);
	await page.locator('input[type=password]').fill(password);
	await Promise.all([page.waitForURL(url => url.searchParams.get('c') !== 'auth'), page.locator('button[type=submit]').click()]);
	await page.goto(configure);
	csrf = await page.locator('[data-web-extraction] [name=_csrf]').inputValue();
	original = (await (await page.request.get(settings)).json()).configuration;
	await saveConfiguration(page, {});
	await page.goto(configure);
	await expect(page.locator('[data-web-extraction]')).toHaveAttribute('data-extraction-ui-ready', '1');
});

test.afterEach(async ({ page }) => {
	if (!original) return;
	await saveConfiguration(page, original.values.recipes);
	original = null;
	expect(errors).toEqual([]);
	expect(external).toEqual([]);
});

async function saveConfiguration(page, recipes) {
	const latest = (await (await page.request.get(settings)).json()).configuration;
	const response = await page.request.post('./?c=reader&a=extensionConfig', {
		data: { _csrf: csrf, extension: 'WebExtraction', expectedVersion: latest.version, configuration: { recipes } },
	});
	expect(response.ok()).toBe(true);
}
async function openDetails(page, selector) {
	if (!await page.locator(selector).evaluate(element => element.open)) await page.locator(selector + ' > summary').click();
}
async function submit(page, selector) {
	await Promise.all([page.waitForNavigation(), page.locator(selector).click()]);
	await expect(page.locator('[data-web-extraction]')).toHaveAttribute('data-extraction-ui-ready', '1');
}
async function draft(page) { return JSON.parse(await page.locator('#extraction-recipes').inputValue()); }

const field = (page, name, control) => page.locator(`[data-extraction-field=${name}] [data-field-${control}]`);

test('article preset, multiple feeds and keyboard editing produce a saved recipe without JSON editing', async ({ page }) => {
	await expect(page.locator('[data-extraction-advanced]')).not.toHaveAttribute('open');
	expect(await page.evaluate(() => window.data_leave_validation(document.querySelector('[data-web-extraction]')))).toBe(true);
	await page.locator('[data-command=article-preset]').click();
	await page.locator('#extraction-targets').fill('7, 8');
	await field(page, 'title', 'type').selectOption('xpath');
	await field(page, 'title', 'selectors').fill('//h1\n//h2');
	await field(page, 'author', 'selectors').fill('.author');
	await page.locator('#extraction-targets').press('Enter');
	const value = await draft(page);
	expect(value['7']).toEqual(value['8']);
	expect(value['7'].fields.title.map(candidate => candidate.selector)).toEqual(['//h1', '//h2']);
	expect(value['7'].fields.author[0].all).toBe(true);
	await expect(page.locator('[data-draft-status]')).toHaveAttribute('data-dirty', 'true');
	await submit(page, '[data-save-recipes]');
	expect((await (await page.request.get(settings)).json()).configuration.values.recipes).toEqual(value);
});

test('field preview is local, shows fallback diagnostics and leaves saved configuration unchanged', async ({ page }) => {
	await page.locator('[data-command=article-preset]').click();
	await page.locator('#extraction-targets').fill('*');
	await field(page, 'title', 'selectors').fill('.missing\nh1');
	await field(page, 'author', 'selectors').fill('.author');
	await openDetails(page, '[data-preview-panel]');
	await page.locator('#extraction-feed').fill('7');
	await page.locator('#extraction-source').fill('<h1>Readable title</h1><article><p>Body</p></article><b class="author">Ada</b><b class="author">Grace</b>' +
		'</textarea><img id="extraction-injected" src="https://example.invalid/image" onerror="window.extractionInjected=1">');
	await submit(page, '[name=preview]');
	await expect(page.locator('[data-preview-fields]')).toBeVisible();
	await expect(page.locator('[data-result-field=title] pre')).toHaveText('Readable title');
	await expect(page.locator('[data-result-field=author] pre')).toHaveText('Ada\nGrace');
	await expect(page.locator('[data-result-field=title] details li')).toHaveCount(2);
	await expect(page.locator('[data-result-details]')).not.toHaveAttribute('open');
	await expect(page.locator('#extraction-injected')).toHaveCount(0);
	expect(Object.keys((await (await page.request.get(settings)).json()).configuration.values.recipes)).toEqual([]);
	await page.screenshot({ path: 'bin/extraction-builder-preview.png', fullPage: true });
});

test('advanced recipes retain all options during editing of other feeds and cannot be overwritten by the form', async ({ page }) => {
	const advanced = { format: 'html', fields: { title: [{ type: 'css', selector: 'h1', postprocess: [{ type: 'normalize_whitespace' }] }] }, max_matches: 12 };
	await saveConfiguration(page, { 7: advanced });
	await page.goto(configure);
	await page.locator('[data-command=edit][data-feed="7"]').click();
	await expect(page.locator('[data-extraction-advanced]')).toHaveAttribute('open');
	await expect(page.locator('[data-recipe-editor]')).toBeHidden();
	await page.locator('[data-command=new]').click();
	await page.locator('[data-command=json-preset]').click();
	await page.locator('#extraction-targets').fill('8');
	await page.locator('[data-command=apply]').click();
	expect((await draft(page))['7']).toEqual(advanced);
	expect((await draft(page))['8'].format).toBe('json');
	await page.locator('#extraction-targets').fill('7, 9');
	await page.locator('[data-command=apply]').click();
	await expect(page.locator('[data-builder-error]')).toBeVisible();
	expect((await draft(page))['7']).toEqual(advanced);
	expect((await draft(page))['9']).toBeUndefined();
});

test('mobile form and repeated classic slider mounts stay usable without duplicate handlers', async ({ page }) => {
	await page.setViewportSize({ width: 320, height: 820 });
	await page.goto(configure);
	if (await page.locator('#aside_feed a.toggle_aside[href="#close"]').isVisible()) await page.locator('#aside_feed a.toggle_aside[href="#close"]').click();
	await page.locator('[data-command=article-preset]').click();
	await page.locator('#extraction-targets').fill('7, 8');
	await page.locator('[data-command=apply]').click();
	expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
	await page.screenshot({ path: 'bin/extraction-builder-320.png', fullPage: true });
	await page.locator('[data-command=undo]').click();
	expect(await draft(page)).toEqual({});
	expect(await page.evaluate(() => window.data_leave_validation(document.querySelector('[data-web-extraction]')))).toBe(true);
	await page.setViewportSize({ width: 1200, height: 900 });
	await page.goto('./?c=extension');
	for (let attempt = 0; attempt < 2; attempt++) {
		await page.locator('.open-slider[href*="e=Web"]').click();
		await expect(page.locator('#slider [data-web-extraction]')).toHaveAttribute('data-extraction-ui-ready', '1');
		await page.locator('#slider [data-command=article-preset]').click();
		await page.locator('#slider #extraction-targets').fill('7');
		await page.locator('#slider [data-command=apply]').click();
		expect(Object.keys(await draft(page))).toEqual(['7']);
		await page.locator('#slider [data-command=undo]').click();
		expect(await draft(page)).toEqual({});
		await page.locator('#slider .toggle_aside').first().click();
		await expect(page.locator('#slider')).not.toHaveClass(/active/);
	}
});
