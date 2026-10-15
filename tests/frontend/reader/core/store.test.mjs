import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ArticleCache, ReaderStore } from '../../../../p/scripts/reader/core/store.js';
import { defaults, preferenceErrors, preferences } from '../../../../p/scripts/reader/core/preferences.js';

function deferred() {
	let resolve, reject;
	const promise = new Promise((_resolve, _reject) => { resolve = _resolve; reject = _reject; });
	return { promise, resolve, reject };
}
function fixture() {
	const requests = [];
	const api = { request(action, params, options) { const wait = deferred(); requests.push({ action, params, options, ...wait }); return wait.promise; } };
	return { store: new ReaderStore(api, { pageSize: 2, cacheArticles: 2, cacheBytes: 1024 }), requests };
}
const item = (id, extra = {}) => ({ id, feedId: 1, title: id, read: false, starred: false, ...extra });

test('article cache evicts the least recently used item and respects both visible budgets', () => {
	const cache = new ArticleCache(2, 1024);
	cache.set(item('1')); cache.set(item('2')); cache.get('1'); cache.set(item('3'));
	assert.equal(cache.get('2'), null);
	assert.equal(cache.entries.size, 2);
	cache.set(item('large', { content: 'x'.repeat(1000) }));
	assert.equal(cache.get('large'), null);
	assert.ok(cache.bytes <= 1024);
	cache.configure(0, 1024);
	assert.equal(cache.entries.size, 0);
	cache.set(item('4'));
	assert.equal(cache.entries.size, 0);
	cache.configure(5, 0);
	cache.set(item('4'));
	assert.equal(cache.bytes, 0);
});

test('a late response cannot replace a newer stream even if the transport ignores cancellation', async () => {
	const { store, requests } = fixture();
	const older = store.list({ get: 'f_1' });
	const newer = store.list({ get: 'f_2' });
	assert.equal(requests[0].options.signal.aborted, true);
	requests[1].resolve({ items: [item('new')], next: null });
	await newer;
	requests[0].resolve({ items: [item('old')], next: null });
	await older;
	assert.equal(store.items[0].id, 'new');
	assert.equal(store.loading, false);
});

test('article selection discards old content and repeated opens reuse the bounded cache', async () => {
	const { store, requests } = fixture();
	const first = store.open('1');
	const second = store.open('2');
	requests[1].resolve({ article: item('2', { content: 'second' }) }); await second;
	requests[0].resolve({ article: item('1', { content: 'first' }) }); await first;
	assert.equal(store.article.id, '2');
	await store.open('2');
	assert.equal(requests.length, 2);
});

test('page navigation replaces the list and a failed next page preserves a usable cursor', async () => {
	const { store, requests } = fixture();
	const first = store.list({}); requests[0].resolve({ items: [item('4'), item('3')], next: '3' }); await first;
	assert.equal(requests[0].params.limit, '2');
	const failed = store.nextPage(); requests[1].reject(new Error('network')); await failed;
	assert.equal(store.cursor, ''); assert.deepEqual(store.history, []); assert.equal(store.items.length, 2);
	const next = store.nextPage(); requests[2].resolve({ items: [item('2'), item('1')], next: null }); await next;
	assert.equal(store.items.length, 2); assert.deepEqual(store.history, ['']);
	const previous = store.previousPage(); requests[3].resolve({ items: [item('4'), item('3')], next: '3' }); await previous;
	assert.equal(store.items[0].id, '4'); assert.equal(store.history.length, 0);
});

test('optimistic state rolls back on failure, prevents duplicate writes, and can retry', async () => {
	const { store, requests } = fixture();
	store.items = [item('1')]; store.feeds = [{ id: 1, unread: 1 }];
	const pending = store.changeState('1', 'read', true);
	assert.equal(store.items[0].read, true); assert.equal(store.feeds[0].unread, 0);
	await store.changeState('1', 'read', false);
	assert.equal(requests.length, 1);
	requests[0].reject(new Error('network')); await pending;
	assert.equal(store.items[0].read, false); assert.equal(store.feeds[0].unread, 1); assert.equal(store.pending.size, 0);
	const retry = store.retry();
	requests[1].resolve({ read: true, starred: false }); await retry;
	assert.equal(store.items[0].read, true);
});

