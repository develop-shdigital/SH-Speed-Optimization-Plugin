<?php
/**
 * Database cleanup job (`db_clean`).
 *
 * Runs only when the administrator explicitly starts it and only for the
 * items they selected. Every batch follows the same order:
 *
 *  1. select the next batch of ids (≤ 500 rows, bounded by content size);
 *  2. read the complete rows of every table the deletion touches;
 *  3. write them to the backup, read the part back and verify its checksum;
 *  4. only then delete — through the WordPress APIs where they exist so
 *     hooks run and caches are cleared — inside a transaction on InnoDB.
 *
 * If the backup cannot be written the job stops before deleting anything.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Database;

use SH\SpeedOptimizer\Core\Jobs\Job;
use SH\SpeedOptimizer\Core\Jobs\JobHandlerInterface;
use SH\SpeedOptimizer\Core\Jobs\StepResult;
use SH\SpeedOptimizer\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Cleanup job handler.
 */
final class Cleaner implements JobHandlerInterface {

	/**
	 * Items an administrator may select (strict whitelist).
	 */
	public const ITEMS = array( 'revisions', 'auto_drafts', 'trashed_posts', 'spam_comments', 'trashed_comments', 'expired_transients', 'orphaned_postmeta', 'orphaned_commentmeta', 'orphaned_termmeta' );

	public const DEFAULT_KEEP = 5;
	public const MAX_KEEP     = 50;

	/**
	 * Maximum rows per batch per item (the hard cap is 500).
	 */
	public const BATCH_SIZES = array(
		'revisions'               => 200,
		'auto_drafts'             => 100,
		'trashed_posts'           => 50,
		'spam_comments'           => 250,
		'trashed_comments'        => 250,
		'expired_transients'      => 100,
		'expired_site_transients' => 100,
		'orphaned_postmeta'       => 500,
		'orphaned_commentmeta'    => 500,
		'orphaned_termmeta'       => 500,
	);

	public const MAX_BATCH = 500;

	/**
	 * Content bytes per batch before it is cut short (8 MB).
	 */
	public const MAX_BATCH_BYTES = 8388608;

	/**
	 * Seconds a step may spend deleting.
	 */
	public const TIME_BUDGET = 5.0;

	/**
	 * Ids per IN() list.
	 */
	private const CHUNK = 200;

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Database service.
	 *
	 * @var DatabaseService
	 */
	private DatabaseService $service;

	/**
	 * Constructor.
	 *
	 * @param Plugin          $plugin  Plugin container.
	 * @param DatabaseService $service Database service.
	 */
	public function __construct( Plugin $plugin, DatabaseService $service ) {
		$this->plugin  = $plugin;
		$this->service = $service;
	}

	// ---------------------------------------------------------------------
	// Pure helpers.
	// ---------------------------------------------------------------------

	/**
	 * Keep only whitelisted items (unknown values are ignored), in canonical order.
	 *
	 * @param mixed $items Raw items.
	 * @return string[]
	 */
	public static function sanitize_items( $items ): array {
		if ( ! is_array( $items ) ) {
			return array();
		}
		$wanted = array();
		foreach ( $items as $item ) {
			if ( is_string( $item ) && in_array( $item, self::ITEMS, true ) ) {
				$wanted[ $item ] = true;
			}
		}
		return array_values( array_filter( self::ITEMS, static fn( string $item ): bool => isset( $wanted[ $item ] ) ) );
	}

	/**
	 * Number of revisions to keep per post (default 5, 0–50).
	 *
	 * @param mixed $keep Raw value.
	 */
	public static function clamp_keep( $keep ): int {
		if ( is_int( $keep ) || ( is_string( $keep ) && preg_match( '/^\s*-?[0-9]{1,6}\s*$/', $keep ) ) || ( is_float( $keep ) && is_finite( $keep ) ) ) {
			return max( 0, min( self::MAX_KEEP, (int) $keep ) );
		}
		return self::DEFAULT_KEEP;
	}

	/**
	 * Step list for the selected items.
	 *
	 * @param string[] $items           Sanitized items.
	 * @param bool     $site_transients Also clean network site transients.
	 * @return string[]
	 */
	public static function steps_for( array $items, bool $site_transients ): array {
		$steps = array( 'prepare' );
		foreach ( self::sanitize_items( $items ) as $item ) {
			$steps[] = 'clean_' . $item;
			if ( 'expired_transients' === $item && $site_transients ) {
				$steps[] = 'clean_expired_site_transients';
			}
		}
		$steps[] = 'finalize';
		return $steps;
	}

