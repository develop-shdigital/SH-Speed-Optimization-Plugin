// @ts-check
/**
 * Smoke test for the E2E environment itself, run WITHOUT SH Speed Optimizer
 * active. If this fails, the environment (bin/e2e-setup.sh / e2e-server.sh)
 * is broken - not the plugin.
 */
const { test, expect } = require( '@playwright/test' );
const { login, wpCli, urls, collectConsoleErrors } = require( '../helpers/wp' );

const PLUGIN_SLUG = 'sh-speed-optimizer';

test.describe( 'E2E environment (SH Speed Optimizer inactive)', () => {
	/** @type {ReturnType<typeof urls>} */
	let u;

	test.beforeAll( async ( { request } ) => {
		u = urls();
		const res = await request.get( u.home, { failOnStatusCode: false } ).catch( ( e ) => {
			throw new Error( `Site not reachable at ${ u.home } - start it with bin/e2e-server.sh start (${ e.message })` );
		} );
		expect( res.status(), `GET ${ u.home }` ).toBe( 200 );

		const active = wpCli( [ 'plugin', 'list', '--status=active', '--field=name' ] ).split( /\s+/ );
		if ( active.includes( PLUGIN_SLUG ) ) {
			wpCli( [ 'plugin', 'deactivate', PLUGIN_SLUG ] );
		}
	} );

	test( 'WP-CLI helper talks to the E2E site', async () => {
		expect( wpCli( [ 'option', 'get', 'blogname' ] ) ).toBe( 'SH E2E Site' );
		expect( wpCli( 'eval "echo wp_get_environment_type();"' ) ).toBe( 'production' );
		const active = wpCli( [ 'plugin', 'list', '--status=active', '--field=name' ] ).split( /\s+/ );
		expect( active ).toEqual( expect.arrayContaining( [ 'woocommerce', 'elementor', 'contact-form-7' ] ) );
		expect( active ).not.toContain( PLUGIN_SLUG );
	} );

	test( 'home page has hero image, YouTube embed, map iframe and navigation', async ( { page } ) => {
		const errors = collectConsoleErrors( page );
		const res = await page.goto( u.home );
		expect( res?.status() ).toBe( 200 );

		const hero = page.locator( '.shso-hero img.wp-block-cover__image-background' );
		await expect( hero ).toBeVisible();
		await expect( hero ).toHaveAttribute( 'src', /hero.*\.jpg/ );
		expect( await hero.evaluate( ( img ) => /** @type {HTMLImageElement} */ ( img ).naturalWidth ) ).toBeGreaterThan( 1000 );

		await expect( page.locator( '.shso-gallery img' ) ).toHaveCount( 6 );
		await expect( page.locator( 'iframe[src*="youtube.com/embed/dQw4w9WgXcQ"]' ) ).toHaveCount( 1 );
		await expect( page.locator( 'iframe[src*="google.com/maps/embed"]' ) ).toHaveCount( 1 );
		await expect( page.locator( 'form.wpcf7-form' ) ).toHaveCount( 1 );

		// Header navigation reaches all test pages.
		const nav = page.locator( 'header nav.wp-block-navigation' ).first();
		for ( const key of [ 'blog', 'contact', 'shop', 'elementor', 'cart' ] ) {
			await expect( nav.locator( `a[href="${ u[ key ] }"]` ).first() ).toBeAttached();
		}
		expect( errors.filter( ( e ) => e.startsWith( 'pageerror' ) ) ).toEqual( [] );
	} );

	test( 'blog and single post render', async ( { page } ) => {
		let res = await page.goto( u.blog );
		expect( res?.status() ).toBe( 200 );
		await expect( page.locator( '.wp-block-post' ) ).toHaveCount( 5 );

		res = await page.goto( u.post );
		expect( res?.status() ).toBe( 200 );
		await expect( page.locator( 'img.wp-post-image' ).first() ).toBeVisible();
		await expect( page.locator( '.wp-block-comment-template li, .commentlist li' ).first() ).toBeAttached();
	} );

	test( 'Elementor page renders Elementor markup', async ( { page } ) => {
		const res = await page.goto( u.elementor );
		expect( res?.status() ).toBe( 200 );
		const root = page.locator( '.elementor' ).first();
		await expect( root ).toBeVisible();
		for ( const widget of [ 'heading', 'image', 'button', 'video', 'text-editor' ] ) {
			await expect( page.locator( `.elementor-widget-${ widget }` ).first() ).toBeAttached();
		}
		await expect( page.locator( '.elementor-widget-heading h1' ) ).toHaveText( 'Elementor Landing Page' );
		await expect( page.locator( 'script#elementor-frontend-js' ) ).toBeAttached();
		await expect( page.locator( 'link#elementor-frontend-css' ) ).toBeAttached();
	} );

	test( 'contact page contains the Contact Form 7 form', async ( { page } ) => {
		const res = await page.goto( u.contact );
		expect( res?.status() ).toBe( 200 );
		const form = page.locator( 'form.wpcf7-form' );
		await expect( form ).toBeVisible();
		await expect( form.locator( 'input[type="email"]' ) ).toBeVisible();
		await expect( form.locator( 'input[type="submit"], button[type="submit"]' ) ).toBeVisible();
	} );

	test( 'shop lists products; anonymous AJAX add-to-cart, cart and checkout work', async ( { page } ) => {
		const res = await page.goto( u.shop );
		expect( res?.status() ).toBe( 200 );

		const products = page.locator( 'li.wc-block-product, li.product' );
		await expect( products ).toHaveCount( 5 );
		await expect( page.getByRole( 'link', { name: 'SH Hoodie' } ).first() ).toBeVisible();

		// AJAX add to cart (Store API or classic wc-ajax) without leaving the page.
		const card = products.filter( { hasText: 'SH Classic T-Shirt' } );
		const button = card.locator( '.add_to_cart_button' );
		await expect( button ).toBeVisible();
		const addToCart = page.waitForResponse(
			( r ) => /\/wc\/store\/v1\/(batch|cart\/add-item)|wc-ajax=add_to_cart/.test( r.url() ) && r.request().method() === 'POST'
		);
		await button.click();
		const addRes = await addToCart;
		expect( addRes.ok(), `${ addRes.url() } -> ${ addRes.status() }` ).toBeTruthy();
		await expect( card ).toContainText( /1 in cart|View cart/i );
		expect( page.url() ).toBe( u.shop );

		// Cart page (Cart block, rendered client side).
		await page.goto( u.cart );
		await expect( page.locator( '.wc-block-cart-items, .woocommerce-cart-form' ).first() ).toContainText( 'SH Classic T-Shirt' );

		// Checkout page with Cash on delivery.
		const checkoutRes = await page.goto( u.checkout );
		expect( checkoutRes?.status() ).toBe( 200 );
		expect( page.url() ).toBe( u.checkout );
		await expect( page.locator( '.wc-block-checkout, form.checkout' ).first() ).toBeVisible();
		await expect( page.getByText( 'Cash on delivery' ).first() ).toBeVisible();
		await expect( page.getByText( 'SH Classic T-Shirt' ).first() ).toBeVisible();
	} );

	test( 'logged-in admin can reach wp-admin', async ( { page } ) => {
		await login( page );
		const res = await page.goto( '/wp-admin/' );
		expect( res?.status() ).toBe( 200 );
		await expect( page.locator( '#wpbody' ) ).toBeVisible();
		await expect( page ).toHaveTitle( /Dashboard/ );

		const pluginsRes = await page.goto( '/wp-admin/plugins.php' );
		expect( pluginsRes?.status() ).toBe( 200 );
		await expect( page.locator( 'tr[data-slug="woocommerce"].active' ) ).toBeAttached();
	} );
} );
