import { resolveTheme, themeSpec } from './themes.js';

const descriptions = {
	acid: ['亮白底色，荧光粉与柠檬黄强调', 'Bright white with neon pink and lemon accents'],
	aqua: ['深蓝底色，青色强调', 'Deep blue with cyan accents'],
	autumn: ['暖灰底色，砖红与橙色强调', 'Warm grey with brick red and orange accents'],
	black: ['纯黑底色，灰白文字', 'Black surfaces with grey and white text'],
	bumblebee: ['白色底色，蜂蜜黄强调', 'White surfaces with honey yellow accents'],
	business: ['深灰底色，克制的蓝色强调', 'Charcoal surfaces with muted blue accents'],
	cmyk: ['白色底色，蓝、粉与黄色强调', 'White surfaces with blue, pink and yellow accents'],
	coffee: ['深咖底色，暖金色文字', 'Dark coffee surfaces with warm gold text'],
	corporate: ['冷白底色，清晰的蓝色强调', 'Cool white surfaces with clear blue accents'],
	cupcake: ['奶白底色，薄荷绿与粉色强调', 'Cream surfaces with mint and pink accents'],
	cyberpunk: ['明黄底色，亮粉与青色强调', 'Bright yellow surfaces with pink and cyan accents'],
	dark: ['蓝灰底色，柔和的浅色文字', 'Blue grey surfaces with soft light text'],
	dim: ['石板灰底色，浅绿强调', 'Slate grey surfaces with light green accents'],
	dracula: ['紫灰底色，粉紫强调', 'Purple grey surfaces with pink and violet accents'],
	emerald: ['白色底色，翡翠绿强调', 'White surfaces with emerald green accents'],
	fantasy: ['白色底色，莓红与深蓝强调', 'White surfaces with berry red and navy accents'],
	forest: ['深绿底色，鲜绿强调', 'Deep green surfaces with fresh green accents'],
	garden: ['暖灰底色，柔和的粉绿配色', 'Warm grey surfaces with soft pink and green'],
	halloween: ['近黑底色，南瓜橙强调', 'Near black surfaces with pumpkin orange accents'],
	lemonade: ['淡黄绿底色，橄榄绿强调', 'Pale yellow green surfaces with olive accents'],
	light: ['明亮白底，蓝紫强调', 'Bright white surfaces with blue violet accents'],
	lofi: ['黑白配色，简洁的直角边界', 'Black and white with simple square edges'],
	luxury: ['近黑底色，暖金色强调', 'Near black surfaces with warm gold accents'],
	night: ['深海蓝底色，浅蓝强调', 'Deep navy surfaces with light blue accents'],
	nord: ['冷灰白底，柔和的蓝色强调', 'Cool grey white surfaces with soft blue accents'],
	pastel: ['灰白底色，浅紫与淡绿强调', 'Grey white surfaces with lilac and pale green accents'],
	retro: ['米黄底色，褪色的绿与珊瑚红', 'Beige surfaces with faded green and coral accents'],
	sunset: ['深紫底色，橙金色强调', 'Deep violet surfaces with orange gold accents'],
	synthwave: ['深紫底色，霓虹粉与青色强调', 'Deep violet surfaces with neon pink and cyan accents'],
	valentine: ['淡粉底色，玫红强调', 'Pale pink surfaces with rose accents'],
	winter: ['冷白底色，冰蓝强调', 'Cool white surfaces with ice blue accents'],
	wireframe: ['黑白线框，几乎没有装饰', 'Black and white outlines with minimal decoration'],
};

const optionDescriptions = {
	layout: { split: 'layoutSplitDescription', list: 'layoutListDescription', cards: 'layoutCardsDescription' },
	density: { comfortable: 'densityComfortableDescription', compact: 'densityCompactDescription' },
	style: { calm: 'styleCalmDescription', paper: 'stylePaperDescription', contrast: 'styleContrastDescription' },
};

