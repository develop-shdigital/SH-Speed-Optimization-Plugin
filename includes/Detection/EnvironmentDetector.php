<?php
/**
 * WordPress installation facts.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Detection;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress environment detection (the `wp` section of the site profile).
 */
final class EnvironmentDetector {

	/**
	 * Plugins whose only purpose is to switch the REST API off for visitors.
	 */
	private const REST_DISABLERS = array( 'disable-json-api', 'disable-wp-rest-api', 'disable-rest-api-and-require-jwt-oauth-authentication' );

	/**
	 * Collect the `wp` section.
	 *
	 * @param callable $lookup Fact lookup (see Facts).
	 * @return array<string,mixed>
	 */
	public static function collect( callable $lookup ): array {
		global $wp_version;

		$rest_enabled = true;
		foreach ( self::REST_DISABLERS as $slug ) {
			if ( ! empty( $lookup( 'plugin', $slug ) ) ) {
				$rest_enabled = false;
			}
		}

		$home = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';

		return array(
			'version'          => isset( $wp_version ) ? (string) $wp_version : (string) get_bloginfo( 'version' ),
			'multisite'        => is_multisite(),
			'debug'            => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'debug_display'    => defined( 'WP_DEBUG_DISPLAY' ) ? (bool) WP_DEBUG_DISPLAY : ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
			'cron_disabled'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'alternate_cron'   => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
			'rest_enabled'     => $rest_enabled,
			/** This filter is documented in wp-includes/class-wp-xmlrpc-server.php */
			'xmlrpc_enabled'   => (bool) apply_filters( 'xmlrpc_enabled', true ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
			'object_cache'     => function_exists( 'wp_using_ext_object_cache' ) && (bool) wp_using_ext_object_cache(),
			'environment_type' => function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'production',
			'permalinks'       => '' !== (string) get_option( 'permalink_structure', '' ),
			'https'            => ( function_exists( 'is_ssl' ) && is_ssl() ) || 0 === strpos( $home, 'https://' ),
			'locale'           => function_exists( 'get_locale' ) ? (string) get_locale() : '',
			'home_url'         => $home,
			'wp_cache'         => defined( 'WP_CACHE' ) && WP_CACHE,
		);
	}
}
