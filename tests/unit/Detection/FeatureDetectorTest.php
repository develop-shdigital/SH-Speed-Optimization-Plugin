<?php
/**
 * Tests for feature and page builder detection.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Detection;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Detection\Facts;
use SH\SpeedOptimizer\Detection\FeatureDetector;

final class FeatureDetectorTest extends TestCase {

	public function test_features(): void {
		$features = FeatureDetector::features(
			array( 'woocommerce', 'contact-form-7', 'polylang', 'facetwp', 'LayerSlider', 'complianz-gdpr', 'wordpress-seo', 'memberpress', 'sfwd-lms' ),
			Facts::from_array( array( 'option:wpcf7' => array( 'recaptcha' => array( 'site' => 'secret' ) ) ) )
		);

		$this->assertTrue( $features['woocommerce'] );
		$this->assertFalse( $features['edd'] );
		$this->assertSame( 'polylang', $features['multilingual'] );
		$this->assertSame( array( 'contact-form-7' ), $features['forms'] );
		$this->assertSame( array( 'cf7_recaptcha' ), $features['captcha'] );
		$this->assertSame( array( 'layerslider' ), $features['sliders'] );
		$this->assertSame( array( 'complianz-gdpr' ), $features['consent'] );
		$this->assertSame( array( 'wordpress-seo' ), $features['seo'] );
		$this->assertSame( array( 'memberpress' ), $features['membership'] );
		$this->assertSame( array( 'sfwd-lms' ), $features['lms'] );
		$this->assertTrue( $features['ajax_heavy'] );
	}

	public function test_multilingual_from_constant(): void {
		$features = FeatureDetector::features( array(), Facts::from_array( array( 'const:ICL_SITEPRESS_VERSION' => '4.6' ) ) );
		$this->assertSame( 'wpml', $features['multilingual'] );
		$this->assertFalse( $features['ajax_heavy'] );
	}

	public function test_builders_from_plugins_and_theme(): void {
		$builders = FeatureDetector::builders(
			array(
				'elementor'     => array( 'version' => '3.20.0' ),
				'elementor-pro' => array( 'version' => '3.20.1' ),
				'js_composer'   => array( 'version' => '7.5' ),
			),
			array(
				'template'       => 'Divi',
				'version'        => '1.0',
				'is_child'       => true,
				'parent_version' => '4.25.0',
			),
			Facts::from_array( array( 'const:ELEMENTOR_VERSION' => '3.20.2' ) ),
			true
		);

		$this->assertSame( '3.20.2', $builders['elementor'], 'The loaded version constant wins over the header.' );
		$this->assertSame( '3.20.1', $builders['elementor_pro'] );
		$this->assertSame( '7.5', $builders['wpbakery'] );
		$this->assertSame( '4.25.0', $builders['divi'] );
		$this->assertArrayHasKey( 'gutenberg', $builders );
		$this->assertArrayNotHasKey( 'bricks', $builders );
	}

	public function test_classic_editor(): void {
		$lookup = Facts::from_array( array( 'option:classic-editor-replace' => 'classic' ) );

		$builders = FeatureDetector::builders( array( 'classic-editor' => array( 'version' => '1.6' ) ), array( 'template' => 'twentytwentyone' ), $lookup );
		$this->assertArrayHasKey( 'classic_editor', $builders );
		$this->assertArrayNotHasKey( 'gutenberg', $builders );

		$block_theme = FeatureDetector::builders(
			array( 'classic-editor' => array( 'version' => '1.6' ) ),
			array(
				'template'       => 'twentytwentyfour',
				'is_block_theme' => true,
			),
			$lookup
		);
		$this->assertArrayHasKey( 'gutenberg', $block_theme, 'Block themes always use the block editor for templates.' );

		$bricks = FeatureDetector::builders( array(), array( 'template' => 'bricks', 'version' => '1.9' ), Facts::from_array( array() ) );
		$this->assertSame( '1.9', $bricks['bricks'] );
		$this->assertArrayHasKey( 'gutenberg', $bricks );
	}
}
