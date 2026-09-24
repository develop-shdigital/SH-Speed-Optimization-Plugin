<?php
/**
 * Restores rows from a cleanup backup.
 *
 * Safety rules:
 *  - the manifest must match the format version, this site's URL hash and
 *    table prefix;
 *  - every record is validated before anything is written: only whitelisted
 *    core tables and core columns, numeric primary keys;
 *  - rows whose primary key exists again are skipped (never overwritten);
 *  - on InnoDB everything runs in one transaction and any failure rolls the
 *    whole restore back.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Backup restorer.
 */
final class Restorer {

	/**
	 * Ids per IN() list.
	 */
	private const CHUNK = 200;

	/**
	 * Above this number of restored objects, caches are flushed instead of cleaned one by one.
	 */
	private const FLUSH_THRESHOLD = 2000;

	/**
	 * Database object.
	 *
	 * @var object
	 */
	private object $wpdb;

	/**
	 * Backup store.
	 *
	 * @var BackupStore
	 */
	private BackupStore $store;

	/**
	 * Engine helper.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * Current site hash.
	 *
	 * @var string
	 */
	private string $site_hash;

	/**
	 * Multisite.
	 *
	 * @var bool
	 */
	private bool $multisite;

	/**
	 * Result counters.
	 *
	 * @var array<string,array<string,int>>
	 */
	private array $counts = array();

	/**
	 * Keys already handled during this restore (duplicates across parts).
	 *
	 * @var array<string,array<string,bool>>
	 */
	private array $seen = array();

	/**
	 * Restored objects for cache cleaning.
	 *
	 * @var array<string,array<int|string,mixed>>
	 */
	private array $touched = array();

	/**
	 * Constructor.
	 *
	 * @param object      $wpdb      Database object.
	 * @param BackupStore $store     Backup store.
	 * @param Engine      $engine    Engine helper.
	 * @param string      $site_hash Current site hash.
	 * @param bool        $multisite Multisite.
	 */
	public function __construct( object $wpdb, BackupStore $store, Engine $engine, string $site_hash, bool $multisite ) {
		$this->wpdb      = $wpdb;
		$this->store     = $store;
		$this->engine    = $engine;
		$this->site_hash = $site_hash;
		$this->multisite = $multisite;
	}

	/**
	 * Validate a backup without restoring it.
	 *
	 * @param string $id Backup id.
	 * @return array<string,mixed>|\WP_Error Manifest.
	 */
	public function validate( string $id ) {
		if ( ! BackupStore::is_valid_id( $id ) ) {
			return new \WP_Error( 'shso_backup_invalid_id', __( 'This backup does not exist.', 'sh-speed-optimizer' ) );
		}
		$manifest = $this->store->manifest( $id );
		if ( null === $manifest ) {
			return new \WP_Error( 'shso_backup_missing', __( 'This backup does not exist.', 'sh-speed-optimizer' ) );
		}

		$valid = BackupStore::validate_manifest( $manifest, $this->site_hash, (string) $this->wpdb->prefix, $this->multisite );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( 0 === (int) $manifest['rows'] || empty( $manifest['parts'] ) ) {
			return new \WP_Error( 'shso_backup_empty', __( 'This backup contains no data.', 'sh-speed-optimizer' ) );
		}

		$dir = (string) $this->store->dir( $id );
		foreach ( $manifest['parts'] as $part ) {
			if ( ! is_file( $dir . $part['file'] ) ) {
				return new \WP_Error( 'shso_backup_missing_part', __( 'A part of this backup is missing, so it cannot be restored.', 'sh-speed-optimizer' ) );
			}
		}

		return $manifest;
	}

