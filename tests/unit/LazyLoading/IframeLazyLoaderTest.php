<?php
/**
 * Tests for iframe lazy loading.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\LazyLoading;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Modules\LazyLoading\IframeLazyLoader;

final class IframeLazyLoaderTest extends TestCase {

	private function page( string $body ): HtmlDocument {
		return new HtmlDocument( '<!DOCTYPE html><html><head><title>t</title></head><body>' . $body . '</body></html>' );
	}

	public function test_first_iframe_before_heading_stays_eager(): void {
		$doc = $this->page( '<iframe src="https://www.youtube.com/embed/abcdefghijk"></iframe><h1>Title</h1><iframe src="https://www.google.com/maps/embed?pb=x"></iframe>' );
		( new IframeLazyLoader( array() ) )->transform( $doc );
		$html = $doc->html();

		$this->assertStringContainsString( '<iframe src="https://www.youtube.com/embed/abcdefghijk"></iframe>', $html );
		$this->assertStringContainsString( '<iframe src="https://www.google.com/maps/embed?pb=x" loading="lazy"></iframe>', $html );
	}

	public function test_first_iframe_after_heading_is_lazy_unless_media_in_viewport(): void {
		$body = '<h2>Intro</h2><iframe src="https://player.vimeo.com/video/1"></iframe>';

		$doc = $this->page( $body );
		( new IframeLazyLoader( array() ) )->transform( $doc );
		$this->assertStringContainsString( '<iframe src="https://player.vimeo.com/video/1" loading="lazy">', $doc->html() );

		$doc = $this->page( $body );
		( new IframeLazyLoader( array(), true ) )->transform( $doc );
		$this->assertStringContainsString( '<iframe src="https://player.vimeo.com/video/1"></iframe>', $doc->html() );
	}

	public function test_skips_existing_loading_other_loaders_hidden_frames_and_exclusions(): void {
		$body = '<h1>x</h1><p>text</p>'
			. '<iframe src="https://example.com/a" loading="eager"></iframe>'
			. '<iframe src="about:blank" data-src="https://example.com/b"></iframe>'
			. '<iframe src="https://example.com/c" class="lazyload"></iframe>'
			. '<iframe src="https://js.stripe.com/v3/" width="1" height="1"></iframe>'
			. '<iframe src="https://example.com/d" style="display:none"></iframe>'
			. '<iframe src="https://example.com/e" class="no-lazy-frame"></iframe>'
			. '<iframe srcdoc="<p>x</p>"></iframe>'
			. '<noscript><iframe src="https://www.googletagmanager.com/ns.html"></iframe></noscript>'
			. '<iframe src="https://example.com/f"></iframe>';

		$doc = $this->page( $body );
		( new IframeLazyLoader( array( 'no-lazy-frame' ) ) )->transform( $doc );
		$html = $doc->html();

		$this->assertSame( 1, substr_count( $html, 'loading="lazy"' ) );
		$this->assertStringContainsString( '<iframe src="https://example.com/f" loading="lazy"></iframe>', $html );
		$this->assertStringContainsString( '<iframe src="https://js.stripe.com/v3/" width="1" height="1"></iframe>', $html );
		$this->assertStringContainsString( '<noscript><iframe src="https://www.googletagmanager.com/ns.html"></iframe></noscript>', $html );
	}
}
