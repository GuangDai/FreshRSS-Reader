/** Bounded, account-local memory cache. No article content or credentials go into browser storage. */
export class ArticleCache {
	constructor(maxEntries, maxBytes) {
		this.maxEntries = maxEntries;
		this.maxBytes = maxBytes;
		this.bytes = 0;
		this.entries = new Map();
	}

	get(id) {
		const item = this.entries.get(id);
		if (!item) return null;
		this.entries.delete(id);
		this.entries.set(id, item);
		return item.article;
	}

	set(article) {
		if (this.maxEntries === 0 || this.maxBytes === 0) return;
		const size = JSON.stringify(article).length * 2;
		const old = this.entries.get(article.id);
		if (old) { this.bytes -= old.size; this.entries.delete(article.id); }
		if (size > this.maxBytes) return;
		this.entries.set(article.id, { article, size });
		this.bytes += size;
		while (this.entries.size > this.maxEntries || this.bytes > this.maxBytes) {
			const key = this.entries.keys().next().value;
			this.bytes -= this.entries.get(key).size;
			this.entries.delete(key);
		}
	}

	configure(maxEntries, maxBytes) {
		this.maxEntries = maxEntries;
		this.maxBytes = maxBytes;
		while (this.entries.size && (this.entries.size > maxEntries || this.bytes > maxBytes)) {
			const key = this.entries.keys().next().value;
			this.bytes -= this.entries.get(key).size;
			this.entries.delete(key);
		}
	}

	clear() { this.entries.clear(); this.bytes = 0; }
}

/** Data lifecycle is independent of DOM, layout, colours and article rendering. */
export class ReaderStore extends EventTarget {
	constructor(api, { pageSize, cacheArticles, cacheBytes }) {
		super();
		this.api = api;
		this.cache = new ArticleCache(cacheArticles, cacheBytes);
		this.pageSize = pageSize;
		this.items = [];
		this.feeds = [];
		this.feedRevision = 0;
		this.feedSettingsEpoch = 0;
		this.categories = [];
		this.query = { get: 'a', filter: 'all', search: '' };
		this.cursor = '';
		this.next = null;
		this.history = [];
		this.article = null;
		this.selectedId = null;
		this.loading = false;
		this.loadingArticle = false;
		this.error = null;
		this.retry = null;
		this.retryKind = null;
		this.csrfInvalid = false;
		this.pending = new Set();
		this.writeRequests = new Set();
		this.stateOverrides = new Map();
		this.batchProgress = null;
		this.stateRefreshEpoch = 0;
		this.sessionEpoch = 0;
		this.listEpoch = 0;
		this.articleEpoch = 0;
	}

	reportError(error, retry = null, kind = null) {
		if (error.message === 'csrf_invalid') this.csrfInvalid = true;
		if (error.status === 403 && !['administrator_required', 'csrf_invalid'].includes(error.message)) {
			this.sessionEpoch++; this.listEpoch++; this.articleEpoch++;
			this.listRequest?.abort(error); this.articleRequest?.abort(error); this.feedRequest?.abort(error);
			for (const request of this.writeRequests) request.abort(error);
			this.writeRequests.clear(); this.pending.clear(); this.batchProgress = null;
			this.loading = false; this.loadingArticle = false; this.next = null; this.history = [];
			this.cache.clear(); this.items = []; this.article = null; this.selectedId = null;
			this.feeds = []; this.categories = [];
			this.stateOverrides.clear();
		}
		this.error = error;
		this.retry = retry;
		this.retryKind = kind;
		this.emit();
	}

	emit() { this.dispatchEvent(new Event('change')); }

	mergeFeedSettings(updates) {
		if (!updates.length) return;
		const byId = new Map(updates.map(feed => [feed.id, feed]));
		this.feeds = this.feeds.map(feed => byId.has(feed.id) ? { ...feed, ...byId.get(feed.id) } : feed);
		this.feedRevision++;
		this.feedSettingsEpoch++;
		this.emit();
	}

	async feedsRefresh() {
		if (this.feedPromise) return this.feedPromise;
		const epoch = this.sessionEpoch;
		const request = new AbortController();
		this.feedRequest = request;
		const promise = (async () => {
			let data, stateEpoch, settingsEpoch;
			do {
				stateEpoch = this.stateRefreshEpoch;
				settingsEpoch = this.feedSettingsEpoch;
				data = await this.api.request('feeds', {}, { signal: request.signal });
				if (epoch !== this.sessionEpoch) return;
			} while (stateEpoch !== this.stateRefreshEpoch || settingsEpoch !== this.feedSettingsEpoch);
			this.feeds = data.feeds;
			this.categories = data.categories;
			this.feedRevision++;
			this.emit();
		})().catch(error => { if (epoch === this.sessionEpoch) throw error; }).finally(() => {
			if (this.feedPromise === promise) this.feedPromise = null;
		});
		this.feedPromise = promise;
		return promise;
	}

