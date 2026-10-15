import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ReadingPreloader } from '../../../../p/scripts/reader/core/preloading.js';

function fixture(connection = null) {
	const wanted = [];
	let active = true; let time = 0; let pages = 0;
	const store = {
		query: { get: 'a', filter: 'unread' },
		cursor: '',
		selectedId: '5',
		article: { id: '5' },
		items: Array.from({ length: 20 }, (_, index) => ({ id: String(index) })),
		next: 'next',
		pageSize: 50,
		pageLatency: 500,
		articleLatency: 300,
		prefetch: ids => wanted.push(ids),
	};
	const preloader = new ReadingPreloader(store, { connection, active: () => active, now: () => time, onMore: () => { pages++; } });
	return { store, preloader, wanted, pages: () => pages, setActive: value => { active = value; }, advance: value => { time += value; } };
}

test('reading backwards prepares previous bodies and does not fetch another forward page', () => {
	const f = fixture();
	f.store.selectedId = '18'; f.preloader.update(); f.preloader.run();
	assert.equal(f.pages(), 1);
	f.advance(500); f.store.selectedId = '17'; f.preloader.update(); f.preloader.run();
	assert.deepEqual(f.wanted.at(-1), ['16', '15', '18']);
	assert.equal(f.pages(), 1);
	f.preloader.suspend();
});

test('short page batches fill the viewport, while a stationary long list downloads no more pages or bodies', () => {
	const f = fixture();
	f.store.selectedId = null; f.store.article = null;
	f.preloader.run(); assert.equal(f.pages(), 0); assert.deepEqual(f.wanted.at(-1), []);
	f.store.items = f.store.items.slice(0, 2); f.store.pageSize = 2;
	f.preloader.viewport(7, 0); f.preloader.run(); assert.equal(f.pages(), 1);
	f.preloader.suspend();
});

test('data saving and inactive pages stop speculation, while a real scroll can still request the next batch', () => {
	const f = fixture({ saveData: true });
	f.store.selectedId = '19'; f.preloader.update(); f.preloader.run();
	assert.equal(f.pages(), 0); assert.deepEqual(f.wanted.at(-1), []);
	f.preloader.viewport(6, 0, 0, true); f.preloader.run(); assert.equal(f.pages(), 1);
	f.setActive(false); f.preloader.update();
	assert.equal(f.preloader.timer, null); assert.deepEqual(f.wanted.at(-1), []);
});

test('rapid selections wait for a pause before fetching only the final neighbours', async () => {
	const f = fixture();
	try {
		for (let index = 5; index <= 10; index++) {
			f.store.selectedId = String(index); f.advance(40); f.preloader.update();
		}
		assert.equal(f.wanted.length, 0);
		await new Promise(resolve => setTimeout(resolve, 200));
		assert.equal(f.wanted.length, 1);
		assert.equal(f.wanted[0][0], '11'); assert.ok(f.wanted[0].length <= 5);
		assert.ok(f.wanted[0].every(id => Number(id) >= 9));
	} finally { f.preloader.suspend(); }
});

test('a failed continuation cannot cause an automatic retry loop during later state updates', () => {
	const f = fixture();
	f.store.selectedId = '19'; f.store.moreError = new Error('offline');
	f.preloader.update(); f.preloader.viewport(6, 0, 5, true); f.preloader.run();
	assert.equal(f.pages(), 0);
	f.preloader.suspend();
});
