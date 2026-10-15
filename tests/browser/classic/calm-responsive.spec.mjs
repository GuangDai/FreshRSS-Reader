import { test, expect } from '@playwright/test';

const username = process.env.FRESHRSS_TEST_USER;
const password = process.env.FRESHRSS_TEST_PASSWORD;
const longName = 'VeryLongFeedNameWithoutSpaces'.repeat(12);
const geometryTolerance = 1 / 64;

test.use({ hasTouch: true });

test.beforeEach(async ({ page }) => {
	test.skip(!username || !password, 'Use an isolated local test account.');
	await page.goto('./?c=auth&a=login');
	await page.locator('[name=username]').fill(username);
	await page.locator('input[type=password]').fill(password);
	await Promise.all([page.waitForURL(url => url.searchParams.get('c') !== 'auth'), page.locator('button[type=submit]').click()]);
});

async function useCalm(page, rtl = false) {
	// Load the real theme files without changing the account's saved appearance.
	await page.locator('link[rel=stylesheet][href*="/themes/"]').evaluateAll(nodes => nodes.forEach(node => node.remove()));
	await page.evaluate(direction => { document.documentElement.dir = direction; }, rtl ? 'rtl' : 'ltr');
	for (const file of ['base-theme/frss', 'Origine/origine', 'Calm/calm']) {
		await page.addStyleTag({ url: new URL(`../themes/${file}${rtl ? '.rtl' : ''}.css`, page.url()).href });
	}
}

async function expectPageFits(page) {
	const size = await page.evaluate(() => ({ width: window.innerWidth, scrollWidth: document.documentElement.scrollWidth }));
	expect(size.scrollWidth).toBeLessThanOrEqual(size.width + 1);
}

async function addLongSubscriptionNames(page) {
	await page.locator('.drop-section').evaluate((section, name) => {
		const box = document.createElement('div');
		box.className = 'box';
		box.id = 'calm-long-names';
		box.innerHTML = '<div class="box-title"><a class="configure" href="#" aria-label="Edit category">⚙</a>' +
			'<h2><span class="title"></span></h2></div><ul class="box-content"><li class="item feed">' +
			'<a class="configure" href="#" aria-label="Edit feed">⚙</a><span class="item-title"></span></li></ul>';
		for (const title of box.querySelectorAll('.title, .item-title')) title.textContent = name;
		section.prepend(box);
	}, longName);
}

test('Calm configuration and subscription pages fit phone, landscape, tablet and desktop viewports', async ({ page }) => {
	for (const viewport of [
		{ width: 320, height: 568 }, { width: 568, height: 320 }, { width: 768, height: 1024 },
		{ width: 1024, height: 768 }, { width: 1440, height: 900 },
	]) {
		await page.setViewportSize(viewport);
		for (const route of ['?c=configure&a=display', '?c=configure&a=reading', '?c=subscription', '?c=subscription&a=add', '?']) {
			await test.step(`${viewport.width} × ${viewport.height}: ${route}`, async () => {
				await page.goto('./' + route);
				await useCalm(page);
				await expectPageFits(page);
			});
		}
	}
});

test('long subscription names and enlarged settings text fit in both writing directions', async ({ page }) => {
	await page.setViewportSize({ width: 320, height: 568 });
	for (const rtl of [false, true]) {
		await page.goto('./?c=subscription');
		await useCalm(page, rtl);
		await addLongSubscriptionNames(page);
		await expectPageFits(page);
		const boxesFit = await page.locator('#calm-long-names .box-title, #calm-long-names .box-content')
			.evaluateAll(nodes => nodes.every(node => node.scrollWidth <= node.clientWidth + 1));
		expect(boxesFit).toBe(true);
		await page.goto('./?c=configure&a=reading');
		await useCalm(page, rtl);
		await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
		await expectPageFits(page);
		const menu = page.locator('.header > .configure > .dropdown > .btn');
		const bounds = await menu.boundingBox();
		expect(bounds.x).toBeGreaterThanOrEqual(0);
		expect(bounds.x + bounds.width).toBeLessThanOrEqual(320 + geometryTolerance);
		await menu.click({ trial: true });
	}
});

test('touch controls and checkbox labels remain reachable on phones and touch laptops', async ({ page }) => {
	for (const width of [320, 1024]) {
		await page.setViewportSize({ width, height: 768 });
		await page.goto('./?c=configure&a=reading');
		await useCalm(page);
		for (const selector of ['#view_mode', '#posts_per_page', '.form-actions button[type=submit]']) {
			const target = await page.locator(selector).boundingBox();
			expect(target.height).toBeGreaterThanOrEqual(44 - geometryTolerance);
		}
		const labelHeight = await page.locator('#auto_load_more').evaluate(node => node.closest('label').getBoundingClientRect().height);
		expect(labelHeight).toBeGreaterThanOrEqual(44 - geometryTolerance);
		await expect(page.locator('#view_mode')).toHaveCSS('font-size', '16px');
	}
});

