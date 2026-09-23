<?php
/**
 * WP-CLI commands: `wp shso …`.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Core;

use SH\SpeedOptimizer\Diagnostics\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Manage SH Speed Optimizer from the command line.
 */
final class Cli {

	/**
	 * Show status: health, active optimizations, safe mode, cache.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shso status
	 *
	 * @when after_wp_load
	 */
	public function status(): void {
		$plugin = Plugin::instance();
		$scan   = Scanner::last();
		$health = $scan['health'] ?? array(
			'score' => null,
			'label' => 'Not analyzed yet',
		);

		\WP_CLI::line( 'SH Performance Health: ' . ( null === $health['score'] ? 'n/a' : $health['score'] . '/100' ) . ' (' . $health['label'] . ')' );
		\WP_CLI::line( 'Safe Mode: ' . ( $plugin->context()->is_safe_mode() ? 'on' : 'off' ) . ( Context::is_emergency_safe_mode() ? ' (emergency constant)' : '' ) );
		\WP_CLI::line( 'Active optimizations: ' . implode( ', ', $plugin->state()->active_ids() ) );

		try {
			$cache = $plugin->cache()->status();
			\WP_CLI::line( 'Page cache: ' . ( ! empty( $cache['page_cache']['active'] ) ? 'active (' . $cache['page_cache']['mode'] . ')' : 'inactive' ) );
		} catch ( \Throwable $e ) {
			\WP_CLI::line( 'Page cache: unknown' );
		}
	}

	/**
	 * Run a performance scan (server-side only).
	 *
	 * ## EXAMPLES
	 *
	 *     wp shso scan
	 *
	 * @when after_wp_load
	 */
	public function scan(): void {
		$this->run_job( 'scan', array( 'browser' => false ) );
	}

	/**
	 * Scan and apply optimizations that pass server-side verification.
	 * Optimizations that need a browser test are skipped (run them from the dashboard).
	 *
	 * ## EXAMPLES
	 *
	 *     wp shso optimize
	 *
	 * @when after_wp_load
	 */
	public function optimize(): void {
		$this->run_job( 'optimize', array( 'browser' => false ) );
	}

	/**
	 * Purge the page cache.
	 *
	 * ## OPTIONS
	 *
	 * [--url=<url>]
	 * : Purge only this URL.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args  Positional args.
	 * @param array<string,string> $assoc Options.
	 */
	public function purge( array $args, array $assoc ): void {
		unset( $args );
		$cache = Plugin::instance()->cache();
		$count = isset( $assoc['url'] ) ? $cache->purge_url( (string) $assoc['url'] ) : $cache->purge_all( 'cli' );
		\WP_CLI::success( sprintf( 'Purged %d cached files.', $count ) );
	}

	/**
	 * Turn Safe Mode on or off.
	 *
	 * ## OPTIONS
	 *
	 * <state>
	 * : on or off.
	 *
	 * @subcommand safe-mode
	 * @when after_wp_load
	 *
	 * @param array<int,string> $args Positional args.
	 */
	public function safe_mode( array $args ): void {
		$on = 'on' === ( $args[0] ?? '' );
		Plugin::instance()->settings()->update( array( 'safe_mode' => $on ) );
		\WP_CLI::success( 'Safe Mode ' . ( $on ? 'enabled' : 'disabled' ) . '.' );
	}

	/**
	 * Undo the last optimization change.
	 *
	 * @when after_wp_load
	 */
	public function undo(): void {
		$result = Plugin::instance()->snapshots()->undo();
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( 'Restored the previous configuration.' );
	}

	/**
	 * Turn off every optimization and remove all side effects (drop-in, .htaccess rules, generated files).
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * @subcommand reset
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args  Positional args.
	 * @param array<string,string> $assoc Options.
	 */
	public function reset( array $args, array $assoc ): void {
		unset( $args );
		\WP_CLI::confirm( 'Turn off all optimizations?', $assoc );
		$plugin = Plugin::instance();
		$plugin->snapshots()->create( 'Before reset via WP-CLI', 'manual' );
		foreach ( $plugin->state()->active_ids() as $id ) {
			$plugin->engine()->rollback( $id, 'Reset via WP-CLI.', 'manual_off', false, null );
		}
		$plugin->engine()->on_configuration_changed( 'cli_reset' );
		\WP_CLI::success( 'All optimizations are off.' );
	}

	/**
	 * Run a job to completion.
	 *
	 * @param string              $type Type.
	 * @param array<string,mixed> $args Args.
	 */
	private function run_job( string $type, array $args ): void {
		$plugin = Plugin::instance();
		$job    = $plugin->jobs()->start( $type, $args );
		if ( is_wp_error( $job ) ) {
			\WP_CLI::error( $job->get_error_message() );
		}

		$last = '';
		do {
			$job = $plugin->jobs()->run( 30.0 );
			if ( null === $job ) {
				break;
			}
			$data = $job->to_public();
			if ( $data['label'] !== $last ) {
				$last = (string) $data['label'];
				\WP_CLI::line( sprintf( '[%3d%%] %s', $data['progress'], $last ) );
			}
		} while ( $job->is_active() );

		if ( null === $job ) {
			\WP_CLI::error( 'The task disappeared.' );
		}

		foreach ( (array) $job->to_public()['messages'] as $message ) {
			\WP_CLI::line( '  ' . $message['text'] );
		}

		if ( 'done' !== $job->status() ) {
			\WP_CLI::error( 'Task ended with status ' . $job->status() . '.' );
		}
		\WP_CLI::success( 'Done.' );
	}
}
