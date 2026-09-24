<?php
/**
 * Database diagnostics, safe cleanup and restore.
 *
 * Nothing in the database is ever deleted automatically: the cleanup job
 * (`db_clean`) only runs when the administrator starts it for selected
 * items, and every removed row is backed up first so it can be restored.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Database;

use SH\SpeedOptimizer\Core\Jobs\JobHandlerInterface;
use SH\SpeedOptimizer\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Database service (lazily created by {@see Plugin::database()}).
 */
final class DatabaseService {

	/**
	 * Default number of days backups are kept.
	 */
	public const RETENTION_DAYS = 30;

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Engine helper.
	 *
	 * @var Engine|null
	 */
	private ?Engine $engine = null;

	/**
	 * Backup store.
	 *
	 * @var BackupStore|null
	 */
	private ?BackupStore $store = null;

	/**
	 * Cleanup job handler.
	 *
	 * @var Cleaner|null
	 */
	private ?Cleaner $cleaner = null;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Analyze the database (counts and sizes; no rows are loaded).
	 *
	 * @return array<string,mixed> See {@see Analyzer::analyze()}.
	 */
	public function analyze(): array {
		return $this->analyzer( true )->analyze( Cleaner::DEFAULT_KEEP );
	}

	/**
	 * Findings for an analysis.
	 *
	 * @param array<string,mixed> $analysis Result of {@see analyze()}.
	 * @return array<int,array<string,mixed>>
	 */
	public function findings( array $analysis ): array {
		return Findings::build( $analysis );
	}

	/**
	 * Handler of the `db_clean` job.
	 */
	public function job_handler(): JobHandlerInterface {
		if ( null === $this->cleaner ) {
			$this->cleaner = new Cleaner( $this->plugin, $this );
		}
		return $this->cleaner;
	}

	/**
	 * Backups, newest first.
	 *
	 * @return array<int,array{id:string,created:int,items:string[],rows:int,bytes:int,restorable:bool,status:string,restored_at:int,reason:string}>
	 */
	public function backups(): array {
		$list = array();
		foreach ( $this->store()->all() as $manifest ) {
			$reason = $this->unrestorable_reason( $manifest );
			$list[] = array(
				'id'          => (string) $manifest['id'],
				'created'     => (int) $manifest['created'],
				'items'       => array_values( array_intersect( (array) $manifest['items'], Cleaner::ITEMS ) ),
				'rows'        => (int) $manifest['rows'],
				'bytes'       => (int) $manifest['bytes'],
				'restorable'  => '' === $reason,
				'status'      => (string) ( $manifest['status'] ?? '' ),
				'restored_at' => (int) $manifest['restored_at'],
				'reason'      => $reason,
			);
		}
		return $list;
	}

	/**
	 * Restore a backup.
	 *
	 * @param string $backup_id Backup id.
	 * @return array<string,mixed>|\WP_Error Counts per table.
	 */
	public function restore( string $backup_id ) {
		if ( ! BackupStore::is_valid_id( $backup_id ) ) {
			return new \WP_Error( 'shso_backup_invalid_id', __( 'This backup does not exist.', 'sh-speed-optimizer' ) );
		}
		if ( $this->in_use( $backup_id ) ) {
			return new \WP_Error( 'shso_backup_in_use', __( 'A database cleanup is still running. Please wait until it has finished.', 'sh-speed-optimizer' ) );
		}

		global $wpdb;
		$restorer = new Restorer( $wpdb, $this->store(), new Engine( $wpdb ), self::site_hash(), self::is_multisite() );
		$result   = $restorer->restore( $backup_id );

		if ( is_wp_error( $result ) ) {
			$this->plugin->logger()->error(
				'Database backup could not be restored.',
				array(
					'backup_id' => $backup_id,
					'error'     => $result->get_error_code(),
				),
				'database'
			);
			return $result;
		}

		$message = sprintf(
			/* translators: %s: number of entries */
			_n( 'Database backup restored: %s entry was put back.', 'Database backup restored: %s entries were put back.', (int) $result['total_inserted'], 'sh-speed-optimizer' ),
			Findings::number( (int) $result['total_inserted'] )
		);
		$this->plugin->logger()->event(
			'db_restored',
			$message,
			'',
			array(
				'backup_id' => $backup_id,
				'inserted'  => $result['total_inserted'],
				'skipped'   => $result['total_skipped'],
				'failed'    => $result['total_failed'],
			)
		);

		/**
		 * Fires after a database backup was restored.
		 *
		 * @param array<string,mixed> $result    Counts (inserted/skipped/failed per table).
		 * @param string              $backup_id Backup id.
		 */
		do_action( 'shso_db_restored', $result, $backup_id );

		return $result;
	}

