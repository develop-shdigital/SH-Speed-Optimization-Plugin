<?php
/**
 * Tests for plugin slug extraction and the plugins section.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Detection;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Detection\PluginDetector;

final class PluginDetectorTest extends TestCase {

	public function test_slug_from_file(): void {
		$this->assertSame( 'woocommerce', PluginDetector::slug_from_file( 'woocommerce/woocommerce.php' ) );
		$this->assertSame( 'hello', PluginDetector::slug_from_file( 'hello.php' ) );
		$this->assertSame( 'LayerSlider', PluginDetector::slug_from_file( 'LayerSlider/layerslider.php' ) );
		$this->assertSame( 'akismet', PluginDetector::slug_from_file( 'akismet\\akismet.php' ) );
		$this->assertSame( 'kinsta-mu-plugins', PluginDetector::slug_from_file( '/var/www/html/wp-content/mu-plugins/kinsta-mu-plugins.php' ) );
		$this->assertSame( 'wp-rocket', PluginDetector::slug_from_file( '/srv/site/wp-content/plugins/wp-rocket/wp-rocket.php' ) );
		$this->assertSame( 'my-plugins', PluginDetector::slug_from_file( 'my-plugins/main.php' ) );
		$this->assertSame( 'endurance-page-cache', PluginDetector::slug_from_file( 'C:\\sites\\wp-content\\mu-plugins\\endurance-page-cache.php' ) );
		$this->assertSame( '', PluginDetector::slug_from_file( '' ) );
	}

	public function test_build_merges_site_network_and_mu_plugins(): void {
		$plugins = PluginDetector::build(
			array( 'woocommerce/woocommerce.php', 'hello.php', 'broken-entry', 42 ),
			array( 'wordfence/wordfence.php', 'woocommerce/woocommerce.php' ),
			array(
				'woocommerce/woocommerce.php' => array(
					'Name'    => 'WooCommerce',
					'Version' => '9.1.0',
				),
			),
			array(
				'hostinger-preview.php' => array( 'Name' => 'Hostinger Preview' ),
				'hello.php'             => array( 'Name' => 'Duplicate' ),
			)
		);

		$this->assertSame( array( 'woocommerce', 'hello', 'wordfence', 'hostinger-preview' ), array_keys( $plugins ) );
		$this->assertSame( 'WooCommerce', $plugins['woocommerce']['name'] );
		$this->assertSame( '9.1.0', $plugins['woocommerce']['version'] );
		$this->assertTrue( $plugins['woocommerce']['network'] );
		$this->assertFalse( $plugins['hello']['network'] );
		$this->assertSame( 'hello', $plugins['hello']['name'], 'Missing headers fall back to the slug.' );
		$this->assertTrue( $plugins['hostinger-preview']['mu'] );
	}
}
