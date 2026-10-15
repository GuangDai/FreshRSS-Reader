import { test, expect } from '@playwright/test';

const username = process.env.FRESHRSS_TEST_USER;
const password = process.env.FRESHRSS_TEST_PASSWORD;
let csrf;

test.beforeEach(async ({ page }) => {
	test.skip(!username || !password, 'Use an isolated local test account.');
	await page.goto('./?c=auth&a=login');
	await page.locator('[name=username]').fill(username);
	await page.locator('input[type=password]').fill(password);
	await Promise.all([page.waitForURL(url => url.searchParams.get('c') !== 'auth'), page.locator('button[type=submit]').click()]);
	await page.goto('./?c=reader');
	csrf = await page.locator('#reader-bootstrap').evaluate(node => JSON.parse(node.textContent).csrf);
});

test('capabilities and schema expose limits and preview presets', async ({ page }) => {
	const request = page.context().request;
	const caps = await (await request.get('./?c=reader&a=capabilities')).json();
	expect(caps.version).toBe(1);
	expect(caps.authentication).toBe('session');
	expect(caps.limits.maxBatchItems).toBeGreaterThan(0);
	const schema = await (await request.get('./?c=reader&a=schema')).json();
	expect(schema.rulePresets).toBeTruthy();
	expect(schema.extractionPresets).toBeTruthy();
});

test('batch validates every ID before any mutation and rejects ambiguous state', async ({ page }) => {
	const request = page.context().request;
	const items = await (await request.get('./?c=reader&a=items&limit=1')).json();
	const entry = items.items[0];
	test.skip(!entry, 'Sample entry required.');
	const response = await request.post('./?c=reader&a=batch', { data: { _csrf: csrf, ids: [entry.id, '9223372036854775807'], read: !entry.read } });
	expect(response.status()).toBe(404);
	const after = await (await request.get(`./?c=reader&a=article&id=${entry.id}`)).json();
	expect(after.article.read).toBe(entry.read);
	const ambiguous = await request.post('./?c=reader&a=batch', { data: { _csrf: csrf, ids: [entry.id], read: true, starred: false } });
	expect(ambiguous.status()).toBe(400);
	const wrongMethod = await request.get('./?c=reader&a=batch');
	expect(wrongMethod.status()).toBe(405);
});

test('batch accepts string IDs and idempotent retries', async ({ page }) => {
	const request = page.context().request;
	const entry = (await (await request.get('./?c=reader&a=items&limit=1')).json()).items[0];
	test.skip(!entry, 'Sample entry required.');
	for (let i = 0; i < 2; i++) {
		const response = await request.post('./?c=reader&a=batch', { data: { _csrf: csrf, ids: [entry.id, entry.id], read: entry.read } });
		expect(response.status()).toBe(200);
		expect((await response.json()).ids).toEqual([entry.id]);
	}
});

test('malformed JSON and oversized batch return explicit errors', async ({ page }) => {
	const request = page.context().request;
	const invalid = await request.post('./?c=reader&a=batch', { headers: { 'Content-Type': 'application/json' }, data: '{bad' });
	expect(invalid.status()).toBe(400);
	expect((await invalid.json()).error).toBe('invalid_json');
	const caps = await (await request.get('./?c=reader&a=capabilities')).json();
	const response = await request.post('./?c=reader&a=batch', { data: { _csrf: csrf, ids: Array(caps.limits.maxBatchItems + 1).fill('1'), read: true } });
	expect(response.status()).toBe(413);
});

test('feed and category mutations reject wrong types and missing IDs', async ({ page }) => {
	const request = page.context().request;
	for (const [action, data, status] of [
		['feedUpdate', { id: '1', name: 'bad' }, 400],
		['feedUpdate', { id: 2147483647, name: 'bad' }, 404],
		['categories', { id: 2147483647, name: 'bad' }, 404],
		['categories', { name: '' }, 400],
	]) {
		const response = await request.post(`./?c=reader&a=${action}`, { data: { _csrf: csrf, ...data } });
		expect(response.status()).toBe(status);
	}
	const opml = await request.get('./?c=reader&a=export');
	expect(opml.status()).toBe(200);
	expect(opml.headers()['content-type']).toContain('text/x-opml');
	expect(await opml.text()).toContain('<opml');
});

