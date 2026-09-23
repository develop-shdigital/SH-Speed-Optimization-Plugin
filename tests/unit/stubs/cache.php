<?php
/**
 * WordPress function stubs used by the caching subsystem tests.
 *
 * This file is loaded before wordpress.php (alphabetical order), so it must
 * never define a function that wordpress.php defines unconditionally, and it
 * only stubs what no other stub file provides (it would shadow them).
 *
 * @package SH\SpeedOptimizer\Tests
 */

// phpcs:ignoreFile

if ( ! function_exists( 'wp_is_writable' ) ) {
	function wp_is_writable( $path ) {
		return is_writable( $path );
	}
}

if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	function wp_using_ext_object_cache( $using = null ) {
		return false;
	}
}

