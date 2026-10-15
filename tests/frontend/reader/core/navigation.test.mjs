import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ReaderNavigation } from '../../../../p/scripts/reader/core/navigation.js';

const initial = () => ({ query: { get: 'a', filter: 'all', search: '' }, cursor: '', history: [], selectedId: null, listScroll: 0 });

function fixture(href = 'https://reader.test/i/?c=reader') {
	const location = { href };
	const entries = [{ state: { unrelated: true }, href }];
	const restored = [];
	const writes = [];
	let index = 0;
	let traversal = null;
	const history = {
		get state() { return entries[index].state; },
		get length() { return entries.length; },
		pushState(state, title, url) {
			writes.push('push');
			entries.splice(++index, Infinity, { state: structuredClone(state), href: url }); location.href = url;
		},
		replaceState(state, title, url) { writes.push('replace'); entries[index] = { state: structuredClone(state), href: url }; location.href = url; },
		go(delta) { assert.equal(traversal, null); traversal = delta; },
	};
	const navigation = new ReaderNavigation({ history, location, onRestore: (state, panel) => restored.push({ state, panel }) });
	const flush = (delta = traversal) => {
		traversal = null;
		index += delta;
		assert.ok(index >= 0 && index < entries.length, 'traversal stays within the reader');
		location.href = entries[index].href;
		navigation.handlePopState({ state: history.state });
	};
	return { navigation, restored, history, location, entries, flush, writes, flushFrame: () => { traversal = null; } };
}

test('opening and stepping through articles adds one history entry and Back preserves the list', () => {
	const { navigation, history, location, flush, restored } = fixture();
	navigation.start(initial());
	const list = { ...initial(), query: { get: 'f_7', filter: 'unread', search: 'space' }, cursor: '500', history: [''], listScroll: 430 };
	navigation.openArticle('499', list);
	assert.equal(history.length, 2);
	navigation.openArticle('498', { ...list, selectedId: '499' });
	assert.equal(history.length, 2);
	assert.equal(new URL(location.href).searchParams.get('article'), '498');
	flush(-1);
	assert.deepEqual(restored.at(-1), { state: list, panel: null });
	assert.equal(new URL(location.href).searchParams.get('article'), null);
	flush(1);
	assert.equal(restored.at(-1).state.selectedId, '498');
});

test('a shared article URL has a usable list below it and reload adds no more history', () => {
	const f = fixture('https://reader.test/i/?c=reader&get=f_7&filter=unread&search=moon&cursor=500&article=499');
	const state = f.navigation.start(initial());
	assert.equal(state.selectedId, '499');
	assert.deepEqual(state.query, { get: 'f_7', filter: 'unread', search: 'moon' });
	assert.equal(state.cursor, '500');
	assert.equal(f.history.length, 2);
	const reload = new ReaderNavigation({ history: f.history, location: f.location, onRestore() {} });
	assert.deepEqual(reload.start(initial()), state);
	assert.equal(f.history.length, 2);
	f.flush(-1);
	assert.equal(f.restored.at(-1).state.selectedId, null);
});

test('closing responds synchronously and a new open survives the delayed history traversal', () => {
	const { navigation, history, flush, restored, location } = fixture();
	navigation.start(initial()); navigation.openArticle('100', initial());
	navigation.closeArticle({ ...initial(), selectedId: '100', listScroll: 700 });
	assert.equal(restored.at(-1).state.selectedId, null);
	navigation.openArticle('99', { ...initial(), listScroll: 700 });
	flush();
	assert.equal(restored.length, 1, 'old close does not restore over the new article');
	assert.equal(new URL(location.href).searchParams.get('article'), '99');
	assert.equal(history.length, 2);
	flush(-1);
	assert.equal(restored.at(-1).state.listScroll, 700);
	flush(1);
	assert.equal(restored.at(-1).state.selectedId, '99');
});

