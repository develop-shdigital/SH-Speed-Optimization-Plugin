<?php
/**
 * Risk and level vocabulary.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Optimization;

defined( 'ABSPATH' ) || exit;

/**
 * Safety levels.
 *
 * Risk describes how likely an optimization is to break something.
 * Level describes when the engine may enable it:
 *  - safe:         applied automatically.
 *  - smart:        applied automatically only after compatibility analysis and verification.
 *  - experimental: never applied automatically ("disabled by default").
 */
final class Risk {

	public const SAFE     = 'safe';
	public const LOW      = 'low';
	public const MODERATE = 'moderate';
	public const HIGH     = 'high';

	public const LEVEL_SAFE         = 'safe';
	public const LEVEL_SMART        = 'smart';
	public const LEVEL_EXPERIMENTAL = 'experimental';

	/**
	 * Numeric weight used by the decision engine (0 = no risk).
	 *
	 * @param string $risk Risk.
	 */
	public static function weight( string $risk ): int {
		return array(
			self::SAFE     => 5,
			self::LOW      => 20,
			self::MODERATE => 50,
			self::HIGH     => 80,
		)[ $risk ] ?? 80;
	}

	/**
	 * Translated risk label.
	 *
	 * @param string $risk Risk.
	 */
	public static function label( string $risk ): string {
		switch ( $risk ) {
			case self::SAFE:
				return __( 'Safe', 'sh-speed-optimizer' );
			case self::LOW:
				return __( 'Low risk', 'sh-speed-optimizer' );
			case self::MODERATE:
				return __( 'Moderate risk', 'sh-speed-optimizer' );
			default:
				return __( 'High risk', 'sh-speed-optimizer' );
		}
	}

	/**
	 * Translated level label.
	 *
	 * @param string $level Level.
	 */
	public static function level_label( string $level ): string {
		switch ( $level ) {
			case self::LEVEL_SAFE:
				return __( 'Safe', 'sh-speed-optimizer' );
			case self::LEVEL_SMART:
				return __( 'Smart', 'sh-speed-optimizer' );
			default:
				return __( 'Experimental', 'sh-speed-optimizer' );
		}
	}
}