test('preview APIs validate rules and extract supplied HTML without fetching', async ({ page }) => {
	const request = page.context().request;
	const schema = await (await request.get('./?c=reader&a=schema')).json();
	const rule = await request.post('./?c=reader&a=rulePreview', {
		data: { _csrf: csrf, rules: schema.rulePresets.sponsored, entry: { title: 'Sponsored article' } },
	});
	expect(rule.status()).toBe(200);
	expect((await rule.json()).preview).toBeTruthy();
	const invalid = await request.post('./?c=reader&a=rulePreview', { data: { _csrf: csrf, rules: '{', entry: {} } });
	expect(invalid.status()).toBe(400);
	const unknownField = await request.post('./?c=reader&a=rulePreview', {
		data: { _csrf: csrf, rules: schema.rulePresets.sponsored, entry: { unexpected: 'x' } },
	});
	expect(unknownField.status()).toBe(400);
	expect((await unknownField.json()).error).toBe('invalid_preview');
	const extraction = await request.post('./?c=reader&a=extractionPreview', {
		data: {
			_csrf: csrf,
			source: '<h1>Local title</h1><article>Hello</article>',
			recipe: schema.extractionPresets.article,
			baseUrl: 'http://127.0.0.1:1/unreachable'
		},
	});
	expect(extraction.status()).toBe(200);
	expect((await extraction.json()).preview.values.title).toBe('Local title');
});

test('scheduler is restricted to administrators', async ({ browser }) => {
	const otherUser = process.env.FRESHRSS_TEST_OTHER_USER;
	const otherPassword = process.env.FRESHRSS_TEST_OTHER_PASSWORD;
	test.skip(!otherUser || !otherPassword, 'An isolated non-administrator account is required.');
	const context = await browser.newContext();
	try {
		const page = await context.newPage();
		await page.goto(new URL('./?c=auth&a=login', process.env.FRESHRSS_TEST_URL || 'http://127.0.0.1:18080/i/').href);
		await page.locator('[name=username]').fill(otherUser);
		await page.locator('input[type=password]').fill(otherPassword);
		await Promise.all([page.waitForURL(url => url.searchParams.get('c') !== 'auth'), page.locator('button[type=submit]').click()]);
		const response = await context.request.get(new URL('./?c=reader&a=scheduler', page.url()).href);
		expect(response.status()).toBe(403);
		expect((await response.json()).error).toBe('administrator_required');
	} finally {
		await context.close();
	}
});

test('multipart cannot bypass the raw body limit', async ({ page }) => {
	const response = await page.context().request.post('./?c=reader&a=categories', {
		multipart: { _csrf: csrf, name: 'Multipart must be rejected' },
	});
	expect(response.status()).toBe(415);
	expect((await response.json()).error).toBe('unsupported_media_type');
});

