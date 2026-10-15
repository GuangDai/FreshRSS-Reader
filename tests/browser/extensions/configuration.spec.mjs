import { test, expect } from '@playwright/test';

const username = process.env.FRESHRSS_TEST_USER;
const password = process.env.FRESHRSS_TEST_PASSWORD;
const configure = name => './?' + new URLSearchParams({ c: 'extension', a: 'configure', e: name });
const injected = '</textarea></pre><img id="extension-injected" src="https://example.invalid/probe" onerror="document.body.dataset.extensionInjected=1">';
let failedResources;
let externalRequests;

test.beforeEach(async ({ page, baseURL }) => {
	test.skip(username !== 'reader' || !password, 'Extension mutation tests require the isolated local reader account.');
	test.skip(!['127.0.0.1', 'localhost', '[::1]'].includes(new URL(baseURL).hostname), 'Extension mutation tests only run on a local server.');
	failedResources = []; externalRequests = [];
	await page.context().route('**/*', route => {
		const url = new URL(route.request().url());
		if (url.origin !== new URL(baseURL).origin) { externalRequests.push(url.href); return route.abort(); }
		// The optional community catalogue must not trigger a server-side GitHub request in local tests.
		if (url.searchParams.get('c') === 'extension' && url.searchParams.get('a') === 'catalog') return route.fulfill({ body: '' });
		return route.continue();
	});
	page.on('response', response => {
		if (response.status() >= 400 && ['script', 'stylesheet'].includes(response.request().resourceType())) failedResources.push(response.url());
	});
	await page.goto('./?c=auth&a=login');
	await page.locator('[name=username]').fill(username);
	await page.locator('input[type=password]').fill(password);
	await Promise.all([page.waitForURL(url => url.searchParams.get('c') !== 'auth'), page.locator('button[type=submit]').click()]);
});

async function submit(page, selector) {
	await Promise.all([page.waitForNavigation(), page.locator(selector).click()]);
}

async function revealRuleField(page, selector) {
	const parents = page.locator(selector).locator('xpath=ancestor::details');
	for (let index = 0; index < await parents.count(); index++) {
		const details = parents.nth(index);
		if (!await details.evaluate(element => element.open)) await details.locator(':scope > summary').click();
	}
}

async function withExtension(page, name, field, run) {
	await page.goto('./?c=extension');
	const toggle = page.locator(`button.switch[aria-label$=" · ${name}"]`);
	const wasEnabled = await toggle.getAttribute('aria-pressed') === 'true';
	await page.goto(configure(name));
	const original = await page.locator(`[name=${field}]`).inputValue();
	const csrf = await page.locator('.extension-configure input[name=_csrf]').last().inputValue();
	try {
		if (!wasEnabled) {
			await page.goto('./?c=extension');
			await submit(page, `button.switch[aria-label$=" · ${name}"]`);
			await expect(page.locator(`button.switch[aria-label$=" · ${name}"]`)).toHaveAttribute('aria-pressed', 'true');
		}
		await page.goto(configure(name));
		await run(csrf);
	} finally {
		try {
			// Restore against the current version, including after a deliberate stale-write conflict.
			await page.goto(configure(name));
			const versionField = name === 'Rule Engine' ? 'settings_version' : 'configuration_version';
			const version = await page.locator(`[name=${versionField}]`).inputValue();
			const response = await page.context().request.post(configure(name), {
				form: { _csrf: csrf, [field]: original, [versionField]: version, ...(name === 'Rule Engine' ? { rule_action: 'save' } : {}) },
			});
			expect(response.ok()).toBe(true);
			await page.goto(configure(name));
			expect(JSON.parse(await page.locator(`[name=${field}]`).inputValue())).toEqual(JSON.parse(original));
		} finally {
			if (!wasEnabled) {
				await page.context().request.post('./?' + new URLSearchParams({ c: 'extension', a: 'disable', e: name }), { form: { _csrf: csrf } });
				await page.goto('./?c=extension');
				await expect(page.locator(`button.switch[aria-label$=" · ${name}"]`)).toHaveAttribute('aria-pressed', 'false');
			}
		}
	}
}

