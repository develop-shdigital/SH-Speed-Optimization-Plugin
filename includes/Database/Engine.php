<?php
/**
 * Database engine capabilities.
 *
 * Answers "which storage engine do the tables use, how big are they and may
 * we use transactions?" without ever failing: MySQL-only statements are
 * guarded, and on the SQLite integration drop-in (or any failure) the data is
 * reported as unavailable instead of guessed.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Engine helper.
 */
final class Engine {

	/**
	 * Database object (`$wpdb`).
	 *
	 * @var object
	 */
	private object $wpdb;

	/**
	 * Forced SQLite detection (tests), null = detect.
	 *
	 * @var bool|null
	 */
	private ?bool $sqlite;

	/**
	 * Cached table status (false = not loaded yet, null = unavailable).
	 *
	 * @var array<string,array<string,mixed>>|null|false
	 */
	private $status = false;

	/**
	 * Constructor.
	 *
	 * @param object    $wpdb   Database object.
	 * @param bool|null $sqlite Force SQLite detection (null = detect).
	 */
	public function __construct( object $wpdb, ?bool $sqlite = null ) {
		$this->wpdb   = $wpdb;
		$this->sqlite = $sqlite;
	}

	/**
	 * Database object.
	 */
	public function wpdb(): object {
		return $this->wpdb;
	}

	/**
	 * Whether the site runs on the SQLite database integration.
	 */
	public function is_sqlite(): bool {
		if ( null !== $this->sqlite ) {
			return $this->sqlite;
		}
		if ( defined( 'DB_ENGINE' ) && 'sqlite' === strtolower( (string) constant( 'DB_ENGINE' ) ) ) {
			return true;
		}
		if ( defined( 'SQLITE_DB_DROPIN_VERSION' ) || defined( 'SQLITE_DRIVER_VERSION' ) ) {
			return true;
		}
		return class_exists( '\WP_SQLite_DB', false ) && $this->wpdb instanceof \WP_SQLite_DB;
	}

	/**
	 * Status of this site's tables: name => engine, rows, data, index, free.
	 *
	 * Null when the information is unavailable (SQLite, missing privileges …).
	 *
	 * @return array<string,array{engine:string,rows:int,data:int,index:int,free:int}>|null
	 */
	public function table_status(): ?array {
		if ( false !== $this->status ) {
			return $this->status;
		}

		$this->status = null;
		if ( $this->is_sqlite() || empty( $this->wpdb->prefix ) || ! method_exists( $this->wpdb, 'get_results' ) ) {
			return null;
		}

		$prefix = (string) $this->wpdb->prefix;
		$like   = $this->esc_like( $prefix ) . '%';
		$wpdb   = $this->wpdb;
		$rows   = $this->quiet(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Prepared; guarded MySQL-only statement.
			static fn() => $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $like ), ARRAY_A )
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return null;
		}

		$status = self::parse_table_status( $rows, $prefix, $this->is_main_site_prefix() );
		if ( empty( $status ) ) {
			return null;
		}

		$this->status = $status;
		return $status;
	}

	/**
	 * Normalize SHOW TABLE STATUS rows and keep only this site's tables.
	 *
	 * On the main site of a network the prefix ("wp_") also matches the
	 * tables of every other site ("wp_2_posts"); those are excluded.
	 *
	 * @param array<int,array<string,mixed>> $rows         Raw rows.
	 * @param string                         $prefix       Table prefix of this site.
	 * @param bool                           $exclude_subs Exclude "{prefix}{n}_" tables.
	 * @return array<string,array{engine:string,rows:int,data:int,index:int,free:int}>
	 */
	public static function parse_table_status( array $rows, string $prefix, bool $exclude_subs ): array {
		$status = array();
		foreach ( $rows as $row ) {
			$row  = array_change_key_case( (array) $row, CASE_LOWER );
			$name = (string) ( $row['name'] ?? '' );
			if ( '' === $name || 0 !== strpos( $name, $prefix ) ) {
				continue;
			}
			if ( $exclude_subs && preg_match( '/^[0-9]+_/', substr( $name, strlen( $prefix ) ) ) ) {
				continue;
			}
			$status[ $name ] = array(
				'engine' => (string) ( $row['engine'] ?? '' ),
				'rows'   => (int) ( $row['rows'] ?? 0 ),
				'data'   => (int) ( $row['data_length'] ?? 0 ),
				'index'  => (int) ( $row['index_length'] ?? 0 ),
				'free'   => (int) ( $row['data_free'] ?? 0 ),
			);
		}
		return $status;
	}

	/**
	 * Storage engine of the given physical tables (null when unknown).
	 *
	 * @param string[] $tables Physical table names.
	 * @return array<string,string|null>
	 */
	public function engines_for( array $tables ): array {
		$status  = $this->table_status();
		$engines = array();
		foreach ( $tables as $table ) {
			$engine            = $status[ $table ]['engine'] ?? '';
			$engines[ $table ] = '' === $engine ? null : $engine;
		}
		return $engines;
	}

	/**
	 * Whether all given tables support transactions (InnoDB on MySQL/MariaDB).
	 *
	 * SQLite runs every statement atomically but explicit transactions are
	 * not used there (the integration drop-in manages its own); unknown
	 * engines never get transactions.
	 *
	 * @param string[] $tables Physical table names.
	 */
	public function supports_transactions( array $tables ): bool {
		if ( $this->is_sqlite() || empty( $tables ) ) {
			return false;
		}
		foreach ( $this->engines_for( $tables ) as $engine ) {
			if ( null === $engine || 'innodb' !== strtolower( $engine ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Start a transaction.
	 */
	public function begin(): bool {
		return false !== $this->quiet( fn() => $this->wpdb->query( 'START TRANSACTION' ) );
	}

	/**
	 * Commit the transaction.
	 */
	public function commit(): bool {
		return false !== $this->quiet( fn() => $this->wpdb->query( 'COMMIT' ) );
	}

	/**
	 * Roll the transaction back.
	 */
	public function rollback(): bool {
		return false !== $this->quiet( fn() => $this->wpdb->query( 'ROLLBACK' ) );
	}

	/**
	 * Run a query callback with database errors suppressed (restores the previous state).
	 *
	 * @param callable $callback Callback.
	 * @return mixed Callback result, false on exception.
	 */
	public function quiet( callable $callback ) {
		$previous = method_exists( $this->wpdb, 'suppress_errors' ) ? $this->wpdb->suppress_errors( true ) : null;
		try {
			$result = $callback();
		} catch ( \Throwable $e ) {
			$result = false;
		}
		if ( null !== $previous ) {
			$this->wpdb->suppress_errors( $previous );
		}
		return $result;
	}

	/**
	 * Escape a LIKE pattern.
	 *
	 * @param string $text Text.
	 */
	public function esc_like( string $text ): string {
		if ( method_exists( $this->wpdb, 'esc_like' ) ) {
			return (string) $this->wpdb->esc_like( $text );
		}
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * Whether this site's prefix is the base prefix of a network (main site).
	 */
	private function is_main_site_prefix(): bool {
		return function_exists( 'is_multisite' ) && is_multisite()
			&& isset( $this->wpdb->base_prefix ) && $this->wpdb->base_prefix === $this->wpdb->prefix;
	}
}
