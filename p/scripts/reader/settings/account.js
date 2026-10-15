const SECTIONS = {
	reading: ['reading', 'navigation', 'media', 'notifications'],
	filters: ['filters'],
	storage: ['cleanup', 'storage'],
	automation: ['refresh'],
	privacy: ['privacy'],
};
const SHARED_READING_FIELDS = ['mark_when.article', 'mark_when.site', 'mark_updated_article_unread'];
const SIMPLE_DURATION = /^P(?:(\d+)([YMWD])|T(\d+)(H))$/;
const ISO_DURATION = /^P(?=\d|T\d)(?:\d+Y)?(?:\d+M)?(?:\d+W)?(?:\d+D)?(?:T(?=\d)(?:\d+H)?(?:\d+M)?(?:\d+S)?)?$/;

export const ACCOUNT_SETTINGS_MESSAGES = {
	zh: {
		"loading": '正在读取账户设置…',
		"save": '保存更改',
		"reset": '还原更改',
		"retry": '重新读取',
		"saved": '已保存',
		"saving": '正在保存…',
		"loadError": '无法读取账户设置。',
		"saveError": '保存失败，修改仍保留。',
		"invalid": '请检查标出的设置。',
		"invalidFilter": '过滤条件的语法有误。',
		"csrfError": '登录状态已失效，请刷新页面后重试。',
		"enabled": '启用',
		"unit": '单位',
		"unit_articles": '篇',
		"unit_seconds": '秒',
		"unit_hours": '小时',
		"unit_Y": '年',
		"unit_M": '个月',
		"unit_W": '周',
		"unit_D": '天',
		"unit_H": '小时',
		"unit_custom": '自定义 ISO 8601',
		"durationHelp": '请输入 ISO 8601 周期，例如 P1DT6H（1 天 6 小时）、PT30M（30 分钟）或 PT45S（45 秒）。',
		"group_reading": '阅读行为',
		"group_classic_reading": '经典界面的阅读设置',
		"group_navigation": '经典界面的导航',
		"group_media": '媒体加载',
		"group_notifications": '通知',
		"group_filters": '自动标记与过滤',
		"group_cleanup": '自动回收',
		"group_storage": 'RSS 输出',
		"group_refresh": '更新间隔',
		"group_privacy": '隐私',
		"cleanupNote": '规则用于订阅更新时的自动回收，单个订阅可单独覆盖。保存规则不会立即清理已有文章。',
		"filterSyntax": '每行一条条件。支持 intitle:关键词、author:作者、intext:正文，以及 OR、括号和 ! 排除；留空可移除这一类过滤。',
		"field_posts_per_page": '每页文章数',
		"help_posts_per_page": '经典界面的分页大小；独立阅读界面的分页在上方设置。',
		"field_default_view": '默认文章范围',
		"help_default_view": '经典界面打开时使用的文章范围。',
		"field_auto_load_more": '滚动时加载更多',
		"help_auto_load_more": '经典界面滚动到底部时加载下一页。',
		"field_onread_jump_next": '标为已读后跳到下一篇',
		"help_onread_jump_next": '经典界面手动标为已读后的行为。',
		"field_auto_remove_article": '隐藏刚读完的文章',
		"help_auto_remove_article": '经典界面的未读列表即时移除已读文章。',
		"field_reading_confirm": '批量已读前确认',
		"help_reading_confirm": '经典界面执行“全部标为已读”前显示确认。',
		"field_mark_updated_article_unread": '更新的文章重新标为未读',
		"help_mark_updated_article_unread": '订阅内容更新后，让已读文章重新出现在未读列表。',
		'field_mark_when.article': '打开文章时标为已读',
		'help_mark_when.article': '文章成功显示后自动标记；可随时手动改回未读。',
		'field_mark_when.site': '打开原文时标为已读',
		'help_mark_when.site': '点击原文链接时自动标记。',
		'field_mark_when.scroll': '滚动经过文章时标为已读',
		'help_mark_when.scroll': '经典阅读界面中，文章滚出视野后自动标记。',
		'field_mark_when.focus': '文章获得焦点时标为已读',
		'help_mark_when.focus': '经典阅读界面中，选中文章后自动标记。',
		'field_mark_when.gone': '源中消失的文章标为已读',
		'help_mark_when.gone': '更新订阅时，已不在 Feed 中的文章自动标记。',
		'field_mark_when.reception': '新文章直接标为已读',
		'help_mark_when.reception': '所有新收到的文章默认已读；仍会保存文章内容。',
		'field_mark_when.max_n_unread': '每个订阅最多保留未读数',
		'help_mark_when.max_n_unread': '超出数量的较旧文章自动标为已读；禁用时不限制。',
		'field_mark_when.same_title_in_feed': '同标题文章自动标为已读',
		'help_mark_when.same_title_in_feed': '在同一个订阅最近指定数量的文章中检查重复标题。',
		'field_filters.read': '自动标为已读的条件',
		'help_filters.read': '匹配任意一条条件的新文章自动标为已读。',
		'field_filters.star': '自动收藏的条件',
		'help_filters.star': '匹配任意一条条件的新文章自动加入收藏。',
		"field_hide_read_feeds": '隐藏没有未读文章的订阅',
		"help_hide_read_feeds": '经典界面的订阅列表只显示仍有未读文章的 Feed。',
		"field_display_categories": '分类展开方式',
		"help_display_categories": '设置经典界面侧边栏的分类展开状态。',
		"field_show_unread_count": '显示未读计数',
		"help_show_unread_count": '设置经典界面侧边栏的计数显示范围。',
		"field_show_title_unread": '标签页标题显示未读数',
		"help_show_title_unread": '经典界面在浏览器标签页标题中显示未读文章数。',
		"field_show_favicons": '显示网站图标',
		"help_show_favicons": '控制经典界面订阅列表的网站图标。',
		"field_icons_as_emojis": '使用表情符号图标',
		"help_icons_as_emojis": '经典界面用表情符号代替部分界面图标。',
		"field_lazyload": '延迟加载文章图片',
		"help_lazyload": '经典界面按需加载图片，减少初始加载流量。',
		"field_ttl_default": '订阅默认更新间隔',
		"help_ttl_default": '未单独设置更新间隔的 Feed 使用此值，实际执行由服务器更新任务决定。',
		"field_dynamic_opml_ttl_default": '动态 OPML 默认更新间隔',
		"help_dynamic_opml_ttl_default": '定期重新读取动态订阅列表；实际执行由服务器更新任务决定。',
		'field_archiving.keep_period': '文章保留期限',
		'help_archiving.keep_period': '超过期限的文章可在更新时回收。取消启用后不按时间回收。',
		'field_archiving.keep_max': '每个订阅最多保留',
		'help_archiving.keep_max': '超出数量的较旧文章可在更新时回收。',
		'field_archiving.keep_min': '每个订阅至少保留',
		'help_archiving.keep_min': '为每个 Feed 保留最近的指定数量，避免按期限回收后没有内容。',
		'field_archiving.keep_favourites': '保留收藏文章',
		'help_archiving.keep_favourites': '收藏文章不参与自动回收。',
		'field_archiving.keep_labels': '保留有标签的文章',
		'help_archiving.keep_labels': '已添加个人标签的文章不参与自动回收。',
		'field_archiving.keep_unreads': '保留未读文章',
		'help_archiving.keep_unreads': '未读文章不参与自动回收。',
		"field_max_posts_per_rss": 'RSS 输出最多文章数',
		"help_max_posts_per_rss": '账户 RSS 输出的数量上限，独立于数据库保留数量。',
		"field_since_hours_posts_per_rss": 'RSS 输出时间范围',
		"help_since_hours_posts_per_rss": 'RSS 只输出最近指定小时的文章；0 表示不按时间限制。',
		"field_html5_enable_notif": '浏览器通知',
		"help_html5_enable_notif": '经典界面接收更新通知；还需要浏览器授予通知权限。',
		"field_html5_notif_timeout": '浏览器通知停留时间',
		"help_html5_notif_timeout": '0 表示通知持续显示，直到手动关闭。',
		"field_good_notification_timeout": '成功消息停留时间',
		"help_good_notification_timeout": '经典界面的成功消息显示时间；0 表示关闭成功消息。',
		"field_bad_notification_timeout": '错误消息停留时间',
		"help_bad_notification_timeout": '经典界面的错误消息显示时间。',
		"field_retrieve_extension_list": '联网获取扩展列表',
		"help_retrieve_extension_list": '允许 FreshRSS 获取可安装的扩展目录。',
		"field_send_referrer_allowlist": '允许发送来源网址的域名',
		"help_send_referrer_allowlist": '每行一个允许的域名或地址；其他原文链接不会发送来源网址。',
		"option_adaptive": '自动',
		"option_unread": '未读',
		"option_unread_or_favorite": '未读或收藏',
		"option_all": '全部',
		"option_active": '仅展开当前分类',
		"option_remember": '记住上次状态',
		"option_none": '不显示',
		"option_important": '重要订阅',
		"option_categories_all": '全部展开',
		"option_categories_none": '全部折叠',
	},
	en: {
		"loading": 'Loading account settings…',
		"save": 'Save changes',
		"reset": 'Revert changes',
		"retry": 'Try again',
		"saved": 'Saved',
		"saving": 'Saving…',
		"loadError": 'Account settings could not be loaded.',
		"saveError": 'Changes could not be saved. Your draft is preserved.',
		"invalid": 'Check the highlighted settings.',
		"invalidFilter": 'The filter syntax is invalid.',
		"csrfError": 'Your session has expired. Reload to continue.',
		"enabled": 'Enable',
		"unit": 'Unit',
		"unit_articles": 'articles',
		"unit_seconds": 'seconds',
		"unit_hours": 'hours',
		"unit_Y": 'years',
		"unit_M": 'months',
		"unit_W": 'weeks',
		"unit_D": 'days',
		"unit_H": 'hours',
		"unit_custom": 'Custom ISO 8601',
		"durationHelp": 'Enter an ISO 8601 duration, such as P1DT6H (1 day and 6 hours), PT30M (30 minutes), or PT45S (45 seconds).',
		"group_reading": 'Reading behavior',
		"group_classic_reading": 'Classic reading settings',
		"group_navigation": 'Classic navigation',
		"group_media": 'Media loading',
		"group_notifications": 'Notifications',
		"group_filters": 'Automatic marking and filters',
		"group_cleanup": 'Automatic cleanup',
		"group_storage": 'RSS output',
		"group_refresh": 'Update intervals',
		"group_privacy": 'Privacy',
		"cleanupNote": 'Cleanup runs during feed updates. Individual feeds can override these rules. Saving does not immediately remove existing articles.',
		"filterSyntax": 'One condition per line. Use intitle:word, author:name, intext:word, OR, parentheses, or ! to exclude. Leave empty to remove these filters.',
		"field_posts_per_page": 'Articles per page',
		"help_posts_per_page": 'Page size in the classic interface. The independent reader has its own page size above.',
		"field_default_view": 'Default article scope',
		"help_default_view": 'The article scope used when the classic interface opens.',
		"field_auto_load_more": 'Load more while scrolling',
		"help_auto_load_more": 'Load the next page at the bottom of the classic interface.',
		"field_onread_jump_next": 'Move to the next article after marking read',
		"help_onread_jump_next": 'Applies to manual marking in the classic interface.',
		"field_auto_remove_article": 'Hide articles just read',
		"help_auto_remove_article": 'Immediately remove read articles from the classic unread list.',
		"field_reading_confirm": 'Confirm bulk read actions',
		"help_reading_confirm": 'Ask before marking all articles read in the classic interface.',
		"field_mark_updated_article_unread": 'Mark updated articles unread',
		"help_mark_updated_article_unread": 'Bring previously read articles back to the unread list when their content changes.',
		'field_mark_when.article': 'Mark articles read when opened',
		'help_mark_when.article': 'Mark after an article loads successfully. You can still mark it unread manually.',
		'field_mark_when.site': 'Mark articles read when opening the original',
		'help_mark_when.site': 'Mark an article read when following its original link.',
		'field_mark_when.scroll': 'Mark read when scrolling past',
		'help_mark_when.scroll': 'Mark articles after they scroll out of view in the classic reading interface.',
		'field_mark_when.focus': 'Mark read when focused',
		'help_mark_when.focus': 'Mark articles when selected in the classic reading interface.',
		'field_mark_when.gone': 'Mark articles removed from the feed read',
		'help_mark_when.gone': 'Automatically mark articles no longer present in the source during feed updates.',
		'field_mark_when.reception': 'Mark new articles read immediately',
		'help_mark_when.reception': 'Newly received articles start as read. Their content is still stored.',
		'field_mark_when.max_n_unread': 'Maximum unread articles per feed',
		'help_mark_when.max_n_unread': 'Older articles above this limit are marked read. Disable for no limit.',
		'field_mark_when.same_title_in_feed': 'Mark duplicate titles read',
		'help_mark_when.same_title_in_feed': 'Check this many recent articles in the same feed for a matching title.',
		'field_filters.read': 'Conditions for automatically marking read',
		'help_filters.read': 'New articles matching any condition are marked read.',
		'field_filters.star': 'Conditions for automatically starring',
		'help_filters.star': 'New articles matching any condition are added to favorites.',
		"field_hide_read_feeds": 'Hide feeds without unread articles',
		"help_hide_read_feeds": 'Show only feeds with unread articles in the classic sidebar.',
		"field_display_categories": 'Category expansion',
		"help_display_categories": 'Choose how categories expand in the classic sidebar.',
		"field_show_unread_count": 'Unread counts',
		"help_show_unread_count": 'Choose where unread counts appear in the classic sidebar.',
		"field_show_title_unread": 'Unread count in the browser tab',
		"help_show_title_unread": 'Show the unread count in the classic interface tab title.',
		"field_show_favicons": 'Show website icons',
		"help_show_favicons": 'Show favicons in the classic feed list.',
		"field_icons_as_emojis": 'Use emoji icons',
		"help_icons_as_emojis": 'Replace some interface icons with emojis in the classic interface.',
		"field_lazyload": 'Load article images on demand',
		"help_lazyload": 'Reduce initial traffic by delaying image loading in the classic interface.',
		"field_ttl_default": 'Default feed update interval',
		"help_ttl_default": 'Used unless a feed specifies its own interval. The server update task determines when updates run.',
		"field_dynamic_opml_ttl_default": 'Default dynamic OPML interval',
		"help_dynamic_opml_ttl_default": 'Recheck dynamic subscription lists at this interval. Updates depend on the server task.',
		'field_archiving.keep_period': 'Article retention period',
		'help_archiving.keep_period': 'Older articles can be removed during updates. Disable to remove the age limit.',
		'field_archiving.keep_max': 'Maximum articles per feed',
		'help_archiving.keep_max': 'Older articles above this limit can be removed during updates.',
		'field_archiving.keep_min': 'Minimum articles per feed',
		'help_archiving.keep_min': 'Keep this many recent articles even when they exceed the retention period.',
		'field_archiving.keep_favourites': 'Keep starred articles',
		'help_archiving.keep_favourites': 'Exclude favorite articles from automatic cleanup.',
		'field_archiving.keep_labels': 'Keep labeled articles',
		'help_archiving.keep_labels': 'Exclude articles with personal labels from automatic cleanup.',
		'field_archiving.keep_unreads': 'Keep unread articles',
		'help_archiving.keep_unreads': 'Exclude unread articles from automatic cleanup.',
		"field_max_posts_per_rss": 'Maximum articles in RSS output',
		"help_max_posts_per_rss": 'Limits account RSS output, independently of database retention.',
		"field_since_hours_posts_per_rss": 'RSS output time range',
		"help_since_hours_posts_per_rss": 'Include articles from this many recent hours. 0 removes the time limit.',
		"field_html5_enable_notif": 'Browser notifications',
		"help_html5_enable_notif": 'Update notifications in the classic interface also require browser permission.',
		"field_html5_notif_timeout": 'Browser notification duration',
		"help_html5_notif_timeout": '0 keeps the notification open until dismissed.',
		"field_good_notification_timeout": 'Success message duration',
		"help_good_notification_timeout": 'Success messages in the classic interface. 0 disables these messages.',
		"field_bad_notification_timeout": 'Error message duration',
		"help_bad_notification_timeout": 'Error message duration in the classic interface.',
		"field_retrieve_extension_list": 'Fetch the extension catalog',
		"help_retrieve_extension_list": 'Allow FreshRSS to retrieve the available extension list online.',
		"field_send_referrer_allowlist": 'Domains allowed to receive a referrer',
		"help_send_referrer_allowlist": 'One permitted domain or address per line. Other original links do not send a referring URL.',
		"option_adaptive": 'Adaptive',
		"option_unread": 'Unread',
		"option_unread_or_favorite": 'Unread or starred',
		"option_all": 'All',
		"option_active": 'Expand the active category',
		"option_remember": 'Remember previous state',
		"option_none": 'None',
		"option_important": 'Important feeds',
		"option_categories_all": 'Expand all',
		"option_categories_none": 'Collapse all',
	},
};

