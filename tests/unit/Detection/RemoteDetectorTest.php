<?php
/**
 * Tests for the loopback-based detection flow (with a fake HTTP client).
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Detection;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Detection\Detector;
use SH\SpeedOptimizer\Detection\RemoteDetector;
use SH\SpeedOptimizer\Detection\SiteProfile;

final class RemoteDetectorTest extends TestCase {

	private const HOME = 'https://example.test/';
	private const CSS  = 'https://example.test/wp-includes/css/dashicons.min.css';
	private const IMG  = 'https://example.test/wp-includes/images/w-logo-blue.png';

	/**
	 * Fake fetcher returning queued responses per URL and recording calls.
	 *
	 * @param array<string,array<int,array>> $responses URL => list of responses.
	 * @param array                          $calls     Recorded calls (by reference).
	 */
	private function fetcher( array $responses, array &$calls ): callable {
		return static function ( string $url, array $args ) use ( &$responses, &$calls ) {
			$calls[] = array( $url, $args );
			if ( empty( $responses[ $url ] ) ) {
				return array(
					'ok'     => false,
					'status' => 0,
					'error'  => 'timeout',
				);
			}
			return array_shift( $responses[ $url ] );
		};
	}

	private static function page( array $headers, int $status = 200 ): array {
		return array(
			'ok'      => true,
			'status'  => $status,
			'headers' => $headers,
			'ttfb_ms' => 180,
			'time_ms' => 240,
			'error'   => '',
		);
	}

	public function test_full_flow(): void {
		$calls = array();
		$fetch = $this->fetcher(
			array(
				self::HOME => array(
					self::page( array( 'server' => 'cloudflare', 'cf-ray' => 'x', 'cf-cache-status' => 'DYNAMIC', 'content-encoding' => 'br', 'x-litespeed-cache' => 'miss', 'set-cookie' => 'a=b' ) ),
					self::page( array( 'server' => 'cloudflare', 'cf-ray' => 'y', 'cf-cache-status' => 'DYNAMIC', 'content-encoding' => 'br', 'x-litespeed-cache' => 'hit' ) ),
				),
				self::CSS  => array( self::page( array( 'cache-control' => 'max-age=31536000', 'etag' => '"1"' ) ) ),
				self::IMG  => array( self::page( array( 'cache-control' => 'max-age=31536000' ) ) ),
			),
			$calls
		);

		$result = RemoteDetector::run( $fetch, self::HOME, array( self::CSS, self::IMG ), array( 'now' => 1750000000 ) );

		$this->assertTrue( $result['loopback']['ok'] );
		$this->assertSame( 200, $result['loopback']['status'] );
		$this->assertSame( 180, $result['loopback']['ttfb_ms'] );
		$this->assertArrayNotHasKey( 'set-cookie', $result['loopback']['headers'] );
		$this->assertSame( 'cloudflare', $result['cdn'] );
		$this->assertSame( 'Cloudflare', $result['cdn_name'] );
		$this->assertSame( 'litespeed', $result['server_cache'] );
		$this->assertTrue( $result['server_cache_hit'] );
		$this->assertTrue( $result['page_cache'] );
		$this->assertSame( 'br', $result['compression'] );
		$this->assertTrue( $result['browser_cache']['configured'] );
		$this->assertSame( self::CSS, $result['browser_cache']['checked_url'] );
		$this->assertSame( 31536000, $result['browser_cache']['max_age'] );
		$this->assertTrue( $result['browser_cache']['etag'] );

		// The page requests ask for compressed responses explicitly.
		$this->assertSame( 'gzip, br', $calls[0][1]['headers']['Accept-Encoding'] );
		$this->assertSame( 'HEAD', $calls[2][1]['method'] );
	}

	public function test_unreachable_site_is_marked_unknown_and_stops_early(): void {
		$calls  = array();
		$result = RemoteDetector::run( $this->fetcher( array(), $calls ), self::HOME, array( self::CSS, self::IMG ) );

		$this->assertFalse( $result['loopback']['ok'] );
		$this->assertSame( 'timeout', $result['loopback']['error'] );
		$this->assertNull( $result['loopback']['ttfb_ms'] );
		$this->assertSame( 'unknown', $result['compression'] );
		$this->assertNull( $result['cdn'] );
		$this->assertNull( $result['browser_cache']['configured'] );
		$this->assertCount( 2, $calls, 'One page request and one asset request; nothing else is attempted.' );
	}

	public function test_exceptions_from_the_client_never_escape(): void {
		$fetch  = static function () {
			throw new \RuntimeException( 'boom' );
		};
		$result = RemoteDetector::run( $fetch, self::HOME, array( self::CSS ) );
		$this->assertFalse( $result['loopback']['ok'] );
		$this->assertSame( 'boom', $result['loopback']['error'] );
	}

	public function test_http_error_status_and_head_fallback(): void {
		$calls  = array();
		$fetch  = $this->fetcher(
			array(
				self::HOME => array( self::page( array( 'server' => 'nginx' ), 401 ) ),
				self::CSS  => array(
					self::page( array(), 405 ),
					self::page( array( 'cache-control' => 'max-age=600' ) ),
				),
			),
			$calls
		);
		$result = RemoteDetector::run( $fetch, self::HOME, array( self::CSS ) );

		$this->assertFalse( $result['loopback']['ok'], 'A password-protected site cannot be verified by loopback.' );
		$this->assertSame( 'HTTP 401', $result['loopback']['error'] );
		$this->assertSame( 'none', $result['compression'] );
		$this->assertFalse( $result['browser_cache']['configured'] );
		$this->assertSame( 600, $result['browser_cache']['max_age'] );
		$this->assertSame( 'HEAD', $calls[1][1]['method'] );
		$this->assertArrayNotHasKey( 'method', $calls[2][1], 'Retried with a GET request.' );
	}

	public function test_generic_hits_are_ignored_when_our_cache_is_installed(): void {
		$calls = array();
		$make  = function () use ( &$calls ) {
			return $this->fetcher(
				array(
					self::HOME => array(
						self::page( array( 'x-cache' => 'MISS' ) ),
						self::page( array( 'x-cache' => 'HIT' ) ),
					),
				),
				$calls
			);
		};

		$foreign = RemoteDetector::run( $make(), self::HOME, array() );
		$this->assertSame( 'generic', $foreign['server_cache'] );
		$this->assertTrue( $foreign['page_cache'] );

		$ours = RemoteDetector::run( $make(), self::HOME, array(), array( 'ours' => true ) );
		$this->assertNull( $ours['server_cache'] );
		$this->assertFalse( $ours['page_cache'] );
	}

	public function test_merge_remote_into_profile(): void {
		$profile = new SiteProfile(
			array(
				'hosting' => array(
					'provider'      => null,
					'provider_name' => null,
					'page_cache'    => false,
				),
			)
		);
		Detector::merge_remote(
			$profile,
			array(
				'loopback'       => array( 'ok' => true ),
				'cdn'            => 'cloudflare',
				'cdn_name'       => 'Cloudflare',
				'edge_cache_hit' => true,
				'page_cache'     => true,
				'page_cache_by'  => 'Cloudflare',
				'compression'    => 'br',
				'browser_cache'  => array( 'configured' => true ),
			)
		);

		$this->assertTrue( $profile->get( 'hosting.page_cache' ) );
		$this->assertSame( array( 'Cloudflare' ), $profile->provided_by( 'page_cache' ) );
		$this->assertSame( 'br', $profile->get( 'server.compression' ) );
		$this->assertTrue( $profile->get( 'browser_cache.configured' ) );
	}
}