	/**
	 * Delete a backup (refused while a running cleanup writes to it).
	 *
	 * @param string $backup_id Backup id.
	 */
	public function delete_backup( string $backup_id ): bool {
		if ( ! BackupStore::is_valid_id( $backup_id ) || $this->in_use( $backup_id ) ) {
			return false;
		}
		return $this->store()->delete( $backup_id );
	}

	/**
	 * Daily maintenance (called by the core from the daily cron): delete
	 * backups older than the retention period and tidy interrupted ones.
	 */
	public function daily_maintenance(): void {
		/**
		 * Filters how many days database cleanup backups are kept.
		 *
		 * @param int $days Days (default 30, minimum 1).
		 */
		$days = max( 1, (int) apply_filters( 'shso_db_backup_retention_days', self::RETENTION_DAYS ) );

		$deleted = $this->store()->prune( $days * DAY_IN_SECONDS, fn( string $id ): bool => $this->in_use( $id ) );
		if ( $deleted > 0 ) {
			$this->plugin->logger()->debug( 'Old database backups deleted.', array( 'count' => $deleted ), 'database' );
		}
	}

	/**
	 * Analyzer.
	 *
	 * @param bool $fresh Use fresh (uncached) table status.
	 */
	public function analyzer( bool $fresh = false ): Analyzer {
		global $wpdb;
		return new Analyzer( $wpdb, $fresh ? new Engine( $wpdb ) : $this->engine() );
	}

	/**
	 * Engine helper (table status cached for this request).
	 */
	public function engine(): Engine {
		if ( null === $this->engine ) {
			global $wpdb;
			$this->engine = new Engine( $wpdb );
		}
		return $this->engine;
	}

	/**
	 * Backup store of the current site.
	 */
	public function store(): BackupStore {
		if ( null === $this->store ) {
			global $wpdb;
			$this->store = new BackupStore(
				$this->plugin->filesystem(),
				self::site_hash(),
				(string) $wpdb->prefix,
				self::is_multisite(),
				function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1
			);
		}
		return $this->store;
	}

	/**
	 * Hash identifying this site: home URL without scheme plus table prefix.
	 */
	public static function site_hash(): string {
		global $wpdb;
		$home = function_exists( 'get_home_url' ) ? get_home_url() : home_url();
		$home = strtolower( untrailingslashit( (string) preg_replace( '#^[a-z]+://#i', '', (string) $home ) ) );
		return hash( 'sha256', $home . '|' . (string) ( $wpdb->prefix ?? '' ) );
	}

	/**
	 * Why a backup cannot be restored ('' when it can).
	 *
	 * @param array<string,mixed> $manifest Manifest.
	 */
	private function unrestorable_reason( array $manifest ): string {
		global $wpdb;

		$valid = BackupStore::validate_manifest( $manifest, self::site_hash(), (string) $wpdb->prefix, self::is_multisite() );
		if ( is_wp_error( $valid ) ) {
			return $valid->get_error_message();
		}
		if ( $this->in_use( (string) $manifest['id'] ) ) {
			return __( 'A database cleanup is still writing this backup.', 'sh-speed-optimizer' );
		}
		if ( 0 === (int) $manifest['rows'] ) {
			return __( 'This backup contains no data.', 'sh-speed-optimizer' );
		}
		$dir = (string) $this->store()->dir( (string) $manifest['id'] );
		foreach ( (array) $manifest['parts'] as $part ) {
			if ( ! is_file( $dir . (string) $part['file'] ) ) {
				return __( 'A part of this backup is missing, so it cannot be restored.', 'sh-speed-optimizer' );
			}
		}
		return '';
	}

	/**
	 * Whether a running cleanup job writes to this backup.
	 *
	 * @param string $backup_id Backup id.
	 */
	private function in_use( string $backup_id ): bool {
		try {
			$job = $this->plugin->jobs()->current();
		} catch ( \Throwable $e ) {
			return false;
		}
		if ( null === $job || ! $job->is_active() || 'db_clean' !== $job->type() || $backup_id !== (string) $job->get( 'backup_id', '' ) ) {
			return false;
		}
		// A job that stopped responding (the job manager cancels those as stale) no longer holds its backup.
		return time() - (int) ( $job->to_array()['updated'] ?? 0 ) < 30 * MINUTE_IN_SECONDS;
	}

	/**
	 * Multisite.
	 */
	private static function is_multisite(): bool {
		return function_exists( 'is_multisite' ) && is_multisite();
	}
}
