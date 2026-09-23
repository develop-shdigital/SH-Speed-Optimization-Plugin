<?php
/**
 * Background job handler contract.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Core\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * A job is a list of small steps. Each step must finish within a few seconds;
 * long work returns StepResult::repeat() and continues on the next call.
 */
interface JobHandlerInterface {

	/**
	 * Initial step list for a new job.
	 *
	 * @param array<string,mixed> $args Job arguments.
	 * @return string[]
	 */
	public function steps( array $args ): array;

	/**
	 * Run one step.
	 *
	 * Handlers may append steps via $job->add_steps() and store data with $job->set().
	 *
	 * @param string $step Step id.
	 * @param Job    $job  Job.
	 */
	public function run_step( string $step, Job $job ): StepResult;

	/**
	 * Translated, plain-language label for a step (shown while it runs).
	 *
	 * @param string $step Step id.
	 */
	public function label( string $step ): string;

	/**
	 * Called once when all steps completed.
	 *
	 * @param Job $job Job.
	 */
	public function complete( Job $job ): void;

	/**
	 * Called once when the job failed or was cancelled; must leave the site in a safe state.
	 *
	 * @param Job    $job    Job.
	 * @param string $reason Reason.
	 */
	public function abort( Job $job, string $reason ): void;
}
