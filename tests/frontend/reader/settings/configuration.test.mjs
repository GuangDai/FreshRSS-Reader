import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
	ConfigurationSettings, settingsValue, configurationChanges, configurationDocument, importedConfiguration,
} from '../../../../p/scripts/reader/settings/configuration.js';

const field = (saved, raw, spec) => ({ saved, initial: String(saved ?? ''), input: { value: raw }, spec });

test('configuration changes keep explicit zero, false and null while omitting blank secrets', () => {
	const fields = new Map([
		['interval', field(60, '0', { type: 'integer', default: 60, min: 0 })],
		['enabled', field(true, 'false', { type: 'boolean', default: true })],
		['optional', field('value', '', { type: 'string', nullable: true })],
		['token', field(undefined, '', { type: 'string', secret: true })],
	]);
	assert.deepEqual(configurationChanges(fields), { values: { interval: 0, enabled: false, optional: null }, errors: {} });
});

test('equivalent numeric values and empty server objects do not create redundant updates', () => {
	const fields = new Map([
		['interval', field(60, '060', { type: 'integer' })],
		['recipes', { saved: [], initial: '{}', input: { value: '{ }' }, spec: { type: 'object' } }],
	]);
	assert.deepEqual(configurationChanges(fields), { values: {}, errors: {} });
});

test('numeric and structured settings cannot silently round integers outside browser precision', () => {
	assert.throws(() => settingsValue('9007199254740993', { type: 'integer' }), /toolsIntegerInvalid/);
	assert.throws(() => settingsValue('{"id":9007199254740993}', { type: 'object' }), /toolsUnsafeJSON/);
	assert.throws(() => settingsValue('[1e999]', { type: 'array' }), /toolsUnsafeJSON/);
	assert.equal(settingsValue('9007199254740993', { type: 'string' }), '9007199254740993');
});

test('text lengths use UTF-8 bytes and structured type and collection limits remain strict', () => {
	assert.throws(() => settingsValue('中文', { type: 'string', maxLength: 5 }), /toolsTextLimit/);
	assert.throws(() => settingsValue('[1]', { type: 'object' }), /toolsObjectRequired/);
	assert.throws(() => settingsValue('{}', { type: 'array' }), /toolsArrayRequired/);
	assert.throws(() => settingsValue('{"a":1,"b":2}', { type: 'object', maxItems: 1 }), /toolsCollectionLimit/);
	assert.throws(() => settingsValue('4', { type: 'integer', min: 5 }), /toolsNumberInvalid/);
	assert.throws(() => settingsValue('"unknown"', { type: 'string', enum: ['known'] }), /toolsFieldInvalid/);
});

test('exports contain only declared nonsecret values and normalize empty PHP objects for a portable import', () => {
	const snapshot = {
		extension: 'WebExtraction', schema: { recipes: { type: 'object' }, token: { type: 'string', secret: true } }, redacted: ['token'],
	};
	const exported = configurationDocument(snapshot, { recipes: [], token: 'private', unknown: 'legacy' });
	assert.deepEqual(exported, { version: 1, extension: 'WebExtraction', configuration: { recipes: {} }, redacted: ['token'] });
	assert.deepEqual(importedConfiguration(exported, snapshot), { recipes: {} });
});

test('imports reject wrong extensions, undeclared properties, secrets and type coercions', () => {
	const snapshot = { extension: 'Example', schema: { name: { type: 'string' }, token: { type: 'string', secret: true } } };
	const documentValue = configuration => ({ version: 1, extension: 'Example', configuration });
	assert.throws(() => importedConfiguration({ ...documentValue({}), extension: 'Other' }, snapshot), /toolsImportInvalid/);
	assert.throws(() => importedConfiguration(documentValue({ unknown: true }), snapshot), /toolsImportInvalid/);
	assert.throws(() => importedConfiguration(documentValue({ token: 'private' }), snapshot), /toolsImportInvalid/);
	assert.throws(() => importedConfiguration(documentValue({ name: 123 }), snapshot), /toolsImportInvalid/);
	assert.throws(() => importedConfiguration(documentValue(JSON.parse('{"__proto__":{}}')), snapshot), /toolsImportInvalid/);
});

test('configuration saves send the original concurrency token and only actual changes', async () => {
	const calls = [];
	const tools = Object.create(ConfigurationSettings.prototype);
	Object.assign(tools, {
		epoch: 0,
		store: {},
		request: async (...args) => {
			calls.push(args); return { configuration: { extension: 'RuleEngine', version: 'v2', values: { hook_priority: 200 } } };
		},
		showFieldErrors() {},
		sync() {},
		message() {},
		renderSummary() {}
	});
	const panel = {
		configuration: { extension: 'RuleEngine', version: 'v1', values: { hook_priority: 100 } },
		fields: new Map([['hook_priority', field(100, '200', { type: 'integer' })]])
	};
	await tools.save(panel);
	assert.deepEqual(calls[0], ['extensionConfig', {}, { extension: 'RuleEngine', expectedVersion: 'v1', configuration: { hook_priority: 200 } }]);
	assert.equal(panel.configuration.version, 'v2');
	assert.equal(panel.fields.get('hook_priority').initial, '200');
	assert.equal(panel.busy, false);
});

