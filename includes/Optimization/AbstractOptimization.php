<?php
/**
 * Base class with sensible defaults.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Optimization;

use SH\SpeedOptimizer\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Base optimization.
 */
abstract class AbstractOptimization implements OptimizationInterface {

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	protected Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_reversible(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependencies(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function conflicts(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function requirements(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_enabled(): bool {
		return Risk::LEVEL_SAFE === $this->level();
	}

	/**
	 * {@inheritDoc}
	 */
	public function safe_mode_compatible(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function expected_changes(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function verify( array $baseline, array $candidate ): ?string {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function rollback(): void {
	}

	/**
	 * {@inheritDoc}
	 */
	public function details(): array {
		return array();
	}

	/**
	 * Apply profile penalties and "handled by another plugin" detection to an assessment.
	 *
	 * @param Assessment        $assessment Assessment.
	 * @param AssessmentContext $context    Context.
	 * @param string|null       $feature    Conflict feature key (see SiteProfile::provided_by()).
	 */
	protected function finalize( Assessment $assessment, AssessmentContext $context, ?string $feature = null ): Assessment {
		foreach ( $context->rules->penalties( $this->id() ) as $penalty ) {
			$assessment->penalize( (int) $penalty[0], (string) $penalty[1] );
		}

		$disabled = $context->rules->disabled_reason( $this->id() );
		if ( null !== $disabled ) {
			$assessment->blocked = $disabled;
		}

		if ( null !== $feature ) {
			$providers = $context->profile->provided_by( $feature );
			if ( ! empty( $providers ) ) {
				$assessment->handled_by = implode( ', ', $providers );
			}
		}

		return $assessment;
	}

	/**
	 * {@inheritDoc}
	 */
	public function to_array(): array {
		return array(
			'id'                   => $this->id(),
			'name'                 => $this->name(),
			'description'          => $this->description(),
			'category'             => $this->category(),
			'risk'                 => $this->risk(),
			'level'                => $this->level(),
			'reversible'           => $this->is_reversible(),
			'dependencies'         => $this->dependencies(),
			'conflicts'            => $this->conflicts(),
			'requirements'         => $this->requirements(),
			'default'              => $this->default_enabled(),
			'safe_mode_compatible' => $this->safe_mode_compatible(),
		);
	}
}
