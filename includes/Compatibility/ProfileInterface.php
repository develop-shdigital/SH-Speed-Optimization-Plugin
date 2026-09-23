<?php
/**
 * Compatibility profile contract.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * A profile protects one plugin/theme/builder family.
 *
 * `applies()` runs on frontend requests, so it must be cheap: check constants,
 * classes or functions only — no database queries or file reads.
 */
interface ProfileInterface {

	/**
	 * Stable id, e.g. "elementor".
	 */
	public function id(): string;

	/**
	 * Human readable name, e.g. "Elementor".
	 */
	public function name(): string;

	/**
	 * Whether the protected software is active.
	 */
	public function applies(): bool;

	/**
	 * Contribute exclusions, disabled optimizations and confidence penalties.
	 *
	 * @param Rules $rules Rules being built.
	 */
	public function register( Rules $rules ): void;
}
