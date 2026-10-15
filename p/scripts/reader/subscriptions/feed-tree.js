/** Keep subscription nodes stable across count refreshes and unrelated article changes. */
export class FeedTree {
	constructor(root, element, {
		errorLabel = 'Feed fetch failed', expandLabel = 'Expand {name}', collapseLabel = 'Collapse {name}',
		allLabel = 'All articles in {name}', unreadLabel = '{name}: {count} unread articles', locale = 'en',
	} = {}) {
		this.root = root;
		this.element = element;
		this.errorLabel = errorLabel;
		Object.assign(this, { expandLabel, collapseLabel, allLabel, unreadLabel });
		this.countFormat = new Intl.NumberFormat(locale);
		this.compactCountFormat = new Intl.NumberFormat(locale, { notation: 'compact', maximumFractionDigits: 0 });
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
				const root = this.element('div', 'feed-group');
				const header = this.element('div', 'feed-group-header');
				const toggle = this.element('button', 'feed-group-toggle'); toggle.type = 'button'; toggle.dataset.toggleCategory = category.id;
				const arrow = this.element('span', 'reader-icon icon-next'); arrow.setAttribute('aria-hidden', 'true'); toggle.append(arrow);
				const title = this.element('button', 'feed-group-name', category.name); title.type = 'button'; title.dataset.category = category.id;
				title.setAttribute('aria-current', 'false');
				const count = this.element('button', 'feed-group-unread count'); count.type = 'button'; count.dataset.categoryUnread = category.id;
				count.setAttribute('aria-current', 'false');
				const feeds = this.element('div', 'feed-group-feeds'); feeds.id = `reader-category-${category.id}-feeds`;
				toggle.setAttribute('aria-controls', feeds.id); header.append(toggle, title, count); root.append(header, feeds);
				group = { root, header, toggle, title, count, feeds, expanded: false, visible: 0, unread: 0 };
				this.groups.set(category.id, group);
			}
			group.name = category.name;
			if (group.title.textContent !== category.name) group.title.textContent = category.name;
			const allLabel = this.allLabel.replace('{name}', category.name);
			if (group.title.getAttribute('aria-label') !== allLabel) group.title.setAttribute('aria-label', allLabel);
			group.title.title = allLabel;
			group.unread = (byCategory.get(category.id) || []).reduce((total, feed) => total + ((feed.priority ?? 10) >= 0 ? feed.unread : 0), 0);
			const fullCount = this.countFormat.format(group.unread);
			const count = group.unread >= 100000 ? this.compactCountFormat.format(group.unread) : fullCount;
			if (group.count.textContent !== count) group.count.textContent = count;
			const unreadLabel = this.unreadLabel.replace('{name}', category.name).replace('{count}', fullCount);
			if (group.count.getAttribute('aria-label') !== unreadLabel) group.count.setAttribute('aria-label', unreadLabel);
			group.count.title = unreadLabel;
			this.setExpanded(category.id, group.expanded);
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
				if (group.feeds.children[feedIndex] !== node.row) group.feeds.insertBefore(node.row, group.feeds.children[feedIndex] || null);
			}
		}
		if (structureChanged) this.filter(this.search, true);
	}

	setExpanded(id, expanded) {
		const group = this.groups.get(Number(id)); if (!group) return;
		group.expanded = Boolean(expanded); group.feeds.hidden = !group.expanded;
		const value = String(group.expanded);
		if (group.root.dataset.expanded !== value) group.root.dataset.expanded = value;
		if (group.toggle.getAttribute('aria-expanded') !== value) group.toggle.setAttribute('aria-expanded', value);
		const label = (group.expanded ? this.collapseLabel : this.expandLabel).replace('{name}', group.name);
		if (group.toggle.getAttribute('aria-label') !== label) group.toggle.setAttribute('aria-label', label);
	}

	toggle(id) {
		const group = this.groups.get(Number(id)); if (group) this.setExpanded(id, !group.expanded);
	}

	select(stream, filter = 'all') {
		const node = stream.startsWith('f_') ? this.nodes.get(Number(stream.slice(2))) : null;
		const category = stream.startsWith('c_') ? this.groups.get(Number(stream.slice(2))) : null;
		const button = node?.button || (category ? filter === 'unread' ? category.count : category.title : null);
		if (button === this.selectedButton) return;
		this.selectedButton?.setAttribute('aria-current', 'false'); button?.setAttribute('aria-current', 'page');
		this.selected = node || category; this.selectedButton = button;
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
		const changed = value !== this.search;
		this.search = value;
		for (const group of this.groups.values()) group.visible = 0;
		for (const node of this.nodes.values()) {
			const hidden = !node.searchName.includes(value);
			if (node.row.hidden !== hidden) node.row.hidden = hidden;
			if (!hidden) this.groups.get(node.categoryId).visible++;
		}
		for (const [id, group] of this.groups) {
			if (group.root.hidden !== !group.visible) group.root.hidden = !group.visible;
			if (value) {
				if (!wasSearching || group.beforeSearch === undefined) {
					group.beforeSearch = group.expanded; this.setExpanded(id, Boolean(group.visible));
				} else if (changed) this.setExpanded(id, Boolean(group.visible));
			} else if (group.beforeSearch !== undefined) {
				this.setExpanded(id, group.beforeSearch); delete group.beforeSearch;
			}
		}
		this.filteredIds = this.orderedIds.filter(id => !this.nodes.get(id).row.hidden);
	}
}