test('late article body preserves a state update completed while it was loading', async () => {
	const { store, requests } = fixture();
	store.items = [item('1')];
	const article = store.open('1');
	const change = store.changeState('1', 'starred', true);
	requests[1].resolve({ read: false, starred: true }); await change;
	requests[0].resolve({ article: item('1', { content: 'body', starred: false }) }); await article;
	assert.equal(store.article.starred, true);
});

test('authentication failure clears account data and disposal aborts reads', async () => {
	const { store, requests } = fixture();
	store.cache.set(item('private')); store.items = [item('private')];
	const request = store.open('1');
	const error = new Error('expired'); error.status = 403;
	requests[0].reject(error); await request;
	assert.equal(store.cache.entries.size, 0); assert.equal(store.items.length, 0); assert.equal(store.article, null);
	store.list({}); store.dispose();
	assert.equal(requests[1].options.signal.aborted, true);
});

test('settings validate against the server schema, including changed administrator limits', () => {
	const schema = {
		pageSize: { type: 'integer', min: 1, max: 75, default: 25 },
		theme: { type: 'enum', options: ['light', 'dark'], default: 'light' },
		cache: { type: 'boolean', default: false }
	};
	assert.deepEqual(defaults(schema), { pageSize: 25, theme: 'light', cache: false });
	assert.deepEqual(preferenceErrors({ pageSize: 76, theme: 'url(evil)', surprise: true }, schema), ['pageSize', 'theme', 'surprise']);
	assert.deepEqual(preferences({ pageSize: 50, cache: true }, schema), { pageSize: 50, theme: 'light', cache: true });
	assert.deepEqual(preferenceErrors({ pageSize: '50', cache: 1 }, schema), ['pageSize', 'cache']);
});

test('a feed response arriving after authentication failure cannot restore account data', async () => {
	const { store, requests } = fixture();
	const request = store.feedsRefresh();
	const error = new Error('authentication_required'); error.status = 403;
	store.reportError(error);
	requests[0].resolve({ feeds: [{ id: 1, name: 'private' }], categories: [] });
	await request;
	assert.deepEqual(store.feeds, []);
	assert.equal(store.error.status, 403);
});

test('retrying failed pagination advances and retreats the cursor history', async () => {
	const { store, requests } = fixture();
	store.items = [item('4')]; store.next = '4';
	const failed = store.nextPage(); requests[0].reject(new Error('offline')); await failed;
	const retry = store.retry(); requests[1].resolve({ items: [item('3')], next: '3' }); await retry;
	assert.equal(store.cursor, '4'); assert.deepEqual(store.history, ['']);
	const back = store.previousPage(); requests[2].reject(new Error('offline')); await back;
	const retryBack = store.retry(); requests[3].resolve({ items: [item('4')], next: '4' }); await retryBack;
	assert.equal(store.cursor, ''); assert.deepEqual(store.history, []);
});

for (const stateCompletesFirst of [true, false]) {
	test(`a stale list preserves an overlapping state write (write completes first: ${stateCompletesFirst})`, async () => {
		const { store, requests } = fixture();
		store.items = [item('1')];
		const list = store.list();
		const write = store.changeState('1', 'read', true);
		if (stateCompletesFirst) { requests[1].resolve({ read: true, starred: false }); await write; }
		requests[0].resolve({ items: [item('1')], next: null }); await list;
		assert.equal(store.items[0].read, true);
		if (!stateCompletesFirst) { requests[1].reject(new Error('offline')); await write; assert.equal(store.items[0].read, false); }
		assert.equal(store.stateOverrides.size, 0);
	});
}

