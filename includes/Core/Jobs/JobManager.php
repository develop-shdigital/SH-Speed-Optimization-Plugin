<?php
/**
 * Runs background jobs step by step.
 *
 * Jobs advance either while the administrator watches (the dashboard calls the
 * step endpoint repeatedly) or through WP-Cron / Action Scheduler when nobody
 * is watching. Heavy work never runs during normal visitor requests.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Core\Jobs;

use SH\SpeedOptimizer\Core\Plugin;
use SH\SpeedOptimizer\Core\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Job manager.
 */
final class JobManager {

	public const OPTION      = 'shso_job';
	public const LOCK_OPTION = 'shso_job_lock';

	/**
	 * How long an administrator's browser may take to return measurements.
	 */
	public const BROWSER_TIMEOUT = 600;

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Handlers by job type.
	 *
	 * @var array<string,JobHandlerInterface>
	 */
	private array $handlers = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register a handler.
	 *
	 * @param string              $type    Job type.
	 * @param JobHandlerInterface $handler Handler.
	 */
	public function register( string $type, JobHandlerInterface $handler ): void {
		$this->handlers[ $type ] = $handler;
	}

	/**
	 * Handler for a type.
	 *
	 * @param string $type Type.
	 */
	public function handler( string $type ): ?JobHandlerInterface {
		$this->plugin->register_job_handlers();
		return $this->handlers[ $type ] ?? null;
	}

	/**
	 * Current (latest) job.
	 */
	public function current(): ?Job {
		$raw = get_option( self::OPTION );
		return is_array( $raw ) && ! empty( $raw['id'] ) ? new Job( $raw ) : null;
	}

	/**
	 * Start a job. Fails when another job is still active (unless it is stale).
	 *
	 * @param string              $type Type.
	 * @param array<string,mixed> $args Arguments.
	 * @return Job|\WP_Error
	 */
	public function start( string $type, array $args = array() ) {
		$handler = $this->handler( $type );
		if ( null === $handler ) {
			return new \WP_Error( 'shso_unknown_job', __( 'Unknown task.', 'sh-speed-optimizer' ) );
		}

		$current = $this->current();
		if ( null !== $current && $current->is_active() ) {
			$stale = ( time() - (int) $current->to_array()['updated'] ) > 30 * MINUTE_IN_SECONDS;
			if ( ! $stale ) {
				return new \WP_Error( 'shso_job_running', __( 'Another task is still running. Please wait until it has finished.', 'sh-speed-optimizer' ) );
			}
			$this->finish_aborted( $current, __( 'The task stopped responding and was cancelled.', 'sh-speed-optimizer' ) );
		}

		$job = new Job(
			array(
				'id'     => wp_generate_password( 12, false ),
				'type'   => $type,
				'status' => Job::RUNNING,
				'steps'  => array_values( $handler->steps( $args ) ),
				'args'   => $args,
				'user'   => get_current_user_id(),
			)
		);

		$this->save( $job );
		Scheduler::async( Scheduler::JOB_HOOK, array(), 15 );

		return $job;
	}