const equal = (left, right) => JSON.stringify(left) === JSON.stringify(right);
const normalizeLines = value => [...new Set(value.map(line => line.trim()).filter(Boolean))];
const copy = (value, spec) => spec.type === 'string_list' && Array.isArray(value)
	? normalizeLines(value)
	: spec.type === 'duration' && typeof value === 'string' ? value.trim() : value;
const signature = field => JSON.stringify([field.input.value, field.input.checked, field.enabled?.checked, field.durationUnit?.value]);

/** Read typed values without treating explicit false or zero as empty. */
export function accountFieldValue({ spec, input, enabled, durationUnit }) {
	if ((spec.type === 'integer_or_false' || spec.type === 'duration') && !enabled.checked) return false;
	if (spec.type === 'boolean') return input.checked;
	if (spec.type === 'integer' || spec.type === 'integer_or_false') return input.value.trim() === '' ? NaN : Number(input.value);
	if (spec.type === 'duration') {
		if (durationUnit.value === 'custom') return input.value.trim();
		const count = input.value.trim();
		if (!/^\d+$/.test(count)) return '';
		return durationUnit.value === 'H' ? 'PT' + count + 'H' : 'P' + count + durationUnit.value;
	}
	if (spec.type === 'string_list') return normalizeLines(input.value.split(/\r?\n/));
	return input.value;
}

