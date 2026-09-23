<?php
/**
 * Backups of database rows removed by the cleanup.
 *
 * Layout (private uploads directory, web access denied, no listing):
 *
 *     uploads/sh-speed-optimizer/backups/<id>/manifest.json
 *     uploads/sh-speed-optimizer/backups/<id>/part-0001.json.gz
 *
 * Every batch is written as its own gzip-compressed JSON-lines part through
 * the atomic {@see Filesystem::write()}, read back and checksummed before the
 * cleaner deletes anything. The manifest records the format version, a hash
 * of the site URL, the table prefix and a SHA-256 checksum per part.
 *
 * Part format: the first line is a header `{"shso":1,"item":"…","part":1}`,
 * every further line one record: `{"t":"posts","r":{…row…}}` for a row or
 * `{"t":"posts","link":{"id":5,"from":0,"to":12}}` for a parent link that
 * WordPress rewrites when the row is deleted. Values that are not valid
 * UTF-8 are stored base64-encoded and listed in the record's "b64" key.
 *
 * Backup ids are random (`db-YYYYmmdd-His-<16 hex>`) and validated with a
 * strict pattern on every use, so they can never address another path.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Database;

use SH\SpeedOptimizer\Core\Filesystem;

defined( 'ABSPATH' ) || exit;

/**
 * Backup storage.
 */
final class BackupStore {

	public const FORMAT       = 'shso-db-backup';
	public const VERSION      = 1;
	public const ID_PATTERN   = '/^db-[0-9]{8}-[0-9]{6}-[a-f0-9]{16}$/';
	public const PART_PATTERN = '/^part-[0-9]{4,6}\.json\.gz$/';
	public const MANIFEST     = 'manifest.json';
	public const STATUSES     = array( 'in_progress', 'complete', 'partial' );

	/**
	 * Largest compressed part accepted when reading (64 MB).
	 */
	public const MAX_PART_BYTES = 67108864;

	/**
	 * Largest decompressed part accepted when reading (512 MB).
	 */
	public const MAX_RAW_BYTES = 536870912;

	/**
	 * Filesystem.
	 *
	 * @var Filesystem
	 */
	private Filesystem $fs;

	/**
	 * Hash identifying this site.
	 *
	 * @var string
	 */
	private string $site_hash;

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Multisite.
	 *
	 * @var bool
	 */
	private bool $multisite;

	/**
	 * Blog id.
	 *
	 * @var int
	 */
	private int $blog_id;

	/**
	 * Constructor.
	 *
	 * @param Filesystem $fs        Filesystem.
	 * @param string     $site_hash Site hash ({@see DatabaseService::site_hash()}).
	 * @param string     $prefix    Table prefix.
	 * @param bool       $multisite Multisite.
	 * @param int        $blog_id   Blog id.
	 */
	public function __construct( Filesystem $fs, string $site_hash, string $prefix, bool $multisite, int $blog_id = 1 ) {
		$this->fs        = $fs;
		$this->site_hash = $site_hash;
		$this->prefix    = $prefix;
		$this->multisite = $multisite;
		$this->blog_id   = $blog_id;
	}

	/**
	 * Whether a string is a well-formed backup id.
	 *
	 * @param mixed $id Id.
	 */
	public static function is_valid_id( $id ): bool {
		return is_string( $id ) && 1 === preg_match( self::ID_PATTERN, $id );
	}

