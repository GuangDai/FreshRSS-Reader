import { test } from 'node:test';
import assert from 'node:assert/strict';
import { AccountSettings, accountFieldValid, accountFieldValue } from '../../../../p/scripts/reader/settings/account.js';

function control(value = '') {
	return {
		value: String(value),
		checked: false,
		disabled: false,
		attributes: {},
		focused: false,
		setAttribute(key, value) { this.attributes[key] = value; },
		removeAttribute(key) { delete this.attributes[key]; },
		focus() { this.focused = true; },
	};
}

function field(type, initial, section = 'reading', spec = {}) {
	const value = {
		spec: { type, ...spec },
		initial,
		section,
		input: control(),
		error: { hidden: true },
	};
	if (type === 'integer_or_false' || type === 'duration') value.enabled = control();
	if (type === 'duration') value.durationUnit = control('M');
	AccountSettings.prototype.setField(value, initial);
	return value;
}

function editor(fields, api = { request: async () => { throw new Error('unexpected_request'); } }, options = {}) {
	const ui = new AccountSettings({ api, bootstrap: { lang: 'zh-CN' }, root: { removeEventListener() {} }, ...options });
	ui.fields = new Map(Object.entries(fields));
	ui.schema = { fields: Object.fromEntries(Object.entries(fields).map(([key, value]) => [key, value.spec])) };
	for (const section of new Set(Object.values(fields).map(value => value.section))) {
		ui.sections.set(section, {
			form: control(), save: control(), reset: control(), status: { textContent: '' }, revision: 0, busy: false,
		});
	}
	return ui;
}

function deferred() {
	let resolve; let reject;
	const promise = new Promise((_resolve, _reject) => { resolve = _resolve; reject = _reject; });
	return { promise, resolve, reject };
}

test('optional account settings preserve explicit false and zero', () => {
	const value = field('integer_or_false', false, 'storage', { min: 0 });
	assert.equal(accountFieldValue(value), false);
	value.enabled.checked = true; value.input.value = '0';
	assert.equal(accountFieldValue(value), 0);
	assert.equal(accountFieldValid(0, value.spec), true);
	value.input.value = '';
	assert.ok(Number.isNaN(accountFieldValue(value)));
	assert.equal(accountFieldValid(accountFieldValue(value), value.spec), false);
	value.enabled.checked = false;
	assert.equal(accountFieldValid(accountFieldValue(value), value.spec), true);
});

test('integer validation respects schema ranges and rejects incomplete or unsafe numbers', () => {
	const spec = { type: 'integer', min: 1, max: 1000 };
	for (const invalid of [0, 1001, 1.5, NaN, Infinity, Number.MAX_SAFE_INTEGER + 1, '20', false]) {
		assert.equal(accountFieldValid(invalid, spec), false);
	}
	assert.equal(accountFieldValid(20, spec), true);
	assert.equal(accountFieldValid('unread', { type: 'string', options: ['unread', 'all'] }), true);
	assert.equal(accountFieldValid('missing', { type: 'string', options: ['unread', 'all'] }), false);
});

test('duration controls round-trip all supported ISO units and disabled retention', () => {
	for (const duration of ['P3M', 'P1Y', 'P2W', 'P7D', 'PT12H', 'P0D']) {
		const value = field('duration', duration, 'storage');
		assert.equal(accountFieldValue(value), duration);
		assert.equal(accountFieldValid(duration, value.spec), true);
	}
	const value = field('duration', false, 'storage');
	assert.equal(accountFieldValue(value), false);
	assert.equal(value.input.disabled, true);
	value.enabled.checked = true; value.input.value = '9'; value.durationUnit.value = 'H';
	assert.equal(accountFieldValue(value), 'PT9H');
	value.input.value = '-1';
	assert.equal(accountFieldValid(accountFieldValue(value), value.spec), false);
	assert.equal(accountFieldValid('P1DT1H', value.spec), true);
});

