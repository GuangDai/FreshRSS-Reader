import { defineConfig } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

/** Shared browser defaults; each suite keeps its own viewport and project matrix. */
export function browserConfig({ outputName, use = {}, ...options }) {
	return defineConfig({
		testDir: fileURLToPath(new URL('..', import.meta.url)),
		testMatch: '**/*.spec.mjs',
		workers: 1,
		reporter: 'list',
		...options,
		use: {
			baseURL: process.env.FRESHRSS_TEST_URL || 'http://127.0.0.1:18080/i/',
			viewport: { width: 1440, height: 1000 },
			screenshot: 'only-on-failure',
			...use,
		},
		outputDir: fileURLToPath(new URL(`../../../bin/test-results/browser/${outputName}/`, import.meta.url)),
	});
}

/** Local review credentials remain optional and are never included in reports. */
export function loadReviewEnvironment() {
	const environment = new URL('../../../bin/ui-review.env', import.meta.url);
	if (!existsSync(environment)) return;
	for (const line of readFileSync(environment, 'utf8').split('\n')) {
		const match = line.match(/^(?:export\s+)?([A-Z_][A-Z0-9_]*)=(.*)$/);
		if (!match || process.env[match[1]]) continue;
		process.env[match[1]] = match[2].replace(/^(['"])(.*)\1$/, '$2');
	}
}
