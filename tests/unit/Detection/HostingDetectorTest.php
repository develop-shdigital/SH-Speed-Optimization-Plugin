<?php
/**
 * Tests for managed hosting detection.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Detection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Detection\Facts;
use SH\SpeedOptimizer\Detection\HostingDetector;

final class HostingDetectorTest extends TestCase {

	public static function provider(): array {
		return array(
			'wp engine'            => array( array( 'const:WPE_APIKEY' => 'x' ), 'wpengine', true ),
			'wp engine isp'        => array( array( 'const:WPE_ISP' => true ), 'wpengine', true ),
			'kinsta'               => array( array( 'const:KINSTAMU_VERSION' => '2.0' ), 'kinsta', true ),
			'pantheon env'         => array( array( 'env:PANTHEON_ENVIRONMENT' => 'live' ), 'pantheon', true ),
			'wordpress.com'        => array( array( 'const:WPCOMSH_VERSION' => '3.0' ), 'wpcom', true ),
			'atomic'               => array( array( 'const:IS_ATOMIC' => true ), 'wpcom', true ),
			'pressable'            => array( array( 'const:IS_PRESSABLE' => true ), 'pressable', true ),
			'flywheel'             => array( array( 'const:FLYWHEEL_CONFIG_DIR' => '/www' ), 'flywheel', true ),
			'godaddy'              => array( array( 'const:GD_SYSTEM_PLUGIN_DIR' => '/x' ), 'godaddy', true ),
			'godaddy prefix'       => array( array( 'const_prefix:WPAAS_' => true ), 'godaddy', true ),
			'servebolt path'       => array( array( 'path:abspath' => '/kunder/acme_123/site_456/public/' ), 'servebolt', true ),
			'siteground cache on'  => array(
				array(
					'plugin:sg-cachepress'                     => true,
					'option:siteground_optimizer_enable_cache' => '1',
				),
				'siteground',
				true,
			),
			'siteground cache off' => array( array( 'path:abspath' => '/home/customer/www/example.com/public_html/' ), 'siteground', false ),
			'bluehost default'     => array( array( 'mu:endurance-page-cache' => true ), 'bluehost', true ),
			'bluehost level 0'     => array(
				array(
					'mu:endurance-page-cache'      => true,
					'option:endurance_cache_level' => 0,
				),
				'bluehost',
				false,
			),
			'cloudways'            => array( array( 'path:abspath' => '/home/123456.cloudwaysapps.com/abcdef/public_html/' ), 'cloudways', false ),
			'hostinger'            => array( array( 'mu:hostinger' => true ), 'hostinger', false ),
			'nexcess with cache'   => array(
				array(
					'mu:nexcess-mapps'     => true,
					'plugin:cache-enabler' => true,
				),
				'nexcess',
				true,
			),
			'unknown'              => array( array( 'path:abspath' => '/var/www/html/' ), null, false ),
		);
	}

	#[DataProvider( 'provider' )]
	public function test_detect( array $facts, ?string $provider, bool $page_cache ): void {
		$result = HostingDetector::detect( Facts::from_array( $facts ) );
		$this->assertSame( $provider, $result['provider'] );
		$this->assertSame( $page_cache, $result['page_cache'] );
		if ( null !== $provider ) {
			$this->assertNotEmpty( $result['provider_name'] );
		}
	}
}
