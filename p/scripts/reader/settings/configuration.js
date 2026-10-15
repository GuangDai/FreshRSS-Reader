const isObject = value => value !== null && typeof value === 'object' && !Array.isArray(value);
const bytes = value => new TextEncoder().encode(value).length;
const pretty = value => JSON.stringify(value, null, 2);
const omitted = Symbol('omitted');

export const SETTINGS_TOOLS_MESSAGES = {
	en: {
		toolsRuleTitle: 'Article filters',
		toolsRuleNote: 'With the Rule Engine extension enabled, saved rules can mark articles read, star them or add tags during refresh. ' +
			'Saving changes the configuration only. Enable the extension in Extensions and sharing.',
		toolsExtractionTitle: 'Content extraction',
		toolsExtractionNote: 'With the Web Extraction extension enabled, saved recipes extract titles, bodies, authors and links from received content. ' +
			'Saving changes the configuration only. Enable the extension in Extensions and sharing.',
		toolsAdvanced: 'Advanced configuration',
		toolsRulesJSON: 'Rules JSON',
		toolsRecipes: 'Extraction recipes JSON',
		toolsHookPriority: 'Processing order',
		toolsHookPriorityHelp: 'Lower priorities run first. Change this only when coordinating multiple extensions.',
		toolsSave: 'Save changes',
		toolsDiscard: 'Discard changes',
		toolsSaved: 'Changes saved.',
		toolsDraft: 'Unsaved changes',
		toolsRetry: 'Retry',
		toolsLoading: 'Loading…',
		toolsSaving: 'Saving…',
		toolsFailed: 'Could not load this tool. Retry to continue.',
		toolsSaveFailed: 'Could not save. Your draft is still here; try again.',
		toolsFieldInvalid: 'Check this value.',
		toolsConflict: 'This configuration changed in another session. Your draft has been kept. Compare it with the current settings before continuing.',
		toolsCurrent: 'Current server configuration',
		toolsUseCurrent: 'Use current configuration',
		toolsReviewDraft: 'Review draft against current settings',
		toolsDraftRebased: 'Your changes have been kept against the current configuration. Review them before saving.',
		toolsPresetsFailed: 'Preset catalog could not be loaded. Existing configurations are still editable.',
		toolsExport: 'Export configuration',
		toolsImport: 'Import configuration',
		toolsImported: 'Configuration imported as a draft. Review it and save to apply.',
		toolsImportInvalid: 'This file is not a compatible configuration, contains unsupported fields or exceeds the size limit.',
		toolsFileLabel: 'Configuration JSON file',
		toolsSecret: 'Leave empty to preserve the saved value.',
		toolsJSONInvalid: 'Enter valid JSON.',
		toolsObjectRequired: 'Enter a JSON object.',
		toolsArrayRequired: 'Enter a JSON array.',
		toolsNumberInvalid: 'Enter a finite number within the allowed range.',
		toolsIntegerInvalid: 'Enter an integer within the browser’s exact numeric range.',
		toolsTextLimit: 'This text exceeds the allowed byte length.',
		toolsCollectionLimit: 'This configuration exceeds the allowed size or item count.',
		toolsUnsafeJSON: 'This JSON contains integers the browser cannot represent exactly. Use the advanced editor to keep the original values.',
		toolsEmptyRules: 'No article rules configured. Use the Visual rule editor below to create conditions and actions.',
		toolsEmptyRecipes: 'No extraction recipes configured.',
		toolsDefaultRecipe: 'All subscriptions (fallback)',
		toolsEnabled: 'Enabled',
		toolsDisabled: 'Disabled',
		toolsEnableRule: 'Enable {name}',
		toolsMoveUp: 'Move {name} up',
		toolsMoveDown: 'Move {name} down',
		toolsPreset: 'Add a preset',
		toolsAddPreset: 'Add to draft',
		toolsPresetAdded: 'Preset added to the draft. Adjust its conditions before saving.',
		toolsPreset_sponsored: 'Mark sponsored content read',
		toolsPreset_releases: 'Star releases',
		toolsPreset_empty_content: 'Star empty articles',
		toolsPreset_short_content: 'Star short articles',
		toolsPreset_repeated_promotion: 'Mark repeated promotion read',
		toolsPreset_author_watch: 'Watch an author',
		toolsPreset_feed_watch: 'Watch a feed',
		toolsPreset_article: 'HTML article',
		toolsPreset_json_item: 'JSON article',
		toolsPreview: 'Test without saving',
		toolsPreviewNote: 'Tests use only the text you provide. They do not change articles or fetch websites.',
		toolsSample: 'Sample article JSON',
		toolsSource: 'Source HTML or JSON',
		toolsPreviewFeed: 'Subscription ID or *',
		toolsBaseURL: 'Base URL for relative links',
		toolsUpdated: 'Test an updated article',
		toolsRunPreview: 'Run test',
		toolsPreviewFailed: 'Could not run the test. Check the inputs and try again.',
		toolsPreviewResult: 'Test result',
		toolsPreviewStale: 'The draft or sample changed. Run the test again to see current results.',
		toolsNoRecipe: 'No extraction recipe matches this subscription ID.',
		toolsPreviewSampleInvalid: 'Use an object whose values are strings or lists of strings.',
		toolsScheduler: 'Refresh queue',
		toolsSchedulerNote: 'Adjust background refresh, retry and maintenance behaviour. A server task must run the queue worker.',
		toolsPauseQueue: 'Pause queue',
		toolsResumeQueue: 'Resume queue',
		toolsPauseNote: 'Pausing prevents new jobs from being claimed. Running jobs are allowed to finish.',
		toolsQueueChanged: 'Queue state updated.',
		toolsQueueFields: 'Refresh and maintenance',
		toolsQueueAdvanced: 'Retry, recovery and history',
		toolsQueueLeaseError: 'Crash recovery lease must be longer than the job timeout plus its termination grace period.',
		toolsQueueRetryError: 'Maximum retry delay must be at least the first retry delay.',
		toolsQueueJitterError: 'Retry variation must be between 0 and 100%.',
		toolsQueueTimeoutError: 'Job timeout plus termination grace period exceeds the server’s supported timer range.',
		toolsFieldTitle: 'Title',
		toolsFieldContent: 'Body',
		toolsFieldAuthor: 'Authors',
		toolsFieldLink: 'Link',
		toolsKind_feed: 'Feed refresh',
		toolsKind_maintenance: 'Account maintenance',
		toolsKind_cache: 'Cache maintenance',
		toolsOutcome_ok: 'Completed',
		toolsOutcome_removed: 'Source removed',
		toolsOutcome_busy: 'Busy; retry scheduled',
		toolsOutcome_fetch_error: 'Fetch failed',
		toolsOutcome_exception: 'Processing failed',
		toolsOutcome_timeout: 'Timed out',
		toolsOutcome_crashed: 'Worker stopped',
		toolsQueueRange: 'Allowed range: {min}–{max} {unit}',
		toolsAdminOnly: 'Queue status is available to administrators. Refresh behaviour for your subscriptions can be adjusted in subscription settings.',
		toolsRefreshStatus: 'Refresh status',
		toolsSchedulerPaused: 'Queue paused',
		toolsSchedulerRunning: 'Queue available',
		toolsSchedulerDisabled: 'Queue disabled; the standard refresh method is in use.',
		toolsSchedulerConfiguration: 'Server queue configuration',
		toolsPending: 'Pending',
		toolsRunning: 'Running',
		toolsRetrying: 'Retrying',
		toolsDue: 'Due now',
		toolsOldestDue: 'Oldest due task',
		toolsHistory: 'Recent refresh tasks',
		toolsHistoryEmpty: 'No retained task history.',
		toolsHistoryMore: 'Show earlier tasks',
		toolsHistoryTime: 'Finished',
		toolsHistoryFeed: 'Subscription',
		toolsHistoryUser: 'Account',
		toolsHistoryKind: 'Task',
		toolsHistoryOutcome: 'Result',
		toolsHistoryDuration: 'Duration',
		toolsHistoryMemory: 'Peak memory',
		toolsConfigEnabled: 'Enabled',
		toolsConfigWorkers: 'Concurrent jobs',
		toolsConfigPerUser: 'Jobs per account',
		toolsConfigPerHost: 'Jobs per host',
		toolsConfigWorkerMemory: 'Memory per job',
		toolsConfigJobTimeout: 'Job timeout',
		toolsConfigKillGrace: 'Termination grace period',
		toolsConfigLease: 'Crash recovery lease',
		toolsConfigRun: 'Queue run duration',
		toolsConfigRetryBase: 'First retry delay',
		toolsConfigRetryMax: 'Maximum retry delay',
		toolsConfigRetryJitter: 'Retry variation',
		toolsConfigMaintenance: 'Maintenance interval',
		toolsConfigWebSub: 'WebSub fallback interval',
		toolsConfigHistoryLimit: 'Retained history count',
		toolsConfigStatusHistory: 'History page size',
		toolsConfigHistoryDays: 'History retention',
		toolsConfigBusyTimeout: 'Database lock timeout',
		toolsConfigPoll: 'Supervisor poll interval',
		toolsConfigPlanBatch: 'Planning batch size',
		toolsTrue: 'Yes',
		toolsFalse: 'No',
		toolsMilliseconds: '{value} ms',
		toolsSeconds: '{value} s',
		toolsDays: '{value} days',
		toolsMiB: '{value} MiB',
		toolsPercent: '{value}%',
	},
	zh: {
		toolsRuleTitle: '文章过滤规则',
		toolsRuleNote: '启用 Rule Engine 扩展后，已保存的规则会在订阅更新时将匹配的文章标为已读、收藏或添加标签。' +
			'保存仅修改配置，不会自动启用扩展；可在“扩展与分享”中启用。',
		toolsExtractionTitle: '内容提取',
		toolsExtractionNote: '启用 Web Extraction 扩展后，已保存的配方会从 FreshRSS 收到的内容中提取标题、正文、作者和链接。' +
			'保存仅修改配置，不会自动启用扩展；可在“扩展与分享”中启用。',
		toolsAdvanced: '高级配置',
		toolsRulesJSON: '规则 JSON',
		toolsRecipes: '提取配方 JSON',
		toolsHookPriority: '处理顺序',
		toolsHookPriorityHelp: '数值较小的插件先运行。仅在需要协调多个插件的处理顺序时调整。',
		toolsSave: '保存更改',
		toolsDiscard: '放弃更改',
		toolsSaved: '更改已保存。',
		toolsDraft: '尚未保存',
		toolsRetry: '重试',
		toolsLoading: '正在加载…',
		toolsSaving: '正在保存…',
		toolsFailed: '工具加载失败，可重试。',
		toolsSaveFailed: '保存失败，草稿已保留，请重试。',
		toolsFieldInvalid: '请检查此项。',
		toolsConflict: '其他会话已修改此配置。当前草稿已保留，请先对照服务器配置，再决定如何修改。',
		toolsCurrent: '服务器当前配置',
		toolsUseCurrent: '使用服务器配置',
		toolsReviewDraft: '按最新配置重新审阅草稿',
		toolsDraftRebased: '已在最新配置上保留你的修改，请检查后再保存。',
		toolsPresetsFailed: '预设加载失败，已有配置仍可编辑。',
		toolsExport: '导出配置',
		toolsImport: '导入配置',
		toolsImported: '配置已导入草稿，检查后保存才会生效。',
		toolsImportInvalid: '文件格式不兼容、包含不支持的字段，或超过大小限制。',
		toolsFileLabel: '配置 JSON 文件',
		toolsSecret: '留空将保留已保存的值。',
		toolsJSONInvalid: '请输入有效的 JSON。',
		toolsObjectRequired: '请输入 JSON 对象。',
		toolsArrayRequired: '请输入 JSON 数组。',
		toolsNumberInvalid: '请输入允许范围内的有限数值。',
		toolsIntegerInvalid: '请输入浏览器能精确表示的整数。',
		toolsTextLimit: '文本超过允许的字节长度。',
		toolsCollectionLimit: '配置超过允许的大小或条目数量。',
		toolsUnsafeJSON: 'JSON 包含浏览器无法精确表示的整数。请使用高级编辑器保留原值。',
		toolsEmptyRules: '尚未配置文章规则。可使用下方“可视化规则编辑器”创建条件和动作。',
		toolsEmptyRecipes: '尚未配置提取配方。',
		toolsDefaultRecipe: '所有订阅的备用配方',
		toolsEnabled: '已启用',
		toolsDisabled: '已停用',
		toolsEnableRule: '启用 {name}',
		toolsMoveUp: '上移 {name}',
		toolsMoveDown: '下移 {name}',
		toolsPreset: '添加预设',
		toolsAddPreset: '加入草稿',
		toolsPresetAdded: '预设已加入草稿，保存前请调整匹配条件。',
		toolsPreset_sponsored: '推广内容标为已读',
		toolsPreset_releases: '收藏版本发布',
		toolsPreset_empty_content: '收藏空正文文章',
		toolsPreset_short_content: '收藏简短文章',
		toolsPreset_repeated_promotion: '重复推广标为已读',
		toolsPreset_author_watch: '关注指定作者',
		toolsPreset_feed_watch: '关注指定订阅',
		toolsPreset_article: 'HTML 文章',
		toolsPreset_json_item: 'JSON 文章',
		toolsPreview: '测试配置',
		toolsPreviewNote: '测试只使用你提供的文本，不会修改文章或访问网站。',
		toolsSample: '测试文章 JSON',
		toolsSource: '源 HTML 或 JSON',
		toolsPreviewFeed: '订阅 ID 或 *',
		toolsBaseURL: '相对链接的基准网址',
		toolsUpdated: '模拟更新已有文章',
		toolsRunPreview: '运行测试',
		toolsPreviewFailed: '测试失败，请检查输入后重试。',
		toolsPreviewResult: '测试结果',
		toolsPreviewStale: '配置或测试内容已更改，请重新运行测试。',
		toolsNoRecipe: '没有适用于此订阅 ID 的提取配方。',
		toolsPreviewSampleInvalid: '请输入 JSON 对象，每个字段的值须为字符串或字符串列表。',
		toolsScheduler: '订阅刷新队列',
		toolsSchedulerNote: '调整后台刷新、失败重试和维护行为。队列工作进程需要由服务器计划任务运行。',
		toolsPauseQueue: '暂停队列',
		toolsResumeQueue: '恢复队列',
		toolsPauseNote: '暂停后不再领取新任务，已经运行的任务会继续完成。',
		toolsQueueChanged: '队列状态已更新。',
		toolsQueueFields: '刷新与维护',
		toolsQueueAdvanced: '重试、故障恢复与记录',
		toolsQueueLeaseError: '崩溃恢复等待时间必须大于任务超时与终止宽限时间之和。',
		toolsQueueRetryError: '重试等待上限不能小于首次重试等待时间。',
		toolsQueueJitterError: '重试时间浮动须为 0 至 100%。',
		toolsQueueTimeoutError: '任务超时与终止宽限时间之和超过服务器支持的定时范围。',
		toolsFieldTitle: '标题',
		toolsFieldContent: '正文',
		toolsFieldAuthor: '作者',
		toolsFieldLink: '链接',
		toolsKind_feed: '订阅刷新',
		toolsKind_maintenance: '账号维护',
		toolsKind_cache: '缓存维护',
		toolsOutcome_ok: '已完成',
		toolsOutcome_removed: '订阅已移除',
		toolsOutcome_busy: '繁忙，等待重试',
		toolsOutcome_fetch_error: '获取失败',
		toolsOutcome_exception: '处理失败',
		toolsOutcome_timeout: '任务超时',
		toolsOutcome_crashed: '工作进程已退出',
		toolsQueueRange: '允许范围：{min}–{max} {unit}',
		toolsAdminOnly: '刷新队列状态仅向管理员开放。你可以在订阅设置中调整自己的刷新频率。',
		toolsRefreshStatus: '刷新状态',
		toolsSchedulerPaused: '队列已暂停',
		toolsSchedulerRunning: '队列可运行',
		toolsSchedulerDisabled: '队列未启用，正在使用常规刷新方式。',
		toolsSchedulerConfiguration: '服务器队列配置',
		toolsPending: '待处理',
		toolsRunning: '运行中',
		toolsRetrying: '待重试',
		toolsDue: '已到期',
		toolsOldestDue: '最早到期任务',
		toolsHistory: '最近刷新任务',
		toolsHistoryEmpty: '没有已保留的任务记录。',
		toolsHistoryMore: '查看更早的任务',
		toolsHistoryTime: '完成时间',
		toolsHistoryFeed: '订阅',
		toolsHistoryUser: '账号',
		toolsHistoryKind: '任务',
		toolsHistoryOutcome: '结果',
		toolsHistoryDuration: '耗时',
		toolsHistoryMemory: '峰值内存',
		toolsConfigEnabled: '启用队列',
		toolsConfigWorkers: '并发任务数',
		toolsConfigPerUser: '每个账号的并发数',
		toolsConfigPerHost: '每个主机的并发数',
		toolsConfigWorkerMemory: '单任务内存',
		toolsConfigJobTimeout: '任务超时',
		toolsConfigKillGrace: '终止宽限时间',
		toolsConfigLease: '崩溃恢复等待',
		toolsConfigRun: '单次队列运行时长',
		toolsConfigRetryBase: '首次重试等待',
		toolsConfigRetryMax: '重试等待上限',
		toolsConfigRetryJitter: '重试时间浮动',
		toolsConfigMaintenance: '维护间隔',
		toolsConfigWebSub: 'WebSub 备用轮询间隔',
		toolsConfigHistoryLimit: '保留记录数',
		toolsConfigStatusHistory: '记录分页大小',
		toolsConfigHistoryDays: '记录保留天数',
		toolsConfigBusyTimeout: '数据库锁等待',
		toolsConfigPoll: '监控轮询间隔',
		toolsConfigPlanBatch: '规划批次大小',
		toolsTrue: '是',
		toolsFalse: '否',
		toolsMilliseconds: '{value} 毫秒',
		toolsSeconds: '{value} 秒',
		toolsDays: '{value} 天',
		toolsMiB: '{value} MiB',
		toolsPercent: '{value}%',
	},
};