	/**
	 * New random, unguessable backup id.
	 *
	 * @param int|null $time Timestamp.
	 */
	public static function generate_id( ?int $time = null ): string {
		return 'db-' . gmdate( 'Ymd-His', $time ?? time() ) . '-' . bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Backups directory.
	 *
	 * @param bool $create Create (and protect) when missing.
	 */
	public function base_dir( bool $create = true ): string {
		if ( $create ) {
			return $this->fs->uploads_dir( 'backups', false );
		}
		return Filesystem::uploads_root( false ) . 'backups/';
	}

	/**
	 * Directory of a backup (null for invalid ids).
	 *
	 * @param string $id Backup id.
	 */
	public function dir( string $id ): ?string {
		return self::is_valid_id( $id ) ? $this->base_dir( false ) . $id . '/' : null;
	}

	/**
	 * Create a new, empty backup.
	 *
	 * @param array<string,mixed> $meta items, keep_revisions, job_id.
	 * @return string|\WP_Error Backup id.
	 */
	public function create( array $meta ) {
		$id = self::generate_id();
		$this->base_dir( true );
		$dir = $this->fs->uploads_dir( 'backups/' . $id, false );

		if ( ! is_dir( $dir ) ) {
			return new \WP_Error( 'shso_backup_dir', __( 'The backup folder could not be created.', 'sh-speed-optimizer' ) );
		}

		$now      = time();
		$manifest = array(
			'format'         => self::FORMAT,
			'version'        => self::VERSION,
			'id'             => $id,
			'created'        => $now,
			'updated'        => $now,
			'site_hash'      => $this->site_hash,
			'prefix'         => $this->prefix,
			'blog_id'        => $this->blog_id,
			'multisite'      => $this->multisite,
			'plugin_version' => defined( 'SHSO_VERSION' ) ? (string) SHSO_VERSION : '',
			'items'          => array_values( array_map( 'strval', (array) ( $meta['items'] ?? array() ) ) ),
			'keep_revisions' => (int) ( $meta['keep_revisions'] ?? 0 ),
			'job_id'         => (string) ( $meta['job_id'] ?? '' ),
			'status'         => 'in_progress',
			'parts'          => array(),
			'tables'         => array(),
			'item_rows'      => array(),
			'rows'           => 0,
			'links'          => 0,
			'bytes'          => 0,
			'raw_bytes'      => 0,
			'data_bytes'     => 0,
			'restored_at'    => 0,
		);

		if ( ! $this->write_manifest( $id, $manifest ) ) {
			$this->fs->delete_tree( $dir, true );
			return new \WP_Error( 'shso_backup_write', __( 'The backup could not be written.', 'sh-speed-optimizer' ) );
		}

		return $id;
	}

	/**
	 * Append one batch of records as a new part.
	 *
	 * Returns true only when the part was written, read back with a matching
	 * checksum and recorded in the manifest. On false nothing may be deleted.
	 *
	 * @param string                         $id      Backup id.
	 * @param string                         $item    Cleanup item.
	 * @param array<int,array<string,mixed>> $records Records.
	 */
	public function append( string $id, string $item, array $records ): bool {
		$manifest = $this->manifest( $id );
		if ( null === $manifest ) {
			return false;
		}

		$number  = count( (array) $manifest['parts'] ) + 1;
		$file    = sprintf( 'part-%04d.json.gz', $number );
		$encoded = self::encode_records( $records, $item, $number, $this->multisite );
		if ( null === $encoded ) {
			return false;
		}

		$compressed = gzencode( $encoded['raw'], 6 );
		$path       = $this->dir( $id ) . $file;
		if ( false === $compressed || ! $this->fs->write( $path, $compressed ) ) {
			return false;
		}

		$hash = hash( 'sha256', $compressed );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Local file inside the plugin's private directory; failures are handled.
		$check = @file_get_contents( $path );
		if ( ! is_string( $check ) || ! hash_equals( $hash, hash( 'sha256', $check ) ) ) {
			$this->fs->delete( $path );
			return false;
		}
		self::sync( $path );

		$manifest['parts'][] = array(
			'file'       => $file,
			'item'       => $item,
			'rows'       => $encoded['rows'],
			'links'      => $encoded['links'],
			'bytes'      => strlen( $compressed ),
			'raw_bytes'  => strlen( $encoded['raw'] ),
			'data_bytes' => $encoded['data_bytes'],
			'sha256'     => $hash,
		);
		foreach ( $encoded['tables'] as $table => $count ) {
			$manifest['tables'][ $table ] = (int) ( $manifest['tables'][ $table ] ?? 0 ) + $count;
		}
		$manifest['item_rows'][ $item ] = (int) ( $manifest['item_rows'][ $item ] ?? 0 ) + $encoded['rows'];
		$manifest['rows']              += $encoded['rows'];
		$manifest['links']             += $encoded['links'];
		$manifest['bytes']             += strlen( $compressed );
		$manifest['raw_bytes']         += strlen( $encoded['raw'] );
		$manifest['data_bytes']        += $encoded['data_bytes'];
		$manifest['updated']            = time();

		if ( ! $this->write_manifest( $id, $manifest ) ) {
			$this->fs->delete( $path );
			return false;
		}

		return true;
	}

	/**
	 * Read a manifest (null when missing or malformed).
	 *
	 * @param string $id Backup id.
	 * @return array<string,mixed>|null
	 */
	public function manifest( string $id ): ?array {
		$dir = $this->dir( $id );
		if ( null === $dir || ! is_file( $dir . self::MANIFEST ) || is_link( $dir . self::MANIFEST ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Local file inside the plugin's private directory; failures are handled.
		$json     = @file_get_contents( $dir . self::MANIFEST );
		$manifest = is_string( $json ) && strlen( $json ) < 4194304 ? json_decode( $json, true ) : null;
		if ( ! is_array( $manifest ) || ( $manifest['id'] ?? '' ) !== $id ) {
			return null;
		}
		foreach ( array( 'parts', 'tables', 'item_rows', 'items' ) as $key ) {
			$manifest[ $key ] = is_array( $manifest[ $key ] ?? null ) ? $manifest[ $key ] : array();
		}
		foreach ( array( 'rows', 'links', 'bytes', 'raw_bytes', 'data_bytes', 'created', 'updated', 'restored_at' ) as $key ) {
			$manifest[ $key ] = (int) ( $manifest[ $key ] ?? 0 );
		}
		return $manifest;
	}

	/**
	 * Merge changes into a manifest.
	 *
	 * @param string              $id      Backup id.
	 * @param array<string,mixed> $changes Changes (structural keys are ignored).
	 */
	public function update( string $id, array $changes ): bool {
		$manifest = $this->manifest( $id );
		if ( null === $manifest ) {
			return false;
		}
		$protected = array( 'format', 'version', 'id', 'created', 'site_hash', 'prefix', 'blog_id', 'multisite', 'parts', 'tables', 'item_rows', 'rows', 'links', 'bytes', 'raw_bytes', 'data_bytes' );
		foreach ( $changes as $key => $value ) {
			if ( ! in_array( $key, $protected, true ) ) {
				$manifest[ $key ] = $value;
			}
		}
		$manifest['updated'] = time();
		return $this->write_manifest( $id, $manifest );
	}

	/**
	 * Read and verify one part.
	 *
	 * @param string              $id   Backup id.
	 * @param array<string,mixed> $part Part entry of the manifest.
	 * @return array{header:array<string,mixed>,records:array<int,array<string,mixed>>}|\WP_Error
	 */
	public function read_part( string $id, array $part ) {
		$file = (string) ( $part['file'] ?? '' );
		$dir  = $this->dir( $id );
		if ( null === $dir || ! preg_match( self::PART_PATTERN, $file ) ) {
			return self::corrupt();
		}

		$path = $dir . $file;
		if ( ! is_file( $path ) || is_link( $path ) || filesize( $path ) > self::MAX_PART_BYTES ) {
			return new \WP_Error( 'shso_backup_missing_part', __( 'A part of this backup is missing, so it cannot be restored.', 'sh-speed-optimizer' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Local file inside the plugin's private directory; failures are handled.
		$compressed = @file_get_contents( $path );
		if ( ! is_string( $compressed ) || ! hash_equals( (string) ( $part['sha256'] ?? '' ), hash( 'sha256', $compressed ) ) ) {
			return self::corrupt();
		}

		$raw = @gzdecode( $compressed, self::MAX_RAW_BYTES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Corrupt data is reported below.
		if ( ! is_string( $raw ) ) {
			return self::corrupt();
		}

		return self::decode_records( $raw );
	}

	/**
	 * All backups (manifests), newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		$base = $this->base_dir( false );
		if ( ! is_dir( $base ) ) {
			return array();
		}

		$list    = array();
		$entries = @scandir( $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		foreach ( (array) $entries as $entry ) {
			if ( ! self::is_valid_id( $entry ) ) {
				continue;
			}
			$manifest = $this->manifest( (string) $entry );
			if ( null !== $manifest ) {
				$list[] = $manifest;
			}
		}

		usort(
			$list,
			static function ( array $a, array $b ): int {
				$order = $b['created'] <=> $a['created'];
				return 0 !== $order ? $order : strcmp( (string) $b['id'], (string) $a['id'] );
			}
		);

		return $list;
	}

	/**
	 * Delete a backup.
	 *
	 * @param string $id Backup id.
	 */
	public function delete( string $id ): bool {
		$dir = $this->dir( $id );
		if ( null === $dir || ! is_dir( $dir ) || is_link( untrailingslashit( $dir ) ) ) {
			return false;
		}
		$this->fs->delete_tree( $dir, true );
		clearstatcache();
		return ! is_dir( $dir );
	}

	/**
	 * Delete old backups and tidy up interrupted ones.
	 *
	 * @param int      $max_age Maximum age in seconds.
	 * @param callable $in_use  fn( string $id ): bool — backups used by a running cleanup are kept.
	 * @param int|null $now     Current timestamp.
	 * @return int Number of deleted backups.
	 */
	public function prune( int $max_age, callable $in_use, ?int $now = null ): int {
		$now     = $now ?? time();
		$deleted = 0;
		$base    = $this->base_dir( false );
		if ( ! is_dir( $base ) ) {
			return 0;
		}

		$entries = @scandir( $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		foreach ( (array) $entries as $entry ) {
			$entry = (string) $entry;
			if ( ! self::is_valid_id( $entry ) || $in_use( $entry ) ) {
				continue;
			}

			$manifest = $this->manifest( $entry );
			if ( null === $manifest ) {
				// Unreadable leftovers are removed after a day.
				$mtime = (int) @filemtime( $base . $entry ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $mtime > 0 && $now - $mtime > DAY_IN_SECONDS && $this->delete( $entry ) ) {
					++$deleted;
				}
				continue;
			}

			if ( $now - $manifest['created'] > $max_age || ( 0 === $manifest['rows'] && $now - $manifest['updated'] > DAY_IN_SECONDS ) ) {
				if ( $this->delete( $entry ) ) {
					++$deleted;
				}
				continue;
			}

			if ( 'in_progress' === ( $manifest['status'] ?? '' ) && $now - $manifest['updated'] > DAY_IN_SECONDS ) {
				$this->update( $entry, array( 'status' => 'partial' ) );
			}
		}

		return $deleted;
	}

	/**
	 * Validate a manifest against this site.
	 *
	 * @param array<string,mixed> $manifest  Manifest.
	 * @param string              $site_hash Current site hash.
	 * @param string              $prefix    Current table prefix.
	 * @param bool                $multisite Multisite.
	 * @return true|\WP_Error
	 */
	public static function validate_manifest( array $manifest, string $site_hash, string $prefix, bool $multisite ) {
		if ( self::FORMAT !== ( $manifest['format'] ?? '' ) || self::VERSION !== (int) ( $manifest['version'] ?? 0 ) || ! self::is_valid_id( $manifest['id'] ?? '' ) ) {
			return new \WP_Error( 'shso_backup_format', __( 'This backup was created in an unknown format and cannot be restored.', 'sh-speed-optimizer' ) );
		}

		if ( ! is_string( $manifest['site_hash'] ?? null ) || '' === $site_hash || ! hash_equals( $site_hash, $manifest['site_hash'] ) || ! is_string( $manifest['prefix'] ?? null ) || $manifest['prefix'] !== $prefix ) {
			return new \WP_Error( 'shso_backup_site', __( 'This backup belongs to a different site and cannot be restored here.', 'sh-speed-optimizer' ) );
		}

		if ( ! in_array( $manifest['status'] ?? '', self::STATUSES, true ) ) {
			return new \WP_Error( 'shso_backup_format', __( 'This backup was created in an unknown format and cannot be restored.', 'sh-speed-optimizer' ) );
		}

		foreach ( array_keys( (array) ( $manifest['tables'] ?? array() ) ) as $table ) {
			if ( ! Schema::is_allowed( (string) $table, $multisite ) ) {
				return new \WP_Error( 'shso_backup_tables', __( 'This backup contains data this plugin never backs up, so it is not restored.', 'sh-speed-optimizer' ) );
			}
		}

		$files = array();
		foreach ( (array) ( $manifest['parts'] ?? array() ) as $part ) {
			$file = is_array( $part ) ? (string) ( $part['file'] ?? '' ) : '';
			if ( ! preg_match( self::PART_PATTERN, $file ) || isset( $files[ $file ] )
				|| ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $part['sha256'] ?? '' ) )
				|| ! in_array( $part['item'] ?? '', Cleaner::ITEMS, true ) ) {
				return self::corrupt();
			}
			$files[ $file ] = true;
		}

		return true;
	}

	/**
	 * Encode records as JSON lines.
	 *
	 * @param array<int,array<string,mixed>> $records   Records.
	 * @param string                         $item      Cleanup item.
	 * @param int                            $number    Part number.
	 * @param bool                           $multisite Multisite (sitemeta allowed).
	 * @return array{raw:string,rows:int,links:int,data_bytes:int,tables:array<string,int>}|null
	 */
	public static function encode_records( array $records, string $item, int $number, bool $multisite = false ): ?array {
		$flags  = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		$lines  = array();
		$rows   = 0;
		$links  = 0;
		$data   = 0;
		$tables = array();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact encoding needed; wp_json_encode() may alter invalid UTF-8.
		$lines[] = json_encode(
			array(
				'shso' => self::VERSION,
				'item' => $item,
				'part' => $number,
			),
			$flags
		);

		foreach ( $records as $record ) {
			$table = (string) ( $record['t'] ?? '' );
			if ( ! Schema::is_allowed( $table, $multisite ) ) {
				return null;
			}

			if ( isset( $record['link'] ) ) {
				if ( ! isset( Schema::LINKS[ $table ] ) ) {
					return null;
				}
				$line = array(
					't'    => $table,
					'link' => array(
						'id'   => (int) ( $record['link']['id'] ?? 0 ),
						'from' => (int) ( $record['link']['from'] ?? 0 ),
						'to'   => (int) ( $record['link']['to'] ?? 0 ),
					),
				);
				++$links;
			} else {
				$row     = array();
				$base64  = array();
				$columns = Schema::columns( $table );
				foreach ( (array) ( $record['r'] ?? array() ) as $column => $value ) {
					if ( ! in_array( $column, $columns, true ) ) {
						continue; // Only core columns are backed up.
					}
					if ( null !== $value && ! is_scalar( $value ) ) {
						return null;
					}
					if ( is_string( $value ) ) {
						$data += strlen( $value );
						if ( 1 !== preg_match( '//u', $value ) ) {
							// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Lossless storage of non UTF-8 data.
							$value    = base64_encode( $value );
							$base64[] = $column;
						}
					}
					$row[ $column ] = $value;
				}
				if ( null === Schema::key_of( $table, $row ) ) {
					return null;
				}
				$line = array(
					't' => $table,
					'r' => $row,
				);
				if ( ! empty( $base64 ) ) {
					$line['b64'] = $base64;
				}
				++$rows;
				$tables[ $table ] = ( $tables[ $table ] ?? 0 ) + 1;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- See above.
			$json = json_encode( $line, $flags );
			if ( false === $json ) {
				return null;
			}
			$lines[] = $json;
		}

		return array(
			'raw'        => implode( "\n", $lines ) . "\n",
			'rows'       => $rows,
			'links'      => $links,
			'data_bytes' => $data,
			'tables'     => $tables,
		);
	}

	/**
	 * Decode JSON lines written by {@see encode_records()}.
	 *
	 * Structure only; table/column whitelisting happens in
	 * {@see Restorer::validate_record()}.
	 *
	 * @param string $raw JSON lines.
	 * @return array{header:array<string,mixed>,records:array<int,array<string,mixed>>}|\WP_Error
	 */
	public static function decode_records( string $raw ) {
		$lines  = explode( "\n", rtrim( $raw, "\n" ) );
		$header = json_decode( (string) array_shift( $lines ), true );
		if ( ! is_array( $header ) || self::VERSION !== (int) ( $header['shso'] ?? 0 ) ) {
			return self::corrupt();
		}

		$records = array();
		foreach ( $lines as $line ) {
			$record = json_decode( $line, true );
			if ( ! is_array( $record ) || ! is_string( $record['t'] ?? null ) ) {
				return self::corrupt();
			}
			if ( isset( $record['r'] ) && is_array( $record['r'] ) && ! empty( $record['b64'] ) ) {
				foreach ( (array) $record['b64'] as $column ) {
					if ( ! is_string( $column ) || ! is_string( $record['r'][ $column ] ?? null ) ) {
						return self::corrupt();
					}
					$decoded = base64_decode( $record['r'][ $column ], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- See encode_records().
					if ( false === $decoded ) {
						return self::corrupt();
					}
					$record['r'][ $column ] = $decoded;
				}
			}
			unset( $record['b64'] );
			$records[] = $record;
		}

		return array(
			'header'  => $header,
			'records' => $records,
		);
	}

	/**
	 * Write a manifest atomically.
	 *
	 * @param string              $id       Backup id.
	 * @param array<string,mixed> $manifest Manifest.
	 */
	private function write_manifest( string $id, array $manifest ): bool {
		$dir = $this->dir( $id );
		if ( null === $dir ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Plain data, consistent with the parts.
		$json = json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		return false !== $json && $this->fs->write( $dir . self::MANIFEST, $json );
	}

	/**
	 * Ask the operating system to flush a written file to disk (best effort).
	 *
	 * @param string $path File.
	 */
	private static function sync( string $path ): void {
		if ( ! function_exists( 'fsync' ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Needed for fsync().
		$handle = @fopen( $path, 'rb' );
		if ( false !== $handle ) {
			@fsync( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not supported on every platform.
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * Corrupt backup error.
	 */
	private static function corrupt(): \WP_Error {
		return new \WP_Error( 'shso_backup_corrupt', __( 'This backup is damaged and cannot be restored.', 'sh-speed-optimizer' ) );
	}
}
