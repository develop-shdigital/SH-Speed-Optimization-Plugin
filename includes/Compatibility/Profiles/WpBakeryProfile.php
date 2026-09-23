<?php
/**
 * WPBakery Page Builder compatibility.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * WPBakery profile.
 */
final class WpBakeryProfile extends AbstractProfile {

	/**
	 * WPBakery frontend handles and asset paths.
	 */
	public const SCRIPTS = array( 'wpb_composer_front_js', 'vc_', 'waypoints', 'vc_grid', 'prettyphoto', 'js_composer/assets/' );

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'wpbakery';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'WPBakery Page Builder';
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		return $this->any_defined( 'WPB_VC_VERSION' ) || $this->any_plugin( 'js_composer' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rules $rules Rules.
	 */
	public function register( Rules $rules ): void {
		$this->protect_scripts( $rules, self::SCRIPTS );
		$rules->penalize( 'js_defer', 15, __( 'WPBakery elements (grids, carousels, animations) rely on jQuery plugins loading in order.', 'sh-speed-optimizer' ) );
	}
}
