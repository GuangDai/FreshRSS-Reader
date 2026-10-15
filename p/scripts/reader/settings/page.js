import { ConfigurationSettings } from './configuration.js';
import { AccountSettings } from './account.js';
import { BackupSettings } from './backup.js';

const sections = [
	['appearance', '外观与排版', 'Appearance', '主题、布局和信息密度。更改会即时预览。', 'Themes, layout and density. Changes preview immediately.', 'settings'],
	['reading', '阅读与操作', 'Reading', '正文排版、阅读行为、导航和快捷键。', 'Article typography, reading behaviour, navigation and keyboard shortcuts.', 'list'],
	[
		'subscriptions', '订阅与分类', 'Subscriptions',
		'管理每一个订阅，也可以筛选后统一调整分类、刷新间隔和显示位置。', 'Manage each subscription, or filter and update categories, refresh intervals and visibility in batches.',
		'rss',
	],
	[
		'filters', '过滤与规则', 'Filters and rules',
		'自动已读、收藏、标签与条件规则，保存前可用样本检查结果。', 'Automatic reading, starring, labels and conditional rules. Test rules with a sample before saving.',
		'all',
	],
	[
		'feeds', '全文与解析', 'Full text and parsing',
		'按订阅配置全文抓取、内容选择器和解析配方。', 'Configure full-text fetching, content selectors and extraction recipes per subscription.',
		'images',
	],
	[
		'data', '数据与备份', 'Data and backups',
		'导入订阅和文章，导出 OPML、收藏、标签与配置。', 'Import subscriptions and articles; export OPML, starred entries, labels and settings.',
		'export',
	],
	[
		'storage', '存储与回收', 'Storage and cleanup',
		'查看数据占用，自定义保留策略、正文缓存和数据库维护。', 'Inspect storage, customise retention, article caching and database maintenance.',
		'archive',
	],
	['privacy', '账号与隐私', 'Account and privacy', '账号、安全、API 访问和文章中的外部资源。', 'Account security, API access and external resources in articles.', 'user'],
	[
		'extensions', '扩展与分享', 'Extensions and sharing',
		'管理扩展、阅读辅助、分享服务及自定义样式。', 'Manage extensions, reading assistance, sharing services and custom styles.',
		'extension',
	],
	['automation', '更新与任务', 'Updates and tasks', '订阅刷新间隔、计数同步和后台任务调度。', 'Feed refresh intervals, count synchronisation and background scheduling.', 'refresh'],
	['advanced', '高级与系统', 'Advanced', '服务器上限、实例维护和其他管理工具。', 'Server limits, instance maintenance and other administration tools.', 'settings'],
];

