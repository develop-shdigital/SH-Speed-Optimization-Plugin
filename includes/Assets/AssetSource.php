<?php
/**
 * Maps asset URLs to local files, safely, and attributes them to their source.
 *
 * Used by the asset copy generator and by the scanner. A URL only resolves to a
 * file when:
 *  - it is http(s), protocol-relative or root-relative and its host is this site's
 *    (home, site, content, includes, plugins or uploads URL host),
 *  - its path maps below ABSPATH or WP_CONTENT_DIR and the real path (symlinks and
 *    ".." resolved) stays inside one of those roots,
 *  - the file is a readable .css or .js file of at most 2 MB.
 *
 * Side-effect free: nothing is written, no remote requests are made.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets;

use SH\SpeedOptimizer\Assets\Minify\CssMinifier;
use SH\SpeedOptimizer\Assets\Minify\JsMinifier;

defined( 'ABSPATH' ) || exit;

/**
 * Asset source resolver.
 */
final class AssetSource {

	public const MAX_BYTES = 2097152;

	public const TYPE_CORE      = 'core';
	public const TYPE_PLUGIN    = 'plugin';
	public const TYPE_THEME     = 'theme';
	public const TYPE_UPLOADS   = 'uploads';
	public const TYPE_GENERATED = 'generated';
	public const TYPE_OTHER     = 'other';

	/**
	 * Cached environment.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $env = null;

	/**
	 * Per-request resolution cache.
	 *
	 * @var array<string,array<string,mixed>|null>
	 */
	private static array $cache = array();

	/**
	 * Resolve an asset URL to a local file.
	 *
	 * Returned keys: url (absolute URL without query/fragment), path (real path), ext (css|js),
	 * source_type (core|plugin|theme|uploads|generated|other), source_slug, source_name,
	 * bytes, mtime, minified.
	 *
	 * @param string $url              Asset URL as found in the page (absolute, protocol- or root-relative).
	 * @param bool   $inspect_contents Read the file to detect minification (false: judge by file name only).
	 * @return array<string,mixed>|null Null when the URL is not a safe local CSS/JS file.
	 */
	public static function resolve( string $url, bool $inspect_contents = true ): ?array {
		$key = ( $inspect_contents ? '1' : '0' ) . $url;
		if ( array_key_exists( $key, self::$cache ) ) {
			return self::$cache[ $key ];
		}
		if ( count( self::$cache ) > 500 ) {
			self::$cache = array();
		}
		self::$cache[ $key ] = self::resolve_in( $url, self::environment(), $inspect_contents );
		return self::$cache[ $key ];
	}