function safeJSONNumbers(value) {
	const pending = [value];
	while (pending.length) {
		const next = pending.pop();
		if (typeof next === 'number' && (!Number.isFinite(next) || (Number.isInteger(next) && !Number.isSafeInteger(next)))) return false;
		if (next !== null && typeof next === 'object') for (const item of Object.values(next)) pending.push(item);
	}
	return true;
}

const normalValue = (value, spec) => spec.type === 'object' && Array.isArray(value) && !value.length ? {} : value;
const sameValue = (left, right, spec) => JSON.stringify(normalValue(left, spec)) === JSON.stringify(normalValue(right, spec));

/** Typed partial changes preserve omitted secrets and reject browser precision loss. */
export function settingsValue(raw, spec) {
	if (raw === '' && spec.secret) return omitted;
	if (raw === '' && spec.nullable) return null;
	if (raw === '' && !spec.required && !Object.hasOwn(spec, 'default')) return omitted;
	let value;
	if (spec.enum || ['object', 'array'].includes(spec.type)) {
		try { value = JSON.parse(raw); } catch { throw new Error('toolsJSONInvalid'); }
		if (!safeJSONNumbers(value)) throw new Error('toolsUnsafeJSON');
	} else if (spec.type === 'boolean') {
		if (!['true', 'false'].includes(raw)) throw new Error('toolsFieldInvalid');
		value = raw === 'true';
	} else if (['integer', 'number'].includes(spec.type)) {
		value = raw.trim() === '' ? NaN : Number(raw);
		if (!Number.isFinite(value)) throw new Error('toolsNumberInvalid');
		if (spec.type === 'integer' && (!/^[+-]?\d+$/.test(raw) || !Number.isSafeInteger(value))) throw new Error('toolsIntegerInvalid');
	} else value = raw;
	if (spec.type === 'object' && !isObject(value) && !(Array.isArray(value) && value.length === 0)) throw new Error('toolsObjectRequired');
	if (spec.type === 'array' && !Array.isArray(value)) throw new Error('toolsArrayRequired');
	if (spec.enum && !spec.enum.some(option => JSON.stringify(option) === JSON.stringify(value))) throw new Error('toolsFieldInvalid');
	if (typeof value === 'number' && (value < (spec.min ?? -Infinity) || value > (spec.max ?? Infinity))) throw new Error('toolsNumberInvalid');
	if (typeof value === 'string' && (bytes(value) < (spec.minLength ?? 0) || bytes(value) > (spec.maxLength ?? Infinity))) throw new Error('toolsTextLimit');
	if (value !== null && typeof value === 'object' && Object.keys(value).length > (spec.maxItems ?? Infinity)) throw new Error('toolsCollectionLimit');
	if (bytes(JSON.stringify(value)) > (spec.maxBytes ?? Infinity)) throw new Error('toolsCollectionLimit');
	return value;
}