	/**
	 * Restore a backup.
	 *
	 * @param string $id Backup id.
	 * @return array<string,mixed>|\WP_Error Counts.
	 */
	public function restore( string $id ) {
		$manifest = $this->validate( $id );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		// 1. Verify every part and record before touching the database.
		foreach ( $manifest['parts'] as $part ) {
			$decoded = $this->store->read_part( $id, $part );
			if ( is_wp_error( $decoded ) ) {
				return $decoded;
			}
			foreach ( $decoded['records'] as $record ) {
				$valid = self::validate_record( $record, $this->multisite );
				if ( is_wp_error( $valid ) ) {
					return $valid;
				}
			}
			unset( $decoded );
		}

		self::raise_limits();

		$this->counts  = array(
			'inserted' => array(),
			'skipped'  => array(),
			'failed'   => array(),
		);
		$this->seen    = array();
		$this->touched = array();

		$tables = array();
		foreach ( array_keys( $manifest['tables'] ) as $table ) {
			$physical = Schema::physical( $this->wpdb, (string) $table );
			if ( null !== $physical ) {
				$tables[] = $physical;
			}
		}
		$transactional = $this->engine->supports_transactions( $tables );
		$linked        = 0;

		if ( $transactional && ! $this->engine->begin() ) {
			$transactional = false;
		}

		try {
			// 2. Insert rows, then 3. point re-parented children back to their restored parent.
			$links  = $this->insert_all( $id, $manifest['parts'], $transactional );
			$linked = $this->restore_links( $links );

			if ( $transactional ) {
				$this->engine->commit();
			}
		} catch ( \Throwable $e ) {
			if ( $transactional ) {
				$this->engine->rollback();
				return new \WP_Error( 'shso_restore_failed', __( 'The backup could not be restored. Nothing was changed.', 'sh-speed-optimizer' ) );
			}
			return new \WP_Error( 'shso_restore_failed', __( 'The restore stopped before it was complete. Rows restored so far were kept; you can run the restore again to finish it.', 'sh-speed-optimizer' ) );
		}

		$this->clean_caches();

		$result = array(
			'backup_id'      => $id,
			'inserted'       => $this->counts['inserted'],
			'skipped'        => $this->counts['skipped'],
			'failed'         => $this->counts['failed'],
			'links'          => $linked,
			'total_inserted' => array_sum( $this->counts['inserted'] ),
			'total_skipped'  => array_sum( $this->counts['skipped'] ),
			'total_failed'   => array_sum( $this->counts['failed'] ),
			'transactional'  => $transactional,
		);

		$this->store->update(
			$id,
			array(
				'restored_at' => time(),
				'restore'     => array(
					'inserted' => $result['total_inserted'],
					'skipped'  => $result['total_skipped'],
					'failed'   => $result['total_failed'],
				),
			)
		);

		return $result;
	}

	/**
	 * Insert the rows of all parts (streamed part by part, never all loaded at once).
	 *
	 * @param string                         $id            Backup id.
	 * @param array<int,array<string,mixed>> $parts         Manifest parts.
	 * @param bool                           $transactional Stop at the first failed insert.
	 * @return array<int,array{t:string,id:int,from:int,to:int}> Parent links to restore.
	 * @throws \RuntimeException When a part cannot be read or (in a transaction) an insert fails.
	 */
	private function insert_all( string $id, array $parts, bool $transactional ): array {
		$links = array();
		foreach ( $parts as $part ) {
			$decoded = $this->store->read_part( $id, $part );
			if ( is_wp_error( $decoded ) ) {
				throw new \RuntimeException( 'A backup part could not be read.' );
			}

			$by_table = array();
			foreach ( $decoded['records'] as $record ) {
				if ( isset( $record['link'] ) ) {
					$links[] = array(
						't'    => $record['t'],
						'id'   => (int) $record['link']['id'],
						'from' => (int) $record['link']['from'],
						'to'   => (int) $record['link']['to'],
					);
				} else {
					$by_table[ $record['t'] ][] = $record['r'];
				}
			}
			unset( $decoded );

			foreach ( Schema::RESTORE_ORDER as $table ) {
				if ( ! empty( $by_table[ $table ] ) ) {
					$this->insert_rows( $table, $by_table[ $table ] );
				}
			}

			if ( $transactional && array_sum( $this->counts['failed'] ) > 0 ) {
				throw new \RuntimeException( 'Insert failed.' );
			}
		}
		return $links;
	}

