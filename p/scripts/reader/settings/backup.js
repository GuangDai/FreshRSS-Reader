export const BACKUP_SETTINGS_MESSAGES = {
	zh: {
		title: '自动 SQLite 备份',
		note: '此策略供服务器定时任务或 CLI 使用。启用后，还需要服务器执行 cli/export-sqlite-auto.php 才会生成备份。',
		enabled: '启用自动导出',
		enabledHelp: '允许服务器导出每位用户的数据库。',
		retention: '每位用户保留的备份份数',
		retentionHelp: '至少保留 1 份；成功导出后清理更旧的备份。修改此项不会立即删除文件。',
		copies: '份',
		loading: '正在读取备份策略…',
		loadError: '无法读取备份策略。',
		saveError: '保存失败，修改仍保留。',
		invalid: '备份份数须为至少 1 的整数。',
		save: '保存备份策略',
		reset: '还原更改',
		retry: '重新读取',
		saving: '正在保存…',
		saved: '备份策略已保存。',
		sessionError: '登录状态已失效，请刷新页面后重试。',
	},
	en: {
		title: 'Automatic SQLite backups',
		note: 'This policy is used by a server task or the CLI. Enabling it requires the server to run cli/export-sqlite-auto.php to create backups.',
		enabled: 'Enable automatic export',
		enabledHelp: 'Allow the server to export each account’s database.',
		retention: 'Backups retained per account',
		retentionHelp: 'Keep at least 1 backup. Older files are removed after a successful export; changing this value does not immediately delete files.',
		copies: 'backups',
		loading: 'Loading backup policy…',
		loadError: 'Backup policy could not be loaded.',
		saveError: 'Changes could not be saved. Your draft is preserved.',
		invalid: 'Enter a whole number of backups, at least 1.',
		save: 'Save backup policy',
		reset: 'Revert changes',
		retry: 'Try again',
		saving: 'Saving…',
		saved: 'Backup policy saved.',
		sessionError: 'Your session has expired. Reload to continue.',
	},
};

/** Keep browser number precision and the CLI’s minimum-one retention behavior explicit. */
export function backupPolicyChanges(fields) {
	const configuration = {}; const errors = {};
	for (const [key, field] of fields) {
		const raw = field.input.value?.trim();
		const value = key === 'enabled' ? field.input.checked : raw === '' ? NaN : Number(raw);
		if (key === 'retention' && (!Number.isSafeInteger(value) || value < (field.spec.min ?? 1) || value > (field.spec.max ?? Number.MAX_SAFE_INTEGER))) {
			errors[key] = 'invalid';
		} else if (value !== field.initial) configuration[key] = value;
	}
	return { configuration, errors };
}

/** Compact administrator policy editor. The host settings page owns navigation and its forms. */
export class BackupSettings {
	constructor({ root, api, bootstrap = {}, element, t, onError = () => {} }) {
		Object.assign(this, { root, api, bootstrap, element, t, onError });
		this.fields = new Map(); this.controllers = new Set(); this.epoch = 0;
		this.onInput = event => {
			if (![...this.fields.values()].some(field => field.input === event.target)) return;
			this.status.textContent = ''; this.showErrors({}); this.sync();
		};
		this.onKey = event => {
			if (event.key === 'Enter' && event.target === this.fields.get('retention')?.input) {
				event.preventDefault(); this.save();
			}
		};
	}

	text(key) {
		const translated = this.t?.('backup_' + key);
		if (translated && translated !== 'backup_' + key) return translated;
		const language = (this.bootstrap.language || this.bootstrap.lang || '').startsWith('zh') ? 'zh' : 'en';
		return BACKUP_SETTINGS_MESSAGES[language][key] || BACKUP_SETTINGS_MESSAGES.en[key] || key;
	}