// These destinations use the complete, existing FreshRSS forms and their authentication flow.
const toolCatalog = {
	appearance: [
		['classic-display', '经典界面的外观', 'Classic appearance', '语言、时区、主题、文章信息和动作显示。', 'Language, time zone, themes and article information.', 'configure', 'display'],
	],
	reading: [
		['shortcuts', '快捷键', 'Keyboard shortcuts', '自定义经典界面的按键，或恢复默认组合。', 'Customise classic-interface shortcuts or restore defaults.', 'configure', 'shortcut'],
	],
	subscriptions: [
		[
			'add-feed', '添加订阅', 'Add a subscription',
			'RSS、Atom、网页与动态 OPML；支持完整抓取选项。', 'RSS, Atom, web pages and dynamic OPML, with full fetching options.',
			'subscription', 'add',
		],
		[
			'organise-feeds', '分类与订阅详情', 'Categories and feed details',
			'新增分类、排序、动态 OPML，以及订阅的全部参数。', 'Create and order categories, manage dynamic OPML and all feed parameters.',
			'subscription', 'index',
		],
		['tags', '标签管理', 'Labels', '添加、重命名、合并标签，设置自动标签条件。', 'Create, rename and merge labels; configure automatic labelling.', 'tag', 'index'],
	],
	filters: [
		[
			'rule-builder', '可视化规则编辑器', 'Visual rule editor',
			'组合条件与动作，设置适用订阅、优先级和预设。', 'Combine conditions and actions, scopes, priorities and presets.',
			'extension', 'configure', { e: 'Rule Engine' },
		],
		[
			'saved-searches', '已保存的查询', 'Saved searches',
			'保存搜索、排序和筛选条件，配置 RSS 与 OPML 分享。', 'Save search, order and filters; configure RSS and OPML sharing.',
			'configure', 'queries',
		],
		['filter-labels', '自动标签', 'Automatic labels', '为标签设置筛选规则，统一整理符合条件的文章。', 'Set label filters to organise matching articles.', 'tag', 'index'],
	],
	feeds: [
		[
			'extraction-builder', '可视化解析编辑器', 'Visual extraction editor',
			'HTML、XML、JSON 配方，字段候选、转换和样本测试。', 'HTML, XML and JSON recipes, candidate fields, transformations and sample tests.',
			'extension', 'configure', { e: 'Web Extraction' },
		],
	],
	data: [
		[
			'import-export', '订阅与文章的导入导出', 'Import and export',
			'OPML、JSON、ZIP，以及可用的 SQLite 归档。源文章导出目前每源最多 50 篇。',
			'OPML, JSON, ZIP and available SQLite archives. Feed exports currently include up to 50 entries per feed.',
			'importExport', 'index',
		],
		[
			'extension-backup', '扩展配置备份', 'Extension configuration backup',
			'导入、导出各扩展的设置，保存时检查版本冲突。', 'Import and export extension settings with version conflict checks.',
			'extension', 'index',
		],
	],
	storage: [
		[
			'storage-maintenance', '清理执行与数据库维护', 'Cleanup and database maintenance',
			'按已有保留策略清理文章、优化数据库，下载可用的归档。', 'Purge articles using existing retention policies, optimise the database and download available archives.',
			'configure', 'archiving',
		],
	],
	privacy: [
		[
			'profile', '账号与安全', 'Account and security',
			'邮箱、密码、RSS 访问令牌及移动客户端 API 密码。', 'Email, password, RSS access token and mobile-client API password.',
			'user', 'profile',
		],
	],
	extensions: [
		[
			'extensions', '已安装扩展', 'Installed extensions',
			'启用、停用和配置扩展；管理配置的导入与导出。', 'Enable, disable and configure extensions; import and export their settings.',
			'extension', 'index',
		],
		['extension-catalog', '扩展目录', 'Extension catalog', '查看可用扩展和安装说明。', 'Browse available extensions and installation instructions.', 'extension', 'catalog'],
		[
			'sharing', '分享服务', 'Sharing services',
			'添加、排序或移除服务，也可以自定义分享地址。', 'Add, reorder or remove services and configure custom sharing URLs.',
			'configure', 'integration',
		],
		[
			'reader-comfort', '阅读辅助', 'Reading assistance',
			'Reader Comfort 的字号、行距、正文宽度和间距。', 'Reader Comfort text size, line spacing, article width and margins.',
			'extension', 'configure', { e: 'Reader Comfort' },
		],
	],
	automation: [],
	advanced: [
		['statistics', '统计', 'Statistics', '订阅更新、文章数量和阅读记录。', 'Feed updates, entry counts and reading history.', 'stats', 'index'],
		['logs', '日志', 'Logs', '查看抓取失败、更新和其他运行记录。', 'Review fetch failures, updates and other operational records.', 'index', 'logs'],
		[
			'system', '实例设置', 'Instance settings',
			'名称、订阅上限、注册与其他服务器选项。', 'Instance name, subscription limits, registration and server options.',
			'configure', 'system', {}, true,
		],
		[
			'authentication', '认证与 API', 'Authentication and API',
			'认证方式、匿名访问和 API 开关。', 'Authentication method, anonymous access and API availability.',
			'auth', 'index', {}, true,
		],
		[
			'users', '用户管理', 'Users',
			'创建、停用和维护账号。敏感操作沿用重新认证。', 'Create, disable and maintain accounts. Sensitive actions retain reauthentication.',
			'user', 'manage', {}, true,
		],
		[
			'updates', '更新与安装检查', 'Updates and installation checks',
			'检查版本与运行环境，使用现有的更新流程。', 'Check versions and installation health using the existing update workflow.',
			'update', 'index', {}, true,
		],
	],
};