	/**
	 * Validate one decoded record against the whitelist.
	 *
	 * @param mixed $record    Record.
	 * @param bool  $multisite Multisite (sitemeta allowed).
	 * @return true|\WP_Error
	 */
	public static function validate_record( $record, bool $multisite ) {
		$invalid = new \WP_Error( 'shso_backup_tables', __( 'This backup contains data this plugin never backs up, so it is not restored.', 'sh-speed-optimizer' ) );

		if ( ! is_array( $record ) || ! is_string( $record['t'] ?? null ) || ! Schema::is_allowed( $record['t'], $multisite ) ) {
			return $invalid;
		}
		$table = $record['t'];

		if ( isset( $record['link'] ) ) {
			if ( ! isset( Schema::LINKS[ $table ] ) || ! is_array( $record['link'] ) ) {
				return $invalid;
			}
			foreach ( array( 'id', 'from', 'to' ) as $key ) {
				if ( ! is_int( $record['link'][ $key ] ?? null ) || $record['link'][ $key ] < 0 ) {
					return $invalid;
				}
			}
			return ( $record['link']['id'] > 0 && $record['link']['to'] > 0 ) ? true : $invalid;
		}

		if ( ! is_array( $record['r'] ?? null ) || empty( $record['r'] ) ) {
			return $invalid;
		}

		$columns = Schema::columns( $table );
		foreach ( $record['r'] as $column => $value ) {
			if ( ! is_string( $column ) || ! in_array( $column, $columns, true ) ) {
				return $invalid;
			}
			if ( null !== $value && ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
				return $invalid;
			}
		}

		return null === Schema::key_of( $table, $record['r'] ) ? $invalid : true;
	}

	/**
	 * Insert rows of one table, skipping keys that exist.
	 *
	 * @param string                         $table Logical table.
	 * @param array<int,array<string,mixed>> $rows  Rows.
	 * @throws \RuntimeException When existing keys cannot be looked up.
	 */
	private function insert_rows( string $table, array $rows ): void {
		$physical = Schema::physical( $this->wpdb, $table );
		if ( null === $physical ) {
			$this->add( 'failed', $table, count( $rows ) );
			return;
		}

		foreach ( array_chunk( $rows, self::CHUNK ) as $chunk ) {
			$existing = $this->existing_keys( $table, $physical, $chunk );

			foreach ( $chunk as $row ) {
				$key = (string) Schema::key_of( $table, $row );
				if ( isset( $this->seen[ $table ][ $key ] ) ) {
					continue; // The same row appeared in an earlier part.
				}
				$this->seen[ $table ][ $key ] = true;

				if ( isset( $existing[ $key ] ) || $this->unique_conflict( $table, $physical, $row ) ) {
					$this->add( 'skipped', $table, 1 );
					continue;
				}

				$inserted = $this->engine->quiet(
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Restoring backed up rows into a whitelisted core table.
					fn() => $this->wpdb->insert( $physical, $row, Schema::formats( $table, $row ) )
				);
				if ( false === $inserted || 0 === $inserted ) {
					$this->add( 'failed', $table, 1 );
					continue;
				}

				$this->add( 'inserted', $table, 1 );
				$this->touch( $table, $row );
			}
		}
	}

	/**
	 * Primary keys of the chunk that already exist.
	 *
	 * @param string                         $table    Logical table.
	 * @param string                         $physical Physical table.
	 * @param array<int,array<string,mixed>> $rows     Rows.
	 * @return array<string,bool>
	 * @throws \RuntimeException When the lookup fails (nothing must be inserted blindly).
	 */
	private function existing_keys( string $table, string $physical, array $rows ): array {
		$pk     = Schema::primary_key( $table );
		$first  = $pk[0];
		$values = array();
		foreach ( $rows as $row ) {
			$values[] = (int) $row[ $first ];
		}
		$values = array_values( array_unique( $values ) );
		$in     = Criteria::placeholders( count( $values ) );
		$select = implode( ', ', $pk );
		$wpdb   = $this->wpdb;

		$found = $this->engine->quiet(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Whitelisted table/columns, prepared values.
			static fn() => $wpdb->get_results( $wpdb->prepare( "SELECT {$select} FROM {$physical} WHERE {$first} IN ({$in})", $values ), ARRAY_A )
		);
		if ( ! is_array( $found ) ) {
			throw new \RuntimeException( 'Lookup failed.' );
		}

		$keys = array();
		foreach ( $found as $row ) {
			$key = Schema::key_of( $table, (array) $row );
			if ( null !== $key ) {
				$keys[ $key ] = true;
			}
		}
		return $keys;
	}

