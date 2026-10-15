import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FeedTree } from '../../../../p/scripts/reader/subscriptions/feed-tree.js';

function element(tag, className = '', text = '') {
	return {
		tag,
		className,
		textContent: text,
		children: [],
		dataset: {},
		attributes: {},
		hidden: false,
		setAttribute(name, value) { this.attributes[name] = value; },
		getAttribute(name) { return this.attributes[name]; },
		append(...children) { for (const child of children) this.insertBefore(child, null); },
		insertBefore(child, before) {
			child.remove(); child.parent = this;
			this.children.splice(before ? this.children.indexOf(before) : this.children.length, 0, child);
		},
		remove() {
			if (this.parent) this.parent.children.splice(this.parent.children.indexOf(this), 1);
			this.parent = null;
		},
	};
}

test('a subscription failure remains understandable through refreshes and clears when fetching recovers', () => {
	const tree = new FeedTree(element('nav'), element, { errorLabel: '抓取失败' });
	const categories = [{ id: 1, name: '订阅' }];
	const original = { id: 1, name: '示例订阅', categoryId: 1, unread: 2, error: true };
	tree.update([original], categories, 1);
	const node = tree.nodes.get(1);
	assert.equal(node.dot.textContent, '▲');
	assert.equal(node.dot.getAttribute('aria-hidden'), 'true');
	assert.equal(node.error.hidden, false);
	assert.equal(node.error.textContent, '抓取失败');
	assert.equal(node.button.title, '示例订阅 · 抓取失败');

	tree.update([{ ...original, name: '重命名订阅', unread: 0 }], categories, 2);
	assert.equal(tree.nodes.get(1).button, node.button);
	assert.equal(node.button.title, '重命名订阅 · 抓取失败');
	assert.equal(node.error.hidden, false);
	tree.select('f_1');

	tree.update([{ ...original, name: '重命名订阅', error: false }], categories, 3);
	assert.equal(tree.nodes.get(1).button, node.button);
	assert.equal(node.button.getAttribute('aria-current'), 'page');
	assert.equal(node.dot.textContent, '●');
	assert.equal(node.error.hidden, true);
	assert.equal(node.button.title, '重命名订阅');
});

test('categories start collapsed with independent native controls and a total unread count', () => {
	const tree = new FeedTree(element('nav'), element, {
		expandLabel: '展开{name}', collapseLabel: '收起{name}', allLabel: '查看{name}的全部文章', unreadLabel: '{name}：{count} 篇未读', locale: 'zh-CN',
	});
	const categories = [{ id: 1, name: '设计' }, { id: 2, name: '技术' }];
	tree.update([
		{ id: 1, name: '设计随笔', categoryId: 1, unread: 7, error: false },
		{ id: 2, name: '字体观察', categoryId: 1, unread: 5, error: false },
		{ id: 3, name: '开发日志', categoryId: 2, unread: 0, error: false },
	], categories, 1);
	const group = tree.groups.get(1); const other = tree.groups.get(2);
	assert.equal(group.root.tag, 'div'); assert.deepEqual(group.header.children, [group.toggle, group.title, group.count]);
	assert.equal(group.toggle.dataset.toggleCategory, 1); assert.equal(group.title.dataset.category, 1);
	assert.equal(group.count.dataset.categoryUnread, 1); assert.equal(group.toggle.getAttribute('aria-controls'), group.feeds.id);
	assert.equal(group.expanded, false); assert.equal(group.feeds.hidden, true); assert.equal(group.toggle.getAttribute('aria-expanded'), 'false');
	assert.equal(group.toggle.getAttribute('aria-label'), '展开设计'); assert.equal(group.title.getAttribute('aria-label'), '查看设计的全部文章');
	assert.equal(group.unread, 12); assert.equal(group.count.textContent, '12'); assert.equal(group.count.getAttribute('aria-label'), '设计：12 篇未读');
	assert.equal(other.count.textContent, '0'); assert.equal(tree.unread, 12);
	tree.toggle('1');
	assert.equal(group.expanded, true); assert.equal(group.feeds.hidden, false); assert.equal(group.toggle.getAttribute('aria-expanded'), 'true');
	assert.equal(group.toggle.getAttribute('aria-label'), '收起设计'); assert.equal(other.expanded, false);
	tree.toggle(1); assert.equal(group.expanded, false); assert.equal(group.feeds.hidden, true);
});

test('category and unread-stream selection highlight separate controls without expanding subscriptions', () => {
	const tree = new FeedTree(element('nav'), element);
	tree.update([{ id: 1, name: 'Journal', categoryId: 1, unread: 2, error: false }], [{ id: 1, name: 'Reading' }], 1);
	const group = tree.groups.get(1); const feed = tree.nodes.get(1);
	tree.select('c_1');
	assert.equal(group.title.getAttribute('aria-current'), 'page'); assert.equal(group.count.getAttribute('aria-current'), 'false');
	assert.equal(group.expanded, false);
	tree.select('c_1', 'unread');
	assert.equal(group.title.getAttribute('aria-current'), 'false'); assert.equal(group.count.getAttribute('aria-current'), 'page');
	assert.equal(group.expanded, false);
	tree.select('f_1');
	assert.equal(group.count.getAttribute('aria-current'), 'false'); assert.equal(feed.button.getAttribute('aria-current'), 'page');
	assert.equal(group.expanded, false);
	tree.select('a'); assert.equal(feed.button.getAttribute('aria-current'), 'false');
});