export function configurationChanges(fields) {
	const values = {}; const errors = {};
	for (const [key, field] of fields) {
		if (field.input.value === field.initial) continue;
		try {
			const value = settingsValue(field.input.value, field.spec);
			if (value !== omitted && !sameValue(value, field.saved, field.spec)) values[key] = value;
		} catch (error) { errors[key] = error.message; }
	}
	return { values, errors };
}

export function configurationDocument(configuration, values) {
	const clean = {};
	for (const [key, spec] of Object.entries(configuration.schema)) {
		if (!spec.secret && Object.hasOwn(values, key)) clean[key] = normalValue(values[key], spec);
	}
	return { version: 1, extension: configuration.extension, configuration: clean, redacted: configuration.redacted || [] };
}

export function importedConfiguration(documentValue, snapshot) {
	if (!isObject(documentValue) || documentValue.version !== 1 || documentValue.extension !== snapshot.extension ||
		!isObject(documentValue.configuration) || Object.keys(documentValue).some(key => !['version', 'extension', 'configuration', 'redacted'].includes(key))) {
		throw new Error('toolsImportInvalid');
	}
	const values = {};
	for (const [key, value] of Object.entries(documentValue.configuration)) {
		const spec = snapshot.schema[key];
		if (!Object.hasOwn(snapshot.schema, key) || spec.secret) throw new Error('toolsImportInvalid');
		const raw = encodeField(value, spec);
		const typed = settingsValue(raw, spec);
		if (typed === omitted || !sameValue(typed, value, spec)) throw new Error('toolsImportInvalid');
		values[key] = value;
	}
	return values;
}

function encodeField(value, spec) {
	if (value === undefined || value === null) return '';
	if (spec.enum || ['object', 'array'].includes(spec.type)) {
		return pretty(spec.type === 'object' && Array.isArray(value) && value.length === 0 ? {} : value);
	}
	return String(value);
}

const schedulerLabels = {
	enabled: 'toolsConfigEnabled',
	workers: 'toolsConfigWorkers',
	per_user: 'toolsConfigPerUser',
	per_host: 'toolsConfigPerHost',
	worker_memory_mib: 'toolsConfigWorkerMemory',
	job_timeout: 'toolsConfigJobTimeout',
	kill_grace: 'toolsConfigKillGrace',
	lease_seconds: 'toolsConfigLease',
	run_seconds: 'toolsConfigRun',
	retry_base: 'toolsConfigRetryBase',
	retry_max: 'toolsConfigRetryMax',
	retry_jitter_percent: 'toolsConfigRetryJitter',
	maintenance_interval: 'toolsConfigMaintenance',
	websub_poll_interval: 'toolsConfigWebSub',
	history_limit: 'toolsConfigHistoryLimit',
	status_history_limit: 'toolsConfigStatusHistory',
	history_days: 'toolsConfigHistoryDays',
	busy_timeout_ms: 'toolsConfigBusyTimeout',
	poll_ms: 'toolsConfigPoll',
	plan_batch_size: 'toolsConfigPlanBatch',
};

