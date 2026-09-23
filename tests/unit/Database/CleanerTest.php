<?php
/**
 * Tests for the cleanup job: pure helpers and the backup-before-delete flow.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Database;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Core\Jobs\Job;
use SH\SpeedOptimizer\Core\Jobs\StepResult;
use SH\SpeedOptimizer\Core\Plugin;
use SH\SpeedOptimizer\Database\BackupStore;
use SH\SpeedOptimizer\Database\Cleaner;
use SH\SpeedOptimizer\Database\DatabaseService;

final class CleanerTest extends TestCase {

	/**
	 * Previous global $wpdb.
	 *
	 * @var mixed
	 */
	private $previous_wpdb;

	protected function setUp(): void {
		shso_test_reset();
		Plugin::reset();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		$service = new DatabaseService( Plugin::instance() );
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \SHSO_Test_Fake_WPDB ) {
			Plugin::instance()->filesystem()->delete_tree( $service->store()->base_dir( true ), false );
		}
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		Plugin::reset();
	}

	// ------------------------------------------------------------------
	// Pure helpers.
	// ------------------------------------------------------------------

	public function test_items_are_validated_against_the_whitelist(): void {
		$this->assertSame(
			array( 'revisions', 'spam_comments', 'orphaned_termmeta' ),
			Cleaner::sanitize_items( array( 'orphaned_termmeta', 'spam_comments', 'users', 'revisions', 'revisions', 'Revisions', ' spam_comments', 'orphaned_relationships', 42, null, array( 'revisions' ) ) )
		);
		$this->assertSame( array(), Cleaner::sanitize_items( 'revisions' ) );
		$this->assertSame( array(), Cleaner::sanitize_items( null ) );
		$this->assertSame( Cleaner::ITEMS, Cleaner::sanitize_items( array_reverse( Cleaner::ITEMS ) ) );
	}

	public function test_keep_revisions_is_clamped(): void {
		$this->assertSame( 5, Cleaner::clamp_keep( null ) );
		$this->assertSame( 5, Cleaner::clamp_keep( 'abc' ) );
		$this->assertSame( 5, Cleaner::clamp_keep( array( 3 ) ) );
		$this->assertSame( 0, Cleaner::clamp_keep( -3 ) );
		$this->assertSame( 0, Cleaner::clamp_keep( 0 ) );
		$this->assertSame( 50, Cleaner::clamp_keep( 99 ) );
		$this->assertSame( 7, Cleaner::clamp_keep( '7' ) );
		$this->assertSame( 7, Cleaner::clamp_keep( 7.9 ) );
		$this->assertSame( 5, Cleaner::clamp_keep( '5; DROP TABLE' ) );
	}

	public function test_keep_newest_revisions_per_post(): void {
		$rows = array(
			// Post 10: five revisions, one autosave.
			array(
				'ID'          => 101,
				'post_parent' => 10,
				'post_date'   => '2024-01-01 10:00:00',
				'post_name'   => '10-revision-v1',
			),
			array(
				'ID'          => 102,
				'post_parent' => 10,
				'post_date'   => '2024-01-02 10:00:00',
				'post_name'   => '10-revision-v1',
			),
			array(
				'ID'          => 105,
				'post_parent' => 10,
				'post_date'   => '2024-01-05 10:00:00',
				'post_name'   => '10-revision-v1',
			),
			array(
				'ID'          => 103,
				'post_parent' => 10,
				'post_date'   => '2024-01-03 10:00:00',
				'post_name'   => '10-revision-v1',
			),
			array(
				'ID'          => 104,
				'post_parent' => 10,
				'post_date'   => '2024-01-03 10:00:00', // Same date as 103: the higher ID is newer.
				'post_name'   => '10-revision-v1',
			),
			array(
				'ID'          => 100,
				'post_parent' => 10,
				'post_date'   => '2023-01-01 10:00:00',
				'post_name'   => '10-autosave-v1',
			),
			// Post 20: two revisions.
			array(
				'ID'          => 201,
				'post_parent' => 20,
				'post_date'   => '2024-01-01 10:00:00',
				'post_name'   => '20-revision-v1',
			),
			array(
				'ID'          => 202,
				'post_parent' => 20,
				'post_date'   => '2024-01-02 10:00:00',
				'post_name'   => '20-revision-v1',
			),
		);

		$this->assertSame( array( 101, 102 ), Cleaner::select_revisions_to_delete( $rows, 3 ), 'Keeps 105, 104, 103; autosave 100 untouched.' );
		$this->assertSame( array( 101, 102, 103, 104, 201 ), Cleaner::select_revisions_to_delete( $rows, 1 ) );
		$this->assertSame( array( 101, 102, 103, 104, 105, 201, 202 ), Cleaner::select_revisions_to_delete( $rows, 0 ) );
		$this->assertSame( array(), Cleaner::select_revisions_to_delete( $rows, 5 ) );
		$this->assertSame( array(), Cleaner::select_revisions_to_delete( array(), 5 ) );
	}

	/**
	 * Revision rows of one post: ids $first..$last, dated in id order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function revisions_of( int $parent, int $first, int $last, int $bytes = 10 ): array {
		$rows = array();
		for ( $id = $first; $id <= $last; $id++ ) {
			$rows[] = array(
				'ID'          => $id,
				'post_parent' => $parent,
				'post_date'   => gmdate( 'Y-m-d H:i:s', 1700000000 + $id ),
				'post_name'   => $parent . '-revision-v1',
				'bytes'       => $bytes,
			);
		}
		return $rows;
	}

	public function test_revision_batch_stops_inside_a_post_and_continues_there(): void {
		$groups = array(
			10 => self::revisions_of( 10, 1, 5 ),  // 4 removable with keep 1.
			20 => self::revisions_of( 20, 6, 8 ),  // 2 removable.
		);

		$batch = Cleaner::plan_revisions( $groups, 0, 3, 1, false );
		$this->assertSame( array( 1, 2, 3 ), $batch['ids'] );
		$this->assertSame( 10, $batch['cursor'], 'Continue with post 10.' );
		$this->assertSame( 11, $batch['skip'] );
		$this->assertTrue( $batch['more'] );

		$batch = Cleaner::plan_revisions( $groups, 0, 10, 1, false );
		$this->assertSame( array( 1, 2, 3, 4, 6, 7 ), $batch['ids'] );
		$this->assertSame( 21, $batch['cursor'] );
		$this->assertFalse( $batch['more'] );

		$batch = Cleaner::plan_revisions( $groups, 0, 4, 1, false );
		$this->assertSame( array( 1, 2, 3, 4 ), $batch['ids'] );
		$this->assertSame( 11, $batch['cursor'], 'Post 10 is complete, post 20 is next.' );
		$this->assertTrue( $batch['more'] );

		$this->assertTrue( Cleaner::plan_revisions( $groups, 0, 10, 1, true )['more'], 'More posts follow.' );
	}

	public function test_revision_batch_respects_the_byte_budget(): void {
		$groups = array( 10 => self::revisions_of( 10, 1, 4, 600 ) );
		$this->assertSame( array( 1 ), Cleaner::plan_revisions( $groups, 0, 100, 0, false, 1000 )['ids'] );
		$this->assertSame( array( 1 ), Cleaner::plan_revisions( $groups, 0, 100, 0, false, 10 )['ids'], 'At least one row per batch.' );
	}

	public function test_cursor_rules(): void {
		$batch = array(
			'cursor' => 10,
			'skip'   => 11,
		);
		$this->assertSame( 11, Cleaner::next_cursor( 'revisions', $batch, array( 'deleted' => 0, 'last' => 3, 'complete' => true ), 0 ) );
		$this->assertSame( 10, Cleaner::next_cursor( 'revisions', $batch, array( 'deleted' => 3, 'last' => 3, 'complete' => true ), 0 ) );
		$this->assertSame( 4, Cleaner::next_cursor( 'revisions', $batch, array( 'deleted' => 1, 'last' => 3, 'complete' => false ), 4 ) );

		$batch = array(
			'cursor' => 500,
			'skip'   => 500,
		);
		$this->assertSame( 500, Cleaner::next_cursor( 'spam_comments', $batch, array( 'deleted' => 0, 'last' => 500, 'complete' => true ), 100 ) );
		$this->assertSame( 250, Cleaner::next_cursor( 'spam_comments', $batch, array( 'deleted' => 150, 'last' => 250, 'complete' => false ), 100 ) );
	}

	public function test_revision_cleanup_terminates_even_when_deletions_fail(): void {
		// Post 10 has 300 removable revisions that can never be deleted, post 20 has 5 that can.
		$revisions = array(
			10 => self::revisions_of( 10, 1, 301 ),
			20 => self::revisions_of( 20, 1000, 1005 ),
		);
		$cursor    = 0;
		$deleted   = array();

		for ( $round = 0; $round < 20; $round++ ) {
			$groups = array_filter(
				$revisions,
				static fn( array $rows, int $parent ): bool => $parent >= $cursor && count( $rows ) > 1,
				ARRAY_FILTER_USE_BOTH
			);
			$batch  = Cleaner::plan_revisions( $groups, $cursor, 200, 1, false );
			if ( empty( $batch['ids'] ) ) {
				$cursor = $batch['cursor'];
				if ( ! $batch['more'] ) {
					break;
				}
				continue;
			}

			$ok = array_values( array_filter( $batch['ids'], static fn( int $id ): bool => $id >= 1000 ) );
			foreach ( $revisions as $parent => $rows ) {
				$revisions[ $parent ] = array_values( array_filter( $rows, static fn( array $row ): bool => ! in_array( $row['ID'], $ok, true ) ) );
			}
			$deleted = array_merge( $deleted, $ok );

			$cursor = Cleaner::next_cursor(
				'revisions',
				$batch,
				array(
					'deleted'  => count( $ok ),
					'last'     => (int) end( $batch['ids'] ),
					'complete' => true,
				),
				$cursor
			);
			if ( ! $batch['more'] ) {
				break;
			}
		}

		$this->assertLessThan( 20, $round, 'The loop ended.' );
		$this->assertSame( array( 1000, 1001, 1002, 1003, 1004 ), $deleted, 'The deletable revisions of the next post were still cleaned.' );
	}

	public function test_cap_by_bytes_keeps_at_least_one_row(): void {
		$rows = array(
			array(
				'id'    => 1,
				'bytes' => 600,
			),
			array(
				'id'    => 2,
				'bytes' => 300,
			),
			array(
				'id'    => 3,
				'bytes' => 300,
			),
		);
		$this->assertSame( array( 1, 2 ), array_column( Cleaner::cap_by_bytes( $rows, 1000 ), 'id' ) );
		$this->assertSame( array( 1 ), array_column( Cleaner::cap_by_bytes( $rows, 10 ), 'id' ) );
		$this->assertSame( array( 1, 2, 3 ), array_column( Cleaner::cap_by_bytes( $rows, 5000 ), 'id' ) );
	}

	public function test_steps(): void {
		$this->assertSame( array( 'prepare', 'clean_revisions', 'clean_expired_transients', 'finalize' ), Cleaner::steps_for( array( 'expired_transients', 'revisions', 'evil' ), false ) );
		$this->assertSame( array( 'prepare', 'clean_expired_transients', 'clean_expired_site_transients', 'finalize' ), Cleaner::steps_for( array( 'expired_transients' ), true ) );
		$this->assertSame( array( 'prepare', 'finalize' ), Cleaner::steps_for( array(), false ) );
	}

	public function test_transient_value_names(): void {
		$this->assertSame( '_transient_feed_x', Cleaner::transient_value_name( '_transient_timeout_feed_x' ) );
		$this->assertSame( '_site_transient_update_core', Cleaner::transient_value_name( '_site_transient_timeout_update_core' ) );
		$this->assertNull( Cleaner::transient_value_name( '_transient_timeout_' ) );
		$this->assertNull( Cleaner::transient_value_name( 'blogname' ) );
	}

	public function test_labels_are_plain_language(): void {
		$cleaner = $this->cleaner();
		foreach ( Cleaner::steps_for( Cleaner::ITEMS, true ) as $step ) {
			$this->assertNotSame( '', $cleaner->label( $step ) );
		}
		$this->assertSame( 'Backing up and emptying the trash…', $cleaner->label( 'clean_trashed_posts' ) );
	}

	// ------------------------------------------------------------------
	// Job flow.
	// ------------------------------------------------------------------

	private function cleaner(): Cleaner {
		return Plugin::instance()->database()->job_handler();
	}

	/**
	 * @param array<string,mixed> $args Job args.
	 */
	private function job( array $args ): Job {
		return new Job(
			array(
				'id'     => 'job123',
				'type'   => 'db_clean',
				'status' => Job::RUNNING,
				'steps'  => $this->cleaner()->steps( $args ),
				'args'   => $args,
			)
		);
	}

	/**
	 * Run all steps like the job manager does.
	 */
	private function run_job( Job $job ): void {
		$cleaner = $this->cleaner();
		$guard   = 0;
		while ( null !== ( $step = $job->current_step() ) && $guard++ < 50 ) {
			$result = $cleaner->run_step( $step, $job );
			if ( StepResult::DONE === $result->status ) {
				$job->advance();
			}
		}
		$job->set_status( Job::DONE );
		$cleaner->complete( $job );
	}

	/**
	 * Fake database with two orphaned post meta rows.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows returned for the backup.
	 */
	private function orphan_db( array $rows, array &$state ): \SHSO_Test_Fake_WPDB {
		$wpdb = new \SHSO_Test_Fake_WPDB();
		$wpdb->on( '/^SELECT COUNT\(\*\) FROM wp_postmeta m LEFT JOIN wp_posts o/', '2' );
		$wpdb->on(
			'/^SELECT m\.meta_id AS id, LENGTH\(m\.meta_value\) AS bytes FROM wp_postmeta m/',
			static function ( string $sql ) {
				return false !== strpos( $sql, 'm.meta_id > 0 ' )
					? array(
						array(
							'id'    => '11',
							'bytes' => '5',
						),
						array(
							'id'    => '12',
							'bytes' => '3',
						),
					)
					: array();
			}
		);
		$wpdb->on( '/^SELECT meta_id, post_id, meta_key, meta_value FROM wp_postmeta WHERE meta_id IN \(11, 12\)$/', $rows );
		$wpdb->on(
			'/^DELETE FROM wp_postmeta WHERE meta_id IN \(11, 12\)$/',
			static function () use ( &$state ) {
				$service                  = Plugin::instance()->database();
				$backups                  = $service->store()->all();
				$state['backup_at_delete'] = ! empty( $backups ) && 2 === $backups[0]['rows'] && is_file( $service->store()->dir( $backups[0]['id'] ) . 'part-0001.json.gz' );
				return 2;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
		return $wpdb;
	}

	public function test_backup_is_written_before_rows_are_deleted(): void {
		$state = array();
		$rows  = array(
			array(
				'meta_id'    => '11',
				'post_id'    => '999',
				'meta_key'   => '_old',
				'meta_value' => 'hello',
			),
			array(
				'meta_id'    => '12',
				'post_id'    => '998',
				'meta_key'   => '_old',
				'meta_value' => 'abc',
			),
		);
		$wpdb  = $this->orphan_db( $rows, $state );
		$job   = $this->job(
			array(
				'items'          => array( 'orphaned_postmeta', 'unknown_item' ),
				'keep_revisions' => 'x',
			)
		);

		$this->assertSame( array( 'prepare', 'clean_orphaned_postmeta', 'finalize' ), $job->remaining_steps() );
		$this->run_job( $job );

		$this->assertTrue( $state['backup_at_delete'] ?? false, 'The backup part existed and was recorded before DELETE ran.' );

		$summary = $job->get( 'summary' );
		$this->assertSame( 'complete', $summary['status'] );
		$this->assertSame( 2, $summary['deleted_total'] );
		$this->assertSame( 2, $summary['items']['orphaned_postmeta']['deleted'] );
		$this->assertSame( 2, $summary['items']['orphaned_postmeta']['found'] );
		$this->assertSame( 2, $summary['items']['orphaned_postmeta']['backed_up_rows'] );
		$this->assertSame( 5, $summary['keep_revisions'] );
		$this->assertSame( 26, $summary['removed_data_bytes'], 'Measured size of the removed rows.' );
		$this->assertSame( 26, $summary['bytes_freed_estimate'] );
		$this->assertSame( 'row_data', $summary['bytes_freed_source'] );
		$this->assertNull( $summary['table_size_delta_bytes'], 'Table sizes are unavailable in this environment.' );
		$this->assertTrue( BackupStore::is_valid_id( $summary['backup_id'] ) );

		$service  = Plugin::instance()->database();
		$manifest = $service->store()->manifest( $summary['backup_id'] );
		$this->assertSame( 'complete', $manifest['status'] );
		$this->assertSame( array( 'orphaned_postmeta' => 2 ), $manifest['deleted'] );

		$backups = $service->backups();
		$this->assertCount( 1, $backups );
		$this->assertTrue( $backups[0]['restorable'] );
		$this->assertSame( array( 'orphaned_postmeta' ), $backups[0]['items'] );

		$this->assertContains( 'shso_db_cleaned', $GLOBALS['shso_test_actions'] );
		$this->assertSame( 'success', end( $job->to_array()['messages'] )['type'] );

		// Every query is prepared with the literal ids; no unbounded DELETE.
		foreach ( $wpdb->sql() as $sql ) {
			if ( 0 === strpos( $sql, 'DELETE' ) ) {
				$this->assertStringContainsString( 'WHERE meta_id IN (11, 12)', $sql );
			}
		}
	}

	public function test_backup_failure_stops_before_deleting(): void {
		$state = array();
		$rows  = array(
			array(
				'meta_id'    => '11',
				'post_id'    => '999',
				'meta_key'   => '_old',
				'meta_value' => array( 'not storable' ), // Makes the backup write fail.
			),
		);
		$wpdb  = $this->orphan_db( $rows, $state );
		$job   = $this->job( array( 'items' => array( 'orphaned_postmeta' ) ) );

		$cleaner = $this->cleaner();
		$this->assertSame( StepResult::DONE, $cleaner->run_step( 'prepare', $job )->status );
		$job->advance();

		try {
			$cleaner->run_step( 'clean_orphaned_postmeta', $job );
			$this->fail( 'The step must stop the job.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'backup', strtolower( $e->getMessage() ) );
		}

		foreach ( $wpdb->sql() as $sql ) {
			$this->assertStringNotContainsString( 'DELETE', $sql, 'Nothing is deleted when the backup fails.' );
		}
		$this->assertSame( 'error', end( $job->to_array()['messages'] )['type'] );

		// The job manager then aborts: the empty backup is removed, nothing was deleted.
		$cleaner->abort( $job, 'failed' );
		$summary = $job->get( 'summary' );
		$this->assertSame( 'partial', $summary['status'] );
		$this->assertSame( 0, $summary['deleted_total'] );
		$this->assertNull( $summary['backup_id'] );
		$this->assertSame( array(), Plugin::instance()->database()->backups() );
	}

	public function test_nothing_to_clean_creates_no_backup(): void {
		$wpdb = new \SHSO_Test_Fake_WPDB();
		$wpdb->on( '/SELECT COUNT/', '0' );
		$GLOBALS['wpdb'] = $wpdb;

		$job = $this->job( array( 'items' => array( 'orphaned_postmeta' ) ) );
		$this->run_job( $job );

		$this->assertSame( '', (string) $job->get( 'backup_id', '' ) );
		$this->assertSame( 0, $job->get( 'summary' )['deleted_total'] );
		$this->assertSame( array(), Plugin::instance()->database()->backups() );
		foreach ( $wpdb->sql() as $sql ) {
			$this->assertStringNotContainsString( 'DELETE', $sql );
		}
	}

	public function test_no_items_selected(): void {
		$GLOBALS['wpdb'] = new \SHSO_Test_Fake_WPDB();
		$job             = $this->job( array( 'items' => array( 'users', 'orphaned_relationships' ) ) );

		$this->assertSame( array( 'prepare', 'finalize' ), $job->remaining_steps() );
		$this->run_job( $job );
		$this->assertSame( array(), $job->get( 'summary' )['items'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->log, 'No database access without selected items.' );
	}

	public function test_abort_after_partial_cleanup_keeps_the_backup_restorable(): void {
		$state = array();
		$rows  = array(
			array(
				'meta_id'    => '11',
				'post_id'    => '999',
				'meta_key'   => '_old',
				'meta_value' => 'hello',
			),
			array(
				'meta_id'    => '12',
				'post_id'    => '998',
				'meta_key'   => '_old',
				'meta_value' => 'abc',
			),
		);
		$this->orphan_db( $rows, $state );
		$job     = $this->job( array( 'items' => array( 'orphaned_postmeta' ) ) );
		$cleaner = $this->cleaner();

		$cleaner->run_step( 'prepare', $job );
		$job->advance();
		$cleaner->run_step( 'clean_orphaned_postmeta', $job );
		$cleaner->abort( $job, 'Cancelled.' );

		$summary = $job->get( 'summary' );
		$this->assertSame( 'partial', $summary['status'] );
		$this->assertSame( 2, $summary['deleted_total'] );
		$this->assertSame( 'Cancelled.', $summary['reason'] );

		$manifest = Plugin::instance()->database()->store()->manifest( $summary['backup_id'] );
		$this->assertSame( 'partial', $manifest['status'] );
		$this->assertTrue( Plugin::instance()->database()->backups()[0]['restorable'] );
		$this->assertSame( 'warning', end( $job->to_array()['messages'] )['type'] );
	}

	public function test_service_rejects_invalid_backup_ids(): void {
		$GLOBALS['wpdb'] = new \SHSO_Test_Fake_WPDB();
		$service         = Plugin::instance()->database();

		$this->assertInstanceOf( \WP_Error::class, $service->restore( '../../../wp-config' ) );
		$this->assertFalse( $service->delete_backup( '../backups' ) );
		$this->assertFalse( $service->delete_backup( 'db-20260101-000000-0123456789abcdef' ) );
	}
}
