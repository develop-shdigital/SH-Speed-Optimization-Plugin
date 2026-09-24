<?php
/**
 * RUM rate limiting tests.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Tests\Unit\Diagnostics;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Diagnostics\Rum;

/**
 * @covers \SH\SpeedOptimizer\Diagnostics\Rum
 */
final class RumRateLimitTest extends TestCase {

	public function test_ipv6_clients_share_a_bucket_per_64_network(): void {
		$this->assertSame( Rum::client_bucket( '2001:db8:1:2::1' ), Rum::client_bucket( '2001:db8:1:2:ffff:ffff:ffff:ffff' ) );
		$this->assertNotSame( Rum::client_bucket( '2001:db8:1:2::1' ), Rum::client_bucket( '2001:db8:1:3::1' ) );
		$this->assertSame( '203.0.113.9', Rum::client_bucket( '203.0.113.9' ) );
	}

	public function test_daily_cap_is_checked_before_anything_is_written(): void {
		update_option(
			'shso_rum_daily',
			array(
				'date'  => gmdate( 'Y-m-d' ),
				'count' => 1000000,
			)
		);
		$_SERVER['REMOTE_ADDR'] = '2001:db8::1';
		$before                 = $GLOBALS['shso_test_options'] ?? null;

		$this->assertFalse( Rum::allow() );
		$this->assertSame( $before, $GLOBALS['shso_test_options'] ?? null, 'A rejected beacon writes nothing.' );

		delete_option( 'shso_rum_daily' );
		unset( $_SERVER['REMOTE_ADDR'] );
	}
}