	/**
	 * Revisions to delete: everything except the newest $keep per post.
	 *
	 * Rows need ID and post_parent; post_date orders them (ID breaks ties).
	 * Autosaves are never selected.
	 *
	 * @param array<int,array<string,mixed>> $rows Revision rows.
	 * @param int                            $keep Revisions kept per post.
	 * @return int[] Ids, grouped by post and oldest first.
	 */
	public static function select_revisions_to_delete( array $rows, int $keep ): array {
		$keep   = max( 0, $keep );
		$groups = array();
		foreach ( $rows as $row ) {
			$row = (array) $row;
			if ( false !== strpos( (string) ( $row['post_name'] ?? '' ), Criteria::AUTOSAVE_PATTERN ) ) {
				continue;
			}
			$id = (int) ( $row['ID'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$groups[ (int) ( $row['post_parent'] ?? 0 ) ][] = array(
				'id'   => $id,
				'date' => (string) ( $row['post_date'] ?? '' ),
			);
		}
		ksort( $groups );

		$delete = array();
		foreach ( $groups as $revisions ) {
			usort(
				$revisions,
				static function ( array $a, array $b ): int {
					$order = strcmp( $b['date'], $a['date'] );
					return 0 !== $order ? $order : $b['id'] <=> $a['id'];
				}
			);
			$old = array_slice( $revisions, $keep );
			$ids = array_map( static fn( array $revision ): int => $revision['id'], $old );
			sort( $ids );
			$delete = array_merge( $delete, $ids );
		}

		return $delete;
	}

	/**
	 * Cut a batch once its content exceeds the byte budget (at least one row is kept).
	 *
	 * @param array<int,array<string,mixed>> $rows      Rows with "bytes".
	 * @param int                            $max_bytes Budget.
	 * @return array<int,array<string,mixed>>
	 */
	public static function cap_by_bytes( array $rows, int $max_bytes ): array {
		$kept  = array();
		$bytes = 0;
		foreach ( $rows as $row ) {
			$size = max( 0, (int) ( $row['bytes'] ?? 0 ) );
			if ( ! empty( $kept ) && $bytes + $size > $max_bytes ) {
				break;
			}
			$kept[] = $row;
			$bytes += $size;
		}
		return $kept;
	}

	/**
	 * Name of the value option that belongs to a transient timeout option.
	 *
	 * @param string $timeout_name E.g. "_transient_timeout_foo".
	 */
	public static function transient_value_name( string $timeout_name ): ?string {
		foreach ( array(
			'_site_transient_timeout_' => '_site_transient_',
			'_transient_timeout_'      => '_transient_',
		) as $prefix => $value_prefix ) {
			if ( 0 === strpos( $timeout_name, $prefix ) && strlen( $timeout_name ) > strlen( $prefix ) ) {
				return $value_prefix . substr( $timeout_name, strlen( $prefix ) );
			}
		}
		return null;
	}

	/**
	 * Cursor after a batch.
	 *
	 * Ids that were not processed (time budget) are selected again next time.
	 * A revision batch that removed nothing skips ahead, so rows that cannot
	 * be deleted can never cause an endless loop.
	 *
	 * @param string                                    $key     Step key.
	 * @param array{cursor:int,skip:int}                $batch   Batch (see plan_revisions()).
	 * @param array{deleted:int,last:int,complete:bool} $result  Deletion result.
	 * @param int                                       $current Current cursor.
	 */
	public static function next_cursor( string $key, array $batch, array $result, int $current ): int {
		if ( 'revisions' === $key ) {
			if ( 0 === (int) $result['deleted'] ) {
				return (int) $batch['skip'];
			}
			return $result['complete'] ? (int) $batch['cursor'] : $current;
		}
		return $result['complete'] ? (int) $batch['cursor'] : max( $current, (int) $result['last'] );
	}

	/**
	 * Cleanup item a step key belongs to.
	 *
	 * @param string $key Step key (item or "expired_site_transients").
	 */
	public static function item_of( string $key ): string {
		return 'expired_site_transients' === $key ? 'expired_transients' : $key;
	}

	// ---------------------------------------------------------------------
	// JobHandlerInterface.
	// ---------------------------------------------------------------------

	/**
	 * Initial steps.
	 *
	 * @param array<string,mixed> $args Job arguments (items, keep_revisions).
	 * @return string[]
	 */
	public function steps( array $args ): array {
		return self::steps_for( self::sanitize_items( $args['items'] ?? array() ), self::cleans_site_transients() );
	}

	/**
	 * Run a step.
	 *
	 * @param string $step Step id.
	 * @param Job    $job  Job.
	 */
	public function run_step( string $step, Job $job ): StepResult {
		if ( 'prepare' === $step ) {
			return $this->prepare( $job );
		}
		if ( 'finalize' === $step ) {
			return $this->finalize( $job );
		}
		if ( 0 === strpos( $step, 'clean_' ) ) {
			$key = substr( $step, 6 );
			if ( in_array( self::item_of( $key ), (array) $job->get( 'items', array() ), true ) && isset( self::BATCH_SIZES[ $key ] ) ) {
				return $this->clean( $key, $job );
			}
		}
		return StepResult::done();
	}

	/**
	 * Plain-language step label.
	 *
	 * @param string $step Step id.
	 */
	public function label( string $step ): string {
		$labels = array(
			'prepare'                       => __( 'Counting what can be cleaned up and preparing the backup…', 'sh-speed-optimizer' ),
			'clean_revisions'               => __( 'Backing up and removing old revisions…', 'sh-speed-optimizer' ),
			'clean_auto_drafts'             => __( 'Backing up and removing unused automatic drafts…', 'sh-speed-optimizer' ),
			'clean_trashed_posts'           => __( 'Backing up and emptying the trash…', 'sh-speed-optimizer' ),
			'clean_spam_comments'           => __( 'Backing up and deleting spam comments…', 'sh-speed-optimizer' ),
			'clean_trashed_comments'        => __( 'Backing up and deleting trashed comments…', 'sh-speed-optimizer' ),
			'clean_expired_transients'      => __( 'Backing up and removing expired temporary data…', 'sh-speed-optimizer' ),
			'clean_expired_site_transients' => __( 'Backing up and removing expired network-wide temporary data…', 'sh-speed-optimizer' ),
			'clean_orphaned_postmeta'       => __( 'Backing up and removing leftover post details…', 'sh-speed-optimizer' ),
			'clean_orphaned_commentmeta'    => __( 'Backing up and removing leftover comment details…', 'sh-speed-optimizer' ),
			'clean_orphaned_termmeta'       => __( 'Backing up and removing leftover category details…', 'sh-speed-optimizer' ),
			'finalize'                      => __( 'Finishing the database cleanup…', 'sh-speed-optimizer' ),
		);
		return $labels[ $step ] ?? __( 'Cleaning up the database…', 'sh-speed-optimizer' );
	}

	/**
	 * All steps completed.
	 *
	 * @param Job $job Job.
	 */
	public function complete( Job $job ): void {
		if ( null === $job->get( 'summary' ) ) {
			$job->set( 'summary', $this->summary( $job, 'complete' ) );
		}
	}

	/**
	 * The job failed or was cancelled.
	 *
	 * Batches already deleted stay deleted: they are in the backup, which is
	 * kept and marked as partial so the administrator can restore it.
	 *
	 * @param Job    $job    Job.
	 * @param string $reason Reason.
	 */
	public function abort( Job $job, string $reason ): void {
		$summary           = $this->summary( $job, 'partial' );
		$summary['reason'] = $reason;
		$summary           = $this->settle_backup( $summary, 'partial' );
		$job->set( 'summary', $summary );

		if ( $summary['deleted_total'] > 0 ) {
			$job->message(
				sprintf(
					/* translators: %s: number of entries */
					_n(
						'The cleanup stopped early. %s entry had already been removed; it is saved in the backup and can be restored.',
						'The cleanup stopped early. %s entries had already been removed; they are saved in the backup and can be restored.',
						$summary['deleted_total'],
						'sh-speed-optimizer'
					),
					Findings::number( $summary['deleted_total'] )
				),
				'warning'
			);
			$this->plugin->logger()->event(
				'db_clean_aborted',
				__( 'The database cleanup stopped early. Removed entries are saved in a backup.', 'sh-speed-optimizer' ),
				'',
				self::log_context( $summary ),
				'warning'
			);
		}
	}

	// ---------------------------------------------------------------------
	// Steps.
	// ---------------------------------------------------------------------

	/**
	 * Count, snapshot sizes and create the backup.
	 *
	 * @param Job $job Job.
	 * @throws \RuntimeException When the backup cannot be created (the job stops, nothing is deleted).
	 */
	private function prepare( Job $job ): StepResult {
		$items = self::sanitize_items( $job->arg( 'items', array() ) );
		$keep  = self::clamp_keep( $job->arg( 'keep_revisions', self::DEFAULT_KEEP ) );

		$job->set( 'items', $items );
		$job->set( 'keep', $keep );
		$job->set( 'deleted', array() );
		$job->set( 'excluded', Criteria::excluded_post_types() );

		if ( empty( $items ) ) {
			$job->message( __( 'Nothing was selected, so nothing was changed.', 'sh-speed-optimizer' ) );
			return StepResult::done();
		}

		$analyzer = $this->service->analyzer();
		$counts   = array();
		foreach ( $items as $item ) {
			$counts[ $item ] = $analyzer->count_item( $item, $keep );
		}
		$job->set( 'counts_before', $counts );
		$job->set( 'sizes_before', $analyzer->sizes() );

		$known = array_filter( $counts, 'is_int' );
		if ( count( $known ) === count( $counts ) && 0 === array_sum( $known ) ) {
			$job->message( __( 'There was nothing to clean up, so nothing was changed.', 'sh-speed-optimizer' ) );
			return StepResult::done();
		}

		$backup = $this->service->store()->create(
			array(
				'items'          => $items,
				'keep_revisions' => $keep,
				'job_id'         => $job->id(),
			)
		);
		if ( is_wp_error( $backup ) ) {
			$job->message( __( 'The backup could not be created, so nothing was deleted. Please make sure the uploads folder is writable.', 'sh-speed-optimizer' ), 'error' );
			throw new \RuntimeException( 'Database backup could not be created.' );
		}
		$job->set( 'backup_id', $backup );

		$engine        = $this->service->engine();
		$transactional = array();
		foreach ( $items as $item ) {
			$transactional[ $item ] = $engine->supports_transactions( $this->tables_for( $item ) );
		}
		$job->set( 'transactional', $transactional );

		return StepResult::done();
	}

	/**
	 * Back up and delete one batch.
	 *
	 * @param string $key Step key.
	 * @param Job    $job Job.
	 * @throws \RuntimeException When the backup cannot be written (the job stops before deleting).
	 */
	private function clean( string $key, Job $job ): StepResult {
		$backup_id = (string) $job->get( 'backup_id', '' );
		if ( '' === $backup_id ) {
			return StepResult::done(); // Nothing was counted, no backup: never delete without one.
		}

		$item  = self::item_of( $key );
		$start = microtime( true );
		$batch = $this->next_batch( $key, $job );

		if ( empty( $batch['ids'] ) ) {
			$job->set( 'cursor_' . $key, $batch['cursor'] );
			return $batch['more'] ? StepResult::repeat() : StepResult::done();
		}

		$records = $this->collect( $key, $batch['ids'] );

		if ( ! $this->service->store()->append( $backup_id, $item, $records ) ) {
			$job->message( __( 'The backup could not be written, so the cleanup was stopped before deleting anything else. Please make sure the uploads folder is writable and has free space.', 'sh-speed-optimizer' ), 'error' );
			throw new \RuntimeException( 'Database backup could not be written.' );
		}

		$transactional = ! empty( ( (array) $job->get( 'transactional', array() ) )[ $item ] );
		$result        = $this->delete( $key, $batch['ids'], $records, $transactional, $start, $job );

		$deleted          = (array) $job->get( 'deleted', array() );
		$deleted[ $item ] = (int) ( $deleted[ $item ] ?? 0 ) + $result['deleted'];
		$job->set( 'deleted', $deleted );

		$cursor = self::next_cursor( $key, $batch, $result, (int) $job->get( 'cursor_' . $key, 0 ) );
		$job->set( 'cursor_' . $key, $cursor );

		$this->adapt_batch_size( $key, $job, microtime( true ) - $start );

		return ( $batch['more'] || ! $result['complete'] ) ? StepResult::repeat() : StepResult::done();
	}

	/**
	 * Write the summary, log and notify.
	 *
	 * @param Job $job Job.
	 */
	private function finalize( Job $job ): StepResult {
		$summary = $this->settle_backup( $this->summary( $job, 'complete' ), 'complete' );
		$job->set( 'summary', $summary );

		if ( empty( $job->get( 'items', array() ) ) || '' === (string) $job->get( 'backup_id', '' ) ) {
			return StepResult::done();
		}

		$message = sprintf(
			/* translators: %s: number of entries */
			_n(
				'Database cleanup finished: %s entry was removed.',
				'Database cleanup finished: %s entries were removed.',
				$summary['deleted_total'],
				'sh-speed-optimizer'
			),
			Findings::number( $summary['deleted_total'] )
		);
		if ( null !== $summary['backup_id'] ) {
			$message .= ' ' . __( 'A backup was saved and can be restored from the Database page.', 'sh-speed-optimizer' );
		}

		$job->message( $message, 'success' );
		$this->plugin->logger()->event( 'db_cleaned', $message, '', self::log_context( $summary ) );

		/**
		 * Fires after a database cleanup finished.
		 *
		 * @param array<string,mixed> $summary Summary (rows deleted per item, backup id, size estimates).
		 */
		do_action( 'shso_db_cleaned', $summary );

		return StepResult::done();
	}

	// ---------------------------------------------------------------------
	// Batch selection.
	// ---------------------------------------------------------------------

	/**
	 * Next batch of ids for a step key.
	 *
	 * @param string $key Step key.
	 * @param Job    $job Job.
	 * @return array{ids:int[],cursor:int,more:bool,skip:int}
	 */
	private function next_batch( string $key, Job $job ): array {
		global $wpdb;

		$cursor = (int) $job->get( 'cursor_' . $key, 0 );
		$limit  = $this->batch_size( $key, $job );

		if ( 'revisions' === $key ) {
			return $this->next_revisions( $cursor, $limit, (int) $job->get( 'keep', self::DEFAULT_KEEP ) );
		}

		$now = time();
		switch ( $key ) {
			case 'auto_drafts':
			case 'trashed_posts':
				list( $where, $args ) = 'auto_drafts' === $key
					? Criteria::auto_drafts( $now )
					: Criteria::trashed_posts( (array) $job->get( 'excluded', Criteria::excluded_post_types() ) );
				$sql                  = "SELECT p.ID AS id, LENGTH(p.post_content) + COALESCE((SELECT SUM(LENGTH(pm.meta_value)) FROM {$wpdb->postmeta} pm WHERE pm.post_id = p.ID), 0) AS bytes FROM {$wpdb->posts} p WHERE {$where} AND p.ID > %d ORDER BY p.ID ASC LIMIT %d";
				$params               = array_merge( $args, array( $cursor, $limit ) );
				try {
					$rows = $this->select( $sql, $params );
				} catch ( \RuntimeException $e ) {
					// Database engines without correlated subqueries: measure the content only.
					$rows = $this->select( "SELECT p.ID AS id, LENGTH(p.post_content) AS bytes FROM {$wpdb->posts} p WHERE {$where} AND p.ID > %d ORDER BY p.ID ASC LIMIT %d", $params );
				}
				return self::batch_from_rows( $rows, $cursor, $limit );

			case 'spam_comments':
			case 'trashed_comments':
				list( $where, $args ) = Criteria::comments( 'spam_comments' === $key ? 'spam' : 'trash' );
				$sql                  = "SELECT comment_ID AS id, LENGTH(comment_content) AS bytes FROM {$wpdb->comments} WHERE {$where} AND comment_ID > %d ORDER BY comment_ID ASC LIMIT %d";
				break;

			case 'expired_transients':
				return $this->next_transients( $cursor, $limit, false );

			case 'expired_site_transients':
				return $this->next_transients( $cursor, $limit, true );

			default:
				$definition = Criteria::orphaned_meta( $wpdb, substr( $key, 9, -4 ) );
				if ( null === $definition ) {
					return self::empty_batch( $cursor );
				}
				$args = array();
				$sql  = "SELECT m.meta_id AS id, LENGTH(m.meta_value) AS bytes FROM {$definition['meta']} m LEFT JOIN {$definition['object']} o ON o.{$definition['object_pk']} = m.{$definition['meta_fk']} WHERE o.{$definition['object_pk']} IS NULL AND m.meta_id > %d ORDER BY m.meta_id ASC LIMIT %d";
		}

		$rows = $this->select( $sql, array_merge( $args, array( $cursor, $limit ) ) );
		return self::batch_from_rows( $rows, $cursor, $limit );
	}

	/**
	 * Build a batch from id/bytes rows.
	 *
	 * @param array<int,array<string,mixed>> $rows   Rows (id, bytes), ordered by id.
	 * @param int                            $cursor Current cursor.
	 * @param int                            $limit  Batch size.
	 * @return array{ids:int[],cursor:int,more:bool,skip:int}
	 */
	private static function batch_from_rows( array $rows, int $cursor, int $limit ): array {
		$capped = self::cap_by_bytes( $rows, self::MAX_BATCH_BYTES );
		$ids    = array_map( static fn( array $row ): int => (int) $row['id'], $capped );
		$next   = empty( $ids ) ? $cursor : max( $ids );
		return array(
			'ids'    => $ids,
			'cursor' => $next,
			'more'   => count( $rows ) >= $limit || count( $capped ) < count( $rows ),
			'skip'   => $next,
		);
	}

	/**
	 * Empty batch.
	 *
	 * @param int $cursor Cursor.
	 * @return array{ids:int[],cursor:int,more:bool,skip:int}
	 */
	private static function empty_batch( int $cursor ): array {
		return array(
			'ids'    => array(),
			'cursor' => $cursor,
			'more'   => false,
			'skip'   => $cursor,
		);
	}

	/**
	 * Next batch of revisions, walking posts by id ($cursor = next post id, inclusive).
	 *
	 * @param int $cursor Cursor.
	 * @param int $limit  Batch size.
	 * @param int $keep   Revisions kept per post.
	 * @return array{ids:int[],cursor:int,more:bool,skip:int}
	 */
	private function next_revisions( int $cursor, int $limit, int $keep ): array {
		global $wpdb;

		list( $where, $args ) = Criteria::revisions( $wpdb );
		$parent_limit         = 25;
		$parents              = $this->select(
			"SELECT post_parent AS parent FROM {$wpdb->posts} WHERE {$where} AND post_parent >= %d GROUP BY post_parent HAVING COUNT(*) > %d ORDER BY post_parent ASC LIMIT %d",
			array_merge( $args, array( $cursor, $keep, $parent_limit ) )
		);
		if ( empty( $parents ) ) {
			return self::empty_batch( $cursor );
		}

		$groups = array();
		foreach ( $parents as $parent_row ) {
			$parent            = (int) $parent_row['parent'];
			$groups[ $parent ] = $this->select(
				"SELECT ID, post_parent, post_date, post_name, LENGTH(post_content) AS bytes FROM {$wpdb->posts} WHERE {$where} AND post_parent = %d",
				array_merge( $args, array( $parent ) )
			);
		}

		return self::plan_revisions( $groups, $cursor, $limit, $keep, count( $parents ) >= $parent_limit );
	}

	/**
	 * Plan the next batch of revisions from the revisions of consecutive posts.
	 *
	 * The cursor is the id of the next post to look at (inclusive). A batch
	 * that stops inside a post continues with that post next time; "skip"
	 * is the cursor to use when a batch removed nothing, so failing rows can
	 * never cause an endless loop.
	 *
	 * @param array<int,array<int,array<string,mixed>>> $groups       Post id => revision rows (ID, post_parent, post_date, post_name, bytes), ascending post ids.
	 * @param int                                       $cursor       Current cursor.
	 * @param int                                       $limit        Batch size.
	 * @param int                                       $keep         Revisions kept per post.
	 * @param bool                                      $more_posts   Whether more posts follow the given ones.
	 * @param int                                       $max_bytes    Content bytes per batch.
	 * @return array{ids:int[],cursor:int,more:bool,skip:int}
	 */
	public static function plan_revisions( array $groups, int $cursor, int $limit, int $keep, bool $more_posts, int $max_bytes = self::MAX_BATCH_BYTES ): array {
		$selected = array();
		$bytes    = 0;
		$next     = $cursor;
		$last     = $cursor - 1;
		$full     = false;

		foreach ( $groups as $parent => $rows ) {
			$parent = (int) $parent;
			$last   = $parent;
			$sizes  = array();
			foreach ( $rows as $row ) {
				$sizes[ (int) $row['ID'] ] = (int) ( $row['bytes'] ?? 0 );
			}

			foreach ( self::select_revisions_to_delete( $rows, $keep ) as $id ) {
				if ( ! empty( $selected ) && ( count( $selected ) >= $limit || $bytes + $sizes[ $id ] > $max_bytes ) ) {
					$full = true;
					break;
				}
				$selected[] = $id;
				$bytes     += $sizes[ $id ];
			}

			if ( $full ) {
				$next = $parent; // Continue with this post next time.
				break;
			}
			$next = $parent + 1;
			if ( count( $selected ) >= $limit ) {
				$full = true;
				break;
			}
		}

		return array(
			'ids'    => $selected,
			'cursor' => $next,
			'more'   => $full || $more_posts,
			'skip'   => max( $next, $last + 1 ),
		);
	}

	/**
	 * Next batch of expired transients (timeout rows), bounded by the size of their values.
	 *
	 * @param int  $cursor Cursor (timeout row id).
	 * @param int  $limit  Batch size.
	 * @param bool $site   Network site transients in sitemeta.
	 * @return array{ids:int[],cursor:int,more:bool,skip:int}
	 */
	private function next_transients( int $cursor, int $limit, bool $site ): array {
		global $wpdb;

		$now = time();
		if ( $site ) {
			if ( empty( $wpdb->sitemeta ) ) {
				return self::empty_batch( $cursor );
			}
			list( $where, $args ) = Criteria::site_transient_timeouts( $wpdb, $now, Analyzer::network_id() );
			$rows                 = $this->select(
				"SELECT meta_id AS id, meta_key AS name FROM {$wpdb->sitemeta} WHERE {$where} AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
				array_merge( $args, array( $cursor, $limit ) )
			);
		} else {
			$multisite            = function_exists( 'is_multisite' ) && is_multisite();
			list( $where, $args ) = Criteria::transient_timeouts( $wpdb, $now, ! $multisite );
			$rows                 = $this->select(
				"SELECT option_id AS id, option_name AS name FROM {$wpdb->options} WHERE {$where} AND option_id > %d ORDER BY option_id ASC LIMIT %d",
				array_merge( $args, array( $cursor, $limit ) )
			);
		}

		// Size of the values, so a batch of large cached values stays bounded.
		$names = array();
		foreach ( $rows as $row ) {
			$value = self::transient_value_name( (string) $row['name'] );
			if ( null !== $value ) {
				$names[] = $value;
			}
		}
		$sizes = $this->transient_sizes( $names, $site );
		foreach ( $rows as &$row ) {
			$row['bytes'] = $sizes[ (string) self::transient_value_name( (string) $row['name'] ) ] ?? 0;
		}
		unset( $row );

		return self::batch_from_rows( $rows, $cursor, $limit );
	}

	/**
	 * Value sizes of transients by option name.
	 *
	 * @param string[] $names Option/meta names.
	 * @param bool     $site  Sitemeta.
	 * @return array<string,int>
	 */
	private function transient_sizes( array $names, bool $site ): array {
		global $wpdb;

		$sizes = array();
		foreach ( array_chunk( $names, self::CHUNK ) as $chunk ) {
			$in = Criteria::placeholders( count( $chunk ), '%s' );
			if ( $site ) {
				$rows = $this->select(
					"SELECT meta_key AS name, LENGTH(meta_value) AS bytes FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key IN ({$in})",
					array_merge( array( Analyzer::network_id() ), $chunk )
				);
			} else {
				$rows = $this->select( "SELECT option_name AS name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE option_name IN ({$in})", $chunk );
			}
			foreach ( $rows as $row ) {
				$sizes[ (string) $row['name'] ] = (int) $row['bytes'];
			}
		}
		return $sizes;
	}

	// ---------------------------------------------------------------------
	// Backup collection.
	// ---------------------------------------------------------------------

	/**
	 * Complete rows of every table a deletion touches.
	 *
	 * @param string $key Step key.
	 * @param int[]  $ids Primary ids.
	 * @return array<int,array<string,mixed>> Records.
	 */
	private function collect( string $key, array $ids ): array {
		global $wpdb;

		switch ( $key ) {
			case 'revisions':
			case 'auto_drafts':
			case 'trashed_posts':
				return $this->collect_posts( $ids );

			case 'spam_comments':
			case 'trashed_comments':
				return $this->collect_comments( $ids );

			case 'expired_transients':
				$records = $this->rows( 'options', 'option_id', $ids );
				$names   = array();
				foreach ( $records as $record ) {
					$value = self::transient_value_name( (string) $record['r']['option_name'] );
					if ( null !== $value ) {
						$names[] = $value;
					}
				}
				return array_merge( $records, $this->rows( 'options', 'option_name', $names, '%s' ) );

			case 'expired_site_transients':
				$network = Analyzer::network_id();
				$records = $this->rows( 'sitemeta', 'meta_id', $ids );
				$names   = array();
				foreach ( $records as $record ) {
					$value = self::transient_value_name( (string) $record['r']['meta_key'] );
					if ( null !== $value ) {
						$names[] = $value;
					}
				}
				$values = $this->rows( 'sitemeta', 'meta_key', $names, '%s', 'site_id = %d', array( $network ) );
				return array_merge( $records, $values );

			default:
				$definition = Criteria::orphaned_meta( $wpdb, substr( $key, 9, -4 ) );
				return null === $definition ? array() : $this->rows( $definition['logical'], 'meta_id', $ids );
		}
	}

	/**
	 * Posts plus everything WordPress deletes or rewrites with them.
	 *
	 * Revisions, meta, term relationships and comments are deleted by
	 * `wp_delete_post()`. Child posts are backed up in full (plugins such as
	 * WooCommerce delete variations with their product); for children that
	 * WordPress only re-parents a link record is stored as well.
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int,array<string,mixed>>
	 */
	private function collect_posts( array $ids ): array {
		global $wpdb;

		$records  = $this->rows( 'posts', 'ID', $ids );
		$parents  = array();
		$post_ids = array();
		foreach ( $records as $record ) {
			$post_ids[]                          = (int) $record['r']['ID'];
			$parents[ (int) $record['r']['ID'] ] = (int) $record['r']['post_parent'];
		}
		if ( empty( $post_ids ) ) {
			return array();
		}

		// Children of the deleted posts (revisions, variations, attachments …).
		$children = array();
		foreach ( array_chunk( $post_ids, self::CHUNK ) as $chunk ) {
			$in       = Criteria::placeholders( count( $chunk ) );
			$children = array_merge(
				$children,
				$this->select( "SELECT ID, post_parent, post_type FROM {$wpdb->posts} WHERE post_parent IN ({$in})", $chunk )
			);
		}

		$full_children = array();
		foreach ( $children as $child ) {
			$child_id = (int) $child['ID'];
			if ( isset( $parents[ $child_id ] ) ) {
				continue; // Part of this batch anyway.
			}
			$parent_id = (int) $child['post_parent'];
			if ( 'attachment' !== $child['post_type'] ) {
				$full_children[] = $child_id; // Attachments are only re-parented, never deleted with the post.
			}
			if ( 'revision' !== $child['post_type'] ) {
				$records[] = array(
					't'    => 'posts',
					'link' => array(
						'id'   => $child_id,
						'from' => $parents[ $parent_id ] ?? 0,
						'to'   => $parent_id,
					),
				);
			}
		}

		if ( ! empty( $full_children ) ) {
			$records  = array_merge( $records, $this->rows( 'posts', 'ID', $full_children ) );
			$post_ids = array_merge( $post_ids, $full_children );
		}

		$records = array_merge(
			$records,
			$this->rows( 'postmeta', 'post_id', $post_ids ),
			$this->rows( 'term_relationships', 'object_id', $post_ids )
		);

		$comments    = $this->rows( 'comments', 'comment_post_ID', $post_ids );
		$comment_ids = array();
		foreach ( $comments as $comment ) {
			$comment_ids[] = (int) $comment['r']['comment_ID'];
		}

		return array_merge( $records, $comments, $this->rows( 'commentmeta', 'comment_id', $comment_ids ) );
	}

	/**
	 * Comments, their meta and links of replies that WordPress re-parents.
	 *
	 * @param int[] $ids Comment ids.
	 * @return array<int,array<string,mixed>>
	 */
	private function collect_comments( array $ids ): array {
		global $wpdb;

		$records = $this->rows( 'comments', 'comment_ID', $ids );
		$parents = array();
		foreach ( $records as $record ) {
			$parents[ (int) $record['r']['comment_ID'] ] = (int) $record['r']['comment_parent'];
		}
		if ( empty( $parents ) ) {
			return array();
		}

		foreach ( array_chunk( array_keys( $parents ), self::CHUNK ) as $chunk ) {
			$in      = Criteria::placeholders( count( $chunk ) );
			$replies = $this->select( "SELECT comment_ID, comment_parent FROM {$wpdb->comments} WHERE comment_parent IN ({$in})", $chunk );
			foreach ( $replies as $reply ) {
				$reply_id = (int) $reply['comment_ID'];
				if ( isset( $parents[ $reply_id ] ) ) {
					continue;
				}
				$records[] = array(
					't'    => 'comments',
					'link' => array(
						'id'   => $reply_id,
						'from' => $parents[ (int) $reply['comment_parent'] ] ?? 0,
						'to'   => (int) $reply['comment_parent'],
					),
				);
			}
		}

		return array_merge( $records, $this->rows( 'commentmeta', 'comment_id', array_keys( $parents ) ) );
	}

	/**
	 * Full rows (core columns) of a whitelisted table as records.
	 *
	 * @param string           $table      Logical table.
	 * @param string           $column     Column to match (core column of that table).
	 * @param array<int,mixed> $values     Values.
	 * @param string           $format     %d|%s.
	 * @param string           $extra      Additional prepared condition.
	 * @param array<int,mixed> $extra_args Arguments of the additional condition.
	 * @return array<int,array<string,mixed>>
	 */
	private function rows( string $table, string $column, array $values, string $format = '%d', string $extra = '', array $extra_args = array() ): array {
		global $wpdb;

		$physical = Schema::physical( $wpdb, $table );
		if ( null === $physical || empty( $values ) || ! in_array( $column, Schema::columns( $table ), true ) ) {
			return array();
		}

		$select  = Schema::select_list( $table );
		$records = array();
		foreach ( array_chunk( array_values( array_unique( $values ) ), self::CHUNK ) as $chunk ) {
			$in  = Criteria::placeholders( count( $chunk ), '%d' === $format ? '%d' : '%s' );
			$sql = "SELECT {$select} FROM {$physical} WHERE {$column} IN ({$in})" . ( '' === $extra ? '' : " AND {$extra}" );
			foreach ( $this->select( $sql, array_merge( $chunk, $extra_args ), true ) as $row ) {
				$records[] = array(
					't' => $table,
					'r' => $row,
				);
			}
		}
		return $records;
	}

	// ---------------------------------------------------------------------
	// Deletion.
	// ---------------------------------------------------------------------

	/**
	 * Delete a backed-up batch.
	 *
	 * @param string                         $key           Step key.
	 * @param int[]                          $ids           Ids (ascending).
	 * @param array<int,array<string,mixed>> $records       Backed up records.
	 * @param bool                           $transactional Use a transaction.
	 * @param float                          $start         Step start time.
	 * @param Job                            $job           Job.
	 * @return array{deleted:int,last:int,complete:bool}
	 * @throws \Throwable Rethrown after rolling the transaction back.
	 */
	private function delete( string $key, array $ids, array $records, bool $transactional, float $start, Job $job ): array {
		$engine = $this->service->engine();
		$result = array(
			'deleted'  => 0,
			'last'     => 0,
			'complete' => true,
		);

		if ( $transactional && ! $engine->begin() ) {
			$transactional = false;
		}

		try {
			switch ( $key ) {
				case 'revisions':
				case 'auto_drafts':
				case 'trashed_posts':
				case 'spam_comments':
				case 'trashed_comments':
					$excluded = (array) $job->get( 'excluded', Criteria::excluded_post_types() );
					foreach ( $ids as $index => $id ) {
						if ( $index > 0 && microtime( true ) - $start > self::TIME_BUDGET ) {
							$result['complete'] = false;
							break;
						}
						$ok = in_array( $key, array( 'spam_comments', 'trashed_comments' ), true )
							? $this->delete_comment( $key, $id )
							: $this->delete_post( $key, $id, $excluded );
						if ( $ok ) {
							++$result['deleted'];
						}
						$result['last'] = $id;
					}
					break;

				case 'expired_transients':
					$result['deleted'] = $this->delete_options( $ids, $records );
					break;

				case 'expired_site_transients':
					$result['deleted'] = $this->delete_sitemeta( $ids, $records );
					break;

				default:
					$result['deleted'] = $this->delete_meta( $key, $ids, $records );
			}

			if ( $transactional ) {
				$engine->commit();
			}
		} catch ( \Throwable $e ) {
			if ( $transactional ) {
				$engine->rollback();
			}
			throw $e;
		}

		if ( $result['complete'] && ! empty( $ids ) ) {
			$result['last'] = (int) end( $ids );
		}
		return $result;
	}

	/**
	 * Delete one post through WordPress after re-checking it still qualifies.
	 *
	 * @param string   $key      Step key.
	 * @param int      $id       Post id.
	 * @param string[] $excluded Protected post types.
	 */
	private function delete_post( string $key, int $id, array $excluded ): bool {
		$post = get_post( $id );
		if ( ! $post ) {
			return false;
		}

		if ( 'revisions' === $key ) {
			if ( 'revision' !== $post->post_type || false !== strpos( (string) $post->post_name, Criteria::AUTOSAVE_PATTERN ) ) {
				return false;
			}
			return (bool) wp_delete_post_revision( $id );
		}

		if ( 'auto_drafts' === $key && 'auto-draft' !== $post->post_status ) {
			return false;
		}
		if ( 'trashed_posts' === $key && ( 'trash' !== $post->post_status || in_array( $post->post_type, $excluded, true ) ) ) {
			return false;
		}

		return (bool) wp_delete_post( $id, true );
	}

	/**
	 * Delete one comment through WordPress after re-checking its status.
	 *
	 * @param string $key Step key.
	 * @param int    $id  Comment id.
	 */
	private function delete_comment( string $key, int $id ): bool {
		$comment = get_comment( $id );
		$status  = 'spam_comments' === $key ? 'spam' : 'trash';
		if ( ! $comment || $status !== $comment->comment_approved ) {
			return false;
		}
		return (bool) wp_delete_comment( $id, true );
	}

	/**
	 * Delete expired transient rows (timeouts and values) by id.
	 *
	 * @param int[]                          $ids     Timeout option ids.
	 * @param array<int,array<string,mixed>> $records Backed up option rows.
	 * @return int Number of transients removed.
	 */
	private function delete_options( array $ids, array $records ): int {
		global $wpdb;

		$value_ids = array();
		$names     = array();
		foreach ( $records as $record ) {
			$option_id = (int) $record['r']['option_id'];
			$names[]   = (string) $record['r']['option_name'];
			if ( ! in_array( $option_id, $ids, true ) ) {
				$value_ids[] = $option_id;
			}
		}

		$deleted = $this->delete_by_ids( (string) $wpdb->options, 'option_id', $ids );
		$this->delete_by_ids( (string) $wpdb->options, 'option_id', $value_ids );

		if ( function_exists( 'wp_cache_delete' ) ) {
			foreach ( $names as $name ) {
				wp_cache_delete( $name, 'options' );
			}
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}

		return $deleted;
	}

	/**
	 * Delete expired network site transients by id.
	 *
	 * @param int[]                          $ids     Timeout meta ids.
	 * @param array<int,array<string,mixed>> $records Backed up sitemeta rows.
	 */
	private function delete_sitemeta( array $ids, array $records ): int {
		global $wpdb;

		$value_ids = array();
		$keys      = array();
		foreach ( $records as $record ) {
			$meta_id = (int) $record['r']['meta_id'];
			$keys[]  = (int) $record['r']['site_id'] . ':' . (string) $record['r']['meta_key'];
			if ( ! in_array( $meta_id, $ids, true ) ) {
				$value_ids[] = $meta_id;
			}
		}

		$deleted = $this->delete_by_ids( (string) $wpdb->sitemeta, 'meta_id', $ids );
		$this->delete_by_ids( (string) $wpdb->sitemeta, 'meta_id', $value_ids );

		if ( function_exists( 'wp_cache_delete' ) ) {
			foreach ( $keys as $key ) {
				wp_cache_delete( $key, 'site-options' );
			}
		}

		return $deleted;
	}

	/**
	 * Delete orphaned meta rows by id.
	 *
	 * @param string                         $key     Step key.
	 * @param int[]                          $ids     Meta ids.
	 * @param array<int,array<string,mixed>> $records Backed up rows.
	 */
	private function delete_meta( string $key, array $ids, array $records ): int {
		global $wpdb;

		$definition = Criteria::orphaned_meta( $wpdb, substr( $key, 9, -4 ) );
		if ( null === $definition ) {
			return 0;
		}

		// Only ids whose rows made it into the backup.
		$backed_up = array();
		$objects   = array();
		foreach ( $records as $record ) {
			$backed_up[] = (int) $record['r']['meta_id'];
			$objects[]   = (int) $record['r'][ $definition['meta_fk'] ];
		}
		$deleted = $this->delete_by_ids( $definition['meta'], 'meta_id', array_values( array_intersect( $ids, $backed_up ) ) );

		if ( function_exists( 'wp_cache_delete' ) ) {
			foreach ( array_unique( $objects ) as $object_id ) {
				wp_cache_delete( $object_id, $definition['cache'] );
			}
		}

		return $deleted;
	}

	/**
	 * Prepared DELETE … WHERE {column} IN (ids), chunked.
	 *
	 * @param string $table  Physical table (from $wpdb).
	 * @param string $column Id column (fixed by the caller).
	 * @param int[]  $ids    Ids.
	 * @return int Deleted rows.
	 * @throws \RuntimeException When a delete query fails.
	 */
	private function delete_by_ids( string $table, string $column, array $ids ): int {
		global $wpdb;

		$deleted = 0;
		foreach ( array_chunk( array_values( array_unique( array_map( 'intval', $ids ) ) ), self::CHUNK ) as $chunk ) {
			$in = Criteria::placeholders( count( $chunk ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Core table from $wpdb, prepared id placeholders.
			$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$column} IN ({$in})", $chunk ) );
			if ( false === $result ) {
				throw new \RuntimeException( 'Delete query failed.' );
			}
			$deleted += (int) $result;
		}
		return $deleted;
	}

	// ---------------------------------------------------------------------
	// Internals.
	// ---------------------------------------------------------------------

	/**
	 * Run a SELECT and return rows (throws on database errors so nothing is deleted on partial data).
	 *
	 * @param string           $sql      SQL template (table names from $wpdb only).
	 * @param array<int,mixed> $args     Arguments.
	 * @param bool             $strict   Throw on error.
	 * @return array<int,array<string,mixed>>
	 * @throws \RuntimeException On database errors.
	 */
	private function select( string $sql, array $args, bool $strict = true ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Table names come from $wpdb, values are prepared.
		if ( ! is_array( $rows ) || ( $strict && '' !== (string) $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Database query failed while preparing the cleanup.' );
		}
		return $rows;
	}

	/**
	 * Current batch size of a step (adapted to the server's speed).
	 *
	 * @param string $key Step key.
	 * @param Job    $job Job.
	 */
	private function batch_size( string $key, Job $job ): int {
		$default = self::BATCH_SIZES[ $key ] ?? 100;
		$size    = (int) $job->get( 'batch_' . $key, $default );
		return max( 5, min( self::MAX_BATCH, $default, $size ) );
	}

	/**
	 * Halve the batch size after slow batches, grow it back after fast ones.
	 *
	 * @param string $key     Step key.
	 * @param Job    $job     Job.
	 * @param float  $elapsed Seconds.
	 */
	private function adapt_batch_size( string $key, Job $job, float $elapsed ): void {
		$size = $this->batch_size( $key, $job );
		if ( $elapsed > self::TIME_BUDGET * 0.6 ) {
			$size = (int) max( 5, floor( $size / 2 ) );
		} elseif ( $elapsed < 1.0 ) {
			$size = (int) min( self::BATCH_SIZES[ $key ] ?? 100, ceil( $size * 1.5 ) );
		}
		$job->set( 'batch_' . $key, $size );
	}

	/**
	 * Physical tables a cleanup item deletes from.
	 *
	 * @param string $item Item.
	 * @return string[]
	 */
	private function tables_for( string $item ): array {
		global $wpdb;

		$map    = array(
			'revisions'            => array( 'posts', 'postmeta', 'term_relationships', 'comments', 'commentmeta' ),
			'auto_drafts'          => array( 'posts', 'postmeta', 'term_relationships', 'comments', 'commentmeta' ),
			'trashed_posts'        => array( 'posts', 'postmeta', 'term_relationships', 'comments', 'commentmeta' ),
			'spam_comments'        => array( 'comments', 'commentmeta' ),
			'trashed_comments'     => array( 'comments', 'commentmeta' ),
			'expired_transients'   => self::cleans_site_transients() ? array( 'options', 'sitemeta' ) : array( 'options' ),
			'orphaned_postmeta'    => array( 'postmeta' ),
			'orphaned_commentmeta' => array( 'commentmeta' ),
			'orphaned_termmeta'    => array( 'termmeta' ),
		);
		$tables = array();
		foreach ( $map[ $item ] ?? array() as $logical ) {
			$physical = Schema::physical( $wpdb, $logical );
			if ( null !== $physical ) {
				$tables[] = $physical;
			}
		}
		return $tables;
	}

	/**
	 * Whether network site transients are cleaned (main site of a network only).
	 */
	private static function cleans_site_transients(): bool {
		return function_exists( 'is_multisite' ) && is_multisite() && Analyzer::manages_network_data();
	}

	/**
	 * Build the summary.
	 *
	 * @param Job    $job    Job.
	 * @param string $status complete|partial.
	 * @return array<string,mixed>
	 */
	private function summary( Job $job, string $status ): array {
		$items     = (array) $job->get( 'items', array() );
		$deleted   = (array) $job->get( 'deleted', array() );
		$counts    = (array) $job->get( 'counts_before', array() );
		$backup_id = (string) $job->get( 'backup_id', '' );
		$manifest  = '' === $backup_id ? null : $this->service->store()->manifest( $backup_id );

		$per_item = array();
		foreach ( $items as $item ) {
			$per_item[ $item ] = array(
				'found'          => $counts[ $item ] ?? null,
				'deleted'        => (int) ( $deleted[ $item ] ?? 0 ),
				'backed_up_rows' => (int) ( $manifest['item_rows'][ $item ] ?? 0 ),
			);
		}

		$before      = (array) $job->get( 'sizes_before', array() );
		$after       = ( 'complete' === $status && ! empty( $items ) ) ? $this->service->analyzer( true )->sizes() : array();
		$table_delta = ( is_int( $before['tables_bytes'] ?? null ) && is_int( $after['tables_bytes'] ?? null ) ) ? max( 0, $before['tables_bytes'] - $after['tables_bytes'] ) : null;
		$auto_delta  = ( is_int( $before['autoload_bytes'] ?? null ) && is_int( $after['autoload_bytes'] ?? null ) ) ? max( 0, $before['autoload_bytes'] - $after['autoload_bytes'] ) : null;
		$data_bytes  = null === $manifest ? 0 : (int) $manifest['data_bytes'];

		return array(
			'status'                 => $status,
			'items'                  => $per_item,
			'deleted_total'          => array_sum( array_map( 'intval', $deleted ) ),
			'keep_revisions'         => (int) $job->get( 'keep', self::DEFAULT_KEEP ),
			'backup_id'              => null === $manifest ? null : $backup_id,
			'backup_rows'            => null === $manifest ? 0 : (int) $manifest['rows'],
			'backup_bytes'           => null === $manifest ? 0 : (int) $manifest['bytes'],
			'table_size_delta_bytes' => $table_delta,
			'autoload_delta_bytes'   => $auto_delta,
			'removed_data_bytes'     => $data_bytes,
			'bytes_freed_estimate'   => ( null !== $table_delta && $table_delta > 0 ) ? $table_delta : $data_bytes,
			'bytes_freed_source'     => ( null !== $table_delta && $table_delta > 0 ) ? 'table_sizes' : 'row_data',
			'finished_at'            => time(),
		);
	}

	/**
	 * Mark the backup as finished (or delete it when it holds no rows).
	 *
	 * @param array<string,mixed> $summary Summary.
	 * @param string              $status  complete|partial.
	 * @return array<string,mixed> Summary with the final backup id.
	 */
	private function settle_backup( array $summary, string $status ): array {
		$backup_id = $summary['backup_id'];
		if ( null === $backup_id ) {
			return $summary;
		}

		$store = $this->service->store();
		if ( 0 === (int) $summary['backup_rows'] ) {
			$store->delete( $backup_id );
			$summary['backup_id'] = null;
			return $summary;
		}

		$deleted = array();
		foreach ( $summary['items'] as $item => $data ) {
			$deleted[ $item ] = $data['deleted'];
		}
		$store->update(
			$backup_id,
			array(
				'status'   => $status,
				'finished' => time(),
				'deleted'  => $deleted,
			)
		);
		return $summary;
	}

	/**
	 * Log context (counts and ids only, no row data).
	 *
	 * @param array<string,mixed> $summary Summary.
	 * @return array<string,mixed>
	 */
	private static function log_context( array $summary ): array {
		$deleted = array();
		foreach ( (array) $summary['items'] as $item => $data ) {
			$deleted[ $item ] = (int) $data['deleted'];
		}
		return array(
			'deleted'              => $deleted,
			'deleted_total'        => (int) $summary['deleted_total'],
			'backup_id'            => $summary['backup_id'],
			'bytes_freed_estimate' => $summary['bytes_freed_estimate'],
			'status'               => $summary['status'],
		);
	}
}
