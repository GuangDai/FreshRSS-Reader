import { ReaderAPI } from './core/api.js';
import { ReaderStore } from './core/store.js';
import { ReadingPreloader } from './core/preloading.js';
import { articleDocument, safeLink } from './articles/document.js';
import { defaults, preferences, preferenceErrors } from './core/preferences.js';
import { translator } from './core/i18n.js';
import { FeedTree } from './subscriptions/feed-tree.js';
import { FeedSelection } from './subscriptions/batch-update.js';
import { FeedEditor } from './subscriptions/feed-editor.js';
import { ReaderNavigation } from './core/navigation.js';
import { resolveTheme } from './appearance/themes.js';
import { appearanceColours } from './appearance/colours.js';
import { AppearanceChoices } from './appearance/choices.js';
import { SettingsPage } from './settings/page.js';
import { SettingsSubscriptions } from './settings/subscriptions.js';

const bootstrap = JSON.parse(document.getElementById('reader-bootstrap').textContent);
bootstrap.lang = bootstrap.language;
let schema = bootstrap.schema;
const t = translator(bootstrap.language);
let saved = preferences(bootstrap.preferences, schema);
let current = { ...saved };
const bytesPerMiB = 1024 * 1024;
const api = new ReaderAPI(bootstrap.csrf, { timeoutSeconds: saved.requestTimeout });
const store = new ReaderStore(api, { pageSize: saved.pageSize, cacheArticles: saved.cacheArticles, cacheBytes: saved.cacheMiB * bytesPerMiB });
const $ = id => document.getElementById(id);
const form = $('appearance-form');
const settingsRoot = $('settings-page');
const settingsPage = new SettingsPage({
	root: settingsRoot,
	api,
	store,
	bootstrap,
	element,
	t,
	onSection: section => { navigation.setSettingsSection(section); settingsPage.activate(section, true); },
	onDirty: () => syncSettingsSave(),
	onAccountSaved: values => {
		bootstrap.autoMarkArticle = Boolean(values['mark_when.article']); bootstrap.autoMarkSite = Boolean(values['mark_when.site']);
	},
	onError: error => { if (error?.status === 403) { store.reportError(error); renderStatus(); } },
});
settingsPage.mount();
const media = matchMedia('(prefers-color-scheme: dark)');
const mobile = matchMedia('(max-width: 820px), (max-height: 500px) and (max-width: 1100px) and (any-pointer: coarse)');
const forcedColours = matchMedia('(forced-colors: active)');
const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
const appearanceChoices = new AppearanceChoices({ element, t, language: bootstrap.language, systemDark: () => media.matches });
const feedTree = new FeedTree($('feed-tree'), element, {
	errorLabel: t('feedError'),
	locale: bootstrap.language,
	expandLabel: t('expandCategory'),
	collapseLabel: t('collapseCategory'),
	allLabel: t('categoryAll'),
	unreadLabel: t('categoryUnread'),
});
const dateFormatter = new Intl.DateTimeFormat(bootstrap.language, { month: 'short', day: 'numeric' });
const articleDateFormatter = new Intl.DateTimeFormat(bootstrap.language, { dateStyle: 'medium', timeStyle: 'short' });
let renderedItems = null;
let renderedListFeeds = null;
let renderedSelection = null;
let renderedPending = new Set();
const entryNodes = new Map();
let frameKey = '';
let appearancePalette = null;
let refreshTimer = 0;
let pageActive = true;
let clearedSession = -1;
let articleNavigationQueue = 0;
let articleNavigationPaging = false;
let articleNavigationEpoch = 0;
let notice = bootstrap.adjustments?.length ? t('adjusted') + bootstrap.adjustments.map(t).join(', ') : '';
let appearanceEpoch = 0;
let appearanceDraftRevision = 0;
let readerDraft = null;
let settingsBuilt = false;
let appearanceFrame = 0;
let searchTimer = 0;
let searchComposing = false;
let autoReadSelection = null;
const feedSelection = new FeedSelection();
feedSelection.activate(true);
const settingsSubscriptions = new SettingsSubscriptions({
	root: settingsRoot,
	store,
	selection: feedSelection,
	element,
	t,
	language: bootstrap.language,
	onOpenFeed: id => settingsPage.openFeed(id),
	onStatus: () => renderFeedSelection(),
});
let reconciledFeeds = null;
let selectionViewKey = '';
const feedEditor = new FeedEditor({
	api,
	store,
	selection: feedSelection,
	schema: bootstrap.subscription || { fields: {} },
	limits: bootstrap.apiLimits,
	t,
	element,
	pageSize: () => saved.pageSize,
	onStatus: message => { notice = message; renderStatus(); renderFeedSelection(); },
});
let navigationEpoch = 0;
let restoringNavigation = false;
let centeringFrame = 0;
let centeredSelection = null;
let followSelection = true;
let navigationStorage = null;
try { navigationStorage = window.sessionStorage; } catch {}
const navigation = new ReaderNavigation({
	history: window.history,
	location: window.location,
	onRestore: restoreNavigation,
	storage: navigationStorage,
	storageKey: `freshrssReader:${location.pathname}:${JSON.stringify([Boolean(bootstrap.readOnly), bootstrap.username || ''])}`,
	restoreStorage: performance.getEntriesByType('navigation')[0]?.type,
});
const preloader = new ReadingPreloader(store, {
	active: () => pageActive && !restoringNavigation && settingsRoot.hidden && document.visibilityState !== 'hidden' &&
		navigator.onLine !== false && store.error?.status !== 403,
	connection: navigator.connection,
	onMore: () => loadMoreArticles(true),
});

function sameList(value) {
	return store.cursor === value.cursor && ['get', 'filter', 'search', 'state'].every(key => store.query[key] === value.query[key]);
}

function navigationSnapshot() {
	let listScroll = $('article-list').scrollTop;
	if (store.selectedId && (mobile.matches || current.layout !== 'split') && navigation.current) {
		listScroll = sameList(navigation.current.snapshot) ? navigation.current.snapshot.listScroll : 0;
	}
	return {
		query: { ...store.query },
		cursor: store.cursor,
		history: [...store.history],
		selectedId: store.selectedId,
		listScroll,
		loadedCount: store.items.length
	};
}

function restoreListScroll(value) { $('article-list').scrollTop = value; }

