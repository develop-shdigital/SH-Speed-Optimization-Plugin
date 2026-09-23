<?php
/**
 * Database query analysis for signed analysis requests.
 *
 * Report only: the collector never changes a query or any plugin code. It
 * turns on WordPress' own query log (SAVEQUERIES) for the analysis request,
 * attributes every query to the plugin, theme or core code that triggered
 * it and summarizes the log without storing any literal values.
 *
 * Usage (scanner): call {@see start()} as early as possible in an analysis
 * request and {@see report()} at shutdown; later feed the reports of several
 * pages to {@see findings()}.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\QueryOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Query collector.
 */
final class QueryCollector {

	/**
	 * Backtrace depth inspected per query.
	 */
	public const TRACE_DEPTH = 25;

	/**
	 * Whether start() ran.
	 *
	 * @var bool
	 */
	private static bool $started = false;

	/**
	 * Cached path roots for attribution.
	 *
	 * @var array<string,string[]>|null
	 */
	private static ?array $roots = null;

	/**
	 * Enable the query log for this request.
	 *
	 * WordPress checks SAVEQUERIES when each query runs, so every query after
	 * this call is logged. If SAVEQUERIES was explicitly disabled in
	 * wp-config.php nothing is changed and the report is "unavailable".
	 */
	public static function start(): void {
		if ( self::$started ) {
			return;
		}
		self::$started = true;

		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant, only defined in signed analysis requests.
		}
		if ( ! constant( 'SAVEQUERIES' ) ) {
			return;
		}