test('Rule Engine settings save, preview without saving, reject invalid operators and escape results', async ({ page }) => {
	await withExtension(page, 'Rule Engine', 'rules_json', async csrf => {
		const rules = { version: 1, rules: [{ id: 'browser-release', when: { field: 'title', op: 'contains', value: 'release' }, actions: { star: true } }] };
		await revealRuleField(page, '#rules-json');
		await page.locator('#rules-json').fill(JSON.stringify(rules));
		await submit(page, '[name=rule_action][value=save]');
		expect(JSON.parse(await page.locator('#rules-json').inputValue())).toEqual(rules);
		await page.locator('#rule-preset').selectOption('releases');
		await page.locator('[data-rule-command=preset]').click();
		await expect(page.locator('[data-compound-values] input')).toHaveCount(2);
		await page.locator('[data-rule-command=cancel-editor]').click();
		expect(JSON.parse(await page.locator('#rules-json').inputValue())).toEqual(rules);
		await page.goto(configure('Rule Engine'));
		expect(JSON.parse(await page.locator('#rules-json').inputValue())).toEqual(rules);
		const draft = structuredClone(rules); draft.rules[0].id = injected;
		await revealRuleField(page, '#rules-json');
		await page.locator('#rules-json').fill(JSON.stringify(draft));
		await revealRuleField(page, '#sample-json');
		await page.locator('#sample-json').fill(JSON.stringify({ title: 'Release 2.0' }));
		await submit(page, '[name=rule_action][value=preview]');
		const preview = JSON.parse(await page.locator('pre[role=status]').textContent());
		expect(preview.matched).toEqual([injected]); expect(preview.actions.star).toBe(true);
		await expect(page.locator('#extension-injected')).toHaveCount(0);
		expect(await page.locator('body').getAttribute('data-extension-injected')).toBeNull();
		const api = await page.context().request.post('./?c=reader&a=rulePreview', {
			data: { _csrf: csrf, rules: JSON.stringify(draft), entry: { title: 'Release 2.0' }, explain: true },
		});
		expect(api.status()).toBe(200); expect((await api.json()).preview).toEqual(preview);
		await page.locator('[name=sample_updated]').check();
		await submit(page, '[name=rule_action][value=preview]');
		const updatedPreview = JSON.parse(await page.locator('pre[role=status]').textContent());
		expect(updatedPreview.actions.star).toBeUndefined();
		expect(updatedPreview.explanation.suppressed_actions).toContain('star');
		await expect(page.locator('[name=sample_updated]')).toBeChecked();
		const updatedAPI = await page.context().request.post('./?c=reader&a=rulePreview', {
			data: { _csrf: csrf, rules: JSON.stringify(draft), entry: { title: 'Release 2.0' }, explain: true, updated: true },
		});
		expect(updatedAPI.status()).toBe(200); expect((await updatedAPI.json()).preview).toEqual(updatedPreview);
		const unknown = await page.context().request.post('./?c=reader&a=rulePreview', {
			data: { _csrf: csrf, rules: JSON.stringify(draft), entry: { unknown: 'not an entry field' } },
		});
		expect(unknown.status()).toBe(400);
		const unknownResult = await unknown.json();
		expect(unknownResult.error).toBe('invalid_preview'); expect(unknownResult.fields[0]).toContain('sample.unknown');
		await page.goto(configure('Rule Engine'));
		expect(JSON.parse(await page.locator('#rules-json').inputValue())).toEqual(rules);
		draft.rules[0].when.op = 'unknown_operator';
		await revealRuleField(page, '#rules-json');
		await page.locator('#rules-json').fill(JSON.stringify(draft));
		await submit(page, '[name=rule_action][value=save]');
		await expect(page.locator('pre[role=status]')).toContainText('op');
		expect(JSON.parse(await page.locator('#rules-json').inputValue())).toEqual(draft);
		const bad = await page.context().request.post('./?c=reader&a=rulePreview', { data: { _csrf: csrf, rules: JSON.stringify(draft), entry: {} } });
		expect(bad.status()).toBe(400); expect((await bad.json()).error).toBe('invalid_rules');
		await page.goto(configure('Rule Engine'));
		expect(JSON.parse(await page.locator('#rules-json').inputValue())).toEqual(rules);
	});
	expect(failedResources).toEqual([]); expect(externalRequests).toEqual([]);
});

