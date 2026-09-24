<?php
/**
 * Beaver Builder (plugin and theme) compatibility.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Beaver Builder profile.
 */
final class BeaverBuilderProfile extends AbstractProfile {

	/**
	 * Beaver Builder handles and asset paths (per-layout scripts live in uploads/bb-plugin/cache/).
	 */
	public const SCRIPTS = array( 'fl-builder', 'fl-automator', 'uploads/bb-plugin/cache/', 'plugins/bb-plugin/', 'plugins/beaver-builder-lite-version/', 'themes/bb-theme/js/' );

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'beaver_builder';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'Beaver Builder';
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		return $this->any_defined( 'FL_BUILDER_VERSION' ) || $this->env->class_exists( 'FLBuilder' ) || $this->any_plugin( 'bb-plugin', 'beaver-builder-lite-version' ) || $this->theme_is( 'bb-theme' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rules $rules Rules.
	 */
	public function register( Rules $rules ): void {
		$this->protect_scripts( $rules, self::SCRIPTS );
		$rules->penalize( 'js_defer', 10, __( 'Beaver Builder layouts initialise with per-page scripts that expect the original order.', 'sh-speed-optimizer' ) );
	}
}
