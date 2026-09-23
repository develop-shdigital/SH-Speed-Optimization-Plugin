<?php
/**
 * Tests for speculation rules.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Preload;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Modules\Preload\SpeculationRules;

final class SpeculationRulesTest extends TestCase {

	public function test_valid_json_with_exclusions(): void {
		$script = SpeculationRules::to_script( SpeculationRules::build( '/', '/', array( '/cart/', 'https://example.test/checkout/', '/my-account' ) ) );

		$this->assertStringStartsWith( '<script type="speculationrules">', $script );
		$json  = substr( $script, strlen( '<script type="speculationrules">' ), -strlen( '</script>' ) );
		$rules = json_decode( $json, true );
		$this->assertIsArray( $rules, 'Valid JSON.' );

		$rule = $rules['prefetch'][0];
		$this->assertSame( 'document', $rule['source'] );
		$this->assertSame( 'moderate', $rule['eagerness'] );
		$this->assertSame( array( 'href_matches' => '/*' ), $rule['where']['and'][0] );

		$patterns = $rule['where']['and'][1]['not']['href_matches'];
		foreach ( array( '/wp-admin/*', '/wp-login.php*', '/*logout*', '/*add-to-cart*', '/*.pdf', '/*.zip', '/*\\?(.+)', '/cart/*', '/checkout/*', '/my-account/*' ) as $expected ) {
			$this->assertContains( $expected, $patterns );
		}

		$selectors = $rule['where']['and'][2]['not']['selector_matches'];
		foreach ( array( 'a[rel~="nofollow"]', '[data-no-prefetch]', 'a[href*="?"]', 'a[href*="add-to-cart"]' ) as $expected ) {
			$this->assertStringContainsString( $expected, $selectors );
		}
	}

	public function test_subdirectory_install_and_script_safety(): void {
		$rules    = SpeculationRules::build( '/blog/', '/blog/wp/' );
		$and      = $rules['prefetch'][0]['where']['and'];
		$patterns = $and[1]['not']['href_matches'];

		$this->assertSame( '/blog/*', $and[0]['href_matches'] );
		$this->assertContains( '/blog/wp/wp-admin/*', $patterns );
		$this->assertContains( '/blog/wp-content/*', $patterns );

		$script = SpeculationRules::to_script( SpeculationRules::build( '/</script><script>alert(1)//' ) );
		$this->assertSame( 1, substr_count( $script, '</script>' ) );
		$this->assertSame( '/', SpeculationRules::dir( '' ) );
		$this->assertSame( '/shop/', SpeculationRules::dir( 'https://example.test/shop?x=1' ) );
	}
}
