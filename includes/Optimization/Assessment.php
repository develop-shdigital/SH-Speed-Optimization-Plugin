<?php
/**
 * Result of an optimization's detection logic.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Optimization;

defined( 'ABSPATH' ) || exit;

/**
 * What an optimization found on this site.
 */
final class Assessment {

	public const BENEFIT_NONE   = 'none';
	public const BENEFIT_LOW    = 'low';
	public const BENEFIT_MEDIUM = 'medium';
	public const BENEFIT_HIGH   = 'high';

	/**
	 * Whether there is anything to optimize.
	 *
	 * @var bool
	 */
	public bool $applicable = true;

	/**
	 * Compatibility confidence 0–100 (how sure we are it will not break this site).
	 *
	 * @var int
	 */
	public int $confidence = 90;

	/**
	 * Expected benefit.
	 *
	 * @var string
	 */
	public string $benefit = self::BENEFIT_LOW;

	/**
	 * Another system already provides this optimization (e.g. "WP Rocket").
	 *
	 * @var string|null
	 */
	public ?string $handled_by = null;

	/**
	 * Hard block with a reason (e.g. missing server support).
	 *
	 * @var string|null
	 */
	public ?string $blocked = null;

	/**
	 * Plain-language notes explaining the confidence value.
	 *
	 * @var string[]
	 */
	public array $reasons = array();

	/**
	 * Detection data (counts, sizes …) for diagnostics.
	 *
	 * @var array<string,mixed>
	 */
	public array $data = array();

	/**
	 * Create an assessment.
	 *
	 * @param bool   $applicable Applicable.
	 * @param int    $confidence Confidence.
	 * @param string $benefit    Benefit.
	 */
	public static function make( bool $applicable = true, int $confidence = 90, string $benefit = self::BENEFIT_LOW ): self {
		$a             = new self();
		$a->applicable = $applicable;
		$a->confidence = $confidence;
		$a->benefit    = $benefit;
		return $a;
	}

	/**
	 * Nothing to do.
	 *
	 * @param string $reason Why.
	 */
	public static function not_applicable( string $reason ): self {
		$a            = self::make( false, 100, self::BENEFIT_NONE );
		$a->reasons[] = $reason;
		return $a;
	}

	/**
	 * Lower confidence with a reason.
	 *
	 * @param int    $points Points to subtract.
	 * @param string $reason Reason.
	 */
	public function penalize( int $points, string $reason ): self {
		$this->confidence = max( 0, min( 100, $this->confidence - $points ) );
		$this->reasons[]  = $reason;
		return $this;
	}

	/**
	 * Add a note.
	 *
	 * @param string $reason Reason.
	 */
	public function note( string $reason ): self {
		$this->reasons[] = $reason;
		return $this;
	}

	/**
	 * Array form.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'applicable' => $this->applicable,
			'confidence' => $this->confidence,
			'benefit'    => $this->benefit,
			'handled_by' => $this->handled_by,
			'blocked'    => $this->blocked,
			'reasons'    => array_values( array_unique( $this->reasons ) ),
			'data'       => $this->data,
		);
	}

	/**
	 * Restore from array form.
	 *
	 * @param array<string,mixed> $data Data.
	 */
	public static function from_array( array $data ): self {
		$a             = new self();
		$a->applicable = (bool) ( $data['applicable'] ?? true );
		$a->confidence = (int) ( $data['confidence'] ?? 0 );
		$a->benefit    = (string) ( $data['benefit'] ?? self::BENEFIT_NONE );
		$a->handled_by = isset( $data['handled_by'] ) ? (string) $data['handled_by'] : null;
		$a->blocked    = isset( $data['blocked'] ) ? (string) $data['blocked'] : null;
		$a->reasons    = array_map( 'strval', (array) ( $data['reasons'] ?? array() ) );
		$a->data       = (array) ( $data['data'] ?? array() );
		return $a;
	}
}
