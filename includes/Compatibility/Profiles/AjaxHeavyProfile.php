<?php
/**
 * AJAX-driven filtering and search (FacetWP, SearchWP Live Search, WOOF, YITH filters, Filter Everything).
 *
 * Filtered result URLs carry query parameters and bypass the page cache by
 * default, so only the scripts need protection.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX-heavy profile.
 */
final class AjaxHeavyProfile extends AbstractProfile {

	/**
	 * Plugin slug => script needles.
	 */
	public const PLUGINS = array(
		'facetwp'                                      => array( 'facetwp' ),
		'searchwp-live-ajax-search'                    => array( 'searchwp-live-search', 'swp-live-search', 'searchwp' ),
		'searchwp'                                     => array( 'searchwp' ),
		'woocommerce-products-filter'                  => array( 'woof' ),
		'yith-woocommerce-ajax-navigation'             => array( 'yith-wcan', 'yith_wcan' ),
		'yith-woocommerce-ajax-product-filter-premium' => array( 'yith-wcan', 'yith_wcan' ),
		'filter-everything'                            => array( 'filter-everything', 'wpc-filter' ),
		'filter-everything-pro'                        => array( 'filter-everything', 'wpc-filter' ),
		'ajax-load-more'                               => array( 'ajax-load-more' ),
	);

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'ajax_heavy';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'AJAX filtering and search', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		return $this->any_plugin( ...array_keys( self::PLUGINS ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rules $rules Rules.
	 */
	public function register( Rules $rules ): void {
		foreach ( self::PLUGINS as $slug => $needles ) {
			if ( $this->any_plugin( $slug ) ) {
				$this->protect_scripts( $rules, $needles );
			}
		}
		$rules->penalize( 'js_defer', 10, __( 'Filters and live search load results with scripts that must be ready when the page opens.', 'sh-speed-optimizer' ) );
	}
}
