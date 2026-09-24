<?php
/**
 * Tests for the @font-face transformer.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Fonts;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\Fonts\FontFaceTransformer;

final class FontFaceTransformerTest extends TestCase {

	public function test_adds_swap_and_keeps_existing_font_display(): void {
		$css = "@font-face {\n\tfont-family: 'Brand Sans';\n\tsrc: url(brand.woff2) format('woff2');\n}\n"
			. "@font-face { font-family: Other; font-display: optional; src: url(o.woff2); }\n"
			. 'body{font-family:"Brand Sans"}';

		$out = FontFaceTransformer::add_swap( $css );

		$this->assertStringContainsString( "@font-face {font-display:swap;\n\tfont-family: 'Brand Sans';", $out );
		$this->assertStringContainsString( '@font-face { font-family: Other; font-display: optional; src: url(o.woff2); }', $out );
		$this->assertSame( 1, substr_count( $out, 'font-display:swap' ) );
		$this->assertStringEndsWith( 'body{font-family:"Brand Sans"}', $out );
	}

	public function test_skips_icon_fonts(): void {
		$css = '@font-face{font-family:"Font Awesome 6 Free";src:url(../webfonts/fa-solid-900.woff2)}'
			. '@font-face{font-family:dashicons;src:url(../fonts/dashicons.woff2)}'
			. '@font-face{font-family:eicons;src:url(eicons.woff2)}'
			. '@font-face{font-family:star;src:url(star.woff)}'
			. '@font-face{font-family:WooCommerce;src:url(WooCommerce.woff)}'
			. '@font-face{font-family:"Material Icons";src:url(https://fonts.gstatic.com/s/materialicons/v140/flUhRq6tzZclQEJ-Vdg-IuiaDsNc.woff2)}'
			. '@font-face{font-family:"Material Symbols Outlined";src:url(x.woff2)}'
			. '@font-face{font-family:"ETmodules";src:url(modules.woff)}'
			. '@font-face{font-family:"swiper-icons";src:url("data:application/font-woff;charset=utf-8;base64, d09GRgABAAAAAAZgABAAAAAADAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA")}'
			. '@font-face{font-family:"Custom";src:url(/wp-content/plugins/x/assets/fonts/icomoon.woff)}'
			. '@font-face{font-family:Lato;src:url(lato.woff2)}';

		$out = FontFaceTransformer::add_swap( $css );

		$this->assertSame( 1, substr_count( $out, 'font-display:swap' ) );
		$this->assertStringContainsString( '@font-face{font-display:swap;font-family:Lato;', $out );
		$this->assertTrue( FontFaceTransformer::is_icon_font( 'bootstrap-icons' ) );
		$this->assertTrue( FontFaceTransformer::is_icon_font( 'la-solid-900' ) );
		$this->assertTrue( FontFaceTransformer::is_icon_font( '"Linearicons-Free"' ) );
		$this->assertFalse( FontFaceTransformer::is_icon_font( 'Open Sans', 'https://fonts.gstatic.com/s/opensans/v40/mem8.woff2' ) );
	}

	public function test_minified_css_multiple_rules_and_comments(): void {
		$css = '/* @font-face{font-family:Commented;src:url(c.woff2)} */@font-face{font-family:A;src:url(a.woff2)}@FONT-FACE{font-family:B;src:url(b.woff2)}/* note { } */.x{color:red}';
		$out = FontFaceTransformer::add_swap( $css );

		$this->assertStringContainsString( '/* @font-face{font-family:Commented;src:url(c.woff2)} */', $out, 'Commented rules stay untouched.' );
		$this->assertStringContainsString( '@font-face{font-display:swap;font-family:A;src:url(a.woff2)}', $out );
		$this->assertStringContainsString( '@FONT-FACE{font-display:swap;font-family:B;src:url(b.woff2)}', $out );
		$this->assertStringContainsString( '/* note { } */.x{color:red}', $out );
		$this->assertSame( 0, FontFaceTransformer::count_missing( $out ) );
		$this->assertSame( 2, FontFaceTransformer::count_missing( $css ) );

		// Idempotent and unrelated CSS unchanged.
		$this->assertSame( $out, FontFaceTransformer::add_swap( $out ) );
		$this->assertSame( '.a{b:c}', FontFaceTransformer::process( '.a{b:c}' ) );
	}

	public function test_google_imports_get_display_swap(): void {
		$css = '@import url("https://fonts.googleapis.com/css?family=Roboto:400,700");@import \'//fonts.googleapis.com/css2?family=Inter&display=optional\';@import url(https://fonts.googleapis.com/icon?family=Material+Icons);';
		$out = FontFaceTransformer::process( $css );

		$this->assertStringContainsString( '@import url("https://fonts.googleapis.com/css?family=Roboto:400,700&display=swap")', $out );
		$this->assertStringContainsString( "@import '//fonts.googleapis.com/css2?family=Inter&display=optional'", $out );
		$this->assertStringContainsString( '@import url(https://fonts.googleapis.com/icon?family=Material+Icons)', $out );
	}
}
