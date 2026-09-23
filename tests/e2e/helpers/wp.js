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
 * Collect uncaught page errors and console.error() messages.
 * Returns an array that fills over time.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {string[]}
 */
function collectConsoleErrors( page ) {
	/** @type {string[]} */
	const errors = [];
	page.on( 'pageerror', ( err ) => errors.push( `pageerror: ${ err.message }` ) );
	page.on( 'console', ( msg ) => {
		if ( msg.type() === 'error' ) {
			const loc = msg.location();
			errors.push( `console.error: ${ msg.text() }${ loc && loc.url ? ` (${ loc.url }:${ loc.lineNumber })` : '' }` );
		}
	} );
	return errors;
}

module.exports = {
	login,
	wpCli,
	urls,
	content,
	collectConsoleErrors,
	E2E_DIR,
	E2E_ROOT,
	WP_CLI,
	BASE_URL,
};
