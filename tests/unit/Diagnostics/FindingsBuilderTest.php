<?php
/**
 * Findings builder tests.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Diagnostics;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Diagnostics\FindingsBuilder;
use SH\SpeedOptimizer\Diagnostics\HealthScore;

final class FindingsBuilderTest extends TestCase {

	private function scan(): array {
		$home = array(
			'url'           => 'https://example.test/',
			'template'      => 'front_page',
			'status'        => 200,
			'generation_ms' => 1800,
			'html_bytes'    => 600 * 1024,
			'scripts'       => array(
				array( 'src' => 'https://example.test/a.js', 'render_blocking' => true, 'source' => array( 'type' => 'plugin', 'slug' => 'contact-form-7' ) ),
				array( 'src' => 'https://example.test/b.js', 'render_blocking' => true, 'source' => array( 'type' => 'theme', 'slug' => 't' ) ),
			),
			'styles'        => array(),
			'third_party'   => array(
				array( 'id' => 'ga', 'name' => 'Google Analytics', 'category' => 'analytics' ),
				array( 'id' => 'fb', 'name' => 'Meta Pixel', 'category' => 'ads' ),
				array( 'id' => 'rc', 'name' => 'reCAPTCHA', 'category' => 'captcha' ),
			),
			'iframes'       => array(
				array( 'kind' => 'youtube', 'src' => 'https://www.youtube.com/embed/x' ),
				array( 'kind' => 'google_maps', 'src' => 'https://www.google.com/maps/embed?pb=1' ),
			),
			'images'        => array(
				'count'              => 12,
				'lazy'               => 0,
				'missing_dimensions' => 4,
				'largest'            => array( array( 'src' => 'https://example.test/wp-content/uploads/hero.jpg', 'bytes' => 2800 * 1024 ) ),
			),
			'fonts'         => array(
				'google' => array(
					array(
						'url'      => 'https://fonts.googleapis.com/css?family=Roboto:300,400,500,700|Open+Sans:400,600',
						'families' => array( 'Roboto' => array( '300', '400', '500', '700' ), 'Open Sans' => array( '400', '600' ) ),
						'display'  => false,
					),
				),
			),
			'requests'      => array( 'scripts' => 30, 'styles' => 20, 'images' => 40 ),
			'bytes'         => array( 'js' => 2 * 1024 * 1024, 'css' => 100 ),
			'signatures'    => array( 'contact-form-7' => false ),
		);
		$post         = $home;
		$post['url']  = 'https://example.test/post/';
		$post['template'] = 'single-post';
		$post['signatures'] = array( 'contact-form-7' => true );

		return array(
			'profile'   => array(
				'loopback'      => array( 'ok' => true, 'ttfb_ms' => 1200 ),
				'server'        => array( 'opcache' => false, 'php_version' => '8.1.2', 'memory_limit' => 64 * 1024 * 1024, 'compression' => 'none' ),
				'wp'            => array( 'object_cache' => false ),
				'conflicts'     => array(
					'wp-rocket'     => array( 'name' => 'WP Rocket', 'features' => array( 'page_cache', 'minify_css' ) ),
					'wp-super-cache' => array( 'name' => 'WP Super Cache', 'features' => array( 'page_cache' ) ),
				),
				'browser_cache' => array( 'configured' => false ),
				'plugins'       => array( 'contact-form-7' => array( 'name' => 'Contact Form 7' ) ),
			),
			'pages'     => array(
				$home['url'] => $home,
				$post['url'] => $post,
			),
			'browser'   => array(
				'front_page' => array(
					'dom'    => array( 'visible' => 100 ),
					'lcp'    => array( 'ms' => 5200 ),
					'cls'    => 0.3,
					'images' => array( 'oversized' => array( array( 'src' => 'x.jpg', 'natural' => array( 5000, 3000 ), 'rendered' => array( 800, 480 ) ) ) ),
					'css'    => array( 'sheets' => array( array( 'href' => 'https://example.test/big.css', 'rules' => 1000, 'used' => 100 ) ) ),
					'errors' => array( array( 'msg' => 'Uncaught TypeError: x' ) ),
				),
			),
			'media'     => array( 'oversized_originals' => array( 'count' => 3, 'top' => array() ), 'missing_alt' => 7 ),
			'decisions' => array(
				'critical_css' => array(
					'name'        => 'Critical CSS',
					'category'    => 'css',
					'description' => 'd',
					'decision'    => array( 'action' => 'recommend', 'benefit' => 'high', 'summary' => 'Experimental' ),
				),
			),
			'cache'     => array(),
			'active'    => array(),
		);
	}

	public function test_builds_plain_language_findings_from_real_data(): void {
		$findings = ( new FindingsBuilder() )->build( $this->scan() );
		$ids      = array_column( $findings, 'id' );

		foreach ( array( 'server_response', 'slow_generation', 'opcache_off', 'php_version', 'memory_limit', 'no_compression', 'object_cache', 'conflict_wp-rocket', 'multiple_page_caches', 'page_cache', 'browser_cache', 'render_blocking_js', 'third_party_early', 'maps_immediate', 'videos_immediate', 'large_image', 'missing_dimensions', 'lazy_images', 'font_weights', 'font_display', 'html_size', 'many_requests', 'js_weight', 'global_assets_contact-form-7', 'oversized_originals', 'missing_alt', 'lab_lcp_front_page', 'lab_cls_front_page', 'lab_oversized_front_page', 'baseline_js_errors_front_page', 'recommend_critical_css' ) as $expected ) {
			$this->assertContains( $expected, $ids, "Missing finding {$expected}" );
		}

		$by_id = array_column( $findings, null, 'id' );
		$this->assertSame( 'notice', $by_id['third_party_early']['severity'], 'Captcha is not counted as delayable (2 < 3).' );
		$this->assertStringContainsString( '2 third-party scripts', $by_id['third_party_early']['title'] );
		$this->assertStringContainsString( '2.7 MB', $by_id['large_image']['title'] );
		$this->assertStringContainsString( 'used on 1 of 2', $by_id['global_assets_contact-form-7']['title'] );
		$this->assertSame( 'browser', $by_id['lab_lcp_front_page']['source'] );
	}

	public function test_active_optimizations_suppress_related_findings(): void {
		$scan           = $this->scan();
		$scan['active'] = array( 'page_cache', 'js_defer', 'js_delay_third_party', 'map_facade', 'video_facade' );
		$ids            = array_column( ( new FindingsBuilder() )->build( $scan ), 'id' );

		$this->assertNotContains( 'render_blocking_js', $ids );
		$this->assertNotContains( 'third_party_early', $ids );
		$this->assertNotContains( 'maps_immediate', $ids );
		$this->assertContains( 'page_cache', $ids );
	}

	public function test_report_sections_and_score(): void {
		$findings = ( new FindingsBuilder() )->build( $this->scan() );
		$report   = FindingsBuilder::report( $findings );

		$this->assertNotEmpty( $report['attention'] );
		$this->assertLessThanOrEqual( 12, count( $report['improvements'] ) );
		$rank     = array( 'critical' => 0, 'warning' => 1, 'notice' => 2 );
		$previous = -1;
		foreach ( $report['attention'] as $finding ) {
			$this->assertGreaterThanOrEqual( $previous, $rank[ $finding['severity'] ], 'Attention items are sorted by severity.' );
			$previous = $rank[ $finding['severity'] ];
		}

		$health = HealthScore::calculate( $findings );
		$this->assertIsInt( $health['score'] );
		$this->assertLessThan( 75, $health['score'] );
	}

	public function test_empty_scan_produces_no_page_findings(): void {
		$findings = ( new FindingsBuilder() )->build( array() );
		$this->assertSame( array( 'page_cache' ), array_column( $findings, 'id' ) );
	}
}