/** Local validation complements server validation of filters and configuration writes. */
export function accountFieldValid(value, spec) {
	if (value === false && (spec.type === 'integer_or_false' || spec.type === 'duration')) return true;
	if (spec.type === 'boolean') return typeof value === 'boolean';
	if (spec.type === 'integer' || spec.type === 'integer_or_false') {
		return Number.isSafeInteger(value) && (spec.min === undefined || value >= spec.min) && (spec.max === undefined || value <= spec.max);
	}
	if (spec.type === 'duration') return typeof value === 'string' && ISO_DURATION.test(value);
	if (spec.type === 'string_list') return Array.isArray(value) && value.every(item => typeof item === 'string');
	return typeof value === 'string' && (!(spec.options || spec.enum) || (spec.options || spec.enum).includes(value));
}

/** Account settings keep a separate draft for each visible settings category. */
export class AccountSettings {
	constructor({ api, bootstrap, root, element, t, onSaved = () => {}, onError = () => {} }) {
		Object.assign(this, { api, bootstrap, root, element, t, onSaved, onError });
		this.fields = new Map(); this.sections = new Map(); this.values = {}; this.epoch = 0;
		this.onInput = event => this.change(event);
		this.onSubmit = event => {
			const form = event.target.closest('[data-account-section]');
			if (!form || !this.root.contains(form)) return;
			event.preventDefault(); this.save(form.dataset.accountSection);
		};
		this.onClick = event => {
			const reset = event.target.closest('[data-account-reset]');
			if (reset && this.root.contains(reset)) this.reset(reset.dataset.accountReset);
			const retry = event.target.closest('[data-account-retry]');
			if (retry && this.root.contains(retry)) this.load();
		};
	}