test('short screens keep save actions in flow and allow scrolling to the last sidebar category', async ({ page }) => {
	await page.setViewportSize({ width: 568, height: 320 });
	await page.goto('./?c=configure&a=reading');
	await useCalm(page);
	await expect(page.locator('.form-actions')).toHaveCSS('position', 'static');
	await page.goto('./?');
	await useCalm(page);
	const aside = page.locator('.aside_feed');
	await aside.evaluate(node => {
		node.classList.remove('is-hidden');
		node.classList.add('visible');
		const sidebar = node.querySelector('#sidebar');
		sidebar.replaceChildren();
		for (let i = 0; i < 40; i++) {
			const category = document.createElement('li');
			category.className = 'tree-folder category';
			category.id = `calm-category-${i}`;
			category.innerHTML = '<a class="tree-folder-title" href="#"><span class="title" data-unread="0"></span></a>';
			category.querySelector('.title').textContent = `Long subscription category ${i}`;
			sidebar.append(category);
		}
	});
	await expect(aside).toHaveCSS('height', '320px');
	const lastCategory = page.locator('#calm-category-39 > a');
	await lastCategory.scrollIntoViewIfNeeded();
	await expect(lastCategory).toBeInViewport();
	const target = await lastCategory.boundingBox();
	expect(target.y).toBeGreaterThanOrEqual(0);
	expect(target.y + target.height).toBeLessThanOrEqual(320);
	await expectPageFits(page);
});

test('forced colors preserve the active category and keyboard focus boundaries', async ({ page }) => {
	await page.emulateMedia({ forcedColors: 'active' });
	await page.goto('./?');
	await useCalm(page);
	const active = page.locator('.aside_feed .tree-folder').first();
	await active.evaluate(node => node.classList.add('active'));
	await expect(active.locator('.tree-folder-title')).toHaveCSS('outline-style', 'solid');
	await expect(active.locator('.tree-folder-title')).toHaveCSS('outline-width', '2px');
	await page.goto('./?c=configure&a=reading');
	await useCalm(page);
	await page.locator('#view_mode').focus();
	await expect(page.locator('#view_mode')).toHaveCSS('outline-style', 'solid');
	await expect(page.locator('#view_mode')).toHaveCSS('outline-width', '2px');
});

test('save actions stay legible and long bulk action labels wrap without covering other buttons', async ({ page }) => {
	for (const viewport of [{ width: 320, height: 568 }, { width: 390, height: 844 }, { width: 568, height: 320 }]) {
		await page.setViewportSize(viewport);
		await page.goto('./?c=configure&a=reading');
		await useCalm(page);
		await expect(page.locator('.form-actions')).toHaveCSS('background-color', 'rgb(255, 255, 255)');
		await page.locator('.form-actions .group-controls').evaluate(node => {
			for (const title of ['应用到全部已选择的订阅源', '恢复所选订阅源的默认配置']) {
				const button = document.createElement('button');
				button.type = 'button';
				button.className = 'btn';
				button.textContent = title;
				node.append(button);
			}
		});
		for (const button of await page.locator('.form-actions .btn').all()) {
			await button.click({ trial: true });
			const bounds = await button.boundingBox();
			expect(bounds.x).toBeGreaterThanOrEqual(0);
			expect(bounds.x + bounds.width).toBeLessThanOrEqual(viewport.width + geometryTolerance);
		}
		await expectPageFits(page);
	}
});

test('keyboard focus stays visible inside clipped input groups and primary actions', async ({ page }) => {
	await page.setViewportSize({ width: 390, height: 844 });
	for (const colorScheme of ['light', 'dark']) {
		await page.emulateMedia({ colorScheme });
		await page.goto('./?c=subscription&a=add');
		await useCalm(page);
		await page.evaluate(() => document.documentElement.classList.add('darkMode_auto'));
		await page.locator('#url_rss').focus();
		const focus = await page.locator('#url_rss').evaluate(node => {
			const style = window.getComputedStyle(node);
			const bounds = node.getBoundingClientRect();
			const parent = node.closest('.group-controls').getBoundingClientRect();
			const outset = Math.max(0, parseFloat(style.outlineWidth) + parseFloat(style.outlineOffset));
			return {
				style: style.outlineStyle,
				consistentColor: style.outlineColor === style.borderColor,
				fits: bounds.left - outset >= parent.left && bounds.right + outset <= parent.right &&
					bounds.top - outset >= parent.top && bounds.bottom + outset <= parent.bottom,
			};
		});
		expect(focus).toEqual({ style: 'solid', consistentColor: true, fits: true });
		const submit = page.locator('.form-actions .btn-important').first();
		await submit.focus();
		const contrast = await submit.evaluate(node => {
			const style = window.getComputedStyle(node);
			return { outline: style.outlineColor, background: style.backgroundColor, width: style.outlineWidth };
		});
		expect(contrast.outline).not.toBe(contrast.background);
		expect(contrast.width).toBe('2px');
	}
});

