// @ts-check
/**
 * Activation changes nothing until the administrator analyzes and applies.
 */
const { test, expect } = require( '@playwright/test' );
const { login, urls } = require( '../helpers/wp' );
const shso = require( '../helpers/shso' );

test.describe( 'Activation and onboarding entry', () => {
	test.beforeAll( () => {
		shso.reset();
	} );

	test( 'a fresh activation does not change the site', async ( { request } ) => {
		const u = urls();
		const first = await shso.fetchPage( request, u.home );
		const second = await shso.fetchPage( request, u.home );

		expect( first.status ).toBe( 200 );
		expect( second.cache, 'no page cache before the administrator applies optimizations' ).toBeNull();
		expect( first.body ).toContain( 'wp-emoji' );
		expect( first.body ).not.toContain( 'sh-speed-optimizer/assets/' );
		expect( first.body ).not.toContain( 'shso-facade' );
		expect( shso.activeIds() ).toEqual( [] );
	} );

	test( 'the REST API is closed to visitors', async ( { request } ) => {
		const u = urls();
		for ( const route of [ 'status', 'optimizations', 'diagnostics', 'settings', 'cache' ] ) {
			const res = await request.get( `${ u.home }wp-json/shso/v1/${ route }`, { failOnStatusCode: false } );
			expect( [ 401, 403 ], route ).toContain( res.status() );
		}
		const post = await request.post( `${ u.home }wp-json/shso/v1/jobs`, { data: { type: 'optimize' }, failOnStatusCode: false } );
		expect( [ 401, 403 ] ).toContain( post.status() );
	} );

	test( 'a forged verification token is ignored', async ( { request } ) => {
		const u = urls();
		const res = await shso.fetchPage( request, `${ u.home }?shso_verify=eyJtIjoiY2FuZGlkYXRlIiwibyI6WyJqc19kZWZlciJdfQ.forged` );
		expect( res.status ).toBe( 200 );
		expect( res.body ).not.toContain( 'shso-probe' );
	} );

	test( 'menu and onboarding are shown to the administrator', async ( { page } ) => {
		await login( page );
		const menu = page.locator( '#toplevel_page_shso' );
		await expect( menu ).toBeVisible();
		for ( const label of [ 'Overview', 'Optimization', 'Diagnostics', 'Cache', 'Settings' ] ) {
			await expect( menu.locator( '.wp-submenu a', { hasText: label } ) ).toHaveCount( 1 );
		}

		await page.goto( '/wp-admin/admin.php?page=shso' );
		await expect( page.getByText( 'Welcome to SH Speed Optimizer' ) ).toBeVisible();
		await expect( page.locator( '#shso-analyze' ) ).toBeVisible();
		await expect( page.locator( '.shso-header' ) ).toHaveCount( 1 );
	} );
} );