	text(key) {
		const translated = this.t?.('account_' + key);
		if (translated && translated !== 'account_' + key) return translated;
		const language = (this.bootstrap.lang || this.bootstrap.language)?.startsWith('zh') ? 'zh' : 'en';
		return ACCOUNT_SETTINGS_MESSAGES[language][key] || ACCOUNT_SETTINGS_MESSAGES.en[key] || key;
	}

	mount() {
		for (const section of Object.keys(SECTIONS)) {
			const container = this.root.querySelector('[data-account-settings="' + section + '"]');
			if (!container) continue;
			const status = this.element('p', 'account-settings-status', this.text('loading'));
			status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
			container.append(status);
			this.sections.set(section, { container, status, revision: 0, busy: false });
		}
		this.root.addEventListener('input', this.onInput); this.root.addEventListener('change', this.onInput);
		this.root.addEventListener('submit', this.onSubmit); this.root.addEventListener('click', this.onClick);
		return this;
	}

	activate(section) {
		if (!this.sections.has(section) || this.loaded) return Promise.resolve();
		return this.load();
	}

	async load() {
		if (this.loading) return this.loading;
		const epoch = this.epoch; const controller = new AbortController(); this.controller = controller;
		for (const state of this.sections.values()) {
			state.status.textContent = this.text('loading'); state.retry?.remove();
		}
		this.loading = this.api.request('accountSettings', {}, { signal: controller.signal }).then(data => {
			if (this.epoch !== epoch) return;
			this.schema = data.schema;
			if (!this.schema?.fields || !data.values) throw new Error('invalid_response');
			if (!this.loaded) this.build();
			this.accept(data); this.loaded = true;
			for (const state of this.sections.values()) state.status.textContent = '';
		}).catch(error => {
			if (this.epoch !== epoch || controller.signal.aborted) return;
			this.failed(error);
			for (const state of this.sections.values()) {
				state.status.textContent = this.text(this.csrfInvalid ? 'csrfError' : 'loadError');
				const retry = this.element('button', '', this.text('retry')); retry.type = 'button'; retry.dataset.accountRetry = '';
				state.container.append(retry); state.retry = retry;
			}
		}).finally(() => { if (this.epoch === epoch) { this.loading = null; this.controller = null; } });
		return this.loading;
	}

