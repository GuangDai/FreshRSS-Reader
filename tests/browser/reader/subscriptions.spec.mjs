import { test, expect } from '@playwright/test';

async function openSubscriptions(page) {
	if (!await page.locator('#settings-page').isVisible()) await page.locator('#open-settings').click();
	const section = page.locator('[data-settings-section="subscriptions"]');
	if (await section.isVisible()) await section.click();
	else await page.locator('#settings-section-select').selectOption('subscriptions');
	await expect(page.locator('#settings-feed-list')).toBeVisible();
}

async function fixture(page, configure) {
	const username = process.env.FRESHRSS_TEST_USER;
	const password = process.env.FRESHRSS_TEST_PASSWORD;
	test.skip(!username || !password, 'Use an isolated local test account.');
	await page.goto('./?c=auth&a=login');
	await page.locator('[name=username]').fill(username);
	await page.locator('input[type=password]').fill(password);
	await Promise.all([page.waitForURL(url => url.searchParams.get('c') !== 'auth'), page.locator('button[type=submit]').click()]);
	const state = {
		feeds: Array.from({ length: 6 }, (_, index) => ({
			id: index + 1,
			name: `Feed ${index + 1}`,
			categoryId: 1,
			unread: 1,
			error: false,
			ttl: 3600,
			enabled: true,
			priority: 10,
			version: 'v1-' + (index + 1),
		})),
		requests: [],
	};
	configure?.(state);
	await page.route('**/*c=reader*', async route => {
		const url = new URL(route.request().url());
		const action = url.searchParams.get('a');
		if (!action) {
			const response = await route.fetch();
			let html = await response.text();
			html = html.replace(/(<script[^>]*id="reader-bootstrap"[^>]*>)([\s\S]*?)(<\/script>)/, (_, open, json, close) => {
				const bootstrap = JSON.parse(json);
				bootstrap.preferences = { ...bootstrap.preferences, layout: 'split', refreshInterval: 0, pageSize: 5 };
				bootstrap.apiLimits.maxBatchItems = 2;
				bootstrap.subscription = {
					fields: {
						categoryId: { type: 'integer', min: 1, max: 100 },
						ttl: { type: 'integer', min: 0, max: 86400 },
						enabled: { type: 'boolean' },
						priority: { type: 'integer', options: [-10, -5, 0, 10, 20] },
						name: { type: 'string', maxLength: 255 },
					}
				};
				return open + JSON.stringify(bootstrap).replaceAll('<', '\\u003c') + close;
			});
			return route.fulfill({ response, body: html });
		}
		if (action === 'feeds') {
			return route.fulfill({ json: { version: 1, feeds: state.feeds, categories: [{ id: 1, name: 'First' }, { id: 2, name: 'Second' }] } });
		}
		if (action === 'items') {
			return route.fulfill({
				json: {
					version: 1,
					next: null,
					items: Array.from({ length: 5 }, (_, index) => ({
						id: String(index + 1), title: 'Article ' + index, feedId: 1, date: 1, read: false, starred: false,
					})),
				}
			});
		}
		if (action === 'article') {
			return route.fulfill({
				json: {
					version: 1,
					article: {
						id: url.searchParams.get('id'),
						title: 'Reading stays available',
						content: '<p>Independent reader</p>',
						read: false,
						starred: false,
						link: '',
					}
				}
			});
		}
		if (action === 'feedBatch') {
			const body = route.request().postDataJSON(); state.requests.push(body);
			if (state.beforeBatch) {
				const response = await state.beforeBatch(body, route);
				if (response) return route.fulfill(response);
			}
			const conflict = body.ids.find(id => state.feeds.find(feed => feed.id === id).version !== body.expectedVersions[id]);
			if (conflict) {
				return route.fulfill({
					status: 409,
					json: {
						version: 1,
						error: 'configuration_conflict',
						atomic: true,
						conflicts: [{ type: 'feed', id: conflict, current: state.feeds.find(feed => feed.id === conflict) }],
					}
				});
			}
			const updated = body.ids.map(id => {
				const feed = state.feeds.find(feed => feed.id === id);
				Object.assign(feed, body.changes, { version: feed.version + '-next' }); return feed;
			});
			return route.fulfill({ json: { version: 1, atomic: true, feeds: updated, updated: body.ids } });
		}
		return route.fulfill({ status: 400, json: { version: 1, error: 'unexpected_fixture_action' } });
	});
	await page.goto('./?c=reader');
	await expect(page.locator('.feed-button')).toHaveCount(6);
	await openSubscriptions(page);
	await expect(page.locator('#settings-feed-list [data-select-feed]')).toHaveCount(6);
	return state;
}

