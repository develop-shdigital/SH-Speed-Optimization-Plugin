<?php
/**
 * Tests for the JavaScript optimizations (minify, defer, delay all, third-party delay).
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\JavascriptOptimization;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Compatibility\Rules;
use SH\SpeedOptimizer\Core\Plugin;
use SH\SpeedOptimizer\Core\Settings;
use SH\SpeedOptimizer\Detection\SiteProfile;
use SH\SpeedOptimizer\Modules\JavascriptOptimization\JsDeferOptimization;
use SH\SpeedOptimizer\Modules\JavascriptOptimization\JsDelayOptimization;
use SH\SpeedOptimizer\Modules\JavascriptOptimization\JsMinifyOptimization;
use SH\SpeedOptimizer\Modules\ThirdPartyOptimization\ThirdPartyDelayOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\OptimizationInterface;
use SH\SpeedOptimizer\Optimization\Risk;

final class JsOptimizationsTest extends TestCase {

	protected function setUp(): void {
		shso_test_reset();
	}

	/**
	 * Context for pages.
	 *
	 * @param array      $pages   url => page data (status added).
	 * @param Rules|null $rules   Rules.
	 * @param array      $profile Profile.
	 */
	private static function context( array $pages, ?Rules $rules = null, array $profile = array() ): AssessmentContext {
		foreach ( $pages as $url => $page ) {
			$pages[ $url ] = array_merge( array( 'status' => 200 ), $page );
		}
		return new AssessmentContext( new SiteProfile( $profile ), $rules ?? new Rules(), new Settings(), $pages );
	}

	/**
	 * Script item.
	 *
	 * @param string $src   URL.
	 * @param array  $extra Overrides.
	 */
	private static function script( string $src, array $extra = array() ): array {
		return array_merge(
			array(
				'handle'   => basename( $src, '.js' ),
				'src'      => $src,
				'local'    => 0 === strpos( $src, '/' ),
				'in_head'  => true,
				'async'    => false,
				'defer'    => false,
				'module'   => false,
				'bytes'    => 10000,
				'minified' => false,
			),
			$extra
		);
	}

	public function test_metadata_matches_the_specification(): void {
		$plugin = Plugin::instance();
		$cases  = array(
			array( new JsMinifyOptimization( $plugin ), 'js_minify', Category::JAVASCRIPT, Risk::LOW, Risk::LEVEL_SAFE ),
			array( new JsDeferOptimization( $plugin ), 'js_defer', Category::JAVASCRIPT, Risk::MODERATE, Risk::LEVEL_SMART ),
			array( new JsDelayOptimization( $plugin ), 'js_delay_all', Category::JAVASCRIPT, Risk::HIGH, Risk::LEVEL_EXPERIMENTAL ),
			array( new ThirdPartyDelayOptimization( $plugin ), 'js_delay_third_party', Category::THIRD_PARTY, Risk::MODERATE, Risk::LEVEL_SMART ),
		);
		foreach ( $cases as $case ) {
			list( $optimization, $id, $category, $risk, $level ) = $case;
			$this->assertSame( $id, $optimization->id() );
			$this->assertSame( $category, $optimization->category() );
			$this->assertSame( $risk, $optimization->risk() );
			$this->assertSame( $level, $optimization->level() );
			$this->assertContains( OptimizationInterface::REQ_BROWSER, $optimization->requirements(), $id );
			$this->assertNotSame( '', $optimization->name() );
			$this->assertNotSame( '', $optimization->description() );
		}
		$this->assertFalse( ( new JsDelayOptimization( $plugin ) )->default_enabled() );
		$this->assertFalse( ( new JsDeferOptimization( $plugin ) )->default_enabled() );
	}

	public function test_js_minify_assessment(): void {
		$optimization = new JsMinifyOptimization( Plugin::instance() );
		$context      = self::context(
			array(
				'https://example.test/'  => array(
					'scripts' => array(
						self::script( '/wp-content/plugins/a/a.js', array( 'bytes' => 90000 ) ),
						self::script( '/wp-content/plugins/b/b.min.js', array( 'minified' => true ) ),
						self::script( 'https://www.googletagmanager.com/gtag/js?id=G-1', array( 'local' => false ) ),
					),
				),
				'https://example.test/x' => array(
					'scripts' => array( self::script( '/wp-content/plugins/c/c.js', array( 'bytes' => 70000 ) ) ),
				),
			),
			( new Rules() )->add( 'js_no_minify', array( 'plugins/c/' ) )
		);
		$assessment   = $optimization->assess( $context );
		$this->assertTrue( $assessment->applicable );
		$this->assertSame( 1, $assessment->data['count'] );
		$this->assertSame( Assessment::BENEFIT_MEDIUM, $assessment->benefit );
		$this->assertSame( 85, $assessment->confidence );

		$profile = array(
			'conflicts' => array(
				'wp-rocket' => array(
					'name'     => 'WP Rocket',
					'features' => array( 'minify_js', 'delay_js' ),
				),
			),
		);
		$this->assertSame( 'WP Rocket', $optimization->assess( self::context( array( 'https://example.test/' => array( 'scripts' => array( self::script( '/a.js' ) ) ) ), null, $profile ) )->handled_by );
	}

	public function test_js_defer_assessment_counts_render_blocking_head_scripts(): void {
		$optimization = new JsDeferOptimization( Plugin::instance() );
		$scripts      = array(
			self::script( '/a.js' ),
			self::script( '/b.js' ),
			self::script( '/c.js', array( 'async' => true ) ),
			self::script( '/d.js', array( 'defer' => true ) ),
			self::script( '/e.js', array( 'module' => true ) ),
			self::script( '/f.js', array( 'in_head' => false ) ),
		);
		$assessment   = $optimization->assess( self::context( array( 'https://example.test/' => array( 'scripts' => $scripts ) ) ) );
		$this->assertSame( 2, $assessment->data['blocking_scripts'] );
		$this->assertSame( Assessment::BENEFIT_MEDIUM, $assessment->benefit );
		$this->assertSame( 80, $assessment->confidence );

		$many = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$many[] = self::script( "/s{$i}.js" );
		}
		$this->assertSame( Assessment::BENEFIT_HIGH, $optimization->assess( self::context( array( 'https://example.test/' => array( 'scripts' => $many ) ) ) )->benefit );
		$this->assertSame( Assessment::BENEFIT_HIGH, $optimization->assess( self::context( array( 'https://example.test/' => array( 'scripts' => array( self::script( '/big.js', array( 'bytes' => 200000 ) ) ) ) ) ) )->benefit );
		$this->assertSame( Assessment::BENEFIT_LOW, $optimization->assess( self::context( array( 'https://example.test/' => array( 'scripts' => array( self::script( '/one.js' ) ) ) ) ) )->benefit );
		$this->assertFalse( $optimization->assess( self::context( array( 'https://example.test/' => array( 'scripts' => array( self::script( '/f.js', array( 'in_head' => false ) ) ) ) ) ) )->applicable );

		$rules = ( new Rules() )->penalize( 'js_defer', 25, 'WooCommerce checkout scripts are sensitive to load order.' );
		$this->assertSame( 55, $optimization->assess( self::context( array( 'https://example.test/' => array( 'scripts' => $scripts ) ), $rules ) )->confidence );
	}

	public function test_apply_defer_adds_attribute_to_safe_scripts_only(): void {
		$doc     = new HtmlDocument(
			'<!DOCTYPE html><html><head>'
			. '<script src="/wp-includes/js/jquery/jquery.min.js" id="jquery-core-js"></script>'
			. '<script src="/wp-content/plugins/lib/lib.js?ver=1" id="lib-js"></script>'
			. '<script src="/wp-content/plugins/app/app.js" id="app-js"></script>'
			. '<script src="/wp-content/plugins/solo/solo.js" id="solo-js" async></script>'
			. '</head><body><script>jQuery(function($){ $(".x").hide(); });</script></body></html>'
		);
		$scripts = array(
			'jquery-core' => array( 'deps' => array() ),
			'lib'         => array( 'deps' => array() ),
			'app'         => array( 'deps' => array( 'lib' ) ),
			'solo'        => array( 'deps' => array() ),
		);

		$count = JsDeferOptimization::apply_defer( $doc, $scripts, array() );
		$html  = $doc->html();

		$this->assertSame( 2, $count );
		$this->assertStringContainsString( '<script src="/wp-includes/js/jquery/jquery.min.js" id="jquery-core-js"></script>', $html );
		$this->assertStringContainsString( '<script src="/wp-content/plugins/lib/lib.js?ver=1" id="lib-js" defer></script>', $html );
		$this->assertStringContainsString( '<script src="/wp-content/plugins/app/app.js" id="app-js" defer></script>', $html );
		$this->assertStringContainsString( '<script src="/wp-content/plugins/solo/solo.js" id="solo-js" async></script>', $html );

		$excluded = JsDeferOptimization::apply_defer(
			new HtmlDocument( '<html><head><script src="/a.js" id="a-js"></script></head><body></body></html>' ),
			array( 'a' => array( 'deps' => array() ) ),
			array(
				'is_excluded' => static function ( string $handle ): bool {
					return 'a' === $handle;
				},
			)
		);
		$this->assertSame( 0, $excluded );
	}

	public function test_js_delay_all_assessment(): void {
		$optimization = new JsDelayOptimization( Plugin::instance() );
		$scripts      = array(
			self::script( '/wp-includes/js/jquery/jquery.min.js', array( 'handle' => 'jquery-core' ) ),
			self::script( '/wp-content/plugins/a/a.js' ),
			self::script( '/wp-content/plugins/b/b.js' ),
			self::script( '/wp-content/plugins/c/c.js' ),
			self::script( 'https://static.hotjar.com/c/hotjar-1.js', array( 'local' => false ) ),
		);
		$assessment   = $optimization->assess( self::context( array( 'https://example.test/' => array( 'scripts' => $scripts ) ) ) );
		$this->assertTrue( $assessment->applicable );
		$this->assertSame( 3, $assessment->data['local_scripts'] );
		$this->assertSame( Assessment::BENEFIT_MEDIUM, $assessment->benefit );
		$this->assertSame( 50, $assessment->confidence );

		$only_jquery = self::context( array( 'https://example.test/' => array( 'scripts' => array( $scripts[0] ) ) ) );
		$this->assertFalse( $optimization->assess( $only_jquery )->applicable );
	}

	public function test_third_party_delay_assessment(): void {
		$optimization = new ThirdPartyDelayOptimization( Plugin::instance() );
		$page         = array(
			'third_party' => array(
				array( 'id' => 'google_analytics', 'name' => 'Google Analytics', 'category' => 'analytics', 'src' => 'https://www.googletagmanager.com/gtag/js?id=G-1', 'inline' => false, 'blocking' => false ),
				array( 'id' => 'meta_pixel', 'name' => 'Meta Pixel', 'category' => 'ads', 'src' => null, 'inline' => true, 'blocking' => false ),
				array( 'id' => '', 'name' => 'Hotjar', 'category' => 'heatmap', 'src' => 'https://static.hotjar.com/c/hotjar-1.js', 'inline' => false, 'blocking' => false ),
				array( 'id' => 'cookiebot', 'name' => 'Cookiebot', 'category' => 'consent', 'src' => 'https://consent.cookiebot.com/uc.js', 'inline' => false, 'blocking' => true ),
				array( 'id' => 'recaptcha', 'name' => 'reCAPTCHA', 'category' => 'captcha', 'src' => 'https://www.google.com/recaptcha/api.js', 'inline' => false, 'blocking' => false ),
			),
		);
		$assessment   = $optimization->assess( self::context( array( 'https://example.test/' => $page ), null, array( 'features' => array( 'consent' => array( 'cookiebot' ) ) ) ) );

		$this->assertTrue( $assessment->applicable );
		$this->assertSame( 3, $assessment->data['delayable_per_page_max'] );
		$this->assertSame( Assessment::BENEFIT_HIGH, $assessment->benefit );
		$this->assertSame( array( 'Google Analytics', 'Meta (Facebook) Pixel', 'Hotjar' ), $assessment->data['services'] );

		$one = array( 'third_party' => array( $page['third_party'][0], $page['third_party'][3] ) );
		$this->assertSame( Assessment::BENEFIT_MEDIUM, $optimization->assess( self::context( array( 'https://example.test/' => $one ) ) )->benefit );

		$never = array( 'third_party' => array( $page['third_party'][3], $page['third_party'][4] ) );
		$this->assertFalse( $optimization->assess( self::context( array( 'https://example.test/' => $never ) ) )->applicable );

		// Fallback on external scripts when the analysis has no third-party list.
		$fallback = array( 'scripts' => array( self::script( 'https://widget.intercom.io/widget/x', array( 'local' => false ) ) ) );
		$this->assertTrue( $optimization->assess( self::context( array( 'https://example.test/' => $fallback ) ) )->applicable );

		// Excluded by the user.
		update_option( Settings::OPTION, array( 'exclude_js' => array( 'googletagmanager.com' ) ) );
		$this->assertFalse( $optimization->assess( self::context( array( 'https://example.test/' => $one ) ) )->applicable );
	}

	public function test_delay_timeout_filter(): void {
		$this->assertSame( 8000, \SH\SpeedOptimizer\Assets\DelayEngine::timeout() );
		add_filter(
			'shso_delay_timeout',
			static function () {
				return 3;
			}
		);
		$this->assertSame( 3000, \SH\SpeedOptimizer\Assets\DelayEngine::timeout() );
		$this->assertSame( 0, \SH\SpeedOptimizer\Assets\DelayEngine::timeout_all() );
	}
}
