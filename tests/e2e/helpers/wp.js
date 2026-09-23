// @ts-check
/**
 * Shared helpers for the SH Speed Optimizer E2E tests.
 *
 * Paths follow bin/e2e-setup.sh:
 *   SHSO_E2E_DIR       WordPress root (default /home/user/shso-e2e/wordpress)
 *   SHSO_E2E_WP_CLI    WP-CLI executable (default <SHSO_E2E_DIR>/../bin/wp)
 *   SHSO_E2E_BASE_URL  Site URL (default http://127.0.0.1:8889)
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { expect } = require( '@playwright/test' );

const E2E_DIR = path.resolve( process.env.SHSO_E2E_DIR || '/home/user/shso-e2e/wordpress' );
const E2E_ROOT = path.dirname( E2E_DIR );
const WP_CLI = process.env.SHSO_E2E_WP_CLI || path.join( E2E_ROOT, 'bin', 'wp' );
const BASE_URL = ( process.env.SHSO_E2E_BASE_URL || 'http://127.0.0.1:8889' ).replace( /\/+$/, '' );
const ADMIN_USER = 'admin';
const ADMIN_PASSWORD = 'admin';

/**
 * Log in as admin/admin through /wp-login.php and wait for the dashboard.
 *
 * @param {import('@playwright/test').Page} page
 */
async function login( page ) {
	await page.goto( '/wp-login.php', { waitUntil: 'domcontentloaded' } );
	await page.locator( '#user_login' ).fill( ADMIN_USER );
	await page.locator( '#user_pass' ).fill( ADMIN_PASSWORD );
	await Promise.all( [
		page.waitForURL( /\/wp-admin\/|action=confirm_admin_email/, { waitUntil: 'domcontentloaded' } ),
		page.locator( '#wp-submit' ).click(),
	] );
	// "Administration email verification" screen (normally disabled by the setup script).
	if ( page.url().includes( 'action=confirm_admin_email' ) ) {
		await Promise.all( [
			page.waitForURL( /\/wp-admin\//, { waitUntil: 'domcontentloaded' } ),
			page.locator( '#correct-admin-email' ).click(),
		] );
	}
	// Plugins may redirect elsewhere after login; always end on the dashboard.
	if ( ! /\/wp-admin\/(index\.php)?$/.test( new URL( page.url() ).pathname ) ) {
		await page.goto( '/wp-admin/', { waitUntil: 'domcontentloaded' } );
	}
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
	await expect( page.locator( '#adminmenu' ) ).toBeVisible();
}

/**
 * Run WP-CLI against the E2E site and return trimmed stdout.
 * Throws (with stderr in the message) when the command fails.
 *
 * @param {string[]|string} args e.g. ['plugin', 'activate', 'sh-speed-optimizer'] or 'plugin list --format=json'
 * @param {{ input?: string, timeout?: number }} [options]
 * @returns {string}
 */
function wpCli( args, options = {} ) {
	const argv = Array.isArray( args ) ? args : splitArgs( args );
	const cmd = [
		'-d', 'memory_limit=1024M',
		'-d', 'display_errors=stderr',
		WP_CLI,
		'--allow-root',
		`--path=${ E2E_DIR }`,
		`--url=${ BASE_URL }`,
		...argv,
	];
	try {
		const out = execFileSync( 'php', cmd, {
			encoding: 'utf8',
			input: options.input,
			timeout: options.timeout || 120 * 1000,
			maxBuffer: 64 * 1024 * 1024,
			env: { ...process.env, WP_CLI_ALLOW_ROOT: '1' },
			stdio: [ 'pipe', 'pipe', 'pipe' ],
		} );
		return out.trim();
	} catch ( error ) {
		const e = /** @type {any} */ ( error );
		const stderr = e.stderr ? String( e.stderr ).trim() : '';
		const stdout = e.stdout ? String( e.stdout ).trim() : '';
		throw new Error( `wp ${ argv.join( ' ' ) } failed (exit ${ e.status }):\n${ stderr || stdout || e.message }` );
	}
}

/**
 * Minimal shell-like splitting for string commands (supports '...' and "...").
 *
 * @param {string} str
 * @returns {string[]}
 */
function splitArgs( str ) {
	const out = [];
	const re = /"((?:\\.|[^"\\])*)"|'([^']*)'|(\S+)/g;
	let m;
	while ( ( m = re.exec( str ) ) ) {
		out.push( m[ 1 ] !== undefined ? m[ 1 ].replace( /\\(.)/g, '$1' ) : m[ 2 ] !== undefined ? m[ 2 ] : m[ 3 ] );
	}
	return out;
}

