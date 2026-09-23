<?php
/**
 * Page cache storage (WordPress side).
 *
 * Layout: cache_root/pages/<host>/<path segments>/index[-variant].html, each
 * file starting with one line of JSON metadata followed by the HTML body, plus
 * an optional precompressed "….html.gz" sibling holding only the body. All
 * writes and deletes go through Core\Filesystem (atomic, confined to the
 * plugin's cache directory).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Cache;

use SH\SpeedOptimizer\Core\Filesystem;

defined( 'ABSPATH' ) || exit;

/**
 * Cache file storage.
 */
final class Storage {

	/**
	 * Pages containing WordPress nonces expire after at most 10 hours (nonces live 12–24 hours).
	 */
	public const NONCE_TTL = 36000;

	/**
	 * Maximum variant files per page directory (protects against variant explosion).
	 */
	public const MAX_VARIANTS = 64;

	/**
	 * Sub directories of a page directory purged together with the page (pagination, comment pages, AMP).
	 */
	private const PAGE_CHILDREN = array( 'page', 'amp' );

	/**
	 * Filesystem.
	 *
	 * @var Filesystem
	 */
	private Filesystem $fs;

	/**
	 * Cache root with trailing slash.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Constructor.
	 *
	 * @param Filesystem  $fs   Filesystem.
	 * @param string|null $root Cache root (defaults to Filesystem::cache_root()).
	 */
	public function __construct( Filesystem $fs, ?string $root = null ) {
		$this->fs   = $fs;
		$this->root = trailingslashit( wp_normalize_path( $root ?? Filesystem::cache_root() ) );
	}

	/**
	 * Directory holding the pages.
	 */
	public function pages_root(): string {
		return $this->root . 'pages/';
	}

	/**
	 * Store a rendered page.
	 *
	 * @param array<string,mixed> $decision     Delivery decision (action "cache").
	 * @param string              $html         HTML body.
	 * @param string[]            $headers      Replayable header lines.
	 * @param string              $content_type Content type.
	 * @param int                 $lifespan     Lifespan in seconds.
	 * @param bool                $gzip         Also write a precompressed copy.
	 * @param int                 $now          Timestamp.
	 * @return string|null Stored file path.
	 */
	public function store( array $decision, string $html, array $headers, string $content_type, int $lifespan, bool $gzip, int $now ): ?string {
		if ( 'cache' !== ( $decision['action'] ?? '' ) || ! Delivery::is_variant_file( (string) ( $decision['file'] ?? '' ) ) ) {
			return null;
		}

		if ( ! is_dir( $this->pages_root() ) ) {
			$this->fs->cache_dir( 'pages' );
		}

		$dir  = $this->pages_root() . $decision['dir'];
		$path = $dir . $decision['file'];

		if ( ! is_file( $path ) && count( $this->variant_files( $dir ) ) >= self::MAX_VARIANTS ) {
			return null;
		}

		$expires = $now + max( 60, $lifespan );
		if ( self::has_nonce( $html ) ) {
			$expires = min( $expires, $now + self::NONCE_TTL );
		}

		$gz_size = 0;
		if ( $gzip && function_exists( 'gzencode' ) ) {
			$compressed = gzencode( $html, 6 );
			if ( is_string( $compressed ) && '' !== $compressed && $this->fs->write( $path . '.gz', $compressed ) ) {
				$gz_size = strlen( $compressed );
			}
		}
		if ( 0 === $gz_size && is_file( $path . '.gz' ) ) {
			$this->fs->delete( $path . '.gz' );
		}

		$meta = array(
			'v'       => Delivery::FORMAT_VERSION,
			'created' => $now,
			'expires' => $expires,
			'url'     => (string) ( $decision['url'] ?? '' ),
			'type'    => $content_type,
			'headers' => array_values( $headers ),
			'variant' => (array) ( $decision['variant'] ?? array() ),
			'size'    => strlen( $html ),
			'gz'      => $gz_size,
		);

		return $this->fs->write( $path, Delivery::encode_entry( $meta, $html ) ) ? $path : null;
	}

	/**
	 * Read a stored entry.
	 *
	 * @param string $dir  Directory below pages/.
	 * @param string $file Variant file name.
	 * @return array{meta:array<string,mixed>,body:string}|null
	 */
	public function read( string $dir, string $file ): ?array {
		$path = $this->pages_root() . $dir . $file;
		if ( ! is_file( $path ) ) {
			return null;
		}
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local cache file.
		return false === $contents ? null : Delivery::decode_entry( $contents );
	}