test('contract methods match HTTP and plugin settings round-trip with semantic validation', async ({ page }) => {
	const request = page.context().request;
	const schema = await (await request.get('./?c=reader&a=schema')).json();
	expect(schema.contract.endpoints.batch.methods).toEqual(['POST']);
	expect(schema.contract.endpoints.batch.operations.POST.request.properties.ids.maxItems).toBe(schema.limits.maxBatchItems);
	const wrongMethod = await request.post('./?c=reader&a=article', { data: { _csrf: csrf, id: '1' } });
	expect(wrongMethod.status()).toBe(405);
	expect(wrongMethod.headers().allow).toBe('GET');
	const before = await (await request.get('./?c=reader&a=extensionConfig&extension=RuleEngine')).json();
	expect(before.configuration.extension).toBe('RuleEngine');
	const invalid = await request.post('./?c=reader&a=extensionConfig', {
		data: { _csrf: csrf, extension: 'RuleEngine', expectedVersion: before.configuration.version, configuration: { rules_json: '{' } },
	});
	expect(invalid.status()).toBe(400);
	expect((await invalid.json()).fields.rules_json).toBeTruthy();
	try {
		const saved = await request.post('./?c=reader&a=extensionConfig', {
			data: { _csrf: csrf, extension: 'RuleEngine', expectedVersion: before.configuration.version, configuration: { rules_json: '{"version":1,"rules":[]}' } },
		});
		expect(saved.status()).toBe(200);
		const after = await (await request.get('./?c=reader&a=extensionConfig&extension=RuleEngine')).json();
		expect(after.configuration.values.rules_json).toBe('{"version":1,"rules":[]}');
		const unknown = await request.post('./?c=reader&a=extensionConfig', {
			data: { _csrf: csrf, extension: 'RuleEngine', expectedVersion: after.configuration.version, configuration: { unexpected: true } },
		});
		expect(unknown.status()).toBe(400);
	} finally {
		const latest = await (await request.get('./?c=reader&a=extensionConfig&extension=RuleEngine')).json();
		const restored = await request.post('./?c=reader&a=extensionConfig', {
			data: { _csrf: csrf, extension: 'RuleEngine', expectedVersion: latest.configuration.version, configuration: before.configuration.values },
		});
		expect(restored.status()).toBe(200);
	}
});

test('subscription batches apply together and reject stale versions without partial changes', async ({ page }) => {
	const request = page.context().request;
	const original = (await (await request.get('./?c=reader&a=feeds')).json()).feeds.slice(0, 2);
	expect(original).toHaveLength(2);
	const ids = original.map(feed => feed.id);
	const expectedVersions = Object.fromEntries(original.map(feed => [feed.id, feed.version]));
	try {
		const response = await request.post('./?c=reader&a=feedBatch', {
			data: { _csrf: csrf, ids, expectedVersions, changes: { ttl: 3600, enabled: false } },
		});
		expect(response.status()).toBe(200);
		const changed = await response.json();
		expect(changed.atomic).toBe(true);
		expect(changed.updated).toEqual(ids);
		expect(changed.feeds.every(feed => feed.enabled === false)).toBe(true);
		const stale = await request.post('./?c=reader&a=feedBatch', {
			data: { _csrf: csrf, ids, expectedVersions, changes: { name: 'Must not overwrite' } },
		});
		expect(stale.status()).toBe(409);
		expect((await stale.json()).conflicts).toHaveLength(2);
		const latest = (await (await request.get('./?c=reader&a=feeds')).json()).feeds.filter(feed => ids.includes(feed.id));
		expect(latest.map(feed => feed.name)).toEqual(original.map(feed => feed.name));
		const invalid = await request.post('./?c=reader&a=feedBatch', {
			data: { _csrf: csrf, ids, expectedVersions, changes: { url: 'http://127.0.0.1/private' } },
		});
		expect(invalid.status()).toBe(400);
	} finally {
		const current = (await (await request.get('./?c=reader&a=feeds')).json()).feeds;
		const items = original.map(feed => ({
			type: 'feed',
			id: feed.id,
			expectedVersion: current.find(item => item.id === feed.id).version,
			changes: { ttl: feed.ttl, enabled: feed.enabled }
		}));
		const restored = await request.post('./?c=reader&a=subscriptionBatch', { data: { _csrf: csrf, items } });
		expect(restored.status()).toBe(200);
	}
});