export function settingsSections(language = 'en') {
	const zh = language.toLowerCase().startsWith('zh');
	return sections.map(([id, cn, en, cnDescription, enDescription, icon]) => ({ id, title: zh ? cn : en, description: zh ? cnDescription : enDescription, icon }));
}

export class SettingsPage {
	constructor({ root, api, store, bootstrap, element, t, onSection, onAccountSaved, onError }) {
		Object.assign(this, { root, api, store, bootstrap, element, t, onSection, onError });
		this.zh = bootstrap.language.toLowerCase().startsWith('zh');
		this.sections = settingsSections(bootstrap.language);
		this.section = 'appearance'; this.opened = false; this.storageEpoch = 0; this.toolDirty = false;
		this.account = new AccountSettings({ root, api, bootstrap, element, t, onSaved: onAccountSaved, onError });
		this.tools = new ConfigurationSettings({ root, api, bootstrap, store, element, t, onStatus: () => {} });
		this.backups = new BackupSettings({ root, api, bootstrap, element, t, onError });
		this.message = event => this.receiveMessage(event);
	}

	text(zh, en) { return this.zh ? zh : en; }
	node(id) { return this.root.querySelector('#' + id); }

	mount() {
		this.node('settings-account').textContent = this.bootstrap.username || '';
		const navigation = this.node('settings-navigation');
		const select = this.node('settings-section-select');
		const panels = this.node('settings-panels');
		for (const section of this.sections) {
			const button = this.element('button'); button.type = 'button'; button.dataset.settingsSection = section.id;
			const icon = this.element('span', 'reader-icon icon-' + section.icon); icon.setAttribute('aria-hidden', 'true');
			button.append(icon, this.element('span', '', section.title)); navigation.append(button);
			const option = this.element('option', '', section.title); option.value = section.id; select.append(option);
			const panel = this.element('section', 'settings-section'); panel.dataset.settingsPanel = section.id; panel.hidden = true;
			if (['appearance', 'reading', 'privacy', 'storage', 'automation', 'advanced'].includes(section.id)) {
				const fields = this.element('div'); fields.dataset.readerPreferences = section.id; panel.append(fields);
			}
			if (section.id === 'appearance') {
				const preview = this.element('div', 'appearance-preview');
				const identity = this.element('div', 'preview-identity');
				const name = this.element('strong', '', 'Sunset'); name.id = 'preview-theme-name';
				const mode = this.element('small'); mode.id = 'preview-theme-mode'; identity.append(name, mode);
				const swatches = this.element('div', 'preview-swatches'); swatches.setAttribute('aria-hidden', 'true');
				for (let i = 0; i < 4; i++) swatches.append(this.element('span'));
				preview.append(identity, swatches); panel.prepend(preview);
			}
			if (['reading', 'filters', 'storage', 'privacy', 'automation'].includes(section.id)) {
				const account = this.element('div'); account.dataset.accountSettings = section.id; panel.append(account);
			}
			if (['filters', 'feeds', 'automation'].includes(section.id)) {
				const tools = this.element('div'); tools.dataset.settingsTools = section.id; panel.append(tools);
			}
			if (section.id === 'subscriptions') this.buildSubscriptions(panel);
			if (section.id === 'feeds') {
				const label = this.element('label', 'settings-feed-picker', this.text('选择需要配置的订阅', 'Choose a subscription to configure'));
				const feed = this.element('select'); feed.id = 'settings-feed-picker'; label.append(feed);
				const edit = this.element('button', '', this.text('打开完整 Feed 设置', 'Open full feed settings')); edit.type = 'button'; edit.id = 'settings-edit-feed';
				panel.prepend(label, edit);
			}
			if (section.id === 'storage') {
				const stats = this.element('div', 'settings-storage-stats'); stats.id = 'settings-storage-stats'; stats.setAttribute('aria-live', 'polite');
				const status = this.element('p', 'settings-note'); status.id = 'settings-storage-status'; status.setAttribute('role', 'status');
				const refresh = this.element('button', '', this.text('更新统计', 'Refresh statistics'));
				refresh.type = 'button';
				refresh.id = 'settings-refresh-storage';
				const heading = this.element('div', 'settings-storage-heading'); heading.append(this.element('h3', '', this.text('当前数据', 'Current data')), refresh);
				const usage = this.element('section', 'settings-group'); usage.append(heading, stats, status); panel.prepend(usage);
			}
			if (section.id === 'data') {
				this.buildTransfer(panel);
				const backups = this.element('div'); backups.dataset.backupSettings = 'true'; panel.append(backups);
			}
			if (section.id === 'advanced') this.buildLimits(panel);
			this.buildTools(section.id, panel);
			panels.append(panel);
		}
		const preview = this.element('details', 'reading-preview'); preview.id = 'reading-preview';
		preview.append(this.element('summary', '', this.t('readingPreview')));
		const paper = this.element('div', 'settings-preview');
		paper.append(this.element('strong', '', this.t('previewTitle')), this.element('p', '', this.t('previewText')));
		preview.append(paper);
		this.root.querySelector('[data-reader-preferences="reading"]').append(preview);
		navigation.addEventListener('click', event => {
			const button = event.target.closest('[data-settings-section]'); if (button) this.onSection(button.dataset.settingsSection);
		});
		select.addEventListener('change', () => this.onSection(select.value));
		this.node('settings-search').addEventListener('input', () => this.search());
		this.root.addEventListener('click', event => {
			if (event.target.closest('[data-resume-settings-tool]') && this.toolRecord) this.openTool(this.toolRecord.href, this.toolRecord.title);
			const tool = event.target.closest('[data-settings-tool]');
			if (tool) this.openTool(tool.dataset.settingsTool, tool.dataset.toolTitle);
			const result = event.target.closest('[data-settings-result]');
			if (result) {
				this.node('settings-search').value = ''; this.search(); this.onSection(result.dataset.settingsResult);
				const target = result.dataset.focusAccount
					? this.root.querySelector(
						`[data-account-field="${result.dataset.focusAccount}"] input, ` +
						`[data-account-field="${result.dataset.focusAccount}"] select, ` +
						`[data-account-field="${result.dataset.focusAccount}"] textarea`)
					: result.dataset.focusPreference && this.root.querySelector(
						`[data-preference="${result.dataset.focusPreference}"] input, ` +
						`[data-preference="${result.dataset.focusPreference}"] select`);
				for (let parent = target?.parentElement; parent && parent !== this.root; parent = parent.parentElement) {
					if (parent.tagName === 'DETAILS') parent.open = true;
				}
				target?.focus();
			}
		});
		this.node('settings-edit-feed').addEventListener('click', () => {
			const picker = this.node('settings-feed-picker'); if (picker.value) this.openFeed(Number(picker.value));
		});
		this.node('close-settings-tool').addEventListener('click', () => this.closeTool());
		this.node('settings-refresh-storage').addEventListener('click', () => this.loadStorage());
		window.addEventListener('message', this.message);
		this.account.mount(); this.tools.mount(); this.backups.mount();
	}

