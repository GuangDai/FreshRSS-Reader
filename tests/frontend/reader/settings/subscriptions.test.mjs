import { test } from 'node:test';
import assert from 'node:assert/strict';
import { SettingsSubscriptions } from '../../../../p/scripts/reader/settings/subscriptions.js';
import { FeedSelection } from '../../../../p/scripts/reader/subscriptions/batch-update.js';

class Element extends EventTarget {
	constructor(tag, className = '', text = '') {
		super(); Object.assign(this, { tag, className, textContent: text, children: [], dataset: {}, attributes: {}, hidden: false, value: '' });
	}

	setAttribute(name, value) { this.attributes[name] = value; }
	getAttribute(name) { return this.attributes[name]; }
	append(...nodes) { for (const node of nodes) this.insertBefore(node, null); }
	insertBefore(node, before) {
		node.remove(); node.parent = this;
		this.children.splice(before ? this.children.indexOf(before) : this.children.length, 0, node);
	}

	remove() { if (this.parent) this.parent.children.splice(this.parent.children.indexOf(this), 1); this.parent = null; }
	replaceChildren(...nodes) { for (const child of [...this.children]) child.remove(); this.append(...nodes); }
	before(node) { this.parent.insertBefore(node, this); }
	querySelector(selector) {
		if (selector === '#' + this.id) return this;
		for (const child of this.children) { const found = child.querySelector(selector); if (found) return found; }
		return null;
	}

	closest(selector) {
		const match = selector.split(', ').some(part => (part === '[data-select-feed]' && Object.hasOwn(this.dataset, 'selectFeed')) ||
			(part === '[data-settings-feed]' && Object.hasOwn(this.dataset, 'settingsFeed')) ||
			(part === '[data-select-feed-target]' && Object.hasOwn(this.dataset, 'selectFeedTarget')));
		return match ? this : this.parent?.closest(selector) || null;
	}

	focus() { this.focused = true; }
	scrollIntoView() { this.scrolled = true; }
}

const element = (...args) => new Element(...args);
const feeds = [
	{ id: 1, name: 'Daily News', categoryId: 1, priority: 10, ttl: 3600, enabled: true, unread: 3, error: false, version: 'one' },
	{ id: 2, name: 'Design Notes', categoryId: 2, priority: 0, ttl: 0, enabled: false, unread: 0, error: true, version: 'two' },
	{
		id: 3,
		name: 'Design Journal',
		categoryId: 2,
		priority: -10,
		ttl: 600,
		enabled: true,
		unread: 1,
		error: true,
		version: 'three',
		url: 'https://journal.example/rss'
	},
	{ id: 4, name: 'Coding', categoryId: 1, priority: 20, ttl: 86400, enabled: true, unread: 0, error: false, version: 'four' },
];

function fixture(options = {}) {
	const root = element('main');
	for (const [tag, id] of [['input', 'settings-feed-search'], ['select', 'settings-feed-filter'], ['select', 'settings-feed-category'],
		['div', 'settings-feed-list'], ['p', 'settings-feeds-empty']]) {
		const node = element(tag); node.id = id; root.append(node);
	}
	root.querySelector('#settings-feed-filter').value = 'all';
	const store = { feeds: feeds.map(feed => ({ ...feed })), categories: [{ id: 1, name: 'News' }, { id: 2, name: 'Design' }], feedRevision: 1 };
	const selection = new FeedSelection(); selection.activate(true);
	const opened = []; let statuses = 0;
	const subscriptions = new SettingsSubscriptions({ root, store, selection, element, onOpenFeed: id => opened.push(id), onStatus: () => statuses++, ...options });
	subscriptions.mount();
	return { root, store, selection, subscriptions, opened, statuses: () => statuses };
}

function key(target, key, modifiers = {}) {
	return { target, key, preventDefault() { this.prevented = true; }, stopPropagation() { this.stopped = true; }, ...modifiers };
}

