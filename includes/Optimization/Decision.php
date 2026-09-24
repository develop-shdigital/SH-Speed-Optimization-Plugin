<?php
/**
 * Outcome of the decision engine for one optimization.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Optimization;

defined( 'ABSPATH' ) || exit;

/**
 * Decision value object.
 */
final class Decision {

	public const AUTO_APPLY      = 'auto_apply';      // Apply, then verify; roll back on failure.
	public const TEST_THEN_APPLY = 'test_then_apply'; // Keep only if verification (incl. browser checks when required) passes.
	public const RECOMMEND       = 'recommend';       // Suggest to the user; never automatic.
	public const SKIP            = 'skip';            // Not applicable, handled elsewhere, or turned off.
	public const ACTIVE          = 'active';          // Already active.

	/**
	 * Action.
	 *
	 * @var string
	 */
	public string $action;

	/**
	 * Short plain-language explanation.
	 *
	 * @var string
	 */
	public string $summary;

	/**
	 * Confidence 0–100.
	 *
	 * @var int
	 */
	public int $confidence;

	/**
	 * Risk.
	 *
	 * @var string
	 */
	public string $risk;

	/**
	 * Benefit.
	 *
	 * @var string
	 */
	public string $benefit;

	/**
	 * Supporting reasons.
	 *
	 * @var string[]
	 */
	public array $reasons;

	/**
	 * Constructor.
	 *
	 * @param string   $action     Action.
	 * @param string   $summary    Summary.
	 * @param int      $confidence Confidence.
	 * @param string   $risk       Risk.
	 * @param string   $benefit    Benefit.
	 * @param string[] $reasons    Reasons.
	 */
	public function __construct( string $action, string $summary, int $confidence, string $risk, string $benefit, array $reasons = array() ) {
		$this->action     = $action;
		$this->summary    = $summary;
		$this->confidence = $confidence;
		$this->risk       = $risk;
		$this->benefit    = $benefit;
		$this->reasons    = $reasons;
	}

	/**
	 * Whether the engine may enable it without the user.
	 */
	public function is_automatic(): bool {
		return in_array( $this->action, array( self::AUTO_APPLY, self::TEST_THEN_APPLY ), true );
	}

	/**
	 * Array form.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'action'     => $this->action,
			'summary'    => $this->summary,
			'confidence' => $this->confidence,
			'risk'       => $this->risk,
			'benefit'    => $this->benefit,
			'reasons'    => array_values( array_unique( $this->reasons ) ),
		);
	}
}
