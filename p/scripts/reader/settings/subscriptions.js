/** Subscription management has its own list, filters and persistent selection. */
export class SettingsSubscriptions {
	constructor({ root, store, selection, element, t, language = 'zh-CN', onOpenFeed, onStatus = () => {} }) {
		Object.assign(this, { root, store, selection, element, t, onOpenFeed, onStatus });
		this.zh = language.toLowerCase().startsWith('zh');
		this.nodes = new Map(); this.filteredIds = []; this.orderedIds = [];
		this.listeners = []; this.mounted = false;
	}

	text(zh, en) { return this.zh ? zh : en; }
	node(id) { return this.root.querySelector('#' + id); }

	mount() {
		if (this.mounted) return;
		this.mounted = true;
		this.list = this.node('settings-feed-list');
		this.search = this.node('settings-feed-search');
		this.statusFilter = this.node('settings-feed-filter');
		this.categoryFilter = this.node('settings-feed-category');
		this.empty = this.node('settings-feeds-empty');
		this.summary = this.node('settings-feed-summary');
		if (!this.summary) {
			this.summary = this.element('p', 'settings-feed-summary settings-note'); this.summary.id = 'settings-feed-summary';
			this.summary.setAttribute('role', 'status'); this.list.before(this.summary);
		}
		this.listen(this.search, 'input', () => this.filter());
		this.listen(this.search, 'search', () => this.filter());
		this.listen(this.statusFilter, 'change', () => this.filter());
		this.listen(this.categoryFilter, 'change', () => this.filter());
		this.listen(this.list, 'click', event => this.click(event));
		this.listen(this.list, 'change', event => this.change(event));
		this.listen(this.list, 'keydown', event => this.keydown(event));
		this.listen(this.selection, 'change', () => { this.syncSelection(); this.onStatus(); });
		this.update();
	}

	listen(target, type, listener) {
		target.addEventListener(type, listener); this.listeners.push([target, type, listener]);
	}

	update() {
		if (!this.mounted) return;
		const feeds = Array.isArray(this.store.feeds) ? this.store.feeds : [];
		const categories = Array.isArray(this.store.categories) ? this.store.categories : [];
		if (this.feeds === feeds && this.categories === categories && this.revision === this.store.feedRevision) {
			this.syncSelection(); return;
		}
		this.feeds = feeds; this.categories = categories; this.revision = this.store.feedRevision;
		this.categoriesById = new Map(categories.filter(category => category && validId(category.id)).map(category => [category.id, category]));
		this.updateCategories();
		const existing = new Set(); this.orderedIds = [];
		for (const feed of feeds) {
			if (!feed || !validId(feed.id) || existing.has(feed.id)) continue;
			existing.add(feed.id); this.orderedIds.push(feed.id);
			let node = this.nodes.get(feed.id);
			if (!node) { node = this.createRow(feed); this.nodes.set(feed.id, node); }
			this.updateRow(node, feed);
			const index = this.orderedIds.length - 1;
			if (this.list.children[index] !== node.row) this.list.insertBefore(node.row, this.list.children[index] || null);
		}
		for (const [id, node] of this.nodes) {
			if (!existing.has(id)) { node.row.remove(); this.nodes.delete(id); }
		}
		this.filter(); this.syncSelection();
	}

	updateCategories() {
		const signature = JSON.stringify([...this.categoriesById].map(([id, category]) => [id, category.name]));
		if (signature === this.categorySignature) return;
		this.categorySignature = signature;
		const previous = this.categoryFilter.value;
		const all = this.element('option', '', this.text('全部分类', 'All categories')); all.value = '';
		const options = [all];
		for (const [id, category] of this.categoriesById) {
			const option = this.element('option', '', category.name); option.value = String(id); options.push(option);
		}
		this.categoryFilter.replaceChildren(...options);
		this.categoryFilter.value = this.categoriesById.has(Number(previous)) ? previous : '';
	}

	createRow(feed) {
		const row = this.element('div', 'settings-feed-row'); row.dataset.settingsFeedRow = String(feed.id);
		const target = this.element('label', 'settings-feed-select-target');
		target.dataset.selectFeedTarget = String(feed.id);
		const checkbox = this.element('input', 'feed-select'); checkbox.type = 'checkbox'; checkbox.dataset.selectFeed = String(feed.id);
		target.append(checkbox);
		const content = this.element('div', 'settings-feed-content');
		const button = this.element('button', 'settings-feed-name'); button.type = 'button'; button.dataset.settingsFeed = String(feed.id);
		const meta = this.element('span', 'settings-feed-meta');
		content.append(button, meta); row.append(target, content);
		return { row, target, checkbox, button, meta };
	}

	updateRow(node, feed) {
		node.feed = feed;
		const category = this.categoriesById.get(feed.categoryId)?.name || this.text('未分类', 'Uncategorised');
		const parts = [category];
		const unread = Number.isSafeInteger(feed.unread) && feed.unread > 0 ? feed.unread : 0;
		if (unread) parts.push(this.text(`${unread} 篇未读`, `${unread} unread`));
		if (feed.enabled === false) parts.push(this.text('已暂停', 'Paused'));
		if (feed.error === true) parts.push(this.text('抓取失败', 'Fetch failed'));
		if (Number.isSafeInteger(feed.ttl) && feed.ttl > 0) parts.push(this.interval(feed.ttl));
		const visibility = this.visibility(feed.priority);
		if (visibility) parts.push(visibility);
		const signature = JSON.stringify([feed.name, parts, feed.version, feed.url]);
		if (node.signature !== signature) {
			node.signature = signature;
			node.button.textContent = String(feed.name || ''); node.meta.textContent = parts.join(' · ');
			node.checkbox.setAttribute('aria-label', this.text(`选择 ${feed.name}`, `Select ${feed.name}`));
			node.meta.id = 'settings-feed-meta-' + feed.id; node.button.setAttribute('aria-describedby', node.meta.id);
			node.checkbox.setAttribute('aria-describedby', node.meta.id);
			node.row.dataset.error = String(feed.error === true); node.row.dataset.paused = String(feed.enabled === false);
		}
		node.searchName = [feed.name, feed.url || '', category].join(' ').toLocaleLowerCase();
	}

