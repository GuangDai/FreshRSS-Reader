import { FeedBatchUpdate, feedChangeErrors } from './batch-update.js';

/** The batch editor owns its draft and progress; article navigation stays independent. */
export class FeedEditor {
	constructor({ api, store, selection, schema, limits, t, element, onStatus, pageSize }) {
		Object.assign(this, { store, selection, schema, t, element, onStatus, pageSize });
		this.batch = new FeedBatchUpdate(api, store, limits);
		this.dialog = document.getElementById('feed-settings-dialog');
		this.form = document.getElementById('feed-settings-form');
		this.fields = new Map();
		this.targetIds = []; this.targetFeeds = [];
		this.epoch = 0; this.resultPage = 0; this.reloading = false;
		this.batch.addEventListener('change', () => this.render());
		const updateDraft = event => {
			const field = [...this.fields.values()].find(field => field.input === event.target || field.enabled === event.target);
			if (!field) return;
			if (field.input === event.target) field.enabled.checked = this.fieldChanged(field);
			this.error = ''; this.info = '';
			this.dirty = [...this.fields.values()].some(field => this.fieldChanged(field));
			field.input.removeAttribute('aria-invalid'); this.render();
		};
		this.form.addEventListener('input', updateDraft);
		this.form.addEventListener('change', updateDraft);
		this.form.addEventListener('submit', event => { event.preventDefault(); this.submit(); });
		this.dialog.addEventListener('close', () => { this.epoch++; });
		document.getElementById('cancel-feed-settings').addEventListener('click', () => this.dialog.close());
		document.getElementById('show-feed-job').addEventListener('click', () => this.showProgress());
		document.getElementById('stop-feed-settings').addEventListener('click', () => this.batch.stop());
		document.getElementById('retry-feed-settings').addEventListener('click', () => this.reviewUnsaved());
		document.getElementById('feed-settings-results').addEventListener('click', event => {
			const button = event.target.closest('[data-result-page]');
			if (!button) return;
			this.resultPage += Number(button.dataset.resultPage); this.renderResults();
			document.getElementById('feed-settings-results').querySelector('ol')?.focus();
		});
		document.getElementById('reset-feed-settings').addEventListener('click', () => {
			for (const field of this.fields.values()) {
				field.enabled.checked = false; field.input.value = field.initial; field.input.removeAttribute('aria-invalid'); field.refreshHint();
			}
			this.error = ''; this.info = t('feedDraftCleared'); this.dirty = false; this.render();
		});
	}

	open() {
		this.epoch++;
		this.reviewingJob = this.batch.running;
		const sameTargets = this.targetIds.length === this.selection.ids.size && this.targetIds.every(id => this.selection.ids.has(id));
		if (!this.batch.running && (!this.dirty || !sameTargets || this.batch.progress)) {
			this.targetIds = [...this.selection.ids];
			const ids = new Set(this.targetIds);
			this.targetFeeds = this.store.feeds.filter(feed => ids.has(feed.id)).map(feed => ({ ...feed }));
			this.error = ''; this.info = ''; this.dirty = false; this.resultPage = 0; this.batch.clear(); this.buildFields();
		}
		this.render();
		if (!this.dialog.open) this.dialog.showModal();
	}

	showProgress() {
		this.epoch++; this.reviewingJob = true; this.render();
		if (!this.dialog.open) this.dialog.showModal();
		this.renderResults();
	}

	buildFields() {
		const root = document.getElementById('feed-settings-fields'); root.replaceChildren();
		this.fields.clear();
		for (const [key, spec] of Object.entries(this.schema.fields)) {
			const row = this.element('div', 'feed-setting-row');
			const enabled = this.element('input'); enabled.type = 'checkbox'; enabled.dataset.changeFeedField = key;
			enabled.setAttribute('aria-label', this.t('changeFeedField').replace('{field}', this.t('feedField_' + key)));
			const label = this.element('label', '', this.t('feedField_' + key)); label.htmlFor = 'feed-setting-' + key;
			const options = key === 'categoryId'
				? this.store.categories.map(category => [category.id, category.name])
				: spec.type === 'boolean'
					? [[true, this.t('feedEnabled')], [false, this.t('feedPaused')]]
					: (spec.options || spec.enum)?.map(value => [value,
						this.t('feedPriority_' + (spec.labels?.[value] || String(value).replace('-', 'minus')))]);
			const input = this.element(options ? 'select' : 'input');
			input.id = label.htmlFor; input.name = key;
			input.setAttribute('aria-describedby', 'feed-settings-status');
			if (options) {
				const empty = this.element('option', '', this.t('mixedFeedValues')); empty.value = ''; input.append(empty);
				for (const [value, title] of options) { const option = this.element('option', '', title); option.value = value; input.append(option); }
			} else {
				input.type = spec.type === 'integer' ? 'number' : 'text';
				if (spec.type === 'integer') {
					input.min = spec.min; input.max = spec.max; input.step = 1;
				} else if (spec.maxLength) input.maxLength = spec.maxLength;
				input.placeholder = this.t('mixedFeedValues');
			}
			const initial = this.targetFeeds[0]?.[key];
			const common = this.targetFeeds.every(feed => feed[key] === initial);
			input.value = common && initial !== undefined ? String(initial) : '';
			const controls = this.element('div', 'feed-value-controls'); controls.append(input);
			let refreshHint = () => {};
			if (key === 'ttl') {
				const hint = this.element('small', 'setting-range');
				refreshHint = () => {
					let seconds = Number(input.value);
					if (input.value === '' || !Number.isSafeInteger(seconds) || seconds < 0) { hint.textContent = ''; return; }
					const parts = [];
					for (const [unit, duration] of [['Days', 86400], ['Hours', 3600], ['Minutes', 60], ['Seconds', 1]]) {
						const count = Math.floor(seconds / duration); seconds %= duration;
						if (count) parts.push(this.t('duration' + unit).replace('{count}', count));
					}
					hint.textContent = parts.length ? parts.join(' ') : this.t('feedDefaultInterval');
				};
				input.addEventListener('input', refreshHint); refreshHint(); controls.append(hint);
			}
			row.append(enabled, label, controls);
			root.append(row);
			this.fields.set(key, { enabled, input, spec, initial: input.value, refreshHint });
		}
	}

