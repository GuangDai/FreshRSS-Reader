import { browserConfig } from './base.mjs';

export default browserConfig({
	testMatch: '**/reader/settings/embed.spec.mjs',
	outputName: 'settings-embed',
	use: { viewport: { width: 1100, height: 800 } },
	projects: [
		{ name: 'chromium', use: { browserName: 'chromium' } },
		{ name: 'firefox', use: { browserName: 'firefox' } },
		{ name: 'webkit', use: { browserName: 'webkit' } },
	],
});
