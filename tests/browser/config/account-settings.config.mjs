import { browserConfig } from './base.mjs';

export default browserConfig({
	testMatch: '**/reader/settings/account.spec.mjs',
	outputName: 'account-settings',
	projects: [
		{ name: 'desktop', use: { browserName: 'chromium' } },
		{ name: 'phone', use: { browserName: 'chromium', viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true } },
		{ name: 'firefox', use: { browserName: 'firefox' } },
		{ name: 'webkit', use: { browserName: 'webkit' } },
	],
});
