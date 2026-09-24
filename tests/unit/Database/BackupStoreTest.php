<?php
/**
 * Tests for backup storage: id validation, part round trips, manifest validation.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Core\Filesystem;
use SH\SpeedOptimizer\Database\BackupStore;

final class BackupStoreTest extends TestCase {

	private const HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

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
	 * @return array<int,array<string,mixed>>
	 */
	private static function records(): array {
		return array(
			array(
				't' => 'posts',
				'r' => array(
					'ID'          => '12',
					'post_title'  => 'Grüße "quoted" \\ back',
					'post_type'   => 'revision',
					'post_parent' => '5',
				),
			),
			array(
				't' => 'postmeta',
				'r' => array(
					'meta_id'    => '99',
					'post_id'    => '12',
					'meta_key'   => '_binary',
					'meta_value' => "\xff\xfe\x00raw",
				),
			),
			array(
				't' => 'postmeta',
				'r' => array(
					'meta_id'    => '100',
					'post_id'    => '12',
					'meta_key'   => 'nullable',
					'meta_value' => null,
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
		);
	}

	// ------------------------------------------------------------------
	// Ids.
	// ------------------------------------------------------------------

	public function test_generated_ids_are_valid_and_unique(): void {
		$a = BackupStore::generate_id( 1790000000 );
		$b = BackupStore::generate_id( 1790000000 );
		$this->assertTrue( BackupStore::is_valid_id( $a ) );
		$this->assertMatchesRegularExpression( '/^db-20260921-\d{6}-[a-f0-9]{16}$/', $a );
		$this->assertNotSame( $a, $b );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function invalid_ids(): array {
		return array(
			'traversal'         => array( '../../wp-config' ),
			'traversal in id'   => array( 'db-20260101-000000-../../../etc/pw' ),
			'nested traversal'  => array( 'db-20260101-000000-0123456789abcdef/../../x' ),
			'uppercase hex'     => array( 'db-20260101-000000-0123456789ABCDEF' ),
			'short random'      => array( 'db-20260101-000000-0123456789abcde' ),
			'trailing newline'  => array( "db-20260101-000000-0123456789abcdef\n" ),
			'null byte'         => array( "db-20260101-000000-0123456789abcdef\0" ),
			'absolute'          => array( '/db-20260101-000000-0123456789abcdef' ),
			'backslash'         => array( 'db-20260101-000000-0123456789abcde\\' ),
			'empty'             => array( '' ),
			'array'             => array( array( 'db-20260101-000000-0123456789abcdef' ) ),
			'integer'           => array( 123 ),
			'null'              => array( null ),
		);
	}

	#[DataProvider( 'invalid_ids' )]
	public function test_invalid_ids_are_rejected( $id ): void {
		$this->assertFalse( BackupStore::is_valid_id( $id ) );
		if ( is_string( $id ) ) {
			$this->assertNull( $this->store->dir( $id ) );
			$this->assertNull( $this->store->manifest( $id ) );
			$this->assertFalse( $this->store->delete( $id ) );
			$this->assertFalse( $this->store->append( $id, 'revisions', self::records() ) );
		}
	}

	// ------------------------------------------------------------------
	// Storage.
	// ------------------------------------------------------------------

	public function test_create_append_read_round_trip(): void {
		$id = $this->store->create(
			array(
				'items'          => array( 'revisions' ),
				'keep_revisions' => 5,
				'job_id'         => 'job1',
			)
		);
		$this->assertIsString( $id );
		$dir = (string) $this->store->dir( $id );
		$this->assertFileExists( $dir . 'index.html' );
		$this->assertFileExists( $dir . '.htaccess', 'The backup directory denies web access.' );

		$this->assertTrue( $this->store->append( $id, 'revisions', self::records() ) );
		$this->assertTrue( $this->store->append( $id, 'revisions', array_slice( self::records(), 0, 1 ) ) );

		$manifest = $this->store->manifest( $id );
		$this->assertSame( 'in_progress', $manifest['status'] );
		$this->assertSame( 4, $manifest['rows'] );
		$this->assertSame( 1, $manifest['links'] );
		$this->assertSame(
			array(
				'posts'    => 2,
				'postmeta' => 2,
			),
			$manifest['tables']
		);
		$this->assertSame( 4, $manifest['item_rows']['revisions'] );
		$this->assertCount( 2, $manifest['parts'] );
		$this->assertSame( 'part-0001.json.gz', $manifest['parts'][0]['file'] );
		$this->assertSame( self::HASH, $manifest['site_hash'] );
		$this->assertSame( 'wp_', $manifest['prefix'] );
		$this->assertTrue( BackupStore::validate_manifest( $manifest, self::HASH, 'wp_', false ) );

		$part = $this->store->read_part( $id, $manifest['parts'][0] );
		$this->assertIsArray( $part );
		$this->assertSame( 'revisions', $part['header']['item'] );
		$this->assertCount( 4, $part['records'] );
		$this->assertSame( 'Grüße "quoted" \\ back', $part['records'][0]['r']['post_title'] );
		$this->assertSame( "\xff\xfe\x00raw", $part['records'][1]['r']['meta_value'], 'Binary data survives the round trip.' );
		$this->assertNull( $part['records'][2]['r']['meta_value'] );
		$this->assertSame(
			array(
				'id'   => 30,
				'from' => 0,
				'to'   => 12,
			),
			$part['records'][3]['link']
		);
	}

	public function test_tampered_part_is_detected(): void {
		$id = $this->store->create( array( 'items' => array( 'revisions' ) ) );
		$this->store->append( $id, 'revisions', self::records() );
		$manifest = $this->store->manifest( $id );

		file_put_contents( $this->store->dir( $id ) . 'part-0001.json.gz', gzencode( "{\"shso\":1}\n{\"t\":\"users\",\"r\":{\"ID\":\"1\"}}\n" ) );

		$result = $this->store->read_part( $id, $manifest['parts'][0] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'shso_backup_corrupt', $result->get_error_code() );
	}

	public function test_part_file_names_are_validated_on_read(): void {
		$id     = $this->store->create( array( 'items' => array( 'revisions' ) ) );
		$result = $this->store->read_part(
			$id,
			array(
				'file'   => '../../manifest.json',
				'sha256' => str_repeat( 'a', 64 ),
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_encode_refuses_tables_outside_the_whitelist(): void {
		$this->assertNull(
			BackupStore::encode_records(
				array(
					array(
						't' => 'users',
						'r' => array(
							'ID'        => '1',
							'user_pass' => 'x',
						),
					),
				),
				'revisions',
				1
			)
		);
		$sitemeta = array(
			array(
				't' => 'sitemeta',
				'r' => array(
					'meta_id'  => '1',
					'site_id'  => '1',
					'meta_key' => 'k',
				),
			),
		);
		$this->assertNull( BackupStore::encode_records( $sitemeta, 'expired_transients', 1, false ), 'sitemeta only exists on multisite.' );
		$this->assertIsArray( BackupStore::encode_records( $sitemeta, 'expired_transients', 1, true ) );
		$this->assertNull(
			BackupStore::encode_records(
				array(
					array(
						't' => 'posts',
						'r' => array( 'post_title' => 'no primary key' ),
					),
				),
				'revisions',
				1
			)
		);
	}

	public function test_encode_keeps_only_core_columns(): void {
		$encoded = BackupStore::encode_records(
			array(
				array(
					't' => 'options',
					'r' => array(
						'option_id'    => '5',
						'option_name'  => 'x',
						'option_value' => 'y',
						'autoload'     => 'no',
						'evil_column'  => 'dropped',
					),
				),
			),
			'expired_transients',
			1
		);
		$this->assertStringNotContainsString( 'evil_column', $encoded['raw'] );
		$this->assertSame( 1, $encoded['rows'] );
		$this->assertSame( 5, $encoded['data_bytes'] );
	}

	public function test_all_lists_newest_first_and_delete_removes_directory(): void {
		$first  = $this->store->create( array( 'items' => array( 'revisions' ) ) );
		$second = $this->store->create( array( 'items' => array( 'spam_comments' ) ) );
		$this->store->update( $first, array( 'created' => 1 ) ); // Protected: ignored.

		$manifest            = $this->store->manifest( $first );
		$manifest['created'] = time() - 100;
		file_put_contents( $this->store->dir( $first ) . 'manifest.json', json_encode( $manifest ) );

		$ids = array_column( $this->store->all(), 'id' );
		$this->assertSame( array( $second, $first ), $ids );

		$this->assertTrue( $this->store->delete( $first ) );
		$this->assertDirectoryDoesNotExist( (string) $this->store->dir( $first ) );
		$this->assertSame( array( $second ), array_column( $this->store->all(), 'id' ) );
	}

	public function test_prune_deletes_old_backups_but_keeps_used_and_recent_ones(): void {
		$old    = $this->store->create( array( 'items' => array( 'revisions' ) ) );
		$used   = $this->store->create( array( 'items' => array( 'revisions' ) ) );
		$recent = $this->store->create( array( 'items' => array( 'revisions' ) ) );
		foreach ( array( $old, $used, $recent ) as $id ) {
			$this->store->append( $id, 'revisions', self::records() );
		}
		foreach ( array( $old, $used ) as $id ) {
			$manifest            = $this->store->manifest( $id );
			$manifest['created'] = time() - 40 * DAY_IN_SECONDS;
			file_put_contents( $this->store->dir( $id ) . 'manifest.json', json_encode( $manifest ) );
		}

		$deleted = $this->store->prune( 30 * DAY_IN_SECONDS, static fn( string $id ): bool => $id === $used );

		$this->assertSame( 1, $deleted );
		$this->assertNull( $this->store->manifest( $old ) );
		$this->assertNotNull( $this->store->manifest( $used ) );
		$this->assertNotNull( $this->store->manifest( $recent ) );
	}

	// ------------------------------------------------------------------
	// Manifest validation.
	// ------------------------------------------------------------------

	/**
	 * @return array<string,mixed>
	 */
	private static function manifest(): array {
		return array(
			'format'    => BackupStore::FORMAT,
			'version'   => BackupStore::VERSION,
			'id'        => 'db-20260101-000000-0123456789abcdef',
			'site_hash' => self::HASH,
			'prefix'    => 'wp_',
			'status'    => 'complete',
			'tables'    => array(
				'posts'    => 1,
				'postmeta' => 2,
			),
			'parts'     => array(
				array(
					'file'   => 'part-0001.json.gz',
					'item'   => 'revisions',
					'sha256' => str_repeat( 'b', 64 ),
				),
			),
		);
	}

	public function test_valid_manifest(): void {
		$this->assertTrue( BackupStore::validate_manifest( self::manifest(), self::HASH, 'wp_', false ) );
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:string,2:bool}>
	 */
	public static function invalid_manifests(): array {
		$m = self::manifest();

		return array(
			'other site'        => array( array_merge( $m, array( 'site_hash' => str_repeat( 'c', 64 ) ) ), 'shso_backup_site', false ),
			'missing site hash' => array( array_diff_key( $m, array( 'site_hash' => 1 ) ), 'shso_backup_site', false ),
			'other prefix'      => array( array_merge( $m, array( 'prefix' => 'wp2_' ) ), 'shso_backup_site', false ),
			'unknown format'    => array( array_merge( $m, array( 'format' => 'other' ) ), 'shso_backup_format', false ),
			'future version'    => array( array_merge( $m, array( 'version' => 2 ) ), 'shso_backup_format', false ),
			'bad id'            => array( array_merge( $m, array( 'id' => '../x' ) ), 'shso_backup_format', false ),
			'bad status'        => array( array_merge( $m, array( 'status' => 'weird' ) ), 'shso_backup_format', false ),
			'unknown table'     => array( array_merge( $m, array( 'tables' => array( 'users' => 1 ) ) ), 'shso_backup_tables', false ),
			'sitemeta single'   => array( array_merge( $m, array( 'tables' => array( 'sitemeta' => 1 ) ) ), 'shso_backup_tables', false ),
			'part traversal'    => array( array_merge( $m, array( 'parts' => array( array_merge( $m['parts'][0], array( 'file' => '../part-0001.json.gz' ) ) ) ) ), 'shso_backup_corrupt', false ),
			'part bad hash'     => array( array_merge( $m, array( 'parts' => array( array_merge( $m['parts'][0], array( 'sha256' => 'xyz' ) ) ) ) ), 'shso_backup_corrupt', false ),
			'part bad item'     => array( array_merge( $m, array( 'parts' => array( array_merge( $m['parts'][0], array( 'item' => 'users' ) ) ) ) ), 'shso_backup_corrupt', false ),
			'duplicate part'    => array( array_merge( $m, array( 'parts' => array( $m['parts'][0], $m['parts'][0] ) ) ), 'shso_backup_corrupt', false ),
		);
	}

	#[DataProvider( 'invalid_manifests' )]
	public function test_invalid_manifests_are_rejected( array $manifest, string $code, bool $multisite ): void {
		$result = BackupStore::validate_manifest( $manifest, self::HASH, 'wp_', $multisite );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public function test_sitemeta_allowed_on_multisite(): void {
		$manifest           = self::manifest();
		$manifest['tables'] = array( 'sitemeta' => 2 );
		$this->assertTrue( BackupStore::validate_manifest( $manifest, self::HASH, 'wp_', true ) );
	}
}