test('back from an article reached on a later list page keeps that page and cursor stack', () => {
	const { navigation, restored, flush } = fixture();
	navigation.start(initial()); navigation.openArticle('100', initial());
	const later = { ...initial(), cursor: '90', history: ['', '95'], listScroll: 0 };
	navigation.openArticle('89', later);
	flush(-1);
	assert.deepEqual(restored.at(-1).state, later);
});

test('changing filters closes the article without adding a spare list entry', () => {
	const { navigation, history, restored, flush } = fixture();
	navigation.start(initial()); navigation.openArticle('100', initial());
	const list = { ...initial(), query: { get: 'a', filter: 'starred', search: '' } };
	navigation.replace(list); flush();
	assert.deepEqual(navigation.current.snapshot, list);
	assert.equal(restored.length, 0);
	navigation.openArticle('99', list);
	assert.equal(history.length, 2);
});

test('Back dismisses the top panel before the article and forward restores both layers', () => {
	const { navigation, restored, history, flush } = fixture();
	navigation.start(initial()); navigation.openArticle('100', initial());
	navigation.openPanel('appearance');
	assert.equal(history.length, 3);
	flush(-1);
	assert.equal(restored.at(-1).panel, null);
	assert.equal(restored.at(-1).state.selectedId, '100');
	flush(-1);
	assert.equal(restored.at(-1).state.selectedId, null);
	flush(1); flush(1);
	assert.equal(restored.at(-1).panel, 'appearance');
	assert.equal(restored.at(-1).state.selectedId, '100');
});

test('replacing a closing sidebar with settings waits for popstate without losing the new panel', () => {
	const { navigation, restored, history, flush } = fixture();
	navigation.start(initial()); navigation.openPanel('sidebar');
	navigation.closePanel(); navigation.openPanel('appearance'); flush();
	assert.equal(navigation.panel, 'appearance');
	assert.equal(history.length, 2);
	assert.equal(restored.length, 1);
	flush(-1);
	assert.equal(restored.at(-1).panel, null);
});

test('history strips article content, configuration and arbitrary query fields', () => {
	const { navigation, history } = fixture();
	const state = { ...initial(), article: { content: 'private body' }, csrf: 'secret', preferences: { theme: 'dark' } };
	state.query.extra = 'private';
	navigation.start(state); navigation.openArticle('100', state);
	const serialised = JSON.stringify(history.state);
	assert.doesNotMatch(serialised, /private|secret|preferences|content/);
	assert.equal(history.state.unrelated, true);
});

test('returning to an already open panel removes its covering layer without reordering history', () => {
	const { navigation, restored, flush } = fixture();
	navigation.start(initial()); navigation.openPanel('sidebar'); navigation.openPanel('appearance');
	navigation.openPanel('sidebar'); flush();
	assert.equal(navigation.panel, 'sidebar');
	flush(-1);
	assert.equal(restored.at(-1).panel, null);
});

test('several new layers opened during a pending close retain one back step per layer', () => {
	const { navigation, restored, history, flush } = fixture();
	navigation.start(initial()); navigation.openArticle('100', initial());
	navigation.closeArticle();
	navigation.openArticle('99', initial()); navigation.openPanel('appearance');
	flush();
	assert.equal(history.length, 3);
	flush(-1);
	assert.equal(restored.at(-1).state.selectedId, '99');
	assert.equal(restored.at(-1).panel, null);
	flush(-1);
	assert.equal(restored.at(-1).state.selectedId, null);
});

test('invalid shared article IDs do not create an article history entry', () => {
	const { navigation, history, location } = fixture('https://reader.test/i/?c=reader&article=invalid');
	assert.equal(navigation.start(initial()).selectedId, null);
	assert.equal(history.length, 1);
	assert.equal(new URL(location.href).searchParams.get('article'), null);
});

