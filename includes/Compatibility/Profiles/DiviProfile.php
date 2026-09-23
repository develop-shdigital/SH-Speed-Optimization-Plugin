<?php
/**
 * Divi / Extra theme and Divi Builder plugin compatibility.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Divi profile.
 */
final class DiviProfile extends AbstractProfile {

	/**
	 * Divi frontend handles and asset paths.
	 */
	public const SCRIPTS = array(
		'divi-custom-script',
		'et-builder-modules',
		'et-core-common',
		'et-frontend-builder',
		'et-jquery-touch-mobile',
		'fitvids',
		'magnific-popup',
		'easypiechart',
		'salvattore',
		'themes/divi/',
		'themes/extra/',
		'plugins/divi-builder/',
	);

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'divi';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'Divi';
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		return $this->any_defined( 'ET_BUILDER_VERSION', 'ET_BUILDER_PLUGIN_VERSION', 'ET_BUILDER_THEME' )
			|| $this->theme_is( 'Divi', 'Extra' )
			|| $this->any_plugin( 'divi-builder' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rules $rules Rules.
	 */
	public function register( Rules $rules ): void {
		$this->protect_scripts( $rules, self::SCRIPTS );

		$rules->add( 'facade_exclude', array( 'et_pb_video' ) );

		$rules->penalize( 'js_defer', 15, __( 'Divi modules initialise with scripts that expect the original loading order.', 'sh-speed-optimizer' ) );
		$rules->penalize( 'critical_css', 30, __( 'Divi generates page-specific styles and has its own critical CSS feature.', 'sh-speed-optimizer' ) );
	}
}
