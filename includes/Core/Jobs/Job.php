<?php
/**
 * Background job state.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Core\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Job value object (persisted as an array).
 */
final class Job {

	public const QUEUED        = 'queued';
	public const RUNNING       = 'running';
	public const AWAIT_BROWSER = 'awaiting_browser';
	public const DONE          = 'done';
	public const FAILED        = 'failed';
	public const CANCELLED     = 'cancelled';

	/**
	 * Raw data.
	 *
	 * @var array<string,mixed>
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data Data.
	 */
	public function __construct( array $data ) {
		$this->data = array_merge(
			array(
				'id'       => '',
				'type'     => '',
				'status'   => self::QUEUED,
				'steps'    => array(),
				'cursor'   => 0,
				'args'     => array(),
				'store'    => array(),
				'messages' => array(),
				'browser'  => array(
					'plan'         => array(),
					'results'      => array(),
					'requested_at' => 0,
				),
				'label'    => '',
				'error'    => '',
				'user'     => 0,
				'created'  => time(),
				'updated'  => time(),
			),
			$data
		);
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return (string) $this->data['id'];
	}

	/**
	 * Type.
	 */
	public function type(): string {
		return (string) $this->data['type'];
	}

	/**
	 * Status.
	 */
	public function status(): string {
		return (string) $this->data['status'];
	}

	/**
	 * Set status.
	 *
	 * @param string $status Status.
	 */
	public function set_status( string $status ): void {
		$this->data['status'] = $status;
	}

	/**
	 * Whether the job is still in progress.
	 */
	public function is_active(): bool {
		return in_array( $this->status(), array( self::QUEUED, self::RUNNING, self::AWAIT_BROWSER ), true );
	}

	/**
	 * Job argument.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	public function arg( string $key, $fallback = null ) {
		return $this->data['args'][ $key ] ?? $fallback;
	}

	/**
	 * Stored value.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	public function get( string $key, $fallback = null ) {
		return $this->data['store'][ $key ] ?? $fallback;
	}

	/**
	 * Store a value.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function set( string $key, $value ): void {
		$this->data['store'][ $key ] = $value;
	}

	/**
	 * Current step id or null when finished.
	 */
	public function current_step(): ?string {
		return $this->data['steps'][ $this->data['cursor'] ] ?? null;
	}

	/**
	 * Advance to the next step.
	 */
	public function advance(): void {
		++$this->data['cursor'];
	}

	/**
	 * Insert steps right after the current one.
	 *
	 * @param string[] $steps Steps.
	 */
	public function add_steps( array $steps ): void {
		array_splice( $this->data['steps'], (int) $this->data['cursor'] + 1, 0, array_values( $steps ) );
	}

	/**
	 * Remaining step ids (including the current one).
	 *
	 * @return string[]
	 */
	public function remaining_steps(): array {
		return array_slice( $this->data['steps'], (int) $this->data['cursor'] );
	}

	/**
	 * Progress 0–100.
	 */
	public function progress(): int {
		$total = count( $this->data['steps'] );
		if ( 0 === $total ) {
			return 0;
		}
		if ( self::DONE === $this->status() ) {
			return 100;
		}
		return (int) min( 99, floor( 100 * $this->data['cursor'] / $total ) );
	}

	/**
	 * Add a user-facing message.
	 *
	 * @param string $message Message.
	 * @param string $type    info|success|warning|error.
	 */
	public function message( string $message, string $type = 'info' ): void {
		$this->data['messages'][] = array(
			'text' => $message,
			'type' => $type,
			'time' => time(),
		);
		$this->data['messages']   = array_slice( $this->data['messages'], -50 );
	}

	/**
	 * Set the label of the running step.
	 *
	 * @param string $label Label.
	 */
	public function set_label( string $label ): void {
		$this->data['label'] = $label;
	}

	/**
	 * Set the error.
	 *
	 * @param string $error Error.
	 */
	public function set_error( string $error ): void {
		$this->data['error'] = $error;
	}

	/**
	 * Request browser measurements.
	 *
	 * @param array<int,array<string,mixed>> $plan Plan.
	 */
	public function request_browser( array $plan ): void {
		$this->data['browser'] = array(
			'plan'         => $plan,
			'results'      => array(),
			'requested_at' => time(),
		);
		$this->data['status']  = self::AWAIT_BROWSER;
	}

	/**
	 * Browser plan.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function browser_plan(): array {
		return (array) $this->data['browser']['plan'];
	}

	/**
	 * When browser measurements were requested.
	 */
	public function browser_requested_at(): int {
		return (int) $this->data['browser']['requested_at'];
	}

	/**
	 * Store browser results (keyed by plan item key) and resume.
	 *
	 * @param array<string,mixed> $results Results.
	 */
	public function receive_browser( array $results ): void {
		$this->data['browser']['results'] = $results;
		$this->data['browser']['plan']    = array();
		$this->data['status']             = self::RUNNING;
	}

	/**
	 * Mark browser measurements as unavailable and resume.
	 */
	public function browser_unavailable(): void {
		$this->data['browser']['results'] = array( '_unavailable' => true );
		$this->data['browser']['plan']    = array();
		$this->data['status']             = self::RUNNING;
	}

	/**
	 * Browser results of the last request (consumed by the step that asked).
	 *
	 * @return array<string,mixed>
	 */
	public function browser_results(): array {
		return (array) $this->data['browser']['results'];
	}

	/**
	 * Touch.
	 */
	public function touch(): void {
		$this->data['updated'] = time();
	}

	/**
	 * Raw array.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}

	/**
	 * Public representation for the admin UI.
	 *
	 * @return array<string,mixed>
	 */
	public function to_public(): array {
		return array(
			'id'       => $this->id(),
			'type'     => $this->type(),
			'status'   => $this->status(),
			'progress' => $this->progress(),
			'label'    => (string) $this->data['label'],
			'error'    => (string) $this->data['error'],
			'messages' => $this->data['messages'],
			'browser'  => array( 'plan' => $this->browser_plan() ),
			'summary'  => $this->get( 'summary' ),
			'created'  => (int) $this->data['created'],
			'updated'  => (int) $this->data['updated'],
		);
	}
}