	/**
	 * Whether a page contains WordPress nonces (they expire, so the page must too).
	 *
	 * @param string $html HTML.
	 */
	public static function has_nonce( string $html ): bool {
		return false !== strpos( $html, '_wpnonce' ) || false !== stripos( $html, '"nonce"' ) || false !== strpos( $html, 'wpApiSettings' ) || false !== stripos( $html, "'nonce'" );
	}

	/**
	 * Delete all variants of one page plus its pagination, comment pages and AMP version.
	 *
	 * @param string $dir Directory below pages/ ("host/path/").
	 * @return int Deleted files.
	 */
	public function purge_page( string $dir ): int {
		$abs   = $this->pages_root() . $dir;
		$count = 0;

		if ( ! is_dir( $abs ) ) {
			return 0;
		}

		foreach ( $this->entries( $abs ) as $name ) {
			$path = $abs . $name;
			if ( is_link( $path ) ) {
				continue;
			}
			if ( is_file( $path ) && ( Delivery::is_variant_file( $name ) || ( str_ends_with( $name, '.gz' ) && Delivery::is_variant_file( substr( $name, 0, -3 ) ) ) ) ) {
				if ( $this->fs->delete( $path ) ) {
					++$count;
				}
			} elseif ( is_dir( $path ) && ( in_array( $name, self::PAGE_CHILDREN, true ) || 0 === strpos( $name, 'comment-page-' ) ) ) {
				$count += $this->fs->delete_tree( $path, true );
			}
		}

		return $count;
	}

	/**
	 * Delete a site's page tree, keeping the directories of nested sites.
	 *
	 * @param string   $dir     Directory below pages/ ("host/" or "host/blog/").
	 * @param string[] $protect Directories below pages/ that must be kept.
	 * @return int Deleted files.
	 */
	public function purge_tree( string $dir, array $protect = array() ): int {
		$abs = $this->pages_root() . $dir;
		if ( ! is_dir( $abs ) || is_link( untrailingslashit( $abs ) ) ) {
			return 0;
		}

		$keep_dirs = array();
		foreach ( $protect as $keep ) {
			$keep_dirs[] = trailingslashit( $this->pages_root() . $keep );
		}

		return $this->delete_contents( trailingslashit( $abs ), $keep_dirs );
	}

	/**
	 * Recursive delete honoring protected directories.
	 *
	 * @param string   $abs       Absolute directory (trailing slash).
	 * @param string[] $keep_dirs Absolute protected directories (trailing slash).
	 */
	private function delete_contents( string $abs, array $keep_dirs ): int {
		$count = 0;
		foreach ( $this->entries( $abs ) as $name ) {
			$path = $abs . $name;
			if ( is_link( $path ) ) {
				continue;
			}
			if ( is_dir( $path ) ) {
				$sub = $path . '/';
				if ( in_array( $sub, $keep_dirs, true ) ) {
					continue;
				}
				$contains_protected = false;
				foreach ( $keep_dirs as $keep ) {
					if ( 0 === strpos( $keep, $sub ) ) {
						$contains_protected = true;
						break;
					}
				}
				$count += $contains_protected ? $this->delete_contents( $sub, $keep_dirs ) : $this->fs->delete_tree( $path, true );
			} elseif ( is_file( $path ) && ! in_array( $name, array( '.htaccess', 'web.config' ), true ) ) {
				if ( $this->fs->delete( $path ) ) {
					++$count;
				}
			}
		}
		return $count;
	}

	/**
	 * Delete expired entries, orphaned precompressed files and stale temporary files.
	 *
	 * @param string   $dir       Directory below pages/.
	 * @param int      $lifespan  Site lifespan in seconds.
	 * @param int      $now       Timestamp.
	 * @param string[] $protect   Directories below pages/ to skip (nested sites).
	 * @param int      $max_files Stop after examining this many files.
	 * @return int Deleted files.
	 */
	public function gc( string $dir, int $lifespan, int $now, array $protect = array(), int $max_files = 20000 ): int {
		$deleted  = 0;
		$examined = 0;

		$this->walk(
			$dir,
			$protect,
			function ( string $path, string $name ) use ( $lifespan, $now, &$deleted, &$examined, $max_files ) {
				if ( ++$examined > $max_files ) {
					return false;
				}
				if ( Delivery::is_variant_file( $name ) ) {
					if ( $this->is_expired_file( $path, $lifespan, $now ) ) {
						$deleted += (int) $this->fs->delete( $path );
						if ( is_file( $path . '.gz' ) ) {
							$deleted += (int) $this->fs->delete( $path . '.gz' );
						}
					}
				} elseif ( str_ends_with( $name, '.html.gz' ) && Delivery::is_variant_file( substr( $name, 0, -3 ) ) ) {
					if ( ! is_file( substr( $path, 0, -3 ) ) ) {
						$deleted += (int) $this->fs->delete( $path );
					}
				} elseif ( str_ends_with( $name, '.tmp' ) && (int) @filemtime( $path ) < $now - HOUR_IN_SECONDS ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$deleted += (int) $this->fs->delete( $path );
				}
				return true;
			}
		);

		return $deleted;
	}