	interval(seconds) {
		const value = seconds % 86400 === 0
			? [seconds / 86400, '天', 'd']
			: seconds % 3600 === 0
				? [seconds / 3600, '小时', 'h']
				: seconds % 60 === 0 ? [seconds / 60, '分钟', 'min'] : [seconds, '秒', 's'];
		return this.text(`每 ${value[0]} ${value[1]}更新`, `Every ${value[0]} ${value[2]}`);
	}

	visibility(priority) {
		return ({
			'-10': this.text('隐藏', 'Hidden'),
			'-5': this.text('仅订阅内显示', 'Feed only'),
			'0': this.text('仅分类内显示', 'Category only'),
			'20': this.text('重点订阅', 'Important')
		})[priority] || '';
	}

	filter() {
		if (!this.mounted) return;
		const terms = this.search.value.trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);
		const status = this.statusFilter.value; const category = this.categoryFilter.value;
		this.filteredIds = [];
		for (const id of this.orderedIds) {
			const node = this.nodes.get(id); const feed = node.feed;
			const matches = terms.every(term => node.searchName.includes(term)) && (!category || String(feed.categoryId) === category) &&
				(status !== 'error' || feed.error === true) && (status !== 'paused' || feed.enabled === false) &&
				(status !== 'enabled' || feed.enabled === true);
			if (node.row.hidden === matches) node.row.hidden = !matches;
			if (matches) this.filteredIds.push(id);
		}
		const total = this.orderedIds.length; const count = this.filteredIds.length;
		const summary = this.text(`显示 ${count} / ${total} 个订阅`, `Showing ${count} of ${total} subscriptions`);
		if (this.summary.textContent !== summary) this.summary.textContent = summary;
		this.empty.hidden = count > 0;
		this.empty.textContent = total
			? this.text('没有符合筛选条件的订阅。', 'No subscriptions match these filters.')
			: this.text('还没有订阅。添加订阅或导入 OPML 后，即可在此管理。', 'No subscriptions yet. Add a subscription or import OPML to manage it here.');
		this.onStatus();
	}

	matchingIds() { return [...this.filteredIds]; }

	syncSelection() {
		for (const [id, node] of this.nodes) {
			const checked = this.selection.ids.has(id);
			if (node.checkbox.checked !== checked) node.checkbox.checked = checked;
			if (node.row.dataset.selected !== String(checked)) node.row.dataset.selected = String(checked);
		}
	}

	click(event) {
		const checkbox = event.target.closest?.('[data-select-feed]');
		if (checkbox) {
			const id = Number(checkbox.dataset.selectFeed);
			if (this.nodes.has(id)) this.selection.set(id, checkbox.checked, this.filteredIds, event.shiftKey);
			return;
		}
		const target = event.target.closest?.('[data-select-feed-target]');
		if (target && event.shiftKey) {
			// Some browsers reserve Shift-clicking a label for text selection rather than checkbox activation.
			event.preventDefault();
			const id = Number(target.dataset.selectFeedTarget); const node = this.nodes.get(id);
			if (node) {
				node.checkbox.focus({ preventScroll: true });
				this.selection.set(id, !this.selection.ids.has(id), this.filteredIds, true);
			}
			return;
		}
		const button = event.target.closest?.('[data-settings-feed]');
		if (button) {
			const id = Number(button.dataset.settingsFeed); if (this.nodes.has(id)) this.onOpenFeed?.(id);
		}
	}

	change(event) {
		const checkbox = event.target.closest?.('[data-select-feed]');
		if (!checkbox) return;
		const id = Number(checkbox.dataset.selectFeed);
		if (this.nodes.has(id) && this.selection.ids.has(id) !== checkbox.checked) this.selection.set(id, checkbox.checked, this.filteredIds);
	}

	keydown(event) {
		const target = event.target.closest?.('[data-select-feed], [data-settings-feed]');
		if (!target) return;
		if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'a') {
			event.preventDefault(); event.stopPropagation(); this.selection.selectVisible(this.filteredIds); return;
		}
		const checkbox = Object.hasOwn(target.dataset, 'selectFeed');
		const id = Number(checkbox ? target.dataset.selectFeed : target.dataset.settingsFeed);
		const index = this.filteredIds.indexOf(id);
		if (index < 0) return;
		const direction = { ArrowDown: 1, ArrowUp: -1, Home: -Infinity, End: Infinity }[event.key];
		if (direction === undefined) return;
		const next = this.filteredIds[Math.max(0, Math.min(this.filteredIds.length - 1, index + direction))];
		event.preventDefault(); event.stopPropagation();
		if (checkbox && event.shiftKey) {
			if (this.selection.anchor === null) this.selection.set(id, true, this.filteredIds);
			this.selection.set(next, true, this.filteredIds, true);
		}
		this.nodes.get(next)[checkbox ? 'checkbox' : 'button'].focus({ preventScroll: true });
		this.nodes.get(next).row.scrollIntoView?.({ block: 'nearest' });
	}

	dispose() {
		for (const [target, type, listener] of this.listeners) target.removeEventListener(type, listener);
		this.listeners = []; this.mounted = false;
	}
}

function validId(id) { return Number.isSafeInteger(id) && id > 0; }
