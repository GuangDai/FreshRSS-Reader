import { test, expect } from '@playwright/test';

const username = process.env.FRESHRSS_TEST_USER;
const password = process.env.FRESHRSS_TEST_PASSWORD;
const configure = './?c=extension&a=configure&e=Rule%20Engine';
const settings = './?c=reader&a=extensionConfig&extension=RuleEngine';
const empty = { version: 1, rules: [] };
let original;
let csrf;
let pageErrors;

test.beforeEach(async ({ page, baseURL }) => {
	test.skip(!username || !password, 'Use an isolated local test account.');
	test.skip(!['127.0.0.1', 'localhost', '[::1]'].includes(new URL(baseURL).hostname), 'Only mutate a local test account.');
	pageErrors = [];
	page.on('pageerror', error => pageErrors.push(error.message));
	page.on('dialog', dialog => dialog.accept());
	await page.context().route('**/*', route => {
		const url = new URL(route.request().url());
		if (url.origin !== new URL(baseURL).origin) return route.abort();
		if (url.searchParams.get('a') === 'catalog') return route.fulfill({ body: '' });
		return route.continue();
	});
	await page.goto('./?c=auth&a=login');
	await page.locator('[name=username]').fill(username);
	await page.locator('input[type=password]').fill(password);
	await Promise.all([page.waitForURL(url => url.searchParams.get('c') !== 'auth'), page.locator('button[type=submit]').click()]);
	await page.goto(configure);
	csrf = await page.locator('[data-rule-engine] [name=_csrf]').inputValue();
	original = (await (await page.request.get(settings)).json()).configuration;
	const response = await page.request.post('./?c=reader&a=extensionConfig', {
		data: { _csrf: csrf, extension: 'RuleEngine', expectedVersion: original.version, configuration: { rules_json: JSON.stringify(empty) } },
	});
	expect(response.ok()).toBe(true);
	await page.goto(configure);
	await expect(page.locator('[data-rule-engine]')).toHaveAttribute('data-rule-ui-ready', '1');
});

test.afterEach(async ({ page }) => {
	if (!original) return;
	const latest = (await (await page.request.get(settings)).json()).configuration;
	const restored = await page.request.post('./?c=reader&a=extensionConfig', {
		data: { _csrf: csrf, extension: 'RuleEngine', expectedVersion: latest.version, configuration: { rules_json: original.values.rules_json } },
	});
	expect(restored.ok()).toBe(true);
	expect((await (await page.request.get(settings)).json()).configuration.values.rules_json).toBe(original.values.rules_json);
	original = null;
	expect(pageErrors).toEqual([]);
});

async function documentValue(page) { return JSON.parse(await page.locator('#rules-json').inputValue()); }
async function openDetails(page, selector) {
	if (!await page.locator(selector).evaluate(element => element.open)) await page.locator(selector + ' > summary').click();
}
async function submit(page, action) {
	await Promise.all([page.waitForNavigation(), page.locator(`[name=rule_action][value=${action}]`).click()]);
}
async function addPreset(page, preset, name) {
	await page.locator('#rule-preset').selectOption(preset);
	await page.locator('[data-rule-command=preset]').click();
	await page.locator('[data-editor-id]').fill(name);
}

test('preset parameters, simple editing and bulk operations stay drafts until explicitly saved', async ({ page }) => {
	await expect(page.locator('[data-rule-advanced]')).not.toHaveAttribute('open');
	await addPreset(page, 'author_watch', 'Watch Ada');
	await page.locator('[data-editor-value]').fill('Ada');
	await page.locator('[data-rule-command=apply-editor]').click();
	expect((await documentValue(page)).rules[0].when.value).toBe('Ada');
	await expect(page.locator('[data-draft-status]')).toHaveAttribute('data-dirty', 'true');
	expect(JSON.parse((await (await page.request.get(settings)).json()).configuration.values.rules_json)).toEqual(empty);
	await addPreset(page, 'releases', 'Stable releases');
	await expect(page.locator('[data-compound-values] input')).toHaveCount(2);
	await page.locator('[data-compound-value="0"]').fill('version');
	await page.locator('[data-rule-command=apply-editor]').click();
	const first = await documentValue(page);
	expect(first.rules[1].when.all[1].not.value).toBe('prerelease');
	await page.locator('[data-rule-command=up][data-rule-index="1"]').click();
	expect((await documentValue(page)).rules.map(rule => rule.id)).toEqual(['Stable releases', 'Watch Ada']);
	await page.locator('[data-select-all]').check();
	await page.locator('[data-rule-command=disable-selected]').click();
	expect((await documentValue(page)).rules.every(rule => rule.enabled === false)).toBe(true);
	await page.locator('[data-select-rule="1"]').check();
	await page.locator('[data-rule-command=delete-selected]').click();
	expect((await documentValue(page)).rules).toHaveLength(1);
	await page.locator('[data-rule-command=undo]').click();
	expect((await documentValue(page)).rules).toHaveLength(2);
	await submit(page, 'save');
	await expect(page.locator('[data-draft-status]')).toHaveAttribute('data-dirty', 'false');
	const stored = JSON.parse((await (await page.request.get(settings)).json()).configuration.values.rules_json);
	expect(stored.rules.map(rule => rule.id)).toEqual(['Stable releases', 'Watch Ada']);
	await page.screenshot({ path: 'bin/rule-builder-desktop.png', fullPage: true });
});