	build() {
		for (const [section, state] of this.sections) {
			const form = this.element('form', 'account-settings-form'); form.dataset.accountSection = section; form.noValidate = true;
			for (const group of SECTIONS[section]) {
				const entries = Object.entries(this.schema.fields).filter(([, spec]) => spec.group === group);
				if (!entries.length) continue;
				if (section === 'reading' && group === 'reading') {
					const shared = SHARED_READING_FIELDS.flatMap(key => entries.filter(([entry]) => entry === key));
					const classic = entries.filter(([key]) => !SHARED_READING_FIELDS.includes(key));
					if (shared.length) form.append(this.buildGroup(group, shared, section));
					if (classic.length) form.append(this.buildGroup('classic_reading', classic, section, true));
				} else {
					form.append(this.buildGroup(group, entries, section, section === 'reading'));
				}
			}
			const actions = this.element('div', 'account-settings-actions');
			const reset = this.element('button', '', this.text('reset')); reset.type = 'button'; reset.dataset.accountReset = section;
			const save = this.element('button', 'primary', this.text('save')); save.type = 'submit';
			actions.append(reset, save, state.status); form.append(actions); state.container.replaceChildren(form);
			Object.assign(state, { form, reset, save });
		}
	}

	buildGroup(group, entries, section, disclosure = false) {
		const block = this.element(disclosure ? 'details' : 'section', 'account-settings-group');
		block.dataset.accountGroup = group;
		if (disclosure) block.classList.add('account-settings-disclosure');
		block.append(this.element(disclosure ? 'summary' : 'h3', 'account-settings-group-title', this.text('group_' + group)));
		const content = disclosure ? this.element('div', 'account-settings-group-content') : block;
		if (group === 'cleanup') content.append(this.element('p', 'account-settings-help', this.text('cleanupNote')));
		if (group === 'filters') content.append(this.element('p', 'account-settings-help', this.text('filterSyntax')));
		for (const [key, spec] of entries) content.append(this.buildField(key, spec, section));
		if (disclosure) block.append(content);
		return block;
	}