/** Native tools occupy the settings page's existing sections and load only on demand. */
export class ConfigurationSettings {
	constructor({ api, bootstrap, store, t, element, root = document, onStatus = () => {} }) {
		Object.assign(this, { api, bootstrap, store, t, element, root, onStatus });
		this.panels = new Map(); this.controllers = new Set(); this.epoch = 0;
	}

	text(key, values = {}) {
		const translated = this.t?.(key);
		const local = SETTINGS_TOOLS_MESSAGES[this.bootstrap.language?.startsWith('zh') ? 'zh' : 'en'];
		return (translated && translated !== key ? translated : local[key] || key).replace(/\{(\w+)\}/g, (_, name) => String(values[name] ?? ''));
	}

	node(tag, className = '', text) {
		return this.element(tag, className, text);
	}

	button(key, action, className = '') {
		const node = this.node('button', className, this.text(key)); node.type = 'button';
		node.addEventListener('click', action); return node;
	}

	mount() { return this; }

	container(section) {
		return this.root.querySelector(`[data-settings-tools="${section}"]`) || this.root.querySelector('#tools-' + section);
	}

	async request(action, params = {}, body) {
		const controller = new AbortController(); this.controllers.add(controller);
		try {
			return await this.api.request(action, params, { signal: controller.signal, ...(body ? { body } : {}) });
		} finally { this.controllers.delete(controller); }
	}

	async activate(section) {
		section = section.replace(/^tools-/, '');
		this.activeSection = section;
		const extension = { filters: 'RuleEngine', feeds: 'WebExtraction' }[section];
		if (extension) await this.loadExtension(section, extension);
		if (section === 'automation') await this.loadScheduler();
		this.focusError(this.panels.get(section));
	}

	/** Synchronize changes made by a visual editor without replacing a local draft. */
	async refreshExtension(section) {
		section = section.replace(/^tools-/, '');
		const panel = this.panels.get(section);
		if (!panel?.configuration) return this.activate(section);
		if (panel.busy) return;
		const epoch = this.epoch; panel.busy = true; this.sync(panel);
		try {
			const result = await this.request('extensionConfig', { extension: panel.configuration.extension });
			if (epoch !== this.epoch || result.configuration.version === panel.configuration.version) return;
			const draft = configurationChanges(panel.fields);
			panel.busy = false;
			if (Object.keys(draft.values).length || Object.keys(draft.errors).length) this.showConflict(panel, result.configuration);
			else { panel.configuration = result.configuration; this.restore(panel); }
		} catch (error) {
			if (epoch !== this.epoch) return;
			this.message(panel, 'toolsFailed', {}, true); this.report(error);
		} finally { if (epoch === this.epoch) { panel.busy = false; this.sync(panel); this.focusError(panel); } }
	}

	message(panel, key, values = {}, error = false) {
		panel.status.textContent = key ? this.text(key, values) : ''; panel.status.hidden = !key;
		panel.status.classList.toggle('settings-tool-error', error);
		if (key) this.onStatus(panel.status.textContent);
	}

	makePanel(section, title, note) {
		const container = this.container(section); if (!container) return null;
		const wrapper = this.node('section', 'settings-tool-panel'); wrapper.dataset.toolSection = section;
		const header = this.node('div', 'settings-tool-header'); header.append(this.node('h3', '', this.text(title)));
		const description = this.node('p', 'settings-note', this.text(note));
		const status = this.node('p', 'settings-tool-status'); status.setAttribute('role', 'status'); status.hidden = true;
		wrapper.append(header, description, status); container.append(wrapper);
		const panel = { section, wrapper, header, status, fields: new Map(), busy: false }; this.panels.set(section, panel);
		return panel;
	}

	async loadExtension(section, extension) {
		let panel = this.panels.get(section);
		if (panel?.configuration || panel?.busy) return;
		panel ||= this.makePanel(section, section === 'filters' ? 'toolsRuleTitle' : 'toolsExtractionTitle',
			section === 'filters' ? 'toolsRuleNote' : 'toolsExtractionNote');
		if (!panel) return;
		panel.retry?.remove(); panel.busy = true; panel.wrapper.setAttribute('aria-busy', 'true'); this.message(panel, 'toolsLoading');
		const epoch = this.epoch;
		try {
			const result = await this.request('extensionConfig', { extension });
			if (epoch !== this.epoch) return;
			panel.configuration = result.configuration; this.buildExtension(panel);
			this.showFieldErrors(panel, result.configuration.errors || {});
			this.message(panel, Object.keys(result.configuration.errors || {}).length ? 'toolsFieldInvalid' : '', {}, true);
			this.loadPresets(panel);
		} catch (error) {
			if (epoch !== this.epoch) return;
			this.message(panel, 'toolsFailed', {}, true); this.report(error);
			panel.retry = this.button('toolsRetry', () => this.loadExtension(section, extension)); panel.wrapper.append(panel.retry);
		} finally {
			if (epoch === this.epoch) {
				panel.busy = false; panel.wrapper.setAttribute('aria-busy', 'false'); this.sync(panel); this.focusError(panel);
			}
		}
	}

	buildExtension(panel) {
		const { configuration } = panel;
		panel.content?.remove(); panel.fields.clear(); panel.conflict = null;
		const form = this.node('form', 'settings-tool-form'); panel.content = form; form.noValidate = true;
		form.addEventListener('submit', event => { event.preventDefault(); this.save(panel); });
		panel.list = this.node('ul', 'settings-tool-list'); form.append(panel.list);
		panel.presetArea = this.node('div', 'settings-tool-actions'); panel.presetArea.hidden = true; form.append(panel.presetArea);
		const advanced = this.node('details', 'advanced-settings'); advanced.append(this.node('summary', '', this.text('toolsAdvanced'))); panel.advanced = advanced;
		for (const [key, spec] of Object.entries(configuration.schema)) {
			const row = this.node('div', 'settings-tool-field');
			const title = { rules_json: 'toolsRulesJSON', recipes: 'toolsRecipes', hook_priority: 'toolsHookPriority' }[key];
			const label = this.node('label', '', title ? this.text(title) : spec.label || key);
			const id = 'settings-tool-' + panel.section + '-' + key; label.htmlFor = id;
			const long = ['object', 'array'].includes(spec.type) || key === 'rules_json';
			const input = this.node(spec.enum || spec.type === 'boolean' ? 'select' : long ? 'textarea' : 'input');
			input.id = id; input.name = key;
			if (spec.enum || spec.type === 'boolean') {
				const options = spec.enum || [true, false];
				if (spec.nullable || (!spec.required && !Object.hasOwn(spec, 'default'))) {
					const empty = this.node('option'); empty.value = ''; input.append(empty);
				}
				for (const value of options) {
					const option = this.node('option', '', typeof value === 'boolean' ? this.text(value ? 'toolsTrue' : 'toolsFalse') : String(value));
					option.value = spec.enum ? JSON.stringify(value) : String(value); input.append(option);
				}
			} else if (long) { input.rows = 12; input.spellcheck = false; input.classList.add('settings-tool-json'); } else {
				input.type = spec.secret ? 'password' : ['integer', 'number'].includes(spec.type) ? 'number' : 'text';
				if (input.type === 'number') {
					input.step = spec.type === 'integer' ? 1 : 'any';
					if (Number.isSafeInteger(spec.min)) input.min = spec.min;
					if (Number.isSafeInteger(spec.max)) input.max = spec.max;
				}
			}
			input.value = encodeField(configuration.values[key], spec); input.autocomplete = spec.secret ? 'new-password' : 'off';
			const error = this.node('small', 'settings-tool-error'); error.id = id + '-error'; error.hidden = true;
			input.setAttribute('aria-describedby', error.id); row.append(label, input, error);
			if (spec.secret || key === 'hook_priority' || spec.description) {
				const help = this.node('small', 'settings-note', spec.secret
					? this.text('toolsSecret')
					: key === 'hook_priority' ? this.text('toolsHookPriorityHelp') : spec.description);
				help.id = id + '-help'; input.setAttribute('aria-describedby', error.id + ' ' + help.id); row.append(help);
			}
			panel.fields.set(key, { input, initial: input.value, saved: configuration.values[key], error, spec });
			input.addEventListener('input', () => {
				error.hidden = true; input.removeAttribute('aria-invalid'); panel.revision++; this.invalidatePreview(panel);
				if (panel.pendingFocus?.key === key) panel.pendingFocus = null;
				this.message(panel, ''); this.renderSummary(panel); this.sync(panel);
			});
			advanced.append(row);
		}
		form.append(advanced);
		const transfer = this.node('details', 'advanced-settings');
		transfer.append(this.node('summary', '', this.text('toolsExport') + ' / ' + this.text('toolsImport')));
		const actions = this.node('div', 'settings-tool-actions');
		panel.export = this.button('toolsExport', () => this.export(panel));
		panel.import = this.button('toolsImport', () => panel.file.click());
		panel.file = this.node('input'); panel.file.type = 'file'; panel.file.accept = 'application/json,.json'; panel.file.hidden = true;
		panel.file.setAttribute('aria-label', this.text('toolsFileLabel'));
		panel.file.addEventListener('change', () => this.import(panel)); actions.append(panel.export, panel.import, panel.file);
		transfer.append(actions); form.append(transfer);
		const footer = this.node('div', 'settings-tool-actions settings-tool-save');
		panel.save = this.node('button', 'primary', this.text('toolsSave')); panel.save.type = 'submit';
		panel.discard = this.button('toolsDiscard', () => this.restore(panel));
		panel.draft = this.node('span', 'settings-note'); footer.append(panel.save, panel.discard, panel.draft); form.append(footer);
		panel.revision = 0; panel.wrapper.append(form); this.buildPreview(panel); this.renderSummary(panel); this.sync(panel);
	}

