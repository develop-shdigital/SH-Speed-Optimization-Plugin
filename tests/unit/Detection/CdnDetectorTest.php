<?php
/**
 * Tests for CDN, server cache and compression detection from headers.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Detection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Detection\CdnDetector;

final class CdnDetectorTest extends TestCase {

	public static function cdn_provider(): array {
		return array(
			'cloudflare ray'    => array( array( 'CF-RAY' => '8a1b2c3d4e5f-FRA' ), 'cloudflare' ),
			'cloudflare server' => array( array( 'server' => 'cloudflare' ), 'cloudflare' ),
			'quic.cloud'        => array( array( 'x-qc-pop' => 'EU-DE-FRA-01' ), 'quic_cloud' ),
			'bunny pullzone'    => array( array( 'cdn-pullzone' => '12345' ), 'bunny' ),
			'bunny server'      => array( array( 'server' => 'BunnyCDN-DE1-718' ), 'bunny' ),
			'cloudfront id'     => array( array( 'x-amz-cf-id' => 'abc==' ), 'cloudfront' ),
			'cloudfront via'    => array( array( 'via' => '1.1 7f1c.cloudfront.net (CloudFront)' ), 'cloudfront' ),
			'fastly served-by'  => array( array( 'x-served-by' => 'cache-fra-eddf8230100-FRA', 'x-cache' => 'MISS' ), 'fastly' ),
			'fastly header'     => array( array( 'fastly-debug-digest' => 'abc' ), 'fastly' ),
			'akamai header'     => array( array( 'x-akamai-transformed' => '9 - 0 pmb=mRUM,1' ), 'akamai' ),
			'akamai server'     => array( array( 'server' => 'AkamaiGHost' ), 'akamai' ),
			'sucuri'            => array( array( 'x-sucuri-id' => '11005' ), 'sucuri' ),
			'keycdn'            => array( array( 'server' => 'keycdn-engine' ), 'keycdn' ),
			'stackpath'         => array( array( 'x-hw' => '1700000000.cds123.fr3.c' ), 'stackpath' ),
			'azure'             => array( array( 'x-azure-ref' => '0abc' ), 'azure' ),
			'generic x-cdn'     => array( array( 'x-cdn' => 'Imperva' ), 'generic' ),
			'none'              => array( array( 'server' => 'nginx', 'via' => '1.1 varnish (Varnish/7.1)' ), null ),
		);
	}

	#[DataProvider( 'cdn_provider' )]
	public function test_cdn( array $headers, ?string $expected ): void {
		$this->assertSame( $expected, CdnDetector::cdn( $headers ) );
	}

	public function test_server_cache_signatures(): void {
		$this->assertSame( 'litespeed', CdnDetector::server_cache( array( 'x-litespeed-cache' => 'miss' ), array( 'x-litespeed-cache' => 'hit' ) )['type'] );
		$this->assertTrue( CdnDetector::server_cache( array( 'x-litespeed-cache' => 'miss' ), array( 'x-litespeed-cache' => 'hit' ) )['hit'] );

		$varnish = CdnDetector::server_cache( array( 'x-varnish' => '32770' ), array( 'x-varnish' => '32772 32771' ) );
		$this->assertSame( 'varnish', $varnish['type'] );
		$this->assertTrue( $varnish['hit'], 'Two transaction ids mean a cache hit.' );

		$this->assertSame( 'varnish', CdnDetector::server_cache( array( 'via' => '1.1 varnish' ) )['type'] );
		$this->assertSame( 'nginx', CdnDetector::server_cache( array( 'x-fastcgi-cache' => 'HIT' ) )['type'] );
		$this->assertSame( 'nginx', CdnDetector::server_cache( array( 'x-kinsta-cache' => 'HIT' ) )['type'] );
		$this->assertSame( 'nginx', CdnDetector::server_cache( array( 'x-proxy-cache' => 'MISS' ) )['type'] );
		$this->assertSame( 'varnish', CdnDetector::server_cache( array( 'x-cache' => 'HIT: 1', 'x-wpe-request-id' => 'x' ) )['type'] );
		$this->assertSame( 'varnish', CdnDetector::server_cache( array( 'x-styx-req-id' => 'abc' ) )['type'] );
	}

	public function test_generic_cache_needs_hit_or_age_on_second_request(): void {
		$none = CdnDetector::server_cache( array( 'x-cache' => 'MISS' ), array( 'x-cache' => 'MISS' ) );
		$this->assertNull( $none['type'] );

		$hit = CdnDetector::server_cache( array( 'x-cache' => 'MISS' ), array( 'x-cache' => 'HIT' ) );
		$this->assertSame( 'generic', $hit['type'] );
		$this->assertTrue( $hit['hit'] );
		$this->assertTrue( $hit['generic_only'] );

		$age = CdnDetector::server_cache( array( 'server' => 'nginx' ), array( 'age' => '12' ) );
		$this->assertSame( 'generic', $age['type'] );

		// CloudFront reports its own edge hits in X-Cache: that is not a server cache.
		$edge = CdnDetector::server_cache( array( 'x-amz-cf-id' => 'a' ), array( 'x-amz-cf-id' => 'b', 'x-cache' => 'Hit from cloudfront' ) );
		$this->assertNull( $edge['type'] );
		$this->assertTrue( CdnDetector::edge_cache_hit( array( 'x-amz-cf-id' => 'b', 'x-cache' => 'Hit from cloudfront' ) ) );
	}

	public function test_own_headers_are_ignored(): void {
		$result = CdnDetector::server_cache( array( 'x-shso-cache' => 'MISS' ), array( 'x-shso-cache' => 'HIT' ) );
		$this->assertNull( $result['type'] );
		$this->assertArrayNotHasKey( 'x-shso-cache', CdnDetector::normalize( array( 'X-SHSO-Cache' => 'HIT' ) ) );
	}

	public function test_edge_cache_hit(): void {
		$this->assertTrue( CdnDetector::edge_cache_hit( array( 'cf-cache-status' => 'HIT' ) ) );
		$this->assertFalse( CdnDetector::edge_cache_hit( array( 'cf-cache-status' => 'DYNAMIC' ) ) );
		$this->assertTrue( CdnDetector::edge_cache_hit( array( 'x-qc-cache' => 'hit' ) ) );
	}

	public function test_compression(): void {
		$this->assertSame( 'br', CdnDetector::compression( array( 'Content-Encoding' => 'br' ) ) );
		$this->assertSame( 'gzip', CdnDetector::compression( array( 'content-encoding' => 'gzip' ) ) );
		$this->assertSame( 'zstd', CdnDetector::compression( array( 'content-encoding' => 'zstd' ) ) );
		$this->assertSame( 'none', CdnDetector::compression( array( 'content-type' => 'text/html' ) ) );
	}

	public function test_subset_drops_cookies(): void {
		$subset = CdnDetector::subset(
			array(
				'set-cookie'      => 'session=secret',
				'server'          => 'nginx',
				'cf-cache-status' => 'HIT',
			)
		);
		$this->assertArrayNotHasKey( 'set-cookie', $subset );
		$this->assertSame( 'nginx', $subset['server'] );
		$this->assertSame( 'HIT', $subset['cf-cache-status'] );
	}
}
