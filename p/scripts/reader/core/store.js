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
		this.loadingMore = false;
		this.moreError = null;
		this.loadedCursors = new Set();
		this.preloads = new Map();
		this.preloadIds = [];
		this.preloadFailures = new Set();
		this.contentEpoch = 0;
		this.pageLatency = 500;
		this.articleLatency = 300;
		this.loadingArticle = false;
		this.error = null;
		this.retry = null;
		this.retryKind = null;
		this.csrfInvalid = false;
		this.pending = new Set();
		this.pendingReads = new Set();
		this.writeRequests = new Set();
		this.stateOverrides = new Map();
		this.batchProgress = null;
		this.stateRefreshEpoch = 0;
		this.feedStateEpoch = 0;
		this.sessionEpoch = 0;
		this.listEpoch = 0;
		this.articleEpoch = 0;
	}

	reportError(error, retry = null, kind = null) {
		if (error.message === 'csrf_invalid') this.csrfInvalid = true;
		if (error.status === 403 && !['administrator_required', 'csrf_invalid'].includes(error.message)) {
			this.sessionEpoch++; this.listEpoch++; this.articleEpoch++;
			this.listRequest?.abort(error); this.articleRequest?.abort(error); this.feedRequest?.abort(error);
			this.cancelMore(); this.clearContent();
			for (const request of this.writeRequests) request.abort(error);
			this.writeRequests.clear(); this.pending.clear(); this.pendingReads.clear(); this.batchProgress = null;
			this.loading = false; this.loadingArticle = false; this.next = null; this.history = [];
			this.items = []; this.article = null; this.selectedId = null;
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
				if (this.pendingReads.size) await this.waitForReadWrites(request.signal);
				if (epoch !== this.sessionEpoch || request.signal.aborted) return;
				stateEpoch = this.stateRefreshEpoch + this.feedStateEpoch;
				settingsEpoch = this.feedSettingsEpoch;
				data = await this.api.request('feeds', {}, { signal: request.signal });
				if (epoch !== this.sessionEpoch) return;
			} while (this.pendingReads.size || stateEpoch !== this.stateRefreshEpoch + this.feedStateEpoch || settingsEpoch !== this.feedSettingsEpoch);
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

	waitForReadWrites(signal) {
		return new Promise(resolve => {
			const settled = () => {
				if (this.pendingReads.size && !signal.aborted) return;
				this.removeEventListener('change', settled); signal.removeEventListener('abort', settled); resolve();
			};
			this.addEventListener('change', settled); signal.addEventListener('abort', settled, { once: true }); settled();
		});
	}

	async list(query = null, cursor = '') {
		this.listRequest?.abort();
		this.cancelMore(); this.prefetch([]);
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
		this.moreError = null;
		this.error = null;
		this.retry = null;
		this.emit();
		try {
			let data, stateEpoch;
			const started = performance.now();
			do {
				stateEpoch = this.stateRefreshEpoch;
				data = await this.api.request('items', { ...this.query, cursor, limit: String(this.pageSize) }, { signal: this.listRequest.signal });
				if (epoch !== this.listEpoch) return false;
				// A non-atomic batch may have changed unknown rows while this page was loading.
			} while (stateEpoch !== this.stateRefreshEpoch);
			this.items = data.items.map(item => ({ ...item, ...this.stateOverrides.get(item.id) }));
			this.pageLatency = performance.now() - started;
			this.cursor = cursor;
			this.next = data.next;
			this.loadedCursors = new Set([cursor]);
			return true;
		} catch (error) {
			if (epoch === this.listEpoch && !this.listRequest.signal.aborted) this.reportError(error, () => this.list(null, cursor), 'list');
			return false;
		} finally {
			if (epoch === this.listEpoch) { this.loading = false; this.pruneStateOverrides(); this.emit(); }
		}
	}

	cancelMore() {
		this.moreRequest?.abort();
		this.moreRequest = null; this.morePromise = null; this.loadingMore = false;
	}

	/** Append one cursor page. Reading and repeated requests share the same in-flight continuation. */
	loadMore({ background = false } = {}) {
		if (this.morePromise) {
			if (!background) this.moreForeground = true;
			return this.morePromise;
		}
		if (!this.next || this.loading) return Promise.resolve(false);
		const cursor = this.next;
		const epoch = this.listEpoch;
		const request = new AbortController();
		this.moreRequest = request; this.moreForeground = !background;
		this.loadingMore = true; this.moreError = null;
		const started = performance.now();
		const promise = (async () => {
			try {
				let data, stateEpoch;
				do {
					stateEpoch = this.stateRefreshEpoch;
					data = await this.api.request('items', { ...this.query, cursor, limit: String(this.pageSize) }, { signal: request.signal });
					if (epoch !== this.listEpoch || request.signal.aborted) return false;
				} while (stateEpoch !== this.stateRefreshEpoch);
				const existing = new Set(this.items.map(item => item.id));
				const added = data.items.filter(item => !existing.has(item.id)).map(item => ({ ...item, ...this.stateOverrides.get(item.id) }));
				this.items = [...this.items, ...added];
				this.loadedCursors.add(cursor);
				this.next = data.next && !this.loadedCursors.has(data.next) ? data.next : null;
				this.pageLatency = performance.now() - started;
				if (this.retryKind === 'more') { this.error = null; this.retry = null; this.retryKind = null; }
				return true;
			} catch (error) {
				if (epoch === this.listEpoch && !request.signal.aborted) {
					this.moreError = error;
					if (this.moreForeground || error.status === 403) this.reportError(error, () => this.loadMore(), 'more');
				}
				return false;
			} finally {
				if (this.moreRequest === request) {
					this.morePromise = null; this.moreRequest = null; this.loadingMore = false;
					this.pruneStateOverrides(); this.emit();
				}
			}
		})();
		this.morePromise = promise;
		this.emit();
		return promise;
	}

	/** Restore the extent of a continuous list using metadata, without storing article bodies in history. */
	async restoreRange(count) {
		const epoch = this.listEpoch;
		while (epoch === this.listEpoch && this.items.length < count && this.next) {
			if (!await this.loadMore()) break;
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
		const preloaded = this.preloads.get(id);
		this.preloads.delete(id);
		this.articleRequest = preloaded?.request || new AbortController();
		this.selectedId = id;
		this.article = this.cache.get(id);
		this.loadingArticle = !this.article;
		if (this.loadingArticle && !preloaded) this.prefetch([]);
		this.pruneStateOverrides();
		this.error = null;
		this.retry = null;
		this.emit();
		if (this.article) return;
		try {
			const article = await (preloaded?.promise || this.fetchArticle(id, this.articleRequest));
			if (epoch !== this.articleEpoch) return;
			// A read/star update may have completed while the body was being fetched.
			if (article) this.article = { ...article, ...this.stateOverrides.get(id) };
		} catch (error) {
			if (epoch === this.articleEpoch && !this.articleRequest.signal.aborted) this.reportError(error, () => this.open(id), 'article');
		} finally {
			if (epoch === this.articleEpoch) { this.loadingArticle = false; this.pruneStateOverrides(); this.emit(); }
		}
	}

	async fetchArticle(id, request) {
		const epoch = this.sessionEpoch; const contentEpoch = this.contentEpoch;
		const started = performance.now();
		const data = await this.api.request('article', { id }, { signal: request.signal });
		if (epoch !== this.sessionEpoch || contentEpoch !== this.contentEpoch || request.signal.aborted) return null;
		const article = { ...data.article, ...this.stateOverrides.get(id) };
		this.articleLatency = performance.now() - started;
		this.cache.set(article);
		return article;
	}

	/** Only nearby bodies are fetched; no DOM, remote media or read-state writes are created here. */
	prefetch(ids) {
		this.preloadIds = this.cache.maxBytes > 0 ? [...new Set(ids)].filter(id => id !== this.selectedId).slice(0, Math.max(0, this.cache.maxEntries - 1)) : [];
		const wanted = new Set(this.preloadIds);
		for (const [id, preload] of this.preloads) {
			if (!wanted.has(id)) { preload.request.abort(); this.preloads.delete(id); }
		}
		for (const id of this.preloadFailures) if (!wanted.has(id)) this.preloadFailures.delete(id);
		this.pumpPreloads(); this.pruneStateOverrides();
	}

	pumpPreloads() {
		for (const id of this.preloadIds) {
			if (this.preloads.size >= 2) break;
			if (id === this.selectedId || this.preloads.has(id) || this.cache.entries.has(id) || this.preloadFailures.has(id)) continue;
			const request = new AbortController();
			const preload = { request, promise: null };
			this.preloads.set(id, preload);
			preload.promise = this.fetchArticle(id, request).then(article => {
				if (!request.signal.aborted && !this.cache.entries.has(id)) this.preloadFailures.add(id);
				return article;
			}).catch(error => {
				if (!request.signal.aborted) {
					this.preloadFailures.add(id);
					if (error.status === 403) this.reportError(error);
				}
				throw error;
			}).finally(() => {
				if (this.preloads.get(id) === preload) this.preloads.delete(id);
				this.pruneStateOverrides(); this.pumpPreloads();
			});
			// A selected body can await the same promise and surface its error; speculation is otherwise silent.
			preload.promise.catch(() => {});
		}
	}

	clearContent() { this.contentEpoch++; this.prefetch([]); this.cache.clear(); }

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
		if (this.loading || this.loadingMore) return;
		for (const id of this.stateOverrides.keys()) {
			if (!this.pending.has(id) && !this.preloads.has(id) && (!this.loadingArticle || this.selectedId !== id)) this.stateOverrides.delete(id);
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
		if (delta) this.pendingReads.add(id);
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
			if (epoch === this.sessionEpoch) {
				if (delta) this.feedStateEpoch++;
				this.pending.delete(id); this.pendingReads.delete(id); this.pruneStateOverrides(); this.emit();
			}
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
		for (const id of ids) { this.pending.add(id); if (field === 'read') this.pendingReads.add(id); }
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
				for (const id of ids) { this.pending.delete(id); this.pendingReads.delete(id); }
				this.batchProgress = null;
				this.pruneStateOverrides(); this.emit();
			}
		}
		if (epoch !== this.sessionEpoch) return;
		if (failure?.status === 403) { this.reportError(failure); return; }
		// Batch hooks can partially succeed: reload authoritative state even after a failed write.
		this.stateRefreshEpoch++;
		for (const id of ids) this.stateOverrides.delete(id);
		this.clearContent();
		const selected = this.selectedId;
		const count = this.items.length;
		try {
			await Promise.all([
				this.loading ? Promise.resolve() : this.list(null, this.cursor).then(loaded => loaded && this.restoreRange(count)),
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
		this.cancelMore(); this.clearContent();
		this.api.abortAll?.();
		for (const request of this.writeRequests) request.abort();
		this.writeRequests.clear(); this.pending.clear(); this.pendingReads.clear(); this.batchProgress = null;
		this.listRequest = null; this.articleRequest = null; this.feedRequest = null; this.feedPromise = null;
		this.loading = false; this.loadingArticle = false;
		this.article = null; this.retry = null; this.retryKind = null;
		this.stateOverrides.clear();
		this.emit();
	}

	async resume() {
		const epoch = this.sessionEpoch;
		const selected = this.selectedId;
		const count = this.items.length;
		try {
			await Promise.all([this.feedsRefresh(), this.list(null, this.cursor).then(loaded => loaded && this.restoreRange(count)),
				selected ? this.open(selected) : Promise.resolve()]);
		} catch (error) { if (epoch === this.sessionEpoch) this.reportError(error); }
	}
}
