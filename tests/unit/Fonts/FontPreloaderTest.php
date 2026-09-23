<?php
/**
 * Tests for font preloads.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Fonts;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\Fonts\FontPreloader;
use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Modules\Preload\Hints;

final class FontPreloaderTest extends TestCase {

	private function page( string $head ): HtmlDocument {
		return new HtmlDocument( '<!DOCTYPE html><html><head><meta charset="utf-8"><title>t</title>' . $head . '</head><body><h1>x</h1></body></html>' );
	}

	private static function font( string $url, string $family = 'Inter', string $type = 'font/woff2' ): array {
		return array(
			'url'    => $url,
			'type'   => $type,
			'family' => $family,
			'weight' => '400',
			'style'  => 'normal',
		);
	}

	public function test_preloads_at_most_two_verified_woff2_fonts(): void {
		$candidates = array(
			self::font( 'https://example.test/wp-content/themes/t/fonts/brand.woff2', 'Brand' ),
			self::font( 'https://example.test/wp-content/themes/t/fonts/missing.woff2', 'Brand' ),
			self::font( 'https://example.test/wp-content/themes/t/fonts/brand.woff', 'Brand', 'font/woff' ),
			self::font( 'https://fonts.gstatic.com/s/inter/v13/a.woff2', 'Inter' ),
			self::font( 'https://fonts.gstatic.com/s/lora/v1/b.woff2', 'Lora' ),
		);
		$exists     = static fn( string $url ) => false !== strpos( $url, 'brand.woff2' );
		$doc        = $this->page( '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&amp;family=Lora&amp;display=swap">' );

		$added = ( new FontPreloader( $candidates, 'example.test', $exists ) )->transform( $doc, array( Hints::class, 'insert_early' ) );
		$html  = $doc->html();

		$this->assertSame( 2, $added );
		$this->assertStringContainsString( '<meta charset="utf-8"><link rel="preload" as="font" type="font/woff2" href="https://example.test/wp-content/themes/t/fonts/brand.woff2" crossorigin><link rel="preload" as="font" type="font/woff2" href="https://fonts.gstatic.com/s/inter/v13/a.woff2" crossorigin>', $html );
		$this->assertStringNotContainsString( 'lora/v1/b.woff2', $html );
	}

	public function test_skips_unverified_duplicates_foreign_and_icon_fonts(): void {
		$candidates = array(
			self::font( 'https://fonts.gstatic.com/s/inter/v13/a.woff2', 'Inter' ),
			self::font( 'https://cdn.other.test/font.woff2', 'Other' ),
			self::font( 'https://example.test/wp-content/plugins/elementor/assets/lib/eicons/fonts/eicons.woff2', 'eicons' ),
			self::font( 'https://example.test/wp-content/themes/t/fonts/done.woff2', 'Done' ),
			self::font( 'javascript:alert(1)//x.woff2', 'X' ),
		);
		// No Google Fonts stylesheet on this page (e.g. fonts were localized); "done" is preloaded already.
		$doc  = $this->page( '<link rel="preload" as="font" href="/wp-content/themes/t/fonts/done.woff2" crossorigin>' );
		$plan = ( new FontPreloader( $candidates, 'example.test', static fn( string $url ) => true ) )->plan( $doc );

		$this->assertSame( array(), $plan );
		$this->assertSame( array( 'inter' => true ), FontPreloader::google_families( $this->page( '<style>@import url(https://fonts.googleapis.com/css?family=Inter);</style>' ) ) );
	}
}