test('custom duration values preserve compound, minute, second, and zero periods exactly without creating a draft', () => {
	for (const duration of ['P1DT6H', 'PT30M', 'PT45S', 'PT0S', 'P1Y2M3DT4H5M6S']) {
		const period = field('duration', duration, 'storage');
		const ui = editor({ 'archiving.keep_period': period });
		assert.equal(period.input.type, 'text');
		assert.equal(period.durationUnit.value, 'custom');
		assert.equal(period.input.value, duration);
		assert.equal(accountFieldValue(period), duration);
		assert.equal(accountFieldValid(duration, period.spec), true);
		assert.equal(ui.hasDraft('storage'), false);
	}
	for (const invalid of ['', 'P', 'PT', 'P1DT', 'PT1', 'P-1D', 'P1H', 'P1DM', 'PT1S1M']) {
		assert.equal(accountFieldValid(invalid, { type: 'duration' }), false);
	}
});

test('saving another storage field never submits or changes an existing compound retention period', async () => {
	for (const duration of ['P1DT6H', 'PT30M', 'PT45S']) {
		const period = field('duration', duration, 'storage');
		const maximum = field('integer_or_false', 200, 'storage', { min: 0 }); let sent;
		const ui = editor({ 'archiving.keep_period': period, 'archiving.keep_max': maximum }, {
			request: async (action, params, options) => {
				sent = options.body.values;
				return { schema: ui.schema, values: { 'archiving.keep_period': duration, 'archiving.keep_max': 300 } };
			},
		});
		maximum.input.value = '300';
		await ui.save('storage');
		assert.deepEqual(sent, { 'archiving.keep_max': 300 });
		assert.equal(period.input.value, duration);
		assert.equal(ui.hasDraft('storage'), false);
	}
});

test('changing a compound duration to a common unit requires an explicit quantity and switching back restores the raw period', () => {
	const period = field('duration', 'P1DT6H', 'storage');
	const ui = editor({ 'archiving.keep_period': period });
	period.durationUnit.value = 'H'; ui.changeDurationUnit(period);
	assert.equal(period.input.type, 'number');
	assert.equal(period.input.value, '');
	assert.equal(accountFieldValid(accountFieldValue(period), period.spec), false);
	period.durationUnit.value = 'custom'; ui.changeDurationUnit(period);
	assert.equal(period.input.value, 'P1DT6H');
	assert.equal(ui.hasDraft('storage'), false);
	period.durationUnit.value = 'H'; ui.changeDurationUnit(period); period.input.value = '30';
	assert.equal(accountFieldValue(period), 'PT30H');
	period.durationUnit.value = 'custom'; ui.changeDurationUnit(period);
	assert.equal(period.input.value, 'PT30H');
	AccountSettings.prototype.setField(period, false);
	assert.equal(accountFieldValue(period), false);
	period.enabled.checked = true; period.input.value = ' PT0S ';
	assert.equal(accountFieldValue(period), 'PT0S');
	assert.equal(accountFieldValid(accountFieldValue(period), period.spec), true);
});

test('an unrecognized existing retention value remains displayed and is never replaced merely by opening settings', () => {
	const period = field('duration', 'existing-unrecognized-value', 'storage');
	const ui = editor({ 'archiving.keep_period': period });
	assert.equal(period.durationUnit.value, 'custom');
	assert.equal(period.input.value, 'existing-unrecognized-value');
	assert.equal(ui.hasDraft('storage'), false);
});

test('disabling an optional limit preserves its previous editable value for re-enabling', () => {
	const limit = field('integer_or_false', 200, 'storage', { min: 0 });
	AccountSettings.prototype.setField(limit, false);
	assert.equal(accountFieldValue(limit), false);
	assert.equal(limit.input.value, '200');
	limit.enabled.checked = true;
	assert.equal(accountFieldValue(limit), 200);
	const period = field('duration', 'PT12H', 'storage');
	AccountSettings.prototype.setField(period, false);
	assert.equal(accountFieldValue(period), false);
	period.enabled.checked = true;
	assert.equal(accountFieldValue(period), 'PT12H');
});

test('one-line-per-rule fields remove blanks and duplicates while allowing an intentional empty list', () => {
	const value = field('string_list', ['intitle:news'], 'filters');
	value.input.value = ' intitle:news \r\n\n author:Alice\nintitle:news\n';
	assert.deepEqual(accountFieldValue(value), ['intitle:news', 'author:Alice']);
	value.input.value = ' \n\n';
	assert.deepEqual(accountFieldValue(value), []);
});