	draftValues(panel) {
		const { values, errors } = configurationChanges(panel.fields);
		return { values: { ...panel.configuration.values, ...values }, changes: values, errors };
	}

	sync(panel) {
		if (!panel.configuration) return;
		const draft = this.draftValues(panel);
		const dirty = Boolean(Object.keys(draft.changes).length || Object.keys(draft.errors).length);
		const invalid = Object.keys(draft.errors).length > 0;
		for (const { input } of panel.fields.values()) input.disabled = panel.busy;
		panel.save.disabled = panel.busy || Boolean(this.store?.csrfInvalid) || Boolean(panel.conflict) || (!Object.keys(draft.changes).length && !invalid);
		panel.save.textContent = this.text(panel.busy ? 'toolsSaving' : 'toolsSave'); panel.discard.hidden = !dirty; panel.discard.disabled = panel.busy;
		panel.draft.textContent = dirty ? this.text('toolsDraft') : ''; panel.draft.dataset.dirty = String(dirty);
		panel.import.disabled = panel.busy; panel.export.disabled = panel.busy || invalid;
		panel.previewRun.disabled = panel.busy || Boolean(this.store?.csrfInvalid) || panel.previewBusy;
		panel.presetAdd && (panel.presetAdd.disabled = panel.busy || invalid);
		panel.list.querySelectorAll('input,button').forEach(input => { input.disabled = panel.busy || input.dataset.boundary === 'true'; });
	}

	showFieldErrors(panel, errors) {
		for (const [key, field] of panel.fields) {
			const error = errors[key]; field.error.hidden = !error; field.error.textContent = error ? this.text(error) : '';
			field.input.setAttribute('aria-invalid', String(Boolean(error)));
		}
		const key = Object.keys(errors).find(key => panel.fields.has(key));
		panel.pendingFocus = key ? { key, scheduler: false } : null; this.focusError(panel);
	}

	focusError(panel) {
		if (!panel?.pendingFocus || panel.busy || this.activeSection !== panel.section || !panel.wrapper.getClientRects().length) return;
		const { key, scheduler } = panel.pendingFocus;
		const field = (scheduler ? panel.schedulerFields : panel.fields).get(key);
		if (!field || field.input.disabled) return;
		if (scheduler) { if (field.advanced) panel.schedulerAdvanced.open = true; } else panel.advanced.open = true;
		field.input.focus(); panel.pendingFocus = null;
	}

	async save(panel) {
		if (panel.busy || panel.conflict || this.store?.csrfInvalid) return;
		const draft = this.draftValues(panel); this.showFieldErrors(panel, draft.errors);
		if (Object.keys(draft.errors).length || !Object.keys(draft.changes).length) return;
		const epoch = this.epoch; panel.busy = true; this.message(panel, 'toolsSaving'); this.sync(panel);
		try {
			const result = await this.request('extensionConfig', {}, {
				extension: panel.configuration.extension, expectedVersion: panel.configuration.version, configuration: draft.changes,
			});
			if (epoch !== this.epoch) return;
			panel.configuration = result.configuration;
			for (const [key, field] of panel.fields) {
				field.input.value = encodeField(result.configuration.values[key], field.spec);
				field.initial = field.input.value; field.saved = result.configuration.values[key];
			}
			this.message(panel, 'toolsSaved'); this.renderSummary(panel);
		} catch (error) {
			if (epoch !== this.epoch) return;
			const conflict = error.details?.conflicts?.[panel.configuration.extension];
			if (conflict) this.showConflict(panel, conflict);
			else { this.message(panel, 'toolsSaveFailed', {}, true); this.showFieldErrors(panel, isObject(error.fields) ? error.fields : {}); }
			this.report(error);
		} finally { if (epoch === this.epoch) { panel.busy = false; this.sync(panel); this.focusError(panel); } }
	}

	showConflict(panel, current) {
		panel.conflict = current; panel.conflictNode?.remove(); this.message(panel, 'toolsConflict', {}, true);
		const section = this.node('section', 'settings-tool-conflict');
		const latest = pretty(configurationDocument(current, current.values).configuration);
		section.append(this.node('h4', '', this.text('toolsCurrent')), this.node('pre', 'settings-tool-output', latest),
			this.button('toolsReviewDraft', () => this.reviewDraft(panel, current)),
			this.button('toolsUseCurrent', () => { panel.configuration = current; this.restore(panel); }));
		panel.conflictNode = section; panel.content.append(section);
	}

	reviewDraft(panel, current) {
		if (panel.busy) return;
		const draft = configurationChanges(panel.fields);
		for (const [key, field] of panel.fields) {
			const raw = field.input.value;
			field.saved = current.values[key]; field.initial = encodeField(current.values[key], field.spec);
			field.input.value = Object.hasOwn(draft.values, key) || Object.hasOwn(draft.errors, key) ? raw : field.initial;
		}
		panel.configuration = current; panel.conflict = null; panel.conflictNode?.remove(); panel.revision++; this.invalidatePreview(panel);
		this.message(panel, 'toolsDraftRebased'); this.renderSummary(panel); this.sync(panel);
	}

	restore(panel) {
		if (panel.busy) return;
		for (const [key, field] of panel.fields) {
			field.input.value = encodeField(panel.configuration.values[key], field.spec);
			field.initial = field.input.value; field.saved = panel.configuration.values[key];
			field.error.hidden = true; field.input.removeAttribute('aria-invalid');
		}
		panel.conflict = null; panel.conflictNode?.remove(); panel.revision++; this.invalidatePreview(panel);
		this.message(panel, ''); this.renderSummary(panel); this.sync(panel);
	}