	async list(query = null, cursor = '') {
		this.listRequest?.abort();
		const epoch = ++this.listEpoch;
		this.listRequest = new AbortController();
		if (query) {
			// A deliberate filter choice replaces an inherited legacy state, including when restoring history.
			if (Object.hasOwn(query, 'filter') && !Object.hasOwn(query, 'state')) delete this.query.state;
			this.query = { ...this.query, ...query };
			this.history = [];
			this.cursor = ''; this.next = null;
			this.items = [];
			this.closeArticle();
		}
		this.loading = true;
		this.error = null;
		this.retry = null;
		this.emit();
		try {
			let data, stateEpoch;
			do {
				stateEpoch = this.stateRefreshEpoch;
				data = await this.api.request('items', { ...this.query, cursor, limit: String(this.pageSize) }, { signal: this.listRequest.signal });
				if (epoch !== this.listEpoch) return false;
				// A non-atomic batch may have changed unknown rows while this page was loading.
			} while (stateEpoch !== this.stateRefreshEpoch);
			this.items = data.items.map(item => ({ ...item, ...this.stateOverrides.get(item.id) }));
			this.cursor = cursor;
			this.next = data.next;
			return true;
		} catch (error) {
			if (epoch === this.listEpoch && !this.listRequest.signal.aborted) this.reportError(error, () => this.list(null, cursor), 'list');
			return false;
		} finally {
			if (epoch === this.listEpoch) { this.loading = false; this.pruneStateOverrides(); this.emit(); }
		}
	}

	async nextPage() {
		if (!this.next || this.loading) return false;
		const previous = this.cursor;
		const epoch = this.listEpoch + 1;
		if (await this.list(null, this.next)) { this.history.push(previous); this.emit(); return true; }
		if (epoch === this.listEpoch && this.error) this.retry = () => this.nextPage();
		return false;
	}

	async previousPage() {
		if (!this.history.length || this.loading) return false;
		const epoch = this.listEpoch + 1;
		if (await this.list(null, this.history.at(-1))) { this.history.pop(); this.emit(); return true; }
		if (epoch === this.listEpoch && this.error) this.retry = () => this.previousPage();
		return false;
	}

	async open(id) {
		this.articleRequest?.abort();
		const epoch = ++this.articleEpoch;
		this.articleRequest = new AbortController();
		this.selectedId = id;
		this.article = this.cache.get(id);
		this.loadingArticle = !this.article;
		this.pruneStateOverrides();
		this.error = null;
		this.retry = null;
		this.emit();
		if (this.article) return;
		try {
			const data = await this.api.request('article', { id }, { signal: this.articleRequest.signal });
			if (epoch !== this.articleEpoch) return;
			// A read/star update may have completed while the body was being fetched.
			this.article = { ...data.article, ...this.stateOverrides.get(id) };
			this.cache.set(this.article);
		} catch (error) {
			if (epoch === this.articleEpoch && !this.articleRequest.signal.aborted) this.reportError(error, () => this.open(id), 'article');
		} finally {
			if (epoch === this.articleEpoch) { this.loadingArticle = false; this.pruneStateOverrides(); this.emit(); }
		}
	}

	closeArticle() {
		this.articleRequest?.abort();
		this.articleEpoch++;
		this.article = null;
		this.selectedId = null;
		this.loadingArticle = false;
		if (this.retryKind === 'article' && this.error?.status !== 403) { this.error = null; this.retry = null; this.retryKind = null; }
		this.pruneStateOverrides();
		this.emit();
	}

	pruneStateOverrides() {
		// Only retain state across outstanding reads or writes, never the entire reading history.
		if (this.loading) return;
		for (const id of this.stateOverrides.keys()) {
			if (!this.pending.has(id) && (!this.loadingArticle || this.selectedId !== id)) this.stateOverrides.delete(id);
		}
	}

	applyState(id, values) {
		this.stateOverrides.set(id, { ...this.stateOverrides.get(id), ...values });
		for (const item of this.items) if (item.id === id) Object.assign(item, values);
		if (this.article?.id === id) Object.assign(this.article, values);
		const cached = this.cache.get(id);
		if (cached) Object.assign(cached, values);
	}