test('legacy whitespace and duplicate lines do not create a save draft merely by opening settings', () => {
	const filters = field('string_list', undefined, 'filters');
	const ui = editor({ 'filters.read': filters });
	ui.accept({ schema: ui.schema, values: { 'filters.read': [' intitle:news ', 'intitle:news', ''] } });
	assert.equal(ui.hasDraft(), false);
	assert.equal(ui.sections.get('filters').save.disabled, true);
});

test('saving a category submits only its changed values and retains drafts in other categories', async () => {
	let sent; let saved;
	const fields = {
		'mark_when.article': field('boolean', true),
		'posts_per_page': field('integer', 20),
		'archiving.keep_max': field('integer_or_false', 200, 'storage', { min: 0 }),
	};
	const ui = editor(fields, {
		request: async (action, params, options) => {
			assert.equal(action, 'accountSettings'); sent = options.body;
			return { schema: ui.schema, values: { 'mark_when.article': false, "posts_per_page": 20, 'archiving.keep_max': 200 } };
		},
	});
	ui.onSaved = (values, patch) => { saved = { values, patch }; };
	fields['mark_when.article'].input.checked = false;
	fields['archiving.keep_max'].input.value = '300';
	await ui.save('reading');
	assert.deepEqual(sent, { values: { 'mark_when.article': false } });
	assert.deepEqual(saved.patch, sent.values);
	assert.equal(ui.hasDraft('reading'), false);
	assert.equal(ui.hasDraft('storage'), true);
	assert.equal(fields['archiving.keep_max'].input.value, '300');
	assert.equal(ui.sections.get('reading').status.textContent, '已保存');
});

test('server normalization updates untouched submitted fields without discarding a newer draft', async () => {
	const result = deferred();
	const filters = field('string_list', [], 'filters');
	const ui = editor({ 'filters.read': filters }, { request: () => result.promise });
	filters.input.value = 'intitle: news';
	const saving = ui.save('filters');
	filters.input.value = 'intitle:later'; ui.sections.get('filters').revision++;
	result.resolve({ schema: ui.schema, values: { 'filters.read': ['intitle:news'] } });
	await saving;
	assert.equal(filters.input.value, 'intitle:later');
	assert.deepEqual(filters.initial, ['intitle:news']);
	assert.deepEqual(ui.changes('filters'), { 'filters.read': ['intitle:later'] });
	assert.equal(ui.sections.get('filters').status.textContent, '');
});

test('a delayed validation error does not overwrite feedback or flag a newer draft', async () => {
	const result = deferred(); const filters = field('string_list', [], 'filters');
	const ui = editor({ 'filters.read': filters }, { request: () => result.promise });
	filters.input.value = 'intitle:(';
	const saving = ui.save('filters');
	filters.input.value = 'intitle:valid'; ui.sections.get('filters').revision++;
	ui.sections.get('filters').status.textContent = 'new draft';
	const error = new Error('invalid_filter'); error.status = 400; error.details = { field: 'filters.read' };
	result.reject(error); await saving;
	assert.equal(filters.error.hidden, true);
	assert.equal(filters.input.value, 'intitle:valid');
	assert.equal(ui.sections.get('filters').status.textContent, 'new draft');
	assert.equal(ui.sections.get('filters').busy, false);
});

test('server field errors remain inline and focus the invalid control while preserving its value', async () => {
	const filters = field('string_list', [], 'filters');
	const error = new Error('invalid_filter'); error.status = 400; error.details = { field: 'filters.read' };
	const ui = editor({ 'filters.read': filters }, { request: async () => { throw error; } });
	filters.input.value = 'intitle:(';
	await ui.save('filters');
	assert.equal(filters.input.value, 'intitle:(');
	assert.equal(filters.error.hidden, false);
	assert.equal(filters.input.attributes['aria-invalid'], 'true');
	assert.equal(filters.input.focused, true);
	assert.equal(filters.error.textContent, '过滤条件的语法有误。');
});

