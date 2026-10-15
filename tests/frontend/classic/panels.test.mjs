import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../../p/scripts/extra.js', import.meta.url), 'utf8');
function createSlider(timeout = 15) {
	const requests = [];
	const classes = new Set();
	const content = { innerHTML: '', setAttribute() {}, querySelectorAll: () => [] };
	const slider = {
		classList: { add: (...values) => values.forEach(value => classes.add(value)), remove: (...values) => values.forEach(value => classes.delete(value)) },
		dispatchEvent() {},
		setAttribute() {},
		removeAttribute() {},
		querySelector: () => null,
		querySelectorAll: () => [],
	};
	const sandbox = {
		Event,
		URL,
		context: { ajax_loading: true, network: { slider_timeout: timeout }, i18n: {} },
		location: { href: 'http://localhost/i/', hash: '' },
		document: { getElementById: id => id === 'slider' ? slider : content, documentElement: slider },
		XMLHttpRequest: class {
			constructor() { requests.push(this); }
			open(method, url) { this.url = url; }
			send() {}
			abort() { this.aborted = true; this.onabort?.(); }
		},
		data_leave_validation: () => true,
		data_auto_leave_validation() {},
		init_update_feed() {},
		confirm: () => false,
	};
	vm.createContext(sandbox);
	vm.runInContext(source.slice(source.indexOf('const freshrssSliderLoadEvent'), source.indexOf('// </slider>')), sandbox);
	sandbox.slider_status = (element, failed) => { content.innerHTML = failed ? 'Retry' : 'Loading'; };
	const open = href => sandbox.open_slider_listener({ target: { closest: () => ({ href }) }, preventDefault() {} });
	return { sandbox, requests, classes, content, slider, open };
}

for (const seconds of [0, 5]) {
	test(`slider keeps its configured ${seconds}s timeout and isolates loading from global network state`, () => {
		const r = createSlider(seconds);
		r.open('http://localhost/i/?c=subscription&id=1#old-hash');
		assert.equal(r.requests[0].timeout, seconds * 1000);
		assert.equal(r.sandbox.location.hash, 'slider');
		assert.equal(r.content.innerHTML, 'Loading');
		assert.equal(r.sandbox.context.ajax_loading, true);
		assert.equal(new URL(r.requests[0].url).searchParams.get('ajax'), '1');
		r.requests[0].onerror();
		assert.equal(r.content.innerHTML, 'Retry');
		assert.equal(r.classes.has('sliding'), false);
		assert.equal(r.sandbox.context.ajax_loading, true);
	});
}

test('switching targets aborts old requests and ignores late success and error callbacks', () => {
	const r = createSlider();
	r.open('http://localhost/i/?id=1');
	const old = r.requests[0];
	r.open('http://localhost/i/?id=2');
	assert.equal(old.aborted, true);
	old.status = 200; old.response = { body: { innerHTML: 'stale' } };
	old.onload(); old.onerror();
	assert.equal(r.content.innerHTML, 'Loading');
	const current = r.requests[1];
	current.status = 200; current.response = { body: { innerHTML: 'current' } };
	current.onload();
	assert.equal(r.content.innerHTML, 'current');
});

test('closing during loading aborts immediately and a late response cannot reopen the slider', () => {
	const r = createSlider();
	r.open('http://localhost/i/?id=1');
	const req = r.requests[0];
	r.sandbox.close_slider_listener();
	assert.equal(req.aborted, true);
	assert.equal(r.classes.has('active'), false);
	req.status = 200; req.response = { body: { innerHTML: 'stale' } }; req.onload();
	assert.equal(r.content.innerHTML, 'Loading');
	assert.equal(r.classes.has('active'), false);
});

test('declining an unsaved-draft warning keeps the existing inputs and target', () => {
	const r = createSlider();
	r.open('http://localhost/i/?id=1');
	r.content.innerHTML = 'unsaved input';
	r.sandbox.data_leave_validation = () => false;
	r.open('http://localhost/i/?id=2');
	assert.equal(r.requests.length, 1);
	assert.equal(r.content.innerHTML, 'unsaved input');
	assert.equal(r.sandbox.close_slider_listener(), false);
	assert.equal(r.classes.has('active'), true);
});

test('reopening the active target preserves its draft without another request', () => {
	const r = createSlider();
	r.open('http://localhost/i/?id=1');
	r.sandbox.location.hash = '#slider';
	r.content.innerHTML = 'unsaved input';
	r.open('http://localhost/i/?id=1');
	assert.equal(r.requests.length, 1);
	assert.equal(r.content.innerHTML, 'unsaved input');
});
