<?php
/**
 * Slow down WordPress Heartbeat on the frontend.
 *
 * Some plugins load Heartbeat on public pages for logged-in visitors, which
 * sends a request to admin-ajax.php every 15–60 seconds. On the frontend the
 * interval is raised to 120 seconds. The dashboard, the post editor and page
 * builders keep their normal interval, so autosave, post locking and
 * WooCommerce admin work exactly as before.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\Heartbeat;

use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Frontend Heartbeat.
 */
class HeartbeatOptimization extends AbstractOptimization {

	/**
	 * Frontend interval in seconds (the maximum Heartbeat accepts by default).
	 */
	public const INTERVAL = 120;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'heartbeat_frontend';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Reduce background requests on the frontend', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Makes the WordPress "Heartbeat" check in every two minutes instead of every few seconds on public pages. The dashboard and editors are not affected.', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::CLEANUP;
	}

	/**
	 * {@inheritDoc}
	 */
	public function risk(): string {
		return Risk::SAFE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function level(): string {
		return Risk::LEVEL_SAFE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function safe_mode_compatible(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param AssessmentContext $context Scan data.
	 */
	public function assess( AssessmentContext $context ): Assessment {
		$assessment = Assessment::make( true, 95, Assessment::BENEFIT_LOW );
		$assessment->note( __( 'Heartbeat mostly runs for logged-in visitors; the dashboard and editors keep their normal interval.', 'sh-speed-optimizer' ) );
		return $this->finalize( $assessment, $context, 'heartbeat' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Runtime $runtime Runtime.
	 */
	public function register_runtime( Runtime $runtime ): void {
		if ( ! $this->plugin->context()->is_frontend_request() ) {
			return;
		}

		add_filter(
			'heartbeat_settings',
			function ( $settings ) use ( $runtime ) {
				if ( ! is_array( $settings ) || is_admin() ) {
					return $settings;
				}
				if ( $this->plugin->context()->is_editor_preview() ) {
					return $settings;
				}
				if ( null !== $runtime->rules()->disabled_reason( $this->id() ) ) {
					return $settings;
				}
				if ( did_action( 'wp' ) && ! $runtime->is_active_on_page( $this->id() ) ) {
					return $settings;
				}
				return self::apply_interval( $settings, self::INTERVAL );
			},
			99
		);
	}

	/**
	 * Raise the Heartbeat interval (a longer interval set by someone else is kept).
	 *
	 * @param array<string,mixed> $settings Heartbeat settings.
	 * @param int                 $interval Seconds.
	 * @return array<string,mixed>
	 */
	public static function apply_interval( array $settings, int $interval ): array {
		$current              = isset( $settings['interval'] ) ? (int) $settings['interval'] : 0;
		$settings['interval'] = max( $current, $interval );
		return $settings;
	}
}
