<?php
/**
 * Guarded file operations.
 *
 * All generated files live below the plugin's own directories. Every write and
 * delete is checked against those roots so that no code path can touch files
 * elsewhere (path traversal, symlink tricks, arbitrary deletion).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Filesystem helper.
 */
final class Filesystem {

	/**
	 * Extensions the plugin is allowed to write. Never anything executable.
	 */
	private const WRITABLE_EXTENSIONS = array( 'html', 'gz', 'json', 'css', 'js', 'txt', 'webp', 'avif', 'woff2', 'woff', 'ttf', 'otf', 'eot', 'map' );

	/**
	 * Cache root (wp-content/cache/sh-speed-optimizer/).
	 */
	public static function cache_root(): string {
		/**
		 * Filters the cache root directory. Must stay inside wp-content.
		 *
		 * @param string $dir Directory with trailing slash.
		 */
		$dir = (string) apply_filters( 'shso_cache_dir', WP_CONTENT_DIR . '/cache/sh-speed-optimizer/' );
		$dir = wp_normalize_path( trailingslashit( $dir ) );

		if ( 0 !== strpos( $dir, wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) ) ) || false !== strpos( $dir, '..' ) ) {
			$dir = wp_normalize_path( WP_CONTENT_DIR . '/cache/sh-speed-optimizer/' );
		}

		return $dir;
	}

	/**
	 * Public URL of the cache root.
	 */
	public static function cache_url(): string {
		$root    = self::cache_root();
		$content = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) );
		return content_url( '/' . ltrim( substr( $root, strlen( $content ) ), '/' ) );
	}

	/**
	 * Private data root inside uploads (backups, image derivatives).
	 *
	 * @param bool $create Create when missing.
	 */
	public static function uploads_root( bool $create = true ): string {
		$uploads = wp_upload_dir( null, false );
		$dir     = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . 'sh-speed-optimizer/' );
		if ( $create && ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/**
	 * Public URL of the uploads root.
	 */
	public static function uploads_url(): string {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['baseurl'] ) . 'sh-speed-optimizer/';
	}

	/**
	 * A sub directory of the cache root, created and protected on demand.
	 *
	 * Public directories (generated CSS/JS/fonts) may be served by the web
	 * server; private ones (pages, config) deny direct access.
	 *
	 * @param string $sub    Sub directory name, e.g. "assets".
	 * @param bool   $public Whether files are served directly to browsers.
	 */
	public function cache_dir( string $sub, bool $public = false ): string {
		$sub = trim( preg_replace( '/[^a-z0-9_\-\/]/', '', strtolower( $sub ) ), '/' );
		$dir = self::cache_root() . ( '' === $sub ? '' : $sub . '/' );

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			$this->protect( self::cache_root(), true );
			$this->protect( $dir, $public );
		}

		return $dir;
	}

	/**
	 * A protected sub directory of the uploads root.
	 *
	 * @param string $sub    Sub directory.
	 * @param bool   $public Whether files are served directly.
	 */
	public function uploads_dir( string $sub, bool $public ): string {
		$sub = trim( preg_replace( '/[^a-z0-9_\-\/]/', '', strtolower( $sub ) ), '/' );
		$dir = self::uploads_root() . $sub . '/';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			$this->protect( self::uploads_root(), true );
			$this->protect( $dir, $public );
		}

		return $dir;
	}

	/**
	 * Roots the plugin may write into.
	 *
	 * @return string[]
	 */
	public function roots(): array {
		return array( self::cache_root(), self::uploads_root( false ) );
	}

	/**
	 * Whether a path is inside one of the plugin roots (after resolving symlinks and "..").
	 *
	 * @param string $path Path (file may not exist yet).
	 */
	public function is_allowed_path( string $path ): bool {
		$path = wp_normalize_path( $path );

		if ( '' === $path || false !== strpos( $path, "\0" ) || preg_match( '#(^|/)\.\.(/|$)#', $path ) ) {
			return false;
		}

		// Resolve the deepest existing parent so new files can be validated too.
		$check = $path;
		while ( ! file_exists( $check ) && dirname( $check ) !== $check ) {
			$check = dirname( $check );
		}
		$real = realpath( $check );
		if ( false === $real ) {
			return false;
		}
		$real = wp_normalize_path( $real );

		foreach ( $this->roots() as $root ) {
			$root_real = realpath( $root );
			if ( false === $root_real ) {
				// Root not created yet: compare the normalized strings.
				if ( 0 === strpos( $path, $root ) ) {
					return true;
				}
				continue;
			}
			$root_real = trailingslashit( wp_normalize_path( $root_real ) );
			if ( 0 === strpos( trailingslashit( $real ), $root_real ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Atomically write a file inside the plugin roots.
	 *
	 * @param string $path     Target path.
	 * @param string $contents Contents.
	 */
	public function write( string $path, string $contents ): bool {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::WRITABLE_EXTENSIONS, true ) || ! $this->is_allowed_path( $path ) ) {
			return false;
		}

		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$tmp = $dir . '/.' . basename( $path ) . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Atomic cache writes; WP_Filesystem has no rename().
		if ( false === @file_put_contents( $tmp, $contents, LOCK_EX ) ) {
			return false;
		}
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			return false;
		}
		@chmod( $path, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		return true;
	}

	/**
	 * Delete a single file inside the plugin roots.
	 *
	 * @param string $path File path.
	 */
	public function delete( string $path ): bool {
		if ( ! is_file( $path ) || is_link( $path ) || ! $this->is_allowed_path( $path ) ) {
			return false;
		}
		return @unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * Recursively delete a directory's contents inside the plugin roots.
	 *
	 * Symlinks are removed but never followed.
	 *
	 * @param string $dir        Directory.
	 * @param bool   $remove_dir Also remove the directory itself.
	 * @return int Number of deleted files.
	 */
	public function delete_tree( string $dir, bool $remove_dir = true ): int {
		$dir = untrailingslashit( wp_normalize_path( $dir ) );

		if ( ! is_dir( $dir ) || is_link( $dir ) || ! $this->is_allowed_path( $dir ) ) {
			return 0;
		}
		// Never delete a root itself.
		foreach ( $this->roots() as $root ) {
			if ( untrailingslashit( $root ) === $dir ) {
				$remove_dir = false;
			}
		}

		$count = 0;
		$items = @scandir( $dir );
		if ( false === $items ) {
			return 0;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_link( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			} elseif ( is_dir( $path ) ) {
				$count += $this->delete_tree( $path, true );
			} elseif ( ! in_array( $item, array( '.htaccess', 'index.html', 'web.config' ), true ) || $remove_dir ) {
				if ( @unlink( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					++$count;
				}
			}
		}

		if ( $remove_dir ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}

		return $count;
	}

	/**
	 * Total size of a directory in bytes (bounded walk).
	 *
	 * @param string $dir       Directory.
	 * @param int    $max_files Stop after this many files.
	 * @return array{bytes:int,files:int}
	 */
	public function usage( string $dir, int $max_files = 50000 ): array {
		$bytes = 0;
		$files = 0;

		if ( ! is_dir( $dir ) ) {
			return array(
				'bytes' => 0,
				'files' => 0,
			);
		}

		try {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && ! in_array( $file->getFilename(), array( '.htaccess', 'index.html', 'web.config' ), true ) ) {
					$bytes += (int) $file->getSize();
					if ( ++$files >= $max_files ) {
						break;
					}
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return array(
			'bytes' => $bytes,
			'files' => $files,
		);
	}

	/**
	 * Drop directory protection files.
	 *
	 * Every directory gets an empty index.html (no listing). Private directories
	 * additionally deny all direct web access. Public directories get no
	 * server rules at all: a restrictive AllowOverride setting would turn such
	 * rules into HTTP 500 errors for the generated CSS/JS. They never contain
	 * executable files because {@see write()} refuses such extensions.
	 *
	 * @param string $dir    Directory.
	 * @param bool   $public Public directory.
	 */
	public function protect( string $dir, bool $public ): void {
		$dir = trailingslashit( $dir );
		if ( ! is_dir( $dir ) ) {
			return;
		}

		if ( ! file_exists( $dir . 'index.html' ) ) {
			@file_put_contents( $dir . 'index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		if ( $public ) {
			return;
		}

		$htaccess  = "# SH Speed Optimizer: private data, no direct access.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";
		$webconfig = '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>';

		if ( ! file_exists( $dir . '.htaccess' ) ) {
			@file_put_contents( $dir . '.htaccess', $htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $dir . 'web.config' ) ) {
			@file_put_contents( $dir . 'web.config', $webconfig ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}
}