async function restoreNavigation(value, panel, load = false) {
	clearTimeout(searchTimer);
	const epoch = ++navigationEpoch;
	preloader.suspend(); restoringNavigation = true;
	cancelArticleNavigation();
	const previousId = store.selectedId;
	const previousSidebar = document.body.dataset.sidebarOpen === 'true';
	const list = load || !sameList(value) ? store.list(value.query, value.cursor) : Promise.resolve(true);
	store.history = [...value.history];
	$('article-search').elements.search.value = value.query.search;
	if (!value.selectedId) {
		if (store.selectedId) store.closeArticle();
	} else if (store.selectedId !== value.selectedId || (load && !store.article && !store.loadingArticle)) store.open(value.selectedId);
	const showSettings = panel === 'settings' || (panel === 'feed-settings' && navigation.current?.panels.includes('settings'));
	if (!settingsRoot.hidden && !showSettings) {
		readerDraft = settingsPage.readerDirty ? formValues() : null;
		appearanceEpoch++; closeSettingsPage();
	}
	if (feedEditor.dialog.open && panel !== 'feed-settings') feedEditor.dialog.close();
	if (previousSidebar !== (panel === 'sidebar')) sidebar(panel === 'sidebar', false);
	if (showSettings) {
		if (settingsRoot.hidden) {
			ensureSettingsBuilt();
			appearanceEpoch++; fillSettings(readerDraft || saved); validateSettings(readerDraft || saved); showSettingsPage();
		}
		settingsPage.activate(navigation.settingsSection);
	}
	if (panel === 'feed-settings' && !feedEditor.dialog.open) {
		feedEditor.dialog.showModal(); feedEditor.render();
	} else if (!panel && value.selectedId) $('close-article').focus({ preventScroll: true });
	else if (!panel && previousId && !previousSidebar) (entryNodes.get(previousId)?.button || $('article-list')).focus({ preventScroll: true });
	restoreListScroll(value.listScroll);
	const loaded = await list;
	if (epoch !== navigationEpoch) return false;
	if (loaded) await store.restoreRange(value.loadedCount || 0);
	if (epoch !== navigationEpoch) return false;
	restoringNavigation = false;
	if (loaded) {
		restoreListScroll(value.listScroll); render();
		if (load && value.selectedId) centerSelection();
		const anchor = previousId || value.listAnchor;
		if (!value.selectedId && anchor && !panel) {
			const item = entryNodes.get(anchor)?.button.getBoundingClientRect();
			const bounds = $('article-list').getBoundingClientRect();
			if (item && (item.top < bounds.top || item.bottom > bounds.bottom)) centerListItem(anchor, 'instant');
		}
	}
	return loaded;
}

async function queryList(query) {
	clearTimeout(searchTimer);
	const epoch = ++navigationEpoch;
	restoringNavigation = false; preloader.suspend();
	cancelArticleNavigation();
	const request = store.list(query);
	restoreListScroll(0);
	navigation.replace({ ...navigationSnapshot(), listScroll: 0 });
	const loaded = await request;
	if (epoch === navigationEpoch) navigation.replace(navigationSnapshot());
	return loaded;
}

function element(tag, className, text) {
	const node = document.createElement(tag);
	if (className) node.className = className;
	if (text !== undefined) node.textContent = text;
	return node;
}

function localise(root = document) {
	root.querySelectorAll('[data-i18n]').forEach(node => { node.textContent = t(node.dataset.i18n); });
	root.querySelectorAll('[data-title]').forEach(node => { node.title = t(node.dataset.title); node.setAttribute('aria-label', t(node.dataset.title)); });
	root.querySelectorAll('[data-placeholder]').forEach(node => {
		node.placeholder = t(node.dataset.placeholder);
		node.setAttribute('aria-label', t(node.dataset.placeholder));
	});
}

function ensureSettingsBuilt() {
	if (settingsBuilt) return;
	buildSettings(); settingsSubscriptions.mount(); settingsPage.updateFeeds(); localise(settingsRoot);
}

function buildSettings() {
	settingsBuilt = true;
	settingsRoot.querySelectorAll('[data-reader-preferences]').forEach(node => node.replaceChildren());
	$('server-limits').replaceChildren();
	const groups = new Map();
	const appearanceGroups = {
		theme: ['theme', 'lightTheme', 'darkTheme'],
		list: ['layout', 'density', 'style'],
		sizes: ['sidebarWidth', 'listWidth'],
	};
	const order = Object.values(appearanceGroups).flat();
	const fields = Object.entries(schema).sort(([a], [b]) => {
		const rank = key => order.includes(key) ? order.indexOf(key) : order.length;
		return rank(a) - rank(b);
	});
	for (const [key, spec] of fields) {
		const visual = ['theme', 'lightTheme', 'darkTheme', 'layout', 'density', 'style'].includes(key);
		const label = visual
			? appearanceChoices.create(key, spec)
			: element('label', spec.type === 'boolean' ? 'checkbox-setting' : spec.type === 'integer' ? 'range-setting' : '');
		label.dataset.preference = key;
		const heading = element('span', '', t(key));
		let input;
		if (visual) {
			// Visual choices contain their own labelled native radio controls.
		} else if (spec.type === 'enum') {
			input = element('select');
			for (const option of spec.options) {
				const node = element('option', '', t(option)); node.value = option; input.append(node);
			}
		} else {
			input = element('input');
			input.type = spec.type === 'boolean' ? 'checkbox' : 'number';
			if (spec.type === 'integer') {
				input.min = spec.min; input.max = spec.max; input.step = 1;
				heading.append(element('small', 'setting-help', t(key + 'Help')));
			}
		}
		if (!visual) {
			input.name = key;
			input.setAttribute('form', 'appearance-form');
			input.required = spec.type !== 'boolean';
			input.setAttribute('aria-describedby', 'settings-status');
			label.append(heading);
			if (spec.type === 'integer') {
				const control = element('span', 'setting-number-control');
				const unit = element('span', 'setting-unit', t(spec.unit)); unit.setAttribute('aria-hidden', 'true');
				input.setAttribute('aria-label', `${t(key)} (${t(spec.unit)})`); control.append(input, unit); label.append(control);
			} else label.append(input);
		}
		const appearanceGroup = Object.keys(appearanceGroups).find(group => appearanceGroups[group].includes(key));
		const section = appearanceGroup
			? 'appearance'
			: ['cacheArticles', 'cacheMiB'].includes(key)
				? 'storage'
				: key === 'pageSize'
					? 'reading'
					: ['requestTimeout', 'refreshInterval'].includes(key) ? 'automation' : spec.group;
		const groupKey = appearanceGroup || section;
		if (!groups.has(groupKey)) {
			const group = element('fieldset', 'settings-grid');
			const title = appearanceGroup
				? { theme: 'appearanceTheme', list: 'appearanceList', sizes: 'appearanceSizes' }[appearanceGroup]
				: section === 'storage' ? 'articleCache' : section === 'automation' ? 'connectionSync' : spec.group;
			groups.set(groupKey, group);
			const panel = settingsRoot.querySelector(`[data-reader-preferences="${section}"]`);
			if (appearanceGroup === 'sizes') {
				const details = element('details', 'advanced-settings'); details.append(element('summary', '', t(title)), group);
				group.setAttribute('aria-label', t(title)); panel.append(details);
			} else {
				if (appearanceGroup === 'theme') group.setAttribute('aria-label', t(title));
				else group.append(element('legend', '', t(title)));
				panel.append(group);
			}
		}
		groups.get(groupKey).append(label);
	}
	groups.get('theme')?.after(appearanceChoices.createPreview());
	for (const section of ['storage', 'automation']) {
		groups.get(section)?.after(element('p', 'settings-note', t(section === 'storage' ? 'cacheNote' : 'updateNote')));
	}
	for (const [name, value] of Object.entries(bootstrap.limits)) {
		const term = element('dt', '', t(name));
		const detail = element('dd', '', bootstrap.canManageLimits ? '' : value);
		if (bootstrap.canManageLimits) {
			const input = element('input'); input.type = 'number'; input.name = name; input.value = value;
			input.required = true;
			input.min = name === 'max_page_size' ? 1 : 0; input.step = 1;
			input.setAttribute('form', 'reader-limit-form'); input.setAttribute('aria-label', t(name));
			detail.append(input);
		}
		$('server-limits').append(term, detail);
	}
	$('save-server-limits').hidden = !bootstrap.canManageLimits;
	fillSettings(saved);
}

