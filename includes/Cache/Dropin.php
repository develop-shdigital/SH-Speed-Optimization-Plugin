<?php
/**
 * The `advanced-cache.php` drop-in and the WP_CACHE constant.
 *
 * The drop-in lets cached pages be served before WordPress loads (fastest
 * delivery). It is only written when the slot is free or already ours: a
 * drop-in of another cache plugin is never touched. wp-config.php is never
 * edited automatically; enable_wp_cache_constant() is an explicit
 * administrator action with a syntax check and automatic restore from the
 * in-memory original. No copy of wp-config.php (credentials) is ever written.
 *
 * These are the only two files outside the plugin's own directories this
 * class writes (Core\Filesystem refuses PHP files by design).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Cache;

use SH\SpeedOptimizer\Core\Filesystem;
use SH\SpeedOptimizer\Core\Plugin;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The drop-in and wp-config.php must be written directly (WP_Filesystem may require FTP credentials; Filesystem refuses PHP files).
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- File checks must never emit warnings on locked-down hosts (open_basedir, permissions).

/**
 * Drop-in management.
 */
final class Dropin {

	/**
	 * Signature line identifying our drop-in.
	 */
	public const SIGNATURE = 'SHSO-Dropin: sh-speed-optimizer page cache';

	/**
	 * Owner name reported for our own drop-in.
	 */
	public const OWNER_SELF = 'SH Speed Optimizer';

	/**
	 * Marker comment on the line we add to wp-config.php.
	 */
	public const WP_CACHE_MARKER = 'Added by SH Speed Optimizer (page cache)';

	/**
	 * Option holding a fingerprint of wp-config.php before the last change (not autoloaded).
	 */
	public const BACKUP_OPTION = 'shso_wp_config_backup';

	/**
	 * Known owners of advanced-cache.php: name => needles (case-insensitive).
	 */
	private const OWNERS = array(
		'WP Rocket'            => array( 'WP_ROCKET', 'wp-rocket', 'WP Rocket' ),
		'W3 Total Cache'       => array( 'W3 Total Cache', 'W3TC', 'w3-total-cache' ),
		'WP Super Cache'       => array( 'WP SUPER CACHE', 'WPCACHEHOME', 'wp-super-cache', 'wp_cache_phase1' ),
		'LiteSpeed Cache'      => array( 'LiteSpeed', 'LSCWP', 'litespeed-cache' ),
		'WP Fastest Cache'     => array( 'WP Fastest Cache', 'wpFastestCache', 'wp-fastest-cache' ),
		'Cache Enabler'        => array( 'Cache Enabler', 'cache-enabler', 'Cache_Enabler' ),
		'WP-Optimize'          => array( 'WP-Optimize', 'wp-optimize', 'WPO_CACHE' ),
		'SiteGround Optimizer' => array( 'SG Optimizer', 'SG_CachePress', 'sg-cachepress', 'SiteGround' ),
		'Hummingbird'          => array( 'Hummingbird', 'WPHB_', 'wphb' ),
		'Comet Cache'          => array( 'Comet Cache', 'COMET_CACHE', 'comet-cache', 'comet_cache' ),
		'Swift Performance'    => array( 'Swift Performance', 'SWIFT_PERFORMANCE', 'swift-performance' ),
		'FlyingPress'          => array( 'FlyingPress', 'FLYING_PRESS', 'flying-press' ),
		'NitroPack'            => array( 'NitroPack', 'NITROPACK' ),
		'Breeze'               => array( 'Breeze', 'breeze' ),
	);

