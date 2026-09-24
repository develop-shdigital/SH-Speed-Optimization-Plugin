// @ts-check
/**
 * Safe Mode (toolbar) and emergency safe mode (wp-config.php constant).
 */
const { test, expect } = require( '@playwright/test' );
const { login, urls, wpCli } = require( '../helpers/wp' );
const shso = require( '../helpers/shso' );

test.describe( 'Safe Mode', () => {
	test.beforeAll( () => {
		shso.ensureOptimized();
		shso.setSettings( { safe_mode: false } );
		shso.purge();
	} );

	test.afterAll( () => {
		try {
			wpCli( [ 'config', 'delete', 'SHSO_SAFE_MODE' ] );
		} catch ( e ) {}
		shso.setSettings( { safe_mode: false } );
		shso.purge();
	} );

	test( 'toolbar Safe Mode bypasses transformations but keeps safe features', async ( { page, request } ) => {
		const u = urls();
		const active = shso.activeIds();

		await login( page );
		await page.goto( u.home );
		await page.locator( '#wp-admin-bar-shso' ).hover();
		await page.locator( '#wp-admin-bar-shso-safe-mode a' ).click();
		await page.waitForLoadState( 'domcontentloaded' );
		expect( shso.evalJson( '\\SH\\SpeedOptimizer\\Core\\Plugin::instance()->settings()->get( "safe_mode" )' ) ).toBe( true );
		await expect( page.locator( '#wp-admin-bar-shso' ) ).toContainText( 'Safe Mode' );

		await shso.fetchPage( request, u.home );
		const visitor = await shso.fetchPage( request, u.home );
		expect( visitor.cache, 'page cache is safe-mode compatible' ).toBe( 'HIT' );
		expect( visitor.body ).not.toContain( 'shso-facade' );
		expect( visitor.body ).not.toContain( '/cache/sh-speed-optimizer/assets/' );
		expect( visitor.body ).not.toContain( 'type="shso/delay"' );
		if ( active.includes( 'disable_emojis' ) ) {
			expect( visitor.body ).not.toContain( 'wp-emoji-release' );
		}

		// Turn it off again from the toolbar.
		await page.goto( u.home );
		await page.locator( '#wp-admin-bar-shso' ).hover();
		await page.locator( '#wp-admin-bar-shso-safe-mode a' ).click();
		await page.waitForLoadState( 'domcontentloaded' );
		await shso.fetchPage( request, u.home );
		const restored = await shso.fetchPage( request, u.home );
		if ( active.includes( 'video_facade' ) ) {
			expect( restored.body ).toContain( 'shso-facade' );
		}
	} );

	test( 'emergency constant bypasses everything, including the page cache', async ( { page, request } ) => {
		const u = urls();
		wpCli( [ 'config', 'set', 'SHSO_SAFE_MODE', 'true', '--raw', '--anchor=<?php', '--placement=after', '--separator=\n' ] );

		const a = await shso.fetchPage( request, u.home );
		const b = await shso.fetchPage( request, u.home );
		expect( a.status ).toBe( 200 );
		expect( b.cache ).toBeNull();
		expect( b.body ).not.toContain( 'shso-facade' );
		expect( b.body ).not.toContain( '/cache/sh-speed-optimizer/assets/' );
		expect( b.body ).toContain( 'wp-emoji' );

		await login( page );
		await page.goto( '/wp-admin/admin.php?page=shso' );
		await expect( page.getByText( /Emergency Safe Mode is active/ ).first() ).toBeVisible();

		wpCli( [ 'config', 'delete', 'SHSO_SAFE_MODE' ] );
		await shso.fetchPage( request, u.home );
		const back = await shso.fetchPage( request, u.home );
		expect( back.cache ).toBe( 'HIT' );
	} );
} );