test('a conflict preserves the local draft and blocks a second blind save', async () => {
	let requests = 0;
	const tools = Object.create(ConfigurationSettings.prototype);
	const current = { extension: 'RuleEngine', version: 'v2', values: { hook_priority: 150 } };
	Object.assign(tools, {
		epoch: 0,
		store: {},
		request: async () => {
			requests++; throw Object.assign(new Error('configuration_conflict'), { status: 409, details: { conflicts: { RuleEngine: current } } });
		},
		showFieldErrors() {},
		sync() {},
		message() {},
		report() {},
		showConflict(panel, snapshot) { panel.conflict = snapshot; }
	});
	const panel = {
		configuration: { extension: 'RuleEngine', version: 'v1', values: { hook_priority: 100 } },
		fields: new Map([['hook_priority', field(100, '200', { type: 'integer' })]])
	};
	await tools.save(panel); await tools.save(panel);
	assert.equal(requests, 1); assert.equal(panel.fields.get('hook_priority').input.value, '200');
	assert.equal(panel.configuration.version, 'v1'); assert.equal(panel.conflict.version, 'v2');
});

test('reviewing a conflicting draft keeps local changes and takes untouched fields from the current server version', () => {
	const tools = Object.create(ConfigurationSettings.prototype);
	Object.assign(tools, { invalidatePreview() {}, message() {}, renderSummary() {}, sync() {} });
	const panel = {
		revision: 0,
		fields: new Map([
			['hook_priority', field(100, '200', { type: 'integer' })],
			['name', field('before', 'before', { type: 'string' })],
		]),
		conflict: true
	};
	const current = { extension: 'Example', version: 'v2', values: { hook_priority: 150, name: 'changed elsewhere' } };
	tools.reviewDraft(panel, current);
	assert.equal(panel.fields.get('hook_priority').input.value, '200');
	assert.equal(panel.fields.get('hook_priority').initial, '150');
	assert.equal(panel.fields.get('name').input.value, 'changed elsewhere');
	assert.deepEqual(configurationChanges(panel.fields).values, { hook_priority: 200 });
	assert.equal(panel.configuration.version, 'v2'); assert.equal(panel.conflict, null);
});

test('session disposal aborts live tool requests and removes stored configuration nodes', () => {
	let removed = 0;
	const controller = new AbortController();
	const tools = Object.create(ConfigurationSettings.prototype);
	Object.assign(tools, { epoch: 0, controllers: new Set([controller]), panels: new Map([['filters', { wrapper: { remove() { removed++; } } }]]) });
	tools.dispose();
	assert.equal(controller.signal.aborted, true); assert.equal(tools.panels.size, 0); assert.equal(removed, 1);
});

test('queue validation checks crash recovery and retry combinations against the complete pending configuration', () => {
	const tools = Object.create(ConfigurationSettings.prototype);
	const panel = {
		scheduler: {
			configuration: {
				job_timeout: 180, kill_grace: 5, lease_seconds: 240, retry_base: 60, retry_max: 300, retry_jitter_percent: 20,
			}
		},
		schedulerFields: new Map([
			['job_timeout', field(180, '300', { type: 'integer' })],
			['retry_base', field(60, '400', { type: 'integer' })],
			['retry_jitter_percent', field(20, '101', { type: 'integer' })],
		])
	};
	assert.deepEqual(tools.schedulerChanges(panel), {
		values: { job_timeout: 300, retry_base: 400, retry_jitter_percent: 101 },
		errors: { lease_seconds: 'toolsQueueLeaseError', retry_max: 'toolsQueueRetryError', retry_jitter_percent: 'toolsQueueJitterError' }
	});
});

test('queue saves are partial and invalid combinations cannot issue a server write', async () => {
	const calls = [];
	const tools = Object.create(ConfigurationSettings.prototype);
	Object.assign(tools, { store: {}, showSchedulerErrors() {}, writeScheduler: async (...args) => calls.push(args) });
	const panel = {
		scheduler: { configuration: { workers: 2, job_timeout: 180, kill_grace: 5, lease_seconds: 240 } },
		schedulerFields: new Map([['workers', field(2, '4', { type: 'integer' })]])
	};
	await tools.saveScheduler(panel);
	assert.deepEqual(calls[0][1], { configuration: { workers: 4 } });
	panel.schedulerFields.set('job_timeout', field(180, '300', { type: 'integer' }));
	await tools.saveScheduler(panel); assert.equal(calls.length, 1);
});
