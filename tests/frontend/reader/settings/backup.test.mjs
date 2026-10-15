import { test } from 'node:test';
import assert from 'node:assert/strict';
import { BackupSettings, backupPolicyChanges } from '../../../../p/scripts/reader/settings/backup.js';

const schema = { enabled: { type: 'boolean', default: false }, retention: { type: 'integer', min: 1, default: 7 } };
const policy = (configuration = { enabled: false, retention: 7 }) => ({ configuration, schema, atomic: true });

function control(value = '') {
	return {
		value: String(value),
		checked: false,
		disabled: false,
		hidden: false,
		textContent: '',
		attributes: {},
		setAttribute(key, value) { this.attributes[key] = value; },
		removeAttribute(key) { delete this.attributes[key]; },
		focus() { this.focused = true; },
	};
}

function field(key, initial) {
	const input = control(initial);
	if (key === 'enabled') input.checked = initial;
	return { input, error: control(), spec: schema[key], initial };
}

function editor(api = { request: async () => { throw new Error('unexpected_request'); } }) {
	const ui = new BackupSettings({ api, root: {}, bootstrap: { language: 'zh-CN', canManageLimits: true } });
	ui.fields = new Map([['enabled', field('enabled', false)], ['retention', field('retention', 7)]]);
	ui.status = control(); ui.retry = control(); ui.saveButton = control(); ui.resetButton = control();
	ui.loaded = true; ui.wrapper = { removeEventListener() {}, remove() {} };
	return ui;
}

function deferred() {
	let resolve; let reject;
	const promise = new Promise((_resolve, _reject) => { resolve = _resolve; reject = _reject; });
	return { promise, resolve, reject };
}

test('backup changes retain explicit false and submit only semantically changed values', () => {
	const fields = new Map([['enabled', field('enabled', true)], ['retention', field('retention', 7)]]);
	fields.get('enabled').input.checked = false; fields.get('retention').input.value = '007';
	assert.deepEqual(backupPolicyChanges(fields), { configuration: { enabled: false }, errors: {} });
});

test('backup retention rejects zero, empty values, fractions and unsafe integers', () => {
	const fields = new Map([['retention', field('retention', 7)]]);
	for (const value of ['0', '', ' ', '-1', '1.5', 'Infinity', '9007199254740993']) {
		fields.get('retention').input.value = value;
		assert.deepEqual(backupPolicyChanges(fields), { configuration: {}, errors: { retention: 'invalid' } });
	}
	fields.get('retention').input.value = '1';
	assert.deepEqual(backupPolicyChanges(fields), { configuration: { retention: 1 }, errors: {} });
});

test('language fallback keeps the CLI requirement and minimum-one meaning visible', () => {
	const ui = editor();
	ui.t = key => key;
	assert.match(ui.text('note'), /cli\/export-sqlite-auto\.php/);
	assert.match(ui.text('retentionHelp'), /至少保留 1 份/);
	assert.match(ui.text('retentionHelp'), /不会立即删除/);
	ui.bootstrap.language = 'en';
	assert.equal(ui.text('title'), 'Automatic SQLite backups');
});

test('nonadministrators do not display or request system backup policies', async () => {
	let requests = 0;
	const container = { hidden: false };
	const ui = new BackupSettings({
		root: { querySelector: () => container },
		bootstrap: { canManageLimits: false },
		api: { request: async () => { requests++; } },
	});
	ui.mount(); await ui.activate('data');
	assert.equal(container.hidden, true); assert.equal(requests, 0);
});

test('backup policies load only in data settings and share one outstanding request', async () => {
	const pending = deferred(); let requests = 0;
	const ui = editor({ request: async action => { requests++; assert.equal(action, 'backupPolicy'); return pending.promise; } });
	ui.loaded = false;
	await ui.activate('storage'); assert.equal(requests, 0);
	const first = ui.activate('data'); const second = ui.activate('data');
	assert.equal(first, second); assert.equal(requests, 1);
	pending.resolve(policy()); await first;
	await ui.activate('data'); assert.equal(requests, 1);
	assert.equal(ui.hasDraft(), false);
});

test('saves send only changed policy fields and accept concurrent changes to untouched fields', async () => {
	let body;
	const ui = editor({
		request: async (action, params, options) => {
			assert.equal(action, 'backupPolicy'); body = options.body;
			return policy({ enabled: true, retention: 12 });
		}
	});
	ui.fields.get('enabled').input.checked = true;
	await ui.save();
	assert.deepEqual(body, { configuration: { enabled: true } });
	assert.equal(ui.fields.get('retention').input.value, '12');
	assert.equal(ui.hasDraft(), false); assert.equal(ui.saveButton.disabled, true); assert.equal(ui.resetButton.hidden, true);
	assert.equal(ui.status.textContent, '备份策略已保存。');
});