test('editing subscription fields selects changes immediately and reverting or excluding them keeps saving deliberate', async ({ page }) => {
	await fixture(page);
	await page.locator('#settings-feed-list [data-select-feed="1"]').check(); await page.locator('#settings-feed-list [data-select-feed="2"]').check();
	await page.locator('#edit-selected-feeds').click();
	const ttl = page.locator('#feed-setting-ttl');
	const applyTtl = page.locator('[data-change-feed-field=ttl]');
	const save = page.locator('#save-feed-settings');
	const reset = page.locator('#reset-feed-settings');
	await expect(ttl).toBeEnabled(); await expect(ttl).toHaveValue('3600');
	await expect(page.locator('#feed-setting-name')).toHaveValue('');
	await expect(save).toBeDisabled(); await expect(reset).toBeHidden();
	await ttl.fill('0');
	await expect(applyTtl).toBeChecked(); await expect(save).toBeEnabled(); await expect(reset).toBeVisible();
	await applyTtl.uncheck();
	await expect(ttl).toBeEnabled(); await expect(ttl).toHaveValue('0'); await expect(save).toBeDisabled();
	await applyTtl.check(); await expect(save).toBeEnabled();
	await ttl.fill('3600');
	await expect(applyTtl).not.toBeChecked(); await expect(save).toBeDisabled(); await expect(reset).toBeHidden();
	await ttl.focus(); await ttl.press('ArrowUp');
	await expect(applyTtl).toBeChecked(); await expect(save).toBeEnabled();
	await reset.click();
	await expect(ttl).toHaveValue('3600'); await expect(save).toBeDisabled(); await expect(reset).toBeHidden();
	await page.locator('#feed-setting-categoryId').selectOption('2');
	await expect(page.locator('[data-change-feed-field=categoryId]')).toBeChecked(); await expect(save).toBeEnabled();
	await page.locator('#feed-setting-categoryId').selectOption('1');
	await expect(page.locator('[data-change-feed-field=categoryId]')).not.toBeChecked(); await expect(save).toBeDisabled();
});

test('mixed fields accept zero and false directly while excluded drafts stay out of the saved field mask', async ({ page }) => {
	const state = await fixture(page, state => {
		Object.assign(state.feeds[1], { ttl: 1800, enabled: false, priority: 0, categoryId: 2 });
	});
	await page.locator('#settings-feed-list [data-select-feed="1"]').check(); await page.locator('#settings-feed-list [data-select-feed="2"]').check();
	await page.locator('#edit-selected-feeds').click();
	for (const key of ['ttl', 'enabled', 'priority', 'categoryId']) {
		await expect(page.locator('#feed-setting-' + key)).toHaveValue('');
		await expect(page.locator('#feed-setting-' + key)).toBeEnabled();
	}
	await page.locator('#feed-setting-ttl').fill('0');
	await page.locator('#feed-setting-enabled').selectOption('false');
	await page.locator('#feed-setting-priority').selectOption('0');
	await page.locator('#feed-setting-categoryId').selectOption('2');
	for (const key of ['ttl', 'enabled', 'priority', 'categoryId']) await expect(page.locator('[data-change-feed-field=' + key + ']')).toBeChecked();
	await page.locator('[data-change-feed-field=ttl]').uncheck();
	await page.locator('#save-feed-settings').click();
	await expect(page.locator('[data-feed-result=saved]')).toHaveCount(2);
	expect(state.requests).toHaveLength(1);
	expect(state.requests[0].changes).toEqual({ enabled: false, priority: 0, categoryId: 2 });
	expect(state.feeds.map(feed => feed.ttl).slice(0, 2)).toEqual([3600, 1800]);
	await expect(page.locator('#save-feed-settings')).toBeDisabled();
	await expect(page.locator('[data-change-feed-field=enabled]')).not.toBeChecked();
	await expect(page.locator('#feed-setting-enabled')).toBeEnabled();
	await page.locator('#reset-feed-settings').click();
	await expect(page.locator('#feed-setting-ttl')).toHaveValue('');
	await expect(page.locator('#reset-feed-settings')).toBeHidden();
});