const isTheme = key => ['theme', 'lightTheme', 'darkTheme'].includes(key);
const themeName = name => name === 'cmyk' ? 'CMYK' : name === 'lofi' ? 'Lo-Fi' : name[0].toUpperCase() + name.slice(1);

/** Native preference controls with previews scoped to their own sample reader. */
export class AppearanceChoices {
	constructor({ element, t, systemDark = () => false, language = 'en' }) {
		Object.assign(this, { element, t, systemDark });
		this.zh = language.toLowerCase().startsWith('zh');
		this.groups = new Map();
		this.previewDark = null;
		this.previewPane = 'article';
		this.values = { theme: 'sunset', lightTheme: 'nord', darkTheme: 'sunset', layout: 'split', density: 'comfortable', style: 'calm' };
		this.saved = { ...this.values };
	}

	create(key, spec) {
		const fieldset = this.element('fieldset', 'appearance-choice-group'); fieldset.dataset.preference = key;
		fieldset.append(this.element('legend', '', this.t(key)));
		const help = this.element('p', 'appearance-choice-help', this.t(key + 'Help'));
		help.id = 'appearance-' + key + '-help'; fieldset.append(help);
		const tools = this.element('div', 'appearance-choice-tools');
		const current = this.element('span', 'appearance-current-choice'); tools.append(current);
		const expand = this.element('button', 'appearance-preview-expand', this.t('appearancePreviewExpand')); expand.type = 'button';
		expand.addEventListener('click', () => {
			if (key === 'lightTheme') this.previewDark = false;
			if (key === 'darkTheme') this.previewDark = true;
			this.sync(this.values, this.saved);
			if (this.preview) this.preview.dialog.showModal();
		}); tools.append(expand); fieldset.append(tools);
		const grid = this.element('div', 'appearance-choice-grid ' + (isTheme(key) ? 'theme-choice-grid' : 'layout-choice-grid'));
		const choices = [];
		for (const value of spec.options) {
			const label = this.element('label', 'appearance-choice'); label.dataset.choiceValue = value;
			const input = this.element('input'); input.type = 'radio'; input.name = key; input.value = value;
			input.setAttribute('form', 'appearance-form'); input.id = `appearance-${key}-${value}`;
			const name = isTheme(key) ? value === 'auto' ? this.t('auto') : themeName(value) : this.t(value);
			input.setAttribute('aria-label', name);
			const content = this.element('span', 'appearance-choice-content');
			const miniature = this.reader(true); content.append(miniature);
			const title = this.element('span', 'appearance-choice-label');
			const check = this.element('span', 'appearance-choice-check', '✓'); check.setAttribute('aria-hidden', 'true');
			title.append(this.element('strong', '', name), check); content.append(title);
			const description = this.element('small', 'appearance-choice-description', this.description(key, value));
			description.id = input.id + '-description'; input.setAttribute('aria-describedby', description.id);
			const state = this.element('small', 'appearance-choice-state'); state.setAttribute('aria-hidden', 'true');
			content.append(description, state); label.append(input, content); grid.append(label);
			input.addEventListener('input', () => {
				if (key === 'lightTheme') this.previewDark = false;
				if (key === 'darkTheme') this.previewDark = true;
				this.sync({ ...this.values, [key]: input.value }, this.saved);
			});
			choices.push({ value, name, input, label, miniature, state });
		}
		fieldset.append(grid); this.groups.set(key, { fieldset, choices, current }); this.sync(this.values, this.saved);
		return fieldset;
	}

	description(key, value) {
		if (value === 'auto') return this.t('appearanceAutoDescription');
		if (!isTheme(key)) return this.t(optionDescriptions[key][value]);
		const theme = themeSpec(value);
		return `${this.t(theme.dark ? 'darkPalette' : 'lightPalette')} · ${descriptions[value][this.zh ? 0 : 1]}`;
	}

