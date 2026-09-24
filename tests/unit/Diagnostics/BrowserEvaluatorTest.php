<?php
/**
 * Browser verification tests.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Diagnostics;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Diagnostics\BrowserEvaluator;

final class BrowserEvaluatorTest extends TestCase {

	private function probe( array $overrides = array() ): array {
		return array_replace_recursive(
			array(
				'url'             => 'https://example.test/',
				'errors'          => array(),
				'resource_errors' => array(),
				'cls'             => 0.01,
				'lcp'             => array( 'ms' => 1200 ),
				'dom'             => array(
					'elements' => 800,
					'visible'  => 600,
					'height'   => 3000,
					'forms'    => 1,
					'inputs'   => 3,
					'buttons'  => 4,
					'markers'  => array(
						'nav'  => array(
							'count'   => 1,
							'visible' => 1,
						),
					),
				),
				'images'          => array( 'distorted' => array() ),
			),
			$overrides
		);
	}

	public function test_identical_results_pass(): void {
		$outcome = BrowserEvaluator::compare( $this->probe(), $this->probe() );
		$this->assertTrue( $outcome['ok'] );
		$this->assertFalse( $outcome['unavailable'] );
	}

	public function test_new_javascript_error_fails_but_existing_one_does_not(): void {
		$baseline  = $this->probe( array( 'errors' => array( array( 'msg' => 'Uncaught TypeError: a is undefined', 'src' => 'https://example.test/app.js' ) ) ) );
		$same      = $this->probe( array( 'errors' => array( array( 'msg' => 'Uncaught TypeError: a is undefined', 'src' => 'https://example.test/wp-content/cache/sh-speed-optimizer/assets/p-app-0123456789.min.js' ) ) ) );
		$new_error = $this->probe( array( 'errors' => array( array( 'msg' => 'Uncaught ReferenceError: jQuery is not defined', 'src' => '' ) ) ) );

		$this->assertTrue( BrowserEvaluator::compare( $baseline, $same )['ok'] );
		$failed = BrowserEvaluator::compare( $baseline, $new_error );
		$this->assertFalse( $failed['ok'] );
		$this->assertStringContainsString( 'jQuery is not defined', $failed['failures'][0] );
	}

	public function test_probe_errors_are_ignored(): void {
		$candidate = $this->probe( array( 'errors' => array( array( 'msg' => 'probe: oops', 'probe' => true ) ) ) );
		$this->assertTrue( BrowserEvaluator::compare( $this->probe(), $candidate )['ok'] );
	}

	public function test_same_origin_resource_failure_fails_third_party_does_not(): void {
		$third = $this->probe( array( 'resource_errors' => array( array( 'url' => 'https://www.google-analytics.com/g.js', 'tag' => 'script' ) ) ) );
		$this->assertTrue( BrowserEvaluator::compare( $this->probe(), $third )['ok'] );

		$own = $this->probe( array( 'resource_errors' => array( array( 'url' => 'https://example.test/wp-content/cache/x.css', 'tag' => 'link' ) ) ) );
		$this->assertFalse( BrowserEvaluator::compare( $this->probe(), $own )['ok'] );
	}

	public function test_layout_regressions_fail(): void {
		$hidden_nav = $this->probe( array( 'dom' => array( 'markers' => array( 'nav' => array( 'visible' => 0 ) ) ) ) );
		$this->assertFalse( BrowserEvaluator::compare( $this->probe(), $hidden_nav )['ok'] );

		$collapsed = $this->probe( array( 'dom' => array( 'height' => 1000 ) ) );
		$this->assertFalse( BrowserEvaluator::compare( $this->probe(), $collapsed )['ok'] );

		$shifty = $this->probe( array( 'cls' => 0.3 ) );
		$this->assertFalse( BrowserEvaluator::compare( $this->probe(), $shifty )['ok'] );

		$distorted = $this->probe( array( 'images' => array( 'distorted' => array( array( 'src' => 'https://example.test/a.jpg' ) ) ) ) );
		$this->assertFalse( BrowserEvaluator::compare( $this->probe(), $distorted )['ok'] );
	}

	public function test_missing_buttons_fail(): void {
		$fewer = $this->probe( array( 'dom' => array( 'buttons' => 2 ) ) );
		$this->assertFalse( BrowserEvaluator::compare( $this->probe(), $fewer )['ok'] );
	}

	public function test_unusable_results(): void {
		$outcome = BrowserEvaluator::compare( array( 'timeout' => true ), $this->probe() );
		$this->assertTrue( $outcome['unavailable'] );
		$this->assertFalse( $outcome['ok'] );

		$timeout = BrowserEvaluator::compare( $this->probe(), array( 'timeout' => true ) );
		$this->assertFalse( $timeout['unavailable'] );
		$this->assertFalse( $timeout['ok'] );
	}
}