test('filtered range selection sends a stable field-masked batch while reading and selection remain available', async ({ page }) => {
	const state = await fixture(page);
	await page.locator('#settings-feed-list [data-select-feed="1"]').check();
	await page.locator('#settings-feed-list [data-select-feed="3"]').click({ modifiers: ['Shift'] });
	await expect(page.locator('#settings-feed-list .feed-select:checked')).toHaveCount(3);
	await page.locator('#settings-feed-search').fill('Feed 6'); await page.locator('#select-visible-feeds').click();
	await expect(page.locator('#settings-feed-list .feed-select:checked')).toHaveCount(4);
	await page.locator('#clear-feed-selection').click(); await page.locator('#settings-feed-search').fill('');
	await page.locator('#settings-feed-list [data-select-feed="1"]').check();
	await page.locator('#settings-feed-list [data-select-feed="3"]').click({ modifiers: ['Shift'] });
	await page.locator('#edit-selected-feeds').click();
	await page.locator('#feed-setting-ttl').fill('0');
	let release;
	const gate = new Promise(resolve => { release = resolve; });
	state.beforeBatch = () => gate;
	await page.locator('#save-feed-settings').click();
	await expect(page.locator('#save-feed-settings')).toBeDisabled(); await expect(page.locator('#feed-settings-progress')).toBeVisible();
	await expect(page.locator('#feed-setting-ttl')).toBeDisabled(); await expect(page.locator('[data-change-feed-field=ttl]')).toBeDisabled();
	await page.locator('#cancel-feed-settings').click();
	await page.locator('#cancel-settings').click();
	await page.locator('.entry-card').first().click(); await expect(page.locator('#article-frame')).toBeVisible();
	await openSubscriptions(page);
	await page.locator('#clear-feed-selection').click(); await page.locator('#settings-feed-list [data-select-feed="6"]').check();
	release();
	await expect.poll(() => state.requests.length).toBe(2);
	expect(state.requests.map(request => request.ids)).toEqual([[1, 2], [3]]);
	for (const request of state.requests) expect(request.changes).toEqual({ ttl: 0 });
	await expect(page.locator('#settings-feed-list .feed-select:checked')).toHaveCount(1);
	expect(state.feeds[0].name).toBe('Feed 1'); expect(state.feeds[0].enabled).toBe(true);
	expect(state.feeds[0].ttl).toBe(0); expect(state.feeds[5].ttl).toBe(3600);
});

test('failed items can be reviewed and retried without resubmitting successful subscriptions', async ({ page }) => {
	const state = await fixture(page);
	await page.locator('#settings-feed-list [data-select-feed="1"]').check();
	await page.locator('#settings-feed-list [data-select-feed="3"]').click({ modifiers: ['Shift'] });
	await page.locator('#edit-selected-feeds').click();
	await page.locator('#feed-setting-enabled').selectOption('false');
	state.beforeBatch = () => state.requests.length === 2 ? { status: 500, json: { version: 1, error: 'write_failed' } } : undefined;
	await page.locator('#save-feed-settings').click();
	await expect(page.locator('[data-feed-result=saved]')).toHaveCount(2);
	await expect(page.locator('[data-feed-result=failed]')).toHaveCount(1);
	await page.locator('#retry-feed-settings').click();
	await expect(page.locator('#save-feed-settings')).toBeEnabled();
	await expect(page.locator('#settings-feed-list .feed-select:checked')).toHaveCount(1);
	await expect(page.locator('#feed-setting-enabled')).toHaveValue('false');
	await page.locator('#save-feed-settings').click();
	await expect(page.locator('[data-feed-result=saved]')).toHaveCount(1);
	expect(state.requests.map(request => request.ids)).toEqual([[1, 2], [3], [3]]);
	await expect(page.locator('#save-feed-settings')).toBeDisabled();
	await expect(page.locator('#reset-feed-settings')).toBeHidden();
	await expect(page.locator('#feed-setting-enabled')).toBeEnabled();
});

test('keyboard range selection and stopping queued batches keep confirmed and unsubmitted results distinct', async ({ page }) => {
	const state = await fixture(page);
	await page.locator('#settings-feed-list [data-select-feed="1"]').focus(); await page.keyboard.press('Space');
	await page.keyboard.press('ArrowDown'); await page.keyboard.press('Shift+ArrowDown');
	await expect(page.locator('#settings-feed-list [data-select-feed="3"]')).toBeFocused();
	await expect(page.locator('#settings-feed-list .feed-select:checked')).toHaveCount(3);
	await page.keyboard.press('Control+a'); await expect(page.locator('#settings-feed-list .feed-select:checked')).toHaveCount(6);
	await page.locator('#edit-selected-feeds').click();
	await page.locator('#feed-setting-ttl').fill('60');
	let release;
	const gate = new Promise(resolve => { release = resolve; }); state.beforeBatch = () => gate;
	await page.locator('#save-feed-settings').click(); await page.locator('#stop-feed-settings').click();
	await page.locator('#cancel-feed-settings').click();
	await page.locator('#cancel-settings').click();
	await page.locator('.entry-card').first().click(); await expect(page.locator('#article-frame')).toBeVisible();
	release();
	await openSubscriptions(page);
	await page.locator('#show-feed-job').click();
	await expect(page.locator('[data-feed-result=saved]')).toHaveCount(2);
	await expect(page.locator('[data-feed-result=skipped]')).toHaveCount(3);
	await page.locator('[data-result-page="1"]').click();
	await expect(page.locator('[data-feed-result=skipped]')).toHaveCount(1);
	await expect(page.locator('#save-feed-settings')).toBeDisabled();
	expect(state.requests).toHaveLength(1);
	expect(state.feeds.slice(2).every(feed => feed.ttl === 3600)).toBe(true);
});

