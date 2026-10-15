import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FeedEditor } from '../../../../p/scripts/reader/subscriptions/feed-editor.js';

function draft(fields) {
	const editor = Object.create(FeedEditor.prototype);
	editor.fields = new Map(fields.map(([key, type, initial, value, checked = true]) => [key, {
		spec: { type }, initial, input: { value }, enabled: { checked },
	}]));
	return editor;
}

test('subscription drafts submit only selected changes and keep explicit zero and false from mixed fields', () => {
	const editor = draft([
		['ttl', 'integer', '', '0'],
		['enabled', 'boolean', '', 'false'],
		['priority', 'integer', '10', '0', false],
		['categoryId', 'integer', '1', '2'],
		['name', 'string', '', ' Shared title '],
	]);
	assert.deepEqual(editor.changes(), { ttl: 0, enabled: false, categoryId: 2, name: 'Shared title' });
	editor.fields.get('priority').enabled.checked = true;
	assert.equal(editor.changes().priority, 0);
});

test('restored values and equivalent numeric or trimmed text values cannot create redundant saves', () => {
	const editor = draft([
		['ttl', 'integer', '3600', '03600'],
		['enabled', 'boolean', 'false', 'false'],
		['priority', 'integer', '0', '0'],
		['name', 'string', 'Feed', ' Feed '],
	]);
	assert.deepEqual(editor.changes(), {});
	editor.fields.get('ttl').input.value = '';
	assert.ok(Number.isNaN(editor.changes().ttl));
	editor.fields.get('ttl').input.value = '3600';
	assert.deepEqual(editor.changes(), {});
});
