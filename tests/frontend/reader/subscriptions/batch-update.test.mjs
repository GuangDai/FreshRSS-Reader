import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FeedSelection, FeedBatchUpdate, feedBatchRequests, feedChangeErrors } from '../../../../p/scripts/reader/subscriptions/batch-update.js';

const feeds = [1, 2, 3, 4].map(id => ({ id, name: 'Feed ' + id, version: 'version-' + id, ttl: 3600, enabled: true }));
const limits = { maxBatchItems: 2, maxRequestBytes: 1024 };

test('selection ranges follow filtered display order and retain selections outside a later filter', () => {
	const selection = new FeedSelection(); selection.activate(true);
	selection.set(3, true, [3, 1, 4, 2]);
	selection.set(4, true, [3, 1, 4, 2], true);
	assert.deepEqual([...selection.ids], [3, 1, 4]);
	selection.selectVisible([2]);
	assert.equal(selection.ids.size, 4);
	selection.reconcile(feeds.filter(feed => feed.id !== 1));
	assert.deepEqual([...selection.ids], [3, 4, 2]);
	selection.activate(false); assert.equal(selection.ids.size, 0); assert.equal(selection.anchor, null);
});

test('batch bodies snapshot only explicitly changed fields and obey encoded request bytes', () => {
	const changes = { enabled: false, name: '中文订阅' };
	const ids = [1, 2, 3];
	const requests = feedBatchRequests(ids, feeds, changes, limits, 'csrf');
	ids.length = 0; changes.enabled = true;
	assert.deepEqual(requests.map(request => request.ids), [[1, 2], [3]]);
	assert.deepEqual(requests[0].changes, { enabled: false, name: '中文订阅' });
	assert.deepEqual(requests[0].expectedVersions, { 1: 'version-1', 2: 'version-2' });
	assert.equal(Object.hasOwn(requests[0].changes, 'ttl'), false);
	const single = feedBatchRequests([1], feeds, changes, limits, 'csrf')[0];
	const bytes = new TextEncoder().encode(JSON.stringify({ ...single, _csrf: 'csrf' })).length;
	const byBytes = feedBatchRequests([1, 2], feeds, changes, { maxBatchItems: 10, maxRequestBytes: bytes }, 'csrf');
	assert.equal(byBytes.length, 2);
	for (const request of byBytes) assert.ok(new TextEncoder().encode(JSON.stringify({ ...request, _csrf: 'csrf' })).length <= bytes);
	assert.throws(() => feedBatchRequests([1], feeds, changes, { ...limits, maxRequestBytes: bytes - 1 }, 'csrf'), /request_too_large/);
});

test('server field schema rejects empty numeric drafts and preserves explicit zero and false', () => {
	const fields = { ttl: { type: 'integer', min: 0, max: 5000 }, enabled: { type: 'boolean' }, priority: { type: 'integer', options: [0, 10] } };
	assert.deepEqual(feedChangeErrors({ ttl: 0, enabled: false }, fields), []);
	assert.deepEqual(feedChangeErrors({ ttl: NaN, priority: 20, surprise: true }, fields), ['ttl', 'priority', 'surprise']);
	const name = { name: { type: 'string', maxLength: 6, unit: 'html-encoded-bytes' } };
	assert.deepEqual(feedChangeErrors({ name: '中文' }, name), []);
	assert.deepEqual(feedChangeErrors({ name: '中文名' }, name), ['name']);
	assert.deepEqual(feedChangeErrors({ name: '&&' }, name), ['name']);
});

test('clearing or changing selection after submit cannot alter pending batch IDs or versions', async () => {
	const selection = new FeedSelection(); selection.selectVisible([1, 2, 3]);
	const calls = []; let finish;
	const api = {
		csrf: 'csrf',
		request(action, params, options) {
			calls.push(options.body);
			if (calls.length === 1) return new Promise(resolve => { finish = resolve; });
			return Promise.resolve({ feeds: [] });
		}
	};
	const store = { feeds, sessionEpoch: 0, mergeFeedSettings() {} };
	const batch = new FeedBatchUpdate(api, store, limits);
	const pending = batch.run([...selection.ids], { enabled: false });
	selection.clear(); selection.selectVisible([4]);
	finish({ feeds: [] }); await pending;
	assert.deepEqual(calls.map(body => body.ids), [[1, 2], [3]]);
	assert.equal(batch.results.filter(result => result.status === 'saved').length, 3);
	assert.equal(batch.running, false);
});

test('conflicts mark every member of the atomic batch unsaved and keep current server versions for review', async () => {
	const conflict = { status: 409, details: { conflicts: [{ type: 'feed', id: 1, current: { ...feeds[0], version: 'new-version' } }] } };
	const updates = [];
	const store = { feeds, sessionEpoch: 0, mergeFeedSettings(values) { updates.push(...values); } };
	const batch = new FeedBatchUpdate({ request: async () => { throw conflict; } }, store, limits);
	await batch.run([1, 2], { ttl: 60 });
	assert.deepEqual(batch.results.map(result => [result.id, result.status]), [[1, 'conflict'], [2, 'blocked']]);
	assert.deepEqual(batch.failedIds(), [1, 2]); assert.equal(updates[0].version, 'new-version');
});

test('stopping a batch waits for the submitted request and leaves remaining IDs available for retry', async () => {
	let finish; let calls = 0;
	const api = { request() { calls++; return new Promise(resolve => { finish = resolve; }); } };
	const store = { feeds, sessionEpoch: 0, mergeFeedSettings() {} };
	const batch = new FeedBatchUpdate(api, store, { ...limits, maxBatchItems: 1 });
	const pending = batch.run([1, 2, 3], { enabled: false });
	batch.stop();
	assert.equal(batch.running, true);
	finish({ feeds: [{ ...feeds[0], enabled: false }] }); await pending;
	assert.equal(calls, 1);
	assert.deepEqual(batch.results.map(result => result.status), ['saved', 'skipped', 'skipped']);
	assert.deepEqual(batch.failedIds(), [2, 3]);
});

test('a busy response stops queued requests instead of hammering the same account lock', async () => {
	let calls = 0;
	const error = new Error('processing_busy'); error.status = 503;
	const api = { async request() { calls++; throw error; } };
	const store = { feeds, sessionEpoch: 0, mergeFeedSettings() {} };
	const batch = new FeedBatchUpdate(api, store, { ...limits, maxBatchItems: 1 });
	await batch.run([1, 2, 3], { enabled: false });
	assert.equal(calls, 1);
	assert.deepEqual(batch.results.map(result => result.status), ['failed', 'skipped', 'skipped']);
});

test('disposing a batch records uncertain and unsent targets and ignores its late response', async () => {
	let finish; let calls = 0;
	const api = { request() { calls++; return new Promise(resolve => { finish = resolve; }); } };
	let merges = 0;
	const store = { feeds, sessionEpoch: 0, mergeFeedSettings() { merges++; } };
	const batch = new FeedBatchUpdate(api, store, { ...limits, maxBatchItems: 1 });
	const pending = batch.run([1, 2, 3], { enabled: false });
	batch.dispose();
	assert.equal(batch.running, false);
	assert.deepEqual(batch.results.map(result => result.status), ['failed', 'skipped', 'skipped']);
	finish({ feeds: [{ ...feeds[0], enabled: false }] }); await pending;
	assert.equal(calls, 1); assert.equal(merges, 0);
	batch.dispose(true); assert.deepEqual(batch.results, []); assert.equal(batch.progress, null);
});
