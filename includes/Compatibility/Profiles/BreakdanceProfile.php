<?php
/**
 * Breakdance compatibility.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Breakdance profile.
 */
final class BreakdanceProfile extends AbstractProfile {

	/**
	 * Breakdance handles and asset paths.
	 */
	public const SCRIPTS = array( 'breakdance' );

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'breakdance';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'Breakdance';
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		return $this->any_defined( '__BREAKDANCE_VERSION', '__BREAKDANCE_DIR__' ) || $this->any_plugin( 'breakdance' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rules $rules Rules.
	 */
	public function register( Rules $rules ): void {
		$this->protect_scripts( $rules, self::SCRIPTS );
		$rules->penalize( 'js_defer', 10, __( 'Breakdance elements initialise with inline scripts that expect their libraries to be loaded.', 'sh-speed-optimizer' ) );
	}
}
