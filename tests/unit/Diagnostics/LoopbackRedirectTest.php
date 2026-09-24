<?php
/**
 * Loopback redirect handling tests.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Tests\Unit\Diagnostics;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Diagnostics\Loopback;

/**
 * @covers \SH\SpeedOptimizer\Diagnostics\Loopback
 */
final class LoopbackRedirectTest extends TestCase {

	public function test_redirect_targets_are_resolved(): void {
		$base = 'https://example.test/blog/post/?a=1';
		$this->assertSame( 'https://example.test/new/', Loopback::resolve_redirect( $base, '/new/' ) );
		$this->assertSame( 'https://example.test/blog/post/other', Loopback::resolve_redirect( $base, 'other' ) );
		$this->assertSame( 'https://cdn.test/x', Loopback::resolve_redirect( $base, '//cdn.test/x' ) );
		$this->assertSame( 'http://example.test/', Loopback::resolve_redirect( $base, 'http://example.test/' ) );
	}

	public function test_unsafe_redirect_targets_are_refused(): void {
		$base = 'https://example.test/';
		$this->assertNull( Loopback::resolve_redirect( $base, 'file:///etc/passwd' ) );
		$this->assertNull( Loopback::resolve_redirect( $base, 'gopher://127.0.0.1:6379/' ) );
		$this->assertNull( Loopback::resolve_redirect( $base, "/x\r\nHost: evil" ) );
		$this->assertNull( Loopback::resolve_redirect( $base, '' ) );
	}

	public function test_other_sites_of_a_subdirectory_network_are_not_own_urls(): void {
		$GLOBALS['shso_test_multisite'] = true;
		$GLOBALS['shso_test_sites']     = array(
			1 => '/',
			2 => '/shop/',
		);
		try {
			$this->assertTrue( Loopback::is_own_url( 'https://example.test/about/' ) );
			$this->assertFalse( Loopback::is_own_url( 'https://example.test/shop/cart/' ), 'Another site of the network.' );
			$GLOBALS['shso_test_blog_id'] = 2;
			$this->assertTrue( Loopback::is_own_url( 'https://example.test/shop/cart/' ) );
		} finally {
			unset( $GLOBALS['shso_test_multisite'], $GLOBALS['shso_test_sites'], $GLOBALS['shso_test_blog_id'] );
		}
	}

	public function test_only_own_hosts_are_followed(): void {
		$own = Loopback::resolve_redirect( home_url( '/' ), '/next/' );
		$this->assertTrue( Loopback::is_own_url( (string) $own ) );
		$this->assertFalse( Loopback::is_own_url( (string) Loopback::resolve_redirect( home_url( '/' ), 'http://169.254.169.254/latest/meta-data/' ) ) );
	}
}