function fillSettings(values) {
	for (const [key, spec] of Object.entries(schema)) {
		for (const input of preferenceInputs(key)) {
			if (input.type === 'radio') input.checked = input.value === values[key];
			else if (spec.type === 'boolean') input.checked = values[key];
			else input.value = values[key];
		}
	}
	syncThemeSettings(values);
	syncSettingsSave(values);
}

function syncSettingsSave(values = formValues()) {
	if (form.getAttribute('aria-busy') === 'true') return;
	const unchanged = Object.keys(schema).every(key => values[key] === saved[key]);
	$('save-reader-settings').disabled = store.csrfInvalid || (unchanged && !settingsPage.hasNativeDraft() && !preferenceErrors(values, schema).length);
	settingsPage.syncSavebar(!unchanged);
}

function syncThemeSettings(values) {
	if (!settingsBuilt) return;
	for (const key of ['lightTheme', 'darkTheme']) {
		const label = settingsRoot.querySelector(`[data-preference="${key}"]`);
		if (label) label.hidden = values.theme !== 'auto';
	}
	appearanceChoices.sync(values, saved);
}

function formValues() {
	if (!settingsBuilt) return { ...(readerDraft || saved) };
	return Object.fromEntries(Object.entries(schema).map(([key, spec]) => {
		const inputs = preferenceInputs(key); const input = inputs.find(input => input.checked) || inputs[0];
		return [key, spec.type === 'boolean' ? input.checked : spec.type === 'integer' ? input.valueAsNumber : input.value];
	}));
}

function validateSettings(values, focus = false) {
	const errors = preferenceErrors(values, schema);
	for (const key of Object.keys(schema)) {
		for (const input of preferenceInputs(key)) input.setAttribute('aria-invalid', String(errors.includes(key)));
	}
	if (errors.length) {
		notice = t('invalidSettings') + errors.map(t).join(', ');
		if (focus) {
			const inputs = preferenceInputs(errors[0]);
			const input = inputs.find(node => node.checked) || inputs[0];
			const section = input?.closest('[data-settings-panel]')?.dataset.settingsPanel;
			if (section) { navigation.setSettingsSection(section); settingsPage.activate(section); }
			for (let parent = input?.parentElement; parent && parent !== form; parent = parent.parentElement) {
				if (parent.tagName === 'DETAILS') parent.open = true;
				if (parent.hidden) parent.hidden = false;
			}
			input?.focus();
		}
	} else notice = '';
	renderStatus();
	syncSettingsSave(values);
	return !errors.length;
}

function preferenceInputs(key) {
	// Query the controls directly: WebKit can delay association with an external form after insertion.
	return [...settingsRoot.querySelectorAll(`[form="appearance-form"][name="${CSS.escape(key)}"]`)];
}

function activeTheme() { return resolveTheme(current, media.matches); }

function applyAppearance(values) {
	current = values;
	// Change the complete palette together; hover transitions must not blend unrelated themes.
	cancelAnimationFrame(appearanceFrame);
	document.body.classList.add('appearance-changing');
	for (const key of ['layout', 'style', 'density']) document.body.dataset[key] = values[key];
	const theme = activeTheme();
	document.body.dataset.theme = theme.name;
	document.body.dataset.colourScheme = theme.dark ? 'dark' : 'light';
	for (const key of ['--accent', '--muted', '--on-accent', '--error']) document.body.style.removeProperty(key);
	if (!forcedColours.matches) {
		for (const [key, value] of Object.entries(appearanceColours(document.body))) document.body.style.setProperty(key, value);
		if (values.style === 'contrast') document.body.style.setProperty('--muted', getComputedStyle(document.body).getPropertyValue('--text'));
	}
	for (const [key, css] of Object.entries({
		sidebarWidth: '--sidebar-width',
		listWidth: '--list-width',
		readingWidth: '--reading-width',
		fontSize: '--reader-font-size'
	})) {
		document.documentElement.style.setProperty(css, values[key] + 'px');
	}
	document.documentElement.style.setProperty('--reader-line-height', values.lineHeight / 100);
	syncQuickLayout();
	appearancePalette = articlePalette();
	settingsPage.syncToolAppearance();
	renderArticle();
	syncFocusRegions();
	centerSelection(); measureList();
	appearanceFrame = requestAnimationFrame(() => {
		appearanceFrame = requestAnimationFrame(() => document.body.classList.remove('appearance-changing'));
	});
}

function quickLayout(value) { return mobile.matches && value === 'split' ? 'list' : value; }

function syncQuickLayout() {
	const input = $('quick-layout');
	const split = input.querySelector('[value=split]');
	split.hidden = mobile.matches; split.disabled = mobile.matches;
	input.value = quickLayout(current.layout);
}

function renderFeeds() {
	if (store.error?.status === 403 && !['administrator_required', 'csrf_invalid'].includes(store.error.message) && clearedSession !== store.sessionEpoch) {
		clearedSession = store.sessionEpoch; feedEditor.suspend(true); feedSelection.activate(false);
	}
	feedTree.update(store.feeds, store.categories, store.feedRevision);
	settingsSubscriptions.update();
	if (settingsBuilt) settingsPage.updateFeeds();
	feedTree.select(store.query.get, store.query.filter);
	if (reconciledFeeds !== store.feeds) { reconciledFeeds = store.feeds; feedSelection.reconcile(store.feeds); }
	renderFeedSelection();
	const unread = feedTree.unread.toLocaleString(bootstrap.language);
	if ($('total-unread').textContent !== unread) $('total-unread').textContent = unread;
	document.querySelectorAll('[data-filter]').forEach(button => {
		const selected = button.dataset.filter === store.query.filter ? 'page' : 'false';
		if (button.getAttribute('aria-current') !== selected) button.setAttribute('aria-current', selected);
	});
	$('all-subscriptions').setAttribute('aria-current', store.query.get === 'a' ? 'page' : 'false');
}

function filterFeeds() {
	feedTree.filter($('feed-search').value);
}

function renderFeedSelection() {
	const key = [feedSelection.revision, store.feedRevision, settingsSubscriptions.matchingIds().join(','),
		navigator.onLine, feedEditor.batch.running, store.csrfInvalid].join(':');
	if (key === selectionViewKey) return;
	selectionViewKey = key;
	$('feed-selection-count').textContent = t('selectedFeedCount').replace('{count}', feedSelection.ids.size);
	$('select-visible-feeds').disabled = !settingsSubscriptions.matchingIds().length;
	$('clear-feed-selection').disabled = !feedSelection.ids.size;
	$('edit-selected-feeds').disabled = store.csrfInvalid || (!feedEditor.batch.running && (!feedSelection.ids.size || navigator.onLine === false));
}