	/**
	 * Path of the drop-in.
	 */
	public static function path(): string {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	/**
	 * Whether WP_CACHE is on (WordPress loads advanced-cache.php).
	 */
	public static function wp_cache_enabled(): bool {
		return defined( 'WP_CACHE' ) && WP_CACHE;
	}

	/**
	 * Install (or refresh) our drop-in.
	 *
	 * @return true|\WP_Error
	 */
	public static function install() {
		$path = self::path();

		if ( file_exists( $path ) && ! self::is_ours() ) {
			return new \WP_Error(
				'shso_dropin_foreign',
				sprintf(
					/* translators: %s: name of another cache plugin, or "unknown" */
					__( 'Another page cache (%s) already uses the advanced-cache.php file. It was left untouched.', 'sh-speed-optimizer' ),
					(string) self::owner()
				)
			);
		}

		if ( ! is_dir( WP_CONTENT_DIR ) || ! wp_is_writable( WP_CONTENT_DIR ) ) {
			return new \WP_Error( 'shso_dropin_not_writable', __( 'The wp-content folder is not writable, so pages are served by the plugin itself (slightly slower, but fully functional).', 'sh-speed-optimizer' ) );
		}

		$code = self::template( SHSO_DIR . 'includes/Cache/Delivery.php', Filesystem::cache_root() );
		if ( ! self::is_valid_php( $code ) ) {
			return new \WP_Error( 'shso_dropin_invalid', __( 'The cache drop-in could not be generated.', 'sh-speed-optimizer' ) );
		}

		if ( file_exists( $path ) && (string) @file_get_contents( $path ) === $code ) {
			return true;
		}

		$tmp = WP_CONTENT_DIR . '/.advanced-cache.php.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		if ( false === @file_put_contents( $tmp, $code, LOCK_EX ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'shso_dropin_write', __( 'The cache drop-in could not be written.', 'sh-speed-optimizer' ) );
		}
		@chmod( $tmp, 0644 );
		if ( ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'shso_dropin_write', __( 'The cache drop-in could not be written.', 'sh-speed-optimizer' ) );
		}

		self::invalidate_opcache( $path );

		if ( ! self::is_ours() ) {
			return new \WP_Error( 'shso_dropin_verify', __( 'The cache drop-in could not be verified after writing it.', 'sh-speed-optimizer' ) );
		}

