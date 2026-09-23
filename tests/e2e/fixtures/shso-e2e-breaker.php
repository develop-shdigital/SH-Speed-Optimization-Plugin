<?php
/**
 * Plugin Name: SH Speed Optimizer E2E breaker (test fixture)
 * Description: Deliberately fragile script setup used by the E2E suite to prove that unsafe optimizations are detected and rolled back. Only active while the option shso_e2e_breaker is truthy.
 *
 * A library is enqueued in <head> and used by an inline script in the footer
 * without declaring the dependency. Deferring the library makes the inline
 * script run first and throw — exactly the kind of breakage the browser
 * verification must catch.
 *
 * @package SH\SpeedOptimizer\Tests
 */

add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! get_option( 'shso_e2e_breaker' ) ) {
			return;
		}
		wp_enqueue_script( 'shso-e2e-lib', content_url( 'mu-plugins/shso-e2e-lib.js' ), array(), '1', false );
	}
);

add_action(
	'wp_footer',
	static function () {
		if ( get_option( 'shso_e2e_breaker' ) ) {
			echo '<script>window.ShsoE2eLib.init();</script>';
		}
	},
	5
);