function renderList() {
	$('article-list').setAttribute('aria-busy', String(store.loading));
	const unread = store.items.some(item => !item.read);
	$('mark-page-read').hidden = Boolean(bootstrap.readOnly);
	$('previous-page').disabled = store.loading || $('article-list').scrollTop < 1;
	$('next-page').disabled = store.loading || store.loadingMore || !store.next;
	$('next-page').setAttribute('aria-busy', String(store.loadingMore));
	$('next-page').textContent = t(store.loadingMore ? 'loadingMore' : store.moreError ? 'retryMore' : store.next ? 'loadMore' : 'endOfList');
	$('mark-page-read').title = t('markPageRead').replace('{count}', store.items.filter(item => !item.read).length);
	$('mark-page-read').setAttribute('aria-label', $('mark-page-read').title);
	$('mark-page-read').disabled = store.csrfInvalid || store.loading || store.pending.size > 0 ||
		navigator.onLine === false || !unread;
	const scopeId = Number(store.query.get.slice(2));
	const feed = store.query.get.startsWith('f_') ? feedTree.feedsById.get(scopeId) : null;
	const category = store.query.get.startsWith('c_') ? store.categories.find(category => category.id === scopeId) : null;
	let summary = store.loading
		? t('loading')
		: t('listSummary').replace('{count}', String(store.items.length));
	if (store.loadingMore) summary += ' · ' + t('loadingMore');
	else if (store.moreError) summary += ' · ' + t('retryMore');
	if (category) {
		summary += ' · ' + t('categoryUnreadSummary').replace('{count}',
			(feedTree.groups.get(category.id)?.unread ?? 0).toLocaleString(bootstrap.language));
	}
	$('page-summary').textContent = summary;
	$('page-summary').title = summary;
	const scope = feed || category;
	const title = scope ? scope.name + (store.query.filter === 'all' ? '' : ' · ' + t(store.query.filter)) : t(store.query.filter);
	$('view-title').textContent = title; $('view-title').title = title;
	const itemsChanged = renderedItems !== store.items;
	if (renderedItems !== store.items) {
		renderedItems = store.items;
		const ids = new Set(store.items.map(item => item.id));
		for (const [id, node] of entryNodes) {
			if (!ids.has(id)) { node.button.remove(); entryNodes.delete(id); }
		}
		$('article-list').querySelector('.empty-state')?.remove();
		for (const [index, item] of store.items.entries()) {
			let node = entryNodes.get(item.id);
			if (!node) {
				const button = element('button', 'entry-card'); button.type = 'button'; button.dataset.id = item.id;
				const meta = element('span', 'entry-meta');
				const feedName = element('span', '', feedTree.feedsById.get(item.feedId)?.name || '');
				const date = new Date(item.date * 1000);
				const validDate = !Number.isNaN(date.getTime());
				const time = element('time', '', validDate
					? dateFormatter.format(date)
					: '');
				if (validDate) time.dateTime = date.toISOString();
				const author = element('span', 'entry-author');
				const state = element('span', 'entry-state reader-icon icon-starred'); state.setAttribute('aria-hidden', 'true');
				meta.append(state, feedName, author, time);
				button.append(element('h2', '', item.title), meta);
				node = { button, state, feedName, author }; entryNodes.set(item.id, node);
			}
			node.item = item;
			if ($('article-list').children[index] !== node.button) {
				$('article-list').insertBefore(node.button, $('article-list').children[index] || null);
			}
		}
		if (!store.items.length) $('article-list').append(element('p', 'empty-state', store.loading ? t('loading') : t('empty')));
	}
	const empty = $('article-list').querySelector('.empty-state');
	if (empty) {
		empty.replaceChildren(element('span', '', store.loading ? t('loading') : t(store.query.search ? 'searchEmpty' : 'empty')));
		if (!store.loading && store.query.search) {
			const clear = element('button', '', t('clearSearch')); clear.type = 'button'; clear.dataset.clearSearch = 'true'; empty.append(clear);
		}
	}
	// State and article loads normally touch only the active and pending rows, even in a long reading session.
	const metadataChanged = itemsChanged || renderedListFeeds !== store.feeds;
	const rows = metadataChanged
		? store.items
		: [...new Set([store.selectedId, renderedSelection, ...store.pending, ...renderedPending])].map(id => entryNodes.get(id)?.item).filter(Boolean);
	renderedListFeeds = store.feeds; renderedSelection = store.selectedId; renderedPending = new Set(store.pending);
	for (const item of rows) {
		const node = entryNodes.get(item.id);
		const key = [item.read, item.starred, item.id === store.selectedId, store.pending.has(item.id), item.title].join(':');
		if (key !== node.stateKey) {
			node.stateKey = key;
			for (const [key, value] of Object.entries({
				'data-read': String(item.read),
				'aria-current': String(item.id === store.selectedId),
				'aria-busy': String(store.pending.has(item.id)),
				'aria-label': `${item.title} · ${t(item.read ? 'readState' : 'unread')}${item.starred ? ' · ' + t('starred') : ''}`,
			})) if (node.button.getAttribute(key) !== value) node.button.setAttribute(key, value);
			node.state.hidden = !item.starred;
		}
		if (!metadataChanged) continue;
		const name = feedTree.feedsById.get(item.feedId)?.name || '';
		if (node.feedName.textContent !== name) node.feedName.textContent = name;
		const author = item.author?.trim();
		node.author.hidden = !author || author === name.trim();
		if (node.author.textContent !== author) node.author.textContent = author || '';
	}
	if (centeredSelection !== store.selectedId) {
		followSelection = true; centeredSelection = store.selectedId; centerSelection();
	} else if (itemsChanged && followSelection && !restoringNavigation) centerSelection();
	if (itemsChanged) measureList();
}

function centerSelection() {
	cancelAnimationFrame(centeringFrame);
	if (!store.selectedId || mobile.matches || current.layout !== 'split' || !settingsRoot.hidden || restoringNavigation) return;
	centeringFrame = requestAnimationFrame(() => {
		if (followSelection) centerListItem(store.selectedId, reducedMotion.matches ? 'instant' : 'smooth');
	});
}

function centerListItem(id, behavior) {
	const button = entryNodes.get(id)?.button;
	if (!button) return;
	const list = $('article-list'); const bounds = list.getBoundingClientRect(); const item = button.getBoundingClientRect();
	const top = list.scrollTop + item.top - bounds.top - (list.clientHeight - item.height) / 2;
	list.scrollTo({ top: Math.max(0, top), behavior });
}

function measureList(intent = false, velocity = 0) {
	const list = $('article-list'); const first = list.querySelector('.entry-card');
	const bounds = first?.getBoundingClientRect();
	const columns = bounds?.width ? Math.max(1, Math.floor(list.clientWidth / bounds.width)) : 1;
	const rows = list.clientHeight / (bounds?.height || 90) * columns;
	preloader.viewport(rows, list.scrollHeight - list.scrollTop - list.clientHeight, velocity, intent);
}

async function loadMoreArticles(background = false) {
	const epoch = store.listEpoch;
	const loaded = await store.loadMore({ background });
	if (loaded && epoch === store.listEpoch) navigation.replace(navigationSnapshot());
	return loaded;
}

