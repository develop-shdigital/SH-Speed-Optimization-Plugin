<?php
/**
 * Tests for the CSS minification optimization (detection logic).
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\CssOptimization;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Compatibility\Rules;
use SH\SpeedOptimizer\Core\Plugin;
use SH\SpeedOptimizer\Core\Settings;
use SH\SpeedOptimizer\Detection\SiteProfile;
use SH\SpeedOptimizer\Modules\CssOptimization\CssMinifyOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;

final class CssMinifyOptimizationTest extends TestCase {

	protected function setUp(): void {
		shso_test_reset();
	}

	/**
	 * Context with the given styles on one page.
	 *
	 * @param array      $styles  Styles.
	 * @param Rules|null $rules   Rules.
	 * @param array      $profile Profile data.
	 */
	private static function context( array $styles, ?Rules $rules = null, array $profile = array() ): AssessmentContext {
		return new AssessmentContext(
			new SiteProfile( $profile ),
			$rules ?? new Rules(),
			new Settings(),
			array(
				'https://example.test/' => array(
					'status' => 200,
					'styles' => $styles,
				),
			)
		);
	}

	/**
	 * Style item.
	 *
	 * @param string   $href     URL.
	 * @param int|null $bytes    Size.
	 * @param bool     $minified Minified.
	 * @param bool     $local    Local.
	 */
	private static function style( string $href, ?int $bytes, bool $minified = false, bool $local = true ): array {
		return array(
			'handle'   => basename( $href, '.css' ),
			'href'     => $href,
			'local'    => $local,
			'media'    => 'all',
			'in_head'  => true,
			'bytes'    => $bytes,
			'minified' => $minified,
		);
	}

	public function test_metadata(): void {
		$optimization = new CssMinifyOptimization( Plugin::instance() );
		$this->assertSame( 'css_minify', $optimization->id() );
		$this->assertSame( Category::CSS, $optimization->category() );
		$this->assertSame( Risk::LOW, $optimization->risk() );
		$this->assertSame( Risk::LEVEL_SAFE, $optimization->level() );
		$this->assertTrue( $optimization->default_enabled() );
		$this->assertSame( array(), $optimization->requirements() );
	}

	public function test_benefit_follows_bytes_of_unminified_local_files(): void {
		$optimization = new CssMinifyOptimization( Plugin::instance() );

		$high = $optimization->assess( self::context( array( self::style( '/a.css', 120000 ), self::style( '/b.css?ver=2', 40000 ), self::style( '/b.css?ver=3', 40000 ) ) ) );
		$this->assertTrue( $high->applicable );
		$this->assertSame( Assessment::BENEFIT_HIGH, $high->benefit );
		$this->assertSame( 2, $high->data['count'], 'Same file with another version counts once.' );
		$this->assertSame( 160000, $high->data['bytes'] );
		$this->assertSame( 90, $high->confidence );

		$medium = $optimization->assess( self::context( array( self::style( '/a.css', 40000 ) ) ) );
		$this->assertSame( Assessment::BENEFIT_MEDIUM, $medium->benefit );

		$low = $optimization->assess( self::context( array( self::style( '/a.css', 2000 ), self::style( '/b.css', null ) ) ) );
		$this->assertSame( Assessment::BENEFIT_LOW, $low->benefit );
		$this->assertSame( 1, $low->data['unknown_size'] );
	}

	public function test_not_applicable_when_everything_is_minified_remote_or_excluded(): void {
		$optimization = new CssMinifyOptimization( Plugin::instance() );
		$rules        = ( new Rules() )->add( 'css_no_optimize', array( 'elementor' ) );
		update_option( Settings::OPTION, array( 'exclude_css' => array( '/themes/child/' ) ) );

		$assessment = $optimization->assess(
			self::context(
				array(
					self::style( '/a.min.css', 50000, true ),
					self::style( 'https://fonts.googleapis.com/css2?family=Inter', 5000, false, false ),
					self::style( '/wp-content/plugins/elementor/assets/css/frontend.css', 90000 ),
					self::style( '/wp-content/themes/child/style.css', 90000 ),
				),
				$rules
			)
		);

		$this->assertFalse( $assessment->applicable );
		$this->assertSame( Assessment::BENEFIT_NONE, $assessment->benefit );
	}

	public function test_no_styles_and_other_plugin_detection(): void {
		$optimization = new CssMinifyOptimization( Plugin::instance() );
		$this->assertFalse( $optimization->assess( self::context( array() ) )->applicable );

		$profile    = array(
			'conflicts' => array(
				'autoptimize' => array(
					'name'     => 'Autoptimize',
					'features' => array( 'minify_css' ),
				),
			),
		);
		$assessment = $optimization->assess( self::context( array( self::style( '/a.css', 90000 ) ), null, $profile ) );
		$this->assertSame( 'Autoptimize', $assessment->handled_by );
	}

	public function test_compatibility_penalties_apply(): void {
		$optimization = new CssMinifyOptimization( Plugin::instance() );
		$rules        = ( new Rules() )->penalize( 'css_minify', 30, 'Builder X generates CSS at runtime.' );
		$assessment   = $optimization->assess( self::context( array( self::style( '/a.css', 90000 ) ), $rules ) );
		$this->assertSame( 60, $assessment->confidence );
		$this->assertContains( 'Builder X generates CSS at runtime.', $assessment->reasons );
	}
}
