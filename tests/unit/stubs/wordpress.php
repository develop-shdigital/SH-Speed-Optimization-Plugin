<?php
/**
 * Minimal WordPress function stubs for unit tests.
 *
 * Only add functions here that core classes need. Module-specific stubs go
 * into their own file in this directory, each wrapped in function_exists().
 *
 * @package SH\SpeedOptimizer\Tests
 */

// phpcs:ignoreFile

$GLOBALS['shso_test_options'] = array();
$GLOBALS['shso_test_filters'] = array();
$GLOBALS['shso_test_actions'] = array();

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_message() {
			return $this->message;
		}
		public function get_error_code() {
			return $this->code;
		}
	}
}

function shso_test_reset(): void {
	$GLOBALS['shso_test_options'] = array();
	$GLOBALS['shso_test_filters'] = array();
	$GLOBALS['shso_test_actions'] = array();
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function __( $text, $domain = 'default' ) {
	return $text;
}
function _e( $text, $domain = 'default' ) {
	echo $text;
}
function _x( $text, $context, $domain = 'default' ) {
	return $text;
}
function _n( $single, $plural, $number, $domain = 'default' ) {
	return 1 === (int) $number ? $single : $plural;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}
function esc_attr__( $text, $domain = 'default' ) {
	return esc_attr( $text );
}
function esc_url( $url ) {
	return (string) $url;
}
function esc_url_raw( $url ) {
	return (string) $url;
}
function esc_js( $text ) {
	return addslashes( (string) $text );
}

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['shso_test_filters'][ $hook ][ $priority ][] = $callback;
	return true;
}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	return add_filter( $hook, $callback, $priority, $args );
}
function remove_filter( $hook, $callback, $priority = 10 ) {
	return true;
}
function remove_action( $hook, $callback, $priority = 10 ) {
	return true;
}
function has_filter( $hook, $callback = false ) {
	return ! empty( $GLOBALS['shso_test_filters'][ $hook ] );
}
function has_action( $hook, $callback = false ) {
	return has_filter( $hook, $callback );
}
function apply_filters( $hook, $value, ...$args ) {
	if ( empty( $GLOBALS['shso_test_filters'][ $hook ] ) ) {
		return $value;
	}
	ksort( $GLOBALS['shso_test_filters'][ $hook ] );
	foreach ( $GLOBALS['shso_test_filters'][ $hook ] as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$value = $callback( $value, ...$args );
		}
	}
	return $value;
}
function do_action( $hook, ...$args ) {
	$GLOBALS['shso_test_actions'][] = $hook;
	if ( empty( $GLOBALS['shso_test_filters'][ $hook ] ) ) {
		return;
	}
	ksort( $GLOBALS['shso_test_filters'][ $hook ] );
	foreach ( $GLOBALS['shso_test_filters'][ $hook ] as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$callback( ...$args );
		}
	}
}
function did_action( $hook ) {
	return count( array_keys( $GLOBALS['shso_test_actions'], $hook, true ) );
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['shso_test_options'] ) ? $GLOBALS['shso_test_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['shso_test_options'][ $name ] = $value;
	return true;
}
function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $name, $GLOBALS['shso_test_options'] ) ) {
		return false;
	}
	$GLOBALS['shso_test_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['shso_test_options'][ $name ] );
	return true;
}
function get_site_option( $name, $default = false ) {
	return get_option( 'site_' . $name, $default );
}
function update_site_option( $name, $value ) {
	return update_option( 'site_' . $name, $value );
}
function get_transient( $name ) {
	return get_option( '_transient_' . $name );
}
function set_transient( $name, $value, $ttl = 0 ) {
	return update_option( '_transient_' . $name, $value );
}
function delete_transient( $name ) {
	return delete_option( '_transient_' . $name );
}
function is_multisite() {
	return ! empty( $GLOBALS['shso_test_multisite'] );
}
function get_current_blog_id() {
	return (int) ( $GLOBALS['shso_test_blog_id'] ?? 1 );
}
/**
 * Test sites: $GLOBALS['shso_test_sites'] = [ blog_id => path prefix ], longest prefix wins.
 */
function get_site_by_path( $domain, $path ) {
	$best = null;
	foreach ( (array) ( $GLOBALS['shso_test_sites'] ?? array( 1 => '/' ) ) as $id => $prefix ) {
		if ( 0 === strpos( $path, $prefix ) && ( null === $best || strlen( $prefix ) > strlen( $best[1] ) ) ) {
			$best = array( $id, $prefix );
		}
	}
	return null === $best ? false : (object) array( 'blog_id' => (string) $best[0], 'path' => $best[1] );
}
function wp_salt( $scheme = 'auth' ) {
	return 'test-salt-' . $scheme;
}
function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options | JSON_UNESCAPED_SLASHES, $depth );
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_text_field( $str ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( (string) $str ) ) );
}
function wp_strip_all_tags( $text, $remove_breaks = false ) {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
	return trim( strip_tags( $text ) );
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component );
}
function trailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' ) . '/';
}
function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}
function wp_normalize_path( $path ) {
	$path = str_replace( '\\', '/', (string) $path );
	$path = preg_replace( '|(?<=.)/+|', '/', $path );
	return $path;
}
function wp_mkdir_p( $target ) {
	return is_dir( $target ) || mkdir( $target, 0777, true );
}
function home_url( $path = '' ) {
	return 'https://example.test' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
}
function site_url( $path = '' ) {
	return home_url( $path );
}
function content_url( $path = '' ) {
	return 'https://example.test/wp-content' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
}
function includes_url( $path = '' ) {
	return 'https://example.test/wp-includes/' . ltrim( $path, '/' );
}
function plugins_url( $path = '', $plugin = '' ) {
	return 'https://example.test/wp-content/plugins/' . ltrim( $path, '/' );
}
function wp_upload_dir( $time = null, $create = true ) {
	return array(
		'basedir' => WP_CONTENT_DIR . '/uploads',
		'baseurl' => 'https://example.test/wp-content/uploads',
	);
}
function is_admin() {
	return false;
}
function wp_doing_ajax() {
	return false;
}
function wp_doing_cron() {
	return false;
}
function is_user_logged_in() {
	return false;
}
function current_user_can( $cap ) {
	return in_array( $cap, (array) ( $GLOBALS['shso_test_caps'] ?? array() ), true );
}
function get_current_user_id() {
	return 0;
}
function absint( $value ) {
	return abs( (int) $value );
}
function wp_generate_password( $length = 12, $special = true ) {
	return substr( bin2hex( random_bytes( $length ) ), 0, $length );
}
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function get_bloginfo( $show = '' ) {
	return 'charset' === $show ? 'UTF-8' : 'Test';
}
function is_ssl() {
	return true;
}
function wp_get_environment_type() {
	return 'production';
}