	fieldChanged({ input, initial, spec }) {
		if (spec.type === 'integer' && input.value !== '' && initial !== '') return Number(input.value) !== Number(initial);
		return input.value.trim() !== initial.trim();
	}

	changes() {
		const changes = {};
		for (const [key, field] of this.fields) {
			const { enabled, input, spec } = field;
			if (!enabled.checked || !this.fieldChanged(field)) continue;
			changes[key] = spec.type === 'boolean'
				? (input.value === '' ? null : input.value === 'true')
				: spec.type === 'integer' ? (input.value === '' ? NaN : Number(input.value)) : input.value.trim();
		}
		return changes;
	}

	async submit() {
		if (this.store.csrfInvalid || this.batch.running || this.reloading) return;
		const changes = this.changes();
		const errors = feedChangeErrors(changes, this.schema.fields);
		for (const [key, field] of this.fields) field.input.setAttribute('aria-invalid', String(errors.includes(key)));
		if (errors.length) {
			this.error = this.t('invalidSettings') + errors.map(key => this.t('feedField_' + key)).join(', ');
			if (errors.length === 1 && errors[0] === 'name' && changes.name) this.error = this.t('feedNameTooLong');
			this.render(); this.fields.get(errors[0]).input.focus(); return;
		}
		if (!Object.keys(changes).length) return;
		this.error = ''; this.info = ''; this.resultPage = 0;
		this.submittedChanges = changes;
		try {
			await this.batch.run(this.targetIds, changes, this.targetFeeds);
			const saved = new Map(this.batch.results.filter(result => result.status === 'saved' && result.current).map(result => [result.id, result.current]));
			this.targetFeeds = this.targetFeeds.map(feed => saved.get(feed.id) || feed);
			for (const [key, field] of this.fields) {
				const value = this.targetFeeds[0]?.[key];
				field.initial = this.targetFeeds.every(feed => feed[key] === value) && value !== undefined ? String(value) : '';
				if (!this.fieldChanged(field)) field.enabled.checked = false;
			}
			this.dirty = false;
			this.render();
		} catch (error) {
			this.error = error.message === 'missing_feed_version' ? this.t('feedMissingVersion') : this.t('failed'); this.render();
		}
	}

	async reviewUnsaved() {
		if (this.store.csrfInvalid || this.batch.running || this.reloading) return;
		const epoch = this.epoch;
		const ids = new Set(this.batch.failedIds());
		const changes = this.changes();
		this.reloading = true; this.error = ''; this.info = this.t('loading'); this.render();
		try {
			await this.store.feedsRefresh();
			if (epoch !== this.epoch) return;
			const available = this.store.feeds.filter(feed => ids.has(feed.id));
			const missing = ids.size - available.length;
			const satisfied = feed => Object.keys(changes).length > 0 && Object.entries(changes).every(([key, value]) => feed[key] === value);
			const alreadyApplied = available.filter(satisfied).length;
			this.targetFeeds = available.filter(feed => !satisfied(feed)).map(feed => ({ ...feed }));
			this.targetIds = this.targetFeeds.map(feed => feed.id);
			this.reviewingJob = false;
			this.selection.replace(this.targetIds);
			this.buildFields(); this.batch.clear(); this.resultPage = 0;
			for (const [key, value] of Object.entries(changes)) {
				const field = this.fields.get(key);
				if (field) { field.enabled.checked = true; field.input.value = String(value); field.refreshHint(); }
			}
			this.info = this.t('reviewLatestFeedValues');
			if (missing) this.info += ' ' + this.t('missingFeedsSkipped').replace('{count}', missing);
			if (alreadyApplied) this.info += ' ' + this.t('feedValuesAlreadyApplied').replace('{count}', alreadyApplied);
			this.dirty = true;
		} catch (error) {
			if (epoch === this.epoch) this.error = this.t('failed');
			if (error.status === 403) this.store.reportError(error);
		} finally { if (epoch === this.epoch) { this.reloading = false; this.render(); } }
	}

