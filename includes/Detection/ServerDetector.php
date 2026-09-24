<?php
/**
 * Web server, PHP and image processing capabilities.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Detection;

defined( 'ABSPATH' ) || exit;

/**
 * Server detection.
 */
final class ServerDetector {

	/**
	 * Parse the web server software.
	 *
	 * LiteSpeed can be configured to announce itself as "Apache", so the
	 * LiteSpeed-specific hints win over the raw string.
	 *
	 * Hints: sapi (PHP_SAPI), lsws_edition (LSWS_EDITION env), x_lscache (bool,
	 * X-LSCACHE env present), iis (bool, IIS-specific server variables present).
	 *
	 * @param string              $raw   SERVER_SOFTWARE value.
	 * @param array<string,mixed> $hints Hints.
	 * @return array{software:string,version:string,raw:string}
	 */
	public static function parse_software( string $raw, array $hints = array() ): array {
		$lower   = strtolower( trim( $raw ) );
		$edition = strtolower( trim( (string) ( $hints['lsws_edition'] ?? '' ) ) );
		$sapi    = strtolower( (string) ( $hints['sapi'] ?? '' ) );

		if ( false !== strpos( $lower, 'openlitespeed' ) ) {
			$software = 'openlitespeed';
		} elseif ( false !== strpos( $lower, 'litespeed' ) ) {
			$software = false !== strpos( $edition, 'open' ) ? 'openlitespeed' : 'litespeed';
		} elseif ( '' !== $edition ) {
			$software = false !== strpos( $edition, 'open' ) ? 'openlitespeed' : 'litespeed';
		} elseif ( 'litespeed' === $sapi || ! empty( $hints['x_lscache'] ) ) {
			$software = 'litespeed';
		} elseif ( preg_match( '/\b(nginx|openresty|tengine|angie)\b/', $lower ) ) {
			$software = 'nginx';
		} elseif ( false !== strpos( $lower, 'apache' ) ) {
			$software = 'apache';
		} elseif ( false !== strpos( $lower, 'microsoft-iis' ) || preg_match( '/\biis\b/', $lower ) || ! empty( $hints['iis'] ) ) {
			$software = 'iis';
		} else {
			$software = 'unknown';
		}

		$version = '';
		if ( preg_match( '#(?:openlitespeed|litespeed|nginx|openresty|apache|microsoft-iis)/([0-9][0-9a-z.\-]*)#i', $raw, $m ) ) {
			$version = $m[1];
		}

		return array(
			'software' => $software,
			'version'  => $version,
			'raw'      => trim( $raw ),
		);
	}

	/**
	 * Convert a php.ini size ("256M", "1G", "-1") to bytes (-1 = unlimited).
	 *
	 * @param string $value Size.
	 */
	public static function to_bytes( string $value ): int {
		$value = strtolower( trim( $value ) );
		if ( '' === $value ) {
			return 0;
		}
		if ( '-1' === $value ) {
			return -1;
		}
		$number = (float) $value;
		$unit   = substr( $value, -1 );
		switch ( $unit ) {
			case 'g':
				$number *= 1024;
				// Fall through.
			case 'm':
				$number *= 1024;
				// Fall through.
			case 'k':
				$number *= 1024;
		}
		return (int) $number;
	}

