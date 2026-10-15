import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { THEME_NAMES } from '../../../p/scripts/reader/appearance/themes.js';

const root = fileURLToPath(new URL('../../../', import.meta.url));
const themeRoot = new URL('../../../p/themes/', import.meta.url);
const read = path => readFileSync(new URL(path, themeRoot), 'utf8');
const upstream = JSON.parse(readFileSync(new URL('../../fixtures/themes/daisyui-5.7.17-themes.json', import.meta.url), 'utf8'));
const expectedFiles = ['_frss.css', '../Origine/origine.css', '../Calm/calm.css', '../MetaCubeXD/common.css', 'theme.css'];
const declarations = css => Object.fromEntries([...css.matchAll(/(^|\n)\s*((?:--[\w-]+)|color-scheme):\s*([^;]+);/g)]
	.map(([, , property, value]) => [property, value.trim()]));

test('Classic registers exactly the 32 MetaCubeXD theme names and resolves every theme asset', () => {
	assert.equal(upstream.daisyUIVersion, '5.7.17');
	const expected = Object.keys(upstream.themes).map(name => 'MetaCube-' + name);
	assert.deepEqual(readdirSync(themeRoot).filter(name => name.startsWith('MetaCube-')), expected);
	for (const name of THEME_NAMES) {
		const directory = new URL('MetaCube-' + name + '/', themeRoot);
		const metadata = JSON.parse(readFileSync(new URL('metadata.json', directory), 'utf8'));
		assert.deepEqual(metadata.files, expectedFiles, name + ': cascade order');
		assert.ok(metadata.name.toLowerCase().includes(name), name + ': visible name');
		assert.ok(metadata.author && metadata.description && metadata.version, name + ': complete metadata');
		for (const file of metadata.files.filter(file => !file.startsWith('_'))) {
			assert.ok(existsSync(new URL(file, directory)), name + ': ' + file);
			assert.ok(existsSync(new URL(file.replace(/\.css$/, '.rtl.css'), directory)), name + ': RTL ' + file);
		}
		assert.ok(existsSync(new URL('thumbs/original.png', directory)), name + ': appearance preview');
	}
});

test('each Classic theme preserves all 29 upstream fields and its fixed colour scheme in LTR and RTL', () => {
	for (const [name, expected] of Object.entries(upstream.themes)) {
		assert.equal(Object.keys(expected).length, 29);
		for (const suffix of ['', '.rtl']) {
			const css = read('MetaCube-' + name + '/theme' + suffix + '.css');
			const rootBlock = /:root\s*\{([^}]+)\}/.exec(css);
			assert.ok(rootBlock, name + ': original token block');
			const values = declarations(rootBlock[1]);
			for (const [field, value] of Object.entries(expected)) {
				assert.equal(values[field], value, `${name}${suffix}: ${field}`);
			}
			assert.equal(Object.keys(values).length, 29, name + ': original tokens only');
		}
	}
});

test('Classic maps every inherited colour variable in both directions, including automatic dark-mode specificity', () => {
	const inherited = new Set(['base-theme/frss.css', 'Origine/origine.css', 'Calm/calm.css'].flatMap(file => Object.entries(declarations(read(file)))
		.filter(([property, value]) => property.startsWith('--') && (/color|scrollbar/.test(property) || /^(#|transparent)/.test(value)))
		.map(([property]) => property)));
	assert.ok(inherited.size > 60, 'Exercise the complete inherited palette, including alerts, shadows and input states');
	for (const suffix of ['', '.rtl']) {
		const css = read('MetaCubeXD/common' + suffix + '.css');
		const rootBlock = /:root\s*,\s*:root\.darkMode_auto\s*\{([^}]+)\}/.exec(css);
		assert.ok(rootBlock, 'Map light and auto palettes with matching specificity');
		const values = declarations(rootBlock[1]);
		for (const property of inherited) {
			assert.ok(values[property], suffix + ': ' + property);
			assert.ok(/var\(|transparent/.test(values[property]), property + ': theme-derived semantic colour');
		}
	}
});

test('FreshRSS PHP theme discovery exposes all 32 Classic themes with the intended CSS order', t => {
	let php = process.env.FRESHRSS_TEST_PHP || 'php';
	try { execFileSync(php, ['-v'], { stdio: 'ignore' }); } catch {
		if (process.env.FRESHRSS_TEST_PHP) throw new Error('FRESHRSS_TEST_PHP is not executable');
		php = root + 'bin/php';
		if (!existsSync(php)) { t.skip('PHP is required to exercise the real theme discovery'); return; }
	}
	const registered = JSON.parse(execFileSync(php, ['-r',
		"require 'constants.php'; require 'lib/lib_rss.php'; echo json_encode(FreshRSS_Themes::get(), JSON_THROW_ON_ERROR);",
	], { cwd: root, encoding: 'utf8' }));
	assert.deepEqual(Object.keys(registered).filter(name => name.startsWith('MetaCube-')), THEME_NAMES.map(name => 'MetaCube-' + name));
	for (const name of THEME_NAMES) assert.deepEqual(registered['MetaCube-' + name].files, expectedFiles);
});
