import { browserConfig, loadReviewEnvironment } from './base.mjs';

loadReviewEnvironment();

export default browserConfig({
	testMatch: '**/reader/settings/page.spec.mjs',
	outputName: 'settings-page',
	fullyParallel: false,
	use: { viewport: { width: 1440, height: 900 } },
	projects: [
		{ name: 'desktop', use: { browserName: 'chromium' } },
		{ name: 'phone', use: { browserName: 'chromium', viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true } },
		{ name: 'small', use: { browserName: 'chromium', viewport: { width: 320, height: 568 }, isMobile: true, hasTouch: true } },
		{ name: 'firefox', use: { browserName: 'firefox' } },
		{ name: 'webkit', use: { browserName: 'webkit' } },
	],
});