test('subscription rows expose real status and editable names without a selection mode', () => {
	const { subscriptions, root } = fixture();
	assert.deepEqual(subscriptions.matchingIds(), [1, 2, 3, 4]);
	const paused = subscriptions.nodes.get(2);
	assert.equal(paused.checkbox.hidden, false);
	assert.equal(paused.checkbox.getAttribute('aria-label'), '选择 Design Notes');
	assert.equal(paused.meta.textContent, 'Design · 已暂停 · 抓取失败 · 仅分类内显示');
	assert.equal(paused.button.getAttribute('aria-describedby'), 'settings-feed-meta-2');
	assert.equal(subscriptions.nodes.get(1).meta.textContent, 'News · 3 篇未读 · 每 1 小时更新');
	assert.equal(subscriptions.nodes.get(3).row.dataset.error, 'true');
	assert.equal(root.querySelector('#settings-feed-summary').textContent, '显示 4 / 4 个订阅');
});

test('name, optional URL, category and status filters compose without modifying selections', () => {
	const { subscriptions, selection } = fixture();
	selection.replace([1, 3]);
	subscriptions.search.value = 'DESIGN journal.example'; subscriptions.filter();
	assert.deepEqual(subscriptions.matchingIds(), [3]);
	subscriptions.search.value = 'design'; subscriptions.categoryFilter.value = '2'; subscriptions.statusFilter.value = 'paused'; subscriptions.filter();
	assert.deepEqual(subscriptions.matchingIds(), [2]);
	assert.deepEqual([...selection.ids], [1, 3]);
	subscriptions.statusFilter.value = 'enabled'; subscriptions.filter(); assert.deepEqual(subscriptions.matchingIds(), [3]);
	subscriptions.search.value = 'unknown'; subscriptions.filter();
	assert.equal(subscriptions.empty.hidden, false); assert.equal(subscriptions.empty.textContent, '没有符合筛选条件的订阅。');
});

test('unrelated refreshes preserve rows, focused inputs, filters and scroll while updating counts', () => {
	const { subscriptions, store } = fixture();
	const original = subscriptions.nodes.get(3);
	subscriptions.search.value = 'Design'; subscriptions.search.focus(); subscriptions.categoryFilter.value = '2'; subscriptions.filter();
	subscriptions.list.scrollTop = 300;
	store.feeds = store.feeds.map(feed => feed.id === 3 ? { ...feed, unread: 7 } : { ...feed }); store.feedRevision++;
	subscriptions.update();
	assert.equal(subscriptions.nodes.get(3), original); assert.equal(subscriptions.list.scrollTop, 300);
	assert.equal(subscriptions.search.value, 'Design'); assert.equal(subscriptions.search.focused, true);
	assert.equal(subscriptions.categoryFilter.value, '2'); assert.deepEqual(subscriptions.matchingIds(), [2, 3]);
	assert.match(original.meta.textContent, /7 篇未读/);
	store.feeds[2].unread = 8; store.feedRevision++; subscriptions.update(); assert.match(original.meta.textContent, /8 篇未读/);
});

test('clicking a feed opens its configuration and Shift selection follows the current filtered order', () => {
	const { subscriptions, selection, opened } = fixture();
	subscriptions.search.value = 'Design'; subscriptions.filter();
	const first = subscriptions.nodes.get(2); const second = subscriptions.nodes.get(3);
	first.checkbox.checked = true; subscriptions.click({ target: first.checkbox });
	second.checkbox.checked = true; subscriptions.click({ target: second.checkbox, shiftKey: true });
	assert.deepEqual([...selection.ids], [2, 3]);
	subscriptions.click({ target: first.button }); assert.deepEqual(opened, [2]);
	const forged = element('button'); forged.dataset.settingsFeed = '9007199254740993'; subscriptions.click({ target: forged });
	assert.deepEqual(opened, [2]);
	first.checkbox.checked = false; subscriptions.change({ target: first.checkbox }); assert.deepEqual([...selection.ids], [3]);
});

test('the enlarged label leaves toggling to its native checkbox activation without emitting a second selection change', () => {
	const { subscriptions, selection } = fixture();
	const node = subscriptions.nodes.get(2);
	assert.equal(node.target.tag, 'label'); assert.equal(node.target.className, 'settings-feed-select-target');
	assert.equal(node.checkbox.parent, node.target);
	let changes = 0; selection.addEventListener('change', () => changes++);
	subscriptions.click({ target: node.target }); assert.equal(changes, 0);
	node.checkbox.checked = true; subscriptions.click({ target: node.checkbox }); subscriptions.change({ target: node.checkbox });
	assert.deepEqual([...selection.ids], [2]); assert.equal(changes, 1);
	subscriptions.click({ target: node.target }); node.checkbox.checked = false;
	subscriptions.click({ target: node.checkbox }); subscriptions.change({ target: node.checkbox });
	assert.equal(selection.ids.size, 0); assert.equal(changes, 2);
});