	mount() {
		if (this.container) return this;
		this.container = this.root.matches?.('[data-backup-settings]') ? this.root : this.root.querySelector('[data-backup-settings]');
		if (!this.container) return this;
		if (this.bootstrap.canManageLimits !== true && this.bootstrap.isAdmin !== true) { this.container.hidden = true; return this; }
		this.wrapper = this.element('section', 'account-settings-group backup-settings');
		this.wrapper.append(this.element('h3', 'account-settings-group-title', this.text('title')));
		this.wrapper.append(this.element('p', 'account-settings-help', this.text('note')));
		this.status = this.element('p', 'account-settings-status', this.text('loading'));
		this.status.setAttribute('role', 'status'); this.status.setAttribute('aria-live', 'polite');
		this.retry = this.element('button', '', this.text('retry')); this.retry.type = 'button'; this.retry.hidden = true;
		this.retry.addEventListener('click', () => this.load());
		this.wrapper.append(this.status, this.retry); this.container.append(this.wrapper);
		this.wrapper.addEventListener('input', this.onInput); this.wrapper.addEventListener('change', this.onInput);
		this.wrapper.addEventListener('keydown', this.onKey);
		return this;
	}

	activate(section) {
		return section === 'data' && this.wrapper && !this.loaded ? this.load() : Promise.resolve();
	}

	async request(body) {
		const controller = new AbortController(); this.controllers.add(controller);
		try { return await this.api.request('backupPolicy', {}, { body, signal: controller.signal }); } finally { this.controllers.delete(controller); }
	}

	load() {
		if (this.loading || this.busy || this.sessionInvalid) return this.loading || Promise.resolve();
		const epoch = this.epoch;
		this.status.textContent = this.text('loading'); this.retry.hidden = true;
		this.loading = this.request().then(data => {
			if (this.epoch !== epoch) return;
			this.accept(data); this.loaded = true; this.status.textContent = '';
		}).catch(error => {
			if (this.epoch !== epoch) return;
			this.report(error); this.status.textContent = this.text(this.sessionInvalid ? 'sessionError' : 'loadError');
			this.retry.hidden = this.sessionInvalid;
		}).finally(() => { if (this.epoch === epoch) this.loading = null; });
		return this.loading;
	}

	build(schema) {
		for (const key of ['enabled', 'retention']) {
			const row = this.element('div', 'account-settings-row'); row.dataset.backupField = key;
			const copy = this.element('div', 'account-settings-label');
			const label = this.element('label', '', this.text(key)); label.htmlFor = 'backup-setting-' + key;
			const help = this.element('small', 'account-settings-help', this.text(key + 'Help')); help.id = label.htmlFor + '-help';
			copy.append(label, help);
			const controls = this.element('div', 'account-settings-control');
			const input = this.element('input'); input.id = label.htmlFor; input.name = key;
			input.type = key === 'enabled' ? 'checkbox' : 'number';
			if (key === 'enabled') input.setAttribute('role', 'switch');
			else { input.min = schema[key].min ?? 1; input.step = 1; input.required = true; }
			input.setAttribute('aria-describedby', help.id + ' ' + label.htmlFor + '-error');
			controls.append(input);
			if (key === 'retention') controls.append(this.element('span', 'account-settings-unit', this.text('copies')));
			const error = this.element('p', 'account-settings-error'); error.id = label.htmlFor + '-error'; error.hidden = true;
			row.append(copy, controls, error); this.wrapper.insertBefore(row, this.status);
			this.fields.set(key, { input, error, spec: schema[key], initial: undefined });
		}
		this.actions = this.element('div', 'account-settings-actions');
		this.resetButton = this.element('button', '', this.text('reset')); this.resetButton.type = 'button';
		this.saveButton = this.element('button', 'primary', this.text('save')); this.saveButton.type = 'button';
		this.resetButton.addEventListener('click', () => this.reset()); this.saveButton.addEventListener('click', () => this.save());
		this.actions.append(this.status, this.resetButton, this.saveButton); this.wrapper.append(this.actions);
	}