/**
 * URLs of the test pages written by bin/e2e-setup.sh.
 *
 * @returns {{home: string, blog: string, post: string, contact: string, elementor: string, shop: string, product: string, cart: string, checkout: string, myaccount: string}}
 */
function urls() {
	const file = path.join( E2E_ROOT, 'e2e-urls.json' );
	if ( ! fs.existsSync( file ) ) {
		throw new Error( `${ file } not found - run bin/e2e-setup.sh first.` );
	}
	return JSON.parse( fs.readFileSync( file, 'utf8' ) );
}

/**
 * IDs of the generated content (pages, posts, products, images, CF7 form).
 *
 * @returns {any}
 */
function content() {
	return JSON.parse( fs.readFileSync( path.join( E2E_ROOT, 'e2e-content.json' ), 'utf8' ) );
}

/**
 * Console noise that is caused by the environment, not by the site:
 * third-party resources (YouTube, Google Fonts, Gravatar, s.w.org emoji) that
 * fail to load when outbound HTTPS is blocked or TLS-intercepted.
 * Pass to collectConsoleErrors( page, { ignore: THIRD_PARTY_NETWORK_NOISE } ).
 */
const THIRD_PARTY_NETWORK_NOISE = [
	/^console\.error: Failed to load resource: net::ERR_[A-Z_]+ \((?!https?:\/\/(127\.0\.0\.1|localhost)[:/])/,
];

/**
 * Collect uncaught page errors and console.error() messages.
 * Returns an array that fills over time.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{ ignore?: RegExp[] }} [options] messages matching any pattern are skipped
 * @returns {string[]}
 */
function collectConsoleErrors( page, options = {} ) {
	const ignore = options.ignore || [];
	/** @type {string[]} */
	const errors = [];
	const push = ( /** @type {string} */ msg ) => {
		if ( ! ignore.some( ( re ) => re.test( msg ) ) ) {
			errors.push( msg );
		}
	};
	page.on( 'pageerror', ( err ) => push( `pageerror: ${ err.message }` ) );
	page.on( 'console', ( msg ) => {
		if ( msg.type() === 'error' ) {
			const loc = msg.location();
			push( `console.error: ${ msg.text() }${ loc && loc.url ? ` (${ loc.url }:${ loc.lineNumber })` : '' }` );
		}
	} );
	return errors;
}

/**
 * Abort every request that does not go to the E2E site itself (YouTube,
 * Google Maps/Fonts, Gravatar, ...). Makes page loads fast and deterministic
 * without internet access. `page.on('request')` still sees the attempts.
 *
 * @param {import('@playwright/test').Page|import('@playwright/test').BrowserContext} target
 */
async function blockExternalRequests( target ) {
	const site = new URL( BASE_URL );
	await target.route( '**/*', ( route ) => {
		const url = new URL( route.request().url() );
		if ( url.host === site.host || url.protocol === 'data:' || url.protocol === 'blob:' ) {
			return route.continue();
		}
		return route.abort( 'blockedbyclient' );
	} );
}

module.exports = {
	login,
	wpCli,
	urls,
	content,
	collectConsoleErrors,
	blockExternalRequests,
	THIRD_PARTY_NETWORK_NOISE,
	E2E_DIR,
	E2E_ROOT,
	WP_CLI,
	BASE_URL,
};
