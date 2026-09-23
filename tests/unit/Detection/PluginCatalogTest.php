<?php
/**
 * Tests for the conflict catalog and feature refinement.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Detection;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Detection\Facts;
use SH\SpeedOptimizer\Detection\PluginCatalog;

final class PluginCatalogTest extends TestCase {

	private function features( string $slug, array $facts ): array {
		$conflicts = PluginCatalog::conflicts( array( $slug ), Facts::from_array( $facts ) );
		$this->assertArrayHasKey( $slug, $conflicts );
		return $conflicts[ $slug ]['features'];
	}

	public function test_unknown_settings_assume_everything_enabled(): void {
		$features = $this->features( 'wp-rocket', array() );
		foreach ( array( 'page_cache', 'browser_cache', 'minify_css', 'minify_js', 'defer_js', 'delay_js', 'lazy_load', 'critical_css', 'preload', 'font_optimization', 'cdn', 'heartbeat', 'cleanup' ) as $feature ) {
			$this->assertContains( $feature, $features );
		}
	}

	public function test_wp_rocket_settings_refine_features(): void {
		$features = $this->features(
			'wp-rocket',
			array(
				'option:wp_rocket_settings' => array(
					'minify_css'       => 1,
					'minify_js'        => 0,
					'defer_all_js'     => 1,
					'delay_js'         => 0,
					'lazyload'         => 0,
					'lazyload_iframes' => 0,
					'async_css'        => 0,
					'cdn'              => 0,
					'emoji'            => 1,
				),
			)
		);

		$this->assertContains( 'page_cache', $features, 'The page cache cannot be switched off in WP Rocket.' );
		$this->assertContains( 'minify_css', $features );
		$this->assertContains( 'defer_js', $features );
		$this->assertContains( 'emojis', $features );
		$this->assertContains( 'cleanup', $features );
		$this->assertNotContains( 'minify_js', $features );
		$this->assertNotContains( 'delay_js', $features );
		$this->assertNotContains( 'lazy_load', $features );
		$this->assertNotContains( 'critical_css', $features );
		$this->assertNotContains( 'cdn', $features );
		$this->assertNotContains( 'heartbeat', $features, 'A checkbox missing from the saved settings is off.' );
		$this->assertNotContains( 'embeds', $features );
		$this->assertContains( 'preload', $features, 'Lenient paths stay unknown when missing.' );
	}

	public function test_litespeed_page_cache_only_on_litespeed_servers(): void {
		$this->assertNotContains( 'page_cache', $this->features( 'litespeed-cache', array( 'server_software:' => 'nginx' ) ) );
		$this->assertContains( 'page_cache', $this->features( 'litespeed-cache', array( 'server_software:' => 'litespeed' ) ) );
		$this->assertContains( 'page_cache', $this->features( 'litespeed-cache', array( 'server_software:' => 'openlitespeed' ) ) );
		$this->assertNotContains(
			'page_cache',
			$this->features(
				'litespeed-cache',
				array(
					'server_software:'            => 'litespeed',
					'option:litespeed.conf.cache' => false,
				)
			)
		);
	}

	public function test_litespeed_optimization_options(): void {
		$features = $this->features(
			'litespeed-cache',
			array(
				'server_software:'                    => 'apache',
				'option:litespeed.conf.optm-css_min'  => '1',
				'option:litespeed.conf.optm-css_comb' => '0',
				'option:litespeed.conf.optm-js_min'   => '0',
				'option:litespeed.conf.optm-js_comb'  => '0',
				'option:litespeed.conf.optm-js_defer' => '1',
				'option:litespeed.conf.media-lazy'    => '0',
			)
		);
		$this->assertContains( 'minify_css', $features );
		$this->assertNotContains( 'minify_js', $features );
		$this->assertContains( 'defer_js', $features );
		$this->assertNotContains( 'delay_js', $features );
		$this->assertNotContains( 'lazy_load', $features, 'Unknown iframe setting does not keep a feature whose known setting is off.' );
		$this->assertContains( 'webp', $features, 'Unreadable settings keep the feature.' );

		$delay = $this->features( 'litespeed-cache', array( 'option:litespeed.conf.optm-js_defer' => 2 ) );
		$this->assertContains( 'delay_js', $delay );

		$off = $this->features( 'litespeed-cache', array( 'option:litespeed.conf.optm-js_defer' => 0 ) );
		$this->assertNotContains( 'defer_js', $off );
		$this->assertNotContains( 'delay_js', $off );
	}

	public function test_autoptimize(): void {
		$features = $this->features(
			'autoptimize',
			array(
				'option:autoptimize_js'              => 'on',
				'option:autoptimize_css'             => '',
				'option:autoptimize_css_defer'       => '',
				'option:autoptimize_extra_settings'  => array(
					'autoptimize_extra_checkbox_field_1' => 'on',
					'autoptimize_extra_radio_field_4'    => '1',
				),
				'option:autoptimize_imgopt_settings' => array(),
			)
		);
		$this->assertSame( array( 'minify_js', 'defer_js', 'cleanup', 'emojis' ), $features );

		$fonts = $this->features( 'autoptimize', array( 'option:autoptimize_extra_settings' => array( 'autoptimize_extra_radio_field_4' => '3' ) ) );
		$this->assertContains( 'font_optimization', $fonts );
	}

	public function test_wp_super_cache_global(): void {
		$this->assertSame( array(), $this->features( 'wp-super-cache', array( 'global:cache_enabled' => false ) ) );
		$this->assertSame( array( 'page_cache' ), $this->features( 'wp-super-cache', array( 'global:cache_enabled' => true ) ) );
		$this->assertSame( array( 'page_cache' ), $this->features( 'wp-super-cache', array() ) );
	}

	public function test_w3_total_cache(): void {
		$features = $this->features(
			'w3-total-cache',
			array(
				'w3tc:pgcache.enabled'      => false,
				'w3tc:browsercache.enabled' => true,
				'w3tc:minify.enabled'       => true,
				'w3tc:minify.css.enable'    => true,
				'w3tc:minify.js.enable'     => false,
				'w3tc:lazyload.enabled'     => false,
				'w3tc:cdn.enabled'          => false,
			)
		);
		$this->assertSame( array( 'browser_cache', 'minify_css' ), $features );
	}

	public function test_cloudflare_only_caches_pages_with_apo(): void {
		$this->assertSame( array(), $this->features( 'cloudflare', array() ) );
		$this->assertSame( array( 'page_cache' ), $this->features( 'cloudflare', array( 'option:automatic_platform_optimization' => array( 'value' => 1 ) ) ) );
		$this->assertSame( array(), $this->features( 'cloudflare', array( 'option:automatic_platform_optimization' => array( 'value' => 0 ) ) ) );
	}

	public function test_jetpack_boost_opt_in_features(): void {
		$features = $this->features(
			'jetpack-boost',
			array(
				'option:jetpack_boost_status_critical-css'      => '1',
				'option:jetpack_boost_status_render-blocking-js' => '',
				'option:jetpack_boost_status_page-cache'        => '1',
			)
		);
		$this->assertContains( 'critical_css', $features );
		$this->assertNotContains( 'defer_js', $features );
		$this->assertContains( 'page_cache', $features );
		$this->assertNotContains( 'minify_js', $features );
	}

	public function test_wp_fastest_cache_json_option(): void {
		$features = $this->features(
			'wp-fastest-cache',
			array( 'option:WpFastestCache' => '{"wpFastestCacheStatus":"on","wpFastestCacheLazyLoad":"on"}' )
		);
		$this->assertSame( array( 'page_cache', 'lazy_load' ), $features );
	}

	public function test_perfmatters_nested_settings(): void {
		$features = $this->features(
			'perfmatters',
			array(
				'option:perfmatters_options' => array(
					'disable_emojis' => '1',
					'assets'         => array( 'delay_js' => '1' ),
				),
			)
		);
		$this->assertContains( 'emojis', $features );
		$this->assertContains( 'cleanup', $features );
		$this->assertContains( 'delay_js', $features );
		$this->assertNotContains( 'defer_js', $features );
		$this->assertNotContains( 'heartbeat', $features );
		$this->assertNotContains( 'lazy_load', $features );
	}

	public function test_purgers_have_no_features_and_unknown_plugins_are_ignored(): void {
		$conflicts = PluginCatalog::conflicts( array( 'varnish-http-purge', 'hello-dolly', 'WP-Rocket' ), Facts::from_array( array() ) );
		$this->assertSame( array(), $conflicts['varnish-http-purge']['features'] );
		$this->assertArrayNotHasKey( 'hello-dolly', $conflicts );
		$this->assertArrayHasKey( 'WP-Rocket', $conflicts, 'Slugs match case-insensitively.' );
	}

	public function test_to_flag(): void {
		$this->assertNull( PluginCatalog::to_flag( null ) );
		$this->assertTrue( PluginCatalog::to_flag( 'on' ) );
		$this->assertTrue( PluginCatalog::to_flag( 1 ) );
		$this->assertFalse( PluginCatalog::to_flag( '0' ) );
		$this->assertFalse( PluginCatalog::to_flag( 'off' ) );
		$this->assertFalse( PluginCatalog::to_flag( '' ) );
		$this->assertFalse( PluginCatalog::to_flag( array() ) );
	}
}
