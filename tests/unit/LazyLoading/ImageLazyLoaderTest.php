<?php
/**
 * Tests for image lazy loading decisions.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\LazyLoading;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Modules\LazyLoading\ImageLazyLoader;

final class ImageLazyLoaderTest extends TestCase {

	private function page( string $body ): HtmlDocument {
		return new HtmlDocument( '<!DOCTYPE html><html><head><title>t</title></head><body class="home">' . $body . '</body></html>' );
	}

	private static function imgs( int $count, string $prefix = 'p' ): string {
		$html = '';
		for ( $i = 1; $i <= $count; $i++ ) {
			$html .= '<img src="https://example.test/wp-content/uploads/' . $prefix . $i . '.jpg" alt="">';
		}
		return $html;
	}

	public function test_first_image_and_first_three_content_images_stay_eager_without_page_data(): void {
		$doc = $this->page( '<img src="/hero-first.jpg" alt="">' . self::imgs( 5 ) );
		( new ImageLazyLoader( array(), array() ) )->transform( $doc );
		$html = $doc->html();

		$this->assertStringContainsString( '<img src="/hero-first.jpg" alt="">', $html );
		foreach ( array( 1, 2, 3 ) as $i ) {
			$this->assertStringContainsString( '<img src="https://example.test/wp-content/uploads/p' . $i . '.jpg" alt="">', $html );
		}
		$this->assertStringContainsString( '<img src="https://example.test/wp-content/uploads/p4.jpg" alt="" loading="lazy" decoding="async">', $html );
		$this->assertStringContainsString( '<img src="https://example.test/wp-content/uploads/p5.jpg" alt="" loading="lazy" decoding="async">', $html );
	}

	public function test_logo_header_pixel_fetchpriority_and_existing_loading_are_respected(): void {
		$body = '<header><a href="/"><img src="/brand.png" alt="Brand"></a><img src="/header-deco.png" alt=""></header>'
			. self::imgs( 3 )
			. '<img class="custom-logo" src="/footer-brand.png" alt="">'
			. '<img src="/tr.gif" width="1" height="1" alt="">'
			. '<img src="/important.jpg" fetchpriority="high" alt="">'
			. '<img src="/eager.jpg" loading="eager" alt="">'
			. '<img src="/dup.jpg" loading="lazy" loading="lazy" alt="">'
			. '<img src="/late.jpg" alt="" decoding="sync">';

		$doc = $this->page( $body );
		( new ImageLazyLoader( array(), array() ) )->transform( $doc );
		$html = $doc->html();

		$this->assertStringContainsString( '<img src="/brand.png" alt="Brand">', $html );
		$this->assertStringContainsString( '<img src="/header-deco.png" alt="">', $html );
		$this->assertStringContainsString( '<img class="custom-logo" src="/footer-brand.png" alt="">', $html );
		$this->assertStringContainsString( '<img src="/tr.gif" width="1" height="1" alt="">', $html );
		$this->assertStringContainsString( '<img src="/important.jpg" fetchpriority="high" alt="">', $html );
		$this->assertStringContainsString( '<img src="/eager.jpg" loading="eager" alt="">', $html );
		$this->assertStringContainsString( '<img src="/dup.jpg" loading="lazy" loading="lazy" alt="">', $html, 'Duplicate attributes: untouched.' );
		$this->assertStringContainsString( '<img src="/late.jpg" alt="" decoding="sync" loading="lazy">', $html, 'An existing decoding value is kept.' );
	}

	public function test_other_lazy_loaders_exclusions_and_noscript_are_untouched(): void {
		$body = self::imgs( 3 )
			. '<img src="data:image/gif;base64,R0lG" data-src="/a.jpg" alt="">'
			. '<img class="lazyload" src="/b.jpg" alt="">'
			. '<img src="/c.jpg" data-lazy-src="/c2.jpg" alt="">'
			. '<img src="/d.jpg" data-srcset="/d.jpg 1x" alt="">'
			. '<img class="skip-lazy slide" src="/e.jpg" alt="">'
			. '<img src="/f.jpg" data-no-lazy="1" alt="">'
			. '<noscript><img src="/g.jpg" alt=""></noscript>'
			. '<img src="/h.jpg" alt="">';

		$doc = $this->page( $body );
		( new ImageLazyLoader( array( 'skip-lazy', 'data-no-lazy' ), array() ) )->transform( $doc );
		$html = $doc->html();

		$this->assertStringContainsString( '<img src="data:image/gif;base64,R0lG" data-src="/a.jpg" alt="">', $html );
		$this->assertStringContainsString( '<img class="lazyload" src="/b.jpg" alt="">', $html );
		$this->assertStringContainsString( '<img src="/c.jpg" data-lazy-src="/c2.jpg" alt="">', $html );
		$this->assertStringContainsString( '<img src="/d.jpg" data-srcset="/d.jpg 1x" alt="">', $html );
		$this->assertStringContainsString( '<img class="skip-lazy slide" src="/e.jpg" alt="">', $html );
		$this->assertStringContainsString( '<img src="/f.jpg" data-no-lazy="1" alt="">', $html );
		$this->assertStringContainsString( '<noscript><img src="/g.jpg" alt=""></noscript>', $html );
		$this->assertStringContainsString( '<img src="/h.jpg" alt="" loading="lazy" decoding="async">', $html );
	}

	public function test_page_data_lcp_and_above_fold_images_are_never_lazy_and_core_lazy_lcp_becomes_eager(): void {
		$page_data = array(
			'lcp'               => array(
				'url'        => 'https://example.test/wp-content/uploads/hero-1024x683.jpg',
				'type'       => 'img',
				'confidence' => 90,
			),
			'above_fold_images' => array( 'https://example.test/wp-content/uploads/side.jpg?ver=2' ),
		);
		$body      = '<img src="/first.jpg" alt="">'
			. '<img src="/c1.jpg" alt="">'
			. '<img src="/c2.jpg" alt="">'
			. '<img src="/c3.jpg" alt="">'
			. '<img src="/wp-content/uploads/hero.jpg" srcset="/wp-content/uploads/hero-1024x683.jpg 1024w, /wp-content/uploads/hero.jpg 2048w" sizes="auto, (max-width: 1024px) 100vw, 1024px" loading="lazy" alt="">'
			. '<img src="//example.test/wp-content/uploads/side.jpg" alt="">';

		$doc = $this->page( $body );
		( new ImageLazyLoader( array(), $page_data, 'example.test' ) )->transform( $doc );
		$html = $doc->html();

		$this->assertStringContainsString( 'sizes="(max-width: 1024px) 100vw, 1024px" alt="">', $html );
		$this->assertStringNotContainsString( 'hero.jpg 2048w" sizes="auto', $html );
		$this->assertMatchesRegularExpression( '#<img src="/wp-content/uploads/hero.jpg"[^>]*>#', $html );
		$this->assertDoesNotMatchRegularExpression( '#<img src="/wp-content/uploads/hero.jpg"[^>]*loading#', $html, 'The LCP image loses loading="lazy".' );
		$this->assertStringContainsString( '<img src="//example.test/wp-content/uploads/side.jpg" alt="">', $html );

		// Measured: one above-the-fold image (plus the LCP) → only the first content image is protected by position.
		$this->assertStringContainsString( '<img src="/c1.jpg" alt="">', $html );
		$this->assertStringContainsString( '<img src="/c2.jpg" alt="" loading="lazy" decoding="async">', $html );
	}

	public function test_protected_count_rules(): void {
		$this->assertSame( 3, ImageLazyLoader::protected_count( array() ) );
		$this->assertSame( 0, ImageLazyLoader::protected_count( array( 'above_fold_images' => array() ) ) );
		$this->assertSame( 1, ImageLazyLoader::protected_count( array( 'above_fold_images' => array(), 'lcp' => array( 'type' => 'img', 'url' => 'x' ) ) ) );
		$this->assertSame( 3, ImageLazyLoader::protected_count( array( 'above_fold_images' => array( 'a', 'b', 'c', 'd', 'e' ) ) ) );
	}
}
