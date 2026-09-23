<?php
/**
 * Background scheduling (WP-Cron, or Action Scheduler when available).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Scheduler.
 */
final class Scheduler {

	public const HOURLY_HOOK = 'shso_cron_hourly';
	public const DAILY_HOOK  = 'shso_cron_daily';
	public const JOB_HOOK    = 'shso_job_tick';
	public const GROUP       = 'sh-speed-optimizer';

	/**
	 * Ensure recurring events exist.
	 */
	public static function schedule_recurring(): void {
		if ( ! wp_next_scheduled( self::HOURLY_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOURLY_HOOK );
		}
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * Remove every event of this plugin.
	 */
	public static function unschedule_all(): void {
		foreach ( self::hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), self::GROUP );
			}
		}
	}

	/**
	 * All hooks used by the plugin (modules add theirs through the filter).
	 *
	 * @return string[]
	 */
	public static function hooks(): array {
		/**
		 * Filters the list of cron hooks owned by SH Speed Optimizer (cleared on deactivation).
		 *
		 * @param string[] $hooks Hooks.
		 */
		return array_unique( (array) apply_filters( 'shso_cron_hooks', array( self::HOURLY_HOOK, self::DAILY_HOOK, self::JOB_HOOK ) ) );
	}

	/**
	 * Schedule a one-off background action (deduplicated).
	 *
	 * Uses Action Scheduler when it is loaded (more reliable on busy sites),
	 * otherwise a single WP-Cron event.
	 *
	 * @param string       $hook  Hook.
	 * @param array<mixed> $args  Arguments.
	 * @param int          $delay Delay in seconds.
	 */
	public static function async( string $hook, array $args = array(), int $delay = 0 ): void {
		if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_has_scheduled_action' ) && did_action( 'action_scheduler_init' ) ) {
			if ( ! as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
				as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP );
			}
			return;
		}

		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( time() + $delay, $hook, $args );
		}
	}

	/**
	 * Whether a one-off action is pending.
	 *
	 * @param string       $hook Hook.
	 * @param array<mixed> $args Arguments.
	 */
	public static function is_scheduled( string $hook, array $args = array() ): bool {
		if ( function_exists( 'as_has_scheduled_action' ) && did_action( 'action_scheduler_init' ) && as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
			return true;
		}
		return false !== wp_next_scheduled( $hook, $args );
	}
}