		return true;
	}

	/**
	 * Remove the drop-in, only when it is ours.
	 */
	public static function uninstall(): bool {
		$path = self::path();
		if ( ! file_exists( $path ) ) {
			return true;
		}
		if ( ! self::is_ours() ) {
			return false;
		}
		$ok = @unlink( $path );
		self::invalidate_opcache( $path );
		return $ok;
	}

	/**
	 * Whether the current drop-in is ours.
	 */
	public static function is_ours(): bool {
		return self::OWNER_SELF === self::owner();
	}

	/**
	 * Owner of the current drop-in (null when there is none).
	 */
	public static function owner(): ?string {
		$path = self::path();
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$contents = @file_get_contents( $path, false, null, 0, 65536 );
		return self::detect_owner( false === $contents ? '' : $contents );
	}

	/**
	 * Detect the owner from the drop-in contents.
	 *
	 * @param string $contents File contents.
	 */
	public static function detect_owner( string $contents ): string {
		if ( false !== strpos( $contents, self::SIGNATURE ) ) {
			return self::OWNER_SELF;
		}
		foreach ( self::OWNERS as $name => $needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== stripos( $contents, $needle ) ) {
					return $name;
				}
			}
		}
		return 'unknown';
	}

	/**
	 * Drop-in source code.
	 *
	 * The file stays parseable by any PHP version, fails open when the plugin
	 * was deleted (is_readable() guard) and honors the emergency constants.
	 *
	 * @param string $delivery_file Absolute path of Delivery.php.
	 * @param string $cache_root    Cache root directory.
	 */
	public static function template( string $delivery_file, string $cache_root ): string {
		$content_dir = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ) . '/';
		$cache_root  = rtrim( str_replace( '\\', '/', $cache_root ), '/' ) . '/';
		$root_code   = 0 === strpos( $cache_root, $content_dir )
			? 'WP_CONTENT_DIR . ' . var_export( '/' . substr( $cache_root, strlen( $content_dir ) ), true ) // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
			: var_export( $cache_root, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		$file_code   = var_export( str_replace( '\\', '/', $delivery_file ), true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		$signature   = self::SIGNATURE;

		return <<<PHP
<?php
/**
 * SH Speed Optimizer page cache drop-in.
 *
 * {$signature}
 *
 * Generated automatically, do not edit. Serves cached pages before WordPress
 * loads. Deleting this file is safe: the plugin then serves cached pages
 * itself. If the plugin is removed, this file does nothing.
 *
 * Emergency switch: define( 'SHSO_SAFE_MODE', true ); in wp-config.php.
 *
 * @package SH\SpeedOptimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ( defined( 'SHSO_SAFE_MODE' ) && SHSO_SAFE_MODE ) || ( defined( 'SHSO_DISABLE_CACHE' ) && SHSO_DISABLE_CACHE ) || version_compare( PHP_VERSION, '8.1', '<' ) ) {
	return;
}

\$shso_delivery_file = {$file_code};
if ( is_readable( \$shso_delivery_file ) ) {
	if ( ! defined( 'SHSO_ADVANCED_CACHE' ) ) {
		define( 'SHSO_ADVANCED_CACHE', true );
	}
	try {
		require_once \$shso_delivery_file;
		if ( class_exists( '\\SH\\SpeedOptimizer\\Cache\\Delivery', false ) ) {
			\\SH\\SpeedOptimizer\\Cache\\Delivery::serve( {$root_code}, 'dropin' );
		}
	} catch ( \\Throwable \$shso_error ) {
		unset( \$shso_error );
	}
}
unset( \$shso_delivery_file );

PHP;
	}

	/**
	 * Whether PHP code parses.
	 *
	 * @param string $code Code.
	 */
	public static function is_valid_php( string $code ): bool {
		try {
			token_get_all( $code, TOKEN_PARSE );
			return true;
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	// ---------------------------------------------------------------------
	// WP_CACHE constant (explicit administrator action only).
	// ---------------------------------------------------------------------

	/**
	 * Location of wp-config.php (same lookup as WordPress).
	 */
	public static function wp_config_path(): ?string {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		$parent = dirname( ABSPATH ) . '/wp-config.php';
		if ( @file_exists( $parent ) && ! @file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
			return $parent;
		}
		return null;
	}

	/**
	 * Add `define( 'WP_CACHE', true );` to wp-config.php.
	 *
	 * Refuses when WP_CACHE appears anywhere in the file, backs the file up into
	 * the private cache directory, inserts one marked line right after the
	 * opening PHP tag and restores the original when the result is not valid.
	 *
	 * @return true|\WP_Error
	 */
	public static function enable_wp_cache_constant() {
		if ( self::wp_cache_enabled() ) {
			return true;
		}

		$path = self::wp_config_path();
		if ( null === $path ) {
			return new \WP_Error( 'shso_wpconfig_missing', __( 'wp-config.php could not be found.', 'sh-speed-optimizer' ) );
		}
		if ( ! wp_is_writable( $path ) ) {
			return new \WP_Error( 'shso_wpconfig_not_writable', __( 'wp-config.php is not writable. Add this line near the top of the file yourself: define( \'WP_CACHE\', true );', 'sh-speed-optimizer' ) );
		}

		$original = @file_get_contents( $path );
		if ( false === $original || '' === $original ) {
			return new \WP_Error( 'shso_wpconfig_read', __( 'wp-config.php could not be read.', 'sh-speed-optimizer' ) );
		}

		$error   = '';
		$updated = self::insert_wp_cache_line( $original, $error );
		if ( null === $updated ) {
			return new \WP_Error( 'shso_wpconfig_' . $error, self::insert_error_message( $error ) );
		}

		$backup = self::backup_wp_config( $original );
		if ( null === $backup ) {
			return new \WP_Error( 'shso_wpconfig_backup', __( 'A backup of wp-config.php could not be created, so the file was not changed.', 'sh-speed-optimizer' ) );
		}

		if ( ! self::write_verified( $path, $updated, $original ) ) {
			return new \WP_Error( 'shso_wpconfig_write', __( 'wp-config.php could not be updated safely. The original file was restored.', 'sh-speed-optimizer' ) );
		}

		return true;
	}

	/**
	 * Remove the line added by enable_wp_cache_constant() (nothing else is touched).
	 *
	 * @return true|\WP_Error
	 */
	public static function disable_wp_cache_constant() {
		$path = self::wp_config_path();
		if ( null === $path ) {
			return new \WP_Error( 'shso_wpconfig_missing', __( 'wp-config.php could not be found.', 'sh-speed-optimizer' ) );
		}

		$original = @file_get_contents( $path );
		if ( false === $original ) {
			return new \WP_Error( 'shso_wpconfig_read', __( 'wp-config.php could not be read.', 'sh-speed-optimizer' ) );
		}

		$updated = self::remove_wp_cache_line( $original );
		if ( null === $updated ) {
			return true; // Our line is not there: nothing to do.
		}
		if ( ! wp_is_writable( $path ) ) {
			return new \WP_Error( 'shso_wpconfig_not_writable', __( 'wp-config.php is not writable.', 'sh-speed-optimizer' ) );
		}
		if ( null === self::backup_wp_config( $original ) || ! self::write_verified( $path, $updated, $original ) ) {
			return new \WP_Error( 'shso_wpconfig_write', __( 'wp-config.php could not be updated safely. The original file was restored.', 'sh-speed-optimizer' ) );
		}

		return true;
	}

	/**
	 * Insert the WP_CACHE line right after the opening PHP tag (pure).
	 *
	 * Error codes: structure (no wp-settings.php), defined (WP_CACHE already
	 * mentioned), open_tag (no opening tag), syntax (result does not parse).
	 *
	 * @param string      $contents wp-config.php contents.
	 * @param string|null $error    Error code (out).
	 */
	public static function insert_wp_cache_line( string $contents, ?string &$error = null ): ?string {
		$error = '';

		if ( false === strpos( $contents, 'wp-settings.php' ) ) {
			$error = 'structure';
			return null;
		}
		if ( preg_match( '/define\s*\(\s*[\'"]WP_CACHE[\'"]/i', $contents ) ) {
			$error = 'defined';
			return null;
		}

		$offset = 0;
		$insert = null;
		$open   = '';
		foreach ( token_get_all( $contents ) as $token ) {
			$text = is_array( $token ) ? $token[1] : $token;
			if ( is_array( $token ) && T_OPEN_TAG === $token[0] ) {
				$insert = $offset + strlen( $text );
				$open   = $text;
				break;
			}
			$offset += strlen( $text );
		}
		if ( null === $insert || 0 !== strpos( ltrim( $open ), '<?php' ) ) {
			$error = 'open_tag';
			return null;
		}

		$line     = "define( 'WP_CACHE', true ); // " . self::WP_CACHE_MARKER;
		$addition = ( "\n" === substr( $open, -1 ) ? '' : "\n" ) . $line . "\n";
		$updated  = substr( $contents, 0, $insert ) . $addition . substr( $contents, $insert );

		if ( ! self::is_valid_php( $updated ) || false === strpos( $updated, 'wp-settings.php' ) ) {
			$error = 'syntax';
			return null;
		}

		return $updated;
	}

	/**
	 * Remove our marked WP_CACHE line (pure). Null when it is not present.
	 *
	 * @param string $contents wp-config.php contents.
	 */
	public static function remove_wp_cache_line( string $contents ): ?string {
		$pattern = '/^[ \t]*define\(\s*\'WP_CACHE\',\s*true\s*\);[ \t]*\/\/[ \t]*' . preg_quote( self::WP_CACHE_MARKER, '/' ) . '[^\r\n]*(?:\r\n|\n)?/m';
		if ( ! preg_match( $pattern, $contents ) ) {
			return null;
		}
		$updated = (string) preg_replace( $pattern, '', $contents, 1 );
		return self::is_valid_php( $updated ) ? $updated : null;
	}

	/**
	 * Translated message for an insert error code.
	 *
	 * @param string $error Error code.
	 */
	private static function insert_error_message( string $error ): string {
		switch ( $error ) {
			case 'defined':
				return __( 'wp-config.php already defines WP_CACHE. Change that line to define( \'WP_CACHE\', true ); yourself if you want faster cache delivery.', 'sh-speed-optimizer' );
			case 'structure':
				return __( 'Your wp-config.php has an unusual structure, so it was not changed automatically. Add define( \'WP_CACHE\', true ); near the top of the file yourself.', 'sh-speed-optimizer' );
			default:
				return __( 'wp-config.php could not be changed safely, so it was left untouched.', 'sh-speed-optimizer' );
		}
	}

	/**
	 * Record a wp-config.php change.
	 *
	 * The file wp-config.php contains database credentials and salts, so no copy of it
	 * is ever written anywhere (a file below wp-content could be reachable on
	 * servers that ignore .htaccess). The automatic restore uses the original
	 * contents held in memory, and the change itself is a single marked line
	 * that disable_wp_cache_constant() removes deterministically. Only a
	 * fingerprint is stored for diagnostics.
	 *
	 * @param string $contents Original contents.
	 * @return string|null Fingerprint.
	 */
	private static function backup_wp_config( string $contents ): ?string {
		$fingerprint = hash( 'sha256', $contents );
		update_option(
			self::BACKUP_OPTION,
			array(
				'sha256' => $fingerprint,
				'bytes'  => strlen( $contents ),
				'time'   => time(),
			),
			false
		);
		return $fingerprint;
	}

	/**
	 * Write wp-config.php and verify it; restore the original on any doubt.
	 *
	 * The new contents are validated before anything is written, then swapped in
	 * atomically where possible, so concurrent requests never read a partial file.
	 *
	 * @param string $path     File.
	 * @param string $updated  New contents.
	 * @param string $original Original contents.
	 */
	private static function write_verified( string $path, string $updated, string $original ): bool {
		if ( false === strpos( $updated, 'wp-settings.php' ) || ! self::is_valid_php( $updated ) ) {
			return false;
		}
		$target = realpath( $path );
		$target = false === $target ? $path : $target; // A symlinked wp-config.php keeps its link.

		if ( ! self::replace_atomically( $target, $updated ) ) {
			$written = @file_put_contents( $target, $updated, LOCK_EX );
			if ( strlen( $updated ) !== $written ) {
				@file_put_contents( $target, $original, LOCK_EX );
				self::invalidate_opcache( $target );
				return false;
			}
		}
		clearstatcache( true, $target );
		$check = @file_get_contents( $target );

		if ( $check !== $updated ) {
			if ( ! self::replace_atomically( $target, $original ) ) {
				@file_put_contents( $target, $original, LOCK_EX );
			}
			self::invalidate_opcache( $target );
			return false;
		}

		self::invalidate_opcache( $target );
		return true;
	}

	/**
	 * Replace a file by writing a temporary file next to it and renaming it over the original.
	 *
	 * Only used when the file belongs to the user PHP runs as (renaming would otherwise
	 * change its owner) and its directory is writable. The temporary file ends in .php so
	 * a web server never serves its contents as text during the moment it exists.
	 *
	 * @param string $target   File.
	 * @param string $contents Contents.
	 */
	private static function replace_atomically( string $target, string $contents ): bool {
		$dir = dirname( $target );
		if ( ! is_file( $target ) || ! is_writable( $dir ) || ! function_exists( 'posix_geteuid' ) || @fileowner( $target ) !== posix_geteuid() ) {
			return false;
		}
		$tmp = $dir . '/wp-config-shso-' . bin2hex( random_bytes( 8 ) ) . '.php';
		if ( strlen( $contents ) !== @file_put_contents( $tmp, $contents, LOCK_EX ) ) {
			@unlink( $tmp );
			return false;
		}
		$perms = @fileperms( $target );
		if ( false !== $perms ) {
			@chmod( $tmp, $perms & 0777 );
		}
		if ( ! @rename( $tmp, $target ) ) {
			@unlink( $tmp );
			return false;
		}
		return true;
	}

	/**
	 * Drop a file from OPcache after changing it.
	 *
	 * @param string $path File.
	 */
	private static function invalidate_opcache( string $path ): void {
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $path, true );
		}
	}
}
