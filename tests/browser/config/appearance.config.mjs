import { browserConfig } from './base.mjs';

export default browserConfig({
	testMatch: '**/reader/appearance.spec.mjs',
	outputName: 'appearance',
	projects: [
		{ name: 'desktop', use: { browserName: 'chromium' } },
		{ name: 'phone', use: { browserName: 'chromium', viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true } },
		{ name: 'landscape', use: { browserName: 'chromium', viewport: { width: 844, height: 390 }, isMobile: true, hasTouch: true } },
	],
});
