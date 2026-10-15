import { test, expect } from '@playwright/test';

test.beforeEach(async ({ page }) => {
	test.skip(!process.env.FRESHRSS_TEST_USER || !process.env.FRESHRSS_TEST_PASSWORD, 'Requires the isolated UI review account.');
	await page.goto('./?c=auth&a=login');
	await page.locator('[name=username]').fill(process.env.FRESHRSS_TEST_USER);
	await page.locator('input[type=password]').fill(process.env.FRESHRSS_TEST_PASSWORD);
	await Promise.all([
		page.waitForURL(url => url.searchParams.get('c') !== 'auth'),
		page.locator('button[type=submit]').click(),
	]);
});

async function embed(page, query = '?c=configure&a=shortcut') {
	await page.goto('./?c=configure&a=reading');
	await page.evaluate(query => {
		document.body.replaceChildren();
		window.embedMessages = [];
		const frame = document.createElement('iframe');
		frame.id = 'tool-frame';
		frame.setAttribute('sandbox', 'allow-same-origin allow-scripts allow-forms allow-modals allow-popups allow-popups-to-escape-sandbox');
		frame.style.cssText = 'display:block;width:100%;height:700px;border:0';
		const url = new URL(query, window.location.href);
		url.searchParams.set('readerSettings', '1');
		frame.src = url.href;
		window.addEventListener('message', event => {
			if (event.origin === window.location.origin && event.source === frame.contentWindow && event.data?.type === 'reader-settings-embed') {
				window.embedMessages.push(event.data);
			}
		});
		document.body.append(frame);
	}, query);
	await expect.poll(() => page.evaluate(() => window.embedMessages.some(message => message.event === 'ready'))).toBe(true);
	return page.frameLocator('#tool-frame');
}

test('only authenticated configuration pages receive same-origin frame permission', async ({ page, browser }) => {
	const normal = await page.request.get('./?c=configure&a=shortcut');
	expect(normal.headers()['content-security-policy']).toContain("frame-ancestors 'none'");
	const embedded = await page.request.get('./?c=configure&a=shortcut&readerSettings=1');
	expect(embedded.headers()['content-security-policy']).toContain("frame-ancestors 'self'");
	const reading = await page.request.get('./?c=index&a=normal&readerSettings=1');
	expect(reading.headers()['content-security-policy']).toContain("frame-ancestors 'none'");
	const anonymous = await browser.newContext();
	try {
		const response = await anonymous.request.get(new URL('./?c=configure&a=shortcut&readerSettings=1', page.url()).href);
		expect(response.headers()['content-security-policy']).not.toContain("frame-ancestors 'self'");
	} finally {
		await anonymous.close();
	}
});

test('embedded tools retain their original form and CSRF without duplicate navigation', async ({ page }) => {
	const frame = await embed(page);
	await expect(frame.locator('html')).toHaveClass(/reader-settings-embedded/);
	await expect(frame.locator('.header')).toHaveCount(0);
	await expect(frame.locator('.aside')).toBeHidden();
	await expect(frame.locator('.post > h1')).toBeHidden();
	await expect(frame.locator('.post > form:first-of-type input[name=_csrf]')).toHaveCount(1);
	await expect(frame.locator('.post > form:first-of-type')).toHaveAttribute('action', /readerSettings=1/);
	await expect(frame.locator('button[type=submit]').first()).toBeVisible();
	await expect.poll(() => page.evaluate(() => window.embedMessages.some(message => message.event === 'resize' && message.height > 100))).toBe(true);
	const messages = await page.evaluate(() => window.embedMessages);
	for (const message of messages) {
		expect(Object.keys(message).every(key => ['type', 'event', 'title', 'route', 'dirty', 'height'].includes(key))).toBe(true);
	}
});

test('saving an unchanged original form keeps the presentation flag after POST redirect', async ({ page }) => {
	const frame = await embed(page);
	const navigation = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).searchParams.get('a') === 'shortcut');
	await frame.locator('button[type=submit]').first().click();
	const response = await navigation;
	expect(new URL(response.url()).searchParams.get('readerSettings')).toBe('1');
	await expect.poll(() => page.frames().find(frame => frame.parentFrame() && frame.url().includes('a=shortcut'))?.url() || '')
		.toContain('readerSettings=1');
	await expect(frame.locator('html')).toHaveClass(/reader-settings-embedded/);
	await expect(frame.locator('.header')).toHaveCount(0);
});

test('GET filtering retains controller, action and embedding flag', async ({ page }) => {
	const frame = await embed(page, '?c=index&a=logs');
	await expect(frame.locator('form[name=log_search] input[name=readerSettings]')).toHaveValue('1');
	await frame.locator('#log-search').fill('example');
	await Promise.all([
		page.waitForResponse(response => {
			const url = new URL(response.url());
			return url.searchParams.get('a') === 'logs' && url.searchParams.get('search') === 'example';
		}),
		frame.locator('form[name=log_search] button').click(),
	]);
	await expect(frame.locator('html')).toHaveClass(/reader-settings-embedded/);
	await expect(frame.locator('#log-search')).toHaveValue('example');
});

