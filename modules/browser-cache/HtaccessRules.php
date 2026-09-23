<?php
/**
 * Browser caching rules for Apache/LiteSpeed (.htaccess).
 *
 * Long-lived caching only for static files by file name (versioned assets:
 * CSS, JS, fonts, images, video); never for HTML. Every directive sits in an
 * <IfModule> guard. Installing keeps a backup of the previous .htaccess,
 * checks the site with loopback requests before and after the change and
 * restores the backup immediately when anything broke.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\BrowserCache;

use SH\SpeedOptimizer\Core\Plugin;
use SH\SpeedOptimizer\Diagnostics\Loopback;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- .htaccess is read and restored directly (insert_with_markers() writes it the same way).

/**
 * .htaccess rules.
 */
final class HtaccessRules {

	/**
	 * Marker used with insert_with_markers().
	 */
	public const MARKER = 'SH Speed Optimizer';

	/**
	 * Signature line inside our block.
	 */
	public const SIGNATURE = '# shso-browser-cache v1';

	/**
	 * Option holding the path of the last .htaccess backup (not autoloaded).
	 */
	public const BACKUP_OPTION = 'shso_htaccess_backup';

	/**
	 * One year / one month / one week in seconds.
	 */
	private const YEAR  = 31536000;
	private const MONTH = 2592000;
	private const WEEK  = 604800;

	/**
	 * Versioned static assets (file extensions).
	 */
	private const CODE_AND_FONTS = 'css|js|mjs|woff2?|ttf|otf|eot';
	private const IMAGES         = 'jpe?g|png|gif|webp|avif|svg';
	private const ICONS          = 'ico';
	private const MEDIA          = 'mp4|webm|ogv|mp3|ogg|m4a';

	/**
	 * Rules placed between the markers.
	 *
	 * @param bool $compression Include compression rules (only when the server does not compress yet).
	 */
	public static function rules( bool $compression = true ): string {
		$code   = self::CODE_AND_FONTS;
		$images = self::IMAGES;
		$icons  = self::ICONS;
		$media  = self::MEDIA;
		$year   = self::YEAR;
		$month  = self::MONTH;
		$week   = self::WEEK;
		$sign   = self::SIGNATURE;

		$rules = <<<HTACCESS
{$sign}
# Browser caching for static files only. HTML pages are never cached by browsers.
<IfModule mod_mime.c>
	AddType image/webp .webp
	AddType image/avif .avif
	AddType font/woff2 .woff2
	AddType font/woff .woff
	AddType image/svg+xml .svg
</IfModule>
<IfModule mod_expires.c>
	<FilesMatch "\.(?i:{$code}|{$images})$">
		ExpiresActive On
		ExpiresDefault "access plus 1 year"
	</FilesMatch>
	<FilesMatch "\.(?i:{$media})$">
		ExpiresActive On
		ExpiresDefault "access plus 1 month"
	</FilesMatch>
	<FilesMatch "\.(?i:{$icons})$">
		ExpiresActive On
		ExpiresDefault "access plus 1 week"
	</FilesMatch>
</IfModule>
<IfModule mod_headers.c>
	<FilesMatch "\.(?i:{$code})$">
		Header set Cache-Control "public, max-age={$year}, immutable"
	</FilesMatch>
	<FilesMatch "\.(?i:{$images})$">
		Header set Cache-Control "public, max-age={$year}"
	</FilesMatch>
	<FilesMatch "\.(?i:{$media})$">
		Header set Cache-Control "public, max-age={$month}"
	</FilesMatch>
	<FilesMatch "\.(?i:{$icons})$">
		Header set Cache-Control "public, max-age={$week}"
	</FilesMatch>
</IfModule>
HTACCESS;

		if ( $compression ) {
			$types  = 'text/html text/plain text/css text/xml text/javascript application/javascript application/json application/ld+json application/xml application/rss+xml application/atom+xml image/svg+xml font/ttf font/otf application/vnd.ms-fontobject';
			$rules .= "\n<IfModule mod_filter.c>\n\t<IfModule mod_brotli.c>\n\t\tAddOutputFilterByType BROTLI_COMPRESS {$types}\n\t</IfModule>\n\t<IfModule mod_deflate.c>\n\t\tAddOutputFilterByType DEFLATE {$types}\n\t</IfModule>\n</IfModule>";
		}

		return $rules;
	}

	/**
	 * Equivalent nginx configuration (displayed for the administrator; never written).
	 */
	public static function nginx_snippet(): string {
		$code   = self::CODE_AND_FONTS;
		$images = self::IMAGES;
		$year   = self::YEAR;

		return <<<NGINX
# Browser caching for static files (add inside the server { } block, then reload nginx).
location ~* \.(?:{$code})$ {
	expires 1y;
	add_header Cache-Control "public, max-age={$year}, immutable";
	access_log off;
}
location ~* \.(?:{$images})$ {
	expires 1y;
	add_header Cache-Control "public, max-age={$year}";
	access_log off;
}
# Compression (skip when it is already enabled in nginx.conf).
gzip on;
gzip_vary on;
gzip_types text/plain text/css text/xml text/javascript application/javascript application/json application/xml application/rss+xml image/svg+xml;
NGINX;
	}