	buildSubscriptions(panel) {
		const toolbar = this.element('div', 'settings-subscriptions-toolbar');
		const search = this.element('input');
		search.type = 'search';
		search.id = 'settings-feed-search';
		search.placeholder = this.t('feedSearch');
		search.setAttribute('aria-label', this.t('feedSearch'));
		const filter = this.element('select'); filter.id = 'settings-feed-filter'; filter.setAttribute('aria-label', this.text('订阅状态', 'Subscription status'));
		for (const [value, zh, en] of [
			['all', '全部订阅', 'All subscriptions'],
			['error', '抓取失败', 'Fetch failed'],
			['paused', '已暂停', 'Paused'],
			['enabled', '抓取已启用', 'Fetching enabled'],
		]) {
			const option = this.element('option', '', this.text(zh, en)); option.value = value; filter.append(option);
		}
		const category = this.element('select'); category.id = 'settings-feed-category'; category.setAttribute('aria-label', this.text('筛选分类', 'Filter categories'));
		toolbar.append(search, category, filter); panel.append(toolbar);
		const selection = this.element('div', 'settings-subscriptions-selection'); selection.id = 'feed-selection-toolbar';
		const actions = this.element('div'); actions.id = 'feed-selection-actions';
		const count = this.element('span'); count.id = 'feed-selection-count'; count.setAttribute('role', 'status'); actions.append(count);
		for (const [id, key] of [
			['select-visible-feeds', 'selectMatchingFeeds'],
			['clear-feed-selection', 'clearSelection'],
			['edit-selected-feeds', 'editSelectedFeeds'],
		]) {
			const button = this.element('button', '', this.t(key)); button.type = 'button'; button.id = id; actions.append(button);
		}
		const progress = this.element('button', '', this.t('showFeedJob'));
		progress.type = 'button';
		progress.id = 'show-feed-job';
		progress.hidden = true;
		selection.append(actions, progress);
		panel.append(selection);
		const list = this.element('div', 'settings-subscriptions-list');
		list.id = 'settings-feed-list';
		list.setAttribute('aria-label', this.text('订阅管理列表', 'Subscription management list'));
		panel.append(list);
		const empty = this.element('p', 'settings-note'); empty.id = 'settings-feeds-empty'; empty.hidden = true; panel.append(empty);
	}