function renderArticle() {
	const article = store.article;
	const active = article || store.items.find(item => item.id === store.selectedId);
	$('article-pane').setAttribute('aria-busy', String(store.loadingArticle));
	document.body.dataset.articleOpen = String(Boolean(store.selectedId));
	$('article-empty').hidden = Boolean(article);
	$('article-empty').querySelector('p').textContent = store.loadingArticle ? t('loading') : t('choose');
	$('article-actions').hidden = !store.selectedId;
	$('original-link').hidden = !article;
	const index = store.items.findIndex(item => item.id === store.selectedId);
	$('previous-article').disabled = !store.selectedId || (!articleNavigationPaging && store.loading) || (index === 0 && !store.history.length);
	$('next-article').disabled = !store.selectedId || (!articleNavigationPaging && store.loading) || (index === store.items.length - 1 && !store.next);
	document.querySelector('.article-navigation').setAttribute('aria-busy', String(articleNavigationPaging));
	for (const [id, label, short, pressed] of [
		['mark-read', t(active?.read ? 'unreadAction' : 'read'), t(active?.read ? 'unreadShort' : 'readShort'), active?.read],
		['mark-star', t(active?.starred ? 'unstar' : 'star'), t(active?.starred ? 'unstarShort' : 'starShort'), active?.starred],
	]) {
		$(id).querySelector('.action-label').textContent = short;
		$(id).setAttribute('aria-label', label); $(id).title = label;
		$(id).disabled = store.csrfInvalid || !active || store.pending.has(active.id) || navigator.onLine === false;
		$(id).setAttribute('aria-pressed', String(Boolean(pressed)));
	}
	$('mark-star').querySelector('[aria-hidden]').className = `reader-icon ${active?.starred ? 'icon-starred' : 'icon-star'}`;
	$('article-frame').hidden = !article;
	if (!article) {
		if (frameKey) {
			const frame = $('article-frame').cloneNode(false); frame.removeAttribute('srcdoc'); $('article-frame').replaceWith(frame); frameKey = '';
		}
		return;
	}
	const link = safeLink(article.link);
	$('original-link').hidden = !link;
	if (link) $('original-link').href = link;
	const date = typeof article.date === 'number' ? new Date(article.date * 1000) : null;
	const metadata = [...new Set([
		feedTree.feedsById.get(article.feedId)?.name?.trim(),
		date && !Number.isNaN(date.getTime()) ? articleDateFormatter.format(date) : '',
		article.author?.trim(),
	].filter(Boolean))].join(' · ');
	const palette = appearancePalette;
	const key = JSON.stringify([article.id, current.style, current.fontSize, current.lineHeight,
		current.readingWidth, palette, forcedColours.matches, current.remoteImages, metadata]);
	if (key !== frameKey) {
		frameKey = key;
		// A new browsing context avoids accumulating srcdoc navigations in the browser's Back history.
		const frame = $('article-frame').cloneNode(false);
		frame.title = article.title;
		frame.srcdoc = articleDocument(article, current, palette, current.remoteImages, {
			media: t('openMedia'), image: t('imagesOff'), imagePrivacy: t('imagesPrivacyBlocked'), byline: metadata,
		});
		$('article-frame').replaceWith(frame);
	}
}

function articlePalette() {
	const palette = { dark: activeTheme().dark, radius: getComputedStyle(document.body).getPropertyValue('--radius-box').trim() };
	if (forcedColours.matches) {
		return { ...palette, surface: 'Canvas', text: 'CanvasText', muted: 'CanvasText', accent: 'LinkText', subtle: 'Canvas', line: 'ButtonText' };
	}
	const probe = element('span', 'sr-only'); probe.setAttribute('aria-hidden', 'true'); document.body.append(probe);
	for (const key of ['surface', 'text', 'muted', 'accent', 'subtle', 'line']) {
		probe.style.color = `var(--${key})`;
		palette[key] = getComputedStyle(probe).color;
	}
	probe.remove();
	return palette;
}

function renderStatus() {
	const error = store.error;
	const status = $('status');
	status.hidden = !store.csrfInvalid && !error && !notice && !store.batchProgress && navigator.onLine !== false;
	status.dataset.error = String(store.csrfInvalid || Boolean(error));
	status.replaceChildren();
	if (store.csrfInvalid) {
		status.append(document.createTextNode(t('csrfExpired')));
		const reload = element('button', '', t('reload'));
		reload.type = 'button'; reload.dataset.reloadReader = 'true'; status.append(reload);
	} else if (error) {
		let message = t('failed');
		if (error.message === 'administrator_required') message = t('adminRequired');
		else if (error.status === 403) message = t('auth');
		else if (error.status === 408) message = t('timeout');
		else if (error.fields?.length) message = t('invalidSettings') + error.fields.map(t).join(', ');
		status.append(document.createTextNode(message));
		if (error.status !== 403) {
			const retry = element('button', '', t('retry'));
			retry.type = 'button'; retry.dataset.retryReader = 'true'; status.append(retry);
		}
	} else if (navigator.onLine === false) status.textContent = t('offline');
	else if (store.batchProgress) {
		status.textContent = t('batchProgress').replace('{completed}', store.batchProgress.completed).replace('{total}', store.batchProgress.total);
	} else status.textContent = notice;
	$('article-status').replaceChildren(...[...status.childNodes].map(node => node.cloneNode(true)));
	$('article-status').hidden = status.hidden || !store.selectedId;
	$('settings-status').replaceChildren(...[...status.childNodes].map(node => node.cloneNode(true)));
	$('settings-status').hidden = status.hidden;
	$('settings-status').dataset.error = status.dataset.error;
	settingsPage.syncSavebar();
	if (store.csrfInvalid) {
		$('save-reader-settings').disabled = true;
		$('save-server-limits').disabled = true;
		$('quick-layout').disabled = true;
	}
	if (bootstrap.readOnly) {
		for (const id of ['mark-read', 'mark-star', 'mark-page-read', 'quick-layout', 'open-settings']) {
			$(id).hidden = true; $(id).disabled = true;
		}
	}
}

function render() {
	renderFeeds(); renderList(); renderArticle(); renderStatus(); syncFocusRegions();
	preloader.update();
	if (autoReadSelection !== store.selectedId) autoReadSelection = null;
	const article = store.article;
	if (!bootstrap.autoMarkArticle || !article || article.id !== store.selectedId || autoReadSelection === article.id) return;
	if (article.read) { autoReadSelection = article.id; return; }
	if (store.csrfInvalid || store.pending.has(article.id) || navigator.onLine === false) return;
	autoReadSelection = article.id;
	store.changeState(article.id, 'read', true);
}

function syncFocusRegions() {
	document.querySelector('.reader-shell').inert = !settingsRoot.hidden;
	const navigationOpen = mobile.matches && document.body.dataset.sidebarOpen === 'true';
	const articleOverlay = Boolean(store.selectedId) && (mobile.matches || current.layout !== 'split');
	$('reader-sidebar').inert = mobile.matches && !navigationOpen;
	document.querySelector('.workspace').inert = navigationOpen;
	document.querySelector('.toolbar').inert = articleOverlay;
	document.querySelector('.article-list-pane').inert = articleOverlay;
	$('status').inert = articleOverlay;
}

function sidebar(open, record = true) {
	const changed = (document.body.dataset.sidebarOpen === 'true') !== open;
	if (changed && record && mobile.matches && navigation.current) {
		if (open) navigation.openPanel('sidebar', navigationSnapshot());
		else if (navigation.panel === 'sidebar') navigation.closePanel(navigationSnapshot());
	}
	document.body.dataset.sidebarOpen = String(open);
	$('close-sidebar').hidden = !open;
	$('toggle-sidebar').setAttribute('aria-expanded', String(open));
	syncFocusRegions();
	if (mobile.matches) (open ? $('feed-search') : $('toggle-sidebar')).focus();
}