	/**
	 * Run steps for up to $budget seconds. Step failures are caught and abort the job safely.
	 *
	 * @param float $budget Seconds.
	 * @throws \RuntimeException Never escapes: caught internally when a handler is missing.
	 */
	public function run( float $budget = 8.0 ): ?Job {
		$job = $this->current();
		if ( null === $job || ! $job->is_active() ) {
			return $job;
		}

		if ( ! $this->lock() ) {
			return $job; // Another process is working on it.
		}

		$handler = $this->handler( $job->type() );
		$start   = microtime( true );

		try {
			if ( null === $handler ) {
				throw new \RuntimeException( 'Missing job handler.' );
			}

			while ( true ) {
				if ( Job::AWAIT_BROWSER === $job->status() ) {
					if ( time() - $job->browser_requested_at() > self::BROWSER_TIMEOUT ) {
						$job->browser_unavailable();
						$job->message( __( 'Browser checks were skipped because the dashboard was closed. Optimizations that need them were not applied.', 'sh-speed-optimizer' ), 'warning' );
					} else {
						break;
					}
				}

				$step = $job->current_step();
				if ( null === $step ) {
					$job->set_status( Job::DONE );
					$handler->complete( $job );
					break;
				}

				$job->set_label( $handler->label( $step ) );
				$result = $handler->run_step( $step, $job );
				$job->touch();

				if ( StepResult::DONE === $result->status ) {
					$job->advance();
				} elseif ( StepResult::AWAIT_BROWSER === $result->status ) {
					// The requesting step is finished; the next step consumes the browser results.
					$job->advance();
					$job->request_browser( $result->plan );
					$this->save( $job );
					break;
				}

				$this->save( $job );

				if ( microtime( true ) - $start > $budget ) {
					break;
				}
			}
		} catch ( \Throwable $e ) {
			$this->plugin->logger()->error(
				'Background task failed.',
				array(
					'job'   => $job->type(),
					'step'  => (string) $job->current_step(),
					'error' => $e->getMessage(),
					'file'  => basename( $e->getFile() ) . ':' . $e->getLine(),
				),
				'engine'
			);
			$this->finish_aborted( $job, __( 'The task could not be completed. Nothing was left half-applied.', 'sh-speed-optimizer' ) );
		}

		$this->save( $job );
		$this->unlock();

		if ( $job->is_active() && Job::AWAIT_BROWSER !== $job->status() ) {
			Scheduler::async( Scheduler::JOB_HOOK, array(), 20 );
		}

		return $job;
	}

	/**
	 * Accept browser measurements for the current job.
	 *
	 * @param string              $job_id  Job id.
	 * @param array<string,mixed> $results Results keyed by plan item key, or [ '_unavailable' => true ].
	 * @return Job|\WP_Error
	 */
	public function receive_browser( string $job_id, array $results ) {
		$job = $this->current();
		if ( null === $job || $job->id() !== $job_id || Job::AWAIT_BROWSER !== $job->status() ) {
			return new \WP_Error( 'shso_job_state', __( 'The task is not waiting for browser checks.', 'sh-speed-optimizer' ) );
		}

		if ( ! empty( $results['_unavailable'] ) ) {
			$job->browser_unavailable();
		} else {
			$allowed = array();
			foreach ( $job->browser_plan() as $item ) {
				$key = (string) ( $item['key'] ?? '' );
				if ( isset( $results[ $key ] ) && is_array( $results[ $key ] ) ) {
					$allowed[ $key ] = $results[ $key ];
				}
			}
			$job->receive_browser( $allowed );
		}

		$job->touch();
		$this->save( $job );
		return $job;
	}

	/**
	 * Cancel the current job.
	 */
	public function cancel(): ?Job {
		$job = $this->current();
		if ( null !== $job && $job->is_active() ) {
			$this->finish_aborted( $job, __( 'Cancelled.', 'sh-speed-optimizer' ), Job::CANCELLED );
			$this->save( $job );
			$this->unlock();
		}
		return $job;
	}

	/**
	 * Cron callback.
	 */
	public function cron_tick(): void {
		$this->run( 20.0 );
	}

	/**
	 * Abort a job through its handler (restores a safe state).
	 *
	 * @param Job    $job    Job.
	 * @param string $reason Reason.
	 * @param string $status Final status.
	 */
	private function finish_aborted( Job $job, string $reason, string $status = Job::FAILED ): void {
		$job->set_status( $status );
		$job->set_error( $reason );
		$handler = $this->handler( $job->type() );
		if ( null !== $handler ) {
			try {
				$handler->abort( $job, $reason );
			} catch ( \Throwable $e ) {
				$this->plugin->logger()->error( 'Task cleanup failed.', array( 'error' => $e->getMessage() ), 'engine' );
			}
		}
		$this->save( $job );
	}

	/**
	 * Persist.
	 *
	 * @param Job $job Job.
	 */
	private function save( Job $job ): void {
		update_option( self::OPTION, $job->to_array(), false );
	}

	/**
	 * Acquire the run lock.
	 */
	private function lock(): bool {
		$expires = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $expires > time() ) {
			return false;
		}
		if ( $expires > 0 ) {
			delete_option( self::LOCK_OPTION );
		}
		return add_option( self::LOCK_OPTION, time() + 90, '', false );
	}

	/**
	 * Release the run lock.
	 */
	private function unlock(): void {
		delete_option( self::LOCK_OPTION );
	}
}