	buildTransfer(panel) {
		const transfer = this.element('section', 'settings-group settings-transfer');
		transfer.append(
			this.element('h3', '', this.text('阅读器配置', 'Reader preferences')),
			this.element('p', 'settings-note', this.text(
				'保存主题、排版和缓存设置。导入会先作为草稿预览，保存后生效。',
				'Export themes, typography and cache preferences. Imports are previewed as drafts before saving.')));
		const tools = this.element('div', 'settings-tools');
		for (const [id, key] of [['export-settings', 'export'], ['import-settings', 'import'], ['reset-settings', 'reset']]) {
			const button = this.element('button', '', this.t(key)); button.type = 'button'; button.id = id; tools.append(button);
		}
		const file = this.element('input');
		file.type = 'file';
		file.id = 'import-file';
		file.accept = 'application/json,.json';
		file.hidden = true;
		tools.append(file);
		transfer.append(tools);
		panel.append(transfer);
	}

	buildLimits(panel) {
		const limits = this.element('details', 'limits-details');
		limits.append(this.element('summary', '', this.t('serverLimits')));
		const values = this.element('dl'); values.id = 'server-limits'; limits.append(values);
		const save = this.element('button', '', this.t('saveLimits'));
		save.type = 'button';
		save.id = 'save-server-limits';
		save.hidden = true;
		limits.append(save);
		panel.append(limits);
		const classic = this.element('a', 'settings-classic-link', this.t('classic')); classic.href = './'; panel.append(classic);
	}

	buildTools(section, panel) {
		const list = this.element('div', 'settings-tool-links');
		for (const [id, zh, en, zhDescription, enDescription, c, a, params = {}, admin = false] of toolCatalog[section] || []) {
			if (admin && !this.bootstrap.canManageLimits) continue;
			const url = new URL('./', location.href); url.search = new URLSearchParams({ c, a, ...params });
			const button = this.element('button', 'settings-tool-link');
			button.type = 'button';
			button.dataset.settingsTool = url.href;
			button.dataset.toolTitle = this.text(zh, en);
			button.dataset.toolId = id;
			const content = this.element('span');
			content.append(this.element('strong', '', this.text(zh, en)), this.element('small', '', this.text(zhDescription, enDescription)));
			const arrow = this.element('span', 'reader-icon icon-next');
			arrow.setAttribute('aria-hidden', 'true');
			button.append(content, arrow);
			list.append(button);
		}
		if (list.childNodes.length) panel.append(list);
	}

