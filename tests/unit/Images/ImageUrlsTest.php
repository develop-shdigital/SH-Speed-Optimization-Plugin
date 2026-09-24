<?php
/**
 * Tests for image URL helpers.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Images;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\Images\ImageUrls;

final class ImageUrlsTest extends TestCase {

	public function test_canonical_ignores_scheme_ver_and_fragment(): void {
		$expected = 'example.test/wp-content/uploads/a.jpg';
		$this->assertSame( $expected, ImageUrls::canonical( 'https://Example.test/wp-content/uploads/a.jpg?ver=6.5#x' ) );
		$this->assertSame( $expected, ImageUrls::canonical( '//example.test/wp-content/uploads/a.jpg' ) );
		$this->assertSame( $expected, ImageUrls::canonical( '/wp-content/uploads/a.jpg', 'example.test' ) );
		$this->assertSame( $expected, ImageUrls::canonical( 'http://example.test/wp-content/uploads/a%2Ejpg' ) );
		$this->assertSame( 'example.test/a.jpg?w=300', ImageUrls::canonical( 'https://example.test/a.jpg?ver=1&w=300' ) );
		$this->assertSame( '', ImageUrls::canonical( 'data:image/gif;base64,R0lGOD' ) );
	}

	public function test_canonical_maps_derivatives_back_to_originals(): void {
		$this->assertSame(
			'example.test/wp-content/uploads/2024/05/photo-800x600.jpg',
			ImageUrls::canonical( 'https://example.test/wp-content/uploads/sh-speed-optimizer/webp/2024/05/photo-800x600.jpg.webp' )
		);
		$this->assertSame(
			'https://example.test/wp-content/uploads/2024/05/a.png',
			ImageUrls::original_url( 'https://example.test/wp-content/uploads/sh-speed-optimizer/webp/2024/05/a.png.webp' )
		);
		$this->assertTrue( ImageUrls::same( 'https://example.test/x/a.jpg', '//example.test/x/a.jpg?ver=2' ) );
	}

	public function test_parse_and_build_srcset(): void {
		$parsed = ImageUrls::parse_srcset( 'a-300x200.jpg 300w, https://cdn.test/w_600,h_400/a.jpg 600w,b.jpg 2x , c.jpg' );
		$this->assertSame(
			array(
				array( 'url' => 'a-300x200.jpg', 'descriptor' => '300w' ),
				array( 'url' => 'https://cdn.test/w_600,h_400/a.jpg', 'descriptor' => '600w' ),
				array( 'url' => 'b.jpg', 'descriptor' => '2x' ),
				array( 'url' => 'c.jpg', 'descriptor' => '' ),
			),
			$parsed
		);
		$this->assertSame( 'a-300x200.jpg 300w, https://cdn.test/w_600,h_400/a.jpg 600w, b.jpg 2x, c.jpg', ImageUrls::build_srcset( $parsed ) );
		$this->assertSame( array(), ImageUrls::parse_srcset( '  ' ) );
	}

	public function test_size_from_filename(): void {
		$this->assertSame( array( 800, 600 ), ImageUrls::size_from_filename( 'https://example.test/wp-content/uploads/2024/05/photo-800x600.jpg' ) );
		$this->assertSame( array( 150, 150 ), ImageUrls::size_from_filename( '/uploads/a-150x150.PNG?ver=2' ) );
		$this->assertSame( array( 300, 200 ), ImageUrls::size_from_filename( '/uploads/sh-speed-optimizer/webp/a-300x200.jpg.webp' ) );
		$this->assertNull( ImageUrls::size_from_filename( '/uploads/photo.jpg' ) );
		$this->assertNull( ImageUrls::size_from_filename( '/uploads/photo-0x600.jpg' ) );
		$this->assertNull( ImageUrls::size_from_filename( '/uploads/icon-24x24.svg' ) );
	}

	public function test_size_from_srcset_uses_width_descriptor_and_sibling_ratio(): void {
		$src    = 'https://example.test/wp-content/uploads/2024/05/photo-scaled.jpg';
		$srcset = 'https://example.test/wp-content/uploads/2024/05/photo-scaled.jpg 2560w, https://example.test/wp-content/uploads/2024/05/photo-300x200.jpg 300w, https://example.test/wp-content/uploads/2024/05/photo-1024x683.jpg 1024w';
		$size = ImageUrls::size_from_srcset( $src, $srcset );
		$this->assertSame( 2560, $size[0] );
		// Sub-size heights are rounded by WordPress, so the derived height is accurate to ±1 px (original: 2560×1707).
		$this->assertEqualsWithDelta( 1707, $size[1], 1 );

		// Sibling of another image or no width descriptor for src: unknown.
		$this->assertNull( ImageUrls::size_from_srcset( $src, 'https://example.test/other-300x200.jpg 300w, ' . $src . ' 2560w' ) );
		$this->assertNull( ImageUrls::size_from_srcset( $src, 'https://example.test/wp-content/uploads/2024/05/photo-300x200.jpg 1x' ) );
	}

	public function test_local_path_mapping_refuses_traversal_and_foreign_hosts(): void {
		$prefixes = array( 'https://example.test/wp-content/uploads/' => '/var/www/wp-content/uploads/' );

		$this->assertSame( '/var/www/wp-content/uploads/2024/a b.jpg', ImageUrls::local_path( 'http://example.test/wp-content/uploads/2024/a%20b.jpg?x=1', $prefixes ) );
		$this->assertSame( '/var/www/wp-content/uploads/a.jpg', ImageUrls::local_path( '/wp-content/uploads/a.jpg', $prefixes, 'example.test' ) );
		$this->assertNull( ImageUrls::local_path( 'https://example.test/wp-content/uploads/../../wp-config.php', $prefixes ) );
		$this->assertNull( ImageUrls::local_path( 'https://example.test/wp-content/uploads/%2e%2e/secret.jpg', $prefixes ) );
		$this->assertNull( ImageUrls::local_path( 'https://example.test/wp-content/uploads/a%00.jpg', $prefixes ) );
		$this->assertNull( ImageUrls::local_path( 'https://cdn.other.test/wp-content/uploads/a.jpg', $prefixes ) );
		$this->assertNull( ImageUrls::local_path( 'data:image/png;base64,AAAA', $prefixes ) );
	}
}
