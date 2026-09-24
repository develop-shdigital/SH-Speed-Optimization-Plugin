<?php
/**
 * Tests for conflict assembly in the detector.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Detection;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Detection\Detector;
use SH\SpeedOptimizer\Detection\Facts;
use SH\SpeedOptimizer\Detection\SiteProfile;

final class DetectorTest extends TestCase {

	public function test_foreign_advanced_cache_is_a_page_cache_conflict(): void {
		$cache = array(
			'advanced_cache'    => array(
				'exists'     => true,
				'ours'       => false,
				'owner'      => 'wp-rocket',
				'owner_name' => 'WP Rocket',
			),
			'wp_cache_constant' => true,
		);

		// WP Rocket deactivated but its drop-in is still serving pages.
		$conflicts = Detector::conflicts( array(), Facts::from_array( array() ), $cache );
		$this->assertSame( array( 'page_cache' ), $conflicts['advanced-cache']['features'] );
		$this->assertStringContainsString( 'WP Rocket', $conflicts['advanced-cache']['name'] );

		// Active WP Rocket already reports the page cache.
		$conflicts = Detector::conflicts( array( 'wp-rocket' ), Facts::from_array( array() ), $cache );
		$this->assertArrayNotHasKey( 'advanced-cache', $conflicts );

		// Our own drop-in is never a conflict; without WP_CACHE the drop-in is inactive.
		$cache['advanced_cache']['ours'] = true;
		$this->assertSame( array(), Detector::conflicts( array(), Facts::from_array( array() ), $cache ) );
		$cache['advanced_cache']['ours'] = false;
		$cache['wp_cache_constant']      = false;
		$this->assertSame( array(), Detector::conflicts( array(), Facts::from_array( array() ), $cache ) );
	}

	public function test_conflicts_feed_provided_by(): void {
		$profile = new SiteProfile(
			array(
				'conflicts' => Detector::conflicts(
					array( 'autoptimize' ),
					Facts::from_array( array( 'option:autoptimize_css' => 'on', 'option:autoptimize_js' => '' ) ),
					array()
				),
			)
		);
		$this->assertSame( array( 'Autoptimize' ), $profile->provided_by( 'minify_css' ) );
		$this->assertSame( array(), $profile->provided_by( 'minify_js' ) );
	}
}
