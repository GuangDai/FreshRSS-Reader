import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../../p/scripts/main.js', import.meta.url), 'utf8');
function createReader(network = {}) {
	const timers = new Map();
	let timer = 0;
	const entries = new Map();
	const requests = [];
	const events = [];
	const listeners = new Map();
	const addEventListener = (name, fn) => listeners.set(name, fn);
	const sandbox = {
		AbortController,
		console: { error() {} },
		context: {
			icons: { read: 'read', unread: 'unread' },
			csrf: 'test',
			network: {
				request_timeout: 30,
				slider_timeout: 15,
				unread_timeout: 15,
				unread_interval: 120,
				unread_backoff_max: 900,
				unread_jitter: 10,
				unread_resume_min_interval: 15,
				...network,
			},
		},
		document: {
			visibilityState: 'visible',
			getElementById: id => entries.get(id),
			querySelector: () => null,
			dispatchEvent: event => events.push(event),
			addEventListener,
		},
		window: { addEventListener },
		navigator: { onLine: true },
		CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
		setTimeout(fn, delay) { timers.set(++timer, { fn, delay }); return timer; },
		clearTimeout(id) { timers.delete(id); },
		badAjax() { sandbox.failures++; },
		failures: 0,
		faviconNbUnread() {},
		toggle_bigMarkAsRead_button() {},
		onScroll() {},
		incUnreadsTag() {},
		notifs_html5_show() {},
		xmlHttpRequestJson: req => req.response,
		XMLHttpRequest: class {
			constructor() { requests.push(this); }
			open() {}
			send() {}
		},
	};
	vm.createContext(sandbox);
	vm.runInContext(source.slice(source.indexOf('const pending_entries'), source.indexOf('function mark_previous_read')), sandbox);
	vm.runInContext(source.slice(source.indexOf('let unreadRefreshRequest'), source.indexOf('function toggle_bigMarkAsRead_button')), sandbox);
	return { sandbox, entries, timers, requests, events, listeners, evaluate: code => vm.runInContext(code, sandbox) };
}
function entry() {
	const classes = new Set(['not_read']);
	const icon = { outerHTML: 'spinner' };
	return {
		id: 'flux_1',
		icon,
		classList: { contains: name => classes.has(name), add: name => classes.add(name), remove: name => classes.delete(name) },
		querySelectorAll: selector => selector.endsWith('.icon') ? [icon] : [],
		querySelector: () => null,
	};
}

for (const failure of ['network', 'http', 'invalid-json', 'null-json']) {
	test(`failed read (${failure}) releases pending state and restores the action`, async () => {
		const r = createReader();
		const article = entry();
		r.entries.set(article.id, article);
		r.evaluate("pending_entries.flux_1 = true");
		r.sandbox.fetch = async () => {
			if (failure === 'network') throw new Error('offline');
			return {
				ok: failure !== 'http',
				status: 500,
				json: async () => {
					if (failure === 'invalid-json') throw new Error('bad JSON');
					return null;
				}
			};
		};
		await r.sandbox.send_mark_read_queue(['1'], true);
		assert.equal(r.evaluate('pending_entries.flux_1'), undefined);
		assert.equal(article.icon.outerHTML, 'unread');
		assert.equal(r.sandbox.failures, 1);
		assert.equal(r.timers.size, 0);
	});
}

test('read completion survives an article removed while awaiting the server', async () => {
	const r = createReader();
	r.evaluate('pending_entries.flux_1 = true');
	r.sandbox.fetch = async () => ({ ok: true, json: async () => ({ tags: {} }) });
	let completed = false;
	await r.sandbox.send_mark_read_queue(['1'], true, () => { completed = true; });
	assert.equal(r.evaluate('pending_entries.flux_1'), undefined);
	assert.equal(completed, true);
});

test('successful read applies the requested state and publishes the extension event', async () => {
	const r = createReader();
	const article = entry();
	r.entries.set(article.id, article);
	r.sandbox.fetch = async () => ({ ok: true, json: async () => ({ tags: {} }) });
	await r.sandbox.send_mark_read_queue(['1'], true);
	await r.sandbox.send_mark_read_queue(['1'], true);
	assert.equal(article.classList.contains('not_read'), false);
	assert.equal(r.events[0].type, 'freshrss:entryStateChange');
	assert.equal(r.events[0].detail.isRead, true);
});

