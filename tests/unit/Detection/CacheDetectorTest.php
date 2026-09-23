<?php
/**
 * Tests for cache drop-in identification.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Detection;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Detection\CacheDetector;

final class CacheDetectorTest extends TestCase {

	public function test_advanced_cache_owner(): void {
		$rocket = CacheDetector::advanced_cache_owner( "<?php\ndefined( 'ABSPATH' ) || exit;\ndefine( 'WP_ROCKET_ADVANCED_CACHE', true );" );
		$this->assertSame( 'wp-rocket', $rocket['owner'] );
		$this->assertFalse( $rocket['ours'] );

		$this->assertSame( 'wp-super-cache', CacheDetector::advanced_cache_owner( "<?php\n# WP SUPER CACHE 1.2\nrequire WPCACHEHOME . 'wp-cache-phase1.php';" )['owner'] );
		$this->assertSame( 'litespeed-cache', CacheDetector::advanced_cache_owner( "<?php\n// LiteSpeed Cache\ndefine( 'LSCACHE_ADV_CACHE', true );" )['owner'] );

		$ours = CacheDetector::advanced_cache_owner( "<?php\n/* SH Speed Optimizer page cache drop-in */" );
		$this->assertTrue( $ours['ours'] );

		$unknown = CacheDetector::advanced_cache_owner( "<?php\n// custom cache" );
		$this->assertNull( $unknown['owner'] );
		$this->assertFalse( $unknown['ours'] );
	}

	public function test_object_cache_name(): void {
		$this->assertSame( 'Redis Object Cache Drop-In', CacheDetector::object_cache_name( "<?php\n/**\n * Plugin Name: Redis Object Cache Drop-In\n * Version: 2.5\n */" ) );
		$this->assertSame( 'Object Cache Pro', CacheDetector::object_cache_name( "<?php\nnamespace RedisCachePro;" ) );
		$this->assertSame( 'APCu', CacheDetector::object_cache_name( "<?php\nfunction wp_cache_get() { return apcu_fetch(); }" ) );
		$this->assertSame( 'Memcached', CacheDetector::object_cache_name( "<?php\n\$m = new Memcached();" ) );
		$this->assertNull( CacheDetector::object_cache_name( '' ) );
	}
}
