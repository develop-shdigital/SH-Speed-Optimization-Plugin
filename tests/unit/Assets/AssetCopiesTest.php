<?php
/**
 * Tests for optimized asset copies (naming, pipeline, queue, budget, GC) and the tag rewriter.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Assets;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\AssetCopies;
use SH\SpeedOptimizer\Assets\AssetRewriter;
use SH\SpeedOptimizer\Assets\AssetSource;
use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Core\Filesystem;

final class AssetCopiesTest extends TestCase {

	private Filesystem $fs;

	protected function setUp(): void {
		shso_test_reset();
		$GLOBALS['shso_test_cron'] = array();
		AssetSource::reset();
		AssetRewriter::reset();
		$this->fs = new Filesystem();
		$this->fs->delete_tree( Filesystem::cache_root() . 'assets', true );

		shso_test_asset_file( 'wp-content/plugins/demo/css/style.css', "/* Demo */\n.a {\n  background: url( ../img/a.png );\n  color : red;\n}\n\n.b { margin: 0 auto; }\n/*# sourceMappingURL=style.css.map */\n" );
		shso_test_asset_file( 'wp-content/plugins/demo/js/app.js', "// App\nfunction hello( name ) {\n\treturn 'Hello ' + name;\n}\n\nwindow.hello = hello;\n//# sourceMappingURL=app.js.map\n" );
		shso_test_asset_file( 'wp-content/plugins/demo/js/chunks.js', "var s = document.currentScript.src;\nload( s.replace( /[^\\/]+$/, 'chunk.js' ) );\n" );
		shso_test_asset_file( 'wp-content/plugins/demo/css/min.css', str_repeat( '.x{color:red}', 60 ) );
		shso_test_asset_file( 'wp-content/plugins/demo/css/a.css', "a {\n  color: red;\n}\n" );
		shso_test_asset_file( 'wp-content/plugins/demo/css/b.css', "b {\n  color: blue;\n}\n" );
		shso_test_asset_file( 'wp-content/plugins/demo/css/c.css', "c {\n  color: green;\n}\n" );
		shso_test_asset_file( 'wp-content/plugins/demo/css/big.css', str_repeat( ".big-selector-name {\n  color: red;\n}\n", 4000 ) );
	}

	/**
	 * Copies instance with optional transforms.
	 *
	 * @param array<string,callable> $css CSS transforms.
	 * @param array                  $limits Limits.
	 */
	private function copies( array $css = array(), array $limits = array() ): AssetCopies {
		return new AssetCopies(
			$this->fs,
			static function ( string $type ) use ( $css ): array {
				return 'css' === $type ? $css : array();
			},
			null,
			$limits
		);
	}

	public function test_copy_name_is_deterministic_and_versioned(): void {
		$source = AssetSource::resolve( 'https://example.test/wp-content/plugins/demo/css/style.css' );
		$name   = AssetCopies::copy_name( $source, 'css', array( 'css_minify' ) );

		$this->assertMatchesRegularExpression( '/^demo-style-[a-f0-9]{10}\.min\.css$/', $name );
		$this->assertSame( $name, AssetCopies::copy_name( $source, 'css', array( 'css_minify', 'css_minify' ) ) );
		$this->assertSame(
			AssetCopies::copy_name( $source, 'css', array( 'font_display_swap', 'css_minify' ) ),
			AssetCopies::copy_name( $source, 'css', array( 'css_minify', 'font_display_swap' ) ),
			'Transform order does not matter.'
		);
		$this->assertNotSame( $name, AssetCopies::copy_name( $source, 'css', array( 'css_minify', 'font_display_swap' ) ), 'Another transform set gives another file.' );

		$changed          = $source;
		$changed['mtime'] = $source['mtime'] + 10;
		$this->assertNotSame( $name, AssetCopies::copy_name( $changed, 'css', array( 'css_minify' ) ), 'A changed original gives a new file.' );
	}

	public function test_css_pipeline_rewrites_urls_minifies_and_strips_source_map(): void {
		$result = AssetCopies::process(
			(string) file_get_contents( ABSPATH . 'wp-content/plugins/demo/css/style.css' ),
			'https://example.test/wp-content/plugins/demo/css/style.css',
			'css',
			array( 'css_minify' ),
			array(),
			true
		);
		$this->assertSame( 'ok', $result['status'] );
		$this->assertSame( '.a{background:url(/wp-content/plugins/demo/img/a.png);color:red}.b{margin:0 auto}', trim( $result['output'] ) );
	}

	public function test_css_transforms_run_before_minify_in_priority_order(): void {
		$calls      = array();
		$transforms = array(
			'first'  => static function ( string $css, string $url ) use ( &$calls ) {
				$calls[] = 'first:' . basename( $url );
				return $css . "\n.first { x: y }";
			},
			'second' => static function ( string $css ) use ( &$calls ) {
				$calls[] = 'second';
				return str_replace( 'color : red', 'color : blue', $css );
			},
		);
		$result     = AssetCopies::process( "a {\n  color : red;\n}\n", 'https://example.test/x/s.css', 'css', array( 'css_minify', 'second', 'first' ), $transforms );
		$this->assertSame( 'ok', $result['status'] );
		$this->assertSame( array( 'first:s.css', 'second' ), $calls );
		$this->assertSame( 'a{color:blue}.first{x:y}', $result['output'] );
	}

	public function test_js_pipeline(): void {
		$result = AssetCopies::process( (string) file_get_contents( ABSPATH . 'wp-content/plugins/demo/js/app.js' ), 'https://example.test/app.js', 'js', array( 'js_minify' ), array() );
		$this->assertSame( 'ok', $result['status'] );
		$this->assertSame( "function hello(name){\nreturn'Hello '+name;\n}\nwindow.hello=hello;\n", $result['output'] );
		$this->assertStringNotContainsString( 'sourceMappingURL', $result['output'] );
	}

	public function test_pipeline_refuses_unsafe_or_useless_results(): void {
		$css = "a {\n  color: red;\n}\n";

		$this->assertSame( 'already_minified', AssetCopies::process( str_repeat( '.x{color:red}', 60 ), 'https://example.test/m.css', 'css', array( 'css_minify' ), array() )['reason'] );
		$this->assertSame( 'location_sensitive', AssetCopies::process( "var s = document.currentScript;\nfoo( s );\n", 'https://example.test/a.js', 'js', array( 'js_minify' ), array() )['reason'] );
		$this->assertSame( 'transform_unavailable:missing', AssetCopies::process( $css, 'https://example.test/a.css', 'css', array( 'css_minify', 'missing' ), array() )['reason'] );

		$tiny = array(
			't' => static function () {
				return 'a';
			},
		);
		$this->assertSame( 'suspicious_size', AssetCopies::process( $css, 'https://example.test/a.css', 'css', array( 't' ), $tiny )['reason'] );

		$empty = array(
			't' => static function () {
				return '   ';
			},
		);
		$this->assertSame( 'empty_output', AssetCopies::process( $css, 'https://example.test/a.css', 'css', array( 't' ), $empty )['reason'] );

		$bad = array(
			't' => static function () {
				return null;
			},
		);
		$this->assertSame( 'failed', AssetCopies::process( $css, 'https://example.test/a.css', 'css', array( 't' ), $bad )['status'] );
		$this->assertSame( 'unparsable', AssetCopies::process( "a{background:url(x.png)}\n/* open", 'https://example.test/a.css', 'css', array( 'css_minify' ), array() )['reason'] );
	}

	public function test_frontend_generates_small_files_synchronously_within_budget(): void {
		$copies = $this->copies();
		$ids    = array( 'css_minify' );

		$first = $copies->copy_url( 'https://example.test/wp-content/plugins/demo/css/a.css?ver=1', 'css', $ids );
		$this->assertNotNull( $first );
		$this->assertMatchesRegularExpression( '#^https://example\.test/wp-content/cache/sh-speed-optimizer/assets/demo-a-[a-f0-9]{10}\.min\.css$#', $first );
		$this->assertSame( 'a{color:red}', file_get_contents( $copies->dir() . basename( $first ) ) );

		$this->assertNotNull( $copies->copy_url( '/wp-content/plugins/demo/css/b.css', 'css', $ids ) );
		$this->assertNull( $copies->copy_url( '/wp-content/plugins/demo/css/c.css', 'css', $ids ), 'Third generation goes to the queue.' );
		$this->assertNull( $copies->copy_url( '/wp-content/plugins/demo/css/big.css', 'css', $ids ), 'Large files are never generated during a page view.' );

		$this->assertSame( array(), get_option( AssetCopies::QUEUE_OPTION, array() ), 'Queue persisted only on flush.' );
		$copies->flush_queue();
		$queue = get_option( AssetCopies::QUEUE_OPTION );
		$this->assertCount( 2, $queue );
		$this->assertSame( AssetCopies::CRON_HOOK, $GLOBALS['shso_test_cron'][0]['hook'] );

		// Existing copies are found without generating (also for root-relative references).
		$again = $this->copies();
		$this->assertSame( '/wp-content/cache/sh-speed-optimizer/assets/' . basename( $first ), $again->copy_url( '/wp-content/plugins/demo/css/a.css', 'css', $ids, 'lookup' ) );
		$this->assertSame( '//example.test/wp-content/cache/sh-speed-optimizer/assets/' . basename( $first ), $again->copy_url( '//example.test/wp-content/plugins/demo/css/a.css', 'css', $ids, 'lookup' ) );
	}

	public function test_queue_worker_processes_entries_and_dedupes(): void {
		$copies = $this->copies( array(), array( 'sync_max_count' => 0 ) );
		$ids    = array( 'css_minify' );
		$copies->copy_url( '/wp-content/plugins/demo/css/c.css', 'css', $ids );
		$copies->copy_url( '/wp-content/plugins/demo/css/c.css', 'css', $ids );
		$copies->copy_url( '/wp-content/plugins/demo/css/big.css', 'css', $ids );
		$copies->flush_queue();
		$this->assertSame( 2, $copies->queue_size() );

		// Another request queues the same entry again: no duplicate.
		$other = $this->copies( array(), array( 'sync_max_count' => 0 ) );
		$other->copy_url( '/wp-content/plugins/demo/css/c.css', 'css', $ids );
		$other->flush_queue();
		$this->assertSame( 2, $other->queue_size() );

		$this->assertSame( 2, $this->copies()->run_queue() );
		$this->assertSame( 0, $this->copies()->queue_size() );
		$this->assertNotNull( $this->copies()->copy_url( '/wp-content/plugins/demo/css/big.css', 'css', $ids, 'lookup' ) );
		$this->assertNotNull( $this->copies()->copy_url( '/wp-content/plugins/demo/css/c.css', 'css', $ids, 'lookup' ) );
	}

	public function test_failed_generation_serves_original_and_is_remembered(): void {
		$throws = array(
			'boom' => static function () {
				throw new \RuntimeException( 'broken transform' );
			},
		);
		$copies = $this->copies( $throws );
		$this->assertNull( $copies->copy_url( '/wp-content/plugins/demo/css/a.css', 'css', array( 'boom' ), 'force' ) );

		$source = AssetSource::resolve( '/wp-content/plugins/demo/css/a.css' );
		$name   = AssetCopies::copy_name( $source, 'css', array( 'boom' ) );
		$this->assertFileDoesNotExist( $copies->dir() . $name );
		$this->assertFileExists( $copies->dir() . $name . '.skip.txt' );
		$this->assertStringContainsString( 'broken transform', (string) file_get_contents( $copies->dir() . $name . '.skip.txt' ) );
		$this->assertNull( $copies->copy_url( '/wp-content/plugins/demo/css/a.css', 'css', array( 'boom' ) ), 'Not retried on every page view.' );
		$this->assertFileExists( ABSPATH . 'wp-content/plugins/demo/css/a.css', 'Originals are never touched.' );
	}

	public function test_already_minified_file_gets_a_skip_marker(): void {
		$copies = $this->copies();
		$this->assertNull( $copies->copy_url( '/wp-content/plugins/demo/css/min.css', 'css', array( 'css_minify' ), 'force' ) );
		$this->assertSame( 1, $copies->stats()['skipped'] );
	}

	public function test_location_sensitive_script_is_not_copied(): void {
		$this->assertNull( $this->copies()->copy_url( '/wp-content/plugins/demo/js/chunks.js', 'js', array( 'js_minify' ), 'force' ) );
	}

	public function test_type_mismatch_and_foreign_urls_are_ignored(): void {
		$copies = $this->copies();
		$this->assertNull( $copies->copy_url( '/wp-content/plugins/demo/js/app.js', 'css', array( 'css_minify' ), 'force' ) );
		$this->assertNull( $copies->copy_url( 'https://cdn.other.test/wp-content/plugins/demo/css/a.css', 'css', array( 'css_minify' ), 'force' ) );
		$this->assertNull( $copies->copy_url( '/wp-content/plugins/demo/css/a.css', 'css', array(), 'force' ) );
	}

	public function test_copies_of_copies_are_never_made(): void {
		$copies = $this->copies();
		$url    = $copies->copy_url( '/wp-content/plugins/demo/css/a.css', 'css', array( 'css_minify' ), 'force' );
		$this->assertNotNull( $url );
		$this->assertNull( $copies->copy_url( $url, 'css', array( 'css_minify' ), 'force' ) );
	}

	public function test_garbage_collection_removes_unused_copies_only(): void {
		$copies = $this->copies();
		$fresh  = $copies->copy_url( '/wp-content/plugins/demo/css/a.css', 'css', array( 'css_minify' ), 'force' );
		$old    = $copies->dir( true ) . 'demo-old-0123456789.min.css';
		file_put_contents( $old, 'a{}' );
		touch( $old, time() - 50 * DAY_IN_SECONDS );
		$marker = $copies->dir() . 'demo-old-0123456789.min.css.skip.txt';
		file_put_contents( $marker, 'skipped' );
		touch( $marker, time() - 50 * DAY_IN_SECONDS );
		$tmp = $copies->dir() . '.demo.min.css.abcd.tmp';
		file_put_contents( $tmp, 'x' );
		touch( $tmp, time() - 2 * DAY_IN_SECONDS );

		$this->assertSame( 3, $copies->garbage_collect() );
		$this->assertFileExists( $copies->dir() . basename( $fresh ) );
		$this->assertFileDoesNotExist( $old );
		$this->assertFileExists( $copies->dir() . 'index.html', 'Protection files stay.' );
	}

	public function test_used_copies_are_touched_so_they_survive_gc(): void {
		$copies = $this->copies();
		$url    = $copies->copy_url( '/wp-content/plugins/demo/css/a.css', 'css', array( 'css_minify' ), 'force' );
		$path   = $copies->dir() . basename( $url );
		touch( $path, time() - 40 * DAY_IN_SECONDS );
		clearstatcache();

		$copies->copy_url( '/wp-content/plugins/demo/css/a.css', 'css', array( 'css_minify' ), 'lookup' );
		clearstatcache();
		$this->assertGreaterThan( time() - 60, filemtime( $path ) );
		$this->assertSame( 0, $copies->garbage_collect() );
	}

	public function test_stats_and_clear_queue(): void {
		$copies = $this->copies( array(), array( 'sync_max_count' => 0 ) );
		$copies->copy_url( '/wp-content/plugins/demo/css/c.css', 'css', array( 'css_minify' ) );
		$copies->flush_queue();
		$this->copies()->copy_url( '/wp-content/plugins/demo/css/a.css', 'css', array( 'css_minify' ), 'force' );

		$stats = $this->copies()->stats();
		$this->assertSame( 1, $stats['css'] );
		$this->assertSame( 1, $stats['queued'] );
		$this->assertGreaterThan( 0, $stats['bytes'] );

		$this->copies()->clear_queue( 'js' );
		$this->assertSame( 1, $this->copies()->queue_size() );
		$this->copies()->clear_queue( 'css' );
		$this->assertSame( 0, $this->copies()->queue_size() );
	}

	// ---------------------------------------------------------------------
	// Tag rewriter.
	// ---------------------------------------------------------------------

	/**
	 * Rewriter with a fake resolver.
	 *
	 * @param callable|null $excluded Exclusion callback.
	 */
	private static function rewriter( ?callable $excluded = null ): AssetRewriter {
		return new AssetRewriter(
			static function ( string $url, string $type ): ?string {
				if ( false !== strpos( $url, 'nocopy' ) ) {
					return null;
				}
				return '/wp-content/cache/sh-speed-optimizer/assets/' . md5( $url ) . '.min.' . $type;
			},
			$excluded ?? static function (): bool {
				return false;
			}
		);
	}

	public function test_rewriter_rewrites_styles_and_keeps_attributes(): void {
		$doc = new HtmlDocument(
			'<html><head>'
			. '<link rel="stylesheet" id="demo-css" href="/wp-content/plugins/demo/css/a.css?ver=1" media="all" data-x="1">'
			. '<link rel="stylesheet" href="/wp-content/plugins/demo/css/b.css" integrity="sha384-abc" crossorigin="anonymous">'
			. '<link rel="stylesheet" id="skip-css" href="/wp-content/plugins/demo/css/c.css" data-no-optimize="1">'
			. '<link rel="preload" as="style" href="/wp-content/plugins/demo/css/d.css">'
			. '<link rel="stylesheet" href="/wp-content/plugins/demo/css/nocopy.css">'
			. '<link rel="alternate stylesheet" href="/alt.css">'
			. '<!-- <link rel="stylesheet" href="/commented.css"> -->'
			. '</head><body></body></html>'
		);

		$this->assertSame( 1, self::rewriter()->rewrite_styles( $doc ) );
		$html = $doc->html();
		$copy = '/wp-content/cache/sh-speed-optimizer/assets/' . md5( '/wp-content/plugins/demo/css/a.css?ver=1' ) . '.min.css';
		$this->assertStringContainsString( '<link rel="stylesheet" id="demo-css" href="' . $copy . '" media="all" data-x="1">', $html );
		$this->assertStringContainsString( 'href="/wp-content/plugins/demo/css/b.css" integrity="sha384-abc"', $html, 'Integrity-protected tags are skipped.' );
		$this->assertStringContainsString( '<!-- <link rel="stylesheet" href="/commented.css"> -->', $html );
		$this->assertSame( '/wp-content/plugins/demo/css/a.css?ver=1', AssetRewriter::original_url( $copy ) );
	}

	public function test_rewriter_respects_excluded_handles_and_urls(): void {
		$doc      = new HtmlDocument( '<html><head><link rel="stylesheet" id="elementor-frontend-css" href="/a.css"><link rel="stylesheet" href="/wp-content/themes/x/keep.css"></head><body></body></html>' );
		$excluded = static function ( string $type, string $handle, string $url ): bool {
			return 'elementor-frontend' === $handle || false !== strpos( $url, 'keep.css' );
		};
		$this->assertSame( 0, self::rewriter( $excluded )->rewrite_styles( $doc ) );
	}

	public function test_rewriter_rewrites_scripts_but_not_modules_or_integrity(): void {
		$doc = new HtmlDocument(
			'<html><head><script src="/wp-content/plugins/demo/js/app.js?ver=2" id="demo-js" defer></script>'
			. '<script type="module" src="/wp-content/plugins/demo/js/mod.js"></script>'
			. '<script src="/wp-content/plugins/demo/js/sri.js" integrity="sha256-x"></script>'
			. '<script type="text/template" src="/tpl.js"></script>'
			. '<script>var inline = "<script src=/x.js>";</script>'
			. '<script type="shso/delay" data-shso-src="/wp-content/plugins/demo/js/late.js" data-shso-delay="all"></script>'
			. '</head><body></body></html>'
		);
		$this->assertSame( 2, self::rewriter()->rewrite_scripts( $doc ) );
		$html = $doc->html();
		$this->assertStringContainsString( '<script src="/wp-content/cache/sh-speed-optimizer/assets/' . md5( '/wp-content/plugins/demo/js/app.js?ver=2' ) . '.min.js" id="demo-js" defer></script>', $html );
		$this->assertStringContainsString( '<script type="module" src="/wp-content/plugins/demo/js/mod.js"></script>', $html );
		$this->assertStringContainsString( 'integrity="sha256-x"', $html );
		$this->assertStringContainsString( 'data-shso-src="/wp-content/cache/sh-speed-optimizer/assets/', $html, 'Delayed placeholders are rewritten too.' );
	}

	public function test_rewriter_handle_helper(): void {
		$tag = \SH\SpeedOptimizer\Assets\Tag::parse( '<link id="wp-block-library-css" rel="stylesheet">' );
		$this->assertSame( 'wp-block-library', AssetRewriter::handle( $tag, 'css' ) );
		$this->assertSame( '', AssetRewriter::handle( $tag, 'js' ) );
	}
}