test('failed navigation to a new query resets the old stream cursor', async () => {
	const { store, requests } = fixture();
	store.cursor = '123'; store.next = '122'; store.history = [''];
	const list = store.list({ search: 'new' });
	requests[0].reject(new Error('offline')); await list;
	assert.equal(store.cursor, ''); assert.equal(store.next, null); assert.deepEqual(store.history, []);
});

test('late writes after authentication failure cannot restore state or replace the authentication error', async () => {
	const { store, requests } = fixture();
	store.items = [item('1')];
	const write = store.changeState('1', 'read', true);
	const error = new Error('authentication_required'); error.status = 403;
	store.reportError(error);
	requests[0].reject(new Error('old network failure')); await write;
	assert.equal(store.error, error); assert.deepEqual(store.items, []); assert.equal(store.stateOverrides.size, 0);
});

test('batch changes follow advertised limits, report progress and reload authoritative state', async () => {
	const { store, requests } = fixture();
	store.items = [item('1'), item('2'), item('3')];
	const batch = store.batchState(['1', '2', '3', '1'], 'read', true, { maxBatchItems: 2, maxRequestBytes: 1024 });
	assert.deepEqual(requests[0].options.body.ids, ['1', '2']);
	assert.equal(store.pending.size, 3);
	requests[0].resolve({ ids: ['1', '2'] }); await Promise.resolve();
	assert.deepEqual(store.batchProgress, { completed: 2, total: 3 });
	assert.deepEqual(requests[1].options.body.ids, ['3']);
	requests[1].resolve({ ids: ['3'] }); await Promise.resolve();
	requests.find(request => request.action === 'items').resolve({ items: [item('1', { read: true })], next: null });
	requests.find(request => request.action === 'feeds').resolve({ feeds: [{ id: 1, unread: 0 }], categories: [] });
	await batch;
	assert.equal(store.batchProgress, null); assert.equal(store.pending.size, 0); assert.equal(store.items[0].read, true);
});

test('partial batch failure is reconciled from the server and retains a retry for the same IDs', async () => {
	const { store, requests } = fixture();
	store.items = [item('1'), item('2')];
	const batch = store.batchState(['1', '2'], 'read', true, { maxBatchItems: 2, maxRequestBytes: 1024 });
	const failure = new Error('hook_failed'); requests[0].reject(failure); await Promise.resolve();
	requests.find(request => request.action === 'items').resolve({ items: [item('1', { read: true }), item('2')], next: null });
	requests.find(request => request.action === 'feeds').resolve({ feeds: [], categories: [] });
	await batch;
	assert.equal(store.items[0].read, true); assert.equal(store.items[1].read, false);
	assert.equal(store.error, failure); assert.equal(typeof store.retry, 'function'); assert.equal(store.pending.size, 0);
});

test('a page requested during a partial batch is re-read without losing navigation history', async () => {
	const { store, requests } = fixture();
	store.items = [item('3'), item('2')]; store.next = '2';
	const batch = store.batchState(['3', '2'], 'read', true, { maxBatchItems: 2, maxRequestBytes: 1024 });
	const next = store.nextPage();
	requests[0].reject(new Error('partial_failure')); await Promise.resolve();
	requests.find(request => request.action === 'feeds').resolve({ feeds: [], categories: [] });
	await batch;
	requests[1].resolve({ items: [item('1')], next: null }); await Promise.resolve();
	assert.equal(requests.at(-1).action, 'items'); assert.equal(requests.at(-1).params.cursor, '2');
	requests.at(-1).resolve({ items: [item('1', { read: true })], next: null }); await next;
	assert.equal(store.items[0].read, true); assert.deepEqual(store.history, ['']);
});

test('a count request overlapping a batch is refreshed before exposing stale unread counts', async () => {
	const { store, requests } = fixture();
	store.items = [item('1')];
	const feeds = store.feedsRefresh();
	const batch = store.batchState(['1'], 'read', true, { maxBatchItems: 2, maxRequestBytes: 1024 });
	requests[1].resolve({ ids: ['1'] }); await Promise.resolve();
	requests[0].resolve({ feeds: [{ id: 1, unread: 1 }], categories: [] }); await Promise.resolve();
	assert.equal(requests.at(-1).action, 'feeds');
	requests.at(-1).resolve({ feeds: [{ id: 1, unread: 0 }], categories: [] });
	requests.find(request => request.action === 'items').resolve({ items: [item('1', { read: true })], next: null });
	await Promise.all([feeds, batch]);
	assert.equal(store.feeds[0].unread, 0);
});

