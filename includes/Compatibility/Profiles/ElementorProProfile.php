<?php
/**
 * Elementor Pro compatibility (popups, forms, sticky elements, motion effects, nav menu).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor Pro profile.
 */
final class ElementorProProfile extends AbstractProfile {

	/**
	 * Elementor Pro frontend handles and asset paths.
	 */
	public const SCRIPTS = array(
		'elementor-pro-frontend',
		'elementor-pro-webpack-runtime',
		'pro-elements-handlers',
		'e-sticky',
		'elementor-sticky',
		'smartmenus',
		'plugins/elementor-pro/assets/js/',
	);

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'elementor_pro';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'Elementor Pro';
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		return $this->any_defined( 'ELEMENTOR_PRO_VERSION' ) || $this->any_plugin( 'elementor-pro' );
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
				'elementor-pro-frontend' => array( 'elementorProFrontend' ),
				'smartmenus'             => array( 'jQuery.fn.smartmenus' ),
			)
		);

		$rules->penalize( 'js_defer', 10, __( 'Elementor Pro popups, forms and sticky elements need their scripts in the original order.', 'sh-speed-optimizer' ) );
	}
}
