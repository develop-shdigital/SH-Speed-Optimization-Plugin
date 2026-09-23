// @ts-check
const { defineConfig, devices } = require( '@playwright/test' );

/**
 * Playwright configuration for the SH Speed Optimizer E2E suite.
 *
 * The WordPress site is built by bin/e2e-setup.sh and served by
 * bin/e2e-server.sh (see README.md). Tests share one WordPress instance and
 * toggle plugin state through WP-CLI, so they must run serially (workers: 1).
 */
const baseURL = ( process.env.SHSO_E2E_BASE_URL || 'http://127.0.0.1:8889' ).replace( /\/+$/, '' );

module.exports = defineConfig( {
	testDir: './specs',
	outputDir: './test-results',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	forbidOnly: !! process.env.CI,
	reporter: 'list',
	timeout: 90 * 1000,
	expect: {
		timeout: 15 * 1000,
	},
	use: {
		baseURL,
		headless: true,
		viewport: { width: 1280, height: 800 },
		actionTimeout: 15 * 1000,
		navigationTimeout: 45 * 1000,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
		locale: 'en-US',
		timezoneId: 'Europe/Zurich',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ], viewport: { width: 1280, height: 800 } },
		},
	],
} );
