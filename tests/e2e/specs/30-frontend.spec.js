// @ts-check
/**
 * Critical scenarios with optimizations active (see TESTING.md).
 */
const { test, expect } = require( '@playwright/test' );
const { login, urls, collectConsoleErrors, blockExternalRequests, THIRD_PARTY_NETWORK_NOISE } = require( '../helpers/wp' );
const shso = require( '../helpers/shso' );

/** Errors caused by the environment or by WooCommerce itself without the plugin. */
const KNOWN_BASELINE = [ ...THIRD_PARTY_NETWORK_NOISE, /undefinedwc\/store\/v1\/cart/, /ERR_CERT_AUTHORITY_INVALID|net::ERR_(BLOCKED_BY_CLIENT|FAILED)/ ];

test.describe( 'Frontend with optimizations active', () => {
	/** @type {ReturnType<typeof urls>} */
	let u;
	/** @type {string[]} */
	let active;

	test.beforeAll( () => {
		u = urls();
		shso.ensureOptimized();
		shso.purge();
		active = shso.activeIds();
	} );

	test.beforeEach( async ( { context } ) => {
		await blockExternalRequests( context );
	} );

	test( '16 · logged-out visitors get cached, optimized pages', async ( { request } ) => {
		const first = await shso.fetchPage( request, u.home );
		const second = await shso.fetchPage( request, u.home );
		expect( first.status ).toBe( 200 );
		expect( [ 'MISS', 'HIT' ] ).toContain( first.cache );
		expect( second.cache ).toBe( 'HIT' );
		expect( second.body ).toContain( '</html>' );
		expect( second.body ).not.toContain( 'wp-emoji-release' );
		if ( active.includes( 'css_minify' ) ) {
			expect( second.body ).toContain( '/cache/sh-speed-optimizer/assets/' );
		}
		// Conditional requests are answered with 304.
		const etag = second.headers.etag;
		if ( etag ) {
			const revalidated = await request.get( u.home, { headers: { 'If-None-Match': etag }, failOnStatusCode: false } );
			expect( revalidated.status() ).toBe( 304 );
		}
	} );

	test( '1 · Elementor landing page renders its widgets without new errors', async ( { page } ) => {
		const errors = collectConsoleErrors( page, { ignore: KNOWN_BASELINE } );
		const res = await page.goto( u.elementor );
		expect( res?.status() ).toBe( 200 );
		for ( const widget of [ 'heading', 'image', 'button', 'text-editor' ] ) {
			await expect( page.locator( `.elementor-widget-${ widget }` ).first() ).toBeVisible();
		}
		await expect( page.locator( '.elementor-widget-heading h1' ) ).toHaveText( 'Elementor Landing Page' );
		await expect( page.locator( 'script#elementor-frontend-js' ) ).toBeAttached();
		// Elementor frontend initialised (handlers attached).
		await expect.poll( () => page.evaluate( () => !! ( window.elementorFrontend && window.elementorFrontend.hooks ) ) ).toBeTruthy();
		await page.mouse.move( 200, 200 );
		await page.mouse.wheel( 0, 400 );
		await page.waitForTimeout( 1000 );
		expect( errors ).toEqual( [] );
	} );

	test( '3 · 7 · contact form is present and submits', async ( { page } ) => {
		const errors = collectConsoleErrors( page, { ignore: KNOWN_BASELINE } );
		await page.goto( u.contact );
		const form = page.locator( 'form.wpcf7-form' );
		await expect( form ).toBeVisible();
		await form.locator( 'input[name="your-name"]' ).fill( 'E2E Tester' );
		await form.locator( 'input[type="email"]' ).fill( 'e2e@example.test' );
		const subject = form.locator( 'input[name="your-subject"]' );
		if ( await subject.count() ) {
			await subject.fill( 'Hello' );
		}
		const message = form.locator( 'textarea' ).first();
		if ( await message.count() ) {
			await message.fill( 'Testing the form after optimization.' );
		}
		const feedback = page.waitForResponse( ( r ) => /contact-form-7\/v1\/contact-forms\/\d+\/feedback/.test( r.url() ) );
		await form.locator( 'input[type="submit"], button[type="submit"]' ).first().click();
		const response = await feedback;
		expect( response.status() ).toBe( 200 );
		const body = await response.json();
		expect( [ 'mail_sent', 'mail_failed' ] ).toContain( body.status ); // Submission processed (the test server has no mailer).
		await expect( form.locator( '.wpcf7-response-output' ) ).not.toBeEmpty();
		expect( errors ).toEqual( [] );
	} );

	test( '4 · 5 · 6 · WooCommerce: AJAX add-to-cart works, cart and checkout are never cached', async ( { page, request } ) => {
		// Anonymous cart/checkout requests without cookies.
		for ( const url of [ u.cart, u.checkout, u.myaccount ] ) {
			await shso.fetchPage( request, url );
			const again = await shso.fetchPage( request, url );
			expect( again.cache, url ).not.toBe( 'HIT' );
		}

		const errors = collectConsoleErrors( page, { ignore: KNOWN_BASELINE } );
		await page.goto( u.shop );
		const products = page.locator( 'li.wc-block-product, li.product' );
		const card = products.filter( { hasText: 'SH Classic T-Shirt' } );
		const addToCart = page.waitForResponse( ( r ) => /\/wc\/store\/v1\/(batch|cart\/add-item)|wc-ajax=add_to_cart/.test( r.url() ) && r.request().method() === 'POST' );
		await card.locator( '.add_to_cart_button' ).click();
		expect( ( await addToCart ).ok() ).toBeTruthy();
		await expect( card ).toContainText( /1 in cart|View cart/i );

		const cartResponse = await page.goto( u.cart );
		expect( cartResponse?.headers()[ 'x-shso-cache' ] ).not.toBe( 'HIT' );
		await expect( page.locator( '.wc-block-cart-items, .woocommerce-cart-form' ).first() ).toContainText( 'SH Classic T-Shirt' );

		// With items in the cart, even the (cacheable) shop page is never served from the cache.
		const shopAgain = await page.goto( u.shop );
		expect( shopAgain?.headers()[ 'x-shso-cache' ] ).not.toBe( 'HIT' );

		const checkout = await page.goto( u.checkout );
		expect( checkout?.status() ).toBe( 200 );
		expect( checkout?.headers()[ 'x-shso-cache' ] ).not.toBe( 'HIT' );
		await expect( page.getByText( 'Cash on delivery' ).first() ).toBeVisible();
		expect( errors ).toEqual( [] );
	} );

	test( '8 · desktop JavaScript navigation and 9 · mobile navigation work', async ( { page, browser } ) => {
		const errors = collectConsoleErrors( page, { ignore: KNOWN_BASELINE } );
		await page.goto( u.home );
		const nav = page.locator( 'header nav.wp-block-navigation' ).first();
		await expect( nav.locator( `a[href="${ u.shop }"]` ).first() ).toBeVisible();
		expect( errors ).toEqual( [] );

		const mobile = await browser.newContext( { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } );
		await blockExternalRequests( mobile );
		const phone = await mobile.newPage();
		const phoneErrors = collectConsoleErrors( phone, { ignore: KNOWN_BASELINE } );
		await phone.goto( u.home );
		const open = phone.locator( 'button.wp-block-navigation__responsive-container-open' ).first();
		await expect( open ).toBeVisible();
		await open.click();
		const overlay = phone.locator( '.wp-block-navigation__responsive-container.is-menu-open' ).first();
		await expect( overlay ).toBeVisible();
		await expect( overlay.locator( `a[href="${ u.shop }"]` ).first() ).toBeVisible();
		await phone.locator( 'button.wp-block-navigation__responsive-container-close' ).first().click();
		await expect( overlay ).toBeHidden();
		expect( phoneErrors ).toEqual( [] );
		await mobile.close();
	} );

	test( '10 · Google Maps facade loads the map on click', async ( { page } ) => {
		test.skip( ! active.includes( 'map_facade' ), 'map_facade is not active on this site' );
		await page.goto( u.home );
		const facade = page.locator( '.shso-facade--map' ).first();
		await expect( facade ).toBeAttached();
		await expect( page.locator( 'iframe[src*="google.com/maps"]' ) ).toHaveCount( 0 );
		const button = facade.getByRole( 'button', { name: /load interactive map/i } );
		await button.scrollIntoViewIfNeeded();
		await button.click();
		await expect( page.locator( 'iframe[src*="google.com/maps/embed"]' ) ).toHaveCount( 1 );
	} );

	test( '11 · YouTube facade is accessible and loads the player on click', async ( { page } ) => {
		test.skip( ! active.includes( 'video_facade' ), 'video_facade is not active on this site' );
		await page.goto( u.home );
		const facade = page.locator( '.shso-facade--video' ).first();
		await expect( facade ).toBeAttached();
		await expect( page.locator( 'iframe[src*="youtube.com/embed"]' ) ).toHaveCount( 0 );
		const play = facade.getByRole( 'button', { name: /play video/i } );
		await play.scrollIntoViewIfNeeded();
		await play.focus();
		await page.keyboard.press( 'Enter' );
		const iframe = page.locator( 'iframe[src*="youtube"]' ).first();
		await expect( iframe ).toBeAttached();
		await expect( iframe ).toHaveAttribute( 'src', /autoplay=1/ );
	} );

	test( '13 · custom AJAX (admin-ajax / wc-ajax) is never cached', async ( { request } ) => {
		for ( let i = 0; i < 2; i++ ) {
			const ajax = await request.post( `${ u.home }?wc-ajax=get_refreshed_fragments`, { failOnStatusCode: false } );
			expect( ajax.status() ).toBe( 200 );
			expect( ajax.headers()[ 'x-shso-cache' ] || null ).not.toBe( 'HIT' );
			expect( await ajax.json() ).toHaveProperty( 'fragments' );

			const admin = await request.post( `${ u.home }wp-admin/admin-ajax.php`, { form: { action: 'shso_e2e_nonexistent' }, failOnStatusCode: false } );
			expect( admin.headers()[ 'x-shso-cache' ] || null ).not.toBe( 'HIT' );
			expect( await admin.text() ).toBe( '0' );
		}
	} );

	test( '14 · REST API responses are never cached', async ( { request } ) => {
		for ( let i = 0; i < 2; i++ ) {
			const res = await request.get( `${ u.home }wp-json/wp/v2/posts?per_page=1`, { failOnStatusCode: false } );
			expect( res.status() ).toBe( 200 );
			expect( res.headers()[ 'x-shso-cache' ] || null ).not.toBe( 'HIT' );
			expect( Array.isArray( await res.json() ) ).toBeTruthy();
		}
	} );

	test( '15 · logged-in administrators get unmodified, uncached pages', async ( { page } ) => {
		await login( page );
		const res = await page.goto( u.home );
		expect( res?.headers()[ 'x-shso-cache' ] ).not.toBe( 'HIT' );
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
		await expect( page.locator( '.shso-facade' ) ).toHaveCount( 0 );
		await expect( page.locator( 'script[type="shso/delay"]' ) ).toHaveCount( 0 );
		await expect( page.locator( 'iframe[src*="youtube.com/embed"]' ) ).toHaveCount( 1 );
		// Toolbar menu for the administrator.
		await expect( page.locator( '#wp-admin-bar-shso' ) ).toBeAttached();
	} );

	test( 'no new JavaScript errors on key pages for visitors', async ( { page } ) => {
		for ( const key of [ 'home', 'blog', 'post', 'shop', 'product' ] ) {
			const errors = collectConsoleErrors( page, { ignore: KNOWN_BASELINE } );
			const res = await page.goto( u[ key ] );
			expect( res?.status(), key ).toBe( 200 );
			await page.mouse.wheel( 0, 600 );
			await page.waitForTimeout( 800 );
			expect( errors, key ).toEqual( [] );
		}
	} );
} );
