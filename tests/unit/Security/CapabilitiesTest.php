<?php
/**
 * Capabilities tests.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Security\Capabilities;

/**
 * @covers \SH\SpeedOptimizer\Security\Capabilities
 */
final class CapabilitiesTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['shso_test_multisite'], $GLOBALS['shso_test_caps'] );
	}

	public function test_single_site_may_change_server_files(): void {
		$this->assertNull( Capabilities::server_files_blocked_reason() );
		$this->assertNull( Capabilities::server_files_blocked_reason( false ) );
	}

	public function test_multisite_requires_a_network_administrator(): void {
		$GLOBALS['shso_test_multisite'] = true;
		$GLOBALS['shso_test_caps']      = array( 'manage_options' );
		$this->assertNotNull( Capabilities::server_files_blocked_reason(), 'A site administrator may not change network-wide files.' );
		$this->assertNull( Capabilities::server_files_blocked_reason( false ), 'Background tasks do not check the user.' );

		$GLOBALS['shso_test_caps'] = array( 'manage_options', 'manage_network_options' );
		$this->assertNull( Capabilities::server_files_blocked_reason() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disallow_file_mods_blocks_everyone(): void {
		define( 'DISALLOW_FILE_MODS', true );
		$this->assertNotNull( Capabilities::server_files_blocked_reason() );
		$this->assertNotNull( Capabilities::server_files_blocked_reason( false ) );
	}
}
