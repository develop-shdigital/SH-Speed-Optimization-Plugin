<?php
/**
 * Metadata and detection logic of the image, font, preload and facade optimizations.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Preload;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Compatibility\Rules;
use SH\SpeedOptimizer\Core\Plugin;
use SH\SpeedOptimizer\Core\Settings;
use SH\SpeedOptimizer\Detection\SiteProfile;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Catalog;
use SH\SpeedOptimizer\Optimization\OptimizationInterface;

final class OptimizationMetadataTest extends TestCase {

	/**
	 * Expected metadata: id => [ category, risk, level, requirements ].
	 */
	private const EXPECTED = array(
		'lazy_load_images'      => array( 'images', 'low', 'safe', array() ),
		'lazy_load_iframes'     => array( 'images', 'low', 'smart', array() ),
		'image_dimensions'      => array( 'images', 'low', 'safe', array( 'browser_verification' ) ),
		'webp_images'           => array( 'images', 'low', 'smart', array() ),
		'lcp_priority'          => array( 'images', 'low', 'safe', array() ),
		'font_display_swap'     => array( 'fonts', 'safe', 'safe', array() ),
		'font_preload'          => array( 'fonts', 'low', 'smart', array( 'browser_verification' ) ),
		'localize_google_fonts' => array( 'fonts', 'low', 'smart', array( 'external_download' ) ),
		'preconnect'            => array( 'third_party', 'safe', 'safe', array() ),
		'video_facade'          => array( 'third_party', 'moderate', 'smart', array( 'browser_verification' ) ),
		'map_facade'            => array( 'third_party', 'moderate', 'smart', array( 'browser_verification' ) ),
		'speculative_prefetch'  => array( 'third_party', 'moderate', 'experimental', array() ),
	);

	protected function setUp(): void {
		shso_test_reset();
		$GLOBALS['shso_test_editor_supports'] = array(
			'image/webp' => true,
			'image/avif' => false,
		);
	}

	private function optimization( string $id ): OptimizationInterface {
		$class = Catalog::map()[ $id ];
		return new $class( Plugin::instance() );
	}

	private function context( array $pages = array(), array $browser = array(), array $conflicts = array() ): AssessmentContext {
		return new AssessmentContext( new SiteProfile( array( 'conflicts' => $conflicts ) ), new Rules(), new Settings(), $pages, $browser );
	}

	public function test_metadata_matches_the_catalog(): void {
		foreach ( self::EXPECTED as $id => $expected ) {
			$optimization = $this->optimization( $id );
			$this->assertSame( $id, $optimization->id() );
			$this->assertSame( $expected[0], $optimization->category(), $id );
			$this->assertSame( $expected[1], $optimization->risk(), $id );
			$this->assertSame( $expected[2], $optimization->level(), $id );
			$this->assertSame( $expected[3], $optimization->requirements(), $id );
			$this->assertNotSame( '', $optimization->name() );
			$this->assertNotSame( '', $optimization->description() );
			$this->assertFalse( $optimization->safe_mode_compatible(), $id );
			$this->assertInstanceOf( Assessment::class, $optimization->assess( $this->context() ), $id );
		}
		$this->assertSame( array( 'iframes' ), $this->optimization( 'video_facade' )->expected_changes() );
	}

	public function test_browser_dependent_optimizations_need_measurements(): void {
		foreach ( array( 'lcp_priority', 'font_preload' ) as $id ) {
			$assessment = $this->optimization( $id )->assess( $this->context() );
			$this->assertFalse( $assessment->applicable, $id );
			$this->assertStringContainsString( 'Optimize My Site', $assessment->reasons[0] );
		}

		$browser = array(
			'front_page' => array(
				'lcp'           => array( 'url' => 'https://example.test/wp-content/uploads/hero.jpg', 'type' => 'img', 'confidence' => 92 ),
				'fonts_preload' => array( array( 'url' => 'https://example.test/wp-content/themes/t/a.woff2', 'type' => 'font/woff2' ) ),
			),
			'page'       => array( 'lcp' => array( 'url' => 'https://example.test/x.jpg', 'type' => 'img', 'confidence' => 40 ) ),
		);
		$lcp     = $this->optimization( 'lcp_priority' )->assess( $this->context( array(), $browser ) );
		$this->assertTrue( $lcp->applicable );
		$this->assertSame( Assessment::BENEFIT_HIGH, $lcp->benefit );
		$this->assertSame( array( 'front_page' ), $lcp->data['templates'] );
		$this->assertTrue( $this->optimization( 'font_preload' )->assess( $this->context( array(), $browser ) )->applicable );
	}

	public function test_page_analysis_based_assessments(): void {
		$pages = array(
			'https://example.test/' => array(
				'status'  => 200,
				'images'  => array(
					'count'              => 20,
					'lazy'               => 2,
					'eager'              => 1,
					'missing_dimensions' => 4,
				),
				'iframes' => array(
					array( 'src' => 'https://www.youtube.com/embed/x', 'kind' => 'youtube', 'lazy' => false ),
					array( 'src' => 'https://www.google.com/maps/embed?pb=1', 'kind' => 'google_maps', 'lazy' => true ),
				),
				'fonts'   => array(
					'google'               => array( array( 'url' => 'https://fonts.googleapis.com/css?family=Lato', 'display' => null ) ),
					'font_display_missing' => 0,
				),
			),
		);
		$context = $this->context( $pages );

		$lazy = $this->optimization( 'lazy_load_images' )->assess( $context );
		$this->assertTrue( $lazy->applicable );
		$this->assertSame( Assessment::BENEFIT_MEDIUM, $lazy->benefit );
		$this->assertSame( 17, $lazy->data['images_without_loading'] );

		$this->assertSame( Assessment::BENEFIT_MEDIUM, $this->optimization( 'image_dimensions' )->assess( $context )->benefit );
		$this->assertSame( Assessment::BENEFIT_MEDIUM, $this->optimization( 'lazy_load_iframes' )->assess( $context )->benefit );
		$this->assertTrue( $this->optimization( 'video_facade' )->assess( $context )->applicable );
		$this->assertTrue( $this->optimization( 'map_facade' )->assess( $context )->applicable );
		$this->assertTrue( $this->optimization( 'font_display_swap' )->assess( $context )->applicable );
		$this->assertTrue( $this->optimization( 'preconnect' )->assess( $context )->applicable );
		$this->assertTrue( $this->optimization( 'localize_google_fonts' )->assess( $context )->applicable );

		$empty = $this->context( array( 'https://example.test/' => array( 'status' => 200, 'images' => array( 'count' => 2, 'lazy' => 2 ) ) ) );
		$this->assertFalse( $this->optimization( 'lazy_load_images' )->assess( $empty )->applicable );
		$this->assertFalse( $this->optimization( 'localize_google_fonts' )->assess( $empty )->applicable );
	}

	public function test_webp_blocked_without_server_support_and_handled_by_other_plugins(): void {
		$GLOBALS['shso_test_editor_supports']['image/webp'] = false;
		$blocked = $this->optimization( 'webp_images' )->assess( $this->context() );
		$this->assertSame( 'Your server cannot create WebP images.', $blocked->blocked );

		$GLOBALS['shso_test_editor_supports']['image/webp'] = true;
		$handled = $this->optimization( 'webp_images' )->assess(
			$this->context( array(), array(), array( 'ewww-image-optimizer' => array( 'name' => 'EWWW Image Optimizer', 'features' => array( 'webp' ) ) ) )
		);
		$this->assertSame( 'EWWW Image Optimizer', $handled->handled_by );

		$fonts = $this->optimization( 'localize_google_fonts' )->assess(
			$this->context(
				array( 'u' => array( 'status' => 200, 'fonts' => array( 'google' => array( array( 'url' => 'https://fonts.googleapis.com/css?family=Lato' ) ) ) ) ),
				array(),
				array( 'omgf' => array( 'name' => 'OMGF', 'features' => array( 'font_optimization' ) ) )
			)
		);
		$this->assertSame( 'OMGF', $fonts->handled_by );
	}
}