	activate(section, focus = false) {
		if (!this.sections.some(value => value.id === section)) section = 'appearance';
		const changed = section !== this.section;
		if ((changed || focus) && this.node('settings-search').value) {
			this.node('settings-search').value = ''; this.search();
		}
		this.section = section;
		if (changed) this.closeTool();
		const selected = this.sections.find(value => value.id === section);
		this.node('settings-section-heading').textContent = selected.title;
		this.node('settings-section-description').textContent = selected.description;
		this.syncDocument();
		this.node('settings-section-select').value = section;
		for (const button of this.root.querySelectorAll('[data-settings-section]')) {
			button.setAttribute('aria-current', button.dataset.settingsSection === section ? 'page' : 'false');
		}
		for (const panel of this.root.querySelectorAll('[data-settings-panel]')) panel.hidden = panel.dataset.settingsPanel !== section;
		if (changed) this.root.querySelector('.settings-content').scrollTop = 0;
		if (focus) this.node('settings-section-heading').focus({ preventScroll: true });
		this.account.activate(section); this.tools.activate(section); this.backups.activate(section);
		if (section === 'storage' && !this.storageLoaded) this.loadStorage();
		this.syncSavebar();
	}

	syncSavebar(dirty = this.readerDirty) {
		this.readerDirty = Boolean(dirty);
		this.node('settings-savebar').hidden = !this.readerDirty && this.node('settings-status').hidden;
		this.node('save-reader-settings').hidden = !this.readerDirty;
		this.node('discard-settings').hidden = !this.readerDirty;
	}

	open() {
		this.opened = true;
		this.account.activate('reading').then(() => { if (this.opened && this.node('settings-search').value) this.search(); });
		this.activate(this.section);
	}

	close() { this.opened = false; this.closeTool(); this.syncDocument(); }

	syncDocument() {
		const document = this.root.ownerDocument; if (!document) return;
		const selected = this.sections.find(section => section.id === this.section);
		document.title = this.opened ? `${selected.title} · ${this.t('settings')} · FreshRSS` : 'FreshRSS · Reader';
		const skip = document.querySelector('.skip-link'); if (!skip) return;
		skip.href = this.opened ? '#settings-section-heading' : '#article-list';
		skip.textContent = this.opened ? this.text('跳到设置内容', 'Skip to settings content') : this.t('skipToArticles');
	}

	hasDraft() { return Boolean(this.readerDirty || this.toolDirty || this.account.hasDraft() || this.tools.hasDraft() || this.backups.hasDraft()); }

	openFeed(id) {
		const feed = this.store.feeds.find(feed => feed.id === id); if (!feed) return;
		const url = new URL('./', location.href); url.search = new URLSearchParams({ c: 'subscription', a: 'feed', id });
		this.openTool(url.href, feed.name);
	}

	openTool(href, title) {
		const url = new URL(href, location.href); if (url.origin !== location.origin) return;
		url.searchParams.delete('readerSettings');
		const retained = this.toolRecord?.href === url.href && this.node('settings-tool-frame').hasAttribute('src');
		if (!retained && this.toolDirty && !window.confirm(this.text('当前工具中有未保存的更改。放弃这些更改并打开另一个工具？',
			'The current tool has unsaved changes. Discard them and open another tool?'))) return;
		this.toolRecord = { href: url.href, title, section: this.section };
		this.node('settings-tool-window').href = url.href;
		this.node('settings-tool-heading').textContent = title;
		this.node('settings-tool-frame').title = title;
		url.searchParams.set('readerSettings', '1');
		if (!retained) this.node('settings-tool-frame').src = url.href;
		this.node('settings-embedded').hidden = false;
		this.node('settings-panels').hidden = true;
		this.root.querySelector('.settings-section-heading').hidden = true;
		this.node('settings-tool-status').textContent = this.t('loading'); this.node('settings-tool-status').hidden = retained;
		if (!retained) this.toolDirty = false;
		this.syncToolDraft(); this.syncSavebar(); this.node('close-settings-tool').focus();
	}

