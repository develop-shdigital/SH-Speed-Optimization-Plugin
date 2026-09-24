<?php
/**
 * WordPress media/theme function stubs used by the image, font and facade tests.
 *
 * Loaded before wordpress.php (alphabetical order), so only functions that
 * wordpress.php does not define may appear here.
 *
 * @package SH\SpeedOptimizer\Tests
 */

// phpcs:ignoreFile

$GLOBALS['shso_test_editor_supports'] = array( 'image/webp' => true, 'image/avif' => false );
$GLOBALS['shso_test_attachment_meta'] = array();

if ( ! function_exists( 'wp_image_editor_supports' ) ) {
	function wp_image_editor_supports( $args = array() ) {
		$mime = is_array( $args ) ? (string) ( $args['mime_type'] ?? '' ) : '';
		return ! empty( $GLOBALS['shso_test_editor_supports'][ $mime ] );
	}
}

if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	function wp_get_attachment_metadata( $attachment_id = 0, $unfiltered = false ) {
		return $GLOBALS['shso_test_attachment_meta'][ (int) $attachment_id ] ?? false;
	}
}

if ( ! function_exists( 'wp_lazy_loading_enabled' ) ) {
	function wp_lazy_loading_enabled( $tag_name, $context ) {
		return true;
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'https://example.test/wp-content/plugins/sh-speed-optimizer/';
	}
}

if ( ! function_exists( 'get_theme_root_uri' ) ) {
	function get_theme_root_uri( $stylesheet_or_template = '', $theme_root = '' ) {
		return 'https://example.test/wp-content/themes';
	}
}

if ( ! function_exists( 'get_theme_root' ) ) {
	function get_theme_root( $stylesheet_or_template = '' ) {
		return WP_CONTENT_DIR . '/themes';
	}
}
