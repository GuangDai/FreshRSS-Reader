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