test('links outside configuration are sent to the parent without blanking the tool', async ({ page }) => {
	const frame = await embed(page);
	await frame.locator('body').evaluate(body => {
		const link = document.createElement('a');
		link.id = 'return-reading';
		link.href = '?c=reader&view=settings';
		link.textContent = 'Return';
		body.append(link);
	});
	await frame.locator('#return-reading').click();
	await expect.poll(() => page.evaluate(() => window.embedMessages.some(message => message.event === 'navigate'))).toBe(true);
	const message = await page.evaluate(() => window.embedMessages.find(message => message.event === 'navigate'));
	expect(new URL(message.url).searchParams.has('readerSettings')).toBe(false);
	await expect(frame.locator('button[type=submit]').first()).toBeVisible();
});

test('configuration tools fit a phone width and preserve operable controls', async ({ page }) => {
	await page.setViewportSize({ width: 320, height: 700 });
	const frame = await embed(page, '?c=configure&a=reading');
	await page.locator('#tool-frame').evaluate(node => { node.style.width = 'calc(100% - 34px)'; });
	const geometry = await frame.locator('html').evaluate(html => ({ width: window.innerWidth, scrollWidth: html.scrollWidth }));
	expect(geometry.scrollWidth).toBeLessThanOrEqual(geometry.width + 1);
	await expect(frame.locator('#posts_per_page')).toBeVisible();
	await frame.locator('button[type=submit]').click({ trial: true });
});

test('expanding an existing panel reports the new content height', async ({ page }) => {
	const frame = await embed(page, '?c=configure&a=reading');
	await frame.locator('.post').evaluate(post => {
		const panel = document.createElement('div');
		panel.id = 'expanding-panel';
		panel.hidden = true;
		panel.style.height = '1000px';
		post.append(panel);
	});
	await expect.poll(() => page.evaluate(() => window.embedMessages.some(message => message.event === 'resize'))).toBe(true);
	const initial = await page.evaluate(() => window.embedMessages.filter(message => message.event === 'resize').at(-1).height);
	await frame.locator('#expanding-panel').evaluate(panel => { panel.hidden = false; });
	await expect.poll(() => page.evaluate(() => window.embedMessages.filter(message => message.event === 'resize').at(-1).height))
		.toBeGreaterThan(initial + 900);
});

test('changing a field and restoring its value clears the dirty state without exposing values', async ({ page }) => {
	const frame = await embed(page);
	const input = frame.locator('#normal_view_shortcut');
	const original = await input.inputValue();
	await input.fill('q');
	await expect.poll(() => page.evaluate(() => window.embedMessages.filter(message => message.event === 'dirty').at(-1)?.dirty)).toBe(true);
	await input.fill(original);
	await expect.poll(() => page.evaluate(() => window.embedMessages.filter(message => message.event === 'dirty').at(-1)?.dirty)).toBe(false);
	const dirtyMessages = await page.evaluate(() => window.embedMessages.filter(message => message.event === 'dirty'));
	expect(dirtyMessages.every(message => !Object.hasOwn(message, 'values') && !Object.hasOwn(message, 'value'))).toBe(true);
});

test('administrative tools retain their permission and reauthentication flow inside settings', async ({ page }) => {
	for (const query of ['?c=user&a=manage', '?c=update&a=index']) {
		const response = await page.request.get(`./${query}&readerSettings=1`);
		test.skip(response.status() === 403, 'Requires an isolated administrator account.');
		expect(response.headers()['content-security-policy']).toContain("frame-ancestors 'self'");
		const frame = await embed(page, query);
		await expect(frame.locator('html')).toHaveClass(/reader-settings-embedded/);
		await expect(frame.locator('.header')).toHaveCount(0);
	}
});

test('internal tool navigation confirms a dirty form once and retains it when cancelled', async ({ page }) => {
	const frame = await embed(page);
	await frame.locator('#normal_view_shortcut').fill('q');
	await frame.locator('.post').evaluate(post => {
		const link = document.createElement('a'); link.id = 'next-tool';
		link.href = '?c=configure&a=privacy'; link.textContent = 'Privacy'; link.className = 'open-slider'; post.append(link);
	});
	page.once('dialog', dialog => dialog.dismiss());
	await frame.locator('#next-tool').click();
	await expect(frame.locator('#normal_view_shortcut')).toHaveValue('q');
	page.once('dialog', dialog => dialog.accept());
	await frame.locator('#next-tool').click();
	await expect(frame.locator('.post > form[action*="a=privacy"]')).toBeVisible();
	await expect(frame.locator('html')).toHaveClass(/reader-settings-embedded/);
});