test('a stalled read is aborted and can be retried', async () => {
	const r = createReader();
	r.evaluate('pending_entries.flux_1 = true');
	r.sandbox.fetch = (url, options) => new Promise((resolve, reject) => {
		options.signal.addEventListener('abort', () => reject(new Error('timeout')));
	});
	const pending = r.sandbox.send_mark_read_queue(['1'], true);
	const timeout = [...r.timers.values()][0];
	assert.equal(timeout.delay, 30000);
	timeout.fn();
	await pending;
	assert.equal(r.evaluate('pending_entries.flux_1'), undefined);
});

test('flushing a batch cancels its previous delayed timer', async () => {
	const r = createReader();
	r.evaluate('send_mark_read_queue_timeout = setTimeout(() => {}, 1000)');
	r.sandbox.send_mark_queue_tick();
	assert.equal(r.timers.size, 0);
});

test('hidden and offline readers do not issue background requests', () => {
	const r = createReader();
	r.sandbox.document.visibilityState = 'hidden';
	r.sandbox.refreshUnreads();
	r.sandbox.scheduleUnreadRefresh();
	assert.equal(r.requests.length, 0);
	assert.equal(r.timers.size, 0);
	r.sandbox.document.visibilityState = 'visible';
	r.sandbox.navigator.onLine = false;
	r.sandbox.refreshUnreads();
	assert.equal(r.requests.length, 0);
});

test('explicitly enabled desktop notifications still poll in a background tab', () => {
	const r = createReader();
	r.sandbox.document.visibilityState = 'hidden';
	r.sandbox.context.html5_enable_notif = true;
	r.sandbox.context.notifs_html5_permission = 'granted';
	r.sandbox.refreshUnreads();
	assert.equal(r.requests.length, 1);
});

test('unread polling has one in-flight request, a timeout, and capped failure backoff', () => {
	const r = createReader();
	r.sandbox.refreshUnreads();
	r.sandbox.refreshUnreads();
	assert.equal(r.requests.length, 1);
	assert.equal(r.requests[0].timeout, 15000);
	r.requests[0].onloadend();
	assert.equal(r.evaluate('unreadRefreshDelay'), 240000);
	for (let i = 0; i < 8; i++) {
		r.sandbox.refreshUnreads();
		r.requests.at(-1).onloadend();
	}
	assert.equal(r.evaluate('unreadRefreshDelay'), 900000);
	assert.equal(r.timers.size, 1);
});

test('successful unread polling restores the normal interval', () => {
	const r = createReader();
	r.evaluate('unreadRefreshDelay = 900000');
	r.sandbox.refreshUnreads();
	const request = r.requests[0];
	request.status = 200;
	request.response = { feeds: {}, tags: {} };
	request.onload();
	request.onloadend();
	assert.equal(r.evaluate('unreadRefreshDelay'), 120000);
	assert.equal(r.timers.size, 1);
});

for (const failure of ['network', 'timeout', 'http', 'invalid-document']) {
	test(`load more (${failure}) restores retry without deleting the current stream`, () => {
		const r = createReader();
		const classes = new Set();
		const button = { classList: { add: value => classes.add(value), remove: value => classes.delete(value) } };
		r.entries.set('load_more', button);
		r.sandbox.context.sort = 'rand';
		r.evaluate('let load_more = false; let url_load_more = "./next"; let box_load_more = {};');
		vm.runInContext(source.slice(source.indexOf('function load_more_posts()'), source.indexOf('const freshrssLoadMoreEvent')), r.sandbox);
		r.sandbox.load_more_posts();
		assert.equal(r.evaluate('load_more'), true);
		assert.equal(classes.has('loading'), true);
		const request = r.requests[0];
		assert.equal(request.timeout, 30000);
		if (failure === 'network') request.onerror();
		else if (failure === 'timeout') request.ontimeout();
		else {
			request.status = failure === 'http' ? 500 : 200;
			request.response = { getElementById: () => null };
			request.onload();
		}
		request.onloadend();
		assert.equal(r.evaluate('load_more'), false);
		assert.equal(classes.has('loading'), false);
		assert.equal(r.sandbox.failures, 1);
		r.sandbox.load_more_posts();
		assert.equal(r.requests.length, 2);
	});
}

