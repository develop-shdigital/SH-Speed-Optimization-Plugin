<?php
/**
 * Tests for the store decision and header filtering of the capture.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Cache;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Cache\Capture;

final class CaptureTest extends TestCase {

	/**
	 * Facts of a cacheable response.
	 *
	 * @param array<string,mixed> $overrides Overrides.
	 * @return array<string,mixed>
	 */
	private function facts( array $overrides = array() ): array {
		return array_merge(
			array(
				'status'       => 200,
				'headers'      => array( 'Content-Type: text/html; charset=UTF-8', 'Link: <https://example.test/wp-json/>; rel="https://api.w.org/"' ),
				'content_type' => 'text/html; charset=UTF-8',
				'html'         => '<!DOCTYPE html><html><head><title>t</title></head><body>' . str_repeat( 'content ', 50 ) . '</body></html>',
				'query_ran'    => true,
				'donotcache'   => false,
				'logged_in'    => false,
				'is_404'       => false,
				'is_search'    => false,
				'is_feed'      => false,
				'is_preview'   => false,
				'is_trackback' => false,
				'is_robots'    => false,
				'is_embed'     => false,
				'password'     => false,
				'editor'       => false,
				'woocommerce'  => false,
				'edd'          => false,
				'verification' => false,
				'safe_cookies' => array(),
			),
			$overrides
		);
	}

	public function test_regular_page_is_stored(): void {
		$this->assertSame( '', Capture::store_decision( $this->facts() ) );
	}

	public function test_rejections(): void {
		$cases = array(
			'status'         => array( 'status' => 404 ),
			'content_type'   => array( 'content_type' => 'application/json' ),
			'too_small'      => array( 'html' => '<html></html>' ),
			'incomplete'     => array( 'html' => '<html><body>' . str_repeat( 'x', 400 ) ),
			'no_query'       => array( 'query_ran' => false ),
			'donotcachepage' => array( 'donotcache' => true ),
			'logged_in'      => array( 'logged_in' => true ),
			'not_found'      => array( 'is_404' => true ),
			'search'         => array( 'is_search' => true ),
			'feed'           => array( 'is_feed' => true ),
			'preview'        => array( 'is_preview' => true ),
			'trackback'      => array( 'is_trackback' => true ),
			'robots'         => array( 'is_robots' => true ),
			'embed'          => array( 'is_embed' => true ),
			'password'       => array( 'password' => true ),
			'editor'         => array( 'editor' => true ),
			'woocommerce'    => array( 'woocommerce' => true ),
			'edd'            => array( 'edd' => true ),
			'verification'   => array( 'verification' => true ),
			'tracking_query' => array( 'tracking' => true ),
		);
		foreach ( $cases as $reason => $overrides ) {
			$this->assertSame( $reason, Capture::store_decision( $this->facts( $overrides ) ), $reason );
		}
		$this->assertSame( 'status', Capture::store_decision( $this->facts( array( 'status' => 301 ) ) ) );
		$this->assertSame( 'status', Capture::store_decision( $this->facts( array( 'status' => 500 ) ) ) );
	}

	public function test_set_cookie_prevents_storing_unless_whitelisted(): void {
		$headers = array( 'Content-Type: text/html', 'Set-Cookie: PHPSESSID=abc; path=/; HttpOnly' );
		$this->assertSame( 'set_cookie', Capture::store_decision( $this->facts( array( 'headers' => $headers ) ) ) );

		$safe = array( 'Content-Type: text/html', 'Set-Cookie: pll_language=de; path=/' );
		$this->assertSame( '', Capture::store_decision( $this->facts( array( 'headers' => $safe, 'safe_cookies' => array( 'pll_language' ) ) ) ) );

		$mixed = array( 'Set-Cookie: pll_language=de; path=/', 'Set-Cookie: tracking=1' );
		$this->assertSame( 'set_cookie', Capture::store_decision( $this->facts( array( 'headers' => $mixed, 'safe_cookies' => array( 'pll_language' ) ) ) ) );
	}

	public function test_no_store_cache_control_prevents_storing(): void {
		foreach ( array( 'no-store', 'private', 'no-cache, must-revalidate, max-age=0', 'No-Cache' ) as $value ) {
			$headers = array( 'Cache-Control: ' . $value );
			$this->assertSame( 'cache_control', Capture::store_decision( $this->facts( array( 'headers' => $headers ) ) ), $value );
		}
		$this->assertSame( '', Capture::store_decision( $this->facts( array( 'headers' => array( 'Cache-Control: public, max-age=600' ) ) ) ) );
	}

	public function test_replayable_headers_never_include_cookies_or_cache_headers(): void {
		$list = array(
			'X-Powered-By: PHP/8.3',
			'Content-Type: text/html; charset=UTF-8',
			'Set-Cookie: a=b',
			'Content-Length: 1234',
			'Content-Encoding: gzip',
			'Transfer-Encoding: chunked',
			'Cache-Control: max-age=60',
			'Expires: Wed, 11 Jan 1984 05:00:00 GMT',
			'Pragma: no-cache',
			'Last-Modified: Tue, 14 Nov 2023 22:13:20 GMT',
			'ETag: "x"',
			'Vary: Cookie',
			'Date: Tue, 14 Nov 2023 22:13:20 GMT',
			'X-SHSO-Cache: MISS',
			'X-SHSO-Optimized: 1',
			'Link: <https://example.test/wp-json/>; rel="https://api.w.org/"',
			'X-Pingback: https://example.test/xmlrpc.php',
			'Content-Security-Policy: upgrade-insecure-requests',
		);
		$this->assertSame(
			array(
				'Link: <https://example.test/wp-json/>; rel="https://api.w.org/"',
				'X-Pingback: https://example.test/xmlrpc.php',
				'Content-Security-Policy: upgrade-insecure-requests',
			),
			Capture::replayable_headers( $list )
		);

		$many = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$many[] = 'X-Custom-' . $i . ': ' . $i;
		}
		$this->assertCount( 30, Capture::replayable_headers( $many ) );
	}

	public function test_content_type_detection(): void {
		$this->assertSame( 'text/html; charset=UTF-8', Capture::content_type( array(), 'UTF-8' ) );
		$this->assertSame( 'text/html; charset=ISO-8859-1', Capture::content_type( array(), 'ISO-8859-1' ) );
		$this->assertSame( 'application/json', Capture::content_type( array( 'content-type: text/html', 'Content-Type: application/json' ) ) );
	}
}
