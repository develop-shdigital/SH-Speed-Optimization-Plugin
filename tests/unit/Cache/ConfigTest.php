<?php
/**
 * Tests for the delivery configuration builders.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Cache;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Cache\Config;
use SH\SpeedOptimizer\Cache\Delivery;

final class ConfigTest extends TestCase {

	public function test_build_site_normalizes_input(): void {
		$site = Config::build_site(
			array(
				'enabled'        => 1,
				'lifespan'       => 5,
				'exclude_urls'   => array( '/checkout/', '/', '*', "/bad\x00/", '/checkout/', '', array( 'x' ) ),
				'bypass_cookies' => array( 'my_cookie', 'bad cookie!', 'wild*' ),
				'vary_cookies'   => array( 'currency', 'no*wildcards' ),
				'ignore_query'   => array( 'UTM_Source', 'fbclid' ),
				'keep_query'     => array( 'lang', 'preview', 'shso_verify', 'bad param' ),
				'hosts'          => array( 'Example.TEST', 'evil host', 'example.test' ),
				'rest_prefix'    => '/api/',
				'mobile'         => 'yes',
			)
		);

		$this->assertTrue( $site['enabled'] );
		$this->assertFalse( $site['safe_mode'] );
		$this->assertSame( 60, $site['lifespan'], 'Clamped to at least one minute.' );
		$this->assertSame( array( '/checkout/', '/bad/' ), $site['exclude_urls'], 'The site root never disables the whole cache.' );
		$this->assertSame( array( 'my_cookie', 'badcookie', 'wild*' ), $site['bypass_cookies'] );
		$this->assertSame( array( 'currency', 'nowildcards' ), $site['vary_cookies'] );
		$this->assertSame( array( 'utm_source', 'fbclid' ), $site['ignore_query'] );
		$this->assertSame( array( 'lang' ), $site['keep_query'], 'Forbidden parameters can never become cache variants.' );
		$this->assertSame( array( 'example.test' ), $site['hosts'] );
		$this->assertSame( 'api', $site['rest_prefix'] );
		$this->assertTrue( $site['mobile'] );

		$this->assertSame( 720 * 3600, Config::build_site( array( 'lifespan' => PHP_INT_MAX ) )['lifespan'] );
	}

	public function test_single_site_configuration(): void {
		$site = Config::build_site(
			array(
				'enabled' => true,
				'hosts'   => Config::hosts_from_urls( array( 'https://example.test/', 'https://example.test/wp' ) ),
			)
		);
		$file = Config::merge_site( array(), Config::prefix_from_url( 'https://example.test' ), $site, 1000 );

		$this->assertSame( Config::VERSION, $file['version'] );
		$this->assertSame( 1000, $file['generated'] );
		$this->assertSame( array( '/' ), array_keys( $file['sites'] ) );
		$this->assertSame( array( 'example.test' ), $file['sites']['/']['hosts'] );

		$roundtrip = json_decode( (string) json_encode( $file ), true );
		$this->assertSame( '/', Delivery::match_site( $roundtrip, '/any/page/' )[0] );
	}

	public function test_subdirectory_multisite_configuration(): void {
		$root  = Config::build_site( array( 'enabled' => true ) );
		$blog2 = Config::build_site( array( 'enabled' => false ) );
		$blog3 = Config::build_site( array( 'enabled' => true ) );

		$file = Config::merge_site( array(), '/', $root, 1 );
		$file = Config::merge_site( $file, Config::prefix_from_url( 'https://example.test/blog2' ), $blog2, 2 );
		$file = Config::merge_site( $file, '/blog3/', $blog3, 3 );

		$this->assertSame( array( '/blog2/', '/blog3/', '/' ), array_keys( $file['sites'] ), 'Longest prefixes first, other sites preserved.' );
		$this->assertSame( array( '/blog2/', '/blog3/' ), Config::nested_prefixes( $file, '/' ) );
		$this->assertSame( array(), Config::nested_prefixes( $file, '/blog2/' ) );

		$this->assertFalse( Delivery::match_site( $file, '/blog2/post/' )[1]['enabled'] );
		$this->assertTrue( Delivery::match_site( $file, '/blog3/post/' )[1]['enabled'] );

		$file = Config::remove_site( $file, '/blog2' );
		$this->assertSame( array( '/blog3/', '/' ), array_keys( $file['sites'] ) );
		$this->assertSame( '/', Delivery::match_site( $file, '/blog2/post/' )[0] );
	}

	public function test_hosts_and_prefix_helpers(): void {
		$this->assertSame( '/', Config::prefix_from_url( 'https://example.test' ) );
		$this->assertSame( '/', Config::prefix_from_url( 'https://example.test/' ) );
		$this->assertSame( '/blog/', Config::prefix_from_url( 'https://example.test/Blog' ) );
		$this->assertSame( '/a/b/', Config::prefix_from_url( 'https://example.test/a/b/' ) );

		$this->assertSame(
			array( 'example.test', 'www.example.test:8443' ),
			Config::hosts_from_urls( array( 'https://Example.test/', 'https://www.example.test:8443/wp/', 'https://example.test/x', 'not a url' ) )
		);
	}

	public function test_tracking_list_contains_common_parameters(): void {
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'fbclid', 'gclid', 'msclkid', '_ga', 'mc_cid' ) as $param ) {
			$this->assertContains( $param, Config::TRACKING_PARAMS );
		}
		$this->assertNotContains( 'ref', Config::TRACKING_PARAMS, 'Affiliate parameters may change content.' );
	}
}
