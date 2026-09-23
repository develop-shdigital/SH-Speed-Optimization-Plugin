<?php
/**
 * Everything the scanner learned about the site's environment.
 *
 * Array shape (all keys optional, detectors fill what they can):
 *
 *   wp        => version, multisite, debug, cron_disabled, alternate_cron, rest_enabled, xmlrpc_enabled,
 *                object_cache (bool), object_cache_type, environment_type, permalinks (bool), https, locale
 *   server    => software (apache|nginx|litespeed|openlitespeed|iis|unknown), software_raw, php_version,
 *                memory_limit (bytes), max_execution_time, opcache (bool), image_editor (gd|imagick|none),
 *                webp (bool), avif (bool), htaccess_writable (bool), wp_config_writable (bool),
 *                compression (gzip|br|none|unknown), disk_free (bytes|null), http2 (bool|null)
 *   plugins   => [ slug => [ name, version, file ] ]                  (active plugins)
 *   theme     => [ name, slug, template, version, is_block_theme, is_child ]
 *   builders  => [ builder id => version ]                           (elementor, elementor_pro, divi, bricks,
 *                                                                      wpbakery, beaver_builder, oxygen, gutenberg …)
 *   features  => [ woocommerce, edd, acf, multilingual (wpml|polylang|translatepress|weglot|null),
 *                  membership[], forms[], booking[], lms[], sliders[], captcha[], maps[], consent[], seo[],
 *                  ajax_heavy (bool), ajax_plugins[] ]
 *   conflicts => [ slug => [ name, features[] ] ]                     (other optimization systems and the
 *                                                                      features they already provide)
 *   hosting   => [ provider, provider_name, page_cache (bool), page_cache_by, cdn (cloudflare|quic_cloud|bunny|
 *                  cloudfront|fastly|akamai|sucuri|keycdn|stackpath|azure|generic|null), cdn_name,
 *                  server_cache (litespeed|varnish|nginx|generic|null), server_cache_name, edge_cache_hit (bool) ]
 *                  (a measured cache hit on a repeated request marks page_cache true)
 *   cache     => [ advanced_cache => [exists, ours, owner], object_cache_dropin => [exists, name],
 *                  wp_cache_constant (bool) ]
 *   loopback  => [ ok (bool), status, ttfb_ms, error, headers[] ]
 *   browser_cache => [ checked_url, cache_control, expires, etag, max_age, configured (bool) ]
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Detection;

defined( 'ABSPATH' ) || exit;

/**
 * Detection result value object.
 */
final class SiteProfile {

	/**
	 * Raw data.
	 *
	 * @var array<string,mixed>
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data Data.
	 */
	public function __construct( array $data = array() ) {
		$this->data = $data;
	}

	/**
	 * Dot-notation getter: get( 'server.software' ).
	 *
	 * @param string $path     Path.
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	public function get( string $path, $fallback = null ) {
		$value = $this->data;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $fallback;
			}
			$value = $value[ $segment ];
		}
		return $value;
	}

	/**
	 * Dot-notation setter.
	 *
	 * @param string $path  Path.
	 * @param mixed  $value Value.
	 */
	public function set( string $path, $value ): void {
		$ref = &$this->data;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! isset( $ref[ $segment ] ) || ! is_array( $ref[ $segment ] ) ) {
				$ref[ $segment ] = array();
			}
			$ref = &$ref[ $segment ];
		}
		$ref = $value;
	}

	/**
	 * Merge a section.
	 *
	 * @param string              $section Section key.
	 * @param array<string,mixed> $values  Values.
	 */
	public function merge( string $section, array $values ): void {
		$current                  = isset( $this->data[ $section ] ) && is_array( $this->data[ $section ] ) ? $this->data[ $section ] : array();
		$this->data[ $section ] = array_merge( $current, $values );
	}

	/**
	 * Whether an active plugin with this slug (directory name) exists.
	 *
	 * @param string $slug Plugin slug.
	 */
	public function has_plugin( string $slug ): bool {
		return isset( $this->data['plugins'][ $slug ] );
	}

	/**
	 * Whether a page builder was detected.
	 *
	 * @param string $builder Builder id.
	 */
	public function has_builder( string $builder ): bool {
		return isset( $this->data['builders'][ $builder ] );
	}

	/**
	 * Whether a feature flag is set (e.g. "woocommerce") or a feature list is non-empty (e.g. "forms").
	 *
	 * @param string $feature Feature key.
	 */
	public function has_feature( string $feature ): bool {
		return ! empty( $this->data['features'][ $feature ] );
	}

	/**
	 * Names of other systems that already provide a feature (page_cache, minify_css, minify_js, defer_js,
	 * delay_js, lazy_load, webp, critical_css, font_optimization, browser_cache, preload, cdn …).
	 *
	 * @param string $feature Feature key.
	 * @return string[]
	 */
	public function provided_by( string $feature ): array {
		$names = array();
		foreach ( (array) ( $this->data['conflicts'] ?? array() ) as $conflict ) {
			if ( in_array( $feature, (array) ( $conflict['features'] ?? array() ), true ) ) {
				$names[] = (string) ( $conflict['name'] ?? '' );
			}
		}
		if ( 'page_cache' === $feature && ! empty( $this->data['hosting']['page_cache'] ) ) {
			$names[] = (string) ( $this->data['hosting']['provider_name'] ?? __( 'Your hosting provider', 'sh-speed-optimizer' ) );
		}
		return array_values( array_unique( array_filter( $names ) ) );
	}

	/**
	 * Raw array.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}
}
