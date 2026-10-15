/** Speculate after a short pause, using reading direction, pace, request latency and available cache. */
export class ReadingPreloader {
	constructor(store, { active, connection = null, onMore, now = () => performance.now() }) {
		Object.assign(this, { store, active, connection, onMore, now });
		this.direction = 1;
		this.pace = 4000;
		this.viewportRows = 6;
		this.distance = Infinity;
		this.velocity = 0;
		this.scrollIntent = false;
		this.timer = null;
	}

	update() {
		const { store } = this;
		const scope = JSON.stringify([store.query, store.cursor]);
		if (scope !== this.scope) {
			this.scope = scope; this.selectedId = null; this.hintId = null;
			this.direction = 1; this.pace = 4000; this.scrollIntent = false; this.lastSelection = null;
		}
		if (store.selectedId !== this.selectedId) {
			const index = store.items.findIndex(item => item.id === store.selectedId);
			const previous = store.items.findIndex(item => item.id === this.selectedId);
			if (index >= 0 && previous >= 0 && index !== previous) this.direction = Math.sign(index - previous);
			const time = this.now();
			if (this.lastSelection !== null && store.selectedId && this.selectedId) {
				this.pace = Math.max(100, Math.min(30000, (this.pace + time - this.lastSelection) / 2));
			}
			this.lastSelection = time; this.selectedId = store.selectedId; this.hintId = null;
		}
		this.schedule();
	}

	viewport(rows, distance, velocity = 0, intent = false) {
		this.viewportRows = rows; this.distance = distance; this.velocity = Math.max(0, velocity);
		this.scrollIntent ||= intent;
		this.schedule(false);
	}

	hint(id) { this.hintId = id; this.schedule(); }

	schedule(reset = true) {
		if (!this.active()) { this.suspend(); return; }
		if (!reset && this.timer !== null) return;
		// Rapid J/K presses or pointer movement should not download every intermediate article.
		clearTimeout(this.timer);
		this.timer = setTimeout(() => { this.timer = null; this.run(); }, 160);
	}

	run() {
		if (!this.active()) { this.suspend(); return; }
		const { store } = this;
		if (store.loading || store.batchProgress) return;
		const constrained = this.connection?.saveData || ['slow-2g', '2g'].includes(this.connection?.effectiveType);
		const index = store.items.findIndex(item => item.id === store.selectedId);
		const lead = Math.max(3, Math.min(store.pageSize, Math.max(Math.ceil(this.viewportRows * 0.75), Math.ceil(store.pageLatency / this.pace) + 2)));
		const readingNearEnd = !constrained && this.direction > 0 && index >= 0 && store.items.length - index - 1 <= lead;
		const scrollingNearEnd = this.scrollIntent && this.distance <= (constrained ? 1 : Math.max(200, this.velocity * (store.pageLatency + 300)));
		const fillViewport = !constrained && !store.selectedId && store.items.length >= store.pageSize && store.items.length < this.viewportRows;
		if (store.next && !store.moreError && (readingNearEnd || scrollingNearEnd || fillViewport)) this.onMore();
		if (constrained || store.loadingArticle) { store.prefetch([]); return; }
		const ids = this.hintId ? [this.hintId] : [];
		if (index >= 0 && store.article) {
			const count = Math.min(4, Math.max(1, Math.ceil(store.articleLatency / this.pace) + 1));
			for (let step = 1; step <= count; step++) {
				const item = store.items[index + this.direction * step];
				if (item) ids.push(item.id);
			}
			const behind = store.items[index - this.direction];
			if (behind) ids.push(behind.id);
		}
		store.prefetch(ids);
	}

	suspend() { clearTimeout(this.timer); this.timer = null; this.store.prefetch([]); }
}