test('password, token and custom fields only send a dirty flag', async ({ page }) => {
	const frame = await embed(page);
	await frame.locator('.post').evaluate(post => {
		const form = document.createElement('form');
		for (const [name, type] of [['passwordPlain', 'password'], ['token', 'text'], ['customPreference', 'text']]) {
			const input = document.createElement('input'); input.name = name; input.type = type; input.id = 'sensitive-' + name; form.append(input);
		}
		post.append(form);
	});
	await frame.locator('#sensitive-passwordPlain').fill('test-password-value-must-stay-local');
	await frame.locator('#sensitive-token').fill('test-token-value-must-stay-local');
	await frame.locator('#sensitive-customPreference').fill('test-custom-value-must-stay-local');
	await expect.poll(() => page.evaluate(() => window.embedMessages.filter(message => message.event === 'dirty').at(-1)?.dirty)).toBe(true);
	const messages = await page.evaluate(() => JSON.stringify(window.embedMessages));
	expect(messages).not.toContain('value-must-stay-local');
	expect(messages).not.toContain('customPreference');
});

test('native inputs and actions follow the Reader palette in a dark settings page', async ({ page }) => {
	const frame = await embed(page);
	await frame.locator('html').evaluate(html => {
		for (const [key, value] of Object.entries({
			"surface": '#101317',
			"text": '#eef2f7',
			"subtle": '#20262e',
			"muted": '#b6bfcb',
			"line": '#454e5b',
			"accent": '#9bc3ff',
			"primary": '#9bc3ff',
			'on-accent': '#11223e',
			"error": '#ffb4b4',
		})) html.style.setProperty('--reader-settings-' + key, value);
	});
	const colours = await frame.locator('#normal_view_shortcut').evaluate(input => ({
		background: window.getComputedStyle(input).backgroundColor, text: window.getComputedStyle(input).color,
	}));
	expect(colours).toEqual({ background: 'rgb(16, 19, 23)', text: 'rgb(238, 242, 247)' });
	const action = await frame.locator('.post > form:first-of-type button[type=submit]').first().evaluate(button => ({
		background: window.getComputedStyle(button).backgroundColor, text: window.getComputedStyle(button).color,
	}));
	expect(action.background).toBe('rgb(155, 195, 255)');
	expect(action.text).toBe('rgb(17, 34, 62)');
});

test('catalog navigation has a complete embedded document while existing fetch responses stay fragments', async ({ page }) => {
	const response = await page.request.get('./?c=extension&a=catalog&readerSettings=1');
	expect(await response.text()).not.toContain('id="reader-settings-embed-config"');
	const frame = await embed(page, '?c=extension&a=catalog');
	await expect(frame.locator('html')).toHaveClass(/reader-settings-embedded/);
	await expect(frame.locator('.post')).toBeVisible();
});

test('viewing matching articles opens the original results in a separate window', async ({ page }) => {
	const frame = await embed(page, '?c=configure&a=reading');
	const preview = frame.locator('[formaction*="a=viewFilter"]').first();
	await expect(preview).toHaveAttribute('formtarget', '_blank');
	await expect(preview).not.toHaveAttribute('formaction', /readerSettings/);
});

test('returning home after an embedded POST clears the settings URL and restores reading', async ({ page }) => {
	await page.goto('./');
	await page.locator('#open-settings').click();
	const section = page.locator('[data-settings-section="appearance"]');
	if (await section.isVisible()) await section.click();
	else await page.locator('#settings-section-select').selectOption('appearance');
	await page.locator('[data-tool-id="language-timezone"]').click();
	const frame = page.frameLocator('#settings-tool-frame');
	await expect(frame.locator('html')).toHaveClass(/reader-settings-embedded/);
	const response = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).searchParams.get('a') === 'display');
	await frame.locator('button[type=submit]').first().click();
	expect((await response).status()).toBeLessThan(400);
	await expect(page.locator('#settings-tool-status')).toBeHidden();
	await page.locator('#close-settings-tool').click();
	await page.locator('#cancel-settings').click();
	await expect(page.locator('.reader-shell')).toBeVisible();
	await expect(page).not.toHaveURL(/view=settings/);
});

test('feed maintenance errors retain a usable settings page and ordinary requests keep their destination', async ({ page }) => {
	await page.goto('./?c=configure&a=shortcut');
	const csrf = await page.locator('.post input[name=_csrf]').first().inputValue();
	for (const action of ['clearCache', 'reload']) {
		const normal = await page.request.post(`./?c=feed&a=${action}`, { form: { _csrf: csrf, id: '0' }, maxRedirects: 0 });
		expect(normal.status()).toBe(302);
		expect(new URL(normal.headers().location, page.url()).searchParams.has('readerSettings')).toBe(false);
		const embedded = await page.request.post(`./?c=feed&a=${action}&readerSettings=1`, { form: { _csrf: csrf, id: '0' }, maxRedirects: 0 });
		expect(embedded.status()).toBe(302);
		const destination = new URL(embedded.headers().location, page.url());
		expect(destination.searchParams.get('c')).toBe('subscription');
		expect(destination.searchParams.get('readerSettings')).toBe('1');
	}
});