	/**
	 * Whether a row conflicts with a unique non-primary key (option names,
	 * network option keys) that exists again.
	 *
	 * @param string              $table    Logical table.
	 * @param string              $physical Physical table.
	 * @param array<string,mixed> $row      Row.
	 */
	private function unique_conflict( string $table, string $physical, array $row ): bool {
		$wpdb = $this->wpdb;
		if ( 'options' === $table && isset( $row['option_name'] ) ) {
			$sql  = "SELECT COUNT(*) FROM {$physical} WHERE option_name = %s";
			$args = array( (string) $row['option_name'] );
		} elseif ( 'sitemeta' === $table && isset( $row['meta_key'], $row['site_id'] ) ) {
			$sql  = "SELECT COUNT(*) FROM {$physical} WHERE site_id = %d AND meta_key = %s";
			$args = array( (int) $row['site_id'], (string) $row['meta_key'] );
		} else {
			return false;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Whitelisted table, prepared values.
		$count = $this->engine->quiet( static fn() => $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) );
		return null === $count || false === $count || (int) $count > 0;
	}

	/**
	 * Point children back to their restored parent.
	 *
	 * Only rows that still point to the value WordPress assigned on deletion
	 * are changed, so later edits by the administrator are respected.
	 *
	 * @param array<int,array{t:string,id:int,from:int,to:int}> $links Links.
	 */
	private function restore_links( array $links ): int {
		$count = 0;
		$wpdb  = $this->wpdb;
		foreach ( $links as $link ) {
			$column   = Schema::LINKS[ $link['t'] ] ?? null;
			$physical = Schema::physical( $wpdb, $link['t'] );
			if ( null === $column || null === $physical || $link['from'] === $link['to'] ) {
				continue;
			}
			$pk     = Schema::primary_key( $link['t'] )[0];
			$result = $this->engine->quiet(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Whitelisted table/column, prepared values.
				static fn() => $wpdb->query( $wpdb->prepare( "UPDATE {$physical} SET {$column} = %d WHERE {$pk} = %d AND {$column} = %d", $link['to'], $link['id'], $link['from'] ) )
			);
			if ( is_int( $result ) && $result > 0 ) {
				$count                                     += $result;
				$this->touched[ $link['t'] ][ $link['id'] ] = true;
			}
		}
		return $count;
	}

	/**
	 * Increment a counter.
	 *
	 * @param string $type  inserted|skipped|failed.
	 * @param string $table Logical table.
	 * @param int    $count Count.
	 */
	private function add( string $type, string $table, int $count ): void {
		$this->counts[ $type ][ $table ] = ( $this->counts[ $type ][ $table ] ?? 0 ) + $count;
	}

	/**
	 * Remember a restored row for cache cleaning.
	 *
	 * @param string              $table Logical table.
	 * @param array<string,mixed> $row   Row.
	 */
	private function touch( string $table, array $row ): void {
		switch ( $table ) {
			case 'posts':
				$this->touched['posts'][ (int) $row['ID'] ] = true;
				break;
			case 'postmeta':
				$this->touched['post_meta'][ (int) ( $row['post_id'] ?? 0 ) ] = true;
				break;
			case 'comments':
				$this->touched['comments'][ (int) $row['comment_ID'] ]                    = true;
				$this->touched['comment_posts'][ (int) ( $row['comment_post_ID'] ?? 0 ) ] = true;
				break;
			case 'commentmeta':
				$this->touched['comment_meta'][ (int) ( $row['comment_id'] ?? 0 ) ] = true;
				break;
			case 'termmeta':
				$this->touched['term_meta'][ (int) ( $row['term_id'] ?? 0 ) ] = true;
				break;
			case 'term_relationships':
				$this->touched['term_taxonomy'][ (int) $row['term_taxonomy_id'] ] = true;
				$this->touched['term_objects'][ (int) $row['object_id'] ]         = true;
				break;
			case 'options':
				$this->touched['options'][ (string) ( $row['option_name'] ?? '' ) ] = true;
				break;
			case 'sitemeta':
				$this->touched['sitemeta'][ (int) ( $row['site_id'] ?? 0 ) . ':' . (string) ( $row['meta_key'] ?? '' ) ] = true;
				break;
		}
	}