	buildField(key, spec, section) {
		const row = this.element('div', 'account-settings-row'); row.dataset.accountField = key;
		const copy = this.element('div', 'account-settings-label');
		const label = this.element('label', '', this.text('field_' + key)); label.htmlFor = 'account-setting-' + key.replaceAll('.', '-');
		const help = this.element('small', 'account-settings-help', this.text('help_' + key)); help.id = label.htmlFor + '-help';
		copy.append(label, help);
		const controls = this.element('div', 'account-settings-control');
		const input = this.element(spec.options || spec.enum ? 'select' : spec.type === 'string_list' ? 'textarea' : 'input');
		input.id = label.htmlFor; input.name = key; input.setAttribute('aria-describedby', help.id + ' ' + label.htmlFor + '-error');
		const field = { input, spec, section, row, initial: undefined };
		if (spec.options || spec.enum) {
			for (const value of spec.options || spec.enum) {
				const optionKey = key === 'display_categories' && ['all', 'none'].includes(value) ? 'option_categories_' + value : 'option_' + value;
				const option = this.element('option', '', this.text(optionKey)); option.value = value; input.append(option);
			}
		} else if (spec.type === 'boolean') {
			input.type = 'checkbox'; input.setAttribute('role', 'switch');
			const target = this.element('label', 'account-settings-switch-target'); target.append(input); controls.append(target);
		} else if (spec.type === 'string_list') {
			input.rows = 4; input.spellcheck = false; row.classList.add('account-settings-row-wide');
		} else {
			input.type = 'number'; input.step = 1;
			if (spec.min !== undefined) input.min = spec.min;
			if (spec.max !== undefined) input.max = spec.max;
		}
		if (spec.type === 'integer_or_false' || spec.type === 'duration') {
			const optional = this.element('label', 'account-settings-optional-label');
			const enabled = this.element('input'); enabled.type = 'checkbox'; enabled.setAttribute('aria-controls', input.id);
			enabled.setAttribute('aria-label', this.text('enabled') + ' ' + this.text('field_' + key));
			const enableText = this.element('span', '', this.text('enabled'));
			optional.append(enabled, enableText); controls.append(optional); field.enabled = enabled;
		}
		if (spec.type !== 'boolean') {
			const value = this.element('div', 'account-settings-value'); value.append(input);
			if (spec.type === 'duration') {
				input.min = 0;
				const unit = this.element('select'); unit.setAttribute('aria-label', this.text('field_' + key) + ' ' + this.text('unit'));
				for (const name of ['D', 'W', 'M', 'Y', 'H', 'custom']) {
					const option = this.element('option', '', this.text('unit_' + name)); option.value = name; unit.append(option);
				}
				value.append(unit); field.durationUnit = unit;
				const hint = this.element('small', 'account-settings-help account-settings-duration-help', this.text('durationHelp'));
				hint.id = label.htmlFor + '-duration-help'; hint.hidden = true;
				input.setAttribute('aria-describedby', input.getAttribute('aria-describedby') + ' ' + hint.id);
				field.durationHelp = hint; copy.append(hint);
			} else if (spec.unit && spec.unit !== 'iso8601') value.append(this.element('span', 'account-settings-unit', this.text('unit_' + spec.unit)));
			controls.append(value);
		}
		const error = this.element('p', 'account-settings-error'); error.id = label.htmlFor + '-error'; error.hidden = true; field.error = error;
		row.append(copy, controls, error); this.fields.set(key, field);
		return row;
	}

