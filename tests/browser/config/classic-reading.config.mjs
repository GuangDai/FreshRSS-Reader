import { browserConfig } from './base.mjs';

export default browserConfig({
	testMatch: '**/classic/reading-interactions.spec.mjs',
	outputName: 'classic-reading',
	use: { viewport: { width: 1440, height: 900 } },
	projects: [
		{ name: 'chromium', use: { browserName: 'chromium' } },
		{ name: 'firefox', use: { browserName: 'firefox' } },
		{ name: 'webkit', use: { browserName: 'webkit' } },
	],
});
