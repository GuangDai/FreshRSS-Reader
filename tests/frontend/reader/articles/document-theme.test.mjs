import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { THEME_NAMES, themeSpec } from '../../../../p/scripts/reader/appearance/themes.js';

const source = readFileSync(new URL('../../../../p/scripts/reader/articles/document.js', import.meta.url), 'utf8').replaceAll('export function ', 'function ');
const preferences = { style: 'editorial', readingWidth: 760, fontSize: 20, lineHeight: 185 };
const article = { title: 'Theme fixture', author: 'Reader', content: '' };

// These tests isolate document construction; content sanitisation is covered in the browser suite.
function render(theme, overrides = {}, allowImages = false, labels = {}) {
	const context = vm.createContext({
		URL,
		document: {
			documentElement: { lang: 'zh-CN', dir: 'rtl' },
			createElement(name) {
				assert.equal(name, 'template');
				return { innerHTML: '', content: { querySelectorAll() { return []; } } };
			},
		},
	});
	vm.runInContext(source, context);
	return context.articleDocument(article, { ...preferences, ...overrides }, theme, allowImages, labels);
}

for (const name of THEME_NAMES) {
	test(`${name} article uses the official theme across its canvas and content`, () => {
		const spec = themeSpec(name);
		const tokens = spec.tokens;
		const palette = {
			dark: spec.dark,
			surface: tokens['--color-base-100'],
			text: tokens['--color-base-content'],
			muted: tokens['--color-base-content'],
			accent: tokens['--color-primary'],
			subtle: tokens['--color-base-200'],
			line: tokens['--color-base-300'],
			radius: tokens['--radius-box'],
		};
		const document = render(palette);
		assert.ok(document.includes(`html{color-scheme:${spec.dark ? 'dark' : 'light'};background:${palette.surface};color:${palette.text}}`));
		assert.ok(document.includes(`background:${palette.surface};\ncolor:${palette.text};font:20px/1.85`));
		assert.ok(document.includes(`a{color:${palette.accent};text-decoration:underline;`));
		assert.ok(document.includes(`background:${palette.subtle};color:${palette.text};border:1px solid ${palette.line};border-radius:${palette.radius};`));
		assert.ok(document.includes(`border-inline-start:3px solid ${palette.accent}`));
		assert.ok(document.includes(`td,th{padding:8px;border:1px solid ${palette.line}}`));
		assert.ok(document.includes(`.meta,figcaption{font-size:.8em;color:${palette.muted}}`));
	});
}

test('paper typography preserves the chosen theme and reading dimensions', () => {
	const palette = { surface: 'rgb(18, 32, 64)', text: 'oklch(90% 0.03 200)', dark: true };
	const document = render(palette, { style: 'paper' });
	assert.ok(document.includes('background:rgb(18, 32, 64)'));
	assert.ok(document.includes('max-width:760px'));
	assert.ok(document.includes('font:20px/1.85 ui-serif,"Noto Serif CJK SC",Georgia,serif'));
	assert.ok(!document.includes('#fbf6eb'));
});

test('boolean callers retain their original light, dark and paper backgrounds', () => {
	assert.ok(render(false).includes('background:#fff'));
	assert.ok(render(true).includes('background:#171d1a'));
	assert.ok(render(false, { style: 'paper' }).includes('background:#fbf6eb'));
});

test('computed wide gamut colours and zero radius are preserved', () => {
	const document = render({ surface: 'color(display-p3 0.05 0.1 0.2)', text: 'rgba(240, 240, 255, 0.95)', radius: '0' });
	assert.ok(document.includes('background:color(display-p3 0.05 0.1 0.2)'));
	assert.ok(document.includes('color:rgba(240, 240, 255, 0.95)'));
	assert.ok(document.includes('border-radius:0'));
});

test('palette values cannot add CSS declarations or break out of the style element', () => {
	const values = [
		'</style><script>alert(1)</script>',
		'black;background:url(https://example.invalid/track)',
		'var(--theme-colour)',
		'#000}body{display:none}',
		'rgb(0,0,0);color:red',
	];
	for (const value of values) {
		const document = render({ surface: value, text: value, accent: value, subtle: value, line: value, muted: value, radius: value });
		assert.ok(!document.includes(value));
		assert.ok(document.includes('background:#fff'));
		assert.ok(document.includes('color:#25342b'));
		assert.ok(document.includes('border-radius:6px'));
		assert.equal((document.match(/<style>/g) || []).length, 1);
		assert.equal((document.match(/<\/style>/g) || []).length, 1);
	}
});

test('forced-colour preferences override every article surface and theme colour', () => {
	const document = render({ surface: 'Canvas', text: 'CanvasText', accent: 'LinkText', muted: 'CanvasText', line: 'ButtonText' });
	assert.ok(document.includes('@media(forced-colors:active){html,body,pre,th{background:Canvas;color:CanvasText}'));
	assert.ok(document.includes('.meta,figcaption{color:CanvasText}a{color:LinkText}pre,td,th,hr{border-color:CanvasText}'));
	assert.ok(document.includes('blockquote{border-color:LinkText}'));
});

test('the themed document retains language, image restrictions and script-free CSP', () => {
	const blocked = render({ dark: true });
	assert.ok(blocked.includes('<html lang="zh-CN" dir="rtl">'));
	assert.ok(blocked.includes('default-src &#39;none&#39;; img-src &#39;none&#39;'));
	assert.ok(!blocked.includes('script-src'));
	assert.ok(render({}, {}, true).includes('img-src https: http:'));
});