	/**
	 * Collect the server section of the site profile.
	 *
	 * @return array<string,mixed>
	 */
	public static function collect(): array {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Parsed with fixed patterns; stored sanitized.
		$raw = isset( $_SERVER['SERVER_SOFTWARE'] ) && is_scalar( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';

		$hints = array(
			'sapi'         => PHP_SAPI,
			'lsws_edition' => isset( $_SERVER['LSWS_EDITION'] ) && is_scalar( $_SERVER['LSWS_EDITION'] ) ? (string) $_SERVER['LSWS_EDITION'] : (string) getenv( 'LSWS_EDITION' ),
			'x_lscache'    => isset( $_SERVER['X-LSCACHE'] ) || isset( $_SERVER['HTTP_X_LSCACHE'] ) || false !== getenv( 'X-LSCACHE' ),
			'iis'          => isset( $_SERVER['IIS_WasUrlRewritten'] ) || isset( $_SERVER['IIS_UrlRewriteModule'] ),
		);

		$protocol = isset( $_SERVER['SERVER_PROTOCOL'] ) && is_scalar( $_SERVER['SERVER_PROTOCOL'] ) ? strtoupper( (string) $_SERVER['SERVER_PROTOCOL'] ) : '';
		// phpcs:enable

		$parsed = self::parse_software( $raw, $hints );

		$server = array(
			'software'           => $parsed['software'],
			'software_version'   => $parsed['version'],
			'software_raw'       => function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $parsed['raw'] ) : $parsed['raw'],
			'php_version'        => PHP_VERSION,
			'php_sapi'           => PHP_SAPI,
			'memory_limit'       => self::to_bytes( (string) ini_get( 'memory_limit' ) ),
			'wp_memory_limit'    => defined( 'WP_MEMORY_LIMIT' ) ? self::to_bytes( (string) WP_MEMORY_LIMIT ) : null,
			'max_execution_time' => (int) ini_get( 'max_execution_time' ),
			'opcache'            => self::opcache_enabled(),
			'extensions'         => array(
				'imagick' => extension_loaded( 'imagick' ),
				'gd'      => extension_loaded( 'gd' ),
				'curl'    => extension_loaded( 'curl' ),
				'zlib'    => extension_loaded( 'zlib' ),
				'brotli'  => extension_loaded( 'brotli' ),
			),
			'compression'        => 'unknown',
			// HTTP/2 seen on this request proves support; HTTP/1.1 proves nothing (proxies downgrade).
			'http2'              => ( 0 === strpos( $protocol, 'HTTP/2' ) || 0 === strpos( $protocol, 'HTTP/3' ) ) ? true : null,
			'disk_free'          => self::disk_free(),
		);

		$server = array_merge( $server, self::image_support() );

		$server['htaccess_writable']  = in_array( $parsed['software'], array( 'apache', 'litespeed', 'openlitespeed' ), true ) ? self::htaccess_writable() : false;
		$server['wp_config_writable'] = self::wp_config_writable();

		return $server;
	}

	/**
	 * Whether OPcache is enabled (null = unknown).
	 */
	public static function opcache_enabled(): ?bool {
		if ( function_exists( 'opcache_get_status' ) ) {
			try {
				$status = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- opcache.restrict_api emits a warning.
				if ( is_array( $status ) ) {
					return ! empty( $status['opcache_enabled'] );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		$ini = ini_get( 'opcache.enable' );
		if ( false === $ini ) {
			return extension_loaded( 'Zend OPcache' ) ? null : false;
		}
		return in_array( strtolower( (string) $ini ), array( '1', 'on', 'yes', 'true' ), true );
	}

	/**
	 * Image editor and modern format support through WordPress' image editors.
	 *
	 * @return array{image_editor:string,webp:bool,avif:bool}
	 */
	private static function image_support(): array {
		$out = array(
			'image_editor' => 'none',
			'webp'         => false,
			'avif'         => false,
		);

		try {
			if ( function_exists( '_wp_image_editor_choose' ) ) {
				$class = (string) _wp_image_editor_choose( array() );
				if ( false !== stripos( $class, 'imagick' ) ) {
					$out['image_editor'] = 'imagick';
				} elseif ( false !== stripos( $class, 'gd' ) ) {
					$out['image_editor'] = 'gd';
				} elseif ( '' !== $class ) {
					$out['image_editor'] = sanitize_key( $class );
				}
			}
			if ( function_exists( 'wp_image_editor_supports' ) ) {
				$out['webp'] = (bool) wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
				$out['avif'] = (bool) wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return $out;
	}

	/**
	 * Free disk space in the content directory (null = unavailable).
	 */
	private static function disk_free(): ?int {
		if ( ! function_exists( 'disk_free_space' ) || ! defined( 'WP_CONTENT_DIR' ) ) {
			return null;
		}
		$free = @disk_free_space( WP_CONTENT_DIR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir may forbid it.
		return false === $free ? null : (int) $free;
	}

	/**
	 * Whether the root .htaccess can be written.
	 */
	private static function htaccess_writable(): bool {
		if ( ! function_exists( 'get_home_path' ) && is_readable( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$home = function_exists( 'get_home_path' ) ? (string) get_home_path() : ABSPATH;
		$file = trailingslashit( $home ) . '.htaccess';
		$test = file_exists( $file ) ? $file : dirname( $file );
		return function_exists( 'wp_is_writable' ) ? (bool) wp_is_writable( $test ) : is_writable( $test ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
	}

	/**
	 * Whether wp-config.php can be written.
	 */
	private static function wp_config_writable(): bool {
		$file = ABSPATH . 'wp-config.php';
		if ( ! file_exists( $file ) && file_exists( dirname( ABSPATH ) . '/wp-config.php' ) ) {
			$file = dirname( ABSPATH ) . '/wp-config.php';
		}
		if ( ! file_exists( $file ) ) {
			return false;
		}
		return function_exists( 'wp_is_writable' ) ? (bool) wp_is_writable( $file ) : is_writable( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
	}
}