	accept(data, sent = {}) {
		if (typeof data.configuration?.enabled !== 'boolean' || !Number.isSafeInteger(data.configuration?.retention) ||
			data.configuration.retention < 1 || data.schema?.enabled?.type !== 'boolean' || data.schema?.retention?.type !== 'integer') {
			throw new Error('invalid_response');
		}
		if (!this.fields.size) this.build(data.schema);
		for (const [key, field] of this.fields) {
			const current = key === 'enabled' ? field.input.checked : Number(field.input.value);
			if (field.initial === undefined || current === field.initial || (Object.hasOwn(sent, key) && current === sent[key])) {
				this.setValue(key, data.configuration[key]);
			}
			field.initial = data.configuration[key]; field.spec = data.schema[key];
		}
		this.showErrors({}); this.sync();
	}

	setValue(key, value) {
		const { input } = this.fields.get(key);
		if (key === 'enabled') input.checked = value;
		else input.value = String(value);
	}

	showErrors(errors) {
		for (const [key, field] of this.fields) {
			const invalid = Boolean(errors[key]); field.error.hidden = !invalid;
			field.error.textContent = invalid ? this.text('invalid') : '';
			if (invalid) field.input.setAttribute('aria-invalid', 'true'); else field.input.removeAttribute('aria-invalid');
		}
	}

	hasDraft(section) {
		if (section && section !== 'data') return false;
		const { configuration, errors } = backupPolicyChanges(this.fields);
		return Object.keys(configuration).length > 0 || Object.keys(errors).length > 0;
	}

	sync() {
		if (!this.saveButton) return;
		const { errors } = backupPolicyChanges(this.fields);
		const busy = Boolean(this.busy || this.sessionInvalid);
		for (const field of this.fields.values()) field.input.disabled = busy;
		this.saveButton.disabled = busy || !this.hasDraft() || Object.keys(errors).length > 0;
		this.saveButton.textContent = this.text(this.busy ? 'saving' : 'save');
		this.resetButton.disabled = busy; this.resetButton.hidden = !this.hasDraft();
	}

	reset() {
		if (this.busy || this.sessionInvalid) return;
		for (const [key, field] of this.fields) this.setValue(key, field.initial);
		this.status.textContent = ''; this.showErrors({}); this.sync();
	}

	async save() {
		if (this.busy || this.sessionInvalid) return;
		const { configuration, errors } = backupPolicyChanges(this.fields); this.showErrors(errors);
		if (Object.keys(errors).length) { this.fields.get(Object.keys(errors)[0]).input.focus(); this.sync(); return; }
		if (!Object.keys(configuration).length) return;
		const epoch = this.epoch; this.busy = true; this.status.textContent = ''; this.sync();
		try {
			const data = await this.request({ configuration });
			if (this.epoch !== epoch) return;
			this.accept(data, configuration); this.status.textContent = this.text('saved');
		} catch (error) {
			if (this.epoch !== epoch) return;
			this.report(error);
			this.status.textContent = this.text(this.sessionInvalid ? 'sessionError' : 'saveError');
			const key = error.details?.field;
			if (this.fields.has(key)) this.showErrors({ [key]: true });
		} finally {
			if (this.epoch === epoch) { this.busy = false; this.sync(); }
		}
	}

	report(error) {
		const sessionError = ['csrf', 'csrf_invalid', 'authentication_required', 'administrator_required'].includes(error.message);
		if (error.status === 401 || (error.status === 403 && sessionError)) {
			this.sessionInvalid = true;
		}
		this.onError(error); this.sync();
	}

	dispose() {
		this.epoch++; for (const controller of this.controllers) controller.abort(); this.controllers.clear();
		this.wrapper?.removeEventListener('input', this.onInput); this.wrapper?.removeEventListener('change', this.onInput);
		this.wrapper?.removeEventListener('keydown', this.onKey); this.wrapper?.remove(); this.wrapper = null;
		this.fields.clear(); this.loaded = false; this.loading = null; this.busy = false;
	}
}
