import { ReaderError } from '../core/api.js';

/** Selection is independent of the query and of an already submitted request. */
export class FeedSelection extends EventTarget {
	constructor() {
		super();
		this.ids = new Set();
		this.anchor = null;
		this.active = false;
		this.revision = 0;
	}

	emit() { this.revision++; this.dispatchEvent(new Event('change')); }
	activate(active) { this.active = active; if (!active) { this.ids.clear(); this.anchor = null; } this.emit(); }
	clear() { this.ids.clear(); this.anchor = null; this.emit(); }
	replace(ids) { this.ids = new Set(ids); this.anchor = null; this.emit(); }

	set(id, checked, orderedIds, range = false) {
		const start = range ? orderedIds.indexOf(this.anchor) : -1;
		const end = orderedIds.indexOf(id);
		const ids = start >= 0 && end >= 0 ? orderedIds.slice(Math.min(start, end), Math.max(start, end) + 1) : [id];
		for (const selected of ids) { if (checked) this.ids.add(selected); else this.ids.delete(selected); }
		if (!range || start < 0) this.anchor = id;
		this.emit();
	}

	selectVisible(ids) { for (const id of ids) this.ids.add(id); this.emit(); }

	reconcile(feeds) {
		const existing = new Set(feeds.map(feed => feed.id));
		let changed = false;
		for (const id of this.ids) if (!existing.has(id)) { this.ids.delete(id); changed = true; }
		if (this.anchor !== null && !existing.has(this.anchor)) this.anchor = null;
		if (changed) this.emit();
	}
}

export function feedChangeErrors(changes, fields) {
	return Object.entries(changes).filter(([key, value]) => {
		const spec = fields[key];
		if (!spec) return true;
		const options = spec.options || spec.enum;
		if (options && !options.includes(value)) return true;
		if (spec.type === 'integer') return !Number.isSafeInteger(value) || value < spec.min || value > spec.max;
		if (spec.type === 'boolean') return typeof value !== 'boolean';
		if (spec.type === 'string') {
			if (typeof value !== 'string' || !value.trim()) return true;
			const encoded = value.trim().replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[character]);
			const length = spec.unit === 'html-encoded-bytes' ? new TextEncoder().encode(encoded).length : [...value].length;
			return Boolean(spec.maxLength && length > spec.maxLength);
		}
		return false;
	}).map(([key]) => key);
}

/** Split immutable ID/version snapshots by the server's actual item and encoded-body limits. */
export function feedBatchRequests(ids, feeds, changes, limits, csrf) {
	const byId = new Map(feeds.map(feed => [feed.id, feed]));
	const encoder = new TextEncoder();
	const size = value => encoder.encode(value).length;
	const body = () => ({ ids: [], changes: { ...changes }, expectedVersions: {} });
	const baseBytes = size(JSON.stringify({ ...body(), _csrf: csrf }));
	const requests = [];
	let current = body(); let bytes = baseBytes;
	for (const id of new Set(ids)) {
		const feed = byId.get(id);
		if (!feed || typeof feed.version !== 'string') throw new ReaderError(409, 'missing_feed_version');
		const addition = size(JSON.stringify(id)) + size(JSON.stringify(String(id)) + ':' + JSON.stringify(feed.version));
		if (baseBytes + addition > limits.maxRequestBytes) throw new ReaderError(413, 'request_too_large');
		if (current.ids.length && (current.ids.length >= limits.maxBatchItems || bytes + addition + 2 > limits.maxRequestBytes)) {
			requests.push(current); current = body(); bytes = baseBytes;
		}
		bytes += addition + (current.ids.length ? 2 : 0);
		current.ids.push(id); current.expectedVersions[id] = feed.version;
	}
	if (current.ids.length) requests.push(current);
	return requests;
}

/** Account-local batch progress; successful responses are applied as they arrive. */
export class FeedBatchUpdate extends EventTarget {
	constructor(api, store, limits) {
		super();
		this.api = api; this.store = store; this.limits = limits;
		this.running = false; this.results = []; this.progress = null;
		this.operation = 0; this.targets = []; this.sentIds = new Set();
	}

	emit() { this.dispatchEvent(new Event('change')); }
	stop() { this.stopping = true; this.emit(); }
	clear() { if (!this.running) { this.results = []; this.progress = null; this.emit(); } }
	failedIds() { return this.results.filter(result => result.status !== 'saved').map(result => result.id); }

	async run(ids, changes, feeds = this.store.feeds) {
		if (this.running || this.store.csrfInvalid) return;
		const requests = feedBatchRequests(ids, feeds, changes, this.limits, this.api.csrf);
		if (!requests.length || !Object.keys(changes).length) return;
		const names = new Map(feeds.map(feed => [feed.id, feed.name]));
		const epoch = this.store.sessionEpoch;
		const operation = ++this.operation;
		const controller = new AbortController(); this.controller = controller;
		this.targets = requests.flatMap(request => request.ids).map(id => ({ id, name: names.get(id) }));
		this.sentIds.clear();
		this.running = true; this.stopping = false; this.results = [];
		this.progress = { completed: 0, total: requests.reduce((sum, request) => sum + request.ids.length, 0), batches: requests.length };
		this.emit();
		try {
			for (const body of requests) {
				if (this.stopping) {
					for (const id of body.ids) this.results.push({ id, name: names.get(id), status: 'skipped' });
					continue;
				}
				try {
					for (const id of body.ids) this.sentIds.add(id);
					const data = await this.api.request('feedBatch', {}, { body, signal: controller.signal });
					if (operation !== this.operation || epoch !== this.store.sessionEpoch) return;
					this.store.mergeFeedSettings(data.feeds);
					const updated = new Map(data.feeds.map(feed => [feed.id, feed]));
					for (const id of body.ids) this.results.push({ id, name: names.get(id), status: 'saved', current: updated.get(id) });
				} catch (error) {
					if (operation !== this.operation || epoch !== this.store.sessionEpoch) return;
					if (error.status === 403) {
						if (error.message === 'csrf_invalid') this.dispose();
						this.store.reportError(error); return;
					}
					const conflicts = error.details?.conflicts || [];
					this.store.mergeFeedSettings(conflicts.map(conflict => conflict.current).filter(Boolean));
					for (const id of body.ids) {
						const conflict = conflicts.find(conflict => conflict.id === id);
						const status = error.status === 409 ? (conflict ? 'conflict' : 'blocked') : 'failed';
						this.results.push({ id, name: names.get(id), status, error, current: conflict?.current });
					}
					if (!error.status || error.status === 408 || error.message === 'processing_busy') this.stopping = true;
				}
				this.progress.completed += body.ids.length;
				this.emit();
			}
		} finally {
			if (operation === this.operation) {
				this.running = false;
				if (epoch !== this.store.sessionEpoch) { this.results = []; this.progress = null; }
				this.emit();
			}
		}
	}

	dispose(clear = false) {
		this.operation++; this.stopping = true; this.controller?.abort(); this.controller = null;
		if (this.running && !clear) {
			const completed = new Set(this.results.map(result => result.id));
			const error = new ReaderError(0, 'operation_interrupted');
			for (const target of this.targets) {
				if (!completed.has(target.id)) this.results.push({ ...target, status: this.sentIds.has(target.id) ? 'failed' : 'skipped', error });
			}
		}
		this.running = false; this.targets = []; this.sentIds.clear();
		if (clear) { this.results = []; this.progress = null; }
		this.emit();
	}
}