test('a stale subscription poll cannot replace versions returned by a successful settings save', async () => {
	const { store, requests } = fixture();
	store.feeds = [{ id: 1, ttl: 60, version: 'old' }];
	const poll = store.feedsRefresh();
	store.mergeFeedSettings([{ id: 1, ttl: 120, version: 'new' }]);
	requests[0].resolve({ feeds: [{ id: 1, ttl: 60, version: 'old' }], categories: [] }); await Promise.resolve();
	assert.equal(store.feeds[0].version, 'new');
	assert.equal(requests.length, 2);
	requests[1].resolve({ feeds: [{ id: 1, ttl: 120, version: 'new' }], categories: [] }); await poll;
	assert.equal(store.feeds[0].ttl, 120);
});

test('disabled caching does not serialize content and a long session stays within both budgets', () => {
	const disabled = new ArticleCache(0, 100);
	disabled.set({ toJSON() { throw new Error('must not serialize'); } });
	const cache = new ArticleCache(4, 1500);
	for (let index = 0; index < 1000; index++) {
		cache.set(item(String(index), { content: 'x'.repeat(index % 400) }));
		assert.ok(cache.entries.size <= 4);
		assert.ok(cache.bytes <= 1500);
	}
	cache.clear(); assert.equal(cache.bytes, 0); assert.equal(cache.entries.size, 0);
});

test('disposal releases pending bodies and old completions cannot clear resumed requests', async () => {
	const { store, requests } = fixture();
	store.items = [item('1')];
	const oldFeed = store.feedsRefresh();
	const oldArticle = store.open('1');
	const oldWrite = store.changeState('1', 'read', true);
	store.dispose();
	assert.equal(store.loadingArticle, false);
	assert.equal(store.pending.size, 0);
	assert.equal(store.writeRequests.size, 0);
	assert.equal(store.cache.bytes, 0);
	assert.equal(store.article, null);
	for (const request of requests) assert.equal(request.options.signal.aborted, true);
	const newFeed = store.feedsRefresh();
	const newWrite = store.changeState('1', 'starred', true);
	requests[0].resolve({ feeds: [{ id: 99 }], categories: [] });
	requests[1].resolve({ article: item('old') });
	requests[2].resolve({ read: true, starred: false });
	await Promise.all([oldFeed, oldArticle, oldWrite]);
	assert.ok(store.feedPromise);
	assert.equal(store.pending.has('1'), true);
	assert.equal(store.article, null);
	assert.deepEqual(store.feeds, []);
	const sameFeed = store.feedsRefresh();
	assert.equal(requests.length, 5);
	requests[3].resolve({ feeds: [{ id: 1 }], categories: [] });
	requests[4].resolve({ read: true, starred: true });
	await Promise.all([newFeed, sameFeed, newWrite]);
	assert.equal(store.feedPromise, null);
	assert.equal(store.pending.size, 0);
});

test('a stalled body retains only its own overlapping state, and closing clears article retry', async () => {
	const { store, requests } = fixture();
	store.items = [item('1'), item('2')];
	const body = store.open('1');
	for (let index = 0; index < 20; index++) {
		const write = store.changeState('2', 'starred', index % 2 === 0);
		requests.at(-1).resolve({ read: false, starred: index % 2 === 0 }); await write;
		assert.equal(store.stateOverrides.size, 0);
	}
	requests[0].reject(new Error('offline')); await body;
	assert.equal(store.retryKind, 'article');
	store.closeArticle();
	assert.equal(store.retry, null); assert.equal(store.error, null);
});

