<?php
/**
 * Tests for restoring backups: record whitelist, site checks, skip-existing, rollback.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Core\Filesystem;
use SH\SpeedOptimizer\Database\BackupStore;
use SH\SpeedOptimizer\Database\Engine;
use SH\SpeedOptimizer\Database\Restorer;

final class RestorerTest extends TestCase {

	private const HASH = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

	private Filesystem $fs;

	private BackupStore $store;

	protected function setUp(): void {
		shso_test_reset();
		$this->fs    = new Filesystem();
		$this->store = new BackupStore( $this->fs, self::HASH, 'wp_', false );
		$this->fs->delete_tree( $this->store->base_dir( true ), false );
	}

	protected function tearDown(): void {
		$this->fs->delete_tree( $this->store->base_dir( true ), false );
	}

	/**
	 * Backup with a revision, two meta rows and a parent link.
	 */
	private function backup(): string {
		$id = $this->store->create( array( 'items' => array( 'trashed_posts' ) ) );
		$this->store->append(
			$id,
			'trashed_posts',
			array(
				array(
					't' => 'posts',
					'r' => array(
						'ID'          => '12',
						'post_title'  => 'Old page',
						'post_status' => 'trash',
						'post_parent' => '0',
					),
				),
				array(
					't' => 'postmeta',
					'r' => array(
						'meta_id'    => '99',
						'post_id'    => '12',
						'meta_key'   => '_data',
						'meta_value' => "\xff binary",
					),
				),
				array(
					't' => 'postmeta',
					'r' => array(
						'meta_id'    => '100',
						'post_id'    => '12',
						'meta_key'   => '_exists_again',
						'meta_value' => 'x',
					),
				),
				array(
					't'    => 'posts',
					'link' => array(
						'id'   => 30,
						'from' => 0,
						'to'   => 12,
					),
				),
			)
		);
		$this->store->update( $id, array( 'status' => 'complete' ) );
		return $id;
	}

	private function fake(): \SHSO_Test_Fake_WPDB {
		$wpdb = new \SHSO_Test_Fake_WPDB();
		$wpdb->on( '/^SELECT ID FROM wp_posts WHERE ID IN \(12\)$/', array() );
		$wpdb->on( '/^SELECT meta_id FROM wp_postmeta WHERE meta_id IN \(99, 100\)$/', array( array( 'meta_id' => '100' ) ) );
		$wpdb->on( '/^UPDATE wp_posts SET post_parent = 12 WHERE ID = 30 AND post_parent = 0$/', 1 );
		return $wpdb;
	}

	public function test_restore_inserts_missing_rows_skips_existing_and_relinks(): void {
		$id     = $this->backup();
		$wpdb   = $this->fake();
		$result = ( new Restorer( $wpdb, $this->store, new Engine( $wpdb, true ), self::HASH, false ) )->restore( $id );

		$this->assertIsArray( $result );
		$this->assertSame(
			array(
				'posts'    => 1,
				'postmeta' => 1,
			),
			$result['inserted']
		);
		$this->assertSame( array( 'postmeta' => 1 ), $result['skipped'] );
		$this->assertSame( 0, $result['total_failed'] );
		$this->assertSame( 1, $result['links'] );
		$this->assertFalse( $result['transactional'] );

		$this->assertSame( 'wp_posts', $wpdb->inserted[0]['table'] );
		$this->assertSame( 'wp_postmeta', $wpdb->inserted[1]['table'] );
		$this->assertSame( "\xff binary", $wpdb->inserted[1]['data']['meta_value'] );
		$this->assertCount( 2, $wpdb->inserted );

		$this->assertGreaterThan( 0, $this->store->manifest( $id )['restored_at'] );
	}

	public function test_backup_of_another_site_is_rejected_before_any_write(): void {
		$id     = $this->backup();
		$wpdb   = $this->fake();
		$result = ( new Restorer( $wpdb, $this->store, new Engine( $wpdb, true ), str_repeat( 'e', 64 ), false ) )->restore( $id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'shso_backup_site', $result->get_error_code() );
		$this->assertSame( array(), $wpdb->inserted );
		$this->assertSame( array(), $wpdb->log );
	}

	public function test_other_table_prefix_is_rejected(): void {
		$id           = $this->backup();
		$wpdb         = $this->fake();
		$wpdb->prefix = 'wp_7_';
		$result       = ( new Restorer( $wpdb, $this->store, new Engine( $wpdb, true ), self::HASH, false ) )->restore( $id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'shso_backup_site', $result->get_error_code() );
	}

	public function test_crafted_part_with_foreign_table_or_column_is_rejected(): void {
		foreach ( array(
			array(
				't' => 'users',
				'r' => array(
					'ID'        => '1',
					'user_pass' => 'x',
				),
			),
			array(
				't' => 'options',
				'r' => array(
					'option_id'   => '1',
					'option_name' => 'x',
					'user_pass'   => 'y',
				),
			),
		) as $record ) {
			$id  = $this->backup();
			$dir = (string) $this->store->dir( $id );
			$raw = gzencode( json_encode( array( 'shso' => 1 ) ) . "\n" . json_encode( $record ) . "\n" );
			file_put_contents( $dir . 'part-0002.json.gz', $raw );

			$manifest            = $this->store->manifest( $id );
			$manifest['parts'][] = array(
				'file'   => 'part-0002.json.gz',
				'item'   => 'trashed_posts',
				'rows'   => 1,
				'sha256' => hash( 'sha256', $raw ),
			);
			file_put_contents( $dir . 'manifest.json', json_encode( $manifest ) );

			$wpdb   = $this->fake();
			$result = ( new Restorer( $wpdb, $this->store, new Engine( $wpdb, true ), self::HASH, false ) )->restore( $id );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'shso_backup_tables', $result->get_error_code() );
			$this->assertSame( array(), $wpdb->inserted, 'Nothing is written when any record is invalid.' );
		}
	}

	public function test_failed_insert_rolls_back_on_innodb(): void {
		$id   = $this->backup();
		$wpdb = $this->fake();
		$wpdb->on(
			'/SHOW TABLE STATUS/',
			array(
				array(
					'Name'   => 'wp_posts',
					'Engine' => 'InnoDB',
				),
				array(
					'Name'   => 'wp_postmeta',
					'Engine' => 'InnoDB',
				),
			)
		);
		$wpdb->on( '/INSERT INTO wp_postmeta/', false );

		$result = ( new Restorer( $wpdb, $this->store, new Engine( $wpdb, false ), self::HASH, false ) )->restore( $id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'shso_restore_failed', $result->get_error_code() );
		$this->assertContains( 'START TRANSACTION', $wpdb->sql() );
		$this->assertContains( 'ROLLBACK', $wpdb->sql() );
		$this->assertNotContains( 'COMMIT', $wpdb->sql() );
	}

	public function test_empty_or_unknown_backups(): void {
		$wpdb     = $this->fake();
		$restorer = new Restorer( $wpdb, $this->store, new Engine( $wpdb, true ), self::HASH, false );

		$this->assertSame( 'shso_backup_invalid_id', $restorer->restore( '../../etc' )->get_error_code() );
		$this->assertSame( 'shso_backup_missing', $restorer->restore( 'db-20260101-000000-0123456789abcdef' )->get_error_code() );

		$empty = $this->store->create( array( 'items' => array( 'revisions' ) ) );
		$this->assertSame( 'shso_backup_empty', $restorer->restore( $empty )->get_error_code() );
	}

	// ------------------------------------------------------------------
	// Record validation.
	// ------------------------------------------------------------------

	/**
	 * @return array<string,array{0:mixed,1:bool}>
	 */
	public static function records(): array {
		return array(
			'post row'             => array(
				array(
					't' => 'posts',
					'r' => array(
						'ID'         => '5',
						'post_title' => 'x',
					),
				),
				true,
			),
			'relationship'         => array(
				array(
					't' => 'term_relationships',
					'r' => array(
						'object_id'        => '5',
						'term_taxonomy_id' => '2',
						'term_order'       => '0',
					),
				),
				true,
			),
			'post link'            => array(
				array(
					't'    => 'posts',
					'link' => array(
						'id'   => 3,
						'from' => 0,
						'to'   => 5,
					),
				),
				true,
			),
			'users table'          => array(
				array(
					't' => 'users',
					'r' => array( 'ID' => '1' ),
				),
				false,
			),
			'physical table name'  => array(
				array(
					't' => 'wp_posts',
					'r' => array( 'ID' => '1' ),
				),
				false,
			),
			'foreign column'       => array(
				array(
					't' => 'posts',
					'r' => array(
						'ID'        => '1',
						'user_pass' => 'x',
					),
				),
				false,
			),
			'sql in column name'   => array(
				array(
					't' => 'posts',
					'r' => array(
						'ID'                 => '1',
						'ID`=1; DROP TABLE x' => 'y',
					),
				),
				false,
			),
			'non numeric key'      => array(
				array(
					't' => 'posts',
					'r' => array( 'ID' => '1 OR 1=1' ),
				),
				false,
			),
			'missing composite pk' => array(
				array(
					't' => 'term_relationships',
					'r' => array( 'object_id' => '5' ),
				),
				false,
			),
			'array value'          => array(
				array(
					't' => 'postmeta',
					'r' => array(
						'meta_id'    => '1',
						'meta_value' => array( 'x' ),
					),
				),
				false,
			),
			'link on meta table'   => array(
				array(
					't'    => 'postmeta',
					'link' => array(
						'id'   => 3,
						'from' => 0,
						'to'   => 5,
					),
				),
				false,
			),
			'negative link'        => array(
				array(
					't'    => 'posts',
					'link' => array(
						'id'   => -3,
						'from' => 0,
						'to'   => 5,
					),
				),
				false,
			),
			'string link id'       => array(
				array(
					't'    => 'comments',
					'link' => array(
						'id'   => '3',
						'from' => 0,
						'to'   => 5,
					),
				),
				false,
			),
			'sitemeta single site' => array(
				array(
					't' => 'sitemeta',
					'r' => array( 'meta_id' => '1' ),
				),
				false,
			),
			'not an array'         => array( 'posts', false ),
		);
	}

	#[DataProvider( 'records' )]
	public function test_record_validation( $record, bool $valid ): void {
		$result = Restorer::validate_record( $record, false );
		if ( $valid ) {
			$this->assertTrue( $result );
		} else {
			$this->assertInstanceOf( \WP_Error::class, $result );
		}
	}

	public function test_sitemeta_records_allowed_on_multisite(): void {
		$record = array(
			't' => 'sitemeta',
			'r' => array(
				'meta_id'  => '1',
				'site_id'  => '1',
				'meta_key' => 'x',
			),
		);
		$this->assertTrue( Restorer::validate_record( $record, true ) );
	}
}
