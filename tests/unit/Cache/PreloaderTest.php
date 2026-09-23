<?php
/**
 * Tests for preload URL selection and small pure helpers of the cache manager.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Cache;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Cache\CacheManager;
use SH\SpeedOptimizer\Cache\Preloader;

final class PreloaderTest extends TestCase {

	public function test_filter_urls(): void {
		$urls = array(
			'https://example.test/',
			'https://example.test/about/',
			'https://example.test/about/#team',
			'https://EXAMPLE.test/about/',
			'https://example.test/?s=search',
			'https://other.test/page/',
			'https://example.test/wp-login.php',
			'https://example.test/wp-admin/',
			'https://example.test/my-account/orders/',
			'https://example.test/cart/',
			'https://example.test/checkout/',
			'https://example.test/feed/',
			'https://example.test/?action=logout',
			'mailto:someone@example.test',
			'/relative/',
			42,
			'https://example.test/excluded/',
			'https://example.test/contact/',
		);
		$cacheable = static function ( string $url ): bool {
			return false === strpos( $url, 'excluded' );
		};

		$this->assertSame(
			array( 'https://example.test/', 'https://example.test/about/', 'https://example.test/contact/' ),
			Preloader::filter_urls( $urls, array( 'example.test' ), $cacheable, 50 )
		);
		$this->assertSame( array( 'https://example.test/' ), Preloader::filter_urls( $urls, array( 'example.test' ), $cacheable, 1 ), 'The preload limit is respected.' );
		$this->assertSame( array(), Preloader::filter_urls( $urls, array( 'example.test' ), $cacheable, 0 ) );
	}

	public function test_object_cache_detection(): void {
		$redis = "<?php\n/**\n * Plugin Name: Redis Object Cache Drop-In\n */\nclass WP_Object_Cache { private \$redis; }";
		$this->assertSame( 'Redis Object Cache Drop-In', CacheManager::object_cache_name( $redis ) );
		$this->assertSame( 'redis', CacheManager::object_cache_type( $redis ) );
		$this->assertSame( 'memcached', CacheManager::object_cache_type( '<?php $m = new Memcached();' ) );
		$this->assertSame( 'apcu', CacheManager::object_cache_type( '<?php apcu_fetch( $k );' ) );
		$this->assertSame( 'unknown', CacheManager::object_cache_type( '<?php // custom' ) );
		$this->assertSame( 'object-cache.php', CacheManager::object_cache_name( '<?php // custom' ) );
	}
}
