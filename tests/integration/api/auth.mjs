/** Run against a private copy of an existing SQLite fixture, never its original data. */
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { cpSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as delay } from 'node:timers/promises';

const source = process.env.FRESHRSS_API_FIXTURE;
assert.ok(source, 'Set FRESHRSS_API_FIXTURE to an isolated seeded SQLite DATA_PATH containing reader and readerother.');
const php = process.env.FRESHRSS_PHP_BINARY || 'php';
const repository = fileURLToPath(new URL('../../..', import.meta.url));
const temporary = mkdtempSync(join(tmpdir(), 'freshrss-api-auth-'));
const data = join(temporary, 'data');
const password = randomBytes(24).toString('hex');
const port = Number(process.env.FRESHRSS_API_TEST_PORT || 18082);
const origin = `http://127.0.0.1:${port}`;
const staleKey = '0123456789abcdef0123456789abcdef';
const environment = { ...process.env, DATA_PATH: data, FRESHRSS_REPOSITORY: repository, FRESHRSS_FIXTURE_PASSWORD: password };
const feverKey = user => createHash('md5').update(`${user}:${password}`).digest('hex');
let server;
let serverOutput = '';
let assertions = 0;
const check = (condition, message) => { assert.ok(condition, message); assertions++; };

try {
	cpSync(resolve(source), data, { recursive: true, dereference: true });
	execFileSync(php, ['-r', `
		require getenv('FRESHRSS_REPOSITORY') . '/constants.php';
		require LIB_PATH . '/lib_rss.php';
		FreshRSS_Context::initSystem();
		FreshRSS_Context::systemConf()->api_enabled = true;
		FreshRSS_Context::systemConf()->save();
		foreach (['reader', 'readerother'] as $username) {
			FreshRSS_Context::initUser($username);
			$config = FreshRSS_Context::userConf();
			$config->enabled = true;
			$config->apiPasswordHash = password_hash(getenv('FRESHRSS_FIXTURE_PASSWORD'), PASSWORD_BCRYPT);
			$key = FreshRSS_fever_Util::updateKey($username, getenv('FRESHRSS_FIXTURE_PASSWORD'));
			if ($key === false) { throw new RuntimeException('Cannot create fixture Fever key'); }
			$config->feverKey = $key;
			$config->save();
		}
		file_put_contents(FreshRSS_fever_Util::getKeyPath('${staleKey}'), 'reader');
	`], { cwd: repository, env: environment, stdio: 'pipe' });
	server = spawn(php, ['-d', 'display_errors=Off', '-d', 'log_errors=On', '-S', `127.0.0.1:${port}`, '-t', 'p'], {
		cwd: repository, env: environment, stdio: ['ignore', 'pipe', 'pipe'],
	});
	for (const stream of [server.stdout, server.stderr]) {
		stream.on('data', chunk => { serverOutput = (serverOutput + chunk).slice(-16000); });
	}
	for (let attempt = 0; attempt < 50; attempt++) {
		if (server.exitCode !== null || server.signalCode !== null) throw new Error(`Fixture server stopped: ${serverOutput}`);
		try { await fetch(`${origin}/api/greader.php`); break; } catch { await delay(100); }
	}

	async function fever(fields = {}, user = 'reader', key = feverKey(user)) {
		const response = await fetch(`${origin}/api/fever.php?api`, { method: 'POST', body: new URLSearchParams({ api_key: key, ...fields }) });
		check(response.status === 200, `Fever status ${response.status}`);
		return response.json();
	}

	const otherBaseline = await fever({ items: '', since_id: '0' }, 'readerother');
	check(otherBaseline.auth === 1 && otherBaseline.total_items === 0, 'Fixture readerother must start empty for isolation checks');
	const first = await fever({ items: '', since_id: '0', feeds: '', groups: '', links: '' });
	check(first.auth === 1 && first.api_version === 4, 'Fever authenticates with generated key');
	check(first.items.length > 1 && first.items.length <= 50, 'Fever returns a bounded item page');
	check(Array.isArray(first.feeds) && Array.isArray(first.groups), 'Fever feeds and groups');
	check(Array.isArray(first.links) && first.links.length === 0, 'Fever links keeps its documented empty-list behaviour');
	const ids = new Set();
	let cursor = '0';
	let forwardPages = 0;
	for (;;) {
		const page = await fever({ items: '', since_id: cursor });
		if (!page.items.length) break;
		forwardPages++;
		for (const entry of page.items) {
			check(BigInt(entry.id) > BigInt(cursor), 'Fever since_id is exclusive and ascending');
			check(!ids.has(entry.id), 'Fever pagination has no duplicate IDs');
			ids.add(entry.id);
			cursor = entry.id;
		}
	}
	check(ids.size === first.total_items, 'Fever forward pagination covers every seeded article');
	const reverseIds = new Set();
	cursor = (BigInt(cursor) + 1n).toString();
	let reversePages = 0;
	for (;;) {
		const page = await fever({ items: '', max_id: cursor });
		if (!page.items.length) break;
		reversePages++;
		for (const entry of page.items) {
			check(BigInt(entry.id) < BigInt(cursor), 'Fever max_id is exclusive and descending');
			check(ids.has(entry.id) && !reverseIds.has(entry.id), 'Fever reverse pagination preserves ID set');
			reverseIds.add(entry.id);
			cursor = entry.id;
		}
	}
	check(reverseIds.size === ids.size, 'Fever reverse pagination covers every seeded article');

	const largeFilter = await fever({
		items: '',
		with_ids: [...ids].slice(0, 1000).join(','),
		feed_ids: Array.from({ length: 1000 }, (_, index) => String(index + 1)).join(',')
	});
	check(largeFilter.items.length === 50, 'Fever accepts bounded intersecting filters beyond the old placeholder ceiling');
	const selected = first.items.slice(0, 2).map(entry => entry.id);
	for (const [state, field, value] of [['read', 'is_read', 1], ['unread', 'is_read', 0], ['saved', 'is_saved', 1], ['unsaved', 'is_saved', 0]]) {
		const changed = await fever({ mark: 'item', as: state, with_ids: selected.join(',') });
		check(changed.auth === 1, `Fever batch ${state} authenticates`);
		const after = await fever({ items: '', with_ids: selected.join(',') });
		check(after.items.length === selected.length && after.items.every(entry => entry[field] === value), `Fever batch ${state} persisted`);
	}
	await fever({ mark: 'item', as: 'saved', id: selected[0] });
	check((await fever({ items: '', with_ids: selected[0] })).items[0].is_saved === 1, 'Fever single-item mark persists');
	const intersection = await fever({ items: '', with_ids: selected.join(','), feed_ids: String(first.items[0].feed_id) });
	check(intersection.items.every(entry => entry.feed_id === first.items[0].feed_id), 'Fever intersects feed and entry filters');

	for (const key of ['00000000000000000000000000000000', staleKey]) {
		const denied = await fever({ items: '', mark: 'item', as: 'read', id: selected[0] }, 'reader', key);
		check(denied.auth === 0 && denied.items === undefined, 'Invalid or stale Fever key exposes no articles');
	}
	const other = await fever({ items: '', with_ids: selected.join(','), mark: 'item', as: 'read', id: selected[0] }, 'readerother');
	check(other.auth === 1 && other.items.length === 0, 'A valid second user cannot read first-user IDs');
	check((await fever({ items: '', with_ids: selected[0] })).items[0].is_read === 0, 'Invalid and other-user writes cannot change first-user state');

	async function greaderLogin(user) {
		const response = await fetch(`${origin}/api/greader.php/accounts/ClientLogin`, {
			method: 'POST', body: new URLSearchParams({ Email: user, Passwd: password }),
		});
		check(response.status === 200, 'GReader ClientLogin succeeds');
		const token = (await response.text()).match(/^Auth=(.+)$/m)?.[1];
		check(Boolean(token), 'GReader provides existing Auth token');
		return { Authorization: `GoogleLogin auth=${token}` };
	}
	const auth = await greaderLogin('reader');
	for (const [path, field] of [['subscription/list', 'subscriptions'], ['tag/list', 'tags'], ['unread-count', 'unreadcounts']]) {
		const response = await fetch(`${origin}/api/greader.php/reader/api/0/${path}?output=json`, { headers: auth });
		check(response.status === 200 && Array.isArray((await response.json())[field]), `GReader ${path} remains compatible`);
	}
	const greaderIds = new Set();
	let continuation = '';
	let greaderPages = 0;
	do {
		const query = new URLSearchParams({ n: '73', ...(continuation ? { c: continuation } : {}) });
		const response = await fetch(`${origin}/api/greader.php/reader/api/0/stream/contents/user/-/state/com.google/reading-list?${query}`, { headers: auth });
		check(response.status === 200, 'GReader normal listing succeeds');
		const page = await response.json();
		check(page.items.length <= 73, 'GReader respects requested page size');
		greaderPages++;
		for (const entry of page.items) {
			check(!greaderIds.has(entry.id), 'GReader continuation has no duplicate IDs');
			greaderIds.add(entry.id);
		}
		continuation = page.continuation || '';
	} while (continuation);
	check(greaderIds.size === ids.size, 'GReader continuation covers same articles as Fever');
	const tokenResponse = await fetch(`${origin}/api/greader.php/reader/api/0/token`, { headers: auth });
	const writeToken = (await tokenResponse.text()).trim();
	check(writeToken.length === 57, 'GReader write token remains compatible');
	const edit = new URLSearchParams({ T: writeToken, a: 'user/-/state/com.google/starred' });
	for (const id of selected) edit.append('i', id);
	const written = await fetch(`${origin}/api/greader.php/reader/api/0/edit-tag`, { method: 'POST', headers: auth, body: edit });
	check(written.status === 200 && (await written.text()).trim() === 'OK', 'GReader repeated-ID write succeeds');
	check((await fever({ items: '', with_ids: selected.join(',') })).items.every(entry => entry.is_saved === 1), 'Fever observes GReader batch writes');
	const contents = new URLSearchParams();
	contents.append('i', selected[0]);
	contents.append('i', `tag:google.com,2005:reader/item/${BigInt(selected[1]).toString(16).padStart(16, '0')}`);
	const contentResponse = await fetch(`${origin}/api/greader.php/reader/api/0/stream/items/contents`, { method: 'POST', headers: auth, body: contents });
	check(contentResponse.status === 200 && (await contentResponse.json()).items.length === 2, 'GReader repeated-ID contents remains compatible');
	const otherAuth = await greaderLogin('readerother');
	const isolated = await fetch(`${origin}/api/greader.php/reader/api/0/stream/items/contents`, { method: 'POST', headers: otherAuth, body: contents });
	check((await isolated.json()).items.length === 0, 'GReader also isolates authenticated users');

	console.log(JSON.stringify({
		assertions,
		articles: ids.size,
		feverForwardPages: forwardPages,
		feverReversePages: reversePages,
		greaderPages,
		fever: 'auth, feeds, groups, links, item states, pagination, stale keys and isolation passed',
		greader: 'login, listing, continuation, write token, repeated fields and isolation passed'
	}));
} catch (error) {
	console.error(serverOutput);
	throw error;
} finally {
	if (server && server.exitCode === null && server.signalCode === null) {
		const stopped = new Promise(resolve => server.once('exit', resolve));
		server.kill('SIGTERM');
		await stopped;
	}
	rmSync(temporary, { recursive: true, force: true });
}
