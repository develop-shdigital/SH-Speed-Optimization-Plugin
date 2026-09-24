// @ts-check
/**
 * Safety net: an optimization that breaks JavaScript is detected in the
 * browser and rolled back automatically; Undo restores the previous state.
 */
const { test, expect } = require( '@playwright/test' );
const { login, urls, blockExternalRequests } = require( '../helpers/wp' );
const shso = require( '../helpers/shso' );

test.describe( 'Automatic rollback and undo', () => {
	test.describe.configure( { timeout: 10 * 60 * 1000 } );

	test.beforeAll( () => {
		shso.ensureOptimized();
		shso.installBreaker();
		shso.purge();
	} );

	test.afterAll( () => {
		shso.removeBreaker();
		shso.purge();
	} );

	test( 'deferring a script that inline code depends on is rolled back', async ( { page, request } ) => {
		const u = urls();

		// The fixture works without optimization.
		await blockExternalRequests( page.context() );
		await page.goto( u.home + '?e2e=baseline' );
		await expect( page.locator( 'html' ) ).toHaveAttribute( 'data-e2e-lib', 'ok' );

		const before = shso.activeIds();
		expect( before ).not.toContain( 'js_defer' );

		// Request JavaScript defer manually; the dashboard runs the tests in this browser.
		await login( page );
		await page.goto( '/wp-admin/admin.php?page=shso-optimization' );
		const response = await shso.api( page, '/shso/v1/optimizations/js_defer', 'POST', { mode: 'on' } );
		expect( response.job ).toBeTruthy();
		await page.reload(); // The UI resumes the running job and performs the browser checks.
		await shso.waitForJobEnd( page, 8 * 60 * 1000 );

		const job = ( await shso.api( page, '/shso/v1/jobs/current' ) ).job;
		expect( job.status ).toBe( 'done' );
		const rolled = ( job.summary.rolled_back || [] ).find( ( r ) => r.id === 'js_defer' );
		expect( rolled, JSON.stringify( job.summary ) ).toBeTruthy();
		expect( rolled.reason ).toMatch( /JavaScript error|e2eWidgetRegistry|boot/i );

		const state = shso.state();
		expect( Object.keys( state.active ) ).not.toContain( 'js_defer' );
		expect( state.disabled.js_defer ).toBeTruthy();

		// The history explains it in plain language.
		const history = await shso.api( page, '/shso/v1/history' );
		expect( history.entries.some( ( e ) => e.event === 'rolled_back' && e.optimization_id === 'js_defer' ) ).toBeTruthy();

		// Visitors never saw the broken version.
		const visitor = await page.context().browser().newContext();
		await blockExternalRequests( visitor );
		const vpage = await visitor.newPage();
		await vpage.goto( u.home + '?e2e=after' );
		await expect( vpage.locator( 'html' ) ).toHaveAttribute( 'data-e2e-lib', 'ok' );
		await visitor.close();

		// Undo returns to exactly the previous configuration.
		await shso.api( page, '/shso/v1/undo', 'POST' );
		expect( shso.activeIds().sort() ).toEqual( before.sort() );

		// Back to automatic control for later runs.
		await shso.api( page, '/shso/v1/optimizations/js_defer', 'POST', { mode: 'auto' } );
		expect( shso.state().disabled.js_defer ).toBeFalsy();
		const settings = await shso.api( page, '/shso/v1/settings' );
		expect( settings.settings ).toBeTruthy();
		void request;
	} );
} );
