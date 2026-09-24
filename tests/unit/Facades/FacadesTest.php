<?php
/**
 * Tests for video and map facades.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Facades;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Tag;
use SH\SpeedOptimizer\Modules\ThirdPartyOptimization\Facades;
use SH\SpeedOptimizer\Modules\ThirdPartyOptimization\MapFacadeOptimization;
use SH\SpeedOptimizer\Modules\ThirdPartyOptimization\VideoFacadeOptimization;

final class FacadesTest extends TestCase {

	private function page( string $body ): HtmlDocument {
		return new HtmlDocument( '<!DOCTYPE html><html><head><title>t</title></head><body><h1>Title</h1>' . $body . '</body></html>' );
	}

	public function test_video_id_parsing(): void {
		$this->assertSame( 'dQw4w9WgXcQ', Facades::youtube_id( 'https://www.youtube.com/embed/dQw4w9WgXcQ?si=abc&start=42' ) );
		$this->assertSame( 'dQw4w9WgXcQ', Facades::youtube_id( '//www.youtube-nocookie.com/embed/dQw4w9WgXcQ' ) );
		$this->assertNull( Facades::youtube_id( 'https://www.youtube.com/embed/videoseries?list=PL123' ) );
		$this->assertNull( Facades::youtube_id( 'https://evil.test/www.youtube.com/embed/dQw4w9WgXcQ' ) );
		$this->assertSame( '76979871', Facades::vimeo_id( 'https://player.vimeo.com/video/76979871?h=8272103f6e&title=0' ) );
		$this->assertSame( '8272103f6e', Facades::vimeo_hash( 'https://player.vimeo.com/video/76979871?h=8272103f6e' ) );
		$this->assertNull( Facades::vimeo_id( 'https://vimeo.com/76979871' ) );

		$this->assertSame( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=90s&list=PL123', Facades::youtube_watch_url( 'dQw4w9WgXcQ', 'https://www.youtube.com/embed/dQw4w9WgXcQ?start=90&list=PL123' ) );
		$this->assertSame( 90, Facades::start_seconds( 'https://x.test/?t=1m30s' ) );
		$this->assertSame( 'https://www.youtube.com/embed/x?rel=0&autoplay=1&start=5', Facades::autoplay_src( 'https://www.youtube.com/embed/x?rel=0&autoplay=0&start=5' ) );
		$this->assertSame( 'https://player.vimeo.com/video/1?autoplay=1', Facades::autoplay_src( 'https://player.vimeo.com/video/1' ) );
	}

	public function test_background_and_js_controlled_videos(): void {
		$this->assertTrue( Facades::is_background_video( 'https://www.youtube.com/embed/x?autoplay=1&mute=1' ) );
		$this->assertTrue( Facades::is_background_video( 'https://player.vimeo.com/video/1?background=1' ) );
		$this->assertTrue( Facades::is_background_video( 'https://player.vimeo.com/video/1?muted=1&loop=1' ) );
		$this->assertFalse( Facades::is_background_video( 'https://www.youtube.com/embed/x?mute=1' ) );
		$this->assertTrue( Facades::uses_js_api( 'https://www.youtube.com/embed/x?enablejsapi=1' ) );
	}

	public function test_youtube_iframe_becomes_accessible_facade_with_escaped_title(): void {
		$iframe = '<iframe width="560" height="315" class="yt embed-responsive-item" style="border:0" src="https://www.youtube.com/embed/dQw4w9WgXcQ?si=Xy&amp;start=42" title="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt; Rick" allow="accelerometer; encrypted-media" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>';
		$doc    = $this->page( $iframe );
		$result = VideoFacadeOptimization::transform( $doc, array() );
		$html   = $doc->html();

		$this->assertSame( 1, $result['count'] );
		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringNotContainsString( '<div', $html, 'Only phrasing content: valid inside <p>.' );
		$this->assertStringContainsString( 'class="shso-facade shso-facade--video yt embed-responsive-item"', $html );
		$this->assertStringContainsString( 'data-shso-src="https://www.youtube.com/embed/dQw4w9WgXcQ?si=Xy&amp;start=42&amp;autoplay=1"', $html );
		$this->assertStringContainsString( 'style="width:560px;max-width:100%;aspect-ratio:560/315;border:0;"', $html );
		$this->assertStringContainsString( 'src="https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg"', $html );
		$this->assertStringContainsString( 'loading="lazy" decoding="async"', $html );
		$this->assertStringContainsString( '<button type="button" class="shso-facade__play" aria-label="Play video: &quot;&gt;', $html );
		$this->assertStringContainsString( '<noscript><a class="shso-facade__link" href="https://www.youtube.com/watch?v=dQw4w9WgXcQ&amp;t=42s"', $html );

		// The original attributes survive as JSON for facades.js.
		$tag = Tag::parse( (string) preg_replace( '#^.*?(<span class="shso-facade[^>]*>).*$#s', '$1', $html ) );
		$this->assertNotNull( $tag );
		$attributes = json_decode( (string) $tag->get( 'data-shso-iframe' ), true );
		$this->assertSame( '560', $attributes['width'] );
		$this->assertSame( 'accelerometer; encrypted-media', $attributes['allow'] );
		$this->assertSame( '', $attributes['allowfullscreen'] );
		$this->assertSame( 'strict-origin-when-cross-origin', $attributes['referrerpolicy'] );
		$this->assertSame( 'https://www.youtube.com/embed/dQw4w9WgXcQ?si=Xy&start=42', $attributes['src'] );
	}

	public function test_videos_that_must_stay_untouched(): void {
		$body = '<iframe src="https://www.youtube.com/embed/aaaaaaaaaaa?autoplay=1&mute=1&loop=1"></iframe>'
			. '<iframe src="https://www.youtube.com/embed/bbbbbbbbbbb?enablejsapi=1"></iframe>'
			. '<iframe data-no-facade src="https://www.youtube.com/embed/ccccccccccc"></iframe>'
			. '<iframe src="https://www.youtube.com/embed/ddddddddddd?rel=0" class="keep-me"></iframe>'
			. '<iframe src="https://player.vimeo.com/video/123"></iframe>'
			. '<noscript><iframe src="https://www.youtube.com/embed/eeeeeeeeeee"></iframe></noscript>';
		$doc    = $this->page( $body );
		$before = $doc->html();
		$result = VideoFacadeOptimization::transform( $doc, array( 'exclude' => array( 'keep-me' ) ) );

		$this->assertSame( 0, $result['count'] );
		$this->assertSame( $before, $doc->html() );
		$this->assertSame( array( array( '123', '' ) ), $result['vimeo_missing'], 'Vimeo waits for a thumbnail.' );

		$doc    = $this->page( '<iframe src="https://player.vimeo.com/video/123" width="640" height="360" title="Demo"></iframe>' );
		$result = VideoFacadeOptimization::transform( $doc, array( 'thumbs' => array( '123' => array( 'url' => 'https://i.vimeocdn.com/video/555-abc_640' ) ) ) );
		$this->assertSame( 1, $result['count'] );
		$this->assertStringContainsString( 'src="https://i.vimeocdn.com/video/555-abc_640"', $doc->html() );
		$this->assertStringContainsString( 'alt="Video: Demo"', $doc->html() );

		$this->assertFalse( VideoFacadeOptimization::is_vimeo_thumbnail( 'https://evil.test/i.vimeocdn.com/x.jpg' ) );
		$this->assertFalse( VideoFacadeOptimization::is_vimeo_thumbnail( 'http://i.vimeocdn.com/x.jpg' ) );
	}

	public function test_map_facade_with_query_and_hero_rules(): void {
		$map = '<iframe src="https://maps.google.com/maps?q=Caf%C3%A9+%22Z%C3%BCrich%22+%3Cb%3E&amp;t=&amp;z=13&amp;output=embed" width="600" height="450" style="border:0;" allowfullscreen loading="lazy"></iframe>';
		$doc = $this->page( '<p>Find us</p>' . $map );

		$this->assertSame( 1, MapFacadeOptimization::transform( $doc ) );
		$html = $doc->html();
		$this->assertStringContainsString( 'class="shso-facade shso-facade--map"', $html );
		$this->assertStringContainsString( '<span class="shso-facade__label">Café &quot;Zürich&quot; &lt;b&gt;</span>', $html );
		$this->assertStringContainsString( 'aria-label="Load interactive map">Load map</button>', $html );
		$this->assertStringContainsString( 'href="https://www.google.com/maps/search/?api=1&amp;query=Caf%C3%A9%20%22Z%C3%BCrich%22%20%3Cb%3E"', $html );
		$this->assertStringContainsString( 'aspect-ratio:600/450;border:0;', $html );

		// First frame above the first heading, data-no-facade, exclusions and the Maps JavaScript API are untouched.
		$html = '<!DOCTYPE html><html><head><title>t</title><script src="https://maps.googleapis.com/maps/api/js?key=x"></script></head><body>'
			. '<iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12"></iframe><h2>Next</h2>'
			. '<iframe data-no-facade src="https://www.google.com/maps/embed?pb=2"></iframe>'
			. '<iframe src="https://www.google.com/maps/embed?pb=keep"></iframe></body></html>';
		$doc  = new HtmlDocument( $html );
		$this->assertSame( 0, MapFacadeOptimization::transform( $doc, array( 'pb=keep' ) ) );
		$this->assertSame( $html, $doc->html() );
	}

	public function test_map_helpers_and_asset_injection(): void {
		$this->assertTrue( Facades::is_google_maps_embed( 'https://www.google.com/maps/embed?pb=!1m18' ) );
		$this->assertTrue( Facades::is_google_maps_embed( 'https://maps.google.de/maps?q=Berlin&output=embed' ) );
		$this->assertTrue( Facades::is_google_maps_embed( 'https://www.google.com/maps?q=Paris&output=embed' ) );
		$this->assertFalse( Facades::is_google_maps_embed( 'https://www.google.com/maps?q=Paris' ) );
		$this->assertFalse( Facades::is_google_maps_embed( 'https://maps.googleapis.com/maps/api/js?key=x' ) );

		$this->assertSame( 'Eiffel Tower', Facades::map_query( 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2624.99!2d2.29!3d48.85!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x47e66e2964e34e2d%3A0x8ddca9ee380ef7e0!2sEiffel%20Tower!5e0!3m2!1sen!2sfr!4v1' ) );
		$this->assertNull( Facades::map_query( 'https://www.google.com/maps/embed?pb=!1m2' ) );
		$this->assertSame( 'https://www.google.com/maps/embed?pb=1', Facades::maps_link( null, '//www.google.com/maps/embed?pb=1' ) );

		$aspect = Facades::aspect( '100%', '400', Facades::MAP_RATIO );
		$this->assertSame( 'width:100%;height:400px;', Facades::sizing_style( $aspect ) );
		$this->assertSame( 'width:100%;aspect-ratio:16/9;', Facades::sizing_style( Facades::aspect( null, null, Facades::VIDEO_RATIO ) ) );

		$doc = $this->page( '<p>x</p>' );
		Facades::inject_assets( $doc, 'https://example.test/a/facades.css?ver=1', 'https://example.test/a/facades.min.js?ver=1' );
		Facades::inject_assets( $doc, 'https://example.test/a/facades.css?ver=1', 'https://example.test/a/facades.min.js?ver=1' );
		$this->assertSame( 1, substr_count( $doc->html(), 'id="shso-facades-css"' ) );
		$this->assertSame( 1, substr_count( $doc->html(), 'id="shso-facades-js"' ) );
		$this->assertStringContainsString( 'facades.min.js?ver=1" defer></script></body>', $doc->html() );
	}
}