	closeTool() {
		const wasOpen = !this.node('settings-embedded').hidden;
		this.node('settings-embedded').hidden = true; this.node('settings-panels').hidden = false;
		this.root.querySelector('.settings-section-heading').hidden = false;
		if (wasOpen) {
			this.store.feedsRefresh().catch(this.onError); this.storageLoaded = false;
		}
		this.syncToolDraft(); this.syncSavebar();
	}

	syncToolDraft() {
		this.root.querySelector('[data-resume-settings-tool]')?.remove();
		if (!this.toolDirty || !this.toolRecord) return;
		const button = this.element('button', 'settings-tool-link settings-draft-link', this.text('继续编辑：', 'Continue editing: ') + this.toolRecord.title);
		button.type = 'button'; button.dataset.resumeSettingsTool = 'true';
		this.root.querySelector(`[data-settings-panel="${this.toolRecord.section}"]`).prepend(button);
	}

	syncToolAppearance() {
		const frame = this.node('settings-tool-frame');
		try {
			const document = frame.contentDocument; if (!document?.documentElement) return;
			const style = getComputedStyle(this.root);
			for (const key of ['surface', 'text', 'line', 'accent', 'subtle', 'muted', 'primary', 'on-accent', 'error']) {
				document.documentElement.style.setProperty('--reader-settings-' + key, style.getPropertyValue('--' + key).trim());
			}
			document.documentElement.style.setProperty('--reader-settings-padding', '0px');
			document.documentElement.style.colorScheme = style.colorScheme;
		} catch { /* Only authenticated same-origin tools receive appearance tokens. */ }
	}

	receiveMessage(event) {
		if (event.origin !== location.origin || event.source !== this.node('settings-tool-frame').contentWindow ||
			event.data?.type !== 'reader-settings-embed') return;
		const data = event.data;
		const wasDirty = this.toolDirty;
		if (data.event === 'ready' || data.event === 'saved') {
			this.toolDirty = Boolean(data.dirty);
			this.syncToolAppearance(); this.syncToolDraft();
			this.node('settings-tool-status').hidden = true;
			if (data.event === 'saved') { this.toolDirty = false; this.storageLoaded = false; }
			if ((data.event === 'saved' || wasDirty) && ['filters', 'feeds'].includes(this.toolRecord?.section)) {
				this.tools.refreshExtension(this.toolRecord.section);
			}
		}
		if (data.event === 'dirty') { this.toolDirty = Boolean(data.dirty); this.syncToolDraft(); }
		if (data.event === 'resize' && Number.isFinite(data.height)) {
			this.node('settings-tool-frame').style.height = Math.max(400, Math.min(100000, data.height + 12)) + 'px';
		}
		if (data.event === 'navigate' && typeof data.url === 'string') {
			try {
				const url = new URL(data.url, location.href);
				if (url.origin === location.origin && !url.username && !url.password) window.open(url.href, '_blank', 'noopener');
			} catch { /* Ignore malformed tool messages. */ }
		}
	}

	updateFeeds() {
		if (this.feeds === this.store.feeds && this.feedRevision === this.store.feedRevision) return;
		this.feeds = this.store.feeds; this.feedRevision = this.store.feedRevision;
		const picker = this.node('settings-feed-picker'); const selected = picker.value;
		picker.replaceChildren();
		for (const feed of this.store.feeds) { const option = this.element('option', '', feed.name); option.value = feed.id; picker.append(option); }
		if (this.store.feeds.some(feed => String(feed.id) === selected)) picker.value = selected;
		picker.disabled = !this.store.feeds.length; this.node('settings-edit-feed').disabled = !this.store.feeds.length;
	}