test('CSRF expiry preserves account data but prevents further state writes', async () => {
	const { store, requests } = fixture();
	store.items = [item('1')]; store.feeds = [{ id: 1 }]; store.article = item('1');
	store.reportError(Object.assign(new Error('csrf_invalid'), { status: 403 }));
	await store.changeState('1', 'read', true);
	await store.batchState(['1'], 'read', true, { maxBatchItems: 2, maxRequestBytes: 1000 });
	assert.equal(requests.length, 0);
	assert.equal(store.items.length, 1); assert.equal(store.feeds.length, 1); assert.equal(store.article.id, '1');
});

test('an inherited reading state survives paging and searching until the user chooses a filter', async () => {
	const { store, requests } = fixture();
	const inherited = store.list({ get: 'T', filter: 'all', search: '', state: 96 });
	assert.equal(requests[0].params.state, 96);
	requests[0].resolve({ items: [item('4')], next: '4' }); await inherited;
	const next = store.nextPage();
	assert.equal(requests[1].params.state, 96);
	requests[1].resolve({ items: [], next: null }); await next;
	const search = store.list({ search: 'RSS' });
	assert.equal(requests[2].params.state, 96);
	requests[2].resolve({ items: [], next: null }); await search;
	const filter = store.list({ filter: 'unread' });
	assert.equal(Object.hasOwn(requests[3].params, 'state'), false);
	assert.equal(requests[3].params.filter, 'unread');
	requests[3].resolve({ items: [], next: null }); await filter;
	assert.deepEqual(store.query, { get: 'T', filter: 'unread', search: 'RSS' });
});

test('continuous loading coalesces requests, appends without replacing the reading trail, and retains the origin cursor', async () => {
	const { store, requests } = fixture();
	const list = store.list({ filter: 'unread', get: 'c_12' });
	requests[0].resolve({ items: [item('6'), item('5')], next: '5' }); await list;
	store.selectedId = '5';
	const first = store.items[0];
	const more = store.loadMore({ background: true });
	assert.equal(store.loadMore(), more);
	assert.equal(store.loading, false); assert.equal(store.loadingMore, true);
	assert.deepEqual(requests[1].params, { get: 'c_12', filter: 'unread', search: '', cursor: '5', limit: '2' });
	requests[1].resolve({ items: [item('5'), item('4')], next: '4' }); await more;
	assert.deepEqual(store.items.map(item => item.id), ['6', '5', '4']);
	assert.equal(store.items[0], first); assert.equal(store.selectedId, '5'); assert.equal(store.cursor, '');
	const end = store.loadMore(); requests[2].resolve({ items: [item('3')], next: '5' }); await end;
	assert.equal(store.next, null); assert.equal(store.loadingMore, false);
});

test('a failed speculative continuation preserves the list and retries the same cursor only on demand', async () => {
	const { store, requests } = fixture();
	store.items = [item('3')]; store.next = '3'; store.selectedId = '3';
	const more = store.loadMore({ background: true });
	requests[0].reject(new Error('offline')); await more;
	assert.equal(store.error, null); assert.equal(store.moreError.message, 'offline');
	assert.equal(store.next, '3'); assert.equal(store.items.length, 1); assert.equal(store.selectedId, '3');
	const retry = store.loadMore(); requests[1].resolve({ items: [item('2')], next: null }); await retry;
	assert.equal(store.moreError, null); assert.equal(store.items.length, 2);
});

test('a delayed continuation cannot append to a newly selected scope even when abort is ignored', async () => {
	const { store, requests } = fixture();
	store.items = [item('old')]; store.next = 'old';
	const more = store.loadMore();
	const scope = store.list({ get: 'f_2', filter: 'unread' });
	assert.equal(requests[0].options.signal.aborted, true);
	requests[1].resolve({ items: [item('new')], next: null }); await scope;
	requests[0].resolve({ items: [item('stale')], next: 'stale' }); await more;
	assert.deepEqual(store.items.map(item => item.id), ['new']); assert.equal(store.next, null);
});

