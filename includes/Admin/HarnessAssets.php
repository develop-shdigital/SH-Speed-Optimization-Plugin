<?php
/**
 * Registers the browser test harness for the plugin's admin pages.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Harness script registration.
 */
final class HarnessAssets {

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'register_script' ), 1 );
	}

	/**
	 * Register the `shso-harness` handle (enqueued as a dependency of the admin app).
	 */
	public static function register_script(): void {
		wp_register_script(
			'shso-harness',
			plugins_url( 'assets/js/harness.js', SHSO_FILE ),
			array(),
			SHSO_VERSION,
			true
		);
		wp_add_inline_script(
			'shso-harness',
			'window.shsoHarnessConfig=' . wp_json_encode(
				array(
					'criticalCssUrl' => plugins_url( 'assets/js/critical-css.js', SHSO_FILE ) . '?ver=' . rawurlencode( SHSO_VERSION ),
				)
			) . ';',
			'before'
		);
	}
}