test('settings sections have shareable URLs without adding a history step for each category', () => {
	const { navigation, history, location, restored, flush } = fixture();
	const list = { ...initial(), query: { get: 'f_7', filter: 'unread', search: 'moon' }, cursor: '500', history: [''], listScroll: 430 };
	navigation.start(list); navigation.openPanel('settings', list);
	assert.equal(navigation.panel, 'settings');
	assert.equal(new URL(location.href).searchParams.get('view'), 'settings');
	assert.equal(new URL(location.href).searchParams.get('section'), 'appearance');
	navigation.setSettingsSection('subscriptions'); navigation.setSettingsSection('storage');
	assert.equal(history.length, 2);
	assert.equal(navigation.settingsSection, 'storage');
	assert.equal(new URL(location.href).searchParams.get('section'), 'storage');
	flush(-1);
	assert.deepEqual(restored.at(-1), { state: list, panel: null });
	assert.equal(new URL(location.href).searchParams.get('view'), null);
	assert.equal(new URL(location.href).searchParams.get('section'), null);
	flush(1);
	assert.equal(restored.at(-1).panel, 'settings');
	assert.equal(navigation.settingsSection, 'storage');
});

test('a direct settings URL preserves the article and creates usable reading history beneath it', () => {
	const f = fixture('https://reader.test/i/?c=reader&get=f_7&filter=unread&search=moon&cursor=500&article=499&view=settings&section=filters');
	const state = f.navigation.start(initial());
	assert.equal(state.selectedId, '499');
	assert.deepEqual(state.query, { get: 'f_7', filter: 'unread', search: 'moon' });
	assert.equal(f.navigation.panel, 'settings');
	assert.equal(f.navigation.settingsSection, 'filters');
	assert.equal(f.history.length, 3);
	const reload = new ReaderNavigation({ history: f.history, location: f.location, onRestore() {} });
	assert.deepEqual(reload.start(initial()), state);
	assert.equal(reload.settingsSection, 'filters');
	assert.equal(reload.panel, 'settings');
	assert.equal(f.history.length, 3);
	f.flush(-1);
	assert.equal(f.restored.at(-1).panel, null);
	assert.equal(f.restored.at(-1).state.selectedId, '499');
	assert.equal(new URL(f.location.href).searchParams.get('view'), null);
	f.flush(-1);
	assert.equal(f.restored.at(-1).state.selectedId, null);
	f.flush(1); f.flush(1);
	assert.equal(f.navigation.panel, 'settings');
	assert.equal(f.navigation.settingsSection, 'filters');
});

test('invalid settings sections fall back and unsupported section changes are ignored', () => {
	const { navigation, history, location } = fixture('https://reader.test/i/?c=reader&view=settings&section=unknown');
	navigation.start(initial());
	assert.equal(navigation.settingsSection, 'appearance');
	assert.equal(history.length, 2);
	navigation.setSettingsSection('unsupported');
	assert.equal(navigation.settingsSection, 'appearance');
	assert.equal(new URL(location.href).searchParams.get('section'), 'appearance');
	const state = structuredClone(history.state);
	state.freshrssReader.settingsSection = { private: 'secret' };
	assert.equal(navigation.read(state).settingsSection, 'appearance');
});

test('closing settings restores the reading page and named close removes its dialog too', () => {
	const { navigation, restored, history, flush, location } = fixture();
	navigation.start(initial()); navigation.openArticle('100', initial());
	navigation.openPanel('settings'); navigation.setSettingsSection('subscriptions'); navigation.openPanel('feed-settings');
	assert.equal(history.length, 4);
	assert.equal(new URL(location.href).searchParams.get('view'), 'settings');
	navigation.closePanel('settings', { ...initial(), selectedId: '100', listScroll: 620 });
	assert.equal(restored.at(-1).panel, null);
	assert.equal(restored.at(-1).state.selectedId, '100');
	flush();
	assert.equal(new URL(location.href).searchParams.get('view'), null);
	assert.equal(navigation.current.snapshot.listScroll, 620);
	flush(1);
	assert.equal(navigation.panel, 'settings');
	assert.equal(navigation.settingsSection, 'subscriptions');
});

