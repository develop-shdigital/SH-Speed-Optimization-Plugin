<?php
/**
 * Tests for the tag model and masked HTML document.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Assets;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Tag;

final class HtmlDocumentTest extends TestCase {

	public function test_tag_parse_and_serialize_roundtrip(): void {
		$html = '<img src="a.jpg?x=1&amp;y=2" alt=\'He said "hi"\' data-x=raw loading width="10">';
		$tag  = Tag::parse( $html );

		$this->assertNotNull( $tag );
		$this->assertSame( 'img', $tag->name );
		$this->assertSame( 'a.jpg?x=1&y=2', $tag->get( 'src' ) );
		$this->assertSame( 'He said "hi"', $tag->get( 'alt' ) );
		$this->assertSame( 'raw', $tag->get( 'data-x' ) );
		$this->assertSame( '', $tag->get( 'loading' ) );
		$this->assertNull( $tag->get( 'missing' ) );
		$this->assertSame( $html, $tag->to_html(), 'Unchanged tags keep their exact markup.' );

		$tag->set( 'loading', 'lazy' );
		$this->assertStringContainsString( 'loading="lazy"', $tag->to_html() );
		$this->assertStringContainsString( 'src="a.jpg?x=1&amp;y=2"', $tag->to_html() );
		$this->assertStringContainsString( 'alt="He said &quot;hi&quot;"', $tag->to_html() );
	}

	public function test_tag_handles_gt_inside_attribute_and_self_closing(): void {
		$tag = Tag::parse( '<img data-note="a > b" src="x.png" />' );
		$this->assertNotNull( $tag );
		$this->assertSame( 'a > b', $tag->get( 'data-note' ) );
		$tag->set( 'decoding', 'async' );
		$this->assertStringEndsWith( ' />', $tag->to_html() );
	}

	public function test_empty_attribute_value_is_not_boolean(): void {
		$tag = Tag::parse( '<img alt="" src=x>' );
		$tag->set( 'loading', 'lazy' );
		$this->assertStringContainsString( 'alt=""', $tag->to_html() );
	}

	public function test_replace_tags_skips_protected_regions(): void {
		$html = '<html><head><title>t</title></head><body>'
			. '<!-- <img src="comment.jpg"> -->'
			. '<script>var s = \'<img src="script.jpg">\';</script>'
			. '<noscript><img src="noscript.jpg"></noscript>'
			. '<textarea><img src="textarea.jpg"></textarea>'
			. '<img src="real.jpg">'
			. '</body></html>';

		$doc   = new HtmlDocument( $html );
		$count = $doc->replace_tags(
			'img',
			static function ( Tag $tag ) {
				return $tag->set( 'loading', 'lazy' );
			}
		);

		$this->assertSame( 1, $count );
		$this->assertStringContainsString( '<img src="real.jpg" loading="lazy">', $doc->html() );
		$this->assertStringContainsString( '<!-- <img src="comment.jpg"> -->', $doc->html() );
		$this->assertStringContainsString( '<noscript><img src="noscript.jpg"></noscript>', $doc->html() );
		$this->assertStringContainsString( '\'<img src="script.jpg">\'', $doc->html() );
	}

	public function test_replace_scripts_reports_head_position(): void {
		$html = '<html><head><script src="a.js"></script></head><body><script>var x=1;</script><!-- <script src="c.js"></script> --></body></html>';
		$doc  = new HtmlDocument( $html );
		$seen = array();

		$doc->replace_scripts(
			static function ( Tag $tag, string $code, array $info ) use ( &$seen ) {
				$seen[] = array( $tag->get( 'src' ), $code, $info['in_head'] );
				return null;
			}
		);

		$this->assertSame(
			array(
				array( 'a.js', '', true ),
				array( null, 'var x=1;', false ),
			),
			$seen
		);
		$this->assertSame( $html, $doc->html() );
	}

	public function test_insertions(): void {
		$doc = new HtmlDocument( '<html><head><meta charset="utf-8"><script>var a="</head>";</script></head><body><p>x</p></body></html>' );
		$this->assertTrue( $doc->insert_in_head( '<link rel="preconnect" href="https://a">' ) );
		$this->assertTrue( $doc->insert_in_head( '<script>first()</script>', true ) );
		$this->assertTrue( $doc->insert_before_body_end( '<div id="end"></div>' ) );

		$this->assertSame(
			'<html><head><script>first()</script><meta charset="utf-8"><script>var a="</head>";</script><link rel="preconnect" href="https://a"></head><body><p>x</p><div id="end"></div></body></html>',
			$doc->html()
		);
	}

	public function test_replace_elements(): void {
		$doc = new HtmlDocument( '<html><head></head><body><iframe src="https://www.youtube.com/embed/abc" width="560"></iframe></body></html>' );
		$doc->replace_elements(
			'iframe',
			static function ( Tag $open, string $inner ) {
				return '<div class="facade" data-src="' . esc_attr( (string) $open->get( 'src' ) ) . '"></div>';
			}
		);
		$this->assertStringContainsString( '<div class="facade" data-src="https://www.youtube.com/embed/abc"></div>', $doc->html() );
	}
}