	/**
	 * Resolve against an explicit environment (pure; used by resolve() and tests).
	 *
	 * @param string              $url              URL.
	 * @param array<string,mixed> $env              Environment (see environment()).
	 * @param bool                $inspect_contents Detect minification from the contents.
	 * @return array<string,mixed>|null
	 */
	public static function resolve_in( string $url, array $env, bool $inspect_contents = true ): ?array {
		$absolute = self::normalize_url( $url, $env );
		if ( null === $absolute ) {
			return null;
		}

		$path = (string) parse_url( $absolute, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Already validated absolute URL.
		$path = rawurldecode( $path );

		if ( '' === $path || false !== strpos( $path, "\0" ) || false !== strpos( $path, '\\' ) || preg_match( '#(^|/)\.\.?(/|$)#', $path ) ) {
			return null;
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( 'css' !== $ext && 'js' !== $ext ) {
			return null;
		}

		$candidate = null;
		foreach ( (array) $env['map'] as $prefix => $dir ) {
			if ( 0 === strpos( $path, (string) $prefix ) ) {
				$candidate = rtrim( (string) $dir, '/' ) . '/' . substr( $path, strlen( (string) $prefix ) );
				break;
			}
		}
		if ( null === $candidate ) {
			return null;
		}

		$real = realpath( $candidate );
		if ( false === $real ) {
			return null;
		}
		$real = str_replace( '\\', '/', $real );

		if ( ! self::inside_any( $real, (array) $env['roots'] ) ) {
			return null;
		}

		$real_ext = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
		if ( $real_ext !== $ext || ! is_file( $real ) || ! is_readable( $real ) ) {
			return null;
		}

		$bytes = (int) @filesize( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $bytes <= 0 || $bytes > (int) ( $env['max_bytes'] ?? self::MAX_BYTES ) ) {
			return null;
		}

		$source   = self::attribute( $real, $env );
		$minified = (bool) preg_match( '/[.\-]min\.(css|js)$/i', $real );
		if ( ! $minified && $inspect_contents ) {
			$contents = (string) @file_get_contents( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file validated above.
			$minified = 'css' === $ext ? CssMinifier::is_minified( $contents ) : JsMinifier::is_minified( $contents );
		}

		return array(
			'url'         => (string) preg_replace( '/[?#].*$/s', '', $absolute ),
			'path'        => $real,
			'ext'         => $ext,
			'source_type' => $source['type'],
			'source_slug' => $source['slug'],
			'source_name' => $source['name'],
			'bytes'       => $bytes,
			'mtime'       => (int) @filemtime( $real ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			'minified'    => $minified,
		);
	}

	/**
	 * Whether a URL points to this site (host check only, no file access).
	 *
	 * @param string $url URL.
	 */
	public static function is_local_url( string $url ): bool {
		return null !== self::normalize_url( $url, self::environment() );
	}

	/**
	 * Normalize a same-site URL to an absolute URL, or null for other hosts/schemes and
	 * document-relative URLs.
	 *
	 * @param string              $url URL.
	 * @param array<string,mixed> $env Environment.
	 */
	public static function normalize_url( string $url, array $env ): ?string {
		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > 2048 || preg_match( '/[\x00-\x1F\x7F\s]/', $url ) ) {
			return null;
		}

		if ( 0 === strpos( $url, '//' ) ) {
			$url = (string) $env['scheme'] . ':' . $url;
		} elseif ( '/' === $url[0] ) {
			$url = (string) $env['origin'] . $url;
		} elseif ( ! preg_match( '#^https?://#i', $url ) ) {
			return null; // Other schemes and document-relative URLs are never resolved.
		}

		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper.
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}

		$host = strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( ! in_array( $host, (array) $env['hosts'], true ) ) {
			return null;
		}

		return $url;
	}

	/**
	 * Environment derived from WordPress (cached for the request).
	 *
	 * Keys: hosts[], scheme, origin, map [url path prefix => directory] (longest first),
	 * roots[] (real paths), core[], plugins[], mu_plugins[], themes[], uploads, generated[], max_bytes.
	 *
	 * @return array<string,mixed>
	 */
	public static function environment(): array {
		if ( null !== self::$env ) {
			return self::$env;
		}

		$home     = home_url( '/' );
		$site     = site_url( '/' );
		$content  = content_url( '/' );
		$includes = includes_url();
		$plugins  = function_exists( 'plugins_url' ) ? plugins_url( '/' ) : $content . 'plugins/';
		$uploads  = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : array();

		$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins';
		$mu_dir     = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		$mu_url     = defined( 'WPMU_PLUGIN_URL' ) ? WPMU_PLUGIN_URL . '/' : $content . 'mu-plugins/';
		$wpinc      = defined( 'WPINC' ) ? WPINC : 'wp-includes';

		$hosts = array();
		foreach ( array( $home, $site, $content, $includes, $plugins, $uploads['baseurl'] ?? '', $mu_url ) as $url ) {
			$parts = parse_url( (string) $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
				$hosts[] = strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
			}
		}

		$map = array();
		$add = static function ( string $url, string $dir ) use ( &$map ): void {
			$path = parse_url( $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			if ( is_string( $path ) && '' !== $dir ) {
				$prefix = rtrim( $path, '/' ) . '/';
				if ( ! isset( $map[ $prefix ] ) ) {
					$map[ $prefix ] = rtrim( str_replace( '\\', '/', $dir ), '/' );
				}
			}
		};
		$add( $plugins, $plugin_dir );
		$add( $mu_url, $mu_dir );
		if ( ! empty( $uploads['baseurl'] ) && ! empty( $uploads['basedir'] ) ) {
			$add( (string) $uploads['baseurl'], (string) $uploads['basedir'] );
		}
		$add( $content, WP_CONTENT_DIR );
		$add( $includes, ABSPATH . $wpinc );
		$add( site_url( '/wp-admin/' ), ABSPATH . 'wp-admin' );
		$add( $site, ABSPATH );
		if ( rtrim( (string) parse_url( $home, PHP_URL_PATH ), '/' ) === rtrim( (string) parse_url( $site, PHP_URL_PATH ), '/' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			$add( $home, ABSPATH );
		}
		uksort(
			$map,
			static function ( $a, $b ) {
				return strlen( (string) $b ) <=> strlen( (string) $a );
			}
		);

		$theme_roots = array();
		if ( ! empty( $GLOBALS['wp_theme_directories'] ) && is_array( $GLOBALS['wp_theme_directories'] ) ) {
			$theme_roots = $GLOBALS['wp_theme_directories'];
		} elseif ( function_exists( 'get_theme_root' ) ) {
			$theme_roots = array( get_theme_root() );
		}
		$theme_roots[] = WP_CONTENT_DIR . '/themes';

		$generated = array( WP_CONTENT_DIR . '/cache/sh-speed-optimizer' );
		if ( class_exists( '\SH\SpeedOptimizer\Core\Filesystem' ) && function_exists( 'wp_normalize_path' ) ) {
			$generated[] = \SH\SpeedOptimizer\Core\Filesystem::cache_root();
			if ( ! empty( $uploads['basedir'] ) ) {
				$generated[] = rtrim( (string) $uploads['basedir'], '/' ) . '/sh-speed-optimizer';
			}
		}

		$origin_parts = parse_url( $home ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$scheme       = is_array( $origin_parts ) && ! empty( $origin_parts['scheme'] ) ? strtolower( $origin_parts['scheme'] ) : 'https';
		$origin       = $scheme . '://' . ( $origin_parts['host'] ?? '' ) . ( isset( $origin_parts['port'] ) ? ':' . $origin_parts['port'] : '' );

		self::$env = array(
			'hosts'      => array_values( array_unique( $hosts ) ),
			'scheme'     => $scheme,
			'origin'     => $origin,
			'map'        => $map,
			'roots'      => self::real_list( array( ABSPATH, WP_CONTENT_DIR ) ),
			'core'       => self::real_list( array( ABSPATH . $wpinc, ABSPATH . 'wp-admin' ) ),
			'plugins'    => self::real_list( array( $plugin_dir ) ),
			'mu_plugins' => self::real_list( array( $mu_dir ) ),
			'themes'     => self::real_list( $theme_roots ),
			'uploads'    => self::real_list( array( (string) ( $uploads['basedir'] ?? '' ) ) ),
			'generated'  => self::real_list( $generated ),
			'max_bytes'  => self::MAX_BYTES,
		);

		return self::$env;
	}

	/**
	 * Forget cached environment and resolutions (tests, multisite switches).
	 */
	public static function reset(): void {
		self::$env   = null;
		self::$cache = array();
	}

	/**
	 * Attribute a real path to its source.
	 *
	 * @param string              $real Real path.
	 * @param array<string,mixed> $env  Environment.
	 * @return array{type:string,slug:string,name:string}
	 */
	public static function attribute( string $real, array $env ): array {
		$checks = array(
			array( 'generated', self::TYPE_GENERATED ),
			array( 'mu_plugins', self::TYPE_PLUGIN ),
			array( 'plugins', self::TYPE_PLUGIN ),
			array( 'themes', self::TYPE_THEME ),
			array( 'uploads', self::TYPE_UPLOADS ),
			array( 'core', self::TYPE_CORE ),
		);

		foreach ( $checks as $check ) {
			foreach ( (array) ( $env[ $check[0] ] ?? array() ) as $root ) {
				$relative = self::relative_to( $real, (string) $root );
				if ( null === $relative ) {
					continue;
				}
				if ( self::TYPE_GENERATED === $check[1] ) {
					return array(
						'type' => self::TYPE_GENERATED,
						'slug' => 'sh-speed-optimizer',
						'name' => 'SH Speed Optimizer',
					);
				}
				if ( self::TYPE_CORE === $check[1] ) {
					return array(
						'type' => self::TYPE_CORE,
						'slug' => 'wordpress',
						'name' => 'WordPress',
					);
				}
				$first = explode( '/', $relative )[0];
				if ( false === strpos( $relative, '/' ) ) {
					$first = (string) preg_replace( '/(\.min)?\.(css|js)$/i', '', $first ); // Single-file (mu-)plugin.
				}
				$slug = self::slug( $first );
				return array(
					'type' => $check[1],
					'slug' => $slug,
					'name' => self::humanize( $slug ),
				);
			}
		}

		$slug  = 'other';
		$roots = (array) $env['roots'];
		usort(
			$roots,
			static function ( $a, $b ) {
				return strlen( (string) $b ) <=> strlen( (string) $a ); // Most specific root first.
			}
		);
		foreach ( $roots as $root ) {
			$relative = self::relative_to( $real, (string) $root );
			if ( null !== $relative && false !== strpos( $relative, '/' ) ) {
				$slug = self::slug( explode( '/', $relative )[0] );
				break;
			}
		}
		return array(
			'type' => self::TYPE_OTHER,
			'slug' => $slug,
			'name' => self::humanize( $slug ),
		);
	}

	/**
	 * Path relative to a root, or null when outside.
	 *
	 * @param string $real Real path.
	 * @param string $root Real root.
	 */
	private static function relative_to( string $real, string $root ): ?string {
		$root = rtrim( $root, '/' );
		if ( '' === $root ) {
			return null;
		}
		return 0 === strpos( $real, $root . '/' ) ? substr( $real, strlen( $root ) + 1 ) : null;
	}

	/**
	 * Whether a real path is inside one of the roots.
	 *
	 * @param string   $real  Real path.
	 * @param string[] $roots Real roots.
	 */
	private static function inside_any( string $real, array $roots ): bool {
		foreach ( $roots as $root ) {
			if ( null !== self::relative_to( $real, (string) $root ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Real paths of existing directories.
	 *
	 * @param string[] $dirs Directories.
	 * @return string[]
	 */
	private static function real_list( array $dirs ): array {
		$out = array();
		foreach ( $dirs as $dir ) {
			if ( '' === (string) $dir ) {
				continue;
			}
			$real = realpath( (string) $dir );
			if ( false !== $real && is_dir( $real ) ) {
				$out[] = rtrim( str_replace( '\\', '/', $real ), '/' );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Safe slug.
	 *
	 * @param string $value Raw value.
	 */
	private static function slug( string $value ): string {
		$slug = trim( (string) preg_replace( '/[^a-z0-9_\-]+/', '-', strtolower( $value ) ), '-' );
		return '' === $slug ? 'other' : substr( $slug, 0, 60 );
	}

	/**
	 * Readable label from a slug ("contact-form-7" → "Contact Form 7").
	 *
	 * @param string $slug Slug.
	 */
	private static function humanize( string $slug ): string {
		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}
}