	setField(field, value) {
		const { input, spec, enabled, durationUnit } = field;
		if (spec.type === 'boolean') input.checked = value === true;
		else if (spec.type === 'string_list') input.value = Array.isArray(value) ? value.join('\n') : '';
		else if (spec.type === 'duration') {
			const match = typeof value === 'string' ? SIMPLE_DURATION.exec(value) : null;
			if (match) {
				durationUnit.value = match[2] || match[4]; this.configureDuration(field); input.value = match[1] || match[3];
			} else if (typeof value === 'string') {
				durationUnit.value = 'custom'; this.configureDuration(field); input.value = value; field.customDuration = value;
			} else if (!input.value) {
				durationUnit.value = 'M'; this.configureDuration(field); input.value = '3';
			}
		} else if (value !== false || !input.value) {
			input.value = value === false || value === null || value === undefined ? String(spec.min || 0) : String(value);
		}
		if (enabled) { enabled.checked = value !== false; input.disabled = !enabled.checked; if (durationUnit) durationUnit.disabled = !enabled.checked; }
		field.signature = signature(field);
	}

	configureDuration(field) {
		const custom = field.durationUnit.value === 'custom';
		field.input.type = custom ? 'text' : 'number';
		if (custom) {
			field.input.removeAttribute('min'); field.input.removeAttribute('max'); field.input.removeAttribute('step');
			field.input.placeholder = 'P1DT6H'; field.input.spellcheck = false;
		} else {
			field.input.min = 0; field.input.step = 1; field.input.placeholder = '';
		}
		if (field.durationHelp) field.durationHelp.hidden = !custom;
		field.durationMode = field.durationUnit.value;
	}

	changeDurationUnit(field) {
		const raw = field.input.value.trim(); const previous = field.durationMode;
		const custom = field.durationUnit.value === 'custom'; let value = raw;
		if (custom) {
			value = /^\d+$/.test(raw) ? previous === 'H' ? 'PT' + raw + 'H' : 'P' + raw + previous : field.customDuration ?? raw;
		} else if (previous === 'custom') {
			field.customDuration = raw;
			const match = SIMPLE_DURATION.exec(raw);
			// A combined duration cannot be safely guessed as a single quantity.
			value = match ? match[1] || match[3] : '';
		}
		this.configureDuration(field); field.input.value = value;
	}

	accept(data, sent = {}) {
		this.values = { ...data.values };
		for (const [key, field] of this.fields) {
			if (!(key in data.values)) continue;
			const current = accountFieldValue(field); const clean = field.initial === undefined || equal(current, field.initial);
			if (clean || (Object.hasOwn(sent, key) && equal(current, sent[key]))) this.setField(field, data.values[key]);
			field.initial = copy(data.values[key], field.spec);
		}
		for (const section of this.sections.keys()) this.sync(section);
	}

	changes(section) {
		const values = {};
		for (const [key, field] of this.fields) {
			if (section && field.section !== section) continue;
			const value = accountFieldValue(field);
			if (!equal(value, field.initial)) values[key] = value;
		}
		return values;
	}

	hasDraft(section) { return Object.keys(this.changes(section)).length > 0; }

