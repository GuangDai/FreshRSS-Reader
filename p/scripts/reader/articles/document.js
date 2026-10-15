function escapeHtml(value) {
	return String(value).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

export function safeLink(value) {
	try { const url = new URL(value); return ['http:', 'https:'].includes(url.protocol) ? url.href : null; } catch { return null; }
}

/** Accept computed colours, never declarations or unresolved CSS variables. */
function safeColour(value, fallback) {
	if (typeof value !== 'string') return fallback;
	const colour = value.trim();
	const valid = /^(?:#[a-f\d]{3,4}|#[a-f\d]{6}(?:[a-f\d]{2})?|Canvas|CanvasText|LinkText|ButtonText|transparent)$/i.test(colour) ||
		/^(?:rgba?|hsla?|oklab|oklch|lab|lch)\([+\-\d.eE% ,/]+\)$/.test(colour) ||
		/^color\((?:srgb|srgb-linear|display-p3|a98-rgb|prophoto-rgb|rec2020|xyz|xyz-d50|xyz-d65) [+\-\d.eE% /]+\)$/.test(colour);
	return valid && (!globalThis.CSS?.supports || CSS.supports('color', colour)) ? colour : fallback;
}

function articleTheme(theme, preferences) {
	const dark = typeof theme === 'boolean' ? theme : theme?.dark === true;
	const fallback = {
		dark,
		surface: dark ? '#171d1a' : typeof theme === 'boolean' && preferences.style === 'paper' ? '#fbf6eb' : '#fff',
		text: dark ? '#e5eee8' : '#25342b',
		muted: dark ? '#acbdb1' : '#66756c',
		accent: dark ? '#a6d7b5' : '#236240',
		subtle: dark ? '#242e28' : '#f2f5ef',
		line: dark ? '#35493d' : '#799980',
		radius: '6px',
	};
	if (!theme || typeof theme !== 'object') return fallback;
	for (const key of ['surface', 'text', 'muted', 'accent', 'subtle', 'line']) fallback[key] = safeColour(theme[key], fallback[key]);
	if (typeof theme.radius === 'string' && /^(?:0|(?:\d+(?:\.\d+)?|\.\d+)(?:px|rem|em))$/.test(theme.radius.trim())) {
		fallback.radius = theme.radius.trim();
	}
	return fallback;
}

/** The frame has an opaque origin and no scripts/forms/navigation permissions. */
export function articleDocument(article, preferences, theme, allowImages = false, labels = {}) {
	const palette = articleTheme(theme, preferences);
	const font = preferences.style === 'paper' ? 'ui-serif,"Noto Serif CJK SC",Georgia,serif' : 'system-ui,-apple-system,sans-serif';
	const template = document.createElement('template');
	template.innerHTML = article.content;
	const content = template.content;
	content.querySelectorAll('iframe,video,audio').forEach(node => {
		const href = safeLink(node.getAttribute('src') || node.querySelector('source')?.getAttribute('src'));
		if (href) {
			const link = document.createElement('a');
			link.href = href;
			link.textContent = labels.media || 'Open embedded media';
			node.replaceWith(link);
		} else node.remove();
	});
	content.querySelectorAll('script,style,link,meta,base,object,embed,form,input,button,textarea,select,source').forEach(node => node.remove());
	const removedAttributes = ['style', 'color', 'bgcolor', 'text', 'link', 'vlink', 'alink', 'face', 'srcdoc', 'id', 'name', 'srcset', 'poster', 'background'];
	content.querySelectorAll('*').forEach(node => {
		for (const attribute of [...node.attributes]) {
			if (attribute.name.startsWith('on') || removedAttributes.includes(attribute.name)) {
				node.removeAttribute(attribute.name);
			}
		}
	});
	content.querySelectorAll('a').forEach(link => {
		const href = safeLink(link.getAttribute('href'));
		if (href) { link.href = href; link.target = '_blank'; link.rel = 'noopener noreferrer'; } else link.removeAttribute('href');
	});
	content.querySelectorAll('img').forEach(img => {
		if (!allowImages || !safeLink(img.getAttribute('src'))) {
			const caption = document.createElement('span'); caption.className = 'meta';
			caption.textContent = img.getAttribute('alt') || labels.image || 'Image blocked';
			img.replaceWith(caption); return;
		}
		img.loading = 'lazy'; img.decoding = 'async'; img.referrerPolicy = 'no-referrer';
	});
	const language = document.documentElement.lang;
	const direction = document.documentElement.dir === 'rtl' ? 'rtl' : 'ltr';
	const csp = `default-src 'none'; img-src ${allowImages ? 'https: http:' : "'none'"}; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'`;
	const byline = labels.byline ?? article.author;
	const metadata = byline ? '<p class="meta">' + escapeHtml(byline) + '</p>' : '';
	return `<!doctype html><html lang="${escapeHtml(language)}" dir="${direction}"><head><meta charset="utf-8"><meta name="referrer" content="no-referrer">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta http-equiv="Content-Security-Policy" content="${escapeHtml(csp)}">
<style>html{color-scheme:${palette.dark ? 'dark' : 'light'};background:${palette.surface};color:${palette.text}}
*{box-sizing:border-box}
body{margin:0 auto;padding:max(24px,env(safe-area-inset-top)) max(clamp(18px,5vw,48px),env(safe-area-inset-right))
max(80px,env(safe-area-inset-bottom)) max(clamp(18px,5vw,48px),env(safe-area-inset-left));max-width:${preferences.readingWidth}px;background:${palette.surface};
color:${palette.text};font:${preferences.fontSize}px/${preferences.lineHeight / 100} ${font};overflow-wrap:anywhere}
h1{font-size:1.65em;line-height:1.4;letter-spacing:-.03em}
h2,h3{line-height:1.5}
p{margin:1.2em 0}
a{color:${palette.accent};text-decoration:underline;text-underline-offset:.15em}
img{max-width:100%;height:auto;border-radius:${palette.radius}}
figure{margin-inline:0;max-width:100%}
pre{padding:16px;background:${palette.subtle};color:${palette.text};border:1px solid ${palette.line};border-radius:${palette.radius};
overflow:auto;white-space:pre;overflow-wrap:normal;font-size:.85em}
blockquote{margin-inline:0;padding-inline:20px;border-inline-start:3px solid ${palette.accent}}
table{display:block;max-width:100%;overflow:auto;border-collapse:collapse}
td,th{padding:8px;border:1px solid ${palette.line}}
th{background:${palette.subtle};color:${palette.text}}
hr{border:0;border-top:1px solid ${palette.line}}
svg{display:none}
.meta,figcaption{font-size:.8em;color:${palette.muted}}
*:focus-visible{outline:2px solid currentColor;outline-offset:3px}
@media(max-height:480px){body{padding-top:max(10px,env(safe-area-inset-top))}h1{margin-block:.35em .4em}}
@media(forced-colors:active){html,body,pre,th{background:Canvas;color:CanvasText}
.meta,figcaption{color:CanvasText}a{color:LinkText}pre,td,th,hr{border-color:CanvasText}blockquote{border-color:LinkText}}
</style></head><body dir="auto"><h1>${escapeHtml(article.title)}</h1>${metadata}${template.innerHTML}</body></html>`;
}