test('concurrent configuration updates from one snapshot have only one winner', async ({ page }) => {
	const request = page.context().request;
	const original = (await (await request.get('./?c=reader&a=feeds')).json()).feeds[0];
	const payload = { _csrf: csrf, ids: [original.id], expectedVersions: { [original.id]: original.version } };
	try {
		const responses = await Promise.all([
			request.post('./?c=reader&a=feedBatch', { data: { ...payload, changes: { ttl: 7200 } } }),
			request.post('./?c=reader&a=feedBatch', { data: { ...payload, changes: { ttl: 10800 } } }),
		]);
		expect(responses.filter(response => response.status() === 200)).toHaveLength(1);
		expect(responses.filter(response => [409, 503].includes(response.status()))).toHaveLength(1);
	} finally {
		const current = (await (await request.get('./?c=reader&a=feeds')).json()).feeds.find(feed => feed.id === original.id);
		const restored = await request.post('./?c=reader&a=feedBatch', {
			data: {
				_csrf: csrf,
				ids: [original.id],
				expectedVersions: { [original.id]: current.version },
				changes: { ttl: original.ttl, enabled: original.enabled }
			},
		});
		expect(restored.status()).toBe(200);
	}
});

test('plugin batch validates every setting and version before publishing any configuration', async ({ page }) => {
	const request = page.context().request;
	const load = async extension => (await (await request.get(`./?c=reader&a=extensionConfig&extension=${extension}`)).json()).configuration;
	const originals = await Promise.all(['RuleEngine', 'WebExtraction'].map(load));
	const change = (configuration, values) => ({ extension: configuration.extension, expectedVersion: configuration.version, configuration: values });
	try {
		const invalid = await request.post('./?c=reader&a=extensionBatch', {
			data: {
				_csrf: csrf,
				items: [change(originals[0], { hook_priority: originals[0].values.hook_priority + 1 }),
					change(originals[1], { recipes: 42 })]
			},
		});
		expect(invalid.status()).toBe(400);
		expect((await load('RuleEngine')).version).toBe(originals[0].version);
		const items = [change(originals[0], { hook_priority: originals[0].values.hook_priority + 1 }), change(originals[1], { recipes: {} })];
		const saved = await request.post('./?c=reader&a=extensionBatch', { data: { _csrf: csrf, items } });
		expect(saved.status()).toBe(200);
		const result = await saved.json();
		expect(result.atomic).toBe(true);
		expect(result.extensions.RuleEngine.values.hook_priority).toBe(originals[0].values.hook_priority + 1);
		const stale = await request.post('./?c=reader&a=extensionBatch', { data: { _csrf: csrf, items } });
		expect(stale.status()).toBe(409);
		expect((await stale.json()).conflicts.RuleEngine.version).toBe(result.extensions.RuleEngine.version);
	} finally {
		const latest = await Promise.all(originals.map(item => load(item.extension)));
		const items = latest.map((item, index) => change(item, originals[index].values));
		const restored = await request.post('./?c=reader&a=extensionBatch', { data: { _csrf: csrf, items } });
		expect(restored.status()).toBe(200);
	}
});

test('rule batch preview returns a draft and never partially returns an invalid edit', async ({ page }) => {
	const request = page.context().request;
	const rules = JSON.stringify({ version: 1, rules: [{ id: 'watch', when: { field: 'title', op: 'contains', value: 'release' }, actions: { star: true } }] });
	const response = await request.post('./?c=reader&a=rulePreview', {
		data: { _csrf: csrf, rules, entry: { title: 'release' }, explain: true, operations: [{ op: 'enable', id: 'watch', enabled: false }] },
	});
	expect(response.status()).toBe(200);
	const result = await response.json();
	expect(JSON.parse(result.patchedRules).rules[0].enabled).toBe(false);
	expect(result.preview.matched).toEqual([]);
	const invalid = await request.post('./?c=reader&a=rulePreview', {
		data: { _csrf: csrf, rules, operations: [{ op: 'enable', id: 'watch', enabled: false }, { op: 'remove', id: 'missing' }] },
	});
	expect(invalid.status()).toBe(400);
	expect((await invalid.json()).patchedRules).toBeUndefined();
});