	renderSummary(panel) {
		const key = panel.section === 'filters' ? 'rules_json' : 'recipes'; const field = panel.fields.get(key); panel.list.replaceChildren();
		if (!field) return;
		let value;
		try { value = JSON.parse(field.input.value); } catch { return; }
		if (!safeJSONNumbers(value)) return;
		if (panel.section === 'filters') {
			if (!isObject(value) || !Array.isArray(value.rules) || value.rules.some(rule => !isObject(rule))) return;
			if (!value.rules.length) panel.list.append(this.node('li', 'settings-note', this.text('toolsEmptyRules')));
			value.rules.forEach((rule, index) => {
				const row = this.node('li', 'settings-tool-row'); const name = String(rule.id || index + 1);
				const label = this.node('label', 'settings-tool-rule-name'); const input = this.node('input');
				input.type = 'checkbox'; input.checked = rule.enabled !== false;
				input.setAttribute('aria-label', this.text('toolsEnableRule', { name })); input.addEventListener('change', () => {
					rule.enabled = input.checked; this.writeJSON(panel, key, value); panel.list.querySelectorAll('input')[index]?.focus();
				}); label.append(input, this.node('span', '', name));
				const controls = this.node('div', 'settings-tool-actions');
				for (const direction of [-1, 1]) {
					const control = this.button(direction < 0 ? 'toolsMoveUp' : 'toolsMoveDown', () => {
						[value.rules[index], value.rules[index + direction]] = [value.rules[index + direction], value.rules[index]];
						this.writeJSON(panel, key, value); panel.list.querySelectorAll('input')[index + direction]?.focus();
					});
					control.textContent = direction < 0 ? '↑' : '↓';
					control.setAttribute('aria-label', this.text(direction < 0 ? 'toolsMoveUp' : 'toolsMoveDown', { name }));
					control.dataset.boundary = String(index + direction < 0 || index + direction >= value.rules.length); controls.append(control);
				}
				row.append(label, controls); panel.list.append(row);
			});
		} else {
			if (!isObject(value)) return;
			if (!Object.keys(value).length) panel.list.append(this.node('li', 'settings-note', this.text('toolsEmptyRecipes')));
			for (const [id, recipe] of Object.entries(value)) {
				const row = this.node('li', 'settings-tool-row'); const feed = this.store?.feeds?.find(feed => String(feed.id) === id);
				const fieldNames = { title: 'toolsFieldTitle', content: 'toolsFieldContent', author: 'toolsFieldAuthor', link: 'toolsFieldLink' };
				const summary = isObject(recipe?.fields) ? Object.keys(recipe.fields).map(key => this.text(fieldNames[key] || key)).join(' · ') : '';
				row.append(this.node('strong', '', id === '*' ? this.text('toolsDefaultRecipe') : feed?.name || id), this.node('span', 'settings-note', summary));
				panel.list.append(row);
			}
		}
	}

	writeJSON(panel, key, value) {
		const input = panel.fields.get(key).input; input.value = pretty(value); input.dispatchEvent(new Event('input', { bubbles: true }));
	}

	async loadPresets(panel) {
		const epoch = this.epoch;
		panel.presetArea.replaceChildren();
		try {
			const schema = await this.request('schema'); if (epoch !== this.epoch) return;
			const presets = panel.section === 'filters' ? schema.rulePresets : schema.extractionPresets;
			if (!presets || !Object.keys(presets).length) return;
			const label = this.node('label', '', this.text('toolsPreset')); const input = this.node('select');
			input.id = 'settings-tool-' + panel.section + '-preset';
			label.htmlFor = input.id;
			for (const id of Object.keys(presets)) {
				const option = this.node('option', '', this.text('toolsPreset_' + id)); option.value = id; input.append(option);
			}
			const add = this.button('toolsAddPreset', () => {
				if (panel.busy) return;
				try {
					if (panel.section === 'filters') {
						const field = panel.fields.get('rules_json'); const value = JSON.parse(field.input.value);
						if (!safeJSONNumbers(value) || !Array.isArray(value.rules)) throw new Error('toolsUnsafeJSON');
						const preset = typeof presets[input.value] === 'string' ? JSON.parse(presets[input.value]) : presets[input.value];
						const rules = structuredClone(preset.rules); const ids = new Set(value.rules.map(rule => rule.id));
						for (const rule of rules) {
							const base = rule.id; let suffix = 2; while (ids.has(rule.id)) rule.id = base + '-' + suffix++; ids.add(rule.id);
						}
						value.rules.push(...rules); this.writeJSON(panel, 'rules_json', value);
					} else {
						const field = panel.fields.get('recipes'); const value = JSON.parse(field.input.value);
						if (!isObject(value) || !safeJSONNumbers(value)) throw new Error('toolsObjectRequired');
						if (Object.hasOwn(value, '*')) { panel.advanced.open = true; field.input.focus(); return; }
						value['*'] = structuredClone(presets[input.value]); this.writeJSON(panel, 'recipes', value);
					}
					panel.advanced.open = true; this.message(panel, 'toolsPresetAdded');
				} catch (error) { this.message(panel, error.message in SETTINGS_TOOLS_MESSAGES.en ? error.message : 'toolsJSONInvalid', {}, true); }
			});
			panel.presetAdd = add; panel.presetArea.append(label, input, add); panel.presetArea.hidden = false; this.sync(panel);
		} catch {
			if (epoch !== this.epoch) return;
			panel.presetArea.hidden = false;
			panel.presetArea.append(this.node('p', 'settings-note', this.text('toolsPresetsFailed')), this.button('toolsRetry', () => this.loadPresets(panel)));
		}
	}

	export(panel) {
		const draft = this.draftValues(panel); if (Object.keys(draft.errors).length) { this.showFieldErrors(panel, draft.errors); return; }
		const url = URL.createObjectURL(new Blob([pretty(configurationDocument(panel.configuration, draft.values))], { type: 'application/json' }));
		const link = this.node('a'); link.href = url; link.download = 'freshrss-' + panel.configuration.extension + '.json'; link.click();
		setTimeout(() => URL.revokeObjectURL(url), 0);
	}

	async import(panel) {
		const file = panel.file.files[0]; panel.file.value = ''; if (!file || panel.busy) return;
		const epoch = this.epoch; const revision = panel.revision; const configuration = panel.configuration;
		try {
			if (file.size > (this.bootstrap.apiLimits?.maxRequestBytes ?? 1048576)) throw new Error('toolsImportInvalid');
			const value = JSON.parse(await file.text());
			if (epoch !== this.epoch || revision !== panel.revision || configuration !== panel.configuration || panel.busy) return;
			const values = importedConfiguration(value, configuration);
			for (const [key, value] of Object.entries(values)) panel.fields.get(key).input.value = encodeField(value, panel.fields.get(key).spec);
			panel.revision++; this.invalidatePreview(panel); panel.advanced.open = true;
			this.renderSummary(panel); this.sync(panel); this.message(panel, 'toolsImported');
		} catch { if (epoch === this.epoch) this.message(panel, 'toolsImportInvalid', {}, true); }
	}

	buildPreview(panel) {
		const details = this.node('details', 'settings-tool-preview'); details.append(this.node('summary', '', this.text('toolsPreview')),
			this.node('p', 'settings-note', this.text('toolsPreviewNote')));
		const form = this.node('form', 'settings-tool-form'); panel.previewInputs = {};
		const add = (key, title, tag, value = '') => {
			const label = this.node('label', 'settings-tool-field', this.text(title)); const input = this.node(tag);
			input.name = key; input.value = value; if (tag === 'textarea') { input.rows = 6; input.spellcheck = false; }
			input.addEventListener('input', () => { panel.revision++; this.invalidatePreview(panel); });
			label.append(input); form.append(label); panel.previewInputs[key] = input; return input;
		};
		if (panel.section === 'filters') {
			add('sample', 'toolsSample', 'textarea', '{}'); const updated = add('updated', 'toolsUpdated', 'input'); updated.type = 'checkbox';
		} else {
			add('feed', 'toolsPreviewFeed', 'input', '*'); add('baseUrl', 'toolsBaseURL', 'input'); add('source', 'toolsSource', 'textarea');
		}
		panel.previewRun = this.node('button', '', this.text('toolsRunPreview')); panel.previewRun.type = 'submit';
		panel.previewStatus = this.node('p', 'settings-tool-status'); panel.previewStatus.setAttribute('role', 'status'); panel.previewStatus.hidden = true;
		panel.previewOutput = this.node('pre', 'settings-tool-output'); panel.previewOutput.hidden = true;
		panel.previewOutput.setAttribute('aria-label', this.text('toolsPreviewResult'));
		form.addEventListener('submit', event => { event.preventDefault(); this.preview(panel); });
		form.append(panel.previewRun, panel.previewStatus, panel.previewOutput); details.append(form); panel.wrapper.append(details); panel.previewDetails = details;
	}

	invalidatePreview(panel) {
		if (!panel.previewOutput?.hidden) {
			panel.previewOutput.hidden = true; panel.previewStatus.hidden = false; panel.previewStatus.textContent = this.text('toolsPreviewStale');
		}
	}

