<?php
/**
 * Tests for the script dependency analysis used by "defer".
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Assets;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\ScriptGraph;

final class ScriptGraphTest extends TestCase {

	/**
	 * WordPress data for a handle.
	 *
	 * @param string[] $deps  Dependencies.
	 * @param array    $extra Overrides.
	 */
	private static function script( array $deps = array(), array $extra = array() ): array {
		return array_merge(
			array(
				'src'               => '/x.js',
				'deps'              => $deps,
				'in_head'           => true,
				'has_inline_after'  => false,
				'has_inline_before' => false,
				'strategy'          => '',
				'module'            => false,
			),
			$extra
		);
	}

	/**
	 * Graph from page body markup.
	 *
	 * @param array  $scripts WordPress data.
	 * @param string $body    Script markup.
	 * @param array  $options Options.
	 */
	private static function graph( array $scripts, string $body, array $options = array() ): ScriptGraph {
		$doc = new HtmlDocument( '<!DOCTYPE html><html><head><title>t</title></head><body>' . $body . '</body></html>' );
		return new ScriptGraph( $scripts, ScriptGraph::tags_from_document( $doc ), $options );
	}

	/**
	 * Script tag markup for a handle.
	 *
	 * @param string $handle Handle.
	 * @param string $attrs  Extra attributes.
	 */
	private static function tag( string $handle, string $attrs = '' ): string {
		return '<script src="/wp-content/plugins/p/' . $handle . '.js?ver=1" id="' . $handle . '-js"' . $attrs . '></script>';
	}

	public function test_independent_script_is_deferrable(): void {
		$graph = self::graph( array( 'slider' => self::script() ), self::tag( 'slider' ) );
		$this->assertSame( array( 'slider' ), $graph->deferrable() );
		$this->assertSame( array( 0 ), $graph->deferrable_tags() );
	}

	public function test_inline_after_script_prevents_defer(): void {
		$body  = self::tag( 'a' ) . '<script id="a-js-after">window.aReady = true;</script>';
		$graph = self::graph( array( 'a' => self::script() ), $body );
		$this->assertSame( array(), $graph->deferrable() );

		$graph = self::graph( array( 'a' => self::script( array(), array( 'has_inline_after' => true ) ) ), self::tag( 'a' ) );
		$this->assertSame( array(), $graph->deferrable(), 'WordPress data alone is enough.' );
	}

	public function test_dependents_must_be_deferred_too(): void {
		$scripts = array(
			'lib' => self::script(),
			'app' => self::script( array( 'lib' ) ),
		);
		$this->assertSame( array( 'lib', 'app' ), self::graph( $scripts, self::tag( 'lib' ) . self::tag( 'app' ) )->deferrable() );

		// The dependent has an inline after script: it stays blocking, so its dependency must too.
		$body = self::tag( 'lib' ) . self::tag( 'app' ) . '<script id="app-js-after">init();</script>';
		$this->assertSame( array(), self::graph( $scripts, $body )->deferrable() );
	}

	public function test_fixed_point_over_a_chain(): void {
		$scripts = array(
			'a' => self::script(),
			'b' => self::script( array( 'a' ) ),
			'c' => self::script( array( 'b' ), array( 'has_inline_after' => true ) ),
			'd' => self::script(),
		);
		$body    = self::tag( 'a' ) . self::tag( 'b' ) . self::tag( 'd' ) . self::tag( 'c' );
		$this->assertSame( array( 'd' ), self::graph( $scripts, $body )->deferrable() );
	}

	public function test_dependents_through_alias_handles(): void {
		$scripts = array(
			'core'  => self::script(),
			'alias' => self::script( array( 'core' ), array( 'src' => '' ) ),
			'user'  => self::script( array( 'alias' ), array( 'has_inline_after' => true ) ),
		);
		$body    = self::tag( 'core' ) . self::tag( 'user' );
		$this->assertSame( array(), self::graph( $scripts, $body )->deferrable() );
	}

	public function test_excluded_dependent_blocks_its_dependencies(): void {
		$scripts = array(
			'lib' => self::script(),
			'app' => self::script( array( 'lib' ) ),
		);
		$options = array(
			'is_excluded' => static function ( string $handle ): bool {
				return 'app' === $handle;
			},
		);
		$this->assertSame( array(), self::graph( $scripts, self::tag( 'lib' ) . self::tag( 'app' ), $options )->deferrable() );
	}

	public function test_already_deferred_dependent_allows_defer(): void {
		$scripts = array(
			'lib' => self::script(),
			'app' => self::script( array( 'lib' ), array( 'strategy' => 'defer' ) ),
		);
		$graph   = self::graph( $scripts, self::tag( 'lib' ) . self::tag( 'app', ' defer' ) );
		$this->assertSame( array( 'lib' ), $graph->deferrable(), 'Already deferred scripts are left alone.' );
	}

	public function test_async_dependent_blocks_defer_and_async_module_are_left_alone(): void {
		$scripts = array(
			'lib' => self::script(),
			'app' => self::script( array( 'lib' ) ),
			'mod' => self::script(),
		);
		$body    = self::tag( 'lib' ) . self::tag( 'app', ' async' ) . self::tag( 'mod', ' type="module"' );
		$this->assertSame( array(), self::graph( $scripts, $body )->deferrable() );
	}

	public function test_jquery_rule_inline_reference_blocks_jquery(): void {
		$scripts = array(
			'jquery-core'    => self::script(),
			'jquery-migrate' => self::script(),
			'jquery'         => self::script( array( 'jquery-core', 'jquery-migrate' ), array( 'src' => '' ) ),
		);
		$tags    = self::tag( 'jquery-core' ) . self::tag( 'jquery-migrate' );

		$this->assertSame( array( 'jquery-core', 'jquery-migrate' ), self::graph( $scripts, $tags )->deferrable(), 'Without inline usage jQuery may be deferred.' );

		foreach ( array( 'jQuery(function(){});', '$( ".x" ).hide();', 'window.jQuery.fn.x = 1;', '$.ajax({});' ) as $inline ) {
			$graph = self::graph( $scripts, $tags . '<script>' . $inline . '</script>' );
			$this->assertSame( array(), $graph->deferrable(), $inline );
		}

		// Template literal "${" and "$foo" are not jQuery.
		$graph = self::graph( $scripts, $tags . '<script>var t = `${ a }`; var $foo = 1;</script>' );
		$this->assertSame( array( 'jquery-core', 'jquery-migrate' ), $graph->deferrable() );
	}

	public function test_inline_before_the_script_does_not_block(): void {
		$scripts = array( 'jquery-core' => self::script() );
		$body    = '<script>document.addEventListener("DOMContentLoaded",function(){jQuery(".a")});</script>' . self::tag( 'jquery-core' );
		$this->assertSame( array( 'jquery-core' ), self::graph( $scripts, $body )->deferrable() );
	}

	public function test_non_executable_inline_scripts_are_ignored(): void {
		$scripts = array( 'jquery-core' => self::script() );
		$body    = self::tag( 'jquery-core' ) . '<script type="application/ld+json">{"name":"jQuery($)"}</script><script type="text/template"><% $(x) %></script>';
		$this->assertSame( array( 'jquery-core' ), self::graph( $scripts, $body )->deferrable() );
	}

	public function test_global_defining_handle_needs_every_later_script_deferred(): void {
		$scripts = array(
			'jquery-core' => self::script(),
			'sloppy'      => self::script( array(), array( 'has_inline_after' => true ) ), // Uses jQuery without declaring it.
		);
		$body    = self::tag( 'jquery-core' ) . self::tag( 'sloppy' );
		$this->assertSame( array(), self::graph( $scripts, $body )->deferrable() );
	}

	public function test_jquery_plugins_inherit_jquery_globals(): void {
		$scripts = array(
			'jquery-core' => self::script(),
			'slick'       => self::script( array( 'jquery-core' ) ),
		);
		$body    = self::tag( 'jquery-core' ) . self::tag( 'slick' ) . '<script>jQuery(".s").slick();</script>';
		$this->assertSame( array(), self::graph( $scripts, $body )->deferrable() );
	}

	public function test_wp_packages_and_inline_wp_references(): void {
		$scripts = array(
			'wp-hooks' => self::script(),
			'wp-i18n'  => self::script( array( 'wp-hooks' ) ),
		);
		$tags    = self::tag( 'wp-hooks' ) . self::tag( 'wp-i18n' );
		$this->assertSame( array( 'wp-hooks', 'wp-i18n' ), self::graph( $scripts, $tags )->deferrable() );
		$body = $tags . '<script id="x-js-translations">wp.i18n.setLocaleData( {}, "x" );</script>';
		$this->assertSame( array(), self::graph( $scripts, $body )->deferrable() );
	}

	public function test_inline_globals_rule(): void {
		$scripts = array( 'maps-lib' => self::script() );
		$body    = self::tag( 'maps-lib' ) . '<script>MyMaps.init();</script>';
		$this->assertSame( array( 'maps-lib' ), self::graph( $scripts, $body )->deferrable() );
		$graph = self::graph( $scripts, $body, array( 'inline_globals' => array( 'maps-lib' => array( 'MyMaps' ) ) ) );
		$this->assertSame( array(), $graph->deferrable() );
	}

	public function test_name_hints_block_likely_global_usage(): void {
		$scripts = array( 'swiper' => self::script() );
		$body    = self::tag( 'swiper' ) . '<script>new Swiper(".s", {});</script>';
		$this->assertSame( array(), self::graph( $scripts, $body )->deferrable() );
		$this->assertSame( array( 'swiper' ), ScriptGraph::name_hints( 'swiper', '/wp-content/plugins/s/swiper-bundle.min.js' ) );
		$this->assertSame( array( 'slick' ), ScriptGraph::name_hints( 'jquery-slick', '/js/slick.min.js?ver=1' ) );
	}

	public function test_hard_coded_local_script_blocks_earlier_candidates(): void {
		$scripts = array( 'a' => self::script() );
		$body    = self::tag( 'a' ) . '<script src="/wp-content/themes/t/custom.js"></script>';
		$this->assertSame( array(), self::graph( $scripts, $body )->deferrable() );

		$body = self::tag( 'a' ) . '<script src="https://cdn.other.test/lib.js" async></script>';
		$this->assertSame( array( 'a' ), self::graph( $scripts, $body )->deferrable() );
	}

	public function test_unknown_scripts_only_deferred_when_catalog_says_defer_safe(): void {
		$body  = '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-1"></script><script src="https://cdn.example.org/unknown.js"></script>';
		$graph = self::graph( array(), $body );
		$this->assertSame( array( 0 ), $graph->deferrable_tags() );
		$this->assertSame( array(), $graph->deferrable(), 'No handles involved.' );

		// A later inline script using the library directly keeps it blocking.
		$body  = '<script src="https://www.google-analytics.com/analytics.js"></script><script>ga("create","UA-1","auto");</script>';
		$this->assertSame( array(), self::graph( array(), $body )->deferrable_tags() );
	}

	public function test_scripts_without_wordpress_data_are_not_deferred(): void {
		$graph = self::graph( array(), self::tag( 'unknown' ) );
		$this->assertSame( array(), $graph->deferrable_tags() );
	}

	public function test_duplicates_are_reported(): void {
		$body  = '<script src="https://example.test/lib.js?ver=1" id="lib-js"></script>'
			. '<script src="//example.test/lib.js?ver=2"></script>'
			. '<script src="/other.js"></script>'
			. '<script src="https://EXAMPLE.test/lib.js"></script>';
		$dupes = self::graph( array(), $body )->duplicates();
		$this->assertCount( 1, $dupes );
		$this->assertSame( '//example.test/lib.js', array_key_first( $dupes ) );
		$this->assertCount( 3, reset( $dupes ) );
	}

	public function test_capture_from_wp_scripts_like_object(): void {
		$wp_scripts             = new \stdClass();
		$wp_scripts->registered = array(
			'jquery-core' => (object) array(
				'src'   => '/wp-includes/js/jquery/jquery.min.js',
				'deps'  => array(),
				'extra' => array(),
			),
			'app'         => (object) array(
				'src'   => '/app.js',
				'deps'  => array( 'jquery-core' ),
				'extra' => array(
					'after'    => array( false, 'init();' ),
					'group'    => 1,
					'strategy' => 'defer',
				),
			),
		);
		$wp_scripts->done       = array( 'jquery-core', 'app' );
		$wp_scripts->groups     = array( 'app' => 1 );

		$data = ScriptGraph::capture( $wp_scripts );
		$this->assertTrue( $data['jquery-core']['in_head'] );
		$this->assertFalse( $data['app']['in_head'] );
		$this->assertTrue( $data['app']['has_inline_after'] );
		$this->assertFalse( $data['jquery-core']['has_inline_after'] );
		$this->assertSame( 'defer', $data['app']['strategy'] );
		$this->assertSame( array( 'jquery-core' ), $data['app']['deps'] );
		$this->assertSame( array(), ScriptGraph::capture( null ) );
	}

	public function test_references_global(): void {
		$this->assertTrue( ScriptGraph::references_global( 'jQuery(document)', 'jQuery' ) );
		$this->assertFalse( ScriptGraph::references_global( 'myjQuery(document)', 'jQuery' ) );
		$this->assertTrue( ScriptGraph::references_global( '_.each(a)', '_' ) );
		$this->assertFalse( ScriptGraph::references_global( 'a_.each(a)', '_' ) );
		$this->assertTrue( ScriptGraph::references_global( 'wp.hooks.addAction()', 'wp' ) );
		$this->assertFalse( ScriptGraph::references_global( 'swp.x', 'wp' ) );
	}
}
