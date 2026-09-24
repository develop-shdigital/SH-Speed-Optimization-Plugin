<?php
/**
 * Cache root filter tests.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Core\Filesystem;

/**
 * @covers \SH\SpeedOptimizer\Core\Filesystem::cache_root
 */
final class CacheRootTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['shso_test_filters']['shso_cache_dir'] );
	}

	private function root_for( string $dir ): string {
		$GLOBALS['shso_test_filters']['shso_cache_dir'] = array(
			10 => array(
				static function () use ( $dir ) {
					return $dir;
				},
			),
		);
		return Filesystem::cache_root();
	}

	public function test_dedicated_directories_are_accepted(): void {
		$content = wp_normalize_path( WP_CONTENT_DIR );
		$this->assertSame( $content . '/cache/my-speed/', $this->root_for( WP_CONTENT_DIR . '/cache/my-speed' ) );
	}

	public function test_shared_directories_are_refused(): void {
		$default = wp_normalize_path( WP_CONTENT_DIR . '/cache/sh-speed-optimizer/' );
		foreach ( array( '', '/', '/cache', '/uploads/', '/plugins/x', '/themes/', '/mu-plugins/y/', '/cache/../plugins/' ) as $suffix ) {
			$this->assertSame( $default, $this->root_for( WP_CONTENT_DIR . $suffix ), 'Refused: wp-content' . $suffix );
		}
		$this->assertSame( $default, $this->root_for( '/tmp/elsewhere/' ) );
	}
}
