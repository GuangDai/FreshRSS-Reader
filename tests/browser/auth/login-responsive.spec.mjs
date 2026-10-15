import { test, expect } from '@playwright/test';

const geometryTolerance = 1 / 64;

for (const scenario of [
	{ width: 320, height: 568, fontSize: '100%' },
	{ width: 568, height: 320, fontSize: '100%' },
	{ width: 320, height: 568, fontSize: '200%' },
	{ width: 568, height: 320, fontSize: '200%' },
]) {
	test(`anonymous login fits ${scenario.width} × ${scenario.height} with ${scenario.fontSize} text`, async ({ page }) => {
		await page.setViewportSize({ width: scenario.width, height: scenario.height });
		await page.goto('./?c=auth&a=login');
		await expect(page.locator('main.prompt')).toBeVisible();
		await page.evaluate(fontSize => { document.documentElement.style.fontSize = fontSize; }, scenario.fontSize);

		// Exercise the site's anonymous theme directly, without replacing styles or logging in.
		const pageSize = await page.evaluate(() => ({ width: window.innerWidth, scrollWidth: document.documentElement.scrollWidth }));
		expect(pageSize.scrollWidth).toBeLessThanOrEqual(pageSize.width);
		const controlsFit = await page.locator('main.prompt input:not([type=hidden]), main.prompt button').evaluateAll(nodes => nodes.every(node => {
			const bounds = node.getBoundingClientRect();
			return bounds.left >= 0 && bounds.right <= window.innerWidth;
		}));
		expect(controlsFit).toBe(true);

		const submit = page.locator('#loginButton');
		await submit.scrollIntoViewIfNeeded();
		await expect(submit).toBeInViewport();
		const bounds = await submit.boundingBox();
		expect(bounds.y).toBeGreaterThanOrEqual(-geometryTolerance);
		expect(bounds.y + bounds.height).toBeLessThanOrEqual(scenario.height + geometryTolerance);
	});
}
