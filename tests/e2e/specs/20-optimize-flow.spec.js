// @ts-check
/**
 * Onboarding in the browser: Analyze My Site → Apply Safe Optimizations.
 * Browser checks run in this Playwright browser through the admin harness.
 */
const { test, expect } = require( '@playwright/test' );
const { login, urls } = require( '../helpers/wp' );
const shso = require( '../helpers/shso' );

test.describe( 'Analyze and optimize from the dashboard', () => {
	test.describe.configure( { timeout: 15 * 60 * 1000 } );

	test.beforeAll( () => {
		shso.reset();
	} );

	test( 'analyze, apply safe optimizations and verify them in the browser', async ( { page, request } ) => {
		const pageErrors = [];
		page.on( 'pageerror', ( e ) => pageErrors.push( e.message ) );

		await login( page );
		await page.goto( '/wp-admin/admin.php?page=shso' );

		// 1. Analyze.
		await page.locator( '#shso-analyze' ).click();
		await expect( page.locator( '#shso-job-modal' ) ).toBeVisible();
		await shso.waitForJobEnd( page, 6 * 60 * 1000 );
		let job = ( await shso.api( page, '/shso/v1/jobs/current' ) ).job;
		expect( job.type ).toBe( 'scan' );
		expect( job.status ).toBe( 'done' );
		await page.locator( '#shso-job-modal button', { hasText: 'Close' } ).click();

		const scanStatus = await shso.api( page, '/shso/v1/status' );
		expect( scanStatus.onboarding ).toBe( 'scanned' );
		expect( scanStatus.health.score ).toEqual( expect.any( Number ) );
		expect( scanStatus.ready.count ).toBeGreaterThan( 0 );
		expect( shso.activeIds(), 'a scan changes nothing' ).toEqual( [] );

		// Browser measurements were stored per template.
		const diagnostics = await shso.api( page, '/shso/v1/diagnostics?developer=1' );
		expect( Object.keys( diagnostics.developer.page_data || {} ) ).toContain( 'front_page' );

		// 2. Apply.
		await page.reload();
		await expect( page.getByText( /Your website is ready for \d+ safe optimization/ ) ).toBeVisible();
		await page.locator( '#shso-apply' ).click();
		await shso.waitForJobEnd( page, 12 * 60 * 1000 );
		job = ( await shso.api( page, '/shso/v1/jobs/current' ) ).job;
		expect( job.type ).toBe( 'optimize' );
		expect( job.status ).toBe( 'done' );

		const applied = ( job.summary.applied || [] ).map( ( i ) => i.id );
		const rolledBack = job.summary.rolled_back || [];
		console.log( 'applied:', applied.join( ', ' ) );
		console.log( 'rolled back:', JSON.stringify( rolledBack ) );
		expect( applied ).toEqual( expect.arrayContaining( [ 'page_cache', 'disable_emojis' ] ) );

		// Browser verification really ran: optimizations that require it are either kept or rolled back
		// with a concrete reason — never skipped for lack of a browser test.
		for ( const item of rolledBack ) {
			expect( item.reason ).not.toMatch( /Needs a test in your browser|browser test could not run/ );
		}
		const active = shso.activeIds();
		const state = shso.state();
		for ( const id of [ 'video_facade', 'map_facade' ] ) {
			if ( active.includes( id ) ) {
				expect( state.active[ id ].verified, `${ id } verified in the browser` ).toBe( 'browser' );
			}
		}

		await page.locator( '#shso-job-modal button', { hasText: 'Close' } ).click();
		await page.reload();
		await expect( page.getByText( 'Optimization Complete' ) ).toBeVisible();
		expect( ( await shso.api( page, '/shso/v1/status' ) ).onboarding ).toBe( 'optimized' );

		// 3. The history lists what happened.
		const history = await shso.api( page, '/shso/v1/history' );
		expect( history.entries.some( ( e ) => e.event === 'applied' && e.optimization_id === 'page_cache' ) ).toBeTruthy();
		expect( history.snapshots.length ).toBeGreaterThan( 0 );

		// 4. Visitors get cached pages.
		const u = urls();
		await shso.fetchPage( request, u.home );
		const cached = await shso.fetchPage( request, u.home );
		expect( cached.cache ).toBe( 'HIT' );

		expect( pageErrors, 'no JavaScript errors in the admin' ).toEqual( [] );
	} );
} );
