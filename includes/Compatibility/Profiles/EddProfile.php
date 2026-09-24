<?php
/**
 * Easy Digital Downloads compatibility.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * EDD profile.
 */
final class EddProfile extends AbstractProfile {

	/**
	 * Checkout and cart scripts.
	 */
	public const SCRIPTS = array( 'edd-ajax', 'edd-checkout-global', 'edd-stripe', 'edds-stripe', 'edd-paypal', 'easy-digital-downloads/assets/js/' );

	/**
	 * EDD settings keys holding page ids of personal pages.
	 */
	public const PAGES = array( 'purchase_page', 'success_page', 'failure_page', 'purchase_history_page', 'login_page', 'confirmation_page' );

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'edd';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'Easy Digital Downloads';
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		return $this->env->class_exists( 'Easy_Digital_Downloads' ) || $this->any_defined( 'EDD_VERSION', 'EDD_PLUGIN_FILE' ) || $this->any_plugin( 'easy-digital-downloads', 'easy-digital-downloads-pro' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rules $rules Rules.
	 */
	public function register( Rules $rules ): void {
		$this->protect_scripts( $rules, self::SCRIPTS );

		$settings = $this->env->option( 'edd_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$urls = array( 'edd_action=', 'edd-listener=' );
		foreach ( self::PAGES as $key ) {
			$urls = array_merge( $urls, $this->page_patterns( (int) ( $settings[ $key ] ?? 0 ) ) );
		}
		$rules->add( 'cache_exclude_urls', $urls );
		$rules->add( 'cache_exclude_cookies', array( 'edd_items_in_cart', 'edd_cart' ) );

		$rules->penalize( 'js_defer', 10, __( 'Easy Digital Downloads cart and checkout scripts depend on running in order.', 'sh-speed-optimizer' ) );
	}
}