	/** A readable, isolated sample, with an optional enlarged view available beside its title. */
	createPreview() {
		if (this.preview?.dialog.open) this.preview.dialog.close();
		const root = this.element('section', 'appearance-live-preview'); root.dataset.appearancePreview = '';
		const heading = this.element('header', 'appearance-preview-heading');
		const title = this.element('strong', '', this.t('appearancePreviewTitle')); title.id = 'appearance-preview-title';
		const expand = this.element('button', 'appearance-preview-expand', this.t('appearancePreviewExpand')); expand.type = 'button';
		const modes = this.previewModes();
		const panes = this.previewPanes();
		heading.append(title, expand); root.append(heading, modes, panes, this.element('p', 'appearance-preview-note', this.t('appearancePreviewNote')));
		const surface = this.element('div', 'appearance-preview-surface'); surface.dataset.appearancePreviewSurface = '';
		const miniature = this.reader(); surface.append(miniature); root.append(surface);
		const dialog = this.element('dialog', 'appearance-preview-dialog'); dialog.setAttribute('aria-labelledby', 'appearance-preview-dialog-title');
		const dialogHeading = this.element('header', 'appearance-preview-heading');
		const dialogTitle = this.element('strong', '', this.t('appearancePreviewTitle')); dialogTitle.id = 'appearance-preview-dialog-title';
		const close = this.element('button', 'appearance-preview-close', this.t('appearancePreviewClose')); close.type = 'button';
		dialogHeading.append(dialogTitle, close);
		const enlargedModes = this.previewModes();
		const enlargedPanes = this.previewPanes();
		const enlarged = this.element('div', 'appearance-preview-surface'); enlarged.dataset.appearancePreviewSurface = '';
		const enlargedReader = this.reader(); enlarged.append(enlargedReader);
		dialog.append(dialogHeading, enlargedModes, enlargedPanes, enlarged,
			this.element('p', 'appearance-preview-note', this.t('appearancePreviewNote'))); root.append(dialog);
		expand.addEventListener('click', () => dialog.showModal()); close.addEventListener('click', () => dialog.close());
		dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
		this.preview = {
			root,
			dialog,
			modes: [modes, enlargedModes],
			panes: [panes, enlargedPanes],
			surfaces: [[surface, miniature], [enlarged, enlargedReader]]
		};
		this.sync(this.values, this.saved); return root;
	}

	previewModes() {
		const group = this.element('div', 'appearance-preview-modes'); group.setAttribute('role', 'group');
		group.setAttribute('aria-label', this.t('appearancePreviewMode'));
		for (const mode of ['light', 'dark']) {
			const button = this.element('button', '', this.t(mode === 'light' ? 'appearancePreviewLight' : 'appearancePreviewDark'));
			button.type = 'button'; button.dataset.previewMode = mode;
			button.addEventListener('click', () => { this.previewDark = mode === 'dark'; this.sync(this.values, this.saved); }); group.append(button);
		}
		return group;
	}

	previewPanes() {
		const group = this.element('div', 'appearance-preview-areas'); group.setAttribute('role', 'group');
		group.setAttribute('aria-label', this.t('appearancePreviewPane'));
		for (const [pane, label] of [['sidebar', 'appearancePreviewSidebar'], ['list', 'appearancePreviewList'], ['article', 'appearancePreviewArticle']]) {
			const button = this.element('button', '', this.t(label)); button.type = 'button'; button.dataset.previewPane = pane;
			button.addEventListener('click', () => { this.previewPane = pane; this.sync(this.values, this.saved); }); group.append(button);
		}
		return group;
	}

