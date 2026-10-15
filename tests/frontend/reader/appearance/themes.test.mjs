import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { THEME_NAMES, resolveTheme, themeSpec } from '../../../../p/scripts/reader/appearance/themes.js';

// This snapshot comes from the published daisyUI package pinned by MetaCubeXD,
// rather than deriving expected colours from our JS or CSS implementation.
const upstream = JSON.parse(readFileSync(new URL('../../../fixtures/themes/daisyui-5.7.17-themes.json', import.meta.url), 'utf8'));
const css = readFileSync(new URL('../../../../p/styles/reader/themes.css', import.meta.url), 'utf8');

test('all 32 selectable themes retain every field from daisyUI 5.7.17', () => {
	assert.equal(upstream.daisyUIVersion, '5.7.17');
	assert.equal(THEME_NAMES.length, 32);
	assert.deepEqual(THEME_NAMES, Object.keys(upstream.themes));
	for (const name of THEME_NAMES) {
		const spec = themeSpec(name);
		assert.equal(spec.name, name);
		assert.equal(spec.dark, upstream.themes[name]['color-scheme'] === 'dark');
		assert.equal(Object.keys(spec.tokens).length, 29);
		assert.deepEqual(Object.keys(spec.tokens), Object.keys(upstream.themes[name]), `${name}: token names`);
		for (const [field, expected] of Object.entries(upstream.themes[name])) {
			assert.equal(spec.tokens[field], expected, `${name}: ${field}`);
		}
	}
});

test('the loaded CSS uses the same 29 original fields for every selectable theme', () => {
	const blocks = [...css.matchAll(/body\[data-theme="([a-z]+)"\] \{\n([\s\S]*?)\n\}/g)];
	assert.deepEqual(blocks.map(block => block[1]), THEME_NAMES);
	for (const [, name, content] of blocks) {
		const values = Object.fromEntries(content.split('\n').map(line => {
			const match = /^\t([^:]+): (.+);$/.exec(line);
			assert.ok(match, `${name}: valid CSS declaration`);
			return [match[1], match[2]];
		}));
		assert.deepEqual(values, upstream.themes[name], `${name}: CSS matches upstream`);
	}
});

test('auto preferences use their selected day and night themes, including legacy defaults', () => {
	assert.equal(resolveTheme({ theme: 'auto' }, false).name, 'nord');
	assert.equal(resolveTheme({ theme: 'auto' }, true).name, 'sunset');
	assert.equal(resolveTheme({ theme: 'auto', lightTheme: 'retro', darkTheme: 'dracula' }, false).name, 'retro');
	assert.equal(resolveTheme({ theme: 'auto', lightTheme: 'retro', darkTheme: 'dracula' }, true).name, 'dracula');
	for (const name of THEME_NAMES) {
		assert.equal(resolveTheme({ theme: name }, false).name, name);
		assert.equal(resolveTheme({ theme: name }, true).name, name);
		assert.equal(resolveTheme({ theme: 'auto', lightTheme: name }, false).name, name);
		assert.equal(resolveTheme({ theme: 'auto', darkTheme: name }, true).name, name);
	}
	assert.equal(resolveTheme({ theme: 'light' }, true).dark, false);
	assert.equal(resolveTheme({ theme: 'dark' }, false).dark, true);
});

test('unrecognised names fall back to built-in themes without accepting CSS or prototype properties', () => {
	for (const name of ['unknown', 'auto', 'constructor', '__proto__', 'url(javascript:evil)', undefined]) {
		assert.equal(themeSpec(name).name, 'sunset');
		assert.equal(resolveTheme({ theme: 'auto', lightTheme: name }, false).name, 'nord');
		assert.equal(resolveTheme({ theme: 'auto', darkTheme: name }, true).name, 'sunset');
	}
	assert.equal(resolveTheme().name, 'sunset');
	assert.ok(Object.isFrozen(THEME_NAMES));
	assert.ok(Object.isFrozen(themeSpec('sunset')));
	assert.ok(Object.isFrozen(themeSpec('sunset').tokens));
});
