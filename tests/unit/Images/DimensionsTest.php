<?php
/**
 * Tests for missing image dimensions.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Images;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Images\DimensionResolver;
use SH\SpeedOptimizer\Modules\ImageOptimization\DimensionFiller;

final class DimensionsTest extends TestCase {

	private function page( string $body ): HtmlDocument {
		return new HtmlDocument( '<!DOCTYPE html><html><head><title>t</title></head><body>' . $body . '</body></html>' );
	}

	private function resolver( array &$probes, array $meta = array() ): DimensionResolver {
		return new DimensionResolver(
			array( 'https://example.test/wp-content/uploads/' => '/srv/uploads/' ),
			'example.test',
			static function ( int $id ) use ( $meta ) {
				return $meta[ $id ] ?? null;
			},
			static function ( string $path ) use ( &$probes ) {
				$probes[] = $path;
				return '/srv/uploads/2024/plain.jpg' === $path ? array( 1600, 900 ) : null;
			},
			array()
		);
	}

	public function test_resolution_order_metadata_filename_srcset_probe(): void {
		$probes = array();
		$meta   = array(
			42 => array(
				'file'   => '2024/photo.jpg',
				'width'  => 2000,
				'height' => 1000,
				'sizes'  => array( 'medium' => array( 'file' => 'photo-300x150.jpg', 'width' => 300, 'height' => 150 ) ),
			),
		);
		$resolver = $this->resolver( $probes, $meta );

		$this->assertSame( array( 2000, 1000 ), $resolver->resolve( 'https://example.test/wp-content/uploads/2024/photo.jpg', '', array( 'wp-image-42' ) ) );
		$this->assertSame( array( 300, 150 ), $resolver->resolve( 'https://example.test/wp-content/uploads/2024/photo-300x150.jpg', '', array( 'wp-image-42' ) ) );
		$this->assertSame( array( 640, 480 ), $resolver->resolve( 'https://example.test/wp-content/uploads/2024/other-640x480.jpg' ) );
		$this->assertSame( array( 1600, 900 ), $resolver->resolve( 'https://example.test/wp-content/uploads/2024/plain.jpg' ) );
		$this->assertSame( array( 1600, 900 ), $resolver->resolve( 'https://example.test/wp-content/uploads/2024/plain.jpg' ) );
		$this->assertSame( array( '/srv/uploads/2024/plain.jpg' ), $probes, 'Probe results are cached.' );

		$this->assertNull( $resolver->resolve( 'https://cdn.other.test/a-640x480.jpg' ), 'External images are never measured.' );
		$this->assertNull( $resolver->resolve( 'https://example.test/wp-content/uploads/logo.svg' ) );
		$this->assertNull( $resolver->resolve( 'https://example.test/wp-content/uploads/../x-10x10.jpg' ) );
	}

	public function test_filler_skips_existing_style_svg_art_direction_and_lazy_placeholders(): void {
		$sizes  = array( 'a.jpg' => array( 800, 600 ) );
		$filler = new DimensionFiller(
			static function ( string $src ) use ( $sizes ) {
				return $sizes[ basename( $src ) ] ?? null;
			}
		);

		$doc = $this->page(
			'<img src="/u/a.jpg" alt="1">'
			. '<img src="/u/a.jpg" width="100" alt="2">'
			. '<img src="/u/a.jpg" style="height: 50px" alt="3">'
			. '<img src="/u/a.jpg" style="max-width:100%" alt="4">'
			. '<img src="/u/b.jpg" alt="5">'
			. '<picture><source media="(max-width:600px)" srcset="/u/m.jpg"><img src="/u/a.jpg" alt="6"></picture>'
			. '<picture><source type="image/webp" srcset="/u/a.webp"><img src="/u/a.jpg" alt="7"></picture>'
			. '<img src="/u/blank.gif" data-src="/u/a.jpg" alt="8">'
			. '<noscript><img src="/u/a.jpg" alt="9"></noscript>'
		);
		$filler->transform( $doc );
		$html = $doc->html();

		$this->assertStringContainsString( '<img src="/u/a.jpg" alt="1" width="800" height="600">', $html );
		$this->assertStringContainsString( '<img src="/u/a.jpg" width="100" alt="2">', $html );
		$this->assertStringContainsString( '<img src="/u/a.jpg" style="height: 50px" alt="3">', $html );
		$this->assertStringContainsString( '<img src="/u/a.jpg" style="max-width:100%" alt="4" width="800" height="600">', $html );
		$this->assertStringContainsString( '<img src="/u/b.jpg" alt="5">', $html );
		$this->assertStringContainsString( '<img src="/u/a.jpg" alt="6">', $html, 'Art-directed pictures are skipped.' );
		$this->assertStringContainsString( '<img src="/u/a.jpg" alt="7" width="800" height="600">', $html );
		$this->assertStringContainsString( '<img src="/u/blank.gif" data-src="/u/a.jpg" alt="8">', $html );
		$this->assertStringContainsString( '<noscript><img src="/u/a.jpg" alt="9"></noscript>', $html );
	}

	public function test_cache_is_bounded(): void {
		$map = array();
		for ( $i = 0; $i < 2100; $i++ ) {
			$map[ 'k' . $i ] = array( 1, 1 );
		}
		$bounded = DimensionResolver::bound( $map, 2000 );
		$this->assertCount( 1500, $bounded );
		$this->assertArrayHasKey( 'k2099', $bounded, 'Newest entries are kept.' );
		$this->assertArrayNotHasKey( 'k0', $bounded );
		$this->assertSame( array( 300, 150 ), DimensionResolver::size_in_metadata( 'x-300x150.jpg', array( 'sizes' => array( array( 'file' => 'x-300x150.jpg', 'width' => 300, 'height' => 150 ) ) ) ) );
	}
}