test('authentication and expired CSRF return recoverable JSON without weakening the checks', async ({ page, browser }) => {
	const unauthenticated = await browser.newContext();
	try {
		const url = new URL('./?c=reader&a=batch', page.url()).href;
		const rejected = await unauthenticated.request.post(url, { data: { ids: ['1'], read: true } });
		expect(rejected.status()).toBe(403);
		expect(rejected.headers()['content-type']).toContain('application/json');
		expect(rejected.headers()['cache-control']).toContain('no-store');
		expect((await rejected.json()).error).toBe('authentication_required');
	} finally {
		await unauthenticated.close();
	}
	for (const token of [undefined, 'expired-csrf-token']) {
		const rejected = await page.context().request.post('./?c=reader&a=batch', { data: { _csrf: token, ids: ['1'], read: true } });
		expect(rejected.status()).toBe(403);
		const data = await rejected.json();
		expect(data.error).toBe('csrf_invalid');
		expect(data.errorDetails).toEqual({ code: 'csrf_invalid', status: 403 });
	}
});

test('empty, scalar and oversized JSON use the same private error envelope', async ({ page }) => {
	const request = page.context().request;
	for (const body of ['', '0', '[]', '{']) {
		const rejected = await request.post('./?c=reader&a=batch', { headers: { 'Content-Type': 'application/json' }, data: body });
		expect(rejected.status()).toBe(400);
		expect((await rejected.json()).errorDetails).toEqual({ code: 'invalid_json', status: 400 });
	}
	const limits = (await (await request.get('./?c=reader&a=capabilities')).json()).limits;
	const oversized = await request.post('./?c=reader&a=batch', { data: { _csrf: csrf, padding: 'x'.repeat(limits.maxRequestBytes) } });
	expect(oversized.status()).toBe(413);
	expect((await oversized.json()).errorDetails).toEqual({ code: 'request_too_large', status: 413 });
	expect(oversized.headers()['cache-control']).toContain('no-store');
});

test('pagination validates terminal cursors and reports search limits as JSON', async ({ page }) => {
	const request = page.context().request;
	const schema = await (await request.get('./?c=reader&a=schema')).json();
	const maximum = schema.preferences.pageSize.max;
	const invalid = await request.get(`./?c=reader&a=items&cursor=1&limit=${maximum + 1}`);
	expect(invalid.status()).toBe(400);
	expect((await invalid.json()).error).toBe('page_size_out_of_range');
	const terminal = await request.get('./?c=reader&a=items&cursor=1&limit=1');
	expect(await terminal.json()).toMatchObject({ items: [], next: null });
	const badSearch = await request.get('./?c=reader&a=items', { params: { search: '('.repeat(40) + 'keyword' + ')'.repeat(40) } });
	expect(badSearch.status()).toBe(400);
	expect((await badSearch.json()).error).toBe('invalid_search');
	const arraySearch = await request.get('./?c=reader&a=items&search%5B%5D=keyword');
	expect(arraySearch.status()).toBe(400);
	expect((await arraySearch.json()).error).toBe('invalid_search');
});

test('administrator queue history accepts only an explicit positive cursor', async ({ page }) => {
	const request = page.context().request;
	const bootstrap = await page.locator('#reader-bootstrap').evaluate(node => JSON.parse(node.textContent));
	test.skip(!bootstrap.canManageLimits, 'An administrator fixture is required.');
	for (const value of ['0', '-1', '01', '1e4', '9999999999999999999999']) {
		const invalid = await request.get('./?c=reader&a=scheduler', { params: { history_before: value } });
		expect(invalid.status()).toBe(400);
		expect((await invalid.json()).error).toBe('invalid_history_cursor');
	}
	const first = await request.get('./?c=reader&a=scheduler');
	expect(first.status()).toBe(200);
	const data = await first.json();
	expect(data.status.history.length).toBeLessThanOrEqual(data.configuration.status_history_limit);
	expect(data.status).toHaveProperty('historyNext');
	const empty = await request.get('./?c=reader&a=scheduler&history_before=1');
	expect(empty.status()).toBe(200);
	expect((await empty.json()).status.history).toEqual([]);
});
