// @ts-check
/**
 * SH Speed Optimizer specific E2E helpers.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { expect } = require( '@playwright/test' );
const { wpCli, E2E_DIR } = require( './wp' );

const PLUGIN = 'sh-speed-optimizer';

/**
 * Activate the plugin if needed.
 */
function activate() {
	const active = wpCli( [ 'plugin', 'list', '--status=active', '--field=name', `--skip-plugins=${ PLUGIN }` ] ).split( /\s+/ );
	if ( ! active.includes( PLUGIN ) ) {
		wpCli( [ 'plugin', 'activate', PLUGIN ] );
	}
}

/**
 * Remove every optimization and all plugin state (fresh-install behaviour).
 */
function reset() {
	activate();
	wpCli( [ 'shso', 'reset', '--yes' ] );
	wpCli( [
		'eval',
		'foreach ( array( "shso_state", "shso_scan", "shso_snapshots", "shso_page_data", "shso_job", "shso_job_lock", "shso_settings", "shso_critical_css", "shso_psi_history" ) as $o ) { delete_option( $o ); } global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}shso_log" );',
	] );
	wpCli( [ 'shso', 'purge' ] );
}

/**
 * Engine state.
 *
 * @returns {{active: Record<string, any>, disabled: Record<string, any>, onboarding: string}}
 */
function state() {
	return JSON.parse( wpCli( [ 'eval', 'echo wp_json_encode( get_option( "shso_state" ) );' ] ) || '{}' );
}

/**
 * Active optimization ids.
 *
 * @returns {string[]}
 */
function activeIds() {
	return Object.keys( ( state() || {} ).active || {} );
}

/**
 * Update settings.
 *
 * @param {Record<string, any>} settings
 */
function setSettings( settings ) {
	wpCli( [ 'eval', `\\SH\\SpeedOptimizer\\Core\\Plugin::instance()->settings()->update( json_decode( '${ JSON.stringify( settings ).replace( /'/g, "\\'" ) }', true ) );` ] );
}

/**
 * Server-side optimization via WP-CLI (no browser checks).
 */
function optimizeCli() {
	return wpCli( [ 'shso', 'optimize' ], { timeout: 10 * 60 * 1000 } );
}

/**
 * Make sure some optimizations are active (runs the server-side optimizer when none are).
 */
function ensureOptimized() {
	activate();
	if ( activeIds().length === 0 ) {
		optimizeCli();
	}
	expect( activeIds() ).toContain( 'page_cache' );
}

/**
 * Purge the page cache.
 */
function purge() {
	wpCli( [ 'shso', 'purge' ] );
}

/**
 * Install the "breaker" test fixture (a script that breaks when deferred).
 */
function installBreaker() {
	const mu = path.join( E2E_DIR, 'wp-content', 'mu-plugins' );
	fs.mkdirSync( mu, { recursive: true } );
	const fixtures = path.join( __dirname, '..', 'fixtures' );
	fs.copyFileSync( path.join( fixtures, 'shso-e2e-breaker.php' ), path.join( mu, 'shso-e2e-breaker.php' ) );
	fs.copyFileSync( path.join( fixtures, 'shso-e2e-lib.js' ), path.join( mu, 'shso-e2e-lib.js' ) );
	wpCli( [ 'option', 'update', 'shso_e2e_breaker', '1' ] );
}

/**
 * Remove the breaker fixture.
 */
function removeBreaker() {
	const mu = path.join( E2E_DIR, 'wp-content', 'mu-plugins' );
	for ( const file of [ 'shso-e2e-breaker.php', 'shso-e2e-lib.js' ] ) {
		try {
			fs.unlinkSync( path.join( mu, file ) );
		} catch ( e ) {}
	}
	try {
		wpCli( [ 'option', 'delete', 'shso_e2e_breaker' ] );
	} catch ( e ) {}
}

/**
 * GET a URL without cookies and return status, cache header and body.
 *
 * @param {import('@playwright/test').APIRequestContext} request
 * @param {string} url
 * @param {Record<string,string>} [headers]
 */
async function fetchPage( request, url, headers = {} ) {
	const res = await request.get( url, { headers, failOnStatusCode: false, maxRedirects: 0 } );
	return {
		status: res.status(),
		cache: res.headers()[ 'x-shso-cache' ] || null,
		body: await res.text(),
		headers: res.headers(),
	};
}

/**
 * Call the plugin REST API from an admin page (uses the wp_rest nonce of the page).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} route e.g. '/shso/v1/status'
 * @param {string} [method]
 * @param {any} [data]
 */
async function api( page, route, method = 'GET', data = undefined ) {
	return page.evaluate(
		async ( args ) => {
			// @ts-ignore
			return window.wp.apiFetch( { path: args.route, method: args.method, data: args.data } );
		},
		{ route, method, data }
	);
}

/**
 * Wait until the job progress dialog shows its Close button (job ended).
 *
 * @param {import('@playwright/test').Page} page
 * @param {number} timeout
 */
async function waitForJobEnd( page, timeout ) {
	await expect( page.locator( '#shso-job-modal button', { hasText: 'Close' } ) ).toBeVisible( { timeout } );
}

module.exports = {
	PLUGIN,
	activate,
	reset,
	state,
	activeIds,
	setSettings,
	optimizeCli,
	ensureOptimized,
	purge,
	installBreaker,
	removeBreaker,
	fetchPage,
	api,
	waitForJobEnd,
};
