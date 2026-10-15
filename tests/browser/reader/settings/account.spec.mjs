import { test, expect } from '../../fixtures/reader.fixture.mjs';
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';

let php = process.env.FRESHRSS_TEST_PHP || 'php';
if (!process.env.FRESHRSS_TEST_PHP) {
	try { execFileSync(php, ['-v'], { stdio: 'ignore' }); } catch {
		if (existsSync(resolve('bin/php'))) php = resolve('bin/php');
	}
}
const schema = JSON.parse(execFileSync(php, ['-r', `
final class FreshRSS_Feed { public const TTL_DEFAULT = 1200; public const TTL_MAX = 604800; }
require 'tests/bootstrap.php';
echo json_encode(['fields' => FreshRSS\\Services\\Reader\\SettingsService::fields(), 'atomic' => true], JSON_THROW_ON_ERROR);
`], { encoding: 'utf8' }));

async function openAccountSettings(page, origin, { duration = 'P3M' } = {}) {
	const html = `<!doctype html><html lang="zh-CN"><head><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/styles/reader/reader.css"><link rel="stylesheet" href="/styles/reader/themes.css">
<link rel="stylesheet" href="/styles/reader/settings.css"></head><body data-theme="nord">
<main id="root" class="settings-main"><nav id="nav">
${['reading', 'filters', 'storage', 'privacy', 'automation'].map(section => `<button data-section="${section}">${section}</button>`).join('')}
</nav>${['reading', 'filters', 'storage', 'privacy', 'automation'].map(section => `<section data-account-settings="${section}" hidden></section>`).join('')}
</main><script type="module">
import { AccountSettings } from '/scripts/reader/settings/account.js';
const schema = ${JSON.stringify(schema)};
const values = Object.fromEntries(Object.entries(schema.fields).map(([key, spec]) => [key,
 spec.type === 'boolean' ? true : spec.type === 'string_list' ? [] : spec.type === 'duration' ? ${JSON.stringify(duration)} : spec.options?.[0] ?? 20]));
window.accountWrites = [];
const api = { request: async (action, params, options = {}) => {
 if (options.body) {
  window.accountWrites.push(options.body.values);
  if (window.accountWait) await new Promise(resolve => { window.accountPending = true; window.accountResume = resolve; });
  if (window.accountRejectField && Object.hasOwn(options.body.values, window.accountRejectField)) {
   const error = new Error('field_out_of_range'); error.status = 400; error.details = { field: window.accountRejectField }; throw error;
  }
  if (options.body.values['filters.read']?.some(value => value.includes('('))) {
   const error = new Error('invalid_filter'); error.status = 400; error.details = { field: 'filters.read' }; throw error;
  }
  Object.assign(values, options.body.values);
 }
 return { schema, values: { ...values } };
}};
const element = (tag, className, text) => { const node = document.createElement(tag); if (className) node.className = className;
 if (text !== undefined) node.textContent = text; return node; };
const root = document.getElementById('root');
const settings = new AccountSettings({ api, bootstrap: { language: 'zh-CN' }, root, element }).mount();
const activate = async section => {
 root.querySelectorAll('[data-account-settings]').forEach(node => { node.hidden = node.dataset.accountSettings !== section; });
 await settings.activate(section);
};
root.querySelector('#nav').addEventListener('click', event => { if (event.target.dataset.section) activate(event.target.dataset.section); });
await activate('reading'); window.accountReady = true;
</script></body></html>`;
	await page.route(origin + '/account-settings', route => route.fulfill({ body: html, contentType: 'text/html; charset=utf-8' }));
	await page.goto(origin + '/account-settings');
	await expect.poll(() => page.evaluate(() => window.accountReady)).toBe(true);
}

test('account forms render all real schema fields with accessible labels and preserve drafts across sections', async ({ page, readerServer }) => {
	await openAccountSettings(page, readerServer.origin);
	await expect(page.locator('[data-account-field]')).toHaveCount(Object.keys(schema.fields).length);
	await expect(page.getByRole('switch', { name: '打开文章时标为已读', exact: true })).toBeChecked();
	const target = page.locator('[data-account-field="mark_when.article"] .account-settings-switch-target');
	const touchArea = await target.boundingBox();
	expect(touchArea.width).toBeGreaterThanOrEqual(44); expect(touchArea.height).toBeGreaterThanOrEqual(44);
	await target.click({ position: { x: 2, y: 2 } });
	await expect(page.getByRole('switch', { name: '打开文章时标为已读', exact: true })).not.toBeChecked();
	await page.locator('[data-section="storage"]').click();
	await page.getByRole('spinbutton', { name: '每个订阅最多保留', exact: true }).fill('300');
	await page.locator('[data-section="reading"]').click();
	await expect(page.getByRole('switch', { name: '打开文章时标为已读', exact: true })).not.toBeChecked();
	await page.getByRole('button', { name: '保存更改', exact: true }).click();
	await expect(page.locator('[data-account-section="reading"] .account-settings-status')).toHaveText('已保存');
	expect(await page.evaluate(() => window.accountWrites)).toEqual([{ 'mark_when.article': false }]);
	await page.locator('[data-section="storage"]').click();
	await expect(page.getByRole('spinbutton', { name: '每个订阅最多保留', exact: true })).toHaveValue('300');
	await page.getByRole('button', { name: '还原更改', exact: true }).click();
	await expect(page.getByRole('spinbutton', { name: '每个订阅最多保留', exact: true })).toHaveValue('20');
	await expect(page.getByRole('button', { name: '保存更改', exact: true })).toBeDisabled();
});