	async changeState(id, field, value) {
		if (this.csrfInvalid || this.pending.has(id) || !['read', 'starred'].includes(field) || typeof value !== 'boolean') return;
		const item = this.items.find(entry => entry.id === id) || (this.article?.id === id ? this.article : null);
		if (!item) return;
		const before = item[field];
		const epoch = this.sessionEpoch;
		const request = new AbortController(); this.writeRequests.add(request);
		const feed = this.feeds.find(feed => feed.id === item.feedId);
		const delta = field === 'read' && before !== value ? (value ? -1 : 1) : 0;
		this.pending.add(id);
		this.error = null;
		this.retry = null;
		this.applyState(id, { [field]: value });
		if (feed && delta) { feed.unread = Math.max(0, feed.unread + delta); this.feedRevision++; }
		this.emit();
		try {
			const data = await this.api.request('state', {}, { body: { id, [field]: value }, signal: request.signal });
			if (epoch !== this.sessionEpoch) return;
			this.applyState(id, { read: data.read, starred: data.starred });
		} catch (error) {
			if (epoch !== this.sessionEpoch) return;
			this.applyState(id, { [field]: before });
			if (feed && delta) { feed.unread = Math.max(0, feed.unread - delta); this.feedRevision++; }
			this.reportError(error, () => this.changeState(id, field, value), 'state');
		} finally {
			this.writeRequests.delete(request);
			if (epoch === this.sessionEpoch) { this.pending.delete(id); this.pruneStateOverrides(); this.emit(); }
		}
	}

	async batchState(ids, field, value, limits) {
		if (this.csrfInvalid || this.batchProgress || !['read', 'starred'].includes(field) || typeof value !== 'boolean') return;
		ids = [...new Set(ids)];
		if (!ids.length || ids.some(id => this.pending.has(id))) return;
		const epoch = this.sessionEpoch;
		const request = new AbortController(); this.writeRequests.add(request);
		const chunks = [];
		let chunk = [];
		for (const id of ids) {
			const candidate = [...chunk, id];
			const bytes = new TextEncoder().encode(JSON.stringify({ ids: candidate, [field]: value, _csrf: this.api.csrf })).length;
			if (chunk.length && (candidate.length > limits.maxBatchItems || bytes > limits.maxRequestBytes)) {
				chunks.push(chunk); chunk = [];
			}
			chunk.push(id);
		}
		if (chunk.length) chunks.push(chunk);
		for (const id of ids) this.pending.add(id);
		this.batchProgress = { completed: 0, total: ids.length };
		this.error = null; this.retry = null; this.emit();
		let failure = null;
		try {
			for (const ids of chunks) {
				await this.api.request('batch', {}, { body: { ids, [field]: value }, signal: request.signal });
				if (epoch !== this.sessionEpoch) return;
				for (const id of ids) this.applyState(id, { [field]: value });
				this.batchProgress.completed += ids.length;
				this.emit();
			}
		} catch (error) { failure = error; } finally {
			this.writeRequests.delete(request);
			if (epoch === this.sessionEpoch) {
				for (const id of ids) this.pending.delete(id);
				this.batchProgress = null;
				this.pruneStateOverrides(); this.emit();
			}
		}
		if (epoch !== this.sessionEpoch) return;
		if (failure?.status === 403) { this.reportError(failure); return; }
		// Batch hooks can partially succeed: reload authoritative state even after a failed write.
		this.stateRefreshEpoch++;
		for (const id of ids) this.stateOverrides.delete(id);
		this.cache.clear();
		const selected = this.selectedId;
		try {
			await Promise.all([
				this.loading ? Promise.resolve() : this.list(null, this.cursor),
				this.feedsRefresh(),
				selected && ids.includes(selected) ? this.open(selected) : Promise.resolve(),
			]);
		} catch (error) { failure ||= error; }
		if (epoch === this.sessionEpoch && failure) this.reportError(failure, () => this.batchState(ids, field, value, limits), 'batch');
	}

	dispose() {
		this.sessionEpoch++;
		this.listEpoch++; this.articleEpoch++;
		this.listRequest?.abort(); this.articleRequest?.abort(); this.feedRequest?.abort();
		this.api.abortAll?.();
		for (const request of this.writeRequests) request.abort();
		this.writeRequests.clear(); this.pending.clear(); this.batchProgress = null;
		this.listRequest = null; this.articleRequest = null; this.feedRequest = null; this.feedPromise = null;
		this.loading = false; this.loadingArticle = false;
		this.cache.clear();
		this.article = null; this.retry = null; this.retryKind = null;
		this.stateOverrides.clear();
		this.emit();
	}

	async resume() {
		const epoch = this.sessionEpoch;
		const selected = this.selectedId;
		try {
			await Promise.all([this.feedsRefresh(), this.list(null, this.cursor), selected ? this.open(selected) : Promise.resolve()]);
		} catch (error) { if (epoch === this.sessionEpoch) this.reportError(error); }
	}
}