	async preview(panel) {
		if (panel.previewBusy || panel.busy || this.store?.csrfInvalid) return;
		const epoch = this.epoch; const revision = panel.revision; panel.previewBusy = true; this.sync(panel);
		panel.previewStatus.hidden = false; panel.previewStatus.textContent = this.text('toolsLoading'); panel.previewOutput.hidden = true;
		try {
			let body; let action;
			if (panel.section === 'filters') {
				const entry = JSON.parse(panel.previewInputs.sample.value);
				const valid = value => typeof value === 'string' || (Array.isArray(value) && value.every(item => typeof item === 'string'));
				if (!isObject(entry) || !Object.values(entry).every(valid)) {
					throw new Error('toolsPreviewSampleInvalid');
				}
				action = 'rulePreview';
				body = { rules: panel.fields.get('rules_json').input.value, entry, explain: true, updated: panel.previewInputs.updated.checked };
			} else {
				const recipes = settingsValue(panel.fields.get('recipes').input.value, panel.fields.get('recipes').spec);
				const recipe = recipes[panel.previewInputs.feed.value.trim()] ?? recipes['*']; if (!recipe) throw new Error('toolsNoRecipe');
				action = 'extractionPreview'; body = { recipe, source: panel.previewInputs.source.value, baseUrl: panel.previewInputs.baseUrl.value.trim() };
			}
			const result = await this.request(action, {}, body);
			if (epoch !== this.epoch || revision !== panel.revision) return;
			panel.previewOutput.textContent = pretty(result.preview); panel.previewOutput.hidden = false; panel.previewStatus.hidden = true;
		} catch (error) {
			if (epoch !== this.epoch || revision !== panel.revision) return;
			panel.previewStatus.textContent = this.text(error.message in SETTINGS_TOOLS_MESSAGES.en ? error.message : 'toolsPreviewFailed'); this.report(error);
		} finally {
			if (epoch === this.epoch) {
				panel.previewBusy = false; this.sync(panel);
				if (revision !== panel.revision) panel.previewStatus.textContent = this.text('toolsPreviewStale');
			}
		}
	}

	async loadScheduler(before, force = false) {
		let panel = this.panels.get('automation'); if (panel?.busy) return;
		panel ||= this.makePanel('automation', 'toolsScheduler', 'toolsSchedulerNote'); if (!panel) return;
		if (!this.bootstrap.canManageLimits) { this.message(panel, 'toolsAdminOnly'); return; }
		if (panel.scheduler && before === undefined && !force) return;
		panel.retry?.remove(); panel.busy = true; panel.wrapper.setAttribute('aria-busy', 'true'); this.message(panel, 'toolsLoading');
		panel.more && (panel.more.disabled = true); panel.refresh && (panel.refresh.disabled = true);
		if (panel.schedulerFields) this.syncScheduler(panel);
		const epoch = this.epoch;
		try {
			const result = await this.request('scheduler', before ? { history_before: before } : {}); if (epoch !== this.epoch) return;
			panel.scheduler = result; this.renderScheduler(panel); this.message(panel, '');
		} catch (error) {
			if (epoch !== this.epoch) return;
			this.message(panel, 'toolsFailed', {}, true); this.report(error);
			panel.retry = this.button('toolsRetry', () => this.loadScheduler(before)); panel.wrapper.append(panel.retry);
		} finally {
			if (epoch === this.epoch) {
				panel.busy = false; panel.wrapper.setAttribute('aria-busy', 'false');
				panel.more && (panel.more.disabled = false); panel.refresh && (panel.refresh.disabled = false);
				if (panel.schedulerFields) this.syncScheduler(panel);
			}
		}
	}

	schedulerChanges(panel) {
		const result = configurationChanges(panel.schedulerFields || new Map());
		const candidate = { ...panel.scheduler.configuration, ...result.values };
		if (Object.hasOwn(candidate, 'lease_seconds') && candidate.lease_seconds <= candidate.job_timeout + candidate.kill_grace) {
			result.errors.lease_seconds = 'toolsQueueLeaseError';
		}
		if (Object.hasOwn(candidate, 'retry_max') && candidate.retry_max < candidate.retry_base) result.errors.retry_max = 'toolsQueueRetryError';
		if (candidate.retry_jitter_percent > 100) result.errors.retry_jitter_percent = 'toolsQueueJitterError';
		return result;
	}

	syncScheduler(panel) {
		const draft = this.schedulerChanges(panel);
		const dirty = Boolean(Object.keys(draft.values).length || Object.keys(draft.errors).length);
		for (const { input } of panel.schedulerFields.values()) input.disabled = panel.busy;
		panel.schedulerSave.disabled = panel.busy || Boolean(this.store?.csrfInvalid) || (!Object.keys(draft.values).length && !Object.keys(draft.errors).length);
		panel.schedulerSave.textContent = this.text(panel.busy ? 'toolsSaving' : 'toolsSave');
		panel.schedulerDiscard.hidden = !dirty; panel.schedulerDiscard.disabled = panel.busy;
		panel.schedulerDraft.textContent = dirty ? this.text('toolsDraft') : '';
		panel.queueToggle.disabled = panel.busy || Boolean(this.store?.csrfInvalid);
	}

	buildSchedulerForm(panel) {
		const old = panel.schedulerFields; panel.schedulerFields = new Map();
		const form = this.node('form', 'settings-tool-form'); form.noValidate = true;
		const usual = this.node('fieldset', 'settings-tool-scheduler-fields'); usual.append(this.node('legend', '', this.text('toolsQueueFields')));
		const advanced = this.node('details', 'advanced-settings'); advanced.append(this.node('summary', '', this.text('toolsQueueAdvanced')));
		panel.schedulerAdvanced = advanced;
		const common = ['enabled', 'workers', 'per_user', 'per_host', 'worker_memory_mib', 'job_timeout', 'maintenance_interval'];
		for (const [key, value] of Object.entries(panel.scheduler.configuration)) {
			const source = panel.scheduler.schema[key]; if (!source) continue;
			const spec = {
				type: typeof value === 'boolean' ? 'boolean' : 'integer', default: source.default ?? value, min: source.min, max: source.max,
			};
			const label = this.node('label', '', this.text(schedulerLabels[key] || key));
			const input = this.node(spec.type === 'boolean' ? 'select' : 'input');
			input.id = 'settings-tool-scheduler-' + key; input.name = key; label.htmlFor = input.id;
			if (spec.type === 'boolean') {
				for (const value of [true, false]) {
					const option = this.node('option', '', this.text(value ? 'toolsTrue' : 'toolsFalse')); option.value = String(value); input.append(option);
				}
			} else {
				input.type = 'number'; input.step = 1;
				if (Number.isSafeInteger(source.min)) input.min = source.min;
				if (Number.isSafeInteger(source.max)) input.max = source.max;
			}
			const initial = encodeField(value, spec); const previous = old?.get(key);
			input.value = previous && previous.input.value !== previous.initial ? previous.input.value : initial;
			const error = this.node('small', 'settings-tool-error'); error.id = input.id + '-error'; error.hidden = true;
			input.setAttribute('aria-describedby', error.id);
			const row = this.node('div', 'settings-tool-field'); row.append(label, input, error);
			const unit = this.unit(1, source.unit).replace('1', '').trim();
			if (spec.type !== 'boolean' && unit) {
				const hint = this.node('small', 'settings-note', unit);
				hint.id = input.id + '-unit'; row.append(hint); input.setAttribute('aria-describedby', error.id + ' ' + hint.id);
			}
			const field = { spec, saved: value, initial, input, error, advanced: !common.includes(key) };
			panel.schedulerFields.set(key, field);
			input.addEventListener('input', () => {
				error.hidden = true; input.removeAttribute('aria-invalid'); this.message(panel, ''); this.syncScheduler(panel);
				if (panel.pendingFocus?.key === key) panel.pendingFocus = null;
			});
			(common.includes(key) ? usual : advanced).append(row);
		}
		const actions = this.node('div', 'settings-tool-actions settings-tool-save');
		panel.schedulerSave = this.node('button', 'primary', this.text('toolsSave')); panel.schedulerSave.type = 'submit';
		panel.schedulerDiscard = this.button('toolsDiscard', () => {
			if (panel.busy) return;
			for (const field of panel.schedulerFields.values()) {
				field.input.value = field.initial; field.error.hidden = true; field.input.removeAttribute('aria-invalid');
			}
			this.message(panel, ''); this.syncScheduler(panel);
		});
		panel.schedulerDraft = this.node('span', 'settings-note'); actions.append(panel.schedulerSave, panel.schedulerDiscard, panel.schedulerDraft);
		form.append(usual, advanced, actions);
		form.addEventListener('submit', event => { event.preventDefault(); this.saveScheduler(panel); });
		return form;
	}

