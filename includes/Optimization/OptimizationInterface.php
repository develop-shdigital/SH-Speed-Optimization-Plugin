<?php
/**
 * Optimization contract.
 *
 * Every optimization is a small, isolated unit with metadata, detection,
 * apply, verification and rollback logic. Runtime behaviour (hooks, HTML
 * transformations) is only registered while the optimization is active.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Optimization;

defined( 'ABSPATH' ) || exit;

/**
 * Optimization interface.
 */
interface OptimizationInterface {

	/** Verified in the administrator's browser before it may be kept (JS errors, layout). */
	public const REQ_BROWSER = 'browser_verification';

	/** Needs the administrator's explicit permission to change server configuration (.htaccess). */
	public const REQ_SERVER_CONFIG = 'server_config';

	/** Needs working loopback requests for verification. */
	public const REQ_LOOPBACK = 'loopback';

	/** Needs explicit permission to download third-party files (e.g. fonts). */
	public const REQ_EXTERNAL_DOWNLOAD = 'external_download';

	/**
	 * Unique id (snake_case), e.g. "disable_emojis".
	 */
	public function id(): string;

	/**
	 * Short translated name.
	 */
	public function name(): string;

	/**
	 * Plain-language translated description (what it does for the visitor).
	 */
	public function description(): string;

	/**
	 * Category (see Category constants).
	 */
	public function category(): string;

	/**
	 * Risk (see Risk constants).
	 */
	public function risk(): string;

	/**
	 * Level: safe|smart|experimental (see Risk::LEVEL_*).
	 */
	public function level(): string;

	/**
	 * Whether rollback fully restores the previous behaviour.
	 */
	public function is_reversible(): bool;

	/**
	 * Optimization ids that must be active first.
	 *
	 * @return string[]
	 */
	public function dependencies(): array;

	/**
	 * Optimization ids that must not be active at the same time.
	 *
	 * @return string[]
	 */
	public function conflicts(): array;

	/**
	 * Requirements (REQ_* constants).
	 *
	 * @return string[]
	 */
	public function requirements(): array;

	/**
	 * Default state when no scan data exists (true only for SAFE level).
	 */
	public function default_enabled(): bool;

	/**
	 * Whether it stays active while Safe Mode is on (only non-transforming, functionality-neutral optimizations).
	 */
	public function safe_mode_compatible(): bool;

	/**
	 * Marker counts the optimization is expected to change (e.g. "iframes" for facades), so verification tolerates it.
	 *
	 * @return string[]
	 */
	public function expected_changes(): array;

	/**
	 * Detection logic: is it applicable, how confident are we, what is the benefit?
	 *
	 * Must not change anything. May be slow-ish (runs in background scans only).
	 *
	 * @param AssessmentContext $context Scan data.
	 */
	public function assess( AssessmentContext $context ): Assessment;

	/**
	 * Apply logic: one-time side effects when activated (write a drop-in, schedule a queue …).
	 * Most optimizations have none because their behaviour lives in register_runtime().
	 *
	 * @return true|\WP_Error
	 */
	public function apply();

	/**
	 * Extra server-side verification beyond the generic checks.
	 *
	 * @param array<string,mixed> $baseline  Loopback snapshot without optimizations.
	 * @param array<string,mixed> $candidate Loopback snapshot with the candidate set.
	 * @return string|null Failure description, or null when fine.
	 */
	public function verify( array $baseline, array $candidate ): ?string;

	/**
	 * Rollback logic: undo apply() side effects and delete generated files.
	 */
	public function rollback(): void;

	/**
	 * Register hooks/transformers for this request. Called in every context (frontend, admin, cron, REST)
	 * while the optimization is effectively active; implementations decide per context what to hook.
	 *
	 * @param \SH\SpeedOptimizer\Optimization\Runtime $runtime Runtime.
	 */
	public function register_runtime( Runtime $runtime ): void;

	/**
	 * Current status details for the dashboard (plain values, translated labels).
	 *
	 * @return array<string,mixed>
	 */
	public function details(): array;

	/**
	 * Metadata array (id, name, description, category, risk, level, reversible, dependencies, conflicts,
	 * requirements, default).
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array;
}