test('a settings category chosen during a closing sidebar survives the pending popstate', () => {
	const { navigation, restored, history, flush, location } = fixture();
	navigation.start(initial()); navigation.openPanel('sidebar');
	navigation.closePanel(); navigation.openPanel('settings'); navigation.setSettingsSection('data'); flush();
	assert.equal(navigation.panel, 'settings');
	assert.equal(navigation.settingsSection, 'data');
	assert.equal(history.length, 2);
	assert.equal(restored.length, 1);
	assert.equal(new URL(location.href).searchParams.get('view'), 'settings');
	assert.equal(new URL(location.href).searchParams.get('section'), 'data');
	flush(-1);
	assert.equal(navigation.panel, null);
	flush(1);
	assert.equal(navigation.panel, 'settings');
	assert.equal(navigation.settingsSection, 'data');
});

test('reloading the reading list from settings removes its old article and preserves the settings page', () => {
	const { navigation, restored, history, flush, location } = fixture();
	navigation.start(initial()); navigation.openArticle('100', initial()); navigation.openPanel('settings');
	navigation.setSettingsSection('reading');
	const list = { ...initial(), cursor: '90', history: [''], listScroll: 230 };
	navigation.replace(list);
	assert.equal(navigation.panel, 'settings');
	assert.equal(navigation.current.snapshot.selectedId, null);
	assert.equal(restored.length, 0, 'saving does not restore a reading page over settings');
	flush();
	assert.equal(history.length, 2, 'the stale article and settings history are replaced');
	assert.equal(navigation.panel, 'settings');
	assert.equal(new URL(location.href).searchParams.get('article'), null);
	assert.equal(new URL(location.href).searchParams.get('view'), 'settings');
	assert.equal(navigation.settingsSection, 'reading');
	flush(-1);
	assert.deepEqual(restored.at(-1), { state: list, panel: null });
	flush(1);
	assert.deepEqual(restored.at(-1), { state: list, panel: 'settings' });
	assert.equal(navigation.settingsSection, 'reading');
});

test('list reload preserves the subscription editor over settings without a stale article history layer', () => {
	const { navigation, restored, history, flush } = fixture();
	navigation.start(initial()); navigation.openArticle('100', initial());
	navigation.openPanel('settings'); navigation.setSettingsSection('subscriptions'); navigation.openPanel('feed-settings');
	const list = { ...initial(), query: { get: 'f_7', filter: 'all', search: '' } };
	navigation.replace(list); flush();
	assert.equal(history.length, 3);
	assert.equal(navigation.panel, 'feed-settings');
	flush(-1);
	assert.deepEqual(restored.at(-1), { state: list, panel: 'settings' });
	flush(-1);
	assert.deepEqual(restored.at(-1), { state: list, panel: null });
	flush(1); flush(1);
	assert.deepEqual(restored.at(-1), { state: list, panel: 'feed-settings' });
});

test('a list reload during pending panel navigation retains settings and folds the original article', () => {
	const { navigation, history, flush, location } = fixture();
	navigation.start(initial()); navigation.openArticle('100', initial()); navigation.openPanel('sidebar');
	navigation.closePanel(); navigation.openPanel('settings');
	navigation.replace(initial());
	navigation.setSettingsSection('storage');
	navigation.replace({ ...initial(), listScroll: 340 });
	flush();
	assert.equal(navigation.panel, 'settings');
	flush();
	assert.equal(history.length, 2);
	assert.equal(navigation.panel, 'settings');
	assert.equal(navigation.settingsSection, 'storage');
	assert.equal(new URL(location.href).searchParams.get('article'), null);
	flush(-1);
	assert.equal(navigation.current.snapshot.selectedId, null);
	assert.equal(navigation.current.snapshot.listScroll, 340);
});

test('rapid settings and article loops write only changed history entries', () => {
	const { navigation, flush, writes } = fixture();
	navigation.start(initial());
	for (let index = 0; index < 32; index++) {
		navigation.openPanel('settings'); navigation.closePanel('settings'); flush();
		navigation.openArticle('100', initial()); navigation.closeArticle(); flush();
	}
	assert.equal(writes.filter(kind => kind === 'replace').length, 1, 'only initialization replaces an unchanged base entry');
	assert.equal(writes.filter(kind => kind === 'push').length, 64, 'settings and article each create one changed entry');
});

