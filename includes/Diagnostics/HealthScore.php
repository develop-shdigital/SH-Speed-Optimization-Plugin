<?php
/**
 * SH Performance Health score.
 *
 * Derived only from the plugin's own diagnostics (findings). It is NOT a
 * Google PageSpeed score and is never labelled as one.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Health score calculator.
 */
final class HealthScore {

	/**
	 * Points deducted per finding severity.
	 */
	private const PENALTY = array(
		'critical' => 15,
		'warning'  => 8,
		'notice'   => 3,
		'info'     => 0,
		'good'     => 0,
	);

	/**
	 * Maximum deduction per category, so one area cannot dominate.
	 */
	private const CATEGORY_CAP = 30;

	/**
	 * Calculate score and status.
	 *
	 * @param array<int,array<string,mixed>> $findings Findings.
	 * @return array{score:int|null,status:string,label:string,improvements:int}
	 */
	public static function calculate( array $findings ): array {
		if ( empty( $findings ) ) {
			return array(
				'score'        => null,
				'status'       => 'unknown',
				'label'        => __( 'Not analyzed yet', 'sh-speed-optimizer' ),
				'improvements' => 0,
			);
		}

		$per_category = array();
		$improvements = 0;

		foreach ( $findings as $finding ) {
			$severity = (string) ( $finding['severity'] ?? 'info' );
			$category = (string) ( $finding['category'] ?? 'other' );
			$penalty  = self::PENALTY[ $severity ] ?? 0;

			$per_category[ $category ] = min( self::CATEGORY_CAP, ( $per_category[ $category ] ?? 0 ) + $penalty );

			if ( in_array( $severity, array( 'notice', 'warning', 'critical' ), true ) ) {
				++$improvements;
			}
		}

		$score  = max( 0, min( 100, 100 - (int) array_sum( $per_category ) ) );
		$status = self::status( $score );

		return array(
			'score'        => $score,
			'status'       => $status,
			'label'        => self::label( $status ),
			'improvements' => $improvements,
		);
	}

	/**
	 * Status for a score.
	 *
	 * @param int $score Score.
	 */
	public static function status( int $score ): string {
		if ( $score >= 90 ) {
			return 'excellent';
		}
		if ( $score >= 75 ) {
			return 'good';
		}
		if ( $score >= 50 ) {
			return 'attention';
		}
		return 'required';
	}

	/**
	 * Translated status label.
	 *
	 * @param string $status Status.
	 */
	public static function label( string $status ): string {
		switch ( $status ) {
			case 'excellent':
				return __( 'Excellent', 'sh-speed-optimizer' );
			case 'good':
				return __( 'Good', 'sh-speed-optimizer' );
			case 'attention':
				return __( 'Needs Attention', 'sh-speed-optimizer' );
			case 'required':
				return __( 'Optimization Required', 'sh-speed-optimizer' );
			default:
				return __( 'Not analyzed yet', 'sh-speed-optimizer' );
		}
	}
}