test('validation reveals collapsed details around a visible invalid field before focusing it', () => {
	const number = field('integer', 20, 'reading', { min: 1 });
	const outer = { open: false, parentElement: { closest: () => null } };
	const inner = { open: false, parentElement: { closest: selector => selector === 'details' ? outer : null } };
	number.row = { closest: selector => selector === 'details' ? inner : null };
	const ui = editor({ posts_per_page: number });
	number.input.value = '0';
	ui.invalid('reading', ['posts_per_page'], 'invalid');
	assert.equal(inner.open, true);
	assert.equal(outer.open, true);
	assert.equal(number.input.focused, true);
	assert.equal(number.input.value, '0');
	assert.equal(ui.hasDraft('reading'), true);
});

test('an error arriving after switching categories remains inline without moving focus into the hidden category', async () => {
	const result = deferred(); const filters = field('string_list', [], 'filters');
	const ui = editor({ 'filters.read': filters }, { request: () => result.promise });
	filters.input.value = 'intitle:(';
	const saving = ui.save('filters');
	const disclosure = { open: false };
	filters.row = { closest: selector => selector === '[hidden]' ? { hidden: true } : selector === 'details' ? disclosure : null };
	const error = new Error('invalid_filter'); error.status = 400; error.details = { field: 'filters.read' };
	result.reject(error); await saving;
	assert.equal(filters.input.focused, false);
	assert.equal(filters.error.hidden, false);
	assert.equal(ui.sections.get('filters').status.textContent, '过滤条件的语法有误。');
	assert.equal(ui.sections.get('filters').busy, false);
	assert.equal(ui.sections.get('filters').save.disabled, false);
	assert.equal(ui.hasDraft('filters'), true);
	assert.equal(disclosure.open, false);
});

test('a duplicate change event on blur cannot erase saved feedback or a server validation error', () => {
	const filters = field('string_list', ['intitle:news'], 'filters');
	const ui = editor({ 'filters.read': filters });
	ui.root.contains = () => true;
	const row = { dataset: { accountField: 'filters.read' } };
	filters.input.closest = () => row;
	const state = ui.sections.get('filters'); state.status.textContent = '已保存';
	filters.error.hidden = false; filters.input.setAttribute('aria-invalid', 'true');
	ui.change({ target: filters.input });
	assert.equal(state.status.textContent, '已保存');
	assert.equal(filters.error.hidden, false);
	assert.equal(filters.input.attributes['aria-invalid'], 'true');
	filters.input.value = 'intitle:new';
	ui.change({ target: filters.input });
	assert.equal(state.status.textContent, '');
	assert.equal(filters.error.hidden, true);
	assert.equal(state.revision, 1);
	state.status.textContent = '已保存';
	ui.change({ target: filters.input });
	assert.equal(state.status.textContent, '已保存');
	assert.equal(state.revision, 1);
});

test('invalid local values do not issue a request and unchanged categories cannot save', async () => {
	let requests = 0;
	const number = field('integer', 20, 'reading', { min: 1 });
	const ui = editor({ posts_per_page: number }, { request: async () => { requests++; } });
	await ui.save('reading'); assert.equal(requests, 0);
	number.input.value = '';
	await ui.save('reading'); assert.equal(requests, 0);
	assert.equal(number.input.attributes['aria-invalid'], 'true');
	ui.reset('reading');
	assert.equal(ui.hasDraft(), false);
	assert.equal(number.input.value, '20');
	assert.equal(number.error.hidden, true);
});

test('writes from different categories are serialized so stale server snapshots cannot replace later saves', async () => {
	const first = deferred(); const calls = [];
	const reading = field('boolean', true); const privacy = field('boolean', true, 'privacy');
	const ui = editor({ 'mark_when.article': reading, "retrieve_extension_list": privacy }, {
		request: async (action, params, options) => {
			calls.push(options.body);
			if (calls.length === 1) return first.promise;
			return { schema: ui.schema, values: { 'mark_when.article': false, "retrieve_extension_list": false } };
		},
	});
	reading.input.checked = false; privacy.input.checked = false;
	const saveReading = ui.save('reading'); const savePrivacy = ui.save('privacy');
	assert.equal(calls.length, 1);
	first.resolve({ schema: ui.schema, values: { 'mark_when.article': false, "retrieve_extension_list": true } });
	await Promise.all([saveReading, savePrivacy]);
	assert.equal(calls.length, 2);
	assert.equal(ui.hasDraft(), false);
	assert.equal(privacy.input.checked, false);
});

