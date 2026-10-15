import { test, expect } from '../fixtures/reader.fixture.mjs';

test('the entire reader module graph uses versioned URLs under a nonce protected CSP', async ({ page, readerServer, reader }) => {
	const { origin } = readerServer;
	const response = await page.goto(origin + '/i/');
	await expect(page.locator('.entry-card')).toHaveCount(4);
	const policy = response.headers()['content-security-policy'];
	const scriptPolicy = policy.split(';').find(directive => directive.trim().startsWith('script-src '));
	expect(scriptPolicy).toMatch(/^\s*script-src 'self' 'nonce-[a-f0-9]{32}'$/);
	expect(scriptPolicy).not.toContain('unsafe-inline');
	const map = await page.locator('script[type=importmap]').evaluate(node => ({
		nonce: node.nonce,
		imports: JSON.parse(node.textContent).imports,
	}));
	expect(scriptPolicy).toContain(`'nonce-${map.nonce}'`);
	const modules = await page.evaluate(() => performance.getEntriesByType('resource')
		.map(entry => entry.name).filter(name => new URL(name).pathname.startsWith('/scripts/reader/') && new URL(name).pathname.endsWith('.js')));
	expect(modules.length).toBeGreaterThan(15);
	for (const request of modules) {
		const url = new URL(request);
		expect(url.searchParams.get('v')).toMatch(/^[a-f0-9]{64}$/);
		expect(map.imports['..' + url.pathname]).toBe('..' + url.pathname + url.search);
	}
	await page.evaluate(() => {
		const script = document.createElement('script');
		script.textContent = 'globalThis.untrustedInlineRan = true;';
		document.head.append(script);
	});
	expect(await page.evaluate(() => globalThis.untrustedInlineRan)).toBeUndefined();
});
