import { browserConfig } from './base.mjs';

export default browserConfig({
	testMatch: '**/reader/reading-interactions.spec.mjs',
	outputName: 'reading-interactions',
	projects: [
		{ name: 'desktop', use: { browserName: 'chromium' } },
		{ name: 'firefox', use: { browserName: 'firefox' } },
		{ name: 'webkit', use: { browserName: 'webkit' } },
		{ name: 'small', use: { browserName: 'chromium', viewport: { width: 320, height: 568 }, isMobile: true, hasTouch: true } },
		{ name: 'phone', use: { browserName: 'chromium', viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true } },
		{ name: 'landscape', use: { browserName: 'chromium', viewport: { width: 844, height: 390 }, isMobile: true, hasTouch: true } },
	],
});