test('expired sessions disable all saves and preserve every draft', async () => {
	let requests = 0;
	const error = new Error('csrf_invalid'); error.status = 403;
	const fields = { 'mark_when.article': field('boolean', true), "retrieve_extension_list": field('boolean', true, 'privacy') };
	const ui = editor(fields, { request: async () => { requests++; throw error; } });
	for (const value of Object.values(fields)) value.input.checked = false;
	await ui.save('reading'); await ui.save('privacy');
	assert.equal(requests, 1);
	assert.equal(ui.csrfInvalid, true);
	assert.equal(ui.hasDraft('reading'), true);
	assert.equal(ui.hasDraft('privacy'), true);
	assert.equal(ui.sections.get('privacy').save.disabled, true);
	assert.equal(ui.sections.get('reading').status.textContent, '登录状态已失效，请刷新页面后重试。');
});

test('disposing during a save aborts the request and prevents a late response from changing the draft', async () => {
	const result = deferred(); let signal;
	const article = field('boolean', true);
	const ui = editor({ 'mark_when.article': article }, { request: (action, params, options) => { signal = options.signal; return result.promise; } });
	article.input.checked = false;
	const saving = ui.save('reading'); ui.dispose();
	assert.equal(signal.aborted, true);
	result.resolve({ schema: ui.schema, values: { 'mark_when.article': false } });
	await saving;
	assert.equal(article.initial, true);
	assert.equal(ui.hasDraft(), true);
});

test('the shared save action submits all account drafts atomically and reports its busy and dirty state', async () => {
	const request = deferred(); const updates = []; const calls = [];
	const article = field('boolean', true); const maximum = field('integer_or_false', 200, 'storage', { min: 0 });
	const ui = editor({ 'mark_when.article': article, 'archiving.keep_max': maximum }, {
		request: (action, params, options) => { calls.push(options.body.values); return request.promise; },
	}, { externalActions: true, onDirty: (dirty, state) => updates.push({ dirty, ...state }) });
	for (const section of ui.sections.values()) { section.save = undefined; section.reset = undefined; }
	article.input.checked = false; maximum.input.value = '300';
	ui.sync('reading');
	assert.deepEqual(updates.at(-1), { dirty: true, busy: false, invalid: false });
	const saving = ui.saveAll();
	assert.deepEqual(calls, [{ 'mark_when.article': false, 'archiving.keep_max': 300 }]);
	assert.deepEqual(updates.at(-1), { dirty: true, busy: true, invalid: false });
	request.resolve({ schema: ui.schema, values: { 'mark_when.article': false, 'archiving.keep_max': 300 } });
	assert.equal(await saving, true);
	assert.deepEqual(updates.at(-1), { dirty: false, busy: false, invalid: false });
	assert.equal(ui.sections.get('storage').status.textContent, '已保存');
});

test('global account validation prevents every category from being partially saved', async () => {
	const article = field('boolean', true); const maximum = field('integer_or_false', 200, 'storage', { min: 0 });
	let requests = 0;
	const ui = editor({ 'mark_when.article': article, 'archiving.keep_max': maximum }, {
		request: async () => { requests++; },
	});
	article.input.checked = false; maximum.input.value = '-1';
	assert.equal(await ui.saveAll(), false);
	assert.equal(requests, 0);
	assert.equal(article.initial, true);
	assert.equal(maximum.input.attributes['aria-invalid'], 'true');
	ui.resetAll();
	assert.equal(ui.hasDraft(), false);
	assert.equal(article.input.checked, true);
	assert.equal(maximum.input.value, '200');
});

test('global account saves retain a draft edited during the request and report that more changes remain', async () => {
	const result = deferred(); const filter = field('string_list', [], 'filters');
	const ui = editor({ 'filters.read': filter }, { request: () => result.promise });
	filter.input.value = 'intitle:news';
	const saving = ui.saveAll();
	filter.input.value = 'intitle:later'; ui.sections.get('filters').revision++;
	result.resolve({ schema: ui.schema, values: { 'filters.read': ['intitle:news'] } });
	assert.equal(await saving, false);
	assert.equal(filter.input.value, 'intitle:later');
	assert.deepEqual(filter.initial, ['intitle:news']);
	assert.equal(ui.hasDraft(), true);
});

