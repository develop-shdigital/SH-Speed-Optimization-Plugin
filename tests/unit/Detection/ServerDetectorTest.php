<?php
/**
 * Tests for web server parsing.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Detection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Detection\ServerDetector;

final class ServerDetectorTest extends TestCase {

	public static function software_provider(): array {
		return array(
			'apache'               => array( 'Apache/2.4.57 (Debian)', array(), 'apache', '2.4.57' ),
			'apache bare'          => array( 'Apache', array(), 'apache', '' ),
			'nginx'                => array( 'nginx/1.25.3', array(), 'nginx', '1.25.3' ),
			'openresty'            => array( 'openresty/1.21.4.1', array(), 'nginx', '1.21.4.1' ),
			'litespeed'            => array( 'LiteSpeed', array(), 'litespeed', '' ),
			'openlitespeed'        => array( 'OpenLiteSpeed/1.7.19', array(), 'openlitespeed', '1.7.19' ),
			'litespeed as apache'  => array( 'Apache', array( 'lsws_edition' => 'LiteSpeed Enterprise' ), 'litespeed', '' ),
			'openlitespeed env'    => array( 'LiteSpeed', array( 'lsws_edition' => 'Openlitespeed 1.7.18' ), 'openlitespeed', '' ),
			'litespeed sapi'       => array( '', array( 'sapi' => 'litespeed' ), 'litespeed', '' ),
			'x-lscache env'        => array( 'Apache', array( 'x_lscache' => true ), 'litespeed', '' ),
			'iis'                  => array( 'Microsoft-IIS/10.0', array(), 'iis', '10.0' ),
			'iis rewrite variable' => array( '', array( 'iis' => true ), 'iis', '' ),
			'unknown'              => array( 'Caddy', array(), 'unknown', '' ),
			'empty'                => array( '', array( 'sapi' => 'cli' ), 'unknown', '' ),
		);
	}

	#[DataProvider( 'software_provider' )]
	public function test_parse_software( string $raw, array $hints, string $software, string $version ): void {
		$parsed = ServerDetector::parse_software( $raw, $hints );
		$this->assertSame( $software, $parsed['software'] );
		$this->assertSame( $version, $parsed['version'] );
		$this->assertSame( trim( $raw ), $parsed['raw'] );
	}

	public function test_to_bytes(): void {
		$this->assertSame( 268435456, ServerDetector::to_bytes( '256M' ) );
		$this->assertSame( 1073741824, ServerDetector::to_bytes( '1G' ) );
		$this->assertSame( 131072, ServerDetector::to_bytes( '128k' ) );
		$this->assertSame( -1, ServerDetector::to_bytes( '-1' ) );
		$this->assertSame( 1000, ServerDetector::to_bytes( '1000' ) );
		$this->assertSame( 0, ServerDetector::to_bytes( '' ) );
	}
}