test('iframe history events continue a pending Back and duplicate restoration events do not traverse twice', () => {
	const { navigation, flush, flushFrame, location } = fixture();
	navigation.start(initial()); navigation.openPanel('settings'); navigation.closePanel('settings');
	flushFrame();
	const frameDocument = {};
	navigation.handleFrameNavigation(frameDocument, 'https://reader.test/i/?c=configure&a=shortcut');
	navigation.handleFrameNavigation(frameDocument, 'https://reader.test/i/?c=configure&a=shortcut');
	assert.notEqual(navigation.pending, null);
	flush();
	assert.equal(navigation.pending, null);
	assert.equal(new URL(location.href).searchParams.get('view'), null);
});

test('opening an article during iframe history traversal survives the eventual parent Back', () => {
	const { navigation, flush, flushFrame, history } = fixture();
	navigation.start(initial()); navigation.openPanel('settings'); navigation.closePanel('settings');
	navigation.openArticle('100', initial());
	flushFrame(); navigation.handleFrameNavigation({}, 'https://reader.test/i/?c=configure&a=shortcut');
	flushFrame(); navigation.handleFrameNavigation({}, 'https://reader.test/i/?c=configure&a=shortcut');
	flush();
	assert.equal(navigation.pending, null);
	assert.equal(navigation.current.snapshot.selectedId, '100');
	assert.equal(navigation.panel, null);
	assert.equal(history.length, 2);
	flush(-1);
	assert.equal(navigation.current.snapshot.selectedId, null);
});

test('a native form draft returns through parent history without traversing iframe entries and Forward restores settings', () => {
	const { navigation, flush, restored, history, location } = fixture();
	navigation.start(initial()); navigation.openArticle('100', initial()); navigation.openPanel('settings');
	navigation.setSettingsSection('reading');
	const reading = { ...initial(), selectedId: '100', listScroll: 350 };
	navigation.closeSettingsPreservingFrame(reading);
	assert.equal(navigation.panel, null);
	assert.deepEqual(restored.at(-1), { state: reading, panel: null });
	assert.equal(history.length, 4, 'one forward settings entry is added to the retained native document');
	flush();
	assert.equal(navigation.pending, null);
	assert.equal(new URL(location.href).searchParams.get('view'), null);
	flush(1);
	assert.equal(navigation.panel, 'settings');
	assert.equal(navigation.settingsSection, 'reading');
	assert.equal(navigation.current.snapshot.selectedId, '100');
});

test('closing the underlying article retains a native form and leaves the article available to Forward', () => {
	const { navigation, flush, restored, location } = fixture();
	navigation.start(initial()); navigation.openArticle('100', initial());
	navigation.closeArticle({ ...initial(), selectedId: '100', listScroll: 240 }, true);
	assert.equal(restored.at(-1).state.selectedId, null);
	flush();
	assert.equal(navigation.pending, null);
	assert.equal(new URL(location.href).searchParams.get('article'), null);
	flush(1);
	assert.equal(navigation.current.snapshot.selectedId, '100');
	assert.equal(navigation.current.snapshot.listScroll, 240);
});

test('a retained frame parent step does not continue Back when the browser reports an enclosing settings state', () => {
	const { navigation, flush, history, location } = fixture();
	navigation.start(initial()); navigation.openPanel('settings'); navigation.closeSettingsPreservingFrame();
	// WebKit reports the iframe's enclosing settings state on this first parent step.
	navigation.handlePopState({ state: history.state });
	assert.equal(navigation.pending, null);
	assert.equal(navigation.panel, null);
	assert.equal(new URL(location.href).searchParams.get('view'), null);
	flush();
	assert.equal(navigation.panel, null);
});