test('opening an in-flight preload reuses it and cancellation cannot cache abandoned bodies', async () => {
	const { store, requests } = fixture(); store.cache.configure(4, 4096);
	store.items = [item('4'), item('3'), item('2'), item('1')]; store.selectedId = '4';
	store.prefetch(['3', '2', '1']);
	assert.equal(requests.length, 2); assert.equal(store.preloads.size, 2);
	const opening = store.open('3');
	store.prefetch([]);
	assert.equal(requests.length, 2); assert.equal(requests[0].options.signal.aborted, false);
	assert.equal(requests[1].options.signal.aborted, true);
	requests[0].resolve({ article: item('3', { content: 'preloaded body' }) }); await opening;
	requests[1].resolve({ article: item('2', { content: 'abandoned body' }) });
	await new Promise(resolve => setImmediate(resolve));
	assert.equal(store.article.content, 'preloaded body'); assert.equal(store.cache.get('2'), null);
	assert.ok(requests.every(request => request.action === 'article'));
});

test('preloaded bodies retain a state write that finished before the body response', async () => {
	const { store, requests } = fixture();
	store.items = [item('1'), item('2')]; store.selectedId = '1';
	store.prefetch(['2']);
	const write = store.changeState('2', 'starred', true);
	requests[1].resolve({ read: false, starred: true }); await write;
	requests[0].resolve({ article: item('2', { content: 'body', starred: false }) });
	await new Promise(resolve => setImmediate(resolve));
	await store.open('2');
	assert.equal(store.article.starred, true); assert.equal(requests.length, 2); assert.equal(store.stateOverrides.size, 0);
});

test('disabled caching prevents all speculative body requests and failed speculation leaves reading usable', async () => {
	const { store, requests } = fixture();
	store.cache.configure(0, 0); store.prefetch(['1', '2']); assert.equal(requests.length, 0);
	store.cache.configure(4, 4096); store.prefetch(['1', '2']);
	requests[0].reject(new Error('unavailable')); requests[1].resolve({ article: item('2') });
	await new Promise(resolve => setImmediate(resolve));
	store.prefetch(['1', '2']);
	assert.equal(requests.length, 2); assert.equal(store.error, null);
	const opening = store.open('1'); requests[2].resolve({ article: item('1') }); await opening;
	assert.equal(store.article.id, '1'); assert.equal(store.error, null);
});

test('disposing cancels a continuation and every preload, including transports ignoring cancellation', async () => {
	const { store, requests } = fixture();
	store.items = [item('2')]; store.next = '2'; store.prefetch(['1']);
	const more = store.loadMore(); store.dispose();
	assert.ok(requests.every(request => request.options.signal.aborted));
	requests[0].resolve({ article: item('1') }); requests[1].resolve({ items: [item('1')], next: null });
	await more; await new Promise(resolve => setImmediate(resolve));
	assert.equal(store.cache.bytes, 0); assert.equal(store.items.length, 1); assert.equal(store.preloads.size, 0);
});

for (const responseBeforeWrite of [true, false]) {
	test(`a delayed unread-count poll cannot undo a single read (poll arrives before write: ${responseBeforeWrite})`, async () => {
		const { store, requests } = fixture();
		store.items = [item('1')]; store.feeds = [{ id: 1, unread: 1 }];
		const poll = store.feedsRefresh();
		const write = store.changeState('1', 'read', true);
		if (responseBeforeWrite) {
			requests[0].resolve({ feeds: [{ id: 1, unread: 1 }], categories: [] });
			await new Promise(resolve => setImmediate(resolve));
			assert.equal(store.feeds[0].unread, 0);
		}
		requests[1].resolve({ read: true, starred: false }); await write;
		if (!responseBeforeWrite) requests[0].resolve({ feeds: [{ id: 1, unread: 1 }], categories: [] });
		await new Promise(resolve => setImmediate(resolve));
		assert.equal(requests.length, 3);
		requests[2].resolve({ feeds: [{ id: 1, unread: 0 }], categories: [] }); await poll;
		assert.equal(store.feeds[0].unread, 0);
	});
}