test('real subscription versions prevent stale UI saves and explicit review retries only the unsaved batch', async ({ page, context, baseURL }) => {
	test.skip(process.env.FRESHRSS_TEST_USER !== 'reader' || !process.env.FRESHRSS_TEST_PASSWORD, 'Requires the isolated reader test account.');
	test.skip(!['127.0.0.1', 'localhost', '[::1]'].includes(new URL(baseURL).hostname), 'Only mutate a local test server.');
	await page.goto('./?c=auth&a=login');
	await page.locator('[name=username]').fill('reader');
	await page.locator('input[type=password]').fill(process.env.FRESHRSS_TEST_PASSWORD);
	await Promise.all([page.waitForURL(url => url.searchParams.get('c') !== 'auth'), page.locator('button[type=submit]').click()]);
	await page.goto('./?c=reader');
	const bootstrap = await page.locator('#reader-bootstrap').evaluate(node => JSON.parse(node.textContent));
	const request = context.request;
	const original = (await (await request.get('./?c=reader&a=feeds')).json()).feeds.slice(0, 2);
	test.skip(original.length !== 2, 'Requires two sample subscriptions.');
	const other = await context.newPage();
	try {
		await openSubscriptions(page);
		for (const feed of original) await page.locator(`#settings-feed-list [data-select-feed="${feed.id}"]`).check();
		await page.locator('#edit-selected-feeds').click();
		await page.locator('#feed-setting-ttl').fill('60');
		await page.locator('#feed-setting-enabled').selectOption('false');
		await other.goto('./?c=reader');
		const concurrent = await other.request.post('./?c=reader&a=feedBatch', {
			data: {
				_csrf: bootstrap.csrf,
				ids: [original[0].id],
				changes: { ttl: original[0].ttl === 120 ? 180 : 120 },
				expectedVersions: { [original[0].id]: original[0].version },
			}
		});
		expect(concurrent.status()).toBe(200);
		const conflicted = page.waitForResponse(response => new URL(response.url()).searchParams.get('a') === 'feedBatch');
		await page.locator('#save-feed-settings').click(); expect((await conflicted).status()).toBe(409);
		await expect(page.locator('[data-feed-result=conflict]')).toHaveCount(1);
		await expect(page.locator('[data-feed-result=blocked]')).toHaveCount(1);
		const afterConflict = (await (await request.get('./?c=reader&a=feeds')).json()).feeds;
		expect(afterConflict.find(feed => feed.id === original[1].id).ttl).toBe(original[1].ttl);
		expect(afterConflict.find(feed => feed.id === original[1].id).enabled).toBe(original[1].enabled);
		await page.locator('#retry-feed-settings').click(); await expect(page.locator('#save-feed-settings')).toBeEnabled();
		await page.locator('#save-feed-settings').click(); await expect(page.locator('[data-feed-result=saved]')).toHaveCount(2);
		const after = (await (await request.get('./?c=reader&a=feeds')).json()).feeds;
		for (const previous of original) {
			const updated = after.find(feed => feed.id === previous.id);
			expect(updated.ttl).toBe(60); expect(updated.enabled).toBe(false);
			expect(updated.name).toBe(previous.name); expect(updated.priority).toBe(previous.priority);
			expect(updated.categoryId).toBe(previous.categoryId);
		}
	} finally {
		await other.close();
		for (const feed of original) {
			const current = (await (await request.get('./?c=reader&a=feeds')).json()).feeds.find(current => current.id === feed.id);
			const restored = await request.post('./?c=reader&a=feedBatch', {
				data: {
					_csrf: bootstrap.csrf,
					ids: [feed.id],
					changes: { ttl: feed.ttl, enabled: feed.enabled },
					expectedVersions: { [feed.id]: current.version },
				}
			});
			expect(restored.status()).toBe(200);
		}
	}
});
