<?php
/**
 * Tests for preconnect hints.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Preload;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Modules\Preload\PreconnectHints;

final class PreconnectHintsTest extends TestCase {

	private function page( string $head, string $body = '' ): HtmlDocument {
		return new HtmlDocument( '<!DOCTYPE html><html><head><meta charset="utf-8"><title>t</title>' . $head . '</head><body>' . $body . '</body></html>' );
	}

	public function test_google_fonts_and_two_blocking_origins_at_most_three(): void {
		$head = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter&display=swap">'
			. '<link rel="stylesheet" href="https://cdn.one.test/a.css">'
			. '<script src="https://cdn.two.test/b.js"></script>'
			. '<script src="https://cdn.three.test/c.js"></script>'
			. '<link rel="stylesheet" href="https://example.test/wp-content/themes/t/style.css">';

		$doc   = $this->page( $head );
		$added = ( new PreconnectHints( array( 'example.test' ) ) )->transform( $doc );
		$html  = $doc->html();

		$this->assertSame( 3, $added );
		$this->assertStringContainsString( '<meta charset="utf-8"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://cdn.one.test">', $html );
		$this->assertStringNotContainsString( 'href="https://cdn.two.test"', $html );
		$this->assertStringNotContainsString( 'preconnect" href="https://example.test', $html );
	}

	public function test_dedup_against_existing_hints_and_ignores_async_delayed_print_and_body_resources(): void {
		$head = "<link rel='dns-prefetch' href='//fonts.googleapis.com' />"
			. '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
			. '<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Lato">'
			. '<script async src="https://async.test/a.js"></script>'
			. '<script defer src="https://defer.test/a.js"></script>'
			. '<script type="shso/delay" src="https://delayed.test/a.js"></script>'
			. '<script type="module" src="https://module.test/a.js"></script>'
			. '<link rel="stylesheet" media="print" href="https://print.test/p.css">'
			. '<script crossorigin="anonymous" src="https://cors.test/lib.js"></script>';
		$body = '<script src="https://footer.test/f.js"></script>';

		$plan = ( new PreconnectHints( array( 'example.test' ) ) )->plan( $this->page( $head, $body ) );

		$this->assertSame(
			array(
				array(
					'origin'      => 'https://cors.test',
					'crossorigin' => true,
				),
			),
			$plan
		);
	}

	public function test_google_fonts_import_in_inline_style_and_nothing_to_do(): void {
		$doc  = $this->page( '<style>@import url("https://fonts.googleapis.com/css?family=Roboto");</style>' );
		$plan = ( new PreconnectHints( array( 'example.test' ) ) )->plan( $doc );
		$this->assertSame( 'https://fonts.gstatic.com', $plan[0]['origin'] );

		$doc    = $this->page( '<link rel="stylesheet" href="/wp-content/themes/t/style.css">' );
		$before = $doc->html();
		$this->assertSame( 0, ( new PreconnectHints( array( 'example.test' ) ) )->transform( $doc ) );
		$this->assertSame( $before, $doc->html() );
	}
}
