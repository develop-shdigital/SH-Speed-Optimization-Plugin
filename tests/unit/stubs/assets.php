<?php
/**
 * WordPress stubs for the CSS/JS optimization tests.
 *
 * Loaded before wordpress.php (alphabetical order): never define a function
 * that wordpress.php defines. Every stub is guarded.
 *
 * @package SH\SpeedOptimizer\Tests
 */

// phpcs:ignoreFile

if ( ! isset( $GLOBALS['shso_test_cron'] ) ) {
	$GLOBALS['shso_test_cron'] = array();
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) {
		foreach ( $GLOBALS['shso_test_cron'] as $event ) {
			if ( $event['hook'] === $hook && $event['args'] === $args ) {
				return $event['time'];
			}
		}
		return false;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
		$GLOBALS['shso_test_cron'][] = array(
			'time' => $timestamp,
			'hook' => $hook,
			'args' => $args,
		);
		return true;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook, $args = array(), $wp_error = false ) {
		$GLOBALS['shso_test_cron'] = array_values(
			array_filter(
				$GLOBALS['shso_test_cron'],
				static function ( $event ) use ( $hook ) {
					return $event['hook'] !== $hook;
				}
			)
		);
		return 0;
	}
}

if ( ! function_exists( 'shso_test_asset_file' ) ) {
	/**
	 * Create a file below the test WordPress root and return its path.
	 *
	 * @param string $relative Path relative to ABSPATH.
	 * @param string $contents Contents.
	 */
	function shso_test_asset_file( string $relative, string $contents ): string {
		$path = ABSPATH . ltrim( $relative, '/' );
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, $contents );
		return $path;
	}
}