test('Web Extraction settings preserve drafts, preview supplied HTML safely and reject invalid recipe roots', async ({ page }) => {
	const openDetails = async (target, selector) => {
		if (!await target.locator(selector).evaluate(element => element.open)) await target.locator(selector + ' > summary').click();
	};
	const editRecipes = async (target, value) => {
		await openDetails(target, '[data-extraction-advanced]');
		await target.locator('#extraction-recipes').fill(value);
	};
	await withExtension(page, 'Web Extraction', 'recipes', async csrf => {
		const recipe = { fields: { title: [{ type: 'css', selector: 'h1' }], content: [{ type: 'css', selector: 'article', mode: 'html' }] }, required: ['title'] };
		const recipes = { '*': recipe };
		await editRecipes(page, JSON.stringify(recipes));
		await submit(page, '.extension-configure button[type=submit]:not([name])');
		expect(JSON.parse(await page.locator('#extraction-recipes').inputValue())).toEqual(recipes);
		const source = `<h1>Preview title</h1><article>${injected}<p>Body</p></article>`;
		await openDetails(page, '[data-preview-panel]');
		await page.locator('#extraction-source').fill(source); await page.locator('#extraction-feed').fill('1');
		await submit(page, '[name=preview]');
		await expect(page.locator('#extraction-source')).toHaveValue(source);
		await expect(page.locator('#extension-injected')).toHaveCount(0);
		const preview = JSON.parse(await page.locator('[data-result-details] pre').textContent());
		expect(preview.values.title).toBe('Preview title'); expect(preview.errors).toEqual([]);
		expect(preview.values.content).toContain('extension-injected');
		const api = await page.context().request.post('./?c=reader&a=extractionPreview', { data: { _csrf: csrf, source, recipe } });
		expect(api.status()).toBe(200); expect((await api.json()).preview).toEqual(preview);
		await editRecipes(page, JSON.stringify({ '*': { unexpected: true } }));
		await submit(page, '[name=preview]');
		await expect(page.locator('.alert-error').first()).toContainText('Unknown recipe option');
		await expect(page.locator('#extraction-source')).toHaveValue(source);
		await expect(page.locator('#extraction-feed')).toHaveValue('1');
		await page.goto(configure('Web Extraction'));
		expect(JSON.parse(await page.locator('#extraction-recipes').inputValue())).toEqual(recipes);
		await editRecipes(page, '[]');
		await submit(page, '.extension-configure button[type=submit]:not([name])');
		await expect(page.locator('.alert-error')).toContainText('JSON object');
		await editRecipes(page, '{}');
		await submit(page, '.extension-configure button[type=submit]:not([name])');
		expect(JSON.parse(await page.locator('#extraction-recipes').inputValue())).toEqual({});
	});
	expect(failedResources).toEqual([]); expect(externalRequests).toEqual([]);
});

