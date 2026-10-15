const stateKey = 'freshrssReader';
const readingStateMask = 111;
const panels = new Set(['sidebar', 'appearance', 'feed-settings', 'settings']);
const settingsSections = new Set([
	'appearance', 'reading', 'subscriptions', 'filters', 'feeds', 'data', 'storage', 'privacy', 'extensions', 'automation', 'advanced',
]);

function settingsSection(value) { return settingsSections.has(value) ? value : 'appearance'; }
function validReadingState(value) {
	return Number.isInteger(value) && value > 0 && value <= readingStateMask && (value & ~readingStateMask) === 0;
}

function snapshot(value = {}) {
	const query = value.query || {};
	return {
		query: {
			get: typeof query.get === 'string' ? query.get : 'a',
			filter: typeof query.filter === 'string' ? query.filter : 'all',
			search: typeof query.search === 'string' ? query.search : '',
			...(validReadingState(query.state) ? { state: query.state } : {}),
		},
		cursor: typeof value.cursor === 'string' ? value.cursor : '',
		history: Array.isArray(value.history) ? value.history.filter(cursor => typeof cursor === 'string') : [],
		selectedId: typeof value.selectedId === 'string' && value.selectedId ? value.selectedId : null,
		listScroll: Number.isFinite(value.listScroll) && value.listScroll >= 0 ? value.listScroll : 0,
		...(Number.isSafeInteger(value.loadedCount) && value.loadedCount > 0 ? { loadedCount: value.loadedCount } : {}),
		...(typeof value.listAnchor === 'string' && value.listAnchor ? { listAnchor: value.listAnchor } : {}),
	};
}

function depth(value) { return Number(Boolean(value.snapshot.selectedId)) + value.panels.length; }

/** Browser history contains navigation metadata only, never article bodies or account configuration. */
export class ReaderNavigation {
	constructor({ history, location, onRestore, storage = null, storageKey = stateKey, restoreStorage = false }) {
		this.history = history;
		this.location = location;
		this.onRestore = onRestore;
		this.current = null;
		this.pending = null;
		this.lastFrameNavigation = null;
		this.writeTimes = [];
		this.lastWriteAt = -Infinity;
		this.pendingWrite = null;
		this.writeTimer = null;
		this.lastArticle = null;
		Object.assign(this, { storage, storageKey, restoreStorage });
	}

	read(value) {
		const state = value?.[stateKey];
		if (state?.version !== 1 || typeof state.session !== 'string') return null;
		return {
			version: 1,
			session: state.session,
			snapshot: snapshot(state.snapshot),
			panels: Array.isArray(state.panels) ? state.panels.filter(panel => panels.has(panel)) : [],
			settingsSection: settingsSection(state.settingsSection),
		};
	}

	start(initial) {
		const historyState = this.read(this.history.state); const stored = this.storedNavigation();
		const existing = stored && (!historyState || historyState.session === stored.session) ? stored : historyState;
		if (existing) {
			this.current = existing;
			if (existing.snapshot.selectedId) this.lastArticle = existing.snapshot;
			this.write(existing, false);
			return snapshot(existing.snapshot);
		}
		const value = snapshot(initial);
		const params = new URL(this.location.href).searchParams;
		for (const key of ['get', 'filter', 'search']) {
			if (params.has(key)) value.query[key] = params.get(key);
		}
		if (params.has('filter')) delete value.query.state;
		else if (params.has('state')) {
			const state = Number(params.get('state'));
			if (validReadingState(state)) {
				const filter = { 2: 'unread', 3: 'all', 4: 'starred' }[state];
				if (filter) {
					value.query.filter = filter; delete value.query.state;
				} else value.query.state = state;
			}
		}
		if (params.has('cursor')) { value.cursor = params.get('cursor'); value.history = []; }
		const id = params.get('article');
		const settings = params.get('view') === 'settings';
		value.selectedId = null;
		this.current = {
			version: 1, session: `${Date.now()}:${Math.random()}`, snapshot: value, panels: [], settingsSection: settingsSection(params.get('section')),
		};
		this.write(this.current, false);
		// A shared article URL gets a list underneath it, so Back can close the reader.
		if (id && /^\d{1,20}$/.test(id)) this.openArticle(id, value);
		// Direct settings URLs also retain a reading page beneath them.
		if (settings) this.openPanel('settings');
		return snapshot(this.current.snapshot);
	}

	storedNavigation() {
		if (![true, 'reload', 'back_forward'].includes(this.restoreStorage)) return null;
		try {
			const stored = JSON.parse(this.storage?.getItem(this.storageKey) || 'null');
			const candidates = [stored, stored?.list, stored?.article].filter(Boolean);
			const match = candidates.find(value => value.url === this.location.href) ||
				(this.restoreStorage !== 'back_forward' && candidates.find(value => value.source === this.location.href));
			return match ? this.read({ [stateKey]: match.value }) : null;
		} catch { return null; }
	}