test('reading settings show shared behaviors first and keep classic controls available inside collapsed groups', async ({ page, readerServer }) => {
	await openAccountSettings(page, readerServer.origin);
	const reading = page.locator('[data-account-section="reading"]');
	await expect(reading.locator('section[data-account-group="reading"] [data-account-field]')).toHaveCount(3);
	await expect(reading.locator('details')).toHaveCount(4);
	await expect(reading.locator('details[open]')).toHaveCount(0);
	const classic = reading.locator('[data-account-group="classic_reading"]');
	await expect(classic.locator('summary')).toHaveText('经典界面的阅读设置');
	await expect(page.getByRole('spinbutton', { name: '每页文章数', exact: true })).toHaveCount(0);
	await classic.locator('summary').click();
	const count = page.getByRole('spinbutton', { name: '每页文章数', exact: true });
	await count.fill('25');
	await classic.locator('summary').click();
	await page.locator('[data-section="privacy"]').click();
	await page.locator('[data-section="reading"]').click();
	await expect(classic).not.toHaveAttribute('open');
	await classic.locator('summary').click();
	await expect(count).toHaveValue('25');
	await classic.locator('summary').click();
	await page.getByRole('button', { name: '保存更改', exact: true }).click();
	await expect(reading.locator('.account-settings-status')).toHaveText('已保存');
	expect(await page.evaluate(() => window.accountWrites.at(-1))).toEqual({ posts_per_page: 25 });
	await expect(classic).not.toHaveAttribute('open');
});

test('an invalid hidden control expands its visible reading group and receives focus without losing the draft', async ({ page, readerServer }) => {
	await openAccountSettings(page, readerServer.origin);
	const classic = page.locator('[data-account-group="classic_reading"]');
	await classic.locator('summary').click();
	const count = page.getByRole('spinbutton', { name: '每页文章数', exact: true });
	await count.fill('0');
	await classic.locator('summary').click();
	await page.getByRole('button', { name: '保存更改', exact: true }).click();
	await expect(classic).toHaveAttribute('open', '');
	await expect(count).toBeFocused();
	await expect(count).toHaveAttribute('aria-invalid', 'true');
	await expect(count).toHaveValue('0');
	expect(await page.evaluate(() => window.accountWrites)).toEqual([]);
	await page.getByRole('button', { name: '还原更改', exact: true }).click();
	await expect(count).toHaveValue('20');
	await expect(page.getByRole('button', { name: '保存更改', exact: true })).toBeDisabled();
});

test('a server error expands a collapsed group only while its category is still visible', async ({ page, readerServer }) => {
	await openAccountSettings(page, readerServer.origin);
	const classic = page.locator('[data-account-group="classic_reading"]');
	const field = page.locator('[data-account-field="posts_per_page"]');
	await page.evaluate(() => { window.accountRejectField = 'posts_per_page'; });
	await classic.locator('summary').click();
	const count = page.getByRole('spinbutton', { name: '每页文章数', exact: true });
	await count.fill('25');
	await classic.locator('summary').click();
	await page.getByRole('button', { name: '保存更改', exact: true }).click();
	await expect(classic).toHaveAttribute('open', '');
	await expect(count).toBeFocused();
	await expect(count).toHaveValue('25');
	await count.fill('30');
	await classic.locator('summary').click();
	await page.evaluate(() => { window.accountWait = true; });
	await page.getByRole('button', { name: '保存更改', exact: true }).click();
	await expect.poll(() => page.evaluate(() => window.accountPending)).toBe(true);
	const privacy = page.locator('[data-section="privacy"]');
	await privacy.click();
	await page.evaluate(() => { window.accountResume(); });
	await expect(field.locator('.account-settings-error')).toHaveText('保存失败，修改仍保留。');
	await expect(classic).not.toHaveAttribute('open');
	await expect(privacy).toBeFocused();
	await page.locator('[data-section="reading"]').click();
	await classic.locator('summary').click();
	await expect(count).toHaveValue('30');
	await expect(count).toHaveAttribute('aria-invalid', 'true');
	await expect(page.getByRole('button', { name: '保存更改', exact: true })).toBeEnabled();
});

