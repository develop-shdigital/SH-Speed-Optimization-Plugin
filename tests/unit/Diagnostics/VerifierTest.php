<?php
/**
 * Server-side verification tests.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Diagnostics;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Diagnostics\Verifier;

final class VerifierTest extends TestCase {

	private function page( string $body, int $status = 200 ): array {
		return Verifier::snapshot(
			array(
				'ok'      => true,
				'status'  => $status,
				'body'    => $body,
				'time_ms' => 100,
				'ttfb_ms' => 50,
			)
		);
	}

	private function html( string $inner ): string {
		return '<!doctype html><html><head><title>T</title></head><body>' . $inner . str_repeat( '<p>filler content</p>', 200 ) . '</body></html>';
	}

	public function test_markers_ignore_comments_scripts_and_hidden_inputs(): void {
		$markers = Verifier::markers( '<form><input type="text"><input type="hidden" name="x"><button>Go</button></form><!-- <form> --><script>"<form>"</script><nav></nav><h1>x</h1>' );
		$this->assertSame( 1, $markers['forms'] );
		$this->assertSame( 1, $markers['inputs'] );
		$this->assertSame( 1, $markers['buttons'] );
		$this->assertSame( 1, $markers['nav'] );
		$this->assertSame( 1, $markers['h1'] );
	}

	public function test_identical_pages_pass(): void {
		$page   = $this->page( $this->html( '<form><input name="a"></form><a href="/x">x</a>' ) );
		$result = Verifier::compare( $page, $page );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array(), $result['failures'] );
	}

	public function test_missing_form_fails(): void {
		$baseline  = $this->page( $this->html( '<form><input name="a"><button>Send</button></form>' ) );
		$candidate = $this->page( $this->html( '' ) );
		$result    = Verifier::compare( $baseline, $candidate );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'forms', implode( ' ', $result['failures'] ) );
	}

	public function test_server_error_and_fatal_fail(): void {
		$baseline = $this->page( $this->html( '<p>ok</p>' ) );

		$error = Verifier::compare( $baseline, $this->page( 'oops', 500 ) );
		$this->assertFalse( $error['ok'] );

		$fatal = Verifier::compare( $baseline, $this->page( $this->html( '<b>Fatal error</b>: Uncaught Error' ) ) );
		$this->assertFalse( $fatal['ok'] );
	}

	public function test_incomplete_page_fails(): void {
		$baseline  = $this->page( $this->html( '<p>ok</p>' ) );
		$candidate = $this->page( substr( $this->html( '<p>ok</p>' ), 0, -20 ) );
		$this->assertFalse( Verifier::compare( $baseline, $candidate )['ok'] );
	}

	public function test_iframe_replaced_by_facade_is_allowed_only_when_expected(): void {
		$baseline  = $this->page( $this->html( '<iframe src="https://www.youtube.com/embed/abc"></iframe>' ) );
		$candidate = $this->page( $this->html( '<div class="shso-facade shso-facade--video"><button>Play</button><img src="t.jpg"></div>' ) );

		// Buttons increased, iframe removed: allowed with a facade when expected.
		$this->assertTrue( Verifier::compare( $baseline, $candidate, array( 'iframes' ) )['ok'] );

		// Iframe removed without a facade: not allowed even when expected.
		$gone = $this->page( $this->html( '' ) );
		$this->assertFalse( Verifier::compare( $baseline, $gone, array( 'iframes' ) )['ok'] );
	}

	public function test_small_link_variation_is_tolerated(): void {
		$links_a  = str_repeat( '<a href="/p">p</a>', 50 );
		$links_b  = str_repeat( '<a href="/p">p</a>', 48 );
		$result   = Verifier::compare( $this->page( $this->html( $links_a ) ), $this->page( $this->html( $links_b ) ) );
		$this->assertTrue( $result['ok'] );

		$links_c = str_repeat( '<a href="/p">p</a>', 30 );
		$this->assertFalse( Verifier::compare( $this->page( $this->html( $links_a ) ), $this->page( $this->html( $links_c ) ) )['ok'] );
	}

	public function test_slow_candidate_is_only_a_warning(): void {
		$baseline            = $this->page( $this->html( '<p>x</p>' ) );
		$candidate           = $baseline;
		$candidate['time_ms'] = 5000;
		$result              = Verifier::compare( $baseline, $candidate );
		$this->assertTrue( $result['ok'] );
		$this->assertNotEmpty( $result['warnings'] );
	}
}