	remember(value, url) {
		// WebKit can discard history.state on reload. Persist only navigation metadata in this tab and account.
		if (!this.storage) return;
		try {
			const previous = JSON.parse(this.storage.getItem(this.storageKey) || 'null');
			const current = { url, source: this.location.href, value: this.read({ [stateKey]: value }) };
			const listURL = new URL(url);
			for (const key of ['article', 'view', 'section']) listURL.searchParams.delete(key);
			const list = {
				url: listURL.href,
				value: this.read({
					[stateKey]: {
						...value,
						panels: [],
						snapshot: { ...value.snapshot, selectedId: null, listAnchor: value.snapshot.selectedId || value.snapshot.listAnchor }
					}
				})
			};
			this.storage.setItem(this.storageKey, JSON.stringify({ ...current, list, article: value.snapshot.selectedId ? current : previous?.article }));
		} catch {}
	}

	get panel() { return this.current?.panels.at(-1) || null; }
	get settingsSection() { return settingsSection(this.current?.settingsSection); }

	setSettingsSection(section) {
		if (!settingsSections.has(section) || section === this.settingsSection) return;
		this.transition({ ...this.current, settingsSection: section }, false);
	}

	write(value, push, immediate = false) {
		if (push) this.flushWrite();
		if (value.snapshot.selectedId) this.lastArticle = value.snapshot;
		const url = new URL(this.location.href);
		const data = value.snapshot;
		const explicitAll = !data.query.state && (url.searchParams.has('filter') || url.searchParams.has('state'));
		if ([null, 'index', 'reader'].includes(url.searchParams.get('c')) &&
			[null, 'index', 'normal', 'reader', 'global'].includes(url.searchParams.get('a'))) {
			url.searchParams.delete('c'); url.searchParams.delete('a');
		}
		for (const [key, text] of Object.entries({ ...data.query, state: data.query.state, cursor: data.cursor, article: data.selectedId })) {
			if (text && !(key === 'get' && text === 'a') && !(key === 'filter' && text === 'all' && !explicitAll)) {
				url.searchParams.set(key, String(text));
			} else url.searchParams.delete(key);
		}
		if (value.panels.includes('settings')) {
			url.searchParams.set('view', 'settings');
			url.searchParams.set('section', settingsSection(value.settingsSection));
		} else {
			url.searchParams.delete('view');
			url.searchParams.delete('section');
		}
		this.remember(value, url.href);
		// Avoid rewriting unchanged entries, including when a completed Back already
		// restored the desired state. WebKit limits history writes in short intervals.
		if (!push && url.href === this.location.href &&
			JSON.stringify(this.read(this.history.state)) === JSON.stringify(this.read({ [stateKey]: value }))) { this.cancelWrite(); return; }
		const now = Date.now();
		this.writeTimes = this.writeTimes.filter(time => now - time < 10000);
		// Coalesce rapid article steps before WebKit's history quota can interrupt reading.
		if (!push && !immediate && this.writeTimes.length >= 20 && now - this.lastWriteAt < 250) {
			this.deferWrite(value, 250 - (now - this.lastWriteAt)); return;
		}
		this.cancelWrite();
		try {
			this.history[push ? 'pushState' : 'replaceState']({ ...this.history.state, [stateKey]: value }, '', url.href);
			this.writeTimes.push(now); this.lastWriteAt = now;
		} catch (error) {
			if (push || error.name !== 'SecurityError') throw error;
			this.deferWrite(value, 1000);
		}
	}

	deferWrite(value, delay) {
		this.cancelWrite(); this.pendingWrite = value;
		this.writeTimer = setTimeout(() => this.flushWrite(), delay);
	}

	cancelWrite() {
		clearTimeout(this.writeTimer); this.writeTimer = null; this.pendingWrite = null;
	}

	flushWrite() {
		const value = this.pendingWrite;
		this.cancelWrite();
		if (value) this.write(value, false, true);
	}

	restore() { this.onRestore(snapshot(this.current.snapshot), this.panel); }

	replace(value) {
		const next = { ...this.current, snapshot: snapshot(value) };
		const articleRemoved = this.current.snapshot.selectedId && !next.snapshot.selectedId;
		const settingsIndex = this.current.panels.indexOf('settings');
		if (articleRemoved) {
			next.panels = settingsIndex < 0 ? [] : this.current.panels.slice(settingsIndex).filter(panel => panel === 'settings' || panel === 'feed-settings');
		}
		// A settings save can reload the list. Remove its stale article history beneath
		// the settings page while retaining the page and any subscription editor.
		this.transition(next, false, Boolean(articleRemoved && settingsIndex >= 0));
	}

	openArticle(id, value) {
		const next = { ...this.current, snapshot: { ...snapshot(value), selectedId: String(id) }, panels: [] };
		if (!this.current.snapshot.selectedId && !this.current.panels.length && !this.pending) {
			this.write({ ...this.current, snapshot: { ...snapshot(value), selectedId: null } }, false);
		}
		this.transition(next, false);
	}

	closeArticle(value = this.current.snapshot, retainFrame = false) {
		if (!this.current.snapshot.selectedId) return;
		const next = { ...this.current, snapshot: { ...snapshot(value), selectedId: null }, panels: [] };
		if (retainFrame) {
			this.closePreservingFrame(next, { ...next, snapshot: { ...next.snapshot, selectedId: this.current.snapshot.selectedId } });
		} else this.transition(next, true);
	}

