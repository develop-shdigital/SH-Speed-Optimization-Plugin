<?php
/**
 * Other page builders (Thrive Architect, Brizy, SiteOrigin Page Builder, Visual Composer).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Generic builder profile.
 */
final class GenericBuilderProfile extends AbstractProfile {

	/**
	 * Id => [ name, plugin slugs, constants, script needles ].
	 */
	public const BUILDERS = array(
		'thrive'          => array( 'Thrive Architect', array( 'thrive-visual-editor' ), array( 'TVE_VERSION' ), array( 'tve_frontend', 'tve-', 'thrive-visual-editor' ) ),
		'brizy'           => array( 'Brizy', array( 'brizy', 'brizy-pro' ), array( 'BRIZY_VERSION' ), array( 'brizy' ) ),
		'siteorigin'      => array( 'SiteOrigin Page Builder', array( 'siteorigin-panels' ), array( 'SITEORIGIN_PANELS_VERSION' ), array( 'siteorigin-panels', 'siteorigin-parallax', 'so-widgets' ) ),
		'visual_composer' => array( 'Visual Composer', array( 'visualcomposer' ), array( 'VCV_VERSION' ), array( 'visualcomposer', 'vcv' ) ),
	);

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'other_builders';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		$names = array();
		foreach ( $this->active() as $id ) {
			$names[] = self::BUILDERS[ $id ][0];
		}
		return empty( $names ) ? __( 'Other page builders', 'sh-speed-optimizer' ) : implode( ', ', $names );
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		return ! empty( $this->active() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rules $rules Rules.
	 */
	public function register( Rules $rules ): void {
		foreach ( $this->active() as $id ) {
			$this->protect_scripts( $rules, self::BUILDERS[ $id ][3], false, true );
		}
		$rules->penalize( 'js_defer', 10, __( 'This page builder initialises its elements with scripts that expect the original order.', 'sh-speed-optimizer' ) );
		$rules->penalize( 'critical_css', 20, __( 'This page builder generates page-specific CSS.', 'sh-speed-optimizer' ) );
	}

	/**
	 * Active builders.
	 *
	 * @return string[]
	 */
	private function active(): array {
		$active = array();
		foreach ( self::BUILDERS as $id => $definition ) {
			if ( $this->any_plugin( ...$definition[1] ) || $this->any_defined( ...$definition[2] ) ) {
				$active[] = $id;
			}
		}
		return $active;
	}
}