	/**
	 * Clean object caches of restored objects (or flush when there are too many).
	 */
	private function clean_caches(): void {
		$total = 0;
		foreach ( $this->touched as $ids ) {
			$total += count( $ids );
		}
		if ( 0 === $total ) {
			return;
		}

		$this->recount_terms();
		$this->recount_comments();

		if ( $total > self::FLUSH_THRESHOLD ) {
			$this->flush_caches();
			return;
		}

		foreach ( array_keys( $this->touched['posts'] ?? array() ) as $post_id ) {
			if ( function_exists( 'clean_post_cache' ) ) {
				clean_post_cache( (int) $post_id );
			}
		}
		if ( function_exists( 'wp_cache_delete' ) ) {
			foreach ( array( 'post_meta', 'comment_meta', 'term_meta' ) as $group ) {
				foreach ( array_keys( $this->touched[ $group ] ?? array() ) as $object_id ) {
					wp_cache_delete( (int) $object_id, $group );
				}
			}
			foreach ( array_keys( $this->touched['options'] ?? array() ) as $name ) {
				wp_cache_delete( (string) $name, 'options' );
			}
			if ( ! empty( $this->touched['options'] ) ) {
				wp_cache_delete( 'alloptions', 'options' );
				wp_cache_delete( 'notoptions', 'options' );
			}
			foreach ( array_keys( $this->touched['sitemeta'] ?? array() ) as $key ) {
				wp_cache_delete( (string) $key, 'site-options' );
				wp_cache_delete( (int) strtok( (string) $key, ':' ) . ':notoptions', 'site-options' );
			}
		}
		if ( ! empty( $this->touched['comments'] ) && function_exists( 'clean_comment_cache' ) ) {
			clean_comment_cache( array_map( 'intval', array_keys( $this->touched['comments'] ) ) );
		}
	}

	/**
	 * Flush caches: per group when the object cache supports it, else everything.
	 */
	private function flush_caches(): void {
		if ( function_exists( 'wp_cache_supports' ) && function_exists( 'wp_cache_flush_group' ) && wp_cache_supports( 'flush_group' ) ) {
			foreach ( array( 'posts', 'post_meta', 'comment', 'comment_meta', 'terms', 'term_meta', 'term_relationships', 'options', 'site-options', 'counts' ) as $group ) {
				wp_cache_flush_group( $group );
			}
			foreach ( array( 'post_tag', 'category' ) as $taxonomy ) {
				wp_cache_flush_group( $taxonomy . '_relationships' );
			}
			if ( function_exists( 'wp_cache_set_posts_last_changed' ) ) {
				wp_cache_set_posts_last_changed();
			}
			return;
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
	}

	/**
	 * Recalculate term counts of restored term relationships.
	 */
	private function recount_terms(): void {
		$tt_ids = array_map( 'intval', array_keys( $this->touched['term_taxonomy'] ?? array() ) );
		if ( empty( $tt_ids ) || ! function_exists( 'wp_update_term_count_now' ) || empty( $this->wpdb->term_taxonomy ) ) {
			return;
		}

		$wpdb = $this->wpdb;
		foreach ( array_chunk( $tt_ids, self::CHUNK ) as $chunk ) {
			$in   = Criteria::placeholders( count( $chunk ) );
			$rows = $this->engine->quiet(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Core table, prepared values.
				static fn() => $wpdb->get_results( $wpdb->prepare( "SELECT term_taxonomy_id, taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ({$in})", $chunk ), ARRAY_A )
			);
			$by_taxonomy = array();
			foreach ( (array) $rows as $row ) {
				$by_taxonomy[ (string) $row['taxonomy'] ][] = (int) $row['term_taxonomy_id'];
			}
			foreach ( $by_taxonomy as $taxonomy => $ids ) {
				if ( function_exists( 'taxonomy_exists' ) && ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}
				wp_update_term_count_now( $ids, $taxonomy );
				if ( function_exists( 'wp_cache_delete' ) ) {
					foreach ( array_keys( $this->touched['term_objects'] ?? array() ) as $object_id ) {
						wp_cache_delete( (int) $object_id, $taxonomy . '_relationships' );
					}
				}
			}
		}
	}

	/**
	 * Recalculate comment counts of posts that got comments back.
	 */
	private function recount_comments(): void {
		if ( ! function_exists( 'wp_update_comment_count_now' ) ) {
			return;
		}
		foreach ( array_keys( $this->touched['comment_posts'] ?? array() ) as $post_id ) {
			if ( $post_id > 0 ) {
				wp_update_comment_count_now( (int) $post_id );
			}
		}
	}

	/**
	 * Give a large restore enough time and memory.
	 */
	private static function raise_limits(): void {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		if ( function_exists( 'set_time_limit' ) && 'cli' !== PHP_SAPI ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- Large restores; may be disabled by the host.
		}
	}
}
