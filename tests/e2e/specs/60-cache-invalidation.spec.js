// @ts-check
/**
 * Cached pages are purged when content changes; never stale.
 */
const { test, expect } = require( '@playwright/test' );
const { urls, wpCli, content } = require( '../helpers/wp' );
const shso = require( '../helpers/shso' );

test.describe( 'Cache invalidation', () => {
	test.beforeAll( () => {
		shso.ensureOptimized();
		shso.purge();
	} );

	test( 'updating a post purges the post and the pages listing it', async ( { request } ) => {
		const u = urls();
		const postId = String( shso.evalJson( `url_to_postid( '${ u.post }' )` ) );
		expect( Number( postId ) ).toBeGreaterThan( 0 );
		const original = shso.evalJson( `get_post_field( 'post_title', ${ Number( postId ) } )` );
		const changed = `${ original } (updated ${ Date.now() })`;

		for ( const url of [ u.post, u.blog ] ) {
			await shso.fetchPage( request, url );
			expect( ( await shso.fetchPage( request, url ) ).cache, url ).toBe( 'HIT' );
		}

		try {
			wpCli( [ 'post', 'update', postId, `--post_title=${ changed }` ] );

			const post = await shso.fetchPage( request, u.post );
			expect( post.cache ).not.toBe( 'HIT' );
			expect( post.body ).toContain( changed );

			const blog = await shso.fetchPage( request, u.blog );
			expect( blog.cache ).not.toBe( 'HIT' );
			expect( blog.body ).toContain( changed );

			expect( ( await shso.fetchPage( request, u.post ) ).cache ).toBe( 'HIT' );
		} finally {
			wpCli( [ 'post', 'update', postId, `--post_title=${ original }` ] );
		}
	} );

	test( 'changing a product purges the product page', async ( { request } ) => {
		const u = urls();
		await shso.fetchPage( request, u.product );
		expect( ( await shso.fetchPage( request, u.product ) ).cache ).toBe( 'HIT' );

		const productId = String( shso.evalJson( `url_to_postid( '${ u.product }' )` ) );
		const price = shso.evalJson( `get_post_meta( ${ Number( productId ) }, '_regular_price', true )` );
		try {
			wpCli( [ 'eval', `$p = wc_get_product( ${ Number( productId ) } ); $p->set_regular_price( '77.00' ); $p->save();` ] );
			const after = await shso.fetchPage( request, u.product );
			expect( after.cache ).not.toBe( 'HIT' );
			expect( after.body ).toMatch( /77[.,]00/ );
		} finally {
			wpCli( [ 'eval', `$p = wc_get_product( ${ Number( productId ) } ); $p->set_regular_price( '${ price }' ); $p->save();` ] );
		}
		void content;
	} );

	test( 'manual purge from the toolbar endpoint and REST', async ( { request } ) => {
		const u = urls();
		await shso.fetchPage( request, u.home );
		expect( ( await shso.fetchPage( request, u.home ) ).cache ).toBe( 'HIT' );
		shso.purge();
		expect( ( await shso.fetchPage( request, u.home ) ).cache ).not.toBe( 'HIT' );
	} );
} );
