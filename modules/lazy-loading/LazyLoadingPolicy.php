<?php
/**
 * Who else controls native lazy loading on this site.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\LazyLoading;

defined( 'ABSPATH' ) || exit;

/**
 * Lazy loading policy.
 *
 * Themes and plugins can switch WordPress' native lazy loading off with the
 * `wp_lazy_loading_enabled` filter. For images that is respected: the other
 * system decides. Elementor's "Optimized Image Loading" switches the filter
 * off for every element type although it only handles images, so its
 * blanket switch is not treated as a decision about iframes.
 */
final class LazyLoadingPolicy {

	/**
	 * Name of the system that disabled image lazy loading, or null when images may be lazy-loaded.
	 */
	public static function images_disabled_by(): ?string {
		if ( ! function_exists( 'wp_lazy_loading_enabled' ) || wp_lazy_loading_enabled( 'img', 'shso_lazy_load' ) ) {
			return null;
		}
		if ( self::elementor_optimized_loading() ) {
			return __( 'Elementor (Optimized Image Loading)', 'sh-speed-optimizer' );
		}
		return __( 'your theme or another plugin', 'sh-speed-optimizer' );
	}

	/**
	 * Whether iframes must not be lazy-loaded because the site switched it off.
	 */
	public static function iframes_disabled(): bool {
		if ( ! function_exists( 'wp_lazy_loading_enabled' ) || wp_lazy_loading_enabled( 'iframe', 'shso_lazy_load' ) ) {
			return false;
		}
		return ! self::elementor_optimized_loading();
	}

	/**
	 * Whether Elementor's optimized image loading is on (it disables core lazy loading globally).
	 */
	public static function elementor_optimized_loading(): bool {
		return defined( 'ELEMENTOR_VERSION' ) && version_compare( (string) ELEMENTOR_VERSION, '3.21.0', '>=' ) && '1' === (string) get_option( 'elementor_optimized_image_loading', '1' );
	}
}