test('advanced rules and unknown options survive list changes without unsafe numeric rewrites', async ({ page }) => {
	const complex = {
		version: 1,
		limits: { rules: 120, depth: 24 },
		rules: [
			{ id: 'complex', when: { all: Array.from({ length: 9 }, (_, i) => ({ field: 'title', op: 'contains', value: String(i) })) }, actions: { star: true } },
			{ id: 'future', when: { field: 'title', op: 'contains', value: 'x', future_option: { preserve: true } }, actions: { star: true } },
		]
	};
	await openDetails(page, '[data-rule-advanced]');
	await page.locator('#rules-json').fill(JSON.stringify(complex));
	await page.locator('[data-rule-command=edit][data-rule-index="0"]').click();
	await expect(page.locator('[data-rule-editor]')).toBeHidden();
	await expect(page.locator('#rules-json')).toBeFocused();
	await page.locator('[data-select-all]').check();
	await page.locator('[data-rule-command=disable-selected]').click();
	const changed = await documentValue(page);
	expect(changed.rules[0].when).toEqual(complex.rules[0].when);
	expect(changed.rules[1].when).toEqual(complex.rules[1].when);
	expect(changed.limits).toEqual(complex.limits);
	const precise = '{"version":1,"limits":{"rules":9007199254740993},"rules":[]}';
	await page.locator('#rules-json').fill(precise);
	await expect(page.locator('[data-rule-command=add]')).toBeDisabled();
	await expect(page.locator('#rules-json')).toHaveValue(precise);
	await expect(page.locator('[data-ui-error]')).toContainText(/整数精度|browser precision/);
});

test('keyboard editing and plain sample preview work at 320px without horizontal overflow', async ({ page }) => {
	await page.setViewportSize({ width: 320, height: 820 });
	await page.locator('[data-rule-command=add]').click();
	await page.locator('[data-editor-id]').fill('Mobile keyword');
	await page.locator('[data-editor-value]').fill('mobile');
	await page.screenshot({ path: 'bin/rule-builder-mobile-editor.png', fullPage: true });
	await page.locator('[data-editor-value]').press('Enter');
	expect((await documentValue(page)).rules[0].when.value).toBe('mobile');
	await openDetails(page, '[data-preview-panel]');
	await page.locator('[data-sample-field=title]').fill('Mobile release');
	await submit(page, 'preview');
	await expect(page.locator('[data-result-summary]')).toContainText(/命中 1|1 rules match/);
	const preview = JSON.parse(await page.locator('[data-result-details] pre').textContent());
	expect(preview.matched).toEqual(['Mobile keyword']);
	expect(JSON.parse((await (await page.request.get(settings)).json()).configuration.values.rules_json)).toEqual(empty);
	expect(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)).toBe(false);
	await page.screenshot({ path: 'bin/rule-builder-mobile.png', fullPage: true });
});

test('settings slider remounts once and never duplicates a created rule after reopening', async ({ page }) => {
	await page.goto('./?c=extension');
	const link = page.locator('a.open-slider.configure[href*="Rule"]').first();
	await link.click();
	const form = page.locator('#slider [data-rule-engine]');
	await expect(form).toHaveAttribute('data-rule-ui-ready', '1');
	await form.locator('[data-rule-command=add]').click();
	await form.locator('[data-editor-id]').fill('First slider draft');
	await form.locator('[data-editor-value]').fill('first');
	await form.locator('[data-rule-command=apply-editor]').click();
	expect(JSON.parse(await form.locator('#rules-json').inputValue()).rules).toHaveLength(1);
	await page.locator('#slider a[href="#close"]').first().click();
	await link.click();
	await expect(form).toHaveAttribute('data-rule-ui-ready', '1');
	await form.locator('[data-rule-command=add]').click();
	await form.locator('[data-editor-id]').fill('Second slider draft');
	await form.locator('[data-editor-value]').fill('second');
	await form.locator('[data-rule-command=apply-editor]').click();
	expect(JSON.parse(await form.locator('#rules-json').inputValue()).rules.map(rule => rule.id)).toEqual(['Second slider draft']);
});
