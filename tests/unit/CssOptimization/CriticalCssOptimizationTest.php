<?php
/**
 * Tests for critical CSS storage and the runtime document conversion.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\CssOptimization;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\AssetRewriter;
use SH\SpeedOptimizer\Assets\AssetSource;
use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Tag;
use SH\SpeedOptimizer\Compatibility\Rules;
use SH\SpeedOptimizer\Core\Plugin;
use SH\SpeedOptimizer\Core\Settings;
use SH\SpeedOptimizer\Detection\SiteProfile;
use SH\SpeedOptimizer\Modules\CssOptimization\CriticalCssOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Risk;

final class CriticalCssOptimizationTest extends TestCase {

	protected function setUp(): void {
		shso_test_reset();
		AssetRewriter::reset();
		AssetSource::reset();
		CriticalCssOptimization::flush_cache();
	}

	/**
	 * Convertible filter for tests: local + not excluded.
	 *
	 * @param string[] $excluded_handles Excluded handles.
	 */
	private static function convertible( array $excluded_handles = array() ): callable {
		return static function ( Tag $tag ) use ( $excluded_handles ): bool {
			return CriticalCssOptimization::is_convertible(
				$tag,
				static function ( string $handle ) use ( $excluded_handles ): bool {
					return in_array( $handle, $excluded_handles, true );
				},
				array( AssetSource::class, 'is_local_url' )
			);
		};
	}

	public function test_store_entries_remove_and_action(): void {
		$stored = array();
		add_action(
			'shso_critical_css_stored',
			static function ( $template, $entry ) use ( &$stored ) {
				$stored[] = array( $template, $entry );
			},
			10,
			2
		);

		CriticalCssOptimization::store(
			'Front_Page',
			'body{margin:0}.x::after{content:"</style><script>alert(1)</script>"}',
			150,
			array(
				'viewport_widths' => array( 1350, '412', -5 ),
				'source_hash'     => 'v1:abc<>',
				'generated_at'    => 1700000000,
			)
		);

		$entries = CriticalCssOptimization::entries();
		$this->assertArrayHasKey( 'front_page', $entries );
		$entry = $entries['front_page'];
		$this->assertSame( 100, $entry['confidence'] );
		$this->assertSame( array( 1350, 412 ), $entry['viewport_widths'] );
		$this->assertSame( 'v1:abc', $entry['source_hash'] );
		$this->assertSame( 1700000000, $entry['generated_at'] );
		$this->assertStringNotContainsString( '</style', $entry['css'] );
		$this->assertStringContainsString( '<\/style>', $entry['css'] );
		$this->assertCount( 1, $stored );
		$this->assertSame( 'front_page', $stored[0][0] );

		// Persisted in the non-autoloaded option and readable after a cache flush.
		CriticalCssOptimization::flush_cache();
		$this->assertArrayHasKey( 'front_page', CriticalCssOptimization::entries() );

		CriticalCssOptimization::remove( 'front_page' );
		$this->assertSame( array(), CriticalCssOptimization::entries() );
		$this->assertFalse( get_option( CriticalCssOptimization::OPTION ) );
	}

	public function test_store_rejects_empty_or_oversized_css(): void {
		CriticalCssOptimization::store( 'page', '   ', 95 );
		CriticalCssOptimization::store( 'page', str_repeat( 'a', CriticalCssOptimization::MAX_BYTES + 1 ), 95 );
		CriticalCssOptimization::store( '', 'a{}', 95 );
		$this->assertSame( array(), CriticalCssOptimization::entries() );
	}

	public function test_store_keeps_at_most_max_templates(): void {
		for ( $i = 0; $i < CriticalCssOptimization::MAX_TEMPLATES + 3; $i++ ) {
			CriticalCssOptimization::store( 'tpl-' . $i, 'a{}', 95, array( 'generated_at' => 1000 + $i ) );
		}
		$entries = CriticalCssOptimization::entries();
		$this->assertCount( CriticalCssOptimization::MAX_TEMPLATES, $entries );
		$this->assertArrayNotHasKey( 'tpl-0', $entries, 'Oldest entries are dropped.' );
		$this->assertArrayHasKey( 'tpl-' . ( CriticalCssOptimization::MAX_TEMPLATES + 2 ), $entries );
	}

	public function test_runtime_entry_requires_high_confidence(): void {
		CriticalCssOptimization::store( 'page', 'a{}', 89 );
		CriticalCssOptimization::store( 'single-post', 'a{}', 90 );
		$this->assertNull( CriticalCssOptimization::entry( 'page' ) );
		$this->assertNotNull( CriticalCssOptimization::entry( 'single-post' ) );
		$this->assertNull( CriticalCssOptimization::entry( 'archive' ) );
	}

	public function test_apply_converts_same_origin_blocking_links(): void {
		$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>t</title>'
			. '<link rel="stylesheet" id="theme-css" href="/wp-content/themes/t/style.css?ver=1" media="all">'
			. '<link rel="stylesheet" href="https://example.test/wp-content/plugins/p/p.css">'
			. '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter">'
			. '<link rel="stylesheet" href="/wp-content/themes/t/print.css" media="print">'
			. '<link rel="stylesheet" id="skip-css" href="/wp-content/plugins/skip/s.css">'
			. '<link rel="stylesheet" href="/sri.css" integrity="sha384-x">'
			. '<link rel="preload" as="font" href="/f.woff2" crossorigin>'
			. '</head><body><p>x</p><link rel="stylesheet" href="/wp-content/plugins/late/late.css"></body></html>';
		$doc  = new HtmlDocument( $html );

		$count = CriticalCssOptimization::apply_to_document( $doc, 'body{margin:0}', self::convertible( array( 'skip' ) ) );
		$out   = $doc->html();

		$this->assertSame( 2, $count );
		$this->assertStringContainsString( '<meta charset="utf-8"><style id="shso-critical-css">body{margin:0}</style><title>', $out );
		$this->assertStringContainsString(
			'<link rel="preload" id="theme-css" href="/wp-content/themes/t/style.css?ver=1" media="all" as="style" data-shso-async onload="this.onload=null;this.rel=&#039;stylesheet&#039;"><noscript><link rel="stylesheet" id="theme-css" href="/wp-content/themes/t/style.css?ver=1" media="all"></noscript>',
			$out
		);
		$this->assertStringContainsString( '<noscript><link rel="stylesheet" href="https://example.test/wp-content/plugins/p/p.css"></noscript>', $out );
		$this->assertStringContainsString( '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter">', $out, 'Cross-origin stays untouched.' );
		$this->assertStringContainsString( '<link rel="stylesheet" href="/wp-content/themes/t/print.css" media="print">', $out );
		$this->assertStringContainsString( '<link rel="stylesheet" id="skip-css" href="/wp-content/plugins/skip/s.css">', $out, 'Excluded handles stay.' );
		$this->assertStringContainsString( '<link rel="stylesheet" href="/sri.css" integrity="sha384-x">', $out );
		$this->assertStringContainsString( '<body><p>x</p><link rel="stylesheet" href="/wp-content/plugins/late/late.css">', $out, 'Body stylesheets stay.' );
		$this->assertStringContainsString( 'id="shso-critical-css-fallback"', $out );
		$this->assertLessThan( strpos( $out, '</body>' ), strpos( $out, 'shso-critical-css-fallback' ) );
	}

	public function test_apply_without_convertible_links_changes_nothing(): void {
		$html = '<!DOCTYPE html><html><head><link rel="stylesheet" href="https://cdn.other.test/a.css"></head><body></body></html>';
		$doc  = new HtmlDocument( $html );
		$this->assertSame( 0, CriticalCssOptimization::apply_to_document( $doc, 'a{}', self::convertible() ) );
		$this->assertSame( $html, $doc->html() );
		$this->assertSame( 0, CriticalCssOptimization::apply_to_document( new HtmlDocument( $html ), '  ', self::convertible() ) );
	}

	public function test_rewritten_copies_are_judged_by_their_original_url(): void {
		AssetRewriter::remember( '/wp-content/cache/sh-speed-optimizer/assets/skip-s-abc.min.css', 'https://cdn.other.test/s.css' );
		$tag = Tag::parse( '<link rel="stylesheet" href="/wp-content/cache/sh-speed-optimizer/assets/skip-s-abc.min.css">' );
		$this->assertFalse( self::convertible()( $tag ) );
	}

	public function test_source_hash(): void {
		$a = CriticalCssOptimization::source_hash( array( 'https://example.test/b.css?ver=1', '/a.css' ) );
		$b = CriticalCssOptimization::source_hash( array( '/a.css', '//example.test/b.css?ver=1', '/a.css' ) );
		$this->assertSame( $a, $b );
		$this->assertStringStartsWith( 'v1:', $a );
		$this->assertNotSame( $a, CriticalCssOptimization::source_hash( array( '/a.css', '/b.css?ver=2' ) ) );
	}

	public function test_rollback_deletes_the_option_and_metadata(): void {
		CriticalCssOptimization::store( 'page', 'a{}', 95 );
		$optimization = new CriticalCssOptimization( Plugin::instance() );
		$this->assertSame( 1, count( $optimization->details()['templates'] ) );
		$optimization->rollback();
		$this->assertFalse( get_option( CriticalCssOptimization::OPTION ) );
		$this->assertSame( array(), CriticalCssOptimization::entries() );

		$this->assertSame( 'critical_css', $optimization->id() );
		$this->assertSame( Risk::HIGH, $optimization->risk() );
		$this->assertSame( Risk::LEVEL_EXPERIMENTAL, $optimization->level() );
		$this->assertFalse( $optimization->default_enabled() );
		$this->assertContains( 'browser_verification', $optimization->requirements() );
	}

	public function test_assess(): void {
		$optimization = new CriticalCssOptimization( Plugin::instance() );
		$page         = array(
			'status' => 200,
			'styles' => array(
				array( 'href' => '/a.css', 'local' => true, 'in_head' => true, 'media' => 'all', 'bytes' => 60000 ),
				array( 'href' => '/b.css', 'local' => true, 'in_head' => true, 'media' => '', 'bytes' => 50000 ),
				array( 'href' => '/print.css', 'local' => true, 'in_head' => true, 'media' => 'print', 'bytes' => 50000 ),
				array( 'href' => 'https://fonts.googleapis.com/css', 'local' => false, 'in_head' => true, 'media' => 'all', 'bytes' => 1000 ),
			),
		);
		$context      = new AssessmentContext( new SiteProfile(), new Rules(), new Settings(), array( 'https://example.test/' => $page ) );
		$assessment   = $optimization->assess( $context );
		$this->assertTrue( $assessment->applicable );
		$this->assertSame( Assessment::BENEFIT_HIGH, $assessment->benefit );
		$this->assertSame( 2, $assessment->data['blocking_stylesheets'] );

		$empty = new AssessmentContext( new SiteProfile(), new Rules(), new Settings(), array( 'https://example.test/' => array( 'status' => 200 ) ) );
		$this->assertFalse( $optimization->assess( $empty )->applicable );
	}
}
