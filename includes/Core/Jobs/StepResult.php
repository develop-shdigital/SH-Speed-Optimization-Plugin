<?php
/**
 * Result of a job step.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Core\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Step result.
 */
final class StepResult {

	public const DONE          = 'done';
	public const REPEAT        = 'repeat';
	public const AWAIT_BROWSER = 'await_browser';

	/**
	 * Outcome.
	 *
	 * @var string
	 */
	public string $status;

	/**
	 * Browser test plan (for AWAIT_BROWSER).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $plan = array();

	/**
	 * Constructor.
	 *
	 * @param string                         $status Status.
	 * @param array<int,array<string,mixed>> $plan   Plan.
	 */
	private function __construct( string $status, array $plan = array() ) {
		$this->status = $status;
		$this->plan   = $plan;
	}

	/**
	 * Step finished.
	 */
	public static function done(): self {
		return new self( self::DONE );
	}

	/**
	 * Step needs another run.
	 */
	public static function repeat(): self {
		return new self( self::REPEAT );
	}

	/**
	 * Step needs measurements from the administrator's browser.
	 *
	 * Each plan item: key, url (with verification token), purpose (analyze|verify), viewport {w,h}.
	 *
	 * @param array<int,array<string,mixed>> $plan Plan.
	 */
	public static function await_browser( array $plan ): self {
		return new self( self::AWAIT_BROWSER, $plan );
	}
}
