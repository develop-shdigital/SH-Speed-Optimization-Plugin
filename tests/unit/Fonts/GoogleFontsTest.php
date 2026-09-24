<?php
/**
 * Tests for the Google Fonts URL parser.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Fonts;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\Fonts\GoogleFonts;

final class GoogleFontsTest extends TestCase {

	public function test_parses_css_v1(): void {
		$parsed = GoogleFonts::parse( 'https://fonts.googleapis.com/css?family=Open+Sans:400,700,400italic|Lato:b,bi&subset=latin,latin-ext' );

		$this->assertTrue( $parsed['valid'] );
		$this->assertSame( 'css', $parsed['api'] );
		$this->assertSame( array( '400', '700' ), $parsed['families']['Open Sans']['weights'] );
		$this->assertSame( array( 'italic', 'normal' ), $parsed['families']['Open Sans']['styles'] );
		$this->assertSame( array( '700' ), $parsed['families']['Lato']['weights'] );
		$this->assertSame( 'latin,latin-ext', $parsed['subset'] );
		$this->assertNull( $parsed['display'] );

		$encoded = GoogleFonts::parse( '//fonts.googleapis.com/css?family=Montserrat%3A300%2C600%7CRoboto' );
		$this->assertSame( array( 'Montserrat', 'Roboto' ), array_keys( $encoded['families'] ) );
		$this->assertSame( array( '400' ), $encoded['families']['Roboto']['weights'] );
	}

	public function test_parses_css2_with_repeated_family_parameters(): void {
		$parsed = GoogleFonts::parse( 'https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,400;0,700;1,400&family=Inter:wght@100..900&family=Lora&display=swap' );

		$this->assertSame( 'css2', $parsed['api'] );
		$this->assertSame( array( 'Roboto', 'Inter', 'Lora' ), array_keys( $parsed['families'] ) );
		$this->assertSame( array( '400', '700' ), $parsed['families']['Roboto']['weights'] );
		$this->assertSame( array( 'italic', 'normal' ), $parsed['families']['Roboto']['styles'] );
		$this->assertSame( array( '100..900' ), $parsed['families']['Inter']['weights'] );
		$this->assertSame( 'swap', $parsed['display'] );

		$this->assertFalse( GoogleFonts::parse( 'https://example.test/css?family=X' )['valid'] );
		$this->assertFalse( GoogleFonts::is_css_url( 'https://fonts.googleapis.com/earlyaccess/notosans.css' ) );
	}

	public function test_add_display_swap(): void {
		$this->assertSame( 'https://fonts.googleapis.com/css?family=Lato&display=swap', GoogleFonts::add_display_swap( 'https://fonts.googleapis.com/css?family=Lato' ) );
		$this->assertSame( 'https://fonts.googleapis.com/css2?family=Inter&display=optional', GoogleFonts::add_display_swap( 'https://fonts.googleapis.com/css2?family=Inter&display=optional' ) );
		$this->assertSame( 'https://fonts.googleapis.com/icon?family=Material+Icons', GoogleFonts::add_display_swap( 'https://fonts.googleapis.com/icon?family=Material+Icons' ) );
		$this->assertSame( 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined', GoogleFonts::add_display_swap( 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined' ) );
		$this->assertSame( 'https://example.test/fonts.css', GoogleFonts::add_display_swap( 'https://example.test/fonts.css' ) );
	}

	public function test_duplicates_and_keys(): void {
		$dupes = GoogleFonts::duplicates(
			array(
				'https://fonts.googleapis.com/css?family=Roboto:400|Lato',
				'https://fonts.googleapis.com/css2?family=Roboto:wght@700',
				'https://fonts.googleapis.com/css2?family=Inter',
			)
		);
		$this->assertSame( array( 'Roboto' => 2 ), $dupes );

		$this->assertSame(
			GoogleFonts::key( 'https://fonts.googleapis.com/css?family=Lato&display=swap' ),
			GoogleFonts::key( 'http://fonts.googleapis.com/css?family=Lato' )
		);
		$this->assertSame( array( array( 'family', 'A B' ), array( 'family', 'C' ) ), GoogleFonts::query_pairs( 'family=A+B&family=C&' ) );
	}
}
