/*
 * Browser tests against a WordPress Playground site, booted from tests/e2e/blueprint.json with this checkout
 * mounted as the plugin. They cover what the PHP suite in tests/wp cannot: the panel's own JavaScript.
 */
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { defineConfig, devices } from '@playwright/test';

import { BASE_URL, PORT, storageState, USERS } from './utils';

const ROOT = path.join(__dirname, '..', '..');

// The same WordPress and PHP as the wp-env site, so the two never test different versions.
const env = JSON.parse(readFileSync(path.join(ROOT, '.wp-env.json'), 'utf8'));
const WP_VERSION = env.core.split('#')[1];

export default defineConfig({
	testDir: __dirname,
	outputDir: path.join(__dirname, 'test-results'),
	// Every test writes to the one Playground site; running them in parallel has not been tried.
	workers: 1,
	forbidOnly: !!process.env.CI,
	reporter: process.env.CI ? 'github' : 'list',
	use: {
		baseURL: BASE_URL,
		trace: 'retain-on-failure',
	},
	projects: [
		{ name: 'setup', testMatch: 'auth.setup.js' },
		{
			name: 'chromium',
			testMatch: '*.spec.js',
			dependencies: ['setup'],
			use: {
				...devices['Desktop Chrome'],
				storageState: storageState(USERS.tester),
			},
		},
	],
	webServer: {
		command: [
			'npx @wp-playground/cli server',
			`--port=${PORT}`,
			`--php=${env.phpVersion}`,
			`--wp=${WP_VERSION}`,
			`--mount="${ROOT}:/wordpress/wp-content/plugins/uat-checklist"`,
			'--blueprint=tests/e2e/blueprint.json',
		].join(' '),
		cwd: ROOT,
		url: `${BASE_URL}/wp-login.php`,
		reuseExistingServer: !process.env.CI,
		// The first boot downloads WordPress.
		timeout: 180_000,
	},
});