test('global account saves preserve every category when the server rejects one field', async () => {
	const article = field('boolean', true); const filters = field('string_list', [], 'filters');
	const failure = new Error('invalid_filter'); failure.status = 400; failure.details = { field: 'filters.read' };
	const ui = editor({ 'mark_when.article': article, 'filters.read': filters }, { request: async () => { throw failure; } });
	article.input.checked = false; filters.input.value = 'intitle:(';
	assert.equal(await ui.saveAll(), false);
	assert.equal(ui.hasDraft('reading'), true);
	assert.equal(ui.hasDraft('filters'), true);
	assert.equal(filters.error.textContent, '过滤条件的语法有误。');
	assert.equal(article.initial, true);
});

test('global account saving waits for an ongoing category write before submitting the remaining drafts', async () => {
	const result = deferred(); const calls = [];
	const article = field('boolean', true); const privacy = field('boolean', true, 'privacy');
	const ui = editor({ 'mark_when.article': article, "retrieve_extension_list": privacy }, {
		request: (action, params, options) => {
			calls.push(options.body.values);
			return calls.length === 1
				? result.promise
				: {
					schema: ui.schema,
					values: { 'mark_when.article': false, "retrieve_extension_list": false }
				};
		},
	});
	article.input.checked = false; privacy.input.checked = false;
	const first = ui.save('reading'); const all = ui.saveAll();
	assert.equal(calls.length, 1);
	result.resolve({ schema: ui.schema, values: { 'mark_when.article': false, "retrieve_extension_list": true } });
	await first;
	assert.equal(await all, true);
	assert.deepEqual(calls, [{ 'mark_when.article': false }, { retrieve_extension_list: false }]);
});

test('an expired session is reported to the shared save action while all account drafts remain available', async () => {
	const article = field('boolean', true); const updates = [];
	const error = new Error('csrf_invalid'); error.status = 403;
	const ui = editor({ 'mark_when.article': article }, { request: async () => { throw error; } }, {
		externalActions: true, onDirty: (dirty, state) => updates.push({ dirty, ...state }),
	});
	article.input.checked = false;
	assert.equal(await ui.saveAll(), false);
	assert.deepEqual(updates.at(-1), { dirty: true, busy: false, invalid: true });
	assert.equal(article.input.checked, false);
	assert.equal(await ui.saveAll(), false);
});

test('changing a submitted value back to its old saved value while saving preserves that newer choice', async () => {
	const result = deferred(); const article = field('boolean', true);
	const ui = editor({ 'mark_when.article': article }, { request: () => result.promise });
	article.input.checked = false;
	const saving = ui.saveAll();
	article.input.checked = true; ui.sections.get('reading').revision++;
	result.resolve({ schema: ui.schema, values: { 'mark_when.article': false } });
	assert.equal(await saving, false);
	assert.equal(article.input.checked, true);
	assert.equal(article.initial, false);
	assert.deepEqual(ui.changes(), { 'mark_when.article': true });
});

test('queued category saves stop with a session error rather than leaving a saving message after a 403', async () => {
	const result = deferred(); const article = field('boolean', true); const privacy = field('boolean', true, 'privacy');
	let requests = 0;
	const ui = editor({ 'mark_when.article': article, "retrieve_extension_list": privacy }, {
		request: () => { requests++; return result.promise; },
	});
	article.input.checked = false; privacy.input.checked = false;
	const first = ui.save('reading'); const second = ui.save('privacy');
	const error = new Error('csrf_invalid'); error.status = 403; result.reject(error);
	await Promise.all([first, second]);
	assert.equal(requests, 1);
	for (const state of ui.sections.values()) {
		assert.equal(state.busy, false);
		assert.equal(state.status.textContent, '登录状态已失效，请刷新页面后重试。');
	}
	assert.equal(ui.hasDraft('reading'), true);
	assert.equal(ui.hasDraft('privacy'), true);
});
