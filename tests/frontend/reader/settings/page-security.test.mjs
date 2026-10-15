import { test } from 'node:test';
import assert from 'node:assert/strict';
import { SettingsPage } from '../../../../p/scripts/reader/settings/page.js';

function fixture() {
	const priorLocation = globalThis.location;
	const priorWindow = globalThis.window;
	globalThis.location = { origin: 'https://reader.test', href: 'https://reader.test/i/?c=reader' };
	const opened = [];
	globalThis.window = { open: (...args) => opened.push(args), confirm: () => false };
	const attributes = new Map();
	let navigations = 0;
	const frame = {
		contentWindow: {},
		style: {},
		title: '',
		hasAttribute: name => attributes.has(name),
		removeAttribute: name => attributes.delete(name),
		get src() { return attributes.get('src'); },
		set src(value) { navigations++; attributes.set('src', value); },
	};
	const nodes = new Map([
		['settings-tool-frame', frame],
		['settings-tool-status', { hidden: false, textContent: '' }],
		['settings-tool-window', { href: '' }],
		['settings-tool-heading', { textContent: '' }],
		['settings-embedded', { hidden: true }],
		['settings-panels', { hidden: false }],
		['close-settings-tool', { focus() {} }],
	]);
	const heading = { hidden: false };
	const page = Object.create(SettingsPage.prototype);
	Object.assign(page, {
		section: 'privacy',
		zh: true,
		toolDirty: false,
		opened: true,
		node: id => nodes.get(id),
		root: { querySelector: selector => selector === '.settings-section-heading' ? heading : null },
		t: key => key,
		syncToolAppearance() {},
		syncToolDraft() {},
		syncSavebar() {},
		store: { feedsRefresh: async () => {} },
		tools: { refreshExtension() {} },
	});
	const event = data => ({ origin: globalThis.location.origin, source: frame.contentWindow, data: { type: 'reader-settings-embed', ...data } });
	return {
		page,
		frame,
		nodes,
		opened,
		event,
		get navigations() { return navigations; },
		restore() { globalThis.location = priorLocation; globalThis.window = priorWindow; },
	};
}

test('forged cross-origin and unrelated-window messages cannot clear drafts or open destinations', () => {
	const f = fixture();
	try {
		f.page.toolDirty = true;
		for (const data of [{ event: 'saved', dirty: false }, { event: 'resize', height: 50000 },
			{ event: 'navigate', url: 'https://reader.test/i/?c=user' }]) {
			f.page.receiveMessage({ ...f.event(data), origin: 'https://untrusted.test' });
			f.page.receiveMessage({ ...f.event(data), source: {} });
			f.page.receiveMessage({ ...f.event(data), data: { ...data, type: 'another-tool' } });
		}
		assert.equal(f.page.toolDirty, true);
		assert.equal(f.frame.style.height, undefined);
		assert.equal(f.nodes.get('settings-tool-status').hidden, false);
		assert.deepEqual(f.opened, []);
	} finally { f.restore(); }
});

test('a trusted new document clears the old form draft after an existing POST redirect', () => {
	const f = fixture();
	try {
		f.page.toolDirty = true;
		f.page.receiveMessage(f.event({ event: 'ready', dirty: false }));
		assert.equal(f.page.toolDirty, false);
		assert.equal(f.nodes.get('settings-tool-status').hidden, true);
		f.page.receiveMessage(f.event({ event: 'dirty', dirty: true }));
		assert.equal(f.page.toolDirty, true);
		f.page.receiveMessage(f.event({ event: 'saved', dirty: false }));
		assert.equal(f.page.toolDirty, false);
		assert.equal(f.page.storageLoaded, false);
	} finally { f.restore(); }
});

test('untrusted frame heights cannot create infinite or unbounded document dimensions', () => {
	const f = fixture();
	try {
		f.page.receiveMessage(f.event({ event: 'resize', height: 720 }));
		assert.equal(f.frame.style.height, '732px');
		for (const height of [Infinity, NaN, '999999999', null]) f.page.receiveMessage(f.event({ event: 'resize', height }));
		assert.equal(f.frame.style.height, '732px');
		f.page.receiveMessage(f.event({ event: 'resize', height: -1 }));
		assert.equal(f.frame.style.height, '400px');
		f.page.receiveMessage(f.event({ event: 'resize', height: 999999999 }));
		assert.equal(f.frame.style.height, '100000px');
	} finally { f.restore(); }
});

test('a dirty tool remains mounted across category changes and reopening does not reload its form', () => {
	const f = fixture();
	try {
		f.page.openTool('?c=user&a=profile', '账号与安全');
		f.page.receiveMessage(f.event({ event: 'dirty', dirty: true }));
		const href = f.frame.src;
		f.page.closeTool();
		assert.equal(f.frame.src, href);
		assert.equal(f.page.toolDirty, true);
		f.page.openTool('?c=user&a=profile&readerSettings=1', '账号与安全');
		assert.equal(f.navigations, 1);
		assert.equal(f.page.toolDirty, true);
		assert.equal(f.nodes.get('settings-embedded').hidden, false);
	} finally { f.restore(); }
});

test('declining a real dirty-form replacement retains the destination and original input document', () => {
	const f = fixture();
	try {
		f.page.openTool('?c=user&a=profile', '账号与安全');
		f.page.receiveMessage(f.event({ event: 'dirty', dirty: true }));
		const record = f.page.toolRecord;
		const href = f.frame.src;
		f.page.openTool('?c=configure&a=shortcut', '快捷键');
		assert.equal(f.page.toolRecord, record);
		assert.equal(f.frame.src, href);
		assert.equal(f.navigations, 1);
		assert.equal(f.page.toolDirty, true);
		f.page.openTool('https://untrusted.test/tool', 'Untrusted');
		assert.equal(f.frame.src, href);
	} finally { f.restore(); }
});

test('malformed and credential-bearing tool destinations are ignored without losing the active form', () => {
	const f = fixture();
	try {
		f.page.toolDirty = true;
		for (const url of ['http://[invalid', 'https://untrusted.test/tool', 'javascript:alert(1)', 'https://name:secret@reader.test/i/']) {
			assert.doesNotThrow(() => f.page.receiveMessage(f.event({ event: 'navigate', url })));
		}
		assert.deepEqual(f.opened, []);
		assert.equal(f.page.toolDirty, true);
		f.page.receiveMessage(f.event({ event: 'navigate', url: 'https://reader.test/i/?c=index' }));
		assert.deepEqual(f.opened, [['https://reader.test/i/?c=index', '_blank', 'noopener']]);
	} finally { f.restore(); }
});