	showSchedulerErrors(panel, errors) {
		for (const [key, field] of panel.schedulerFields) {
			field.error.hidden = !errors[key]; field.error.textContent = errors[key] ? this.text(errors[key]) : '';
			field.input.setAttribute('aria-invalid', String(Boolean(errors[key])));
		}
		const key = Object.keys(errors).find(key => panel.schedulerFields.has(key));
		panel.pendingFocus = key ? { key, scheduler: true } : null; this.focusError(panel);
	}

	async saveScheduler(panel) {
		if (panel.busy || this.store?.csrfInvalid) return;
		const draft = this.schedulerChanges(panel); this.showSchedulerErrors(panel, draft.errors);
		if (Object.keys(draft.errors).length || !Object.keys(draft.values).length) return;
		return this.writeScheduler(panel, { configuration: draft.values }, true);
	}

	async writeScheduler(panel, body, discardSubmitted = false) {
		if (panel.busy || this.store?.csrfInvalid) return;
		const epoch = this.epoch; panel.busy = true; this.message(panel, 'toolsSaving'); this.syncScheduler(panel);
		panel.more && (panel.more.disabled = true); panel.refresh.disabled = true;
		try {
			const result = await this.request('scheduler', {}, body); if (epoch !== this.epoch) return;
			if (discardSubmitted) panel.schedulerFields = null;
			panel.scheduler = result; this.renderScheduler(panel); this.message(panel, discardSubmitted ? 'toolsSaved' : 'toolsQueueChanged');
		} catch (error) {
			if (epoch !== this.epoch) return;
			this.message(panel, 'toolsSaveFailed', {}, true); this.report(error);
			const errors = {};
			if (error.details?.field) errors[error.details.field] = 'toolsFieldInvalid';
			for (const field of Array.isArray(error.fields) ? error.fields : []) errors[field] = 'toolsFieldInvalid';
			if (error.details?.reason === 'lease_must_exceed_timeout_and_grace') errors.lease_seconds = 'toolsQueueLeaseError';
			if (error.details?.reason === 'invalid_retry_bounds') {
				errors.retry_max = 'toolsQueueRetryError'; errors.retry_jitter_percent = 'toolsQueueJitterError';
			}
			if (error.details?.reason === 'timeout_and_grace_exceed_alarm_range') errors.job_timeout = 'toolsQueueTimeoutError';
			this.showSchedulerErrors(panel, errors);
		} finally {
			if (epoch === this.epoch) {
				panel.busy = false; this.syncScheduler(panel); panel.more && (panel.more.disabled = false); panel.refresh.disabled = false;
				this.focusError(panel);
			}
		}
	}

	renderScheduler(panel) {
		panel.content?.remove(); const content = this.node('div'); panel.content = content;
		const { configuration, status } = panel.scheduler;
		const stateLabel = !configuration.enabled ? 'toolsSchedulerDisabled' : status.paused ? 'toolsSchedulerPaused' : 'toolsSchedulerRunning';
		content.append(this.node('p', 'settings-note', this.text(stateLabel)));
		const stats = this.node('dl', 'settings-tool-stats');
		for (const [key, label] of [['pending', 'toolsPending'], ['running', 'toolsRunning'], ['retrying', 'toolsRetrying'], ['due', 'toolsDue']]) {
			const row = this.node('div'); row.append(this.node('dt', '', this.text(label)), this.node('dd', '', String(status[key]))); stats.append(row);
		}
		content.append(stats);
		const queueActions = this.node('div', 'settings-tool-actions');
		panel.queueToggle = this.button(status.paused ? 'toolsResumeQueue' : 'toolsPauseQueue',
			() => this.writeScheduler(panel, { paused: !panel.scheduler.status.paused }));
		panel.queueToggle.hidden = !configuration.enabled;
		queueActions.append(panel.queueToggle, this.node('p', 'settings-note', this.text('toolsPauseNote')));
		content.append(queueActions, this.buildSchedulerForm(panel), this.node('h4', '', this.text('toolsHistory')));
		if (!status.history.length) content.append(this.node('p', 'settings-note', this.text('toolsHistoryEmpty')));
		else {
			const scroll = this.node('div', 'settings-tool-table-scroll'); const table = this.node('table', 'settings-tool-history');
			const head = this.node('thead'); const headings = this.node('tr');
			const historyHeadings = ['toolsHistoryTime', 'toolsHistoryUser', 'toolsHistoryFeed',
				'toolsHistoryKind', 'toolsHistoryOutcome', 'toolsHistoryDuration', 'toolsHistoryMemory'];
			for (const label of historyHeadings) {
				const th = this.node('th', '', this.text(label)); th.scope = 'col'; headings.append(th);
			}
			head.append(headings); const body = this.node('tbody');
			for (const event of status.history) {
				const row = this.node('tr');
				const kind = Object.hasOwn(SETTINGS_TOOLS_MESSAGES.en, 'toolsKind_' + event.kind) ? this.text('toolsKind_' + event.kind) : event.kind;
				const outcomeKey = 'toolsOutcome_' + event.outcome;
				const outcome = Object.hasOwn(SETTINGS_TOOLS_MESSAGES.en, outcomeKey) ? this.text(outcomeKey) : event.outcome;
				const values = [new Date(event.finished * 1000).toLocaleString(this.bootstrap.language), event.username, event.feed_id, kind, outcome,
					this.unit(event.duration_ms, 'milliseconds'), this.unit(Math.round(event.peak_bytes / 1048576 * 10) / 10, 'MiB')];
				for (const value of values) row.append(this.node('td', '', String(value)));
				body.append(row);
			}
			table.append(head, body); scroll.append(table); content.append(scroll);
		}
		const actions = this.node('div', 'settings-tool-actions');
		panel.refresh = this.button('toolsRefreshStatus', () => this.loadScheduler(undefined, true)); actions.append(panel.refresh);
		panel.more = null;
		if (status.historyNext) { panel.more = this.button('toolsHistoryMore', () => this.loadScheduler(status.historyNext)); actions.append(panel.more); }
		content.append(actions); panel.wrapper.append(content); this.syncScheduler(panel);
	}

	unit(value, unit) {
		if (typeof value === 'boolean') return this.text(value ? 'toolsTrue' : 'toolsFalse');
		const key = { milliseconds: 'toolsMilliseconds', seconds: 'toolsSeconds', days: 'toolsDays', MiB: 'toolsMiB', percent: 'toolsPercent' }[unit];
		return key ? this.text(key, { value }) : String(value);
	}

	report(error) { if (error?.status === 403) this.store?.reportError?.(error); }

	hasDraft() {
		return [...this.panels.values()].some(panel => {
			const fields = new Map([...panel.fields, ...(panel.schedulerFields || [])]);
			const result = configurationChanges(fields);
			return Boolean(Object.keys(result.values).length || Object.keys(result.errors).length);
		});
	}

	dispose(clear = true) {
		this.epoch++; for (const controller of this.controllers) controller.abort(); this.controllers.clear();
		if (clear) { for (const panel of this.panels.values()) panel.wrapper.remove(); this.panels.clear(); } else {
			for (const panel of this.panels.values()) {
				panel.busy = false; panel.previewBusy = false;
				if (panel.configuration) this.sync(panel);
				if (panel.schedulerFields) this.syncScheduler(panel);
				panel.wrapper.setAttribute('aria-busy', 'false');
			}
		}
	}
}