	/**
	 * Cached pages and disk usage of a site.
	 *
	 * @param string   $dir       Directory below pages/.
	 * @param string[] $protect   Directories below pages/ to skip.
	 * @param int      $max_files Stop after this many files.
	 * @return array{files:int,bytes:int}
	 */
	public function usage( string $dir, array $protect = array(), int $max_files = 50000 ): array {
		$files    = 0;
		$bytes    = 0;
		$examined = 0;

		$this->walk(
			$dir,
			$protect,
			static function ( string $path, string $name ) use ( &$files, &$bytes, &$examined, $max_files ) {
				if ( ++$examined > $max_files ) {
					return false;
				}
				if ( Delivery::is_variant_file( $name ) ) {
					++$files;
					$bytes += (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				} elseif ( str_ends_with( $name, '.html.gz' ) ) {
					$bytes += (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
				return true;
			}
		);

		return array(
			'files' => $files,
			'bytes' => $bytes,
		);
	}

	/**
	 * Whether a stored file expired (reads only the metadata line).
	 *
	 * @param string $path     File.
	 * @param int    $lifespan Site lifespan.
	 * @param int    $now      Timestamp.
	 */
	private function is_expired_file( string $path, int $lifespan, int $now ): bool {
		// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged -- Read one line of a local cache file.
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		$line = fgets( $handle, 65536 );
		fclose( $handle );
		// phpcs:enable
		if ( false === $line ) {
			return true;
		}
		$meta = json_decode( rtrim( $line, "\n" ), true );
		if ( ! is_array( $meta ) || Delivery::FORMAT_VERSION !== (int) ( $meta['v'] ?? 0 ) ) {
			return true;
		}
		return Delivery::is_expired( $meta, $lifespan, $now );
	}

	/**
	 * Depth-first walk over the files of a directory tree (symlinks are never followed).
	 *
	 * @param string   $dir      Directory below pages/.
	 * @param string[] $protect  Directories below pages/ to skip.
	 * @param callable $callback function( string $path, string $name ): bool — false stops the walk.
	 */
	private function walk( string $dir, array $protect, callable $callback ): void {
		$start = trailingslashit( $this->pages_root() . $dir );
		if ( ! is_dir( $start ) ) {
			return;
		}

		$keep_dirs = array();
		foreach ( $protect as $keep ) {
			$keep_dirs[] = trailingslashit( $this->pages_root() . $keep );
		}

		$stack = array( $start );
		while ( ! empty( $stack ) ) {
			$current = array_pop( $stack );
			foreach ( $this->entries( $current ) as $name ) {
				$path = $current . $name;
				if ( is_link( $path ) ) {
					continue;
				}
				if ( is_dir( $path ) ) {
					if ( ! in_array( $path . '/', $keep_dirs, true ) ) {
						$stack[] = $path . '/';
					}
					continue;
				}
				if ( false === $callback( $path, $name ) ) {
					return;
				}
			}
		}
	}

	/**
	 * Variant files in a directory.
	 *
	 * @param string $abs Absolute directory (trailing slash).
	 * @return string[]
	 */
	private function variant_files( string $abs ): array {
		return array_values( array_filter( $this->entries( $abs ), array( Delivery::class, 'is_variant_file' ) ) );
	}

	/**
	 * Directory entries without dots.
	 *
	 * @param string $abs Absolute directory.
	 * @return string[]
	 */
	private function entries( string $abs ): array {
		if ( ! is_dir( $abs ) ) {
			return array();
		}
		$items = @scandir( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $items ) {
			return array();
		}
		return array_values(
			array_filter(
				$items,
				static function ( $item ) {
					return '.' !== $item && '..' !== $item;
				}
			)
		);
	}
}