for (const seconds of [0, 7]) {
	test(`read timeout follows the server setting (${seconds} seconds)`, async () => {
		const r = createReader({ request_timeout: seconds });
		r.sandbox.fetch = async () => ({ ok: true, json: async () => ({ tags: {} }) });
		const pending = r.sandbox.send_mark_read_queue(['1'], true);
		assert.equal(r.timers.size, seconds === 0 ? 0 : 1);
		if (seconds > 0) assert.equal([...r.timers.values()][0].delay, seconds * 1000);
		await pending;
	});
}

test('zero polling interval prevents timers, resume requests and background notification polling', () => {
	const r = createReader({ unread_interval: 0 });
	Object.assign(r.sandbox.context, { html5_enable_notif: true, notifs_html5_permission: 'granted' });
	for (const visibility of ['visible', 'hidden']) {
		r.sandbox.document.visibilityState = visibility;
		r.sandbox.init_unread_refresh(); r.sandbox.refreshUnreads();
		r.listeners.get('visibilitychange')(); r.listeners.get('online')();
	}
	assert.equal(r.requests.length, 0); assert.equal(r.timers.size, 0);
});

test('polling uses configurable timeout, jitter, base interval and capped backoff', () => {
	const r = createReader({ unread_timeout: 4, unread_interval: 3, unread_backoff_max: 10, unread_jitter: 2 });
	// A fixed random value verifies the server-provided jitter rather than a guessed range.
	r.evaluate('Math.random = () => 0.5');
	r.sandbox.scheduleUnreadRefresh();
	assert.equal([...r.timers.values()][0].delay, 4000);
	for (const expected of [6000, 10000, 10000]) {
		r.sandbox.refreshUnreads();
		assert.equal(r.requests.at(-1).timeout, 4000);
		r.requests.at(-1).onloadend();
		assert.equal(r.evaluate('unreadRefreshDelay'), expected);
	}
	r.sandbox.refreshUnreads();
	const request = r.requests.at(-1);
	request.status = 200; request.response = { feeds: {}, tags: {} };
	request.onload(); request.onloadend();
	assert.equal(r.evaluate('unreadRefreshDelay'), 3000);
});

for (const maximum of [0, 2]) {
	test(`backoff ${maximum} never shortens the base interval; zero timeout and jitter are disabled`, () => {
		const r = createReader({ unread_timeout: 0, unread_interval: 7, unread_backoff_max: maximum, unread_jitter: 0 });
		for (let i = 0; i < 3; i++) {
			r.sandbox.refreshUnreads();
			assert.equal(r.requests.at(-1).timeout, 0);
			r.requests.at(-1).onloadend();
			assert.equal([...r.timers.values()][0].delay, 7000);
		}
	});
}

test('visibility resume respects its own configured spacing independently of the request timeout', () => {
	const r = createReader({ unread_resume_min_interval: 2, unread_timeout: 0 });
	r.evaluate('Date.now = () => 1000');
	r.sandbox.init_unread_refresh();
	r.listeners.get('visibilitychange')();
	assert.equal(r.requests.length, 0);
	r.evaluate('Date.now = () => 2000');
	r.listeners.get('online')();
	assert.equal(r.requests.length, 1); assert.equal(r.requests[0].timeout, 0);
	const immediate = createReader({ unread_resume_min_interval: 0 });
	immediate.evaluate('Date.now = () => 0');
	immediate.sandbox.init_unread_refresh(); immediate.listeners.get('online')();
	assert.equal(immediate.requests.length, 1);
});

for (const seconds of [0, 8]) {
	test(`pagination timeout follows the server setting (${seconds} seconds)`, () => {
		const r = createReader({ request_timeout: seconds });
		r.entries.set('load_more', { classList: { add() {}, remove() {} } });
		r.evaluate('let load_more = false; let url_load_more = "./next"; let box_load_more = {};');
		vm.runInContext(source.slice(source.indexOf('function load_more_posts()'), source.indexOf('const freshrssLoadMoreEvent')), r.sandbox);
		r.sandbox.load_more_posts();
		assert.equal(r.requests[0].timeout, seconds * 1000);
	});
}
