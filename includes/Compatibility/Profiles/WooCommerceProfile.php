<?php
/**
 * WooCommerce compatibility.
 *
 * Cart, checkout and account pages are personal and never cached; cart
 * fragments, variation forms, the checkout and the product gallery need
 * their scripts to run normally; currency switchers get separate cache
 * variants; geolocated prices keep catalog pages out of the cache.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce profile.
 */
final class WooCommerceProfile extends AbstractProfile {

	/**
	 * Frontend handles and file name fragments that must not be deferred or delayed.
	 */
	public const SCRIPTS = array(
		'wc-cart-fragments',
		'cart-fragments',
		'wc-add-to-cart',
		'add-to-cart',
		'js/frontend/woocommerce.',
		'wc-checkout',
		'frontend/checkout.',
		'wc-country-select',
		'country-select',
		'wc-address-i18n',
		'address-i18n',
		'selectwoo',
		'select2',
		'wc-single-product',
		'single-product',
		'zoom',
		'flexslider',
		'photoswipe',
		'wc-blocks',
		'wc-price-slider',
		'price-slider',
		'jquery-blockui',
		'jquery.blockui',
		'js-cookie',
		'js.cookie',
		'sourcebuster',
		'wc-order-attribution',
		'order-attribution',
	);

	/**
	 * Cookies that make pages personal.
	 */
	public const COOKIES = array(
		'woocommerce_items_in_cart',
		'woocommerce_cart_hash',
		'wp_woocommerce_session_',
		// Set by WooCommerce only while the "Recently viewed products" widget is in use.
		'woocommerce_recently_viewed',
	);

	/**
	 * Currency switcher cookies (WOOCS, Aelia, CURCY, YITH, WPML multi-currency): one cache variant per value.
	 */
	public const CURRENCY_COOKIES = array(
		'woocs_current_currency',
		'aelia_cs_selected_currency',
		'wmc_current_currency',
		'yith_wcmcs_currency',
		'wcml_client_currency',
	);

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'woocommerce';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'WooCommerce';
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		return $this->env->class_exists( 'WooCommerce' ) || $this->any_defined( 'WC_PLUGIN_FILE' ) || $this->any_plugin( 'woocommerce' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rules $rules Rules.
	 */
	public function register( Rules $rules ): void {
		$this->protect_scripts( $rules, self::SCRIPTS );

		$rules->add(
			'inline_globals',
			array(
				'js-cookie'             => array( 'Cookies' ),
				'photoswipe'            => array( 'PhotoSwipe' ),
				'photoswipe-ui-default' => array( 'PhotoSwipeUI_Default' ),
				'sourcebuster-js'       => array( 'sbjs' ),
				'wc-order-attribution'  => array( 'wc_order_attribution' ),
			)
		);

		$urls = array( '?add-to-cart=', '&add-to-cart=', 'wc-ajax=', '/wc-api/' );
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
			// Account endpoints (orders, downloads, logout …) and checkout endpoints live below these pages.
			$urls = array_merge( $urls, $this->page_patterns( (int) $this->env->option( 'woocommerce_' . $page . '_page_id', 0 ) ) );
		}
		$rules->add( 'cache_exclude_urls', $urls );

		$rules->add( 'cache_exclude_cookies', self::COOKIES );
		$rules->add( 'cache_vary_cookies', self::CURRENCY_COOKIES );

		if ( 'geolocation' === (string) $this->env->option( 'woocommerce_default_customer_address', '' ) ) {
			$rules->add( 'cache_exclude_urls', $this->catalog_patterns() );
			$rules->penalize( 'page_cache', 10, __( 'WooCommerce geolocates visitors, so prices and taxes can differ per visitor; shop and product pages are not cached.', 'sh-speed-optimizer' ) );
		}

		$rules->penalize( 'js_defer', 15, __( 'WooCommerce cart, variation and checkout scripts depend on running in order.', 'sh-speed-optimizer' ) );
		$rules->penalize( 'js_delay_third_party', 5, __( 'Shops load payment and analytics services that are sensitive to timing.', 'sh-speed-optimizer' ) );
		$rules->penalize( 'critical_css', 20, __( 'Shop pages have many dynamic states (cart notices, variations), which makes critical CSS less reliable.', 'sh-speed-optimizer' ) );
	}

	/**
	 * URL patterns of the shop page, products, product categories and tags.
	 *
	 * @return string[]
	 */
	public function catalog_patterns(): array {
		$patterns = $this->page_patterns( (int) $this->env->option( 'woocommerce_shop_page_id', 0 ) );

		if ( ! $this->env->permalinks_enabled() ) {
			return array_merge( $patterns, array( 'post_type=product', 'product_cat=', 'product_tag=', '?product=', '&product=' ) );
		}

		$permalinks = $this->env->option( 'woocommerce_permalinks', array() );
		$permalinks = is_array( $permalinks ) ? $permalinks : array();

		$bases    = array(
			(string) ( $permalinks['product_base'] ?? '' ),
			(string) ( $permalinks['category_base'] ?? '' ),
			(string) ( $permalinks['tag_base'] ?? '' ),
		);
		$defaults = array( 'product', 'product-category', 'product-tag' );

		foreach ( $bases as $index => $base ) {
			$base = trim( $base );
			if ( '' === $base ) {
				$base = $defaults[ $index ];
			}
			// "/shop/%product_cat%" → "shop": only the static part identifies the URL.
			$percent = strpos( $base, '%' );
			if ( false !== $percent ) {
				$base = substr( $base, 0, $percent );
			}
			$base = trim( $base, '/' );
			if ( '' !== $base ) {
				$patterns[] = '/' . $base . '/';
			}
		}

		return array_values( array_unique( $patterns ) );
	}
}