test('subscription controls stay touch sized beside long feed and category names', async ({ page }) => {
	await page.setViewportSize({ width: 320, height: 568 });
	await page.goto('./?c=subscription');
	await useCalm(page);
	await addLongSubscriptionNames(page);
	for (const target of await page.locator('#calm-long-names .configure, .box.visible-semi .box-title > a').all()) {
		await target.scrollIntoViewIfNeeded();
		const bounds = await target.boundingBox();
		expect(bounds.width).toBeGreaterThanOrEqual(44 - geometryTolerance);
		expect(bounds.height).toBeGreaterThanOrEqual(44 - geometryTolerance);
		await target.click({ trial: true });
	}
	await expectPageFits(page);
});

test('Calm theme preview loads its real image and keeps the description below it', async ({ page }) => {
	await page.setViewportSize({ width: 390, height: 844 });
	await page.goto('./?c=configure&a=display');
	await useCalm(page);
	await page.locator('.theme-preview-list').evaluate(node => {
		node.querySelector('.picked')?.classList.remove('picked');
		node.querySelector('[data-theme-preview="Calm"]').classList.add('picked');
	});
	const preview = page.locator('[data-theme-preview="Calm"]');
	const image = preview.locator('img');
	await image.scrollIntoViewIfNeeded();
	await expect.poll(() => image.evaluate(node => node.complete && node.naturalWidth > 0)).toBe(true);
	const bounds = await image.boundingBox();
	const properties = await preview.locator('.properties').boundingBox();
	expect(properties.y).toBeGreaterThanOrEqual(bounds.y + bounds.height - geometryTolerance);
	const ratios = await image.evaluate(node => ({ natural: node.naturalWidth / node.naturalHeight, rendered: node.width / node.height }));
	expect(ratios.rendered).toBeCloseTo(ratios.natural, 1);
	await expectPageFits(page);
});

test('preview loading status, frame and keyboard close fit phone and short landscape screens', async ({ page }) => {
	for (const viewport of [{ width: 320, height: 568 }, { width: 568, height: 320 }]) {
		await page.setViewportSize(viewport);
		await page.goto('./?c=subscription&a=add');
		await useCalm(page);
		// Reuse the feed editor's popup structure and its real handlers without creating a feed.
		await page.evaluate(() => {
			const popup = document.createElement('div');
			popup.id = 'popup';
			popup.innerHTML = '<div id="popup-content"><div id="popup-close" class="popup-row">×</div>' +
				'<div id="popup-iframe-container" class="popup-row"><div id="popup-iframe-sub">' +
				'<iframe id="popup-iframe" frameborder="0"></iframe></div></div></div>';
			document.body.append(popup);
			window.init_popup();
		});
		let release;
		const pending = new Promise(resolve => { release = resolve; });
		await page.route('**/calm-preview-fixture', async route => {
			await pending;
			await route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body><p>Preview fixture</p></body></html>' });
		});
		try {
			await page.locator('#url_rss').focus();
			await page.evaluate(() => window.openPopupWithSource(new URL('calm-preview-fixture', window.location.href).href));
			await expect(page.locator('#popup [role=status]')).toBeVisible();
			await expect(page.locator('#popup [data-panel-retry]')).toBeHidden();
			const close = page.locator('#popup-close');
			const closeBounds = await close.boundingBox();
			expect(closeBounds.width).toBeGreaterThanOrEqual(44 - geometryTolerance);
			expect(closeBounds.height).toBeGreaterThanOrEqual(44 - geometryTolerance);
			const popupBounds = await page.locator('#popup-content').boundingBox();
			expect(popupBounds.y).toBeGreaterThanOrEqual(0);
			expect(popupBounds.y + popupBounds.height).toBeLessThanOrEqual(viewport.height + geometryTolerance);
			release();
			await expect(page.locator('#popup-iframe-container')).toHaveAttribute('aria-busy', 'false');
			await expect(page.locator('#popup .panel-status p')).toBeHidden();
			await expect(page.frameLocator('#popup-iframe').locator('body')).toContainText('Preview fixture');
			const frameBounds = await page.locator('#popup-iframe').boundingBox();
			expect(frameBounds.y + frameBounds.height).toBeLessThanOrEqual(viewport.height + geometryTolerance);
			await close.press('Enter');
			await expect(page.locator('#popup')).toBeHidden();
			await expect(page.locator('#url_rss')).toBeFocused();
		} finally {
			release();
			await page.unroute('**/calm-preview-fixture');
		}
	}
});
