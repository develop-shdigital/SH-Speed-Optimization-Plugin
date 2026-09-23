<?php
/**
 * Advanced Custom Fields compatibility.
 *
 * ACF renders plain markup on the frontend; only frontend forms (acf_form())
 * load its field scripts, which are protected here. Google Map fields use the
 * Maps API, which the base rules already protect.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * ACF profile.
 */
final class AcfProfile extends AbstractProfile {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'acf';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'Advanced Custom Fields';
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		return $this->env->class_exists( 'ACF' ) || $this->any_defined( 'ACF_VERSION' ) || $this->any_plugin( 'advanced-custom-fields', 'advanced-custom-fields-pro', 'secure-custom-fields' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rules $rules Rules.
	 */
	public function register( Rules $rules ): void {
		$this->protect_scripts( $rules, array( 'acf-input', 'acf-pro-input', 'acf-google-map' ) );
		$rules->add(
			'inline_globals',
			array(
				'acf'       => array( 'acf' ),
				'acf-input' => array( 'acf' ),
			)
		);
	}
}
