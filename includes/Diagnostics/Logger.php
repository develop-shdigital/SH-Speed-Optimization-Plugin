<?php
/**
 * Lightweight logger.
 *
 * Two kinds of entries share one table ({prefix}shso_log):
 *  - "event": optimization history (applied, rolled back, verification failed …). Always recorded.
 *  - "debug": detailed decisions. Only recorded when debug logging is on (setting or SHSO_DEBUG).
 *
 * Context data is scrubbed of anything that looks like a secret or personal data
 * and the table is capped in size.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Logger service.
 */
final class Logger {

	public const MAX_ROWS = 2000;

	/**
	 * Whether debug entries are recorded.
	 *
	 * @var bool
	 */
	private bool $debug;

	/**
	 * Constructor.
	 *
	 * @param bool $debug Debug logging enabled.
	 */
	public function __construct( bool $debug ) {
		$this->debug = $debug;
	}

	/**
	 * Table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'shso_log';
	}

	/**
	 * Record a history event.
	 *
	 * @param string              $event           Event code (applied, rolled_back, verification_failed, excluded, manual_on, manual_off, restored, error …).
	 * @param string              $message         Human readable, translated message.
	 * @param string              $optimization_id Related optimization id.
	 * @param array<string,mixed> $context         Extra data.
	 * @param string              $level           info|warning|error.
	 */
	public function event( string $event, string $message, string $optimization_id = '', array $context = array(), string $level = 'info' ): void {
		$this->insert( 'event', $level, $event, $message, $optimization_id, $context );
	}

	/**
	 * Record a debug entry (no-op unless debug logging is enabled).
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Extra data.
	 * @param string              $channel Channel (cache, engine, compat, assets …).
	 */
	public function debug( string $message, array $context = array(), string $channel = 'general' ): void {
		if ( $this->debug ) {
			$this->insert( 'debug', 'debug', $channel, $message, '', $context );
		}
	}

	/**
	 * Record an error. Errors are always recorded.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Extra data.
	 * @param string              $channel Channel.
	 */
	public function error( string $message, array $context = array(), string $channel = 'general' ): void {
		$this->insert( 'debug', 'error', $channel, $message, '', $context );
	}

	/**
	 * Whether debug logging is active.
	 */
	public function is_debug(): bool {
		return $this->debug;
	}

	/**
	 * Query entries.
	 *
	 * @param string $type  event|debug|all.
	 * @param int    $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function entries( string $type = 'event', int $limit = 100 ): array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		$table = self::table();
		$limit = max( 1, min( 500, $limit ) );

		if ( 'all' === $type ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s ORDER BY id DESC LIMIT %d", $type, $limit ), ARRAY_A );
		}

		foreach ( (array) $rows as &$row ) {
			$row['context'] = json_decode( (string) $row['context'], true ) ?: array();
		}

		return (array) $rows;
	}

	/**
	 * Delete all debug entries.
	 */
	public function clear_debug(): void {
		global $wpdb;
		if ( self::table_exists() ) {
			$wpdb->delete( self::table(), array( 'type' => 'debug' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Keep the table bounded (called from cron).
	 */
	public function prune(): void {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return;
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$threshold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", self::MAX_ROWS ) );
		if ( $threshold > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $threshold ) );
		}

		// Debug entries are only useful for a week.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE type = 'debug' AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) ) );
	}

	/**
	 * Remove secrets and personal data from context arrays.
	 *
	 * @param array<string,mixed> $context Context.
	 * @param int                 $depth   Recursion depth.
	 * @return array<string,mixed>
	 */
	public static function scrub( array $context, int $depth = 0 ): array {
		$clean = array();

		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && preg_match( '/pass|pwd|secret|token|nonce|auth|cookie|session|api_?key|private_?key|license|card|cvc|iban|email|phone|address|signature/i', $key ) ) {
				$clean[ $key ] = '[redacted]';
				continue;
			}
			if ( is_array( $value ) ) {
				$clean[ $key ] = $depth < 4 ? self::scrub( $value, $depth + 1 ) : '[…]';
			} elseif ( is_scalar( $value ) || null === $value ) {
				$string        = (string) $value;
				$string        = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $string );
				$string        = preg_replace( '/([?&](?:[^=&]*(?:token|key|nonce|pass|sig|auth|shso_verify)[^=&]*)=)[^&\s"]+/i', '$1[redacted]', (string) $string );
				$clean[ $key ] = is_string( $value ) ? mb_substr( (string) $string, 0, 1000 ) : $value;
			} else {
				$clean[ $key ] = '[' . gettype( $value ) . ']';
			}
		}

		return $clean;
	}

	/**
	 * Insert a row.
	 *
	 * @param string              $type            event|debug.
	 * @param string              $level           Level.
	 * @param string              $event           Event/channel.
	 * @param string              $message         Message.
	 * @param string              $optimization_id Optimization id.
	 * @param array<string,mixed> $context         Context.
	 */
	private function insert( string $type, string $level, string $event, string $message, string $optimization_id, array $context ): void {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
				'type'            => $type,
				'level'           => substr( $level, 0, 10 ),
				'event'           => substr( sanitize_key( $event ), 0, 40 ),
				'optimization_id' => substr( sanitize_key( $optimization_id ), 0, 64 ),
				'message'         => mb_substr( wp_strip_all_tags( $message ), 0, 500 ),
				'context'         => (string) wp_json_encode( self::scrub( $context ) ),
				'user_id'         => get_current_user_id(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
	}

	/**
	 * Whether the log table exists (cached per request).
	 */
	private static function table_exists(): bool {
		static $exists = null;
		if ( null === $exists ) {
			$exists = (int) get_option( 'shso_db_version', 0 ) >= 1;
		}
		return $exists;
	}
}