function resetPolling() {
	clearTimeout(refreshTimer);
	if (!pageActive || store.error?.status === 403 || !saved.refreshInterval || document.visibilityState === 'hidden' || navigator.onLine === false) return;
	// One request at a time. The configured interval starts after completion.
	refreshTimer = setTimeout(async () => {
		try { await store.feedsRefresh(); } catch (error) { if (error.status === 403) store.reportError(error); }
		resetPolling();
	}, saved.refreshInterval * 1000);
}

function appearanceToken() { return { epoch: appearanceEpoch, revision: appearanceDraftRevision }; }
function currentAppearanceToken(token) { return token.epoch === appearanceEpoch && token.revision === appearanceDraftRevision; }

async function persist(values, token = appearanceToken()) {
	if (store.csrfInvalid || !validateSettings(values, true)) return false;
	const epoch = store.sessionEpoch;
	try {
		const response = await api.request('preferences', {}, { body: { preferences: values } });
		if (epoch !== store.sessionEpoch) return false;
		const previousPageSize = saved.pageSize;
		saved = preferences(response.preferences, schema);
		store.pageSize = saved.pageSize;
		store.cache.configure(saved.cacheArticles, saved.cacheMiB * bytesPerMiB);
		api.timeoutSeconds = saved.requestTimeout;
		applyAppearance(saved);
		syncThemeSettings(formValues());
		resetPolling();
		notice = t(currentAppearanceToken(token) ? 'saved' : 'previousSettingsSaved'); store.error = null;
		if (previousPageSize !== saved.pageSize) await queryList({});
		renderStatus();
		return true;
	} catch (error) {
		if (epoch !== store.sessionEpoch) return false;
		store.reportError(error);
		renderStatus(); return false;
	}
}

async function navigateArticle(delta) {
	articleNavigationQueue += delta;
	if (articleNavigationPaging) return;
	const epoch = articleNavigationEpoch;
	while (articleNavigationQueue) {
		if (epoch !== articleNavigationEpoch) return;
		if (store.loading) { articleNavigationQueue = 0; return; }
		const step = Math.sign(articleNavigationQueue); articleNavigationQueue -= step;
		let index = store.items.findIndex(item => item.id === store.selectedId);
		if (index < 0) index = step > 0 ? -1 : store.items.length;
		let next = store.items[index + step];
		if (!next) {
			const selected = store.selectedId;
			articleNavigationPaging = true; renderArticle();
			const loaded = await (step > 0 ? loadMoreArticles() : store.previousPage());
			if (epoch !== articleNavigationEpoch) {
				if (loaded && !store.selectedId) navigation.replace(navigationSnapshot());
				return;
			}
			articleNavigationPaging = false;
			if (!loaded || store.selectedId !== selected) { articleNavigationQueue = 0; renderArticle(); return; }
			next = step > 0 ? store.items[store.items.findIndex(item => item.id === selected) + 1] : store.items.at(-1);
		}
		if (next) openArticle(next.id);
	}
	renderArticle();
}

function cancelArticleNavigation() { articleNavigationEpoch++; articleNavigationQueue = 0; articleNavigationPaging = false; }

function openArticle(id) {
	if (store.selectedId === id && (store.article || store.loadingArticle) && !store.error) {
		$('close-article').focus({ preventScroll: true }); return;
	}
	navigationEpoch++;
	restoringNavigation = false;
	navigation.openArticle(id, navigationSnapshot());
	store.open(id);
	$('close-article').focus({ preventScroll: true });
}
function closeArticle() { cancelArticleNavigation(); navigation.closeArticle(navigationSnapshot(), settingsPage.toolDirty); }

