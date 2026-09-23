<?php
/**
 * Tests for automatic purge triggers (debounced, never during visitor page views).
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Cache;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Cache\CacheManager;
use SH\SpeedOptimizer\Cache\Purger;
use SH\SpeedOptimizer\Core\Plugin;

final class PurgerTest extends TestCase {

	/**
	 * Original $_SERVER.
	 *
	 * @var array<string,mixed>
	 */
	private array $server = array();

	protected function setUp(): void {
		shso_test_reset();
		$this->server = $_SERVER;
	}

	protected function tearDown(): void {
		$_SERVER = $this->server;
		shso_test_reset();
	}

	/**
	 * New purger for a request method.
	 *
	 * @param string $method HTTP method.
	 */
	private function purger( string $method ): Purger {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_SERVER['REQUEST_URI']    = '/some-page/';
		return new Purger( new CacheManager( Plugin::instance() ) );
	}

	public function test_nothing_is_purged_during_visitor_page_views(): void {
		$purger = $this->purger( 'GET' );
		$purger->on_menu();
		$purger->on_widgets();
		$purger->on_updated_option( 'widget_text' );
		$purger->on_state_changed( array( 'active' => array() ), array( 'active' => array( 'js_defer' => array() ) ) );

		$this->assertSame( '', $purger->queued()['all'] );
		$this->assertEmpty( $GLOBALS['shso_test_filters']['shutdown'] ?? array(), 'No flush is scheduled.' );
	}

	public function test_site_wide_changes_queue_one_full_purge(): void {
		$purger = $this->purger( 'POST' );
		$purger->on_menu();
		$purger->on_widgets();
		$purger->on_theme();

		$this->assertSame( 'menu', $purger->queued()['all'], 'The first reason is kept; the purge runs once.' );
		$this->assertCount( 1, $GLOBALS['shso_test_filters']['shutdown'][0] ?? array(), 'One shutdown flush for the whole request.' );
	}

	public function test_option_triggers(): void {
		$purger = $this->purger( 'POST' );
		$purger->on_updated_option( 'theme_mods_twentytwentyfour' );
		$purger->on_updated_option( 'shso_preload' );
		$purger->on_updated_option( '_transient_foo' );
		$this->assertSame( '', $purger->queued()['all'] );

		$purger->on_updated_option( 'widget_block' );
		$this->assertSame( 'option:widget_block', $purger->queued()['all'] );
	}

	public function test_state_changes_purge_only_when_optimizations_change(): void {
		$purger = $this->purger( 'POST' );
		$old    = array(
			'active'   => array(
				'page_cache' => array( 'since' => 1 ),
				'js_defer'   => array( 'since' => 1 ),
			),
			'revision' => 4,
		);

		$purger->on_state_changed( $old, array_merge( $old, array( 'revision' => 5, 'last_scan_at' => 123 ) ) );
		$reordered = array( 'active' => array_reverse( $old['active'], true ) );
		$purger->on_state_changed( $old, $reordered );
		$this->assertSame( '', $purger->queued()['all'] );

		$purger->on_state_changed( $old, array( 'active' => array( 'page_cache' => array() ) ) );
		$this->assertSame( 'optimizations', $purger->queued()['all'] );
	}

	public function test_page_exclusion_changes_purge(): void {
		$purger = $this->purger( 'POST' );
		$purger->on_state_changed( array( 'page_exclusions' => array() ), array( 'page_exclusions' => array( 'js_defer' => array( 'tpl:page' ) ) ) );
		$this->assertSame( 'optimizations', $purger->queued()['all'] );
	}
}
