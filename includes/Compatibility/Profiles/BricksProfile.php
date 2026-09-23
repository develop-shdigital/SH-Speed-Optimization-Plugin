<?php
/**
 * Bricks Builder (theme) compatibility.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Bricks profile.
 */
final class BricksProfile extends AbstractProfile {

	/**
	 * Bricks frontend handles and asset paths.
	 */
	public const SCRIPTS = array( 'bricks-scripts', 'bricks-splide', 'bricks-swiper', 'bricks-isotope', 'bricks-photoswipe', 'themes/bricks/assets/js/' );

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'bricks';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'Bricks';
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		return $this->any_defined( 'BRICKS_VERSION' ) || $this->theme_is( 'bricks' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rules $rules Rules.
	 */
	public function register( Rules $rules ): void {
		$this->protect_scripts( $rules, self::SCRIPTS );
		$rules->add( 'inline_globals', array( 'bricks-scripts' => array( 'bricksData', 'bricksIsFrontend' ) ) );

		$rules->penalize( 'js_defer', 10, __( 'Bricks elements initialise with the theme scripts.', 'sh-speed-optimizer' ) );
		$rules->penalize( 'critical_css', 20, __( 'Bricks generates page-specific CSS.', 'sh-speed-optimizer' ) );
	}
}
