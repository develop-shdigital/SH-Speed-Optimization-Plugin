<?php
/**
 * Tests for WebP URL rewriting.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Images;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Images\DerivativeMap;
use SH\SpeedOptimizer\Modules\ImageOptimization\WebpRewriter;

final class WebpRewriterTest extends TestCase {

	private const UP  = 'https://example.test/wp-content/uploads/';
	private const DER = 'https://example.test/wp-content/uploads/sh-speed-optimizer/webp/';

	/**
	 * Derivative files that "exist".
	 *
	 * @var string[]
	 */
	private array $existing = array();

	/**
	 * Paths checked.
	 *
	 * @var string[]
	 */
	private array $checked = array();

	private function rewriter( array $exclude = array(), bool $avif = false ): WebpRewriter {
		$map = new DerivativeMap( '/srv/uploads', self::UP, '/srv/uploads/sh-speed-optimizer/webp' );
		return new WebpRewriter(
			$map,
			function ( string $path ): bool {
				$this->checked[] = $path;
				return in_array( $path, $this->existing, true );
			},
			$exclude,
			'example.test',
			$avif
		);
	}

	private function page( string $body ): HtmlDocument {
		return new HtmlDocument( '<!DOCTYPE html><html><head><title>t</title></head><body>' . $body . '</body></html>' );
	}

	protected function setUp(): void {
		$this->existing = array(
			'/srv/uploads/sh-speed-optimizer/webp/2024/05/a.jpg.webp',
			'/srv/uploads/sh-speed-optimizer/webp/2024/05/a-300x200.jpg.webp',
			'/srv/uploads/sh-speed-optimizer/webp/2024/05/bg.png.webp',
		);
		$this->checked  = array();
	}

	public function test_rewrites_src_and_srcset_only_when_derivative_exists(): void {
		$doc = $this->page( '<img src="' . self::UP . '2024/05/a.jpg" srcset="' . self::UP . '2024/05/a-300x200.jpg 300w, ' . self::UP . '2024/05/a-600x400.jpg 600w, ' . self::UP . '2024/05/a.jpg 1200w" alt="x"><img src="' . self::UP . '2024/05/missing.jpg">' );
		$this->rewriter()->transform( $doc );
		$html = $doc->html();

		$this->assertStringContainsString( 'src="' . self::DER . '2024/05/a.jpg.webp"', $html );
		$this->assertStringContainsString( self::DER . '2024/05/a-300x200.jpg.webp 300w, ' . self::UP . '2024/05/a-600x400.jpg 600w, ' . self::DER . '2024/05/a.jpg.webp 1200w', $html );
		$this->assertStringContainsString( '<img src="' . self::UP . '2024/05/missing.jpg">', $html, 'Images without a derivative stay unchanged.' );
		$this->assertSame( 1, count( array_keys( $this->checked, '/srv/uploads/sh-speed-optimizer/webp/2024/05/a.jpg.webp', true ) ), 'Existence checks are memoized per request.' );
	}

	public function test_picture_sources_background_images_and_protected_regions(): void {
		$body = '<picture>'
			. '<source type="image/jpeg" srcset="' . self::UP . '2024/05/a.jpg 1x, ' . self::UP . '2024/05/a-300x200.jpg 2x">'
			. '<source type="image/jpeg" media="(max-width:600px)" srcset="' . self::UP . '2024/05/a.jpg 1x, ' . self::UP . '2024/05/nope.jpg 2x">'
			. '<source type="image/avif" srcset="' . self::UP . '2024/05/a.avif">'
			. '<img src="' . self::UP . '2024/05/a.jpg"></picture>'
			. '<video><source src="' . self::UP . '2024/05/a.jpg" type="video/mp4"></video>'
			. '<div class="hero" style="background-image:url(&quot;' . self::UP . '2024/05/bg.png&quot;);color:red">x</div>'
			. '<section style="background: url(\'/wp-content/uploads/2024/05/bg.png\') no-repeat">y</section>'
			. '<noscript><img src="' . self::UP . '2024/05/a.jpg"></noscript>';

		$doc = $this->page( $body );
		$this->rewriter()->transform( $doc );
		$html = $doc->html();

		$this->assertStringContainsString( '<source type="image/webp" srcset="' . self::DER . '2024/05/a.jpg.webp 1x, ' . self::DER . '2024/05/a-300x200.jpg.webp 2x">', $html );
		$this->assertStringContainsString( '<source type="image/jpeg" media="(max-width:600px)"', $html, 'A typed source switches completely or not at all.' );
		$this->assertStringContainsString( '<source type="image/avif" srcset="' . self::UP . '2024/05/a.avif">', $html );
		$this->assertStringContainsString( '<source src="' . self::UP . '2024/05/a.jpg" type="video/mp4">', $html, 'Video sources are never touched.' );
		$this->assertStringContainsString( 'background-image:url(&quot;' . self::DER . '2024/05/bg.png.webp&quot;)', $html );
		$this->assertStringContainsString( 'url(&#039;/wp-content/uploads/sh-speed-optimizer/webp/2024/05/bg.png.webp&#039;)', $html );
		$this->assertStringContainsString( '<noscript><img src="' . self::UP . '2024/05/a.jpg"></noscript>', $html );
	}

	public function test_respects_exclusions_and_lazy_loader_attributes(): void {
		$doc = $this->page( '<img class="no-webp" src="' . self::UP . '2024/05/a.jpg"><img src="data:image/gif;base64,R0lG" data-src="' . self::UP . '2024/05/a.jpg" class="lazyload">' );
		$this->rewriter( array( 'no-webp' ) )->transform( $doc );
		$html = $doc->html();

		$this->assertStringContainsString( '<img class="no-webp" src="' . self::UP . '2024/05/a.jpg">', $html );
		$this->assertStringContainsString( 'data-src="' . self::DER . '2024/05/a.jpg.webp"', $html );
		$this->assertStringContainsString( 'src="data:image/gif;base64,R0lG"', $html );
	}

	public function test_avif_delivery_wraps_in_picture_only_when_enabled_and_complete(): void {
		$this->existing[] = '/srv/uploads/sh-speed-optimizer/webp/2024/05/a.jpg.avif';

		$doc = $this->page( '<img src="' . self::UP . '2024/05/a.jpg" alt="">' );
		$this->rewriter( array(), true )->transform( $doc );
		$this->assertStringContainsString( '<picture class="shso-picture"><source type="image/avif" srcset="' . self::DER . '2024/05/a.jpg.avif"><img src="' . self::DER . '2024/05/a.jpg.webp" alt=""></picture>', $doc->html() );

		$doc = $this->page( '<img src="' . self::UP . '2024/05/a.jpg" alt="">' );
		$this->rewriter()->transform( $doc );
		$this->assertStringNotContainsString( '<picture', $doc->html(), 'AVIF delivery is off by default.' );

		$doc = $this->page( '<img src="' . self::UP . '2024/05/a.jpg" srcset="' . self::UP . '2024/05/a.jpg 1200w, ' . self::UP . '2024/05/a-300x200.jpg 300w">' );
		$this->rewriter( array(), true )->transform( $doc );
		$this->assertStringNotContainsString( '<picture', $doc->html(), 'No AVIF source unless every candidate has one.' );
	}
}
