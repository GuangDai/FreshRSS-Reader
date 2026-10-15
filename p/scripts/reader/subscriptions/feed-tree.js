/** Keep subscription nodes stable across count refreshes and unrelated article changes. */
export class FeedTree {
	constructor(root, element, { errorLabel = 'Feed fetch failed' } = {}) {
		this.root = root;
		this.element = element;
		this.errorLabel = errorLabel;
		this.nodes = new Map();
		this.groups = new Map();
		this.feedsById = new Map();
		this.unread = 0;
		this.search = '';
		this.nodeRevision = 0;
		this.orderedIds = [];
		this.filteredIds = [];
	}

	update(feeds, categories, revision) {
		if (this.feeds === feeds && this.categories === categories && this.revision === revision) return;
		const structureChanged = this.feeds !== feeds || this.categories !== categories;
		if (structureChanged) this.nodeRevision++;
		this.feeds = feeds; this.categories = categories; this.revision = revision;
		this.feedsById = new Map(feeds.map(feed => [feed.id, feed]));
		const categoryIds = new Set(categories.map(category => category.id));
		for (const [id, node] of this.nodes) {
			if (!this.feedsById.has(id) || !categoryIds.has(this.feedsById.get(id).categoryId)) {
				node.row.remove(); this.nodes.delete(id);
			}
		}
		for (const [id, group] of this.groups) {
			if (!categoryIds.has(id)) { group.root.remove(); this.groups.delete(id); }
		}
		const byCategory = new Map();
		this.unread = 0;
		this.orderedIds = [];
		for (const feed of feeds) {
			this.unread += feed.unread;
			if (!byCategory.has(feed.categoryId)) byCategory.set(feed.categoryId, []);
			byCategory.get(feed.categoryId).push(feed);
		}
		for (const [index, category] of categories.entries()) {
			let group = this.groups.get(category.id);
			if (!group) {
				const root = this.element('details', 'feed-group'); root.open = true;
				const title = this.element('summary', '', category.name);
				root.append(title);
				group = { root, title, visible: 0 };
				this.groups.set(category.id, group);
			}
			if (group.title.textContent !== category.name) group.title.textContent = category.name;
			if (this.root.children[index] !== group.root) this.root.insertBefore(group.root, this.root.children[index] || null);
			for (const [feedIndex, feed] of (byCategory.get(category.id) || []).entries()) {
				this.orderedIds.push(feed.id);
				let node = this.nodes.get(feed.id);
				if (!node) {
					const row = this.element('div', 'feed-row');
					const checkbox = this.element('input', 'feed-select'); checkbox.type = 'checkbox'; checkbox.hidden = true;
					checkbox.dataset.selectFeed = feed.id;
					const button = this.element('button', 'feed-button'); button.type = 'button'; button.dataset.feed = feed.id;
					button.setAttribute('aria-current', 'false');
					const dot = this.element('span', 'feed-dot', '●'); dot.setAttribute('aria-hidden', 'true');
					const count = this.element('span', 'count');
					const name = this.element('span', 'feed-name', feed.name);
					const error = this.element('span', 'feed-error-label sr-only', this.errorLabel); error.hidden = true;
					button.append(dot, name, error, count);
					row.append(checkbox, button);
					node = { row, checkbox, button, dot, count, name, error };
					this.nodes.set(feed.id, node);
				}
				if (node.name.textContent !== feed.name) node.name.textContent = feed.name;
				node.searchName = feed.name.toLocaleLowerCase(); node.categoryId = feed.categoryId;
				const count = feed.unread ? String(feed.unread) : '';
				if (node.count.textContent !== count) node.count.textContent = count;
				if (node.dot.dataset.error !== String(feed.error)) node.dot.dataset.error = feed.error;
				const marker = feed.error ? '▲' : '●';
				if (node.dot.textContent !== marker) node.dot.textContent = marker;
				if (node.error.hidden === Boolean(feed.error)) node.error.hidden = !feed.error;
				const title = feed.error ? `${feed.name} · ${this.errorLabel}` : feed.name;
				if (node.button.title !== title) node.button.title = title;
				if (group.root.children[feedIndex + 1] !== node.row) group.root.insertBefore(node.row, group.root.children[feedIndex + 1] || null);
			}
		}
		if (structureChanged) this.filter(this.search, true);
	}

	select(stream) {
		const node = stream.startsWith('f_') ? this.nodes.get(Number(stream.slice(2))) : null;
		if (node === this.selected) return;
		this.selected?.button.setAttribute('aria-current', 'false');
		node?.button.setAttribute('aria-current', 'page');
		this.selected = node;
	}

	selection(selection, label) {
		if (this.selectionRevision === selection.revision && this.selectionNodeRevision === this.nodeRevision) return;
		this.selectionRevision = selection.revision; this.selectionNodeRevision = this.nodeRevision;
		for (const [id, node] of this.nodes) {
			if (node.checkbox.hidden === selection.active) node.checkbox.hidden = !selection.active;
			if (node.checkbox.checked !== selection.ids.has(id)) node.checkbox.checked = selection.ids.has(id);
			const name = label.replace('{name}', node.name.textContent);
			if (node.checkbox.getAttribute('aria-label') !== name) node.checkbox.setAttribute('aria-label', name);
		}
	}

	matchingIds() { return this.filteredIds; }

	filter(value, force = false) {
		value = value.trim().toLocaleLowerCase();
		if (value === this.search && !force) return;
		const wasSearching = Boolean(this.search);
		this.search = value;
		for (const group of this.groups.values()) group.visible = 0;
		for (const node of this.nodes.values()) {
			const hidden = !node.searchName.includes(value);
			if (node.row.hidden !== hidden) node.row.hidden = hidden;
			if (!hidden) this.groups.get(node.categoryId).visible++;
		}
		for (const group of this.groups.values()) {
			if (group.root.hidden !== !group.visible) group.root.hidden = !group.visible;
			if (value) {
				if (!wasSearching || group.beforeSearch === undefined) group.beforeSearch = group.root.open;
				if (group.visible && !group.root.open) group.root.open = true;
			} else if (group.beforeSearch !== undefined) {
				group.root.open = group.beforeSearch; delete group.beforeSearch;
			}
		}
		this.filteredIds = this.orderedIds.filter(id => !this.nodes.get(id).row.hidden);
	}
}