	sync(section) {
		const state = this.sections.get(section);
		if (!state?.save) return;
		const dirty = this.hasDraft(section);
		state.save.disabled = !!this.csrfInvalid || state.busy || !dirty;
		state.save.textContent = this.text(state.busy ? 'saving' : 'save');
		state.reset.hidden = !dirty; state.reset.disabled = state.busy;
		state.form.setAttribute('aria-busy', String(state.busy));
	}

	change(event) {
		const row = event.target.closest('[data-account-field]'); const field = this.fields.get(row?.dataset.accountField);
		if (!field || !this.root.contains(row)) return;
		if (field.durationUnit && field.durationMode !== field.durationUnit.value) this.changeDurationUnit(field);
		const current = signature(field);
		if (field.signature === current) return;
		field.signature = current;
		const state = this.sections.get(field.section); state.revision++;
		field.error.hidden = true; field.input.removeAttribute('aria-invalid'); state.status.textContent = '';
		if (field.enabled) {
			field.input.disabled = !field.enabled.checked;
			if (field.durationUnit) field.durationUnit.disabled = !field.enabled.checked;
		}
		this.sync(field.section);
	}

	reset(section) {
		const state = this.sections.get(section);
		if (!state || state.busy) return;
		for (const field of this.fields.values()) {
			if (field.section !== section) continue;
			this.setField(field, field.initial); field.error.hidden = true; field.input.removeAttribute('aria-invalid');
		}
		state.revision++; state.status.textContent = ''; this.sync(section);
	}

	invalid(section, keys, message) {
		const state = this.sections.get(section); state.status.textContent = message;
		for (const key of keys) {
			const field = this.fields.get(key);
			if (!field || field.section !== section) continue;
			field.error.textContent = message; field.error.hidden = false; field.input.setAttribute('aria-invalid', 'true');
			if (!field.row?.closest?.('[hidden]')) {
				let disclosure = field.row?.closest?.('details');
				while (disclosure) { disclosure.open = true; disclosure = disclosure.parentElement?.closest('details'); }
			}
		}
		const first = this.fields.get(keys[0]);
		if (first && !first.input.disabled && !first.row?.closest?.('[hidden]')) first.input.focus();
	}

	failed(error) {
		if (error?.status === 403) this.csrfInvalid = true;
		this.onError(error);
		if (this.csrfInvalid) for (const section of this.sections.keys()) this.sync(section);
	}

	async save(section) {
		const state = this.sections.get(section);
		if (!state || state.busy || this.csrfInvalid) return;
		const values = this.changes(section); const keys = Object.keys(values);
		if (!keys.length) return;
		const invalid = keys.filter(key => !accountFieldValid(values[key], this.fields.get(key).spec));
		if (invalid.length) { this.invalid(section, invalid, this.text('invalid')); return; }
		const revision = state.revision; const epoch = this.epoch;
		const controller = new AbortController(); state.controller = controller; state.busy = true;
		state.status.textContent = this.text('saving'); this.sync(section);
		// Serial writes keep full server snapshots from arriving out of order across categories.
		const previous = this.saveTail; let release;
		this.saveTail = new Promise(resolve => { release = resolve; });
		try {
			if (previous) await previous;
			if (this.epoch !== epoch || controller.signal.aborted || this.csrfInvalid) return;
			const data = await this.api.request('accountSettings', {}, { signal: controller.signal, body: { values } });
			if (this.epoch !== epoch) return;
			if (!data.schema?.fields || !data.values) throw new Error('invalid_response');
			this.accept(data, values); this.onSaved(data.values, values);
			state.status.textContent = state.revision === revision ? this.text('saved') : '';
		} catch (error) {
			if (this.epoch !== epoch || controller.signal.aborted) return;
			this.failed(error);
			if (state.revision !== revision) return;
			const field = error.details?.field || error.details?.details?.field;
			const errors = Array.isArray(error.fields) ? error.fields : field ? [field] : [];
			const message = this.text(this.csrfInvalid ? 'csrfError' : error.message === 'invalid_filter' ? 'invalidFilter' : 'saveError');
			this.invalid(section, errors, message);
		} finally {
			release();
			if (this.epoch === epoch) { state.busy = false; state.controller = null; this.sync(section); }
		}
	}

	dispose() {
		this.epoch++; this.controller?.abort();
		for (const state of this.sections.values()) state.controller?.abort();
		this.root.removeEventListener('input', this.onInput); this.root.removeEventListener('change', this.onInput);
		this.root.removeEventListener('submit', this.onSubmit); this.root.removeEventListener('click', this.onClick);
	}
}