test('unchanged or invalid policy drafts cannot issue a write and invalid values receive focus', async () => {
	let requests = 0;
	const ui = editor({ request: async () => { requests++; } });
	await ui.save();
	ui.fields.get('retention').input.value = '0'; await ui.save();
	assert.equal(requests, 0); assert.equal(ui.fields.get('retention').input.focused, true);
	assert.equal(ui.fields.get('retention').input.attributes['aria-invalid'], 'true');
	assert.equal(ui.saveButton.disabled, true);
});

test('a failed save retains editable values and an explicit field error without resetting the policy', async () => {
	const failure = Object.assign(new Error('field_out_of_range'), { status: 400, details: { field: 'retention' } });
	const ui = editor({ request: async () => { throw failure; } });
	const errors = []; ui.onError = error => errors.push(error);
	ui.fields.get('retention').input.value = '13'; await ui.save();
	assert.equal(ui.fields.get('retention').input.value, '13'); assert.equal(ui.fields.get('retention').initial, 7);
	assert.equal(ui.fields.get('retention').input.attributes['aria-invalid'], 'true');
	assert.equal(ui.hasDraft(), true); assert.equal(ui.busy, false); assert.equal(ui.saveButton.disabled, false);
	assert.deepEqual(errors, [failure]); assert.match(ui.status.textContent, /修改仍保留/);
	ui.reset(); assert.equal(ui.fields.get('retention').input.value, '7'); assert.equal(ui.hasDraft(), false);
});

test('duplicate saves are blocked while controls are busy and session failures block further writes', async () => {
	const pending = deferred(); let requests = 0;
	const ui = editor({ request: async () => { requests++; return pending.promise; } });
	ui.fields.get('enabled').input.checked = true;
	const saving = ui.save(); await ui.save();
	assert.equal(requests, 1); assert.equal(ui.fields.get('retention').input.disabled, true);
	pending.reject(Object.assign(new Error('csrf_invalid'), { status: 403 })); await saving;
	assert.equal(ui.fields.get('enabled').input.checked, true); assert.equal(ui.sessionInvalid, true);
	await ui.save(); assert.equal(requests, 1); assert.equal(ui.saveButton.disabled, true);
});

test('disposing backup settings aborts an outstanding request and ignores its eventual response', async () => {
	const pending = deferred(); let signal;
	const ui = editor({ request: async (action, params, options) => { signal = options.signal; return pending.promise; } });
	const retention = ui.fields.get('retention'); retention.input.value = '11';
	const saving = ui.save(); ui.dispose();
	assert.equal(signal.aborted, true); assert.equal(ui.controllers.size, 0);
	pending.resolve(policy({ enabled: true, retention: 11 })); await saving;
	assert.equal(retention.initial, 7); assert.equal(ui.fields.size, 0); assert.equal(ui.loaded, false);
});

test('the compact editor uses grouped rows and button actions without nesting a form', () => {
	class Node {
		constructor(tag, className, textContent = '') {
			Object.assign(this, { tag, className, textContent, children: [], dataset: {}, value: '', checked: false });
		}

		append(...nodes) { for (const node of nodes) { node.remove(); this.children.push(node); node.parent = this; } }
		insertBefore(node, before) { node.remove(); this.children.splice(this.children.indexOf(before), 0, node); node.parent = this; }
		remove() { if (this.parent) this.parent.children.splice(this.parent.children.indexOf(this), 1); }
		setAttribute() {}
		removeAttribute() {}
		addEventListener() {}
		removeEventListener() {}
	}
	const container = new Node('div');
	const ui = new BackupSettings({
		root: { querySelector: () => container },
		bootstrap: { canManageLimits: true, language: 'zh-CN' },
		element: (...args) => new Node(...args),
		api: {},
	});
	ui.mount(); ui.accept(policy());
	const nodes = []; const walk = node => { nodes.push(node); node.children.forEach(walk); }; walk(container);
	assert.equal(nodes.some(node => node.tag === 'form'), false);
	assert.equal(nodes.filter(node => node.dataset.backupField).length, 2);
	assert.equal(nodes.filter(node => node.tag === 'button').every(node => node.type === 'button'), true);
});