	/**
	 * Web server family from SERVER_SOFTWARE: apache|litespeed|nginx|iis|unknown.
	 *
	 * @param string|null $software Server software string (defaults to $_SERVER).
	 */
	public static function server( ?string $software = null ): string {
		if ( null === $software ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
		}
		$software = strtolower( $software );

		if ( false !== strpos( $software, 'litespeed' ) ) {
			return 'litespeed';
		}
		if ( false !== strpos( $software, 'apache' ) ) {
			return 'apache';
		}
		if ( false !== strpos( $software, 'nginx' ) || false !== strpos( $software, 'openresty' ) ) {
			return 'nginx';
		}
		if ( false !== strpos( $software, 'iis' ) ) {
			return 'iis';
		}
		return 'unknown';
	}

	/**
	 * Whether this server reads .htaccess rules we can write.
	 */
	public static function supported(): bool {
		return in_array( self::server(), array( 'apache', 'litespeed' ), true );
	}

	/**
	 * Path of the .htaccess file.
	 */
	public static function path(): string {
		return ABSPATH . '.htaccess';
	}

	/**
	 * Whether WordPress may write the .htaccess file.
	 */
	public static function is_writable(): bool {
		$file = self::path();
		return file_exists( $file ) ? wp_is_writable( $file ) : wp_is_writable( ABSPATH );
	}

	/**
	 * Static asset used to test caching headers.
	 */
	public static function test_asset_url(): string {
		return includes_url( 'css/dashicons.min.css' );
	}

	/**
	 * Install the rules.
	 *
	 * @param array<string,mixed> $options compression (bool|null: null = only when the server does not compress yet).
	 * @return true|\WP_Error
	 */
	public static function install( array $options = array() ) {
		if ( ! self::supported() ) {
			return new \WP_Error( 'shso_htaccess_server', __( 'Your web server does not use .htaccess files, so nothing was changed. Ask your host to add browser caching for static files (the dashboard shows the configuration).', 'sh-speed-optimizer' ) );
		}
		if ( ! self::is_writable() ) {
			return new \WP_Error( 'shso_htaccess_not_writable', __( 'The .htaccess file cannot be changed by WordPress, so nothing was changed.', 'sh-speed-optimizer' ) );
		}

		$file     = self::path();
		$original = null;
		if ( file_exists( $file ) ) {
			$original = @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $original ) {
				return new \WP_Error( 'shso_htaccess_read', __( 'The .htaccess file could not be read, so nothing was changed.', 'sh-speed-optimizer' ) );
			}
			if ( ! self::backup( $original ) ) {
				return new \WP_Error( 'shso_htaccess_backup', __( 'A backup of .htaccess could not be created, so nothing was changed.', 'sh-speed-optimizer' ) );
			}
		}

		$home_before  = Loopback::get( home_url( '/' ), array( 'timeout' => 20 ) );
		$asset_before = Loopback::get( self::test_asset_url(), array( 'timeout' => 20 ) );
		if ( ! $home_before['ok'] || $home_before['status'] <= 0 ) {
			return new \WP_Error( 'shso_htaccess_no_loopback', __( 'Your site could not be checked before the change, so nothing was changed.', 'sh-speed-optimizer' ) );
		}

		$compression = $options['compression'] ?? null;
		if ( null === $compression ) {
			$compression = ! self::server_compresses();
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( ! insert_with_markers( $file, self::MARKER, explode( "\n", self::rules( (bool) $compression ) ) ) ) {
			self::restore( $file, $original );
			return new \WP_Error( 'shso_htaccess_write', __( 'The .htaccess file could not be updated, so nothing was changed.', 'sh-speed-optimizer' ) );
		}

		$home_after  = Loopback::get( home_url( '/' ), array( 'timeout' => 20 ) );
		$asset_after = Loopback::get( self::test_asset_url(), array( 'timeout' => 20 ) );

		$home_broken  = $home_after['status'] !== $home_before['status'];
		$asset_broken = $asset_after['status'] <= 0 || $asset_after['status'] >= 500 || ( $asset_before['status'] >= 200 && $asset_before['status'] < 400 && $asset_after['status'] !== $asset_before['status'] );

		if ( $home_broken || $asset_broken ) {
			self::restore( $file, $original );
			Plugin::instance()->logger()->error(
				'Browser caching rules removed again: the site answered differently after the change.',
				array(
					'home_before'  => (int) $home_before['status'],
					'home_after'   => (int) $home_after['status'],
					'asset_before' => (int) $asset_before['status'],
					'asset_after'  => (int) $asset_after['status'],
				),
				'cache'
			);
			return new \WP_Error( 'shso_htaccess_broke', __( 'Your server did not accept the browser caching rules, so they were removed again immediately. Nothing else was changed.', 'sh-speed-optimizer' ) );
		}

		return true;
	}