document.body.dataset.readOnly = String(Boolean(bootstrap.readOnly));
if (bootstrap.readOnly) document.querySelectorAll('[data-open-settings]').forEach(link => { link.hidden = true; });
$('sign-in').hidden = !bootstrap.readOnly;
localise(); applyAppearance(saved);
document.querySelector('.skip-link').addEventListener('click', event => {
	if (settingsRoot.hidden) return;
	event.preventDefault(); $('settings-section-heading').focus();
});
$('article-search').elements.search.maxLength = bootstrap.limits.max_search_chars;
store.addEventListener('change', render);
feedSelection.addEventListener('change', renderFeedSelection);
$('select-visible-feeds').addEventListener('click', () => feedSelection.selectVisible(settingsSubscriptions.matchingIds()));
$('clear-feed-selection').addEventListener('click', () => feedSelection.clear());
$('edit-selected-feeds').addEventListener('click', () => {
	sidebar(false); navigation.openPanel('feed-settings', navigationSnapshot()); feedEditor.open();
});
$('show-feed-job').addEventListener('click', () => navigation.openPanel('feed-settings', navigationSnapshot()));
feedEditor.dialog.addEventListener('close', () => {
	if (!feedEditor.dialog.open && navigation.panel === 'feed-settings') navigation.closePanel(navigationSnapshot());
});
$('feed-search').addEventListener('input', filterFeeds);
$('feed-tree').addEventListener('click', event => {
	const toggle = event.target.closest('[data-toggle-category]');
	if (toggle) { feedTree.toggle(Number(toggle.dataset.toggleCategory)); return; }
	const category = event.target.closest('[data-category], [data-category-unread]');
	if (category) {
		const unread = Object.hasOwn(category.dataset, 'categoryUnread');
		sidebar(false); chooseStream({
			get: 'c_' + (unread ? category.dataset.categoryUnread : category.dataset.category),
			filter: unread ? 'unread' : store.query.filter,
			search: ''
		});
		return;
	}
	const button = event.target.closest('[data-feed]');
	if (button) {
		sidebar(false); chooseStream({ get: 'f_' + button.dataset.feed, filter: store.query.filter, search: '' });
	}
});
$('feed-tree').addEventListener('keydown', event => {
	const target = event.target.closest('[data-select-feed], [data-feed]');
	if (!target) return;
	if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key) || event.altKey || event.metaKey || event.ctrlKey) return;
	const ids = feedTree.matchingIds().filter(id => feedTree.groups.get(feedTree.nodes.get(id).categoryId).expanded);
	const current = Number(target.dataset.selectFeed || target.dataset.feed);
	const index = ids.indexOf(current);
	const nextIndex = event.key === 'Home' ? 0 : event.key === 'End' ? ids.length - 1 : index + (event.key === 'ArrowDown' ? 1 : -1);
	const id = ids[nextIndex];
	if (id === undefined) return;
	event.preventDefault();
	feedTree.nodes.get(id).button.focus();
});
document.querySelector('.primary-nav').addEventListener('click', event => {
	const button = event.target.closest('[data-filter]');
	if (button) { sidebar(false); chooseStream({ filter: button.dataset.filter }); }
});
$('all-subscriptions').addEventListener('click', () => {
	sidebar(false); chooseStream({ get: 'a', filter: store.query.filter, search: '' });
});
function chooseStream(query) {
	clearTimeout(searchTimer);
	$('article-search').elements.search.value = query.search ?? store.query.search;
	if (store.error || (Object.hasOwn(query, 'filter') && Object.hasOwn(store.query, 'state')) ||
		!Object.entries(query).every(([key, value]) => store.query[key] === value)) queryList(query);
}
function searchArticles() {
	clearTimeout(searchTimer);
	const value = $('article-search').elements.search.value.trim();
	if (value !== store.query.search || store.error) queryList({ search: value });
}
const searchInput = $('article-search').elements.search;
searchInput.addEventListener('compositionstart', () => { searchComposing = true; clearTimeout(searchTimer); });
searchInput.addEventListener('compositionend', () => { searchComposing = false; scheduleSearch(); });
function scheduleSearch() {
	clearTimeout(searchTimer);
	if (searchComposing) return;
	if (!searchInput.value.trim()) searchArticles();
	else searchTimer = setTimeout(searchArticles, 350);
}
searchInput.addEventListener('input', event => { if (!event.isComposing) scheduleSearch(); });
$('article-search').addEventListener('submit', event => { event.preventDefault(); if (!searchComposing) searchArticles(); });
$('article-list').addEventListener('click', event => {
	if (event.target.closest('[data-clear-search]')) { searchInput.value = ''; searchArticles(); searchInput.focus(); return; }
	const button = event.target.closest('[data-id]'); if (button) openArticle(button.dataset.id);
});
$('article-list').addEventListener('pointerover', event => {
	if (event.pointerType === 'mouse') preloader.hint(event.target.closest('[data-id]')?.dataset.id || null);
});
$('article-list').addEventListener('pointerleave', () => preloader.hint(null));
$('article-list').addEventListener('focusin', event => preloader.hint(event.target.closest('[data-id]')?.dataset.id || null));
let listScrollFrame = 0; let lastScrollTop = 0; let lastScrollTime = performance.now();
$('article-list').addEventListener('scroll', () => {
	if (listScrollFrame) return;
	listScrollFrame = requestAnimationFrame(() => {
		listScrollFrame = 0;
		const time = performance.now(); const top = $('article-list').scrollTop;
		const velocity = (top - lastScrollTop) / Math.max(1, time - lastScrollTime);
		lastScrollTop = top; lastScrollTime = time;
		$('previous-page').disabled = store.loading || top < 1;
		measureList(true, velocity);
	});
}, { passive: true });
for (const type of ['wheel', 'touchstart', 'pointerdown']) {
	$('article-list').addEventListener(type, () => { followSelection = false; }, { passive: true });
}
new ResizeObserver(() => { measureList(); if (followSelection) centerSelection(); }).observe($('article-list'));
$('article-list').addEventListener('keydown', event => {
	if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
	followSelection = false;
	const buttons = store.items.map(item => entryNodes.get(item.id).button);
	const index = buttons.indexOf(event.target.closest('[data-id]'));
	const indexNext = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : index + (event.key === 'ArrowDown' ? 1 : -1);
	const next = buttons[indexNext];
	if (next) { event.preventDefault(); next.focus(); } else if (event.key === 'ArrowDown' && store.next) {
		event.preventDefault();
		loadMoreArticles().then(loaded => { if (loaded) entryNodes.get(store.items[index + 1]?.id)?.button.focus(); });
	}
});
$('previous-page').addEventListener('click', () => {
	followSelection = false; $('article-list').scrollTo({ top: 0, behavior: reducedMotion.matches ? 'instant' : 'smooth' });
});
$('next-page').addEventListener('click', () => loadMoreArticles());
$('close-article').addEventListener('click', closeArticle);
$('mark-page-read').addEventListener('click', () => {
	store.batchState(store.items.filter(item => !item.read).map(item => item.id), 'read', true, bootstrap.apiLimits);
});
$('previous-article').addEventListener('click', () => navigateArticle(-1));
$('next-article').addEventListener('click', () => navigateArticle(1));
$('mark-read').addEventListener('click', () => {
	const item = store.article || store.items.find(item => item.id === store.selectedId);
	if (item) { autoReadSelection = item.id; store.changeState(item.id, 'read', !item.read); }
});
$('mark-star').addEventListener('click', () => {
	const item = store.article || store.items.find(item => item.id === store.selectedId);
	if (item) store.changeState(item.id, 'starred', !item.starred);
});
$('original-link').addEventListener('click', () => {
	const article = store.article;
	if (bootstrap.autoMarkSite && article && !article.read) { autoReadSelection = article.id; store.changeState(article.id, 'read', true); }
});
$('toggle-sidebar').addEventListener('click', () => sidebar(document.body.dataset.sidebarOpen !== 'true'));
$('close-sidebar').addEventListener('click', () => sidebar(false));
$('refresh').addEventListener('click', async () => {
	const button = $('refresh');
	button.disabled = true; button.setAttribute('aria-busy', 'true');
	store.clearContent();
	try {
		await Promise.all([queryList({}), store.feedsRefresh()]);
	} catch (error) {
		store.reportError(error); renderStatus();
	} finally { button.disabled = false; button.setAttribute('aria-busy', 'false'); }
});
function retryRequest(event) {
	if (event.target.closest('[data-reload-reader]')) { window.location.reload(); return; }
	if (!event.target.closest('[data-retry-reader]')) return;
	if (store.retry) store.retry();
	else { store.error = null; store.selectedId ? store.open(store.selectedId) : store.list(null, store.cursor); }
}
$('status').addEventListener('click', retryRequest);
$('article-status').addEventListener('click', retryRequest);
$('settings-status').addEventListener('click', retryRequest);
function openSettings(section = navigation.settingsSection) {
	ensureSettingsBuilt();
	appearanceEpoch++; fillSettings(readerDraft || saved); validateSettings(readerDraft || saved); sidebar(false);
	navigation.openPanel('settings', navigationSnapshot()); navigation.setSettingsSection(section);
	showSettingsPage(); settingsPage.activate(section, true);
}
$('open-settings').addEventListener('click', () => openSettings());
document.querySelectorAll('[data-open-settings]').forEach(link => link.addEventListener('click', event => {
	event.preventDefault(); openSettings(link.dataset.openSettings);
}));
function showSettingsPage() {
	settingsRoot.hidden = false; document.querySelector('.reader-shell').hidden = true;
	preloader.suspend();
	settingsPage.open(); syncFocusRegions();
}
function closeSettingsPage() {
	settingsRoot.hidden = true; document.querySelector('.reader-shell').hidden = false;
	settingsPage.close(); syncFocusRegions(); $('open-settings').focus({ preventScroll: true });
	preloader.update();
}
function cancelSettings() {
	const retainTool = settingsPage.toolDirty;
	readerDraft = settingsPage.readerDirty ? formValues() : null;
	appearanceEpoch++; closeSettingsPage();
	if (retainTool) navigation.closeSettingsPreservingFrame(navigationSnapshot());
	else navigation.closePanel('settings', navigationSnapshot());
}
$('cancel-settings').addEventListener('click', cancelSettings);
$('discard-settings').addEventListener('click', () => {
	readerDraft = null; appearanceDraftRevision++; fillSettings(saved); validateSettings(saved);
	settingsPage.resetSettings(); syncSettingsSave();
});
settingsRoot.addEventListener('input', event => {
	if (event.target.getAttribute('form') !== 'appearance-form') return;
	appearanceDraftRevision++; const values = formValues(); validateSettings(values); syncThemeSettings(values);
});
form.addEventListener('submit', async event => {
	event.preventDefault();
	const token = appearanceToken();
	const button = $('save-reader-settings'); button.disabled = true;
	button.textContent = t('saving'); form.setAttribute('aria-busy', 'true');
	try {
		const values = formValues();
		if (Object.keys(schema).some(key => values[key] !== saved[key])) {
			if (!await persist(values, token)) return;
			if (currentAppearanceToken(token)) { readerDraft = null; fillSettings(saved); }
		}
		if (!await settingsPage.saveSettings()) {
			notice = t('settingsIncomplete'); renderStatus();
		} else { notice = t(currentAppearanceToken(token) ? 'saved' : 'previousSettingsSaved'); renderStatus(); }
	} finally { button.textContent = t('save'); form.setAttribute('aria-busy', 'false'); syncSettingsSave(); }
});
$('reset-settings').addEventListener('click', () => {
	appearanceDraftRevision++; const values = defaults(schema); fillSettings(values); validateSettings(values);
});
$('quick-layout').addEventListener('change', async event => {
	const input = event.currentTarget;
	if (input.value === quickLayout(saved.layout) || store.csrfInvalid) return;
	input.disabled = true;
	try { await persist({ ...saved, layout: input.value }); } finally { syncQuickLayout(); input.disabled = store.csrfInvalid || Boolean(bootstrap.readOnly); }
});
$('save-server-limits').addEventListener('click', async event => {
	if (store.csrfInvalid) return;
	const epoch = store.sessionEpoch;
	if (!$('reader-limit-form').reportValidity()) return;
	const values = Object.fromEntries([...$('reader-limit-form').elements].map(input => [input.name, input.valueAsNumber]));
	event.target.disabled = true;
	try {
		const data = await api.request('limits', {}, { body: { limits: values } });
		if (epoch !== store.sessionEpoch) return;
		const draft = !settingsRoot.hidden ? formValues() : null;
		bootstrap.limits = data.limits;
		$('article-search').elements.search.maxLength = bootstrap.limits.max_search_chars;
		schema = data.schema;
		const changed = Object.keys(saved).filter(key => saved[key] !== data.preferences[key]);
		saved = preferences(data.preferences, schema);
		buildSettings(); localise(settingsRoot);
		const invalidDraft = draft ? preferenceErrors(draft, schema) : [];
		const draftValues = draft ? { ...saved, ...Object.fromEntries(Object.entries(draft).filter(([key]) => !invalidDraft.includes(key))) } : saved;
		appearanceDraftRevision++;
		fillSettings(draftValues); applyAppearance(saved);
		store.pageSize = saved.pageSize;
		store.cache.configure(saved.cacheArticles, saved.cacheMiB * bytesPerMiB);
		api.timeoutSeconds = saved.requestTimeout;
		resetPolling();
		notice = t('limitsSaved') + (changed.length ? ' ' + t('adjusted') + changed.map(t).join(', ') : '');
		store.error = null;
		if (changed.includes('pageSize')) await queryList({});
		renderStatus();
	} catch (error) {
		if (epoch === store.sessionEpoch) { store.reportError(error); renderStatus(); }
	} finally { event.target.disabled = store.csrfInvalid; }
});
$('export-settings').addEventListener('click', () => {
	const values = formValues();
	if (!validateSettings(values, true)) return;
	const data = JSON.stringify({ format: 'freshrss-reader', version: 1, preferences: values }, null, 2);
	const url = URL.createObjectURL(new Blob([data], { type: 'application/json' }));
	const link = element('a'); link.href = url; link.download = 'freshrss-reader-settings.json'; link.click();
	setTimeout(() => URL.revokeObjectURL(url), 0);
});
$('import-settings').addEventListener('click', () => $('import-file').click());
$('import-file').addEventListener('change', async event => {
	const file = event.target.files[0];
	if (!file) return;
	const token = appearanceToken();
	try {
		if (file.size > bootstrap.limits.max_settings_bytes) throw new Error('too_large');
		const data = JSON.parse(await file.text());
		if (settingsRoot.hidden || !currentAppearanceToken(token)) return;
		if (data.format !== 'freshrss-reader' || data.version !== 1 || preferenceErrors(data.preferences, schema).length) throw new Error('invalid');
		const values = preferences(data.preferences, schema); appearanceDraftRevision++; fillSettings(values); validateSettings(values);
	} catch {
		if (!settingsRoot.hidden && currentAppearanceToken(token)) {
			notice = t('importFailed').replace('{limit}', String(bootstrap.limits.max_settings_bytes / 1024)); renderStatus();
		}
	} finally { event.target.value = ''; }
});
document.addEventListener('keydown', event => {
	const settingsOpen = !settingsRoot.hidden || Boolean(document.querySelector('dialog[open]'));
	if (event.key === 'Escape' && !settingsOpen) {
		event.preventDefault();
		if (document.body.dataset.sidebarOpen === 'true') sidebar(false);
		else closeArticle();
		return;
	}
	if (settingsOpen || event.target.closest('input,select,textarea,[contenteditable=true]') || event.ctrlKey || event.metaKey || event.altKey) return;
	if (event.key === 'j' || event.key === 'k') {
		event.preventDefault();
		navigateArticle(event.key === 'j' ? 1 : -1);
	} else if (event.key === 'm') $('mark-read').click();
	else if (event.key === 's') $('mark-star').click();
});
media.addEventListener('change', () => { applyAppearance(current); syncThemeSettings(formValues()); });
forcedColours.addEventListener('change', () => applyAppearance(current));
mobile.addEventListener('change', () => { syncFocusRegions(); syncQuickLayout(); centerSelection(); measureList(); });
document.addEventListener('visibilitychange', () => { resetPolling(); preloader.update(); });
navigator.connection?.addEventListener('change', () => preloader.update());
window.addEventListener('online', () => { resetPolling(); render(); });
window.addEventListener('offline', () => { resetPolling(); render(); });
window.addEventListener('pagehide', () => {
	pageActive = false; appearanceEpoch++; cancelArticleNavigation(); clearTimeout(refreshTimer); clearTimeout(searchTimer); store.dispose(); feedEditor.suspend();
	preloader.suspend(); cancelAnimationFrame(centeringFrame); cancelAnimationFrame(listScrollFrame);
	navigation.flushWrite(); navigation.cancelWrite();
});
window.addEventListener('pageshow', event => {
	pageActive = true;
	if (event.persisted) {
		navigation.handlePopState({ state: window.history.state });
		store.resume().finally(resetPolling);
	}
});
window.addEventListener('popstate', event => navigation.handlePopState(event));
function restoreFrameNavigation() {
	if (!navigation.current) return;
	const frame = $('settings-tool-frame');
	try {
		navigation.handleFrameNavigation(frame.contentDocument, frame.contentWindow.location.href);
	} catch { /* Only same-origin settings documents participate. */ }
}
$('settings-tool-frame').addEventListener('load', restoreFrameNavigation);
window.addEventListener('message', event => {
	const frame = $('settings-tool-frame');
	if (event.origin !== location.origin || event.source !== frame.contentWindow || event.data?.type !== 'reader-settings-embed' ||
		event.data.event !== 'navigation') return;
	restoreFrameNavigation();
});
const initialNavigation = navigation.start({ ...navigationSnapshot(), query: { ...store.query, ...bootstrap.initialQuery } });
render();
Promise.all([store.feedsRefresh(), restoreNavigation(initialNavigation, navigation.panel, true)]).then(() => {
	// Feed names may arrive after the compact article list.
	renderedItems = null; render(); resetPolling();
}).catch(error => { store.reportError(error); renderStatus(); resetPolling(); });
