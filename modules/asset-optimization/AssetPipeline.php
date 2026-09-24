<?php
/**
 * Shared runtime wiring for optimized CSS/JS copies.
 *
 * Every optimization that needs asset copies (minification, or any optimization
 * that registered a CSS/JS content transform through Runtime::add_css_transform()/
 * add_js_transform()) calls AssetPipeline::register( $runtime ). It is idempotent.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\AssetOptimization;

use SH\SpeedOptimizer\Assets\AssetCopies;
use SH\SpeedOptimizer\Assets\AssetRewriter;
use SH\SpeedOptimizer\Core\Scheduler;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Asset pipeline registration.
 */
final class AssetPipeline {

	/**
	 * Runtimes already wired (spl object ids).
	 *
	 * @var array<int,bool>
	 */
	private static array $registered = array();

	/**
	 * Register the tag rewriter (HTML transforms at priority 40/50), the background
	 * generation worker, daily garbage collection and the cron hook list entry.
	 *
	 * @param Runtime $runtime Runtime.
	 */
	public static function register( Runtime $runtime ): void {
		$key = spl_object_id( $runtime );
		if ( isset( self::$registered[ $key ] ) ) {
			return;
		}
		self::$registered[ $key ] = true;

		AssetRewriter::register( $runtime );

		add_action( AssetCopies::CRON_HOOK, array( self::class, 'run_queue' ) );
		add_action( Scheduler::DAILY_HOOK, array( self::class, 'collect_garbage' ) );
		add_filter( 'shso_cron_hooks', array( self::class, 'cron_hooks' ) );
	}

	/**
	 * Cron: generate queued copies.
	 */
	public static function run_queue(): void {
		try {
			AssetCopies::instance()->run_queue();
		} catch ( \Throwable $e ) {
			unset( $e ); // Background work must never break cron.
		}
	}

	/**
	 * Cron: delete copies that no page used for a long time.
	 */
	public static function collect_garbage(): void {
		try {
			AssetCopies::instance()->garbage_collect();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Add our cron hook to the list cleared on deactivation.
	 *
	 * @param mixed $hooks Hooks.
	 * @return string[]
	 */
	public static function cron_hooks( $hooks ): array {
		$hooks   = is_array( $hooks ) ? $hooks : array();
		$hooks[] = AssetCopies::CRON_HOOK;
		return $hooks;
	}

	/**
	 * Forget registrations (tests).
	 */
	public static function reset(): void {
		self::$registered = array();
	}
}
