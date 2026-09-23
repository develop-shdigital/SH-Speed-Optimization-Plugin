<?php
/**
 * Tests for the admin page map and the network defaults form parser.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Admin\Admin;
use SH\SpeedOptimizer\Admin\NetworkAdmin;
use SH\SpeedOptimizer\Core\Settings;

/**
 * Admin pure logic.
 */
final class AdminPagesTest extends TestCase {

	public function test_pages_have_the_required_slugs_in_order(): void {
		$slugs = array_map(
			static fn( $page ) => $page['slug'],
			Admin::pages()
		);

		$this->assertSame(
			array(
				'overview'     => 'shso',
				'optimization' => 'shso-optimization',
				'diagnostics'  => 'shso-diagnostics',
				'cache'        => 'shso-cache',
				'settings'     => 'shso-settings',
			),
			$slugs
		);
	}

	public function test_page_id_for_slug(): void {
		$this->assertSame( 'overview', Admin::page_id_for_slug( 'shso' ) );
		$this->assertSame( 'cache', Admin::page_id_for_slug( 'shso-cache' ) );
		$this->assertNull( Admin::page_id_for_slug( 'shso-unknown' ) );
	}

	public function test_network_fields_are_known_settings(): void {
		foreach ( array_keys( NetworkAdmin::field_types() ) as $key ) {
			$this->assertArrayHasKey( $key, Settings::defaults() );
		}
		$this->assertSame( array_keys( NetworkAdmin::field_types() ), array_keys( NetworkAdmin::field_labels() ) );
	}

	public function test_parse_form_booleans_numbers_and_locks(): void {
		$parsed = NetworkAdmin::parse_form(
			array(
				'shso_defaults' => array(
					'auto_optimize'          => '1',
					'safe_mode'              => '0',
					'page_cache'             => '1',
					'cache_lifespan'         => '5000',
					'safe_optimizations'     => '0',
					'advanced_optimizations' => 'on',
					'allow_server_config'    => '',
					'psi_api_key'            => 'not-a-network-field',
				),
				'shso_locked'   => array(
					'auto_optimize'  => '1',
					'cache_lifespan' => '1', // Not lockable.
					'safe_mode'      => '',
					'unknown'        => '1',
				),
			)
		);

		$this->assertSame(
			array(
				'auto_optimize'          => true,
				'safe_mode'              => false,
				'page_cache'             => true,
				'cache_lifespan'         => 720,
				'safe_optimizations'     => false,
				'advanced_optimizations' => true,
				'allow_server_config'    => false,
			),
			$parsed['defaults']
		);
		$this->assertSame( array( 'auto_optimize' ), $parsed['locked'] );
	}

	public function test_parse_form_with_missing_or_malformed_input(): void {
		$parsed = NetworkAdmin::parse_form(
			array(
				'shso_defaults' => 'garbage',
				'shso_locked'   => array( 'page_cache' => '1' ),
			)
		);

		$this->assertFalse( $parsed['defaults']['auto_optimize'] );
		$this->assertSame( Settings::defaults()['cache_lifespan'], $parsed['defaults']['cache_lifespan'] );
		$this->assertSame( array( 'page_cache' ), $parsed['locked'] );

		$low = NetworkAdmin::parse_form( array( 'shso_defaults' => array( 'cache_lifespan' => array( 'x' ) ) ) );
		$this->assertSame( Settings::defaults()['cache_lifespan'], $low['defaults']['cache_lifespan'] );

		$zero = NetworkAdmin::parse_form( array( 'shso_defaults' => array( 'cache_lifespan' => '0' ) ) );
		$this->assertSame( 1, $zero['defaults']['cache_lifespan'] );
	}
}