test('retention controls save real disabled and hour-based duration values', async ({ page, readerServer }) => {
	await openAccountSettings(page, readerServer.origin);
	await page.locator('[data-section="storage"]').click();
	const row = page.locator('[data-account-field="archiving.keep_period"]');
	await page.getByRole('checkbox', { name: '启用 文章保留期限', exact: true }).uncheck();
	await expect(row.locator('input[type="number"]')).toBeDisabled();
	await expect(row.locator('select')).toBeDisabled();
	await page.getByRole('button', { name: '保存更改', exact: true }).click();
	await expect(page.locator('[data-account-section="storage"] .account-settings-status')).toHaveText('已保存');
	expect(await page.evaluate(() => window.accountWrites.at(-1))).toEqual({ 'archiving.keep_period': false });
	await page.getByRole('checkbox', { name: '启用 文章保留期限', exact: true }).check();
	await row.locator('input[type="number"]').fill('12');
	await page.getByRole('combobox', { name: '文章保留期限 单位', exact: true }).selectOption('H');
	await page.getByRole('button', { name: '保存更改', exact: true }).click();
	await expect(page.locator('[data-account-section="storage"] .account-settings-status')).toHaveText('已保存');
	expect(await page.evaluate(() => window.accountWrites.at(-1))).toEqual({ 'archiving.keep_period': 'PT12H' });
});

test('an existing compound retention period stays unchanged when opening settings or saving another field', async ({ page, readerServer }) => {
	await openAccountSettings(page, readerServer.origin, { duration: 'P1DT6H' });
	await page.locator('[data-section="storage"]').click();
	const input = page.getByRole('textbox', { name: '文章保留期限', exact: true });
	const unit = page.getByRole('combobox', { name: '文章保留期限 单位', exact: true });
	const save = page.getByRole('button', { name: '保存更改', exact: true });
	await expect(input).toHaveValue('P1DT6H');
	await expect(unit).toHaveValue('custom');
	await expect(unit.locator('option:checked')).toHaveText('自定义 ISO 8601');
	await expect(save).toBeDisabled();
	await page.getByRole('spinbutton', { name: '每个订阅最多保留', exact: true }).fill('300');
	await save.click();
	await expect(page.locator('[data-account-section="storage"] .account-settings-status')).toHaveText('已保存');
	expect(await page.evaluate(() => window.accountWrites)).toEqual([{ 'archiving.keep_max': 300 }]);
	await expect(input).toHaveValue('P1DT6H');
	await expect(save).toBeDisabled();
	await unit.selectOption('H');
	await expect(page.getByRole('spinbutton', { name: '文章保留期限', exact: true })).toHaveValue('');
	await unit.selectOption('custom');
	await expect(input).toHaveValue('P1DT6H');
	await expect(save).toBeDisabled();
	await input.fill('PT30M');
	await save.click();
	await expect(page.locator('[data-account-section="storage"] .account-settings-status')).toHaveText('已保存');
	expect(await page.evaluate(() => window.accountWrites.at(-1))).toEqual({ 'archiving.keep_period': 'PT30M' });
	await input.fill('P1DT');
	await save.click();
	await expect(input).toBeFocused();
	await expect(input).toHaveAttribute('aria-invalid', 'true');
	await expect(input).toHaveValue('P1DT');
	expect(await page.evaluate(() => window.accountWrites)).toHaveLength(2);
	await page.getByRole('button', { name: '还原更改', exact: true }).click();
	await expect(input).toHaveValue('PT30M');
	await expect(save).toBeDisabled();
});

test('filter errors stay beside their field and clearing a filter sends an empty list', async ({ page, readerServer }) => {
	await openAccountSettings(page, readerServer.origin);
	await page.locator('[data-section="filters"]').click();
	const input = page.getByRole('textbox', { name: '自动标为已读的条件', exact: true });
	await input.fill('intitle:(');
	await page.getByRole('button', { name: '保存更改', exact: true }).click();
	await expect(input).toHaveAttribute('aria-invalid', 'true');
	await expect(input).toBeFocused();
	await expect(page.locator('[data-account-field="filters.read"] .account-settings-error')).toHaveText('过滤条件的语法有误。');
	await input.fill('intitle:news\nauthor:Alice');
	await page.getByRole('button', { name: '保存更改', exact: true }).click();
	await expect(page.locator('[data-account-section="filters"] .account-settings-status')).toHaveText('已保存');
	await input.fill('');
	await page.getByRole('button', { name: '保存更改', exact: true }).click();
	await expect(page.locator('[data-account-section="filters"] .account-settings-status')).toHaveText('已保存');
	expect(await page.evaluate(() => window.accountWrites.at(-1))).toEqual({ 'filters.read': [] });
});
