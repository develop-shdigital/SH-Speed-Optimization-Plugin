<?php
/**
 * Records what WordPress printed (handles, dependencies, inline scripts) so
 * the HTML transformers, which run at the very end of the request, can reason
 * about script dependencies.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\JavascriptOptimization;

use SH\SpeedOptimizer\Assets\ScriptGraph;

defined( 'ABSPATH' ) || exit;

/**
 * Request-local recorder of WP_Scripts data.
 */
final class ScriptRecorder {

	/**
	 * Recorded data (ScriptGraph::capture() shape).
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static array $scripts = array();

	/**
	 * Whether the hooks were added.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Hook the recorder (once per request): after footer scripts were printed.
	 */
	public static function hook(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'wp_print_footer_scripts', array( self::class, 'capture' ), PHP_INT_MAX );
		add_action( 'wp_footer', array( self::class, 'capture' ), PHP_INT_MAX );
	}

	/**
	 * Capture the current state of the global WP_Scripts instance.
	 */
	public static function capture(): void {
		$wp_scripts = $GLOBALS['wp_scripts'] ?? null;
		if ( is_object( $wp_scripts ) ) {
			self::$scripts = ScriptGraph::capture( $wp_scripts );
		}
	}

	/**
	 * Recorded data.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function scripts(): array {
		return self::$scripts;
	}

	/**
	 * Reset (tests).
	 *
	 * @param array<string,array<string,mixed>> $scripts Data to use.
	 */
	public static function set( array $scripts ): void {
		self::$scripts = $scripts;
	}
}