test('a retained form iframe finishing its load cannot add another Back during the parent step', () => {
	const { navigation, flush } = fixture();
	navigation.start(initial()); navigation.openPanel('settings'); navigation.closeSettingsPreservingFrame();
	assert.doesNotThrow(() => navigation.handleFrameNavigation({}, 'https://reader.test/i/?c=configure&a=shortcut'));
	flush();
	assert.equal(navigation.pending, null);
	assert.equal(navigation.panel, null);
});

for (const path of ['/i/', '/i/?c=index', '/i/?c=reader', '/i/?c=index&a=global', '/i/?c=index&a=normal', '/i/?c=index&a=reader']) {
	test(`the unified reading page keeps a canonical home URL from ${path}`, () => {
		const { navigation, history, location } = fixture('https://reader.test' + path);
		navigation.start(initial());
		assert.equal(location.href, 'https://reader.test/i/');
		navigation.openArticle('100', initial());
		const url = new URL(location.href);
		assert.equal(url.pathname, '/i/');
		assert.equal(url.searchParams.get('c'), null);
		assert.equal(url.searchParams.get('a'), null);
		assert.equal(url.searchParams.get('article'), '100');
		assert.equal(history.length, 2);
	});
}

test('legacy compound reading states survive shared links, reload and history', () => {
	const f = fixture('https://reader.test/i/?c=index&a=normal&get=t_42&state=96&search=RSS');
	const state = f.navigation.start(initial());
	assert.deepEqual(state.query, { get: 't_42', filter: 'all', search: 'RSS', state: 96 });
	assert.equal(new URL(f.location.href).searchParams.get('state'), '96');
	f.navigation.openArticle('100', state);
	f.flush(-1);
	assert.equal(f.restored.at(-1).state.query.state, 96);
	const reload = new ReaderNavigation({ history: f.history, location: f.location, onRestore() {} });
	assert.equal(reload.start(initial()).query.state, 96);
	f.navigation.replace({ ...state, query: { get: 't_42', filter: 'unread', search: 'RSS' } });
	assert.equal(new URL(f.location.href).searchParams.has('state'), false);
	assert.equal(new URL(f.location.href).searchParams.get('filter'), 'unread');
});

test('a supported filter overrides an inherited legacy state on a shared URL', () => {
	const { navigation } = fixture('https://reader.test/i/?filter=unread&state=96');
	const state = navigation.start({ ...initial(), query: { ...initial().query, state: 96 } });
	assert.deepEqual(state.query, { get: 'a', filter: 'unread', search: '' });
});

for (const [state, filter] of [[2, 'unread'], [3, 'all'], [4, 'starred']]) {
	test(`legacy state ${state} uses the visible ${filter} filter`, () => {
		const f = fixture('https://reader.test/i/?state=' + state);
		assert.deepEqual(f.navigation.start(initial()).query, { get: 'a', filter, search: '' });
		assert.equal(new URL(f.location.href).searchParams.has('state'), false);
	});
}

test('arbitrary and overflowing states are not retained in navigation or outgoing URLs', () => {
	for (const state of [16, -1, 0, 112, 4294967297, '1', true]) {
		const f = fixture();
		assert.equal(f.navigation.start({ ...initial(), query: { ...initial().query, state } }).query.state, undefined);
		assert.equal(new URL(f.location.href).searchParams.has('state'), false);
	}
});

test('choosing all overrides the account default in a reloadable and shareable URL', () => {
	for (const query of [{ get: 'a', filter: 'unread', search: '' }, { get: 'a', filter: 'all', search: '', state: 96 }]) {
		const f = fixture('https://reader.test/i/');
		f.navigation.start({ ...initial(), query });
		f.navigation.replace(initial());
		assert.equal(new URL(f.location.href).searchParams.get('filter'), 'all');
		assert.equal(new URL(f.location.href).searchParams.has('state'), false);
		const shared = fixture(f.location.href);
		assert.equal(shared.navigation.start({ ...initial(), query }).query.filter, 'all');
		assert.equal(shared.navigation.current.snapshot.query.state, undefined);
		assert.equal(new URL(shared.location.href).searchParams.get('filter'), 'all');
	}
});

