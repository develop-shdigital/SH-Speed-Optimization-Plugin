<?php
/**
 * Tests for LCP image prioritization.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Preload;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Modules\Preload\LcpPreloader;

final class LcpPreloaderTest extends TestCase {

	private const HERO = 'https://example.test/wp-content/uploads/hero-1024x683.jpg';

	private function page( string $body, string $head = '' ): HtmlDocument {
		return new HtmlDocument( '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>t</title>' . $head . '</head><body>' . $body . '</body></html>' );
	}

	private static function lcp( string $url = self::HERO, string $type = 'img', int $confidence = 90 ): array {
		return array(
			'url'        => $url,
			'type'       => $type,
			'selector'   => 'main img',
			'confidence' => $confidence,
		);
	}

	public function test_adds_exactly_one_preload_with_srcset_and_sizes_and_prioritizes_the_image(): void {
		$img = '<img src="/wp-content/uploads/hero.jpg" srcset="/wp-content/uploads/hero-1024x683.jpg 1024w, /wp-content/uploads/hero.jpg 2048w" sizes="auto, (max-width: 1024px) 100vw, 1024px" loading="lazy" alt="">';
		$doc = $this->page( $img . '<img src="/wp-content/uploads/hero.jpg" alt="copy">' );

		$this->assertTrue( ( new LcpPreloader( self::lcp(), 'example.test' ) )->transform( $doc ) );
		$html = $doc->html();

		$this->assertSame( 1, substr_count( $html, 'rel="preload"' ) );
		$this->assertStringContainsString( '<meta charset="UTF-8"><link rel="preload" as="image" href="/wp-content/uploads/hero.jpg" fetchpriority="high" imagesrcset="/wp-content/uploads/hero-1024x683.jpg 1024w, /wp-content/uploads/hero.jpg 2048w" imagesizes="(max-width: 1024px) 100vw, 1024px">', $html );
		$this->assertStringContainsString( 'sizes="(max-width: 1024px) 100vw, 1024px" alt="" fetchpriority="high">', $html );
		$this->assertStringNotContainsString( 'loading="lazy"', $html );
		$this->assertStringContainsString( '<img src="/wp-content/uploads/hero.jpg" alt="copy">', $html, 'Only the first matching image is changed.' );

		// Running again adds nothing (preload exists).
		( new LcpPreloader( self::lcp(), 'example.test' ) )->transform( $doc );
		$this->assertSame( 1, substr_count( $doc->html(), 'rel="preload"' ) );
	}

	public function test_does_nothing_when_image_absent_low_confidence_or_other_high_priority_image(): void {
		$doc = $this->page( '<img src="/wp-content/uploads/other.jpg" alt="">' );
		$this->assertFalse( ( new LcpPreloader( self::lcp(), 'example.test' ) )->transform( $doc ), 'Page data from another URL of the template.' );

		$doc = $this->page( '<img src="' . self::HERO . '" alt="">' );
		$this->assertFalse( ( new LcpPreloader( self::lcp( self::HERO, 'img', 60 ), 'example.test' ) )->transform( $doc ) );
		$this->assertFalse( ( new LcpPreloader( self::lcp( self::HERO, 'text' ), 'example.test' ) )->transform( $doc ) );

		$doc    = $this->page( '<img src="/logo.png" fetchpriority="high" alt=""><img src="' . self::HERO . '" alt="">' );
		$before = $doc->html();
		$this->assertFalse( ( new LcpPreloader( self::lcp(), 'example.test' ) )->transform( $doc ) );
		$this->assertSame( $before, $doc->html() );
	}

	public function test_existing_preload_is_not_duplicated_but_image_gets_priority(): void {
		$doc = $this->page( '<img src="' . self::HERO . '" alt="">', '<link rel="preload" as="image" href="//example.test/wp-content/uploads/hero-1024x683.jpg?ver=3">' );
		( new LcpPreloader( self::lcp(), 'example.test' ) )->transform( $doc );
		$this->assertSame( 1, substr_count( $doc->html(), 'rel="preload"' ) );
		$this->assertStringContainsString( 'fetchpriority="high"', $doc->html() );
	}

	public function test_background_lcp_needs_the_url_in_the_markup(): void {
		$lcp = self::lcp( 'https://example.test/wp-content/uploads/bg.jpg', 'background' );

		$doc = $this->page( '<div style="background-image:url(&quot;https://example.test/wp-content/uploads/sh-speed-optimizer/webp/bg.jpg.webp&quot;)">x</div>' );
		$this->assertTrue( ( new LcpPreloader( $lcp, 'example.test' ) )->transform( $doc ) );
		$this->assertStringContainsString( '<link rel="preload" as="image" href="https://example.test/wp-content/uploads/sh-speed-optimizer/webp/bg.jpg.webp" fetchpriority="high">', $doc->html(), 'The URL actually used by the page is preloaded.' );

		$doc = $this->page( '<div class="hero">x</div>' );
		$this->assertFalse( ( new LcpPreloader( $lcp, 'example.test' ) )->transform( $doc ) );
		$this->assertStringNotContainsString( 'preload', $doc->html() );
	}

	public function test_picture_source_match_gets_priority_without_preload_and_hostile_urls_are_escaped(): void {
		$doc = $this->page( '<picture><source media="(max-width:600px)" srcset="' . self::HERO . '"><img src="/wp-content/uploads/desktop.jpg" alt=""></picture>' );
		$this->assertTrue( ( new LcpPreloader( self::lcp(), 'example.test' ) )->transform( $doc ) );
		$this->assertStringContainsString( '<img src="/wp-content/uploads/desktop.jpg" alt="" fetchpriority="high">', $doc->html() );
		$this->assertStringNotContainsString( 'rel="preload"', $doc->html() );

		$hostile = 'https://example.test/wp-content/uploads/x.jpg?a="><script>alert(1)</script>';
		$doc     = $this->page( '<img src="' . htmlspecialchars( $hostile, ENT_QUOTES ) . '" alt="">' );
		( new LcpPreloader( self::lcp( $hostile ), 'example.test' ) )->transform( $doc );
		$this->assertStringNotContainsString( '<script>alert(1)', $doc->html() );
	}
}
