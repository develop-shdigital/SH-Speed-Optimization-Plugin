<?php
/**
 * Browser probe result sanitising tests.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Tests\Unit\API;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\API\RestController;

/**
 * @covers \SH\SpeedOptimizer\API\RestController::clean_probe_results
 */
final class ProbeResultsTest extends TestCase {

	public function test_strings_are_sanitized_and_bounded(): void {
		$clean = RestController::clean_probe_results(
			array(
				'home:d' => array(
					'errors' => array( '<script>alert(1)</script>' . str_repeat( 'x', 1000 ) ),
					'cls'    => '0.05',
				),
			)
		);
		$this->assertStringNotContainsString( '<script>', $clean['home:d']['errors'][0] );
		$this->assertLessThanOrEqual( 600, mb_strlen( $clean['home:d']['errors'][0] ) );
		$this->assertSame( 0.05, $clean['home:d']['cls'] );
	}

	public function test_critical_css_is_kept_intact(): void {
		$css   = ".hero>h1{font:700 2rem/1.2 \"Inter\",sans-serif}\n@media (max-width:600px){.hero{padding:0 16px}}" . str_repeat( '.a{color:red}', 200 );
		$clean = RestController::clean_probe_results(
			array(
				'home:d' => array(
					'template'     => 'front_page',
					'critical_css' => array(
						'css'       => $css,
						'width'     => '1350',
						'truncated' => false,
					),
				),
			)
		);
		$this->assertSame( $css, $clean['home:d']['critical_css']['css'] );
		$this->assertSame( 1350, $clean['home:d']['critical_css']['width'] );
	}

	public function test_oversized_critical_css_is_dropped(): void {
		$clean = RestController::clean_probe_results(
			array( 'home:d' => array( 'critical_css' => array( 'css' => str_repeat( 'a', 200000 ) ) ) )
		);
		$this->assertSame( '', $clean['home:d']['critical_css']['css'] );
	}
}