	async loadStorage() {
		const epoch = ++this.storageEpoch; const status = this.node('settings-storage-status'); const button = this.node('settings-refresh-storage');
		status.textContent = this.t('loading'); button.disabled = true;
		try {
			const data = await this.api.request('storage'); if (epoch !== this.storageEpoch) return;
			const storage = data.storage || data;
			const stats = this.node('settings-storage-stats'); stats.replaceChildren();
			const bytes = value => value === null
				? '—'
				: new Intl.NumberFormat(this.bootstrap.language, { maximumFractionDigits: 1 }).format(value / (1024 * 1024)) + ' MiB';
			const count = value => Number.isFinite(value) ? value.toLocaleString(this.bootstrap.language) : '—';
			for (const [label, value] of [
				[this.text('数据库', 'Database'), bytes(storage.database.bytes)],
				[this.text('文章', 'Articles'), count(storage.articles.total)],
				[this.text('未读', 'Unread'), count(storage.articles.unread)],
				[this.text('收藏', 'Starred'), count(storage.articles.favourites)],
			]) {
				const item = this.element('div', 'settings-storage-stat');
				item.append(this.element('strong', '', value), this.element('span', '', label));
				stats.append(item);
			}
			const time = new Intl.DateTimeFormat(this.bootstrap.language, { hour: '2-digit', minute: '2-digit' }).format(new Date(storage.timestamp * 1000));
			status.textContent = this.text('服务器统计 · 更新于 ', 'Server statistics · Updated at ') + time;
			let backups = this.node('settings-backup-summary');
			if (!backups) { backups = this.element('p', 'settings-note'); backups.id = 'settings-backup-summary'; status.after(backups); }
			const archives = storage.backups?.archives?.filter(archive => !archive.active) || [];
			backups.textContent = archives.length
				? this.text(`SQLite 备份 ${archives.length} 份 · `, `${archives.length} SQLite backups · `) + bytes(storage.backups.bytes)
				: this.text('当前账户没有 SQLite 备份归档。', 'No SQLite backup archives for this account.');
			this.storageLoaded = true;
		} catch (error) { status.textContent = this.t('failed'); this.onError(error); } finally { if (epoch === this.storageEpoch) button.disabled = false; }
	}

	search() {
		const query = this.node('settings-search').value.trim().toLocaleLowerCase();
		const results = this.node('settings-search-results'); results.replaceChildren(); results.hidden = !query;
		this.node('settings-panels').hidden = Boolean(query);
		if (!query) return;
		if (!this.node('settings-embedded').hidden) this.closeTool();
		this.node('settings-panels').hidden = true;
		for (const section of this.sections) {
			const panel = this.root.querySelector(`[data-settings-panel="${section.id}"]`);
			const matches = (section.title + ' ' + section.description + ' ' + panel.textContent).toLocaleLowerCase().includes(query);
			if (!matches) continue;
			const button = this.element('button', 'settings-search-result'); button.type = 'button'; button.dataset.settingsResult = section.id;
			button.append(this.element('strong', '', section.title), this.element('small', '', section.description)); results.append(button);
			for (const field of panel.querySelectorAll('[data-preference]')) {
				if (!field.textContent.toLocaleLowerCase().includes(query)) continue;
				const result = this.element('button', 'settings-search-result settings-search-field', field.querySelector('span')?.textContent || field.textContent);
				result.type = 'button';
				result.dataset.settingsResult = section.id;
				result.dataset.focusPreference = field.dataset.preference;
				results.append(result);
			}
			for (const field of panel.querySelectorAll('[data-account-field]')) {
				if (!field.textContent.toLocaleLowerCase().includes(query)) continue;
				const result = this.element('button', 'settings-search-result settings-search-field',
					field.querySelector('label')?.textContent || field.textContent);
				result.type = 'button'; result.dataset.settingsResult = section.id; result.dataset.focusAccount = field.dataset.accountField; results.append(result);
			}
		}
		if (!results.childNodes.length) results.append(this.element('p', 'settings-note', this.text('没有找到相关设置。', 'No matching settings.')));
	}

	dispose() { this.storageEpoch++; this.account.dispose(); this.tools.dispose(); this.backups.dispose(); window.removeEventListener('message', this.message); }
}