	suspend(clear = false) {
		this.epoch++; this.reloading = false; this.batch.dispose(clear);
		if (clear) {
			this.targetFeeds = []; this.targetIds = []; this.fields.clear(); this.error = ''; this.info = '';
			document.getElementById('feed-settings-fields').replaceChildren();
			document.getElementById('feed-settings-results').replaceChildren();
			this.dialog.close();
		}
		this.render();
	}

	renderResults() {
		const root = document.getElementById('feed-settings-results');
		root.hidden = !this.batch.results.length;
		if (!this.dialog.open || root.hidden) return;
		const size = this.pageSize();
		const pages = Math.ceil(this.batch.results.length / size);
		this.resultPage = Math.max(0, Math.min(this.resultPage, pages - 1));
		root.replaceChildren();
		if (this.batch.results.some(result => result.status === 'conflict')) root.append(this.element('p', '', this.t('feedBatchConflictNote')));
		const list = this.element('ol'); list.tabIndex = -1; list.start = this.resultPage * size + 1;
		for (const result of this.batch.results.slice(this.resultPage * size, (this.resultPage + 1) * size)) {
			const item = this.element('li', '', `${result.name} · ${this.t('feedResult_' + result.status)}`);
			item.dataset.feedResult = result.status; item.dataset.feedId = result.id;
			if (result.current && result.status === 'conflict') {
				const current = Object.keys(this.submittedChanges || {}).map(key => {
					const value = typeof result.current[key] === 'boolean' ? this.t(result.current[key] ? 'feedEnabled' : 'feedPaused') : result.current[key];
					return this.t('feedField_' + key) + ': ' + value;
				}).join(', ');
				item.append(this.element('p', '', this.t('currentFeedValues') + current));
			} else if (result.error) {
				const field = result.error.details?.field;
				const message = result.error.message === 'processing_busy'
					? this.t('feedServerBusy')
					: result.error.message === 'name_too_long'
						? this.t('feedNameTooLong')
						: field ? this.t('invalidSettings') + this.t('feedField_' + field) : this.t('failed');
				item.append(this.element('p', '', message));
			}
			list.append(item);
		}
		root.append(list);
		if (pages > 1) {
			const previous = this.element('button', '', this.t('previous')); previous.type = 'button'; previous.dataset.resultPage = '-1';
			previous.disabled = this.resultPage === 0;
			const next = this.element('button', '', this.t('next')); next.type = 'button'; next.dataset.resultPage = '1';
			next.disabled = this.resultPage === pages - 1;
			root.append(previous, this.element('span', '', `${this.resultPage + 1} / ${pages}`), next);
		}
	}

	render() {
		const { batch, t } = this;
		const busy = batch.running || this.reloading || this.reviewingJob;
		for (const { enabled, input } of this.fields.values()) {
			enabled.disabled = busy; input.disabled = busy;
		}
		document.getElementById('feed-settings-targets').textContent = t('selectedFeedCount').replace('{count}', this.targetIds.length);
		document.getElementById('save-feed-settings').disabled = this.store.csrfInvalid || busy || !this.targetIds.length || !Object.keys(this.changes()).length;
		const reset = document.getElementById('reset-feed-settings');
		reset.hidden = ![...this.fields.values()].some(field => this.fieldChanged(field));
		reset.disabled = busy;
		document.getElementById('stop-feed-settings').hidden = !batch.running || batch.progress.batches < 2;
		document.getElementById('stop-feed-settings').disabled = batch.stopping;
		const progress = document.getElementById('feed-settings-progress');
		progress.hidden = !batch.running;
		if (batch.progress) { progress.max = batch.progress.total; progress.value = batch.progress.completed; }
		const saved = batch.results.filter(result => result.status === 'saved').length;
		const failed = batch.results.length - saved;
		const message = this.error || this.info || (batch.running
			? t(batch.stopping ? 'stoppingFeedBatches' : 'savingFeeds')
				.replace('{done}', batch.progress.completed).replace('{total}', batch.progress.total).replace('{batches}', batch.progress.batches)
			: batch.progress ? t('feedBatchResult').replace('{saved}', saved).replace('{failed}', failed) : '');
		const status = document.getElementById('feed-settings-status');
		status.hidden = !message; status.textContent = message;
		document.getElementById('retry-feed-settings').hidden = batch.running || !failed;
		document.getElementById('retry-feed-settings').disabled = this.reloading;
		document.getElementById('show-feed-job').hidden = !batch.progress;
		if (batch.progress && message !== this.lastStatus) { this.lastStatus = message; this.onStatus(message); }
		this.renderResults();
	}
}
