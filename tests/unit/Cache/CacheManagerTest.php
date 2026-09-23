<?php
/**
 * Tests for the cache manager with the stubbed WordPress environment (host example.test).
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Cache;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Cache\CacheManager;
use SH\SpeedOptimizer\Cache\Delivery;
use SH\SpeedOptimizer\Core\Filesystem;
use SH\SpeedOptimizer\Core\Plugin;
use SH\SpeedOptimizer\Core\Settings;

final class CacheManagerTest extends TestCase {

	/**
	 * Filesystem.
	 *
	 * @var Filesystem
	 */
	private Filesystem $fs;

	protected function setUp(): void {
		shso_test_reset();
		Plugin::instance()->settings()->flush();
		$this->fs = new Filesystem();
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		Plugin::instance()->settings()->flush();
		shso_test_reset();
	}

	/**
	 * Remove files of host example.test.
	 */
	private function cleanup(): void {
		$this->fs->delete_tree( Filesystem::cache_root() . 'pages/example.test' );
		$this->fs->delete( Filesystem::cache_root() . 'config/example.test.json' );
		$this->fs->delete( Filesystem::cache_root() . 'config/example.test.purged.txt' );
		Delivery::reset();
	}

	/**
	 * Fresh manager (settings are read per instance).
	 */
	private function manager(): CacheManager {
		Plugin::instance()->settings()->flush();
		return new CacheManager( Plugin::instance() );
	}

	public function test_write_config_and_cacheable_urls(): void {
		$cache = $this->manager();

		$this->assertSame( array( 'example.test' ), $cache->hosts() );
		$this->assertSame( '/', $cache->site_prefix() );
		$this->assertTrue( $cache->write_config() );
		$this->assertTrue( $cache->config_exists() );

		$config = Delivery::load_config( Filesystem::cache_root(), 'example.test' );
		$this->assertIsArray( $config );
		$site = $config['sites']['/'];
		$this->assertTrue( $site['enabled'] );
		$this->assertSame( 10 * 3600, $site['lifespan'] );
		$this->assertSame( array( 'example.test' ), $site['hosts'] );
		$this->assertContains( 'utm_source', $site['ignore_query'] );
		$this->assertNotContains( 'wordpress_logged_in_', $site['bypass_cookies'], 'Built-in cookies are enforced by the delivery itself.' );

		$this->assertTrue( $cache->is_cacheable_url( 'https://example.test/about/' ) );
		$this->assertTrue( $cache->is_cacheable_url( 'https://example.test/about/?utm_campaign=x' ) );
		$this->assertFalse( $cache->is_cacheable_url( 'https://example.test/wp-admin/' ) );
		$this->assertFalse( $cache->is_cacheable_url( 'https://example.test/?s=term' ) );
		$this->assertFalse( $cache->is_cacheable_url( 'https://evil.test/' ) );
		$this->assertFalse( $cache->is_cacheable_url( 'not a url' ) );

		$cache->remove_config();
		$this->assertFalse( $cache->config_exists() );
	}

	public function test_settings_shape_the_configuration(): void {
		update_option(
			Settings::OPTION,
			array(
				'exclude_urls'    => array( '/members/' ),
				'exclude_cookies' => array( 'my_login' ),
				'cache_lifespan'  => 2,
				'cache_mobile'    => 'on',
			)
		);
		$site = $this->manager()->site_config();

		$this->assertContains( '/members/', $site['exclude_urls'] );
		$this->assertContains( 'my_login', $site['bypass_cookies'] );
		$this->assertSame( 7200, $site['lifespan'] );
		$this->assertTrue( $site['mobile'] );
		$this->assertFalse( $this->manager()->is_cacheable_url( 'https://example.test/members/area/' ) );

		update_option( Settings::OPTION, array( 'page_cache' => false ) );
		$off = $this->manager();
		$this->assertFalse( $off->site_config()['enabled'] );
		$this->assertFalse( $off->is_cacheable_url( 'https://example.test/about/' ), 'Turned off in Settings.' );
	}

	public function test_purge_url_and_purge_all(): void {
		$cache = $this->manager();
		$cache->write_config();
		$config = Delivery::load_config( Filesystem::cache_root(), 'example.test' );
		$html   = '<html><body>' . str_repeat( 'x', 400 ) . '</body></html>';

		foreach ( array( '/', '/about/', '/about/page/2/', '/news/' ) as $uri ) {
			$decision = Delivery::decide(
				array(
					'HTTP_HOST'   => 'example.test',
					'REQUEST_URI' => $uri,
					'HTTPS'       => 'on',
				),
				array(),
				$config
			);
			$this->assertNotNull( $cache->storage()->store( $decision, $html, array(), 'text/html', 3600, true, time() ) );
		}

		$this->assertSame( 4, $cache->storage()->usage( 'example.test/' )['files'] );
		$this->assertSame( 4, $cache->purge_url( 'https://example.test/about/' ), 'Page + pagination, with .gz siblings.' );
		$this->assertSame( 0, $cache->purge_url( 'https://evil.test/news/' ), 'Other hosts are never touched.' );
		$this->assertSame( 2, $cache->storage()->usage( 'example.test/' )['files'] );

		$this->assertSame( 4, $cache->purge_all( 'test' ) );
		$this->assertSame( 0, $cache->storage()->usage( 'example.test/' )['files'] );
		$this->assertContains( 'shso_cache_cleared', $GLOBALS['shso_test_actions'] );
	}

	public function test_renders_started_before_a_purge_are_not_stored(): void {
		$cache  = $this->manager();
		$before = microtime( true ) - 1;
		$cache->purge_all( 'test' );
		$after = microtime( true ) + 1;

		$this->assertTrue( $cache->purged_since( 'example.test', $before ) );
		$this->assertFalse( $cache->purged_since( 'example.test', $after ) );
		$this->assertFalse( $cache->purged_since( '../example.test', $before ) );
		$this->assertFalse( $cache->purged_since( 'never-purged.test', $before ) );
	}

	public function test_status_and_stats_shape(): void {
		$status = $this->manager()->status();

		$this->assertSame( array( 'page_cache', 'browser_cache', 'object_cache', 'preload' ), array_keys( $status ) );
		foreach ( array( 'active', 'mode', 'handled_by', 'files', 'bytes', 'wp_cache_constant', 'dropin' ) as $key ) {
			$this->assertArrayHasKey( $key, $status['page_cache'] );
		}
		$this->assertSame( 'off', $status['page_cache']['mode'], 'Not active in the engine state.' );
		$this->assertFalse( $status['page_cache']['active'] );
		foreach ( array( 'active', 'configured_by_server', 'rules_installed', 'server' ) as $key ) {
			$this->assertArrayHasKey( $key, $status['browser_cache'] );
		}
		foreach ( array( 'active', 'dropin', 'type' ) as $key ) {
			$this->assertArrayHasKey( $key, $status['object_cache'] );
		}
		$this->assertSame(
			array(
				'queued'   => 0,
				'done'     => 0,
				'running'  => false,
				'last_run' => 0,
			),
			$status['preload']
		);

		$stats = $this->manager()->stats( 7 );
		$this->assertCount( 7, $stats['days'] );
		$this->assertTrue( $stats['sampled'] );
		$this->assertArrayHasKey( 'hit_rate', $stats );
	}
}