		add_filter( 'log_query_custom_data', array( self::class, 'attach' ), 10, 1 );
	}

	/**
	 * Whether start() ran in this request.
	 */
	public static function is_started(): bool {
		return self::$started;
	}

	/**
	 * `log_query_custom_data` filter: attach the component that triggered the query.
	 *
	 * @param mixed $query_data Custom query data.
	 * @return mixed
	 */
	public static function attach( $query_data ) {
		try {
			$query_data = is_array( $query_data ) ? $query_data : array();
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Attribution of queries during signed analysis requests only.
			$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, self::TRACE_DEPTH );
			$files = array();
			foreach ( $trace as $frame ) {
				if ( isset( $frame['file'] ) && is_string( $frame['file'] ) ) {
					$files[] = $frame['file'];
				}
			}
			$query_data[ QueryReport::DATA_KEY ] = self::component_for_files( $files, self::roots() );
		} catch ( \Throwable $e ) {
			unset( $e ); // Attribution must never break a query.
		}
		return $query_data;
	}

	/**
	 * Report for the current request (call at shutdown).
	 *
	 * @return array<string,mixed> See {@see QueryReport::build()}.
	 */
	public static function report(): array {
		global $wpdb;

		if ( ! defined( 'SAVEQUERIES' ) || ! constant( 'SAVEQUERIES' ) || ! is_object( $wpdb ) || empty( $wpdb->queries ) || ! is_array( $wpdb->queries ) ) {
			return QueryReport::unavailable();
		}

		try {
			return QueryReport::build( $wpdb->queries );
		} catch ( \Throwable $e ) {
			return QueryReport::unavailable();
		}
	}

	/**
	 * Findings from the reports of several pages.
	 *
	 * @param array<string|int,array<string,mixed>> $reports Page key (template key, URL or label) => report.
	 * @param array<string,string>|null             $names   Component/slug => display name (null = from WordPress).
	 * @return array<int,array<string,mixed>>
	 */
	public static function findings( array $reports, ?array $names = null ): array {
		return QueryFindings::build( $reports, $names ?? self::component_names() );
	}

	/**
	 * Attribute a query to a component from backtrace file paths (innermost first).
	 *
	 * The first frame inside a plugin, must-use plugin or theme wins, so a
	 * core function called by a plugin is attributed to that plugin. Frames in
	 * ignored paths (wpdb, the db.php drop-in, this plugin) are skipped.
	 *
	 * @param string[]               $files Backtrace file paths.
	 * @param array<string,string[]> $roots plugin, mu-plugin, theme and ignore path lists.
	 * @return string "plugin:slug", "mu-plugin:slug", "theme:slug" or "core".
	 */
	public static function component_for_files( array $files, array $roots ): string {
		$ignore = array();
		foreach ( (array) ( $roots['ignore'] ?? array() ) as $path ) {
			$path = self::normalize_path( (string) $path );
			if ( '' !== $path ) {
				$ignore[] = $path;
			}
		}

		$typed = array();
		foreach ( array( 'mu-plugin', 'plugin', 'theme' ) as $type ) {
			foreach ( (array) ( $roots[ $type ] ?? array() ) as $root ) {
				$root = rtrim( self::normalize_path( (string) $root ), '/' );
				if ( '' !== $root ) {
					$typed[] = array( $root . '/', $type );
				}
			}
		}
		// Longest root first, so nested roots resolve to the most specific type.
		usort( $typed, static fn( array $a, array $b ): int => strlen( $b[0] ) <=> strlen( $a[0] ) );

		foreach ( $files as $file ) {
			$file = self::normalize_path( (string) $file );
			if ( '' === $file ) {
				continue;
			}
			foreach ( $ignore as $path ) {
				if ( 0 === strpos( $file, $path ) ) {
					continue 2;
				}
			}
			foreach ( $typed as list( $root, $type ) ) {
				if ( 0 !== strpos( $file, $root ) ) {
					continue;
				}
				$rest = substr( $file, strlen( $root ) );
				$slug = false === strpos( $rest, '/' ) ? preg_replace( '/\.php$/i', '', $rest ) : strstr( $rest, '/', true );
				$slug = substr( (string) preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $slug ), 0, 100 );
				if ( '' !== $slug ) {
					return $type . ':' . $slug;
				}
			}
		}

		return 'core';
	}

	/**
	 * Display names of plugins and themes from WordPress.
	 *
	 * @return array<string,string>
	 */
	public static function component_names(): array {
		$names = array();

		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'get_plugins' ) ) {
			foreach ( (array) get_plugins() as $file => $data ) {
				$slug = false === strpos( (string) $file, '/' ) ? preg_replace( '/\.php$/', '', (string) $file ) : strstr( (string) $file, '/', true );
				if ( ! empty( $data['Name'] ) ) {
					$names[ 'plugin:' . $slug ] = wp_strip_all_tags( (string) $data['Name'] );
				}
			}
		}
		if ( function_exists( 'get_mu_plugins' ) ) {
			foreach ( (array) get_mu_plugins() as $file => $data ) {
				if ( ! empty( $data['Name'] ) ) {
					$names[ 'mu-plugin:' . preg_replace( '/\.php$/', '', (string) $file ) ] = wp_strip_all_tags( (string) $data['Name'] );
				}
			}
		}
		if ( function_exists( 'wp_get_themes' ) ) {
			foreach ( (array) wp_get_themes() as $stylesheet => $theme ) {
				$names[ 'theme:' . $stylesheet ] = wp_strip_all_tags( (string) $theme->get( 'Name' ) );
			}
		}

		return $names;
	}

	/**
	 * Path roots of this installation.
	 *
	 * @return array<string,string[]>
	 */
	private static function roots(): array {
		if ( null !== self::$roots ) {
			return self::$roots;
		}

		$plugins = defined( 'WP_PLUGIN_DIR' ) ? array( WP_PLUGIN_DIR ) : array();
		$mu      = defined( 'WPMU_PLUGIN_DIR' ) ? array( WPMU_PLUGIN_DIR ) : array();
		$themes  = function_exists( 'get_theme_root' ) ? array( get_theme_root() ) : array();
		if ( ! empty( $GLOBALS['wp_theme_directories'] ) && is_array( $GLOBALS['wp_theme_directories'] ) ) {
			$themes = array_merge( $themes, $GLOBALS['wp_theme_directories'] );
		}

		$ignore = array( dirname( __DIR__, 2 ) . '/' );
		if ( defined( 'SHSO_DIR' ) ) {
			$ignore[] = SHSO_DIR;
		}
		if ( defined( 'ABSPATH' ) && defined( 'WPINC' ) ) {
			$ignore[] = ABSPATH . WPINC . '/class-wpdb.php';
			$ignore[] = ABSPATH . WPINC . '/wp-db.php';
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$ignore[] = WP_CONTENT_DIR . '/db.php';
		}
		foreach ( $plugins as $dir ) {
			$ignore[] = $dir . '/sqlite-database-integration/';
		}

		$with_real = static function ( array $paths ): array {
			$all = array();
			foreach ( $paths as $path ) {
				$all[] = (string) $path;
				$real  = @realpath( (string) $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false !== $real ) {
					$all[] = is_dir( $real ) ? $real . '/' : $real;
				}
			}
			return array_values( array_unique( $all ) );
		};

		self::$roots = array(
			'plugin'    => $with_real( $plugins ),
			'mu-plugin' => $with_real( $mu ),
			'theme'     => $with_real( $themes ),
			'ignore'    => $with_real( $ignore ),
		);
		return self::$roots;
	}

	/**
	 * Normalize a path (forward slashes, no duplicate slashes).
	 *
	 * @param string $path Path.
	 */
	private static function normalize_path( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		return (string) preg_replace( '#(?<=.)/+#', '/', $path );
	}
}
