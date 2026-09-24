<?php
/**
 * Tests for browser cache header parsing.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Detection;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Detection\BrowserCacheDetector;

final class BrowserCacheDetectorTest extends TestCase {

	private const NOW = 1750000000;

	public function test_long_max_age_is_configured(): void {
		$result = BrowserCacheDetector::parse( array( 'Cache-Control' => 'public, max-age=31536000, immutable', 'ETag' => '"abc"' ), self::NOW );
		$this->assertSame( 31536000, $result['max_age'] );
		$this->assertTrue( $result['configured'] );
		$this->assertTrue( $result['etag'] );
	}

	public function test_short_max_age_is_not_configured(): void {
		$result = BrowserCacheDetector::parse( array( 'cache-control' => 'max-age=3600' ), self::NOW );
		$this->assertSame( 3600, $result['max_age'] );
		$this->assertFalse( $result['configured'] );
		$this->assertFalse( $result['etag'] );
	}

	public function test_s_maxage_is_ignored(): void {
		$result = BrowserCacheDetector::parse( array( 'cache-control' => 's-maxage=31536000' ), self::NOW );
		$this->assertNull( $result['max_age'] );
		$this->assertFalse( $result['configured'] );
	}

	public function test_expires_far_in_the_future(): void {
		$result = BrowserCacheDetector::parse( array( 'expires' => gmdate( 'D, d M Y H:i:s', self::NOW + 30 * 86400 ) . ' GMT' ), self::NOW );
		$this->assertNull( $result['max_age'] );
		$this->assertSame( 30 * 86400, $result['expires_in'] );
		$this->assertTrue( $result['configured'] );
	}

	public function test_expires_relative_to_date_header(): void {
		$date   = self::NOW - 86400 * 100; // Server clock far behind ours.
		$result = BrowserCacheDetector::parse(
			array(
				'date'    => gmdate( 'D, d M Y H:i:s', $date ) . ' GMT',
				'expires' => gmdate( 'D, d M Y H:i:s', $date + 8 * 86400 ) . ' GMT',
			),
			self::NOW
		);
		$this->assertTrue( $result['configured'] );
	}

	public function test_max_age_wins_over_expires(): void {
		$result = BrowserCacheDetector::parse(
			array(
				'cache-control' => 'max-age=60',
				'expires'       => gmdate( 'D, d M Y H:i:s', self::NOW + 365 * 86400 ) . ' GMT',
			),
			self::NOW
		);
		$this->assertFalse( $result['configured'] );
	}

	public function test_no_store_and_no_cache(): void {
		$this->assertFalse( BrowserCacheDetector::parse( array( 'cache-control' => 'no-store', 'expires' => gmdate( 'D, d M Y H:i:s', self::NOW + 365 * 86400 ) . ' GMT' ), self::NOW )['configured'] );
		$this->assertFalse( BrowserCacheDetector::parse( array( 'cache-control' => 'no-cache, max-age=31536000' ), self::NOW )['configured'] );
	}

	public function test_invalid_expires_means_expired(): void {
		$result = BrowserCacheDetector::parse( array( 'expires' => '0' ), self::NOW );
		$this->assertSame( 0, $result['expires_in'] );
		$this->assertFalse( $result['configured'] );
	}

	public function test_no_headers(): void {
		$result = BrowserCacheDetector::parse( array(), self::NOW );
		$this->assertNull( $result['max_age'] );
		$this->assertNull( $result['expires_in'] );
		$this->assertFalse( $result['configured'] );
	}
}