test('count refreshes preserve category, feed and selected-control identities and manual expansion', () => {
	const tree = new FeedTree(element('nav'), element);
	const categories = [{ id: 1, name: 'Reading' }];
	const feeds = [
		{ id: 1, name: 'Journal', categoryId: 1, unread: 7, error: false },
		{ id: 2, name: 'Notes', categoryId: 1, unread: 5, error: false },
	];
	tree.update(feeds, categories, 1); tree.toggle(1); tree.select('c_1', 'unread');
	const group = tree.groups.get(1); const button = group.count; const feed = tree.nodes.get(1);
	feeds[0].unread = 0; feeds[1].unread = 1;
	tree.update(feeds, categories, 2);
	assert.equal(tree.groups.get(1), group); assert.equal(group.count, button); assert.equal(tree.nodes.get(1), feed);
	assert.equal(group.count.getAttribute('aria-current'), 'page'); assert.equal(group.count.textContent, '1'); assert.equal(group.unread, 1);
	assert.equal(group.expanded, true); assert.equal(group.feeds.hidden, false); assert.equal(feed.row.parent, group.feeds);
	categories[0].name = 'Renamed'; tree.update([...feeds], categories, 3);
	assert.equal(group.toggle.getAttribute('aria-label'), 'Collapse Renamed'); assert.equal(group.title.textContent, 'Renamed');
	assert.equal(group.count.getAttribute('aria-label'), 'Renamed: 1 unread articles'); assert.equal(group.expanded, true);
});

test('search temporarily reveals matching subscriptions without reducing totals and restores manual expansion', () => {
	const tree = new FeedTree(element('nav'), element);
	const categories = [{ id: 1, name: 'Reading' }, { id: 2, name: 'Work' }];
	const feeds = [
		{ id: 1, name: 'Alpha journal', categoryId: 1, unread: 7, error: false },
		{ id: 2, name: 'Beta notes', categoryId: 1, unread: 5, error: false },
		{ id: 3, name: 'Work log', categoryId: 2, unread: 3, error: false },
	];
	tree.update(feeds, categories, 1); tree.toggle(2);
	const reading = tree.groups.get(1); const work = tree.groups.get(2);
	tree.filter('Alpha');
	assert.equal(reading.expanded, true); assert.equal(work.root.hidden, true); assert.equal(reading.unread, 12);
	assert.equal(reading.count.textContent, '12'); assert.deepEqual(tree.matchingIds(), [1]); assert.equal(tree.nodes.get(2).row.hidden, true);
	tree.toggle(1); tree.update([...feeds], categories, 2);
	assert.equal(reading.expanded, false, 'A count refresh must not reopen a group manually collapsed during search.');
	const added = { id: 4, name: 'Alpha satellite', categoryId: 3, unread: 2, error: false };
	tree.update([...feeds, added], [...categories, { id: 3, name: 'New' }], 3);
	assert.equal(tree.groups.get(3).expanded, true);
	tree.filter('');
	assert.equal(reading.expanded, false); assert.equal(work.expanded, true); assert.equal(work.root.hidden, false);
	assert.equal(tree.groups.get(3).expanded, false, 'New search results restore their original collapsed state.');
	assert.equal(tree.nodes.get(2).row.hidden, false); assert.deepEqual(tree.matchingIds(), [1, 2, 3, 4]);
});

test('category unread totals match category-visible priorities and retain paused subscriptions', () => {
	const tree = new FeedTree(element('nav'), element);
	const feeds = [
		{ priority: -10, unread: 50 }, { priority: -5, unread: 70 },
		{ priority: 0, unread: 3, enabled: false, ttl: 0 }, { priority: 10, unread: 5 }, { priority: 20, unread: 7 }, { unread: 11 },
	].map((feed, index) => ({ id: index + 1, name: 'Feed ' + index, categoryId: 1, error: false, ...feed }));
	tree.update(feeds, [{ id: 1, name: 'Reading' }], 1);
	assert.equal(tree.groups.get(1).unread, 26); assert.equal(tree.groups.get(1).count.textContent, '26');
	assert.equal(tree.unread, 146, 'The existing global unread calculation remains unchanged.');
	tree.filter('Feed 0'); assert.equal(tree.groups.get(1).unread, 26);
});

test('large category counts retain exact accessible totals while the narrow button uses compact text', () => {
	const tree = new FeedTree(element('nav'), element, { locale: 'en-US' });
	const feeds = [{ id: 1, name: 'Journal', categoryId: 1, unread: 100000, error: false }];
	tree.update(feeds, [{ id: 1, name: 'Reading' }], 1);
	const group = tree.groups.get(1);
	assert.equal(group.unread, 100000); assert.equal(group.count.textContent, '100K');
	assert.equal(group.count.getAttribute('aria-label'), 'Reading: 100,000 unread articles'); assert.equal(group.count.title, 'Reading: 100,000 unread articles');
	feeds[0].unread = 0; tree.update(feeds, tree.categories, 2);
	assert.equal(group.unread, 0); assert.equal(group.count.textContent, '0');
});

test('large Chinese category counts keep the unit visible and retain the exact accessible number', () => {
	const tree = new FeedTree(element('nav'), element, { locale: 'zh-CN', unreadLabel: '{name}：{count} 篇未读' });
	tree.update([{ id: 1, name: '日报', categoryId: 1, unread: 1012345, error: false }], [{ id: 1, name: '阅读' }], 1);
	const group = tree.groups.get(1);
	assert.equal(group.unread, 1012345); assert.equal(group.count.textContent, '101万');
	assert.equal(group.count.getAttribute('aria-label'), '阅读：1,012,345 篇未读'); assert.equal(group.count.title, '阅读：1,012,345 篇未读');
});