	openPanel(name, value = this.current.snapshot) {
		if (!panels.has(name)) return;
		const index = this.current.panels.indexOf(name);
		const nextPanels = index < 0 ? [...this.current.panels, name] : this.current.panels.slice(0, index + 1);
		if (!this.pending) this.write({ ...this.current, snapshot: snapshot(value) }, false);
		this.transition({ ...this.current, snapshot: snapshot(value), panels: nextPanels }, false);
	}

	closePanel(nameOrValue = this.current.snapshot, value = this.current.snapshot) {
		if (!this.current.panels.length) return;
		const named = typeof nameOrValue === 'string';
		const index = named ? this.current.panels.indexOf(nameOrValue) : this.current.panels.length - 1;
		if (index < 0) return;
		this.transition({ ...this.current, snapshot: snapshot(named ? value : nameOrValue), panels: this.current.panels.slice(0, index) }, true);
	}

	/** Retain a native form draft by traversing parent entries that share its live document. */
	closeSettingsPreservingFrame(value = this.current.snapshot) {
		if (!this.current.panels.includes('settings')) return;
		const reading = { ...this.current, snapshot: snapshot(value), panels: [] };
		const settings = { ...reading, panels: ['settings'] };
		this.closePreservingFrame(reading, settings);
	}

	closePreservingFrame(next, previous) {
		// Returning through the iframe's own POST history would unload its draft.
		// This pair preserves the document and lets Forward restore the former page.
		this.write(next, false);
		this.write(previous, true);
		this.current = previous;
		this.transition(next, true);
		if (this.pending) this.pending.preserveFrame = true;
	}

	/** A native iframe form adds joint history entries without changing the parent state. */
	handleFrameNavigation(document, href) {
		if (!document) return;
		const previous = this.lastFrameNavigation;
		this.lastFrameNavigation = { document, href };
		// pageshow and popstate can describe the same restored iframe document.
		if (previous?.document === document && previous.href === href) return;
		if (!this.pending || this.pending.preserveFrame ||
			JSON.stringify(this.read(this.history.state)) !== JSON.stringify(this.pending.anchor)) return;
		this.history.go(-1);
	}

	transition(next, notify, rebase = false) {
		const previous = this.current;
		if (rebase || depth(next) !== depth(previous)) this.flushWrite();
		this.current = next;
		if (next.snapshot.selectedId) this.lastArticle = next.snapshot;
		if (this.pending) {
			// back() is asynchronous. A new tap must survive its eventual popstate.
			this.pending.desired = next;
			if (rebase) this.pending.rebase = true;
		} else if (rebase) {
			this.pending = { desired: next, rebase: true, anchor: this.read(this.history.state) };
			this.history.go(-depth(previous));
		} else if (depth(next) < depth(previous)) {
			this.pending = { desired: next, anchor: this.read(this.history.state) };
			this.history.go(depth(next) - depth(previous));
		} else if (depth(next) > depth(previous)) {
			for (let level = depth(previous) + 1; level <= depth(next); level++) {
				this.write({ ...next, panels: next.panels.slice(0, level - Number(Boolean(next.snapshot.selectedId))) }, true);
			}
		} else {
			this.write(next, false);
		}
		if (notify) this.restore();
	}

	handlePopState(event) {
		const target = this.read(event.state);
		if (!target || target.session !== this.current.session) return;
		this.cancelWrite();
		if (this.pending) {
			const desired = this.pending.desired;
			const rebase = this.pending.rebase;
			const preserveFrame = this.pending.preserveFrame;
			this.pending = null;
			this.current = target;
			if (preserveFrame) {
				// WebKit can report the iframe's enclosing state for this parent step.
				// The step is complete; a further Back would unload the retained form.
				this.current = desired;
				this.write(desired, false);
				return;
			}
			if (rebase && depth(target)) {
				this.current = desired;
				this.pending = { desired, rebase: true, anchor: target };
				this.history.go(-depth(target));
				return;
			}
			// Keep the list beneath a quickly reopened article up to date as well.
			if (depth(target) < depth(desired)) {
				this.write({ ...target, snapshot: { ...desired.snapshot, selectedId: target.snapshot.selectedId } }, false);
			}
			this.transition(desired, false);
			return;
		}
		if (depth(target) < depth(this.current)) {
			// Moving between articles can also turn a list page. Closing preserves that list.
			target.snapshot = { ...this.current.snapshot, selectedId: target.snapshot.selectedId };
			this.write(target, false);
		} else if (target.snapshot.selectedId && !this.current.snapshot.selectedId && this.lastArticle &&
			this.lastArticle.cursor === target.snapshot.cursor && JSON.stringify(this.lastArticle.query) === JSON.stringify(target.snapshot.query)) {
			// Native Back may arrive before a coalesced write. Forward still returns to the last article read.
			target.snapshot = snapshot(this.lastArticle); this.write(target, false);
		}
		this.current = target;
		this.restore();
	}
}
