<?php
/**
 * Tests for the standalone page cache delivery (decisions, keys, responses).
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Cache;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Cache\Config;
use SH\SpeedOptimizer\Cache\Delivery;

final class DeliveryTest extends TestCase {

	/**
	 * Host configuration with one root site.
	 *
	 * @param array<string,mixed> $site Overrides.
	 * @return array<string,mixed>
	 */
	private function config( array $site = array() ): array {
		return array(
			'sites' => array(
				'/' => Config::build_site(
					array_merge(
						array(
							'enabled'      => true,
							'lifespan'     => 36000,
							'hosts'        => array( 'example.test', 'www.example.test' ),
							'ignore_query' => Config::TRACKING_PARAMS,
							'gzip'         => true,
							'rest_prefix'  => 'wp-json',
						),
						$site
					)
				),
			),
		);
	}

	/**
	 * $_SERVER-like array.
	 *
	 * @param string              $uri   Request URI.
	 * @param array<string,mixed> $extra Overrides.
	 * @return array<string,mixed>
	 */
	private function server( string $uri, array $extra = array() ): array {
		return array_merge(
			array(
				'HTTP_HOST'      => 'example.test',
				'REQUEST_URI'    => $uri,
				'REQUEST_METHOD' => 'GET',
				'HTTPS'          => 'on',
				'SERVER_PORT'    => '443',
			),
			$extra
		);
	}

	/**
	 * Decide with defaults.
	 *
	 * @param string              $uri     URI.
	 * @param array<string,mixed> $extra   Server overrides.
	 * @param array<string,mixed> $cookies Cookies.
	 * @param array<string,mixed> $site    Site overrides.
	 * @return array<string,mixed>
	 */
	private function decide( string $uri, array $extra = array(), array $cookies = array(), array $site = array() ): array {
		return Delivery::decide( $this->server( $uri, $extra ), $cookies, $this->config( $site ) );
	}

	public function test_regular_pages_are_cacheable(): void {
		$page = $this->decide( '/about/' );
		$this->assertSame( 'cache', $page['action'] );
		$this->assertSame( 'example.test/about/', $page['dir'] );
		$this->assertSame( 'index.html', $page['file'] );
		$this->assertSame( 'https://example.test/about/', $page['url'] );
		$this->assertSame( 'example.test', $page['site_key'] );

		$home = $this->decide( '/' );
		$this->assertSame( 'cache', $home['action'] );
		$this->assertSame( 'example.test/', $home['dir'] );
		$this->assertSame( 'index.html', $home['file'] );

		$this->assertSame( 'cache', $this->decide( '/2024/01/hello-world/' )['action'] );
		$this->assertSame( 'cache', $this->decide( '/index.php/2024/01/hello/' )['action'], 'PATH_INFO permalinks are pages.' );
		$this->assertSame( 'cache', $this->decide( '/about/', array( 'REQUEST_METHOD' => 'HEAD' ) )['action'] );
	}

	public function test_non_get_methods_bypass(): void {
		foreach ( array( 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS' ) as $method ) {
			$decision = $this->decide( '/about/', array( 'REQUEST_METHOD' => $method ) );
			$this->assertSame( 'bypass', $decision['action'], $method );
			$this->assertSame( 'method', $decision['reason'] );
		}
	}

	public function test_admin_login_rest_ajax_feeds_and_files_bypass(): void {
		$paths = array(
			'/wp-admin/',
			'/wp-admin/admin-ajax.php',
			'/wp-login.php',
			'/wp-login.php?action=lostpassword',
			'/xmlrpc.php',
			'/wp-cron.php',
			'/wp-json/',
			'/wp-json/wp/v2/posts',
			'/WP-JSON/wp/v2/posts',
			'/feed/',
			'/comments/feed/',
			'/category/news/feed/',
			'/feed/atom/',
			'/sitemap.xml',
			'/sitemap_index.xml',
			'/wp-sitemap-posts-post-1.xml',
			'/robots.txt',
			'/favicon.ico',
			'/wc-api/v3/orders',
			'/some-script.php',
			'/wp-content/uploads/2024/01/image.jpg',
			'/wp-includes/js/jquery/jquery.min.js',
			'/post/trackback/',
			'/blog/wp-admin/',
		);
		foreach ( $paths as $path ) {
			$this->assertSame( 'bypass', $this->decide( $path )['action'], $path );
		}

		$this->assertSame( 'param', $this->decide( '/?rest_route=/wp/v2/posts' )['reason'] );
		$this->assertSame( 'bypass', $this->decide( '/api/wp/v2/posts', array(), array(), array( 'rest_prefix' => 'api' ) )['action'], 'Custom REST prefix.' );
	}

	public function test_woocommerce_and_preview_parameters_bypass(): void {
		$uris = array(
			'/?add-to-cart=12',
			'/shop/?add-to-cart=12&quantity=1',
			'/cart/?removed_item=1',
			'/cart/?remove_item=abc',
			'/?wc-ajax=get_refreshed_fragments',
			'/?wc-api=callback',
			'/about/?preview=true',
			'/?p=12&preview_id=12',
			'/about/?shso_verify=token',
		);
		foreach ( $uris as $uri ) {
			$decision = $this->decide( $uri, array(), array(), array( 'keep_query' => array( 'p', 'add-to-cart', 'preview' ) ) );
			$this->assertSame( 'bypass', $decision['action'], $uri );
		}
		$this->assertSame( 'param', $this->decide( '/?add-to-cart=12' )['reason'] );
	}

	public function test_tracking_parameters_are_stripped_from_the_key(): void {
		$plain   = $this->decide( '/about/' );
		$tracked = $this->decide( '/about/?utm_source=newsletter&utm_medium=email&fbclid=abc&gclid=xyz&UTM_CAMPAIGN=x' );

		$this->assertSame( 'cache', $tracked['action'] );
		$this->assertSame( $plain['dir'] . $plain['file'], $tracked['dir'] . $tracked['file'] );
		$this->assertSame( 'https://example.test/about/', $tracked['url'] );
	}

	public function test_unknown_query_parameters_bypass(): void {
		$this->assertSame( 'query', $this->decide( '/about/?foo=1' )['reason'] );
		$this->assertSame( 'query', $this->decide( '/?s=search+term' )['reason'] );
		$this->assertSame( 'query', $this->decide( '/about/?utm_source=x&random=1' )['reason'] );
		$this->assertSame( 'cache', $this->decide( '/about/?' )['action'], 'An empty query string is harmless.' );
	}

	public function test_kept_query_parameters_create_variants(): void {
		$site  = array( 'keep_query' => array( 'lang' ) );
		$plain = $this->decide( '/about/', array(), array(), $site );
		$de    = $this->decide( '/about/?lang=de', array(), array(), $site );
		$fr    = $this->decide( '/about/?lang=fr', array(), array(), $site );
		$de2   = $this->decide( '/about/?utm_source=x&lang=de', array(), array(), $site );

		$this->assertSame( 'cache', $de['action'] );
		$this->assertSame( $plain['dir'], $de['dir'] );
		$this->assertNotSame( $plain['file'], $de['file'] );
		$this->assertNotSame( $de['file'], $fr['file'] );
		$this->assertSame( $de['file'], $de2['file'] );
		$this->assertSame( 'https://example.test/about/?lang=de', $de['url'] );
		$this->assertSame( array( 'lang' => 'de' ), $de['variant']['query'] );

		// Parameter names are case sensitive for WordPress: an unknown casing must not reuse the variant.
		$this->assertSame( 'bypass', $this->decide( '/about/?LANG=de', array(), array(), $site )['action'] );
		// Over-long values would allow unlimited variants.
		$this->assertSame( 'bypass', $this->decide( '/about/?lang=' . str_repeat( 'x', 200 ), array(), array(), $site )['action'] );
	}

	public function test_bypass_cookies(): void {
		$cookies = array(
			'wordpress_logged_in_abc123'  => 'admin|123',
			'wp-postpass_abc'             => 'x',
			'comment_author_abc'          => 'Jane',
			'woocommerce_items_in_cart'   => '1',
			'woocommerce_cart_hash'       => 'abc',
			'wp_woocommerce_session_abc'  => 'x',
			'edd_items_in_cart'           => '1',
			'wp-resetpass-abc'            => 'x',
			'wordpress_no_cache'          => '1',
		);
		foreach ( $cookies as $name => $value ) {
			$decision = $this->decide( '/about/', array(), array( $name => $value ) );
			$this->assertSame( 'bypass', $decision['action'], $name );
			$this->assertSame( 'cookie', $decision['reason'] );
		}

		$site = array( 'bypass_cookies' => array( 'my_session', 'shop.*_token' ) );
		$this->assertSame( 'bypass', $this->decide( '/', array(), array( 'my_session_x' => '1' ), $site )['action'] );
		// PHP turns dots in cookie names into underscores.
		$this->assertSame( 'bypass', $this->decide( '/', array(), array( 'shop_abc_token' => '1' ), $site )['action'] );

		$harmless = array(
			'_ga'                   => 'GA1.2.3',
			'wordpress_test_cookie' => 'WP Cookie check',
			'cookie_consent'        => 'yes',
		);
		$this->assertSame( 'cache', $this->decide( '/', array(), $harmless, $site )['action'] );
	}

	public function test_wptouch_switch_bypasses_only_without_mobile_variant(): void {
		$cookies = array( 'wptouch_switch_toggle' => 'desktop' );
		$this->assertSame( 'bypass', $this->decide( '/', array(), $cookies )['action'] );
		$this->assertSame( 'cache', $this->decide( '/', array(), $cookies, array( 'mobile' => true ) )['action'] );
	}

	public function test_excluded_url_patterns(): void {
		$site = array( 'exclude_urls' => array( '/checkout/', '/landing/*', '#^/private#', '/café/', '?lang=xx' ) );

		$this->assertSame( 'excluded', $this->decide( '/checkout/', array(), array(), $site )['reason'] );
		$this->assertSame( 'excluded', $this->decide( '/de/checkout/order-received/', array(), array(), $site )['reason'] );
		$this->assertSame( 'excluded', $this->decide( '/landing/offer/', array(), array(), $site )['reason'] );
		$this->assertSame( 'excluded', $this->decide( '/private-area/', array(), array(), $site )['reason'] );
		$this->assertSame( 'excluded', $this->decide( '/caf%C3%A9/', array(), array(), $site )['reason'], 'Encoded and decoded forms match.' );
		$this->assertSame( 'excluded', $this->decide( '/about/?lang=xx', array(), array(), array_merge( $site, array( 'keep_query' => array( 'lang' ) ) ) )['reason'] );
		$this->assertSame( 'cache', $this->decide( '/public/', array(), array(), $site )['action'] );
		$this->assertSame( 'cache', $this->decide( '/not-private/', array(), array(), $site )['action'], 'Regex is anchored.' );
	}

	public function test_host_header_must_be_allowed(): void {
		$this->assertSame( 'host_not_allowed', $this->decide( '/', array( 'HTTP_HOST' => 'evil.test' ) )['reason'] );
		$this->assertSame( 'host_not_allowed', $this->decide( '/', array( 'HTTP_HOST' => 'example.test:8080' ) )['reason'] );
		$this->assertSame( 'host', $this->decide( '/', array( 'HTTP_HOST' => 'exa mple.test' ) )['reason'] );
		$this->assertSame( 'host', $this->decide( '/', array( 'HTTP_HOST' => '../../etc' ) )['reason'] );
		$this->assertSame( 'host', $this->decide( '/', array( 'HTTP_HOST' => '' ) )['reason'] );
		$this->assertSame( 'cache', $this->decide( '/', array( 'HTTP_HOST' => 'WWW.Example.TEST' ) )['action'], 'Hosts are case-insensitive.' );
		$this->assertSame( 'www.example.test/', $this->decide( '/', array( 'HTTP_HOST' => 'www.example.test' ) )['dir'] );
	}

	public function test_https_and_http_are_separate_variants(): void {
		$https = $this->decide( '/about/' );
		$http  = $this->decide(
			'/about/',
			array(
				'HTTPS'       => '',
				'SERVER_PORT' => '80',
			)
		);
		$port  = $this->decide(
			'/about/',
			array(
				'HTTPS'       => '',
				'SERVER_PORT' => '443',
			)
		);
		// A spoofed proxy header must not select the HTTPS variant (WordPress ignores it too).
		$spoof = $this->decide(
			'/about/',
			array(
				'HTTPS'                  => '',
				'SERVER_PORT'            => '80',
				'HTTP_X_FORWARDED_PROTO' => 'https',
			)
		);

		$this->assertSame( $https['dir'], $http['dir'] );
		$this->assertSame( 'index.html', $https['file'] );
		$this->assertSame( 'index-http.html', $http['file'] );
		$this->assertSame( 'index.html', $port['file'] );
		$this->assertSame( 'index-http.html', $spoof['file'] );
		$this->assertSame( 'http://example.test/about/', $http['url'] );
	}

	public function test_mobile_variant(): void {
		$iphone  = array( 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148 Safari/604.1' );
		$desktop = array( 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0' );
		$hint    = array(
			'HTTP_USER_AGENT'       => 'Mozilla/5.0 (Linux) Chrome/120.0',
			'HTTP_SEC_CH_UA_MOBILE' => '?1',
		);
		$on      = array( 'mobile' => true );

		$this->assertSame( 'index-mobile.html', $this->decide( '/', $iphone, array(), $on )['file'] );
		$this->assertTrue( $this->decide( '/', $iphone, array(), $on )['mobile'] );
		$this->assertSame( 'index.html', $this->decide( '/', $desktop, array(), $on )['file'] );
		$this->assertSame( 'index-mobile.html', $this->decide( '/', $hint, array(), $on )['file'] );
		$this->assertSame( 'index.html', $this->decide( '/', $iphone )['file'], 'No mobile variant unless enabled.' );
	}

	public function test_vary_cookies_create_variants(): void {
		$site = array( 'vary_cookies' => array( 'wmc_current_currency', 'pll.language' ) );

		$none = $this->decide( '/shop/', array(), array(), $site );
		$eur  = $this->decide( '/shop/', array(), array( 'wmc_current_currency' => 'EUR' ), $site );
		$usd  = $this->decide( '/shop/', array(), array( 'wmc_current_currency' => 'USD' ), $site );
		$lang = $this->decide( '/shop/', array(), array( 'pll_language' => 'de' ), $site );

		$this->assertSame( 'index.html', $none['file'] );
		$this->assertNotSame( $none['file'], $eur['file'] );
		$this->assertNotSame( $eur['file'], $usd['file'] );
		$this->assertNotSame( $none['file'], $lang['file'] );
		$this->assertSame( array( 'wmc_current_currency' ), $eur['variant']['vary'], 'Only names are kept, never values.' );

		$evil = $this->decide( '/shop/', array(), array( 'wmc_current_currency' => '<script>alert(1)</script>' ), $site );
		$this->assertSame( 'bypass', $evil['action'] );
	}

	public function test_trailing_slash_is_part_of_the_variant(): void {
		$slash    = $this->decide( '/about/' );
		$no_slash = $this->decide( '/about' );
		$this->assertSame( $slash['dir'], $no_slash['dir'] );
		$this->assertSame( 'index-ns.html', $no_slash['file'] );
	}

	public function test_disabled_or_safe_mode_config_bypasses(): void {
		$this->assertSame( 'disabled', $this->decide( '/', array(), array(), array( 'enabled' => false ) )['reason'] );
		$this->assertSame( 'disabled', $this->decide( '/', array(), array(), array( 'safe_mode' => true ) )['reason'] );
		$this->assertSame( 'site', Delivery::decide( $this->server( '/' ), array(), array() )['reason'] );
	}

	public function test_path_segments_block_traversal(): void {
		foreach ( array( '/../etc/passwd', '/a/../b/', '/%2e%2e/x/', '/%2E%2E/x/', '/a/%2F/b/', '/a/%5c/b/', '/a//b/', '/a/./b/', '/a/%00/', "/a\0b/", '/a\\b/' ) as $path ) {
			$this->assertNull( Delivery::path_segments( $path ), $path );
		}
		foreach ( array( '/../etc/passwd', '/%2e%2e/%2e%2e/wp-config.php/' ) as $path ) {
			$this->assertSame( 'bypass', $this->decide( $path )['action'], $path );
		}
		$this->assertSame( 'uri', $this->decide( "/a\nb/" )['reason'] );
		$this->assertSame( 'uri', $this->decide( 'http://evil.test/' )['reason'], 'Absolute-form request targets are not cached.' );
	}

	public function test_path_segments_are_sanitized(): void {
		$this->assertSame( array(), Delivery::path_segments( '/' ) );
		$this->assertSame( array( 'blog', 'my-post_1.2~x' ), Delivery::path_segments( '/blog/my-post_1.2~x/' ) );

		// Unicode: raw UTF-8 and any percent-encoding casing map to the same key.
		$encoded = Delivery::path_segments( '/caf%C3%A9/' );
		$this->assertSame( array( 'caf%c3%a9' ), $encoded );
		$this->assertSame( $encoded, Delivery::path_segments( '/café/' ) );
		$this->assertSame( $encoded, Delivery::path_segments( '/caf%c3%a9/' ) );

		// Uppercase, hidden, "index…" and very long segments are hashed.
		foreach ( array( '/About/', '/.htaccess/', '/index.html/', '/' . str_repeat( 'a', 200 ) . '/' ) as $path ) {
			$segments = Delivery::path_segments( $path );
			$this->assertIsArray( $segments, $path );
			$this->assertMatchesRegularExpression( '/^=[0-9a-f]{20}$/', $segments[0], $path );
		}
		$this->assertNotSame( Delivery::path_segments( '/About/' ), Delivery::path_segments( '/about/' ) );

		// Depth cap.
		$this->assertCount( 20, Delivery::path_segments( '/' . implode( '/', array_fill( 0, 20, 'a' ) ) . '/' ) );
		$this->assertNull( Delivery::path_segments( '/' . implode( '/', array_fill( 0, 21, 'a' ) ) . '/' ) );

		// Total length cap.
		$this->assertSame( 'uri', $this->decide( '/' . str_repeat( 'a', 3000 ) . '/' )['reason'] );

		// Every produced segment is file-system safe.
		foreach ( array( '/Ünïcödé/日本語/', '/a b/c+d/', '/%3Cscript%3E/', '/semi;colon/', '/quote"s/' ) as $path ) {
			foreach ( (array) Delivery::path_segments( $path ) as $segment ) {
				$this->assertMatchesRegularExpression( '/^[a-z0-9._~%=-]+$/', $segment, $path );
				$this->assertNotContains( $segment, array( '.', '..' ) );
			}
		}
	}

	public function test_host_and_site_keys(): void {
		$this->assertSame( 'example.test', Delivery::normalize_host( 'Example.TEST' ) );
		$this->assertSame( 'localhost:8080', Delivery::normalize_host( 'localhost:8080' ) );
		$this->assertSame( '[::1]:8080', Delivery::normalize_host( '[::1]:8080' ) );
		$this->assertNull( Delivery::normalize_host( 'evil.test/..' ) );
		$this->assertNull( Delivery::normalize_host( 'a:b:c' ) );
		$this->assertSame( 'localhost+8080', Delivery::host_key( 'localhost:8080' ) );
		$this->assertSame( 'example.test~blog2', Delivery::site_key( 'example.test', '/blog2/' ) );
		$this->assertSame( 'example.test', Delivery::site_key( 'example.test', '/' ) );
	}

	public function test_subdirectory_multisite_prefixes(): void {
		$root  = Config::build_site(
			array(
				'enabled' => true,
				'hosts'   => array( 'example.test' ),
			)
		);
		$blog2 = Config::build_site(
			array(
				'enabled'      => true,
				'hosts'        => array( 'example.test' ),
				'exclude_urls' => array( '/blog2/members/' ),
			)
		);
		$config = array(
			'sites' => array(
				'/'       => $root,
				'/blog2/' => $blog2,
			),
		);

		$post = Delivery::decide( $this->server( '/blog2/hello/' ), array(), $config );
		$this->assertSame( '/blog2/', $post['prefix'] );
		$this->assertSame( 'example.test~blog2', $post['site_key'] );
		$this->assertSame( 'example.test/blog2/hello/', $post['dir'] );

		$this->assertSame( '/blog2/', Delivery::decide( $this->server( '/blog2' ), array(), $config )['prefix'] );
		$this->assertSame( '/', Delivery::decide( $this->server( '/blog22/' ), array(), $config )['prefix'] );
		$this->assertSame( 'excluded', Delivery::decide( $this->server( '/blog2/members/' ), array(), $config )['reason'] );
		$this->assertSame( 'cache', Delivery::decide( $this->server( '/members/' ), array(), $config )['action'], 'Rules are per site.' );

		$sub_only = array( 'sites' => array( '/wp/' => $root ) );
		$this->assertSame( 'site', Delivery::decide( $this->server( '/other/' ), array(), $sub_only )['reason'] );
	}

	public function test_entry_format_and_expiry(): void {
		$meta    = array(
			'v'       => Delivery::FORMAT_VERSION,
			'created' => 1000,
			'expires' => 5000,
			'url'     => 'https://example.test/',
			'headers' => array( 'Link: <https://example.test/wp-json/>; rel="https://api.w.org/"' ),
		);
		$body    = "<html>\n<body>line1\nline2</body></html>";
		$encoded = Delivery::encode_entry( $meta, $body );

		$this->assertIsArray( json_decode( (string) strstr( $encoded, "\n", true ), true ), 'First line is the JSON metadata.' );
		$decoded = Delivery::decode_entry( $encoded );
		$this->assertSame( $body, $decoded['body'] );
		$this->assertSame( 'https://example.test/', $decoded['meta']['url'] );
		$this->assertNull( Delivery::decode_entry( 'no metadata' ) );
		$this->assertNull( Delivery::decode_entry( '{"v":99}' . "\n<html>" ) );

		$this->assertFalse( Delivery::is_expired( $meta, 3600, 2000 ) );
		$this->assertTrue( Delivery::is_expired( $meta, 3600, 4600 ), 'Site lifespan passed.' );
		$this->assertTrue( Delivery::is_expired( $meta, 0, 5000 ), 'Entry expiry passed.' );
		$this->assertTrue( Delivery::is_expired( array( 'created' => 9000 ), 3600, 1000 ), 'Created in the future.' );
		$this->assertTrue( Delivery::is_expired( array(), 3600, 1000 ) );
	}

	public function test_hit_response_headers(): void {
		$meta     = array(
			'created' => 1700000000,
			'size'    => 1234,
			'type'    => 'text/html; charset=UTF-8',
			'headers' => array(
				'Link: <https://example.test/wp-json/>; rel="https://api.w.org/"',
				'Set-Cookie: session=abc',
				'X-SHSO-Cache: MISS',
				'Cache-Control: no-cache',
				'X-Pingback: https://example.test/xmlrpc.php',
			),
		);
		$decision = $this->decide( '/' );
		$response = Delivery::response( $meta, $decision, array(), 1234, false, true );

		$this->assertSame( 200, $response['status'] );
		$this->assertContains( 'X-SHSO-Cache: HIT', $response['headers'] );
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $response['headers'] );
		$this->assertContains( 'Cache-Control: max-age=0, must-revalidate', $response['headers'] );
		$this->assertContains( 'Vary: Accept-Encoding', $response['headers'] );
		$this->assertContains( 'Content-Length: 1234', $response['headers'] );
		$this->assertContains( 'Last-Modified: Tue, 14 Nov 2023 22:13:20 GMT', $response['headers'] );
		$this->assertMatchesRegularExpression( '#^ETag: W/"[0-9a-f]+-[0-9a-f]+"$#', implode( "\n", preg_grep( '/^ETag:/', $response['headers'] ) ) );
		$this->assertSame(
			array( 'Link: <https://example.test/wp-json/>; rel="https://api.w.org/"', 'X-Pingback: https://example.test/xmlrpc.php' ),
			$response['replay'],
			'Set-Cookie, cache headers and our own headers are never replayed.'
		);

		$gzip = Delivery::response( $meta, $this->decide( '/', array(), array(), array( 'mobile' => true ) ), array(), 400, true, false );
		$this->assertContains( 'Content-Encoding: gzip', $gzip['headers'] );
		$this->assertContains( 'Vary: Accept-Encoding, User-Agent', $gzip['headers'] );
		$this->assertEmpty( preg_grep( '/^Content-Length:/', $gzip['headers'] ) );

		$bad_type = Delivery::response( array_merge( $meta, array( 'type' => "text/html\r\nSet-Cookie: x" ) ), $decision, array(), 1, false, false );
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $bad_type['headers'] );
	}

	public function test_conditional_requests_get_304(): void {
		$meta     = array(
			'created' => 1700000000,
			'size'    => 1234,
		);
		$decision = $this->decide( '/' );
		$first    = Delivery::response( $meta, $decision, array(), 1234, false, true );
		$etag     = substr( (string) current( preg_grep( '/^ETag:/', $first['headers'] ) ), 6 );

		$this->assertSame( 304, Delivery::response( $meta, $decision, array( 'HTTP_IF_NONE_MATCH' => $etag ), 1234, false, true )['status'] );
		$this->assertSame( 304, Delivery::response( $meta, $decision, array( 'HTTP_IF_NONE_MATCH' => '"other", ' . $etag ), 1234, false, true )['status'] );
		$this->assertSame( 200, Delivery::response( $meta, $decision, array( 'HTTP_IF_NONE_MATCH' => '"other"' ), 1234, false, true )['status'] );
		$this->assertSame( 304, Delivery::response( $meta, $decision, array( 'HTTP_IF_MODIFIED_SINCE' => gmdate( 'D, d M Y H:i:s', 1700000500 ) . ' GMT' ), 1234, false, true )['status'] );
		$this->assertSame( 200, Delivery::response( $meta, $decision, array( 'HTTP_IF_MODIFIED_SINCE' => gmdate( 'D, d M Y H:i:s', 1699999000 ) . ' GMT' ), 1234, false, true )['status'] );

		$not_modified = Delivery::response( $meta, $decision, array( 'HTTP_IF_NONE_MATCH' => $etag ), 1234, false, true );
		$this->assertEmpty( preg_grep( '/^Content-(Type|Length):/', $not_modified['headers'] ) );
		$this->assertContains( 'X-SHSO-Cache: HIT', $not_modified['headers'] );
	}

	public function test_gzip_negotiation(): void {
		$this->assertTrue( Delivery::accepts_gzip( array( 'HTTP_ACCEPT_ENCODING' => 'gzip, deflate, br' ) ) );
		$this->assertTrue( Delivery::accepts_gzip( array( 'HTTP_ACCEPT_ENCODING' => 'gzip;q=0.5' ) ) );
		$this->assertFalse( Delivery::accepts_gzip( array( 'HTTP_ACCEPT_ENCODING' => 'br' ) ) );
		$this->assertFalse( Delivery::accepts_gzip( array( 'HTTP_ACCEPT_ENCODING' => 'gzip;q=0' ) ) );
		$this->assertFalse( Delivery::accepts_gzip( array( 'HTTP_ACCEPT_ENCODING' => 'identity' ) ) );
		$this->assertFalse( Delivery::accepts_gzip( array() ) );
	}

	public function test_header_helpers(): void {
		$this->assertTrue( Delivery::is_replayable_header( 'Link: <https://example.test/wp-json/>; rel="https://api.w.org/"' ) );
		$this->assertFalse( Delivery::is_replayable_header( 'Set-Cookie: a=b' ) );
		$this->assertFalse( Delivery::is_replayable_header( 'set-cookie: a=b' ) );
		$this->assertFalse( Delivery::is_replayable_header( 'X-SHSO-Cache: MISS' ) );
		$this->assertFalse( Delivery::is_replayable_header( "X-Foo: a\r\nSet-Cookie: b=c" ) );
		$this->assertFalse( Delivery::is_replayable_header( 'Not a header' ) );

		$this->assertTrue( Delivery::has_unsafe_set_cookie( array( 'Set-Cookie: PHPSESSID=abc; path=/' ), array() ) );
		$this->assertFalse( Delivery::has_unsafe_set_cookie( array( 'Set-Cookie: pll_language=de; path=/' ), array( 'pll_language' ) ) );
		$this->assertFalse( Delivery::has_unsafe_set_cookie( array( 'Content-Type: text/html' ), array() ) );
	}

	public function test_variant_file_names(): void {
		$this->assertSame( 'index.html', Delivery::variant_file( true, true, false ) );
		$this->assertSame( 'index-http-ns-mobile.html', Delivery::variant_file( false, false, true ) );
		$this->assertSame(
			Delivery::variant_file( true, true, false, array( 'a' => '1', 'b' => '2' ) ),
			Delivery::variant_file( true, true, false, array( 'b' => '2', 'a' => '1' ) ),
			'Parameter order does not matter.'
		);
		$this->assertTrue( Delivery::is_variant_file( 'index-http-0123456789abcdef.html' ) );
		$this->assertFalse( Delivery::is_variant_file( 'index.html.gz' ) );
		$this->assertFalse( Delivery::is_variant_file( '.htaccess' ) );
	}

	public function test_lookup_and_config_loading_from_disk(): void {
		$root = sys_get_temp_dir() . '/shso-tests/delivery-' . bin2hex( random_bytes( 4 ) ) . '/';
		mkdir( $root . 'config', 0777, true );
		file_put_contents( $root . 'config/example.test.json', json_encode( $this->config() ) );

		Delivery::reset();
		$config = Delivery::load_config( $root, 'example.test' );
		$this->assertIsArray( $config );
		$this->assertNull( Delivery::load_config( $root, 'other.test' ) );

		$decision = Delivery::decide( $this->server( '/about/' ), array(), $config );
		$this->assertNull( Delivery::lookup( $root, $decision, time() ), 'Nothing stored yet.' );

		mkdir( $root . 'pages/' . $decision['dir'], 0777, true );
		$meta = array(
			'v'       => Delivery::FORMAT_VERSION,
			'created' => time() - 60,
			'expires' => time() + 600,
		);
		file_put_contents( $root . 'pages/' . $decision['dir'] . $decision['file'], Delivery::encode_entry( $meta, '<html>ok</html>' ) );

		$entry = Delivery::lookup( $root, $decision, time() );
		$this->assertSame( '<html>ok</html>', $entry['body'] );
		$this->assertNull( Delivery::lookup( $root, $decision, time() + 3600 ), 'Expired.' );

		$bypass = $this->decide( '/wp-admin/' );
		$this->assertNull( Delivery::lookup( $root, $bypass, time() ) );

		$this->remove_dir( $root );
		Delivery::reset();
	}

	/**
	 * Recursively remove a temp directory.
	 *
	 * @param string $dir Directory.
	 */
	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}
}