test('compound reading state URLs remain unambiguous after sharing without browser history', () => {
	const f = fixture('https://reader.test/i/?state=96');
	f.navigation.start({ ...initial(), query: { ...initial().query, state: 96 } });
	assert.equal(new URL(f.location.href).searchParams.has('filter'), false);
	const shared = fixture(f.location.href);
	assert.equal(shared.navigation.start(initial()).query.state, 96);
});

test('rapid reading coalesces history writes and preserves the final article and list extent', () => {
	const { navigation, writes, history, location } = fixture();
	navigation.start(initial());
	for (let index = 0; index < 150; index++) {
		navigation.openArticle(String(index + 1), { ...initial(), loadedCount: 200, listScroll: 1200 });
	}
	assert.equal(navigation.current.snapshot.selectedId, '150'); assert.equal(history.length, 2);
	assert.ok(writes.length < 40, 'A reading burst must not consume the browser history quota.');
	navigation.flushWrite();
	assert.equal(new URL(location.href).searchParams.get('article'), '150');
	assert.equal(history.state.freshrssReader.snapshot.loadedCount, 200);
	assert.equal(navigation.pendingWrite, null);
});

test('native Back before a delayed write preserves the latest position for Forward', () => {
	const { navigation, restored, flush } = fixture();
	navigation.start(initial());
	for (let index = 0; index < 120; index++) navigation.openArticle(String(index + 1), { ...initial(), loadedCount: 150, listScroll: 720 });
	assert.ok(navigation.pendingWrite);
	flush(-1);
	assert.equal(restored.at(-1).state.selectedId, null); assert.equal(restored.at(-1).state.loadedCount, 150);
	flush(1);
	assert.equal(restored.at(-1).state.selectedId, '120'); assert.equal(restored.at(-1).state.listScroll, 720);
	navigation.flushWrite();
});

test('reload recovers the last reading position when the browser discards history state before a coalesced URL write', () => {
	const f = fixture();
	const values = new Map();
	const storage = { getItem: key => values.get(key), setItem: (key, value) => values.set(key, value) };
	const navigation = new ReaderNavigation({ history: f.history, location: f.location, storage, onRestore() {} });
	navigation.start(initial());
	for (let index = 0; index < 120; index++) {
		navigation.openArticle(String(index + 1), { ...initial(), loadedCount: 150, content: 'must not persist' });
	}
	assert.ok(navigation.pendingWrite);
	navigation.cancelWrite(); f.entries.at(-1).state = null;
	const reloaded = new ReaderNavigation({ history: f.history, location: f.location, storage, restoreStorage: true, onRestore() {} });
	const value = reloaded.start(initial());
	assert.equal(value.selectedId, '120'); assert.equal(value.loadedCount, 150);
	assert.equal(value.query.filter, 'all'); assert.equal(f.history.length, 2);
	assert.ok([...values.values()].every(value => !value.includes('must not persist')));
	f.entries.at(-1).state = null; f.location.href = 'https://reader.test/i/';
	const back = new ReaderNavigation({ history: f.history, location: f.location, storage, restoreStorage: 'back_forward', onRestore() {} });
	const list = back.start(initial());
	assert.equal(list.selectedId, null); assert.equal(list.loadedCount, 150); assert.equal(list.listAnchor, '120');
	f.entries.at(-1).state = null; f.location.href = 'https://reader.test/i/?article=120';
	const forward = new ReaderNavigation({ history: f.history, location: f.location, storage, restoreStorage: 'back_forward', onRestore() {} });
	assert.equal(forward.start(initial()).selectedId, '120');
});

test('unavailable tab storage cannot prevent reading or add content to history', () => {
	const f = fixture();
	const storage = { getItem() { throw new Error('unavailable'); }, setItem() { throw new Error('quota'); } };
	const navigation = new ReaderNavigation({ history: f.history, location: f.location, storage, restoreStorage: true, onRestore() {} });
	navigation.start(initial()); navigation.openArticle('120', initial());
	assert.equal(navigation.current.snapshot.selectedId, '120');
});