	/**
	 * Remove our block from .htaccess.
	 */
	public static function remove(): bool {
		$file = self::path();
		if ( ! file_exists( $file ) ) {
			return true;
		}
		$contents = @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $contents ) {
			return false;
		}
		$updated = self::strip_block( $contents );
		if ( $updated === $contents ) {
			return true;
		}
		if ( ! wp_is_writable( $file ) ) {
			return false;
		}
		return false !== @file_put_contents( $file, $updated, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Whether our rules are present.
	 */
	public static function is_installed(): bool {
		$file = self::path();
		if ( ! file_exists( $file ) ) {
			return false;
		}
		$contents = @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return is_string( $contents ) && self::contains_rules( $contents );
	}

	/**
	 * Whether .htaccess contents contain our active rules (pure).
	 *
	 * @param string $contents File contents.
	 */
	public static function contains_rules( string $contents ): bool {
		if ( ! preg_match( '/# BEGIN ' . preg_quote( self::MARKER, '/' ) . '\R(.*?)# END ' . preg_quote( self::MARKER, '/' ) . '/s', $contents, $m ) ) {
			return false;
		}
		return false !== strpos( $m[1], self::SIGNATURE );
	}

	/**
	 * Remove our marker block (markers included) from .htaccess contents (pure).
	 *
	 * @param string $contents File contents.
	 */
	public static function strip_block( string $contents ): string {
		$marker = preg_quote( self::MARKER, '/' );
		return (string) preg_replace( '/(?:\r?\n)?# BEGIN ' . $marker . '\R.*?# END ' . $marker . '[^\r\n]*(?:\r?\n)?/s', "\n", $contents, 1 );
	}

	/**
	 * Max-age in seconds from response headers (Cache-Control max-age, else Expires − Date), or null (pure).
	 *
	 * @param array<string,string> $headers Lowercase header names.
	 * @param int|null             $now     Current time.
	 */
	public static function max_age_from_headers( array $headers, ?int $now = null ): ?int {
		$headers = array_change_key_case( $headers, CASE_LOWER );
		$control = (string) ( $headers['cache-control'] ?? '' );
		if ( preg_match( '/(?:^|[,\s])(?:s-)?max-age\s*=\s*"?(\d+)/i', $control, $m ) && false === stripos( $control, 'no-store' ) ) {
			return (int) $m[1];
		}
		if ( ! empty( $headers['expires'] ) ) {
			$expires = strtotime( (string) $headers['expires'] );
			$base    = ! empty( $headers['date'] ) ? strtotime( (string) $headers['date'] ) : false;
			if ( false !== $expires ) {
				return max( 0, $expires - ( false !== $base ? $base : ( $now ?? time() ) ) );
			}
		}
		return null;
	}

	/**
	 * Whether the server already compresses text responses.
	 */
	private static function server_compresses(): bool {
		$response = Loopback::get(
			self::test_asset_url(),
			array(
				'timeout'   => 20,
				'headers'   => array( 'Accept-Encoding' => 'gzip, deflate, br' ),
				'max_bytes' => 65536,
			)
		);
		$encoding = strtolower( (string) ( $response['headers']['content-encoding'] ?? '' ) );
		return '' !== $encoding && 'identity' !== $encoding;
	}

	/**
	 * Keep a copy of the previous .htaccess in the private cache directory.
	 *
	 * @param string $contents Contents.
	 */
	private static function backup( string $contents ): bool {
		$fs   = Plugin::instance()->filesystem();
		$file = $fs->cache_dir( 'backups' ) . 'htaccess-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 8 ) ) . '.txt';
		if ( ! $fs->write( $file, $contents ) ) {
			return false;
		}
		$previous = get_option( self::BACKUP_OPTION );
		if ( is_string( $previous ) && '' !== $previous && $previous !== $file ) {
			$fs->delete( $previous );
		}
		update_option( self::BACKUP_OPTION, $file, false );
		return true;
	}

	/**
	 * Restore the previous .htaccess (or remove the file we created).
	 *
	 * @param string      $file     File.
	 * @param string|null $original Previous contents (null when the file did not exist).
	 */
	private static function restore( string $file, ?string $original ): void {
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
		if ( null !== $original ) {
			@file_put_contents( $file, $original, LOCK_EX );
			return;
		}
		$contents = @file_get_contents( $file );
		if ( is_string( $contents ) ) {
			$rest = trim( self::strip_block( $contents ) );
			if ( '' === $rest ) {
				@unlink( $file );
			} else {
				@file_put_contents( $file, $rest . "\n", LOCK_EX );
			}
		}
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