test('Rule Engine patches stay in the draft and a second window cannot overwrite newer saved rules', async ({ page }) => {
	await withExtension(page, 'Rule Engine', 'rules_json', async () => {
		const first = { version: 1, rules: [{ id: 'browser-first', when: { field: 'title', op: 'contains', value: 'release' }, actions: { read: true } }] };
		await revealRuleField(page, '#rules-json');
		await page.locator('#rules-json').fill(JSON.stringify(first));
		await submit(page, '[name=rule_action][value=save]');
		const second = await page.context().newPage();
		try {
			await second.goto(configure('Rule Engine'));
			const staleVersion = await second.locator('[name=settings_version]').inputValue();
			const newer = structuredClone(first); newer.rules[0].id = 'browser-newer';
			await revealRuleField(page, '#rules-json');
			await page.locator('#rules-json').fill(JSON.stringify(newer));
			await submit(page, '[name=rule_action][value=save]');
			const currentVersion = await page.locator('[name=settings_version]').inputValue();
			expect(currentVersion).not.toBe(staleVersion);
			const operations = [{ op: 'enable', id: 'browser-first', enabled: false }];
			await revealRuleField(second, '#rule-operations');
			await second.locator('#rule-operations').fill(JSON.stringify(operations));
			await submit(second, '[name=rule_action][value=patch]');
			const draft = structuredClone(first); draft.rules[0].enabled = false;
			expect(JSON.parse(await second.locator('#rules-json').inputValue())).toEqual(draft);
			expect(JSON.parse(await second.locator('#rule-operations').inputValue())).toEqual(operations);
			await page.reload();
			expect(JSON.parse(await page.locator('#rules-json').inputValue())).toEqual(newer);
			await submit(second, '[name=rule_action][value=save]');
			await expect(second.locator('pre[role=status]')).toContainText(/其他请求已修改配置|Another request changed these settings/);
			expect(JSON.parse(await second.locator('#rules-json').inputValue())).toEqual(draft);
			expect(JSON.parse(await second.locator('[data-current-rules] pre').textContent())).toEqual(newer);
			await expect(second.locator('[name=settings_version]')).toHaveValue(currentVersion);
			await page.reload();
			expect(JSON.parse(await page.locator('#rules-json').inputValue())).toEqual(newer);
		} finally { await second.close(); }
	});
	expect(failedResources).toEqual([]); expect(externalRequests).toEqual([]);
});

test('Web Extraction preserves a conflicting draft and preview source while keeping the newer saved recipe', async ({ page }) => {
	const openDetails = async (target, selector) => {
		if (!await target.locator(selector).evaluate(element => element.open)) await target.locator(selector + ' > summary').click();
	};
	const editRecipes = async (target, value) => {
		await openDetails(target, '[data-extraction-advanced]');
		await target.locator('#extraction-recipes').fill(value);
	};
	await withExtension(page, 'Web Extraction', 'recipes', async () => {
		const save = '.extension-configure button[type=submit]:not([name])';
		const recipe = selector => ({ '*': { fields: { title: [{ type: 'css', selector }] }, required: ['title'] } });
		const first = recipe('h1');
		await editRecipes(page, JSON.stringify(first));
		await submit(page, save);
		const second = await page.context().newPage();
		try {
			await second.goto(configure('Web Extraction'));
			const staleVersion = await second.locator('[name=configuration_version]').inputValue();
			const newer = recipe('h2');
			await editRecipes(page, JSON.stringify(newer));
			await submit(page, save);
			const currentVersion = await page.locator('[name=configuration_version]').inputValue();
			expect(currentVersion).not.toBe(staleVersion);
			const draft = recipe('h3');
			const source = `<h3>Unsaved preview</h3>${injected}`;
			await editRecipes(second, JSON.stringify(draft));
			await openDetails(second, '[data-preview-panel]');
			await second.locator('#extraction-source').fill(source);
			await second.locator('#extraction-feed').fill('1');
			await submit(second, save);
			await expect(second.locator('.alert-error')).toContainText(/其他请求|Another request/);
			expect(JSON.parse(await second.locator('#extraction-recipes').inputValue())).toEqual(draft);
			await expect(second.locator('#extraction-source')).toHaveValue(source);
			await expect(second.locator('#extraction-feed')).toHaveValue('1');
			expect(JSON.parse(await second.locator('#extraction-latest pre').textContent())).toEqual(newer);
			await expect(second.locator('[name=configuration_version]')).toHaveValue(currentVersion);
			await expect(second.locator('#extension-injected')).toHaveCount(0);
			await page.reload();
			expect(JSON.parse(await page.locator('#extraction-recipes').inputValue())).toEqual(newer);
		} finally { await second.close(); }
	});
	expect(failedResources).toEqual([]); expect(externalRequests).toEqual([]);
});