	reader(small = false) {
		const node = (tag, className, text) => this.element(small ? 'span' : tag, className, text);
		const reader = node('div', 'reader-miniature');
		if (small) reader.setAttribute('aria-hidden', 'true');
		const sidebar = node('aside', 'mini-sidebar'); sidebar.append(node('strong', 'mini-brand', 'FreshRSS'));
		for (const key of ['all', 'unread', 'starred']) sidebar.append(node('span', 'mini-nav' + (key === 'all' ? ' is-active' : ''), this.t(key)));
		sidebar.append(node('span', 'mini-nav', this.t('appearanceSampleSource')));
		const list = node('section', 'mini-list'); list.append(node('strong', 'mini-list-heading', this.t('all')));
		for (const [index, key] of ['appearanceSampleTitle', 'appearanceSampleSecondTitle', 'appearanceSampleThirdTitle'].entries()) {
			const entry = node('article', 'mini-entry' + (index === 0 ? ' is-active' : ''));
			entry.append(node('strong', 'mini-entry-title', this.t(key)),
				node('small', 'mini-entry-meta', this.t('appearanceSampleSource') + ' · ' + this.t('appearanceSampleTime')),
				node('p', 'mini-entry-summary', this.t('appearanceSampleBody'))); list.append(entry);
		}
		const article = node('article', 'mini-article');
		article.append(node('small', 'mini-byline', this.t('appearanceSampleSource') + ' · ' + this.t('appearanceSampleTime')),
			node('h3', 'mini-article-title', this.t('appearanceSampleTitle')), node('p', 'mini-paragraph', this.t('appearanceSampleBody')),
			node('p', 'mini-paragraph', this.t('appearanceSampleSecondBody')), node('span', 'mini-link', this.t('original')));
		reader.append(sidebar, list, article); return reader;
	}

	paint(node, values) {
		const theme = resolveTheme(values, this.previewDark ?? this.systemDark());
		if (node.dataset.theme !== theme.name) {
			for (const [key, value] of Object.entries(theme.tokens)) {
				if (key === 'color-scheme') node.style.colorScheme = value;
				else node.style.setProperty(key, value);
			}
		}
		node.dataset.theme = theme.name;
		for (const key of ['layout', 'density', 'style']) node.dataset[key] = values[key];
	}

	sync(values, saved = this.saved) {
		if (values.theme !== this.values.theme) this.previewDark = null;
		if (values.layout && values.layout !== this.values.layout) this.previewPane = values.layout === 'split' ? 'article' : 'list';
		this.values = { ...this.values, ...values }; this.saved = { ...saved };
		for (const [key, { fieldset, choices, current }] of this.groups) {
			fieldset.hidden = ['lightTheme', 'darkTheme'].includes(key) && this.values.theme !== 'auto';
			current.textContent = this.t(key) + ' · ' + (choices.find(choice => choice.value === this.values[key])?.name || '');
			for (const choice of choices) {
				const selected = this.values[key] === choice.value; const committed = this.saved[key] === choice.value;
				choice.input.checked = selected; choice.label.dataset.selected = String(selected); choice.label.dataset.saved = String(committed);
				choice.state.textContent = selected
					? this.t(committed ? 'appearanceChoiceSaved' : 'appearanceChoiceSelected')
					: committed ? this.t('appearanceChoiceSaved') : '';
				const sample = { ...this.values };
				if (isTheme(key)) { sample.theme = choice.value; sample.layout = 'split'; } else {
					sample[key] = choice.value;
					if (key === 'style') { sample.layout = 'split'; sample.density = 'comfortable'; }
					if (key === 'density') sample.layout = 'list';
				}
				this.paint(choice.miniature, sample);
			}
		}
		if (this.preview) {
			for (const [surface, miniature] of this.preview.surfaces) {
				this.paint(surface, this.values); this.paint(miniature, this.values);
				surface.dataset.previewPane = this.previewPane; miniature.dataset.previewPane = this.previewPane;
			}
			for (const group of this.preview.panes) {
				for (const button of group.children) button.setAttribute('aria-pressed', String(button.dataset.previewPane === this.previewPane));
			}
			for (const group of this.preview.modes) {
				group.hidden = this.values.theme !== 'auto';
				for (const button of group.children) {
					button.setAttribute('aria-pressed', String((button.dataset.previewMode === 'dark') ===
					(this.previewDark ?? this.systemDark())));
				}
			}
		}
	}
}
