<?php
/**
 * Oxygen Builder compatibility.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Oxygen profile.
 */
final class OxygenProfile extends AbstractProfile {

	/**
	 * Oxygen handles and asset paths.
	 */
	public const SCRIPTS = array( 'oxygen', 'plugins/oxygen/' );

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'oxygen';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'Oxygen';
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		return $this->any_defined( 'CT_VERSION' ) || $this->any_plugin( 'oxygen' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rules $rules Rules.
	 */
	public function register( Rules $rules ): void {
		$this->protect_scripts( $rules, self::SCRIPTS );
		$rules->penalize( 'js_defer', 10, __( 'Oxygen prints inline scripts that expect its libraries to be loaded already.', 'sh-speed-optimizer' ) );
	}
}