test('Shift-clicking label padding activates the filtered range once and suppresses a native second toggle', () => {
	const { subscriptions, selection } = fixture();
	subscriptions.search.value = 'Design'; subscriptions.filter();
	selection.set(2, true, subscriptions.matchingIds());
	let changes = 0; selection.addEventListener('change', () => changes++);
	const event = { target: subscriptions.nodes.get(3).target, shiftKey: true, preventDefault() { this.prevented = true; } };
	subscriptions.click(event);
	assert.equal(event.prevented, true); assert.deepEqual([...selection.ids], [2, 3]); assert.equal(changes, 1);
	assert.equal(subscriptions.nodes.get(3).checkbox.focused, true);
});

test('keyboard selection and movement stay within visible subscriptions and preserve control columns', () => {
	const { subscriptions, selection } = fixture();
	subscriptions.search.value = 'Design'; subscriptions.filter();
	const first = subscriptions.nodes.get(2); const second = subscriptions.nodes.get(3);
	const selectAll = key(first.checkbox, 'a', { ctrlKey: true }); subscriptions.keydown(selectAll);
	assert.deepEqual([...selection.ids], [2, 3]); assert.equal(selectAll.prevented, true); assert.equal(selectAll.stopped, true);
	selection.clear(); subscriptions.keydown(key(first.checkbox, 'ArrowDown', { shiftKey: true }));
	assert.deepEqual([...selection.ids], [2, 3]); assert.equal(second.checkbox.focused, true);
	subscriptions.keydown(key(first.button, 'End')); assert.equal(second.button.focused, true);
	subscriptions.keydown(key(second.button, 'Home')); assert.equal(first.button.focused, true);
	selection.clear(); const searchKey = key(subscriptions.search, 'a', { ctrlKey: true }); subscriptions.keydown(searchKey);
	assert.equal(selection.ids.size, 0); assert.equal(searchKey.prevented, undefined);
});

test('invalid IDs never enter the list and removed categories reset only the invalid category filter', () => {
	const { subscriptions, store } = fixture();
	subscriptions.search.value = 'Design'; subscriptions.categoryFilter.value = '2'; subscriptions.filter();
	store.feeds = [store.feeds[2], { ...store.feeds[2] }, { id: '3', name: 'String ID' }, { id: NaN }, { id: 0 }];
	store.categories = [{ id: 1, name: 'News' }]; store.feedRevision++;
	subscriptions.update();
	assert.deepEqual(subscriptions.orderedIds, [3]); assert.equal(subscriptions.nodes.size, 1);
	assert.equal(subscriptions.categoryFilter.value, ''); assert.equal(subscriptions.search.value, 'Design');
	assert.equal(subscriptions.nodes.get(3).meta.textContent.startsWith('未分类'), true);
});

test('the empty account message differs from an unmatched filter and uses the requested language', () => {
	const { subscriptions, store } = fixture({ language: 'en' });
	store.feeds = []; store.feedRevision++; subscriptions.update();
	assert.equal(subscriptions.empty.textContent, 'No subscriptions yet. Add a subscription or import OPML to manage it here.');
	assert.equal(subscriptions.summary.textContent, 'Showing 0 of 0 subscriptions');
});

test('mounting is idempotent and disposal detaches selection and filter listeners', () => {
	const { subscriptions, selection, statuses } = fixture();
	subscriptions.mount(); const before = statuses(); selection.replace([1]); assert.equal(statuses(), before + 1);
	assert.equal(subscriptions.nodes.get(1).checkbox.checked, true);
	subscriptions.dispose(); const detached = statuses(); selection.clear();
	subscriptions.search.value = 'Design'; subscriptions.search.dispatchEvent(new Event('input'));
	assert.equal(statuses(), detached); assert.deepEqual(subscriptions.matchingIds(), [1, 2, 3, 4]);
});
