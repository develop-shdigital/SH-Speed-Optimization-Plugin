<?php
/**
 * Page cache delivery.
 *
 * Serves cached pages. This file is loaded by `wp-content/advanced-cache.php`
 * before WordPress is loaded, so it must not call any WordPress function and
 * must not depend on any other class of the plugin. The same code runs from
 * the plugin's fallback path (`plugins_loaded`) when the drop-in is not
 * active, and its pure helpers (decide(), path_segments() …) are shared with
 * the storage side so both always agree on cache keys.
 *
 * Everything that is not clearly a cacheable anonymous page view is bypassed;
 * any error fails open (WordPress renders the page normally).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Cache;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Runs before WordPress is loaded: no WP_Filesystem, no wp_json_encode().
// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Superglobals are validated here without WordPress helpers.
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- Cache reads must fail silently (fail open).

/**
 * Cache delivery (standalone, no WordPress functions).
 */
final class Delivery {

	/**
	 * Response header telling whether the page came from the cache.
	 */
	public const HEADER = 'X-SHSO-Cache';

	/**
	 * Cache file format version (first line JSON metadata, "\n", HTML body).
	 */
	public const FORMAT_VERSION = 1;

	/**
	 * One in N decisions is recorded in the statistics.
	 */
	public const SAMPLE_RATE = 20;

	/**
	 * Limits protecting the file system from hostile URLs.
	 */
	public const MAX_DEPTH          = 20;
	public const MAX_URI_LENGTH     = 2048;
	public const MAX_SEGMENT_LENGTH = 150;
	public const MAX_VALUE_LENGTH   = 100;

	/**
	 * Cookie name prefixes that always bypass the cache (they identify a visitor
	 * with personal content: logged in, cart, password protected post, commenter …).
	 */
	public const BYPASS_COOKIES = array(
		'wordpress_logged_in_',
		'wp-postpass_',
		'comment_author_',
		'woocommerce_items_in_cart',
		'woocommerce_cart_hash',
		'wp_woocommerce_session_',
		'woocommerce_recently_viewed',
		'edd_items_in_cart',
		'edd_cart',
		'wp-resetpass-',
		'wordpress_no_cache',
		'PHPSESSID', // A PHP session may personalise pages without setting a cookie in the same response.
	);

	/**
	 * WPtouch desktop/mobile switch (bypasses the cache when no mobile variant is kept).
	 */
	public const MOBILE_TOGGLE_COOKIE = 'wptouch_switch_toggle';

	/**
	 * Query parameters that always bypass the cache, whatever the configuration says.
	 */
	public const FORBIDDEN_PARAMS = array(
		'shso_verify',
		'shso_nc',
		'preview',
		'preview_id',
		'preview_nonce',
		'add-to-cart',
		'removed_item',
		'remove_item',
		'undo_item',
		'wc-ajax',
		'wc-api',
		'rest_route',
		'customize_changeset_uuid',
		'doing_wp_cron',
	);

	/**
	 * Path segments that are never pages.
	 */
	private const SPECIAL_SEGMENTS = array( 'wp-admin', 'wp-includes', 'wp-content', 'wp-json', 'feed', 'wc-api', 'trackback' );

	/**
	 * Response headers that are never stored or replayed.
	 */
	private const NON_REPLAYABLE_HEADERS = array(
		'set-cookie',
		'content-length',
		'content-encoding',
		'content-type',
		'transfer-encoding',
		'date',
		'expires',
		'cache-control',
		'pragma',
		'last-modified',
		'etag',
		'x-powered-by',
		'vary',
		'connection',
		'keep-alive',
		'server',
		'status',
		'location',
		'age',
	);

	/**
	 * Bypass reasons counted as (sampled) page views in the statistics.
	 */
	private const COUNTED_BYPASS = array( 'cookie', 'query', 'param', 'excluded' );

	/**
	 * Decision of the current request (shared between the drop-in and the capture).
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $last = null;

	/**
	 * Parsed configuration files (per request).
	 *
	 * @var array<string,array<string,mixed>|null>
	 */
	private static array $configs = array();

	// ---------------------------------------------------------------------
	// Entry point.
	// ---------------------------------------------------------------------

	/**
	 * Serve the current request from the cache when possible.
	 *
	 * On a hit the response is sent and the script exits. Otherwise the
	 * decision is returned (null when the cache is not configured for this
	 * request) and WordPress renders the page.
	 *
	 * @param string $cache_root Cache root directory (wp-content/cache/sh-speed-optimizer/).
	 * @param string $mode       dropin|fallback.
	 * @return array<string,mixed>|null Decision.
	 */
	public static function serve( string $cache_root, string $mode = 'dropin' ): ?array {
		try {
			return self::run( $cache_root, $mode );
		} catch ( \Throwable $e ) {
			unset( $e );
			return null; // Fail open: WordPress renders the page.
		}
	}

	/**
	 * Decision of the current request, if the delivery ran.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function last_decision(): ?array {
		return self::$last;
	}

	/**
	 * Forget per-request state (tests, config rewrites).
	 */
	public static function reset(): void {
		self::$last    = null;
		self::$configs = array();
	}

	/**
	 * Forget parsed configuration files (after they were rewritten).
	 */
	public static function flush_configs(): void {
		self::$configs = array();
	}

	/**
	 * Serve implementation.
	 *
	 * @param string $cache_root Cache root.
	 * @param string $mode       dropin|fallback.
	 * @return array<string,mixed>|null
	 */
	private static function run( string $cache_root, string $mode ): ?array {
		foreach ( array( 'SHSO_SAFE_MODE', 'SHSO_DISABLE_CACHE', 'DOING_CRON', 'DOING_AJAX', 'WP_INSTALLING', 'XMLRPC_REQUEST', 'REST_REQUEST', 'WP_CLI' ) as $constant ) {
			if ( defined( $constant ) && constant( $constant ) ) {
				return null;
			}
		}
		if ( 'cli' === PHP_SAPI || 'phpdbg' === PHP_SAPI ) {
			return null;
		}

		$root = rtrim( str_replace( '\\', '/', $cache_root ), '/' ) . '/';
		$host = self::normalize_host( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
		if ( null === $host ) {
			return null;
		}

		$config = self::load_config( $root, $host );
		if ( null === $config ) {
			return null;
		}

		$decision = self::decide( $_SERVER, $_COOKIE, $config );

		// Fallback mode runs after plugins were loaded: a plugin may already have queued a cookie.
		if ( 'cache' === $decision['action'] && 'fallback' === $mode && self::has_unsafe_set_cookie( headers_list(), (array) ( $decision['site']['safe_cookies'] ?? array() ) ) ) {
			$decision = self::bypass( $decision, 'set_cookie' );
		}

		self::$last = $decision;

		if ( 'bypass' === $decision['action'] ) {
			if ( '' !== $decision['prefix'] && ! in_array( $decision['reason'], array( 'disabled', 'host_not_allowed' ), true ) ) {
				self::send_header( self::HEADER . ': BYPASS' );
				if ( in_array( $decision['reason'], self::COUNTED_BYPASS, true ) ) {
					self::sample( $root, $decision['site_key'], 'bypass' );
				}
			}
			return $decision;
		}

		$entry = self::lookup( $root, $decision, time() );
		if ( null !== $entry && ! headers_sent() ) {
			self::sample( $root, $decision['site_key'], 'hit' );
			self::send( $entry, $decision, $_SERVER, $mode );
			exit;
		}

		$decision['status'] = 'miss';
		self::$last         = $decision;
		self::send_header( self::HEADER . ': MISS' );
		self::sample( $root, $decision['site_key'], 'miss' );

		return $decision;
	}

	// ---------------------------------------------------------------------
	// Decision (pure).
	// ---------------------------------------------------------------------

	/**
	 * Decide whether a request may be served from / stored in the cache.
	 *
	 * Returned keys: action (cache|bypass), reason (bypass reason code), host,
	 * prefix (matched site path prefix), site_key, site (site config), dir
	 * (directory below pages/), file (variant file name), url, https, mobile,
	 * variant (https, slash, mobile, query, vary cookie names), tracking (ignored
	 * tracking parameters were present: serve from the cache, never store).
	 *
	 * @param array<string,mixed> $server  $_SERVER-like array.
	 * @param array<string,mixed> $cookies $_COOKIE-like array.
	 * @param array<string,mixed> $config  Host configuration ({ sites: { prefix: site config } }).
	 * @return array<string,mixed>
	 */
	public static function decide( array $server, array $cookies, array $config ): array {
		$result = array(
			'action'   => 'bypass',
			'reason'   => '',
			'status'   => '',
			'host'     => '',
			'prefix'   => '',
			'site_key' => '',
			'site'     => array(),
			'dir'      => '',
			'file'     => '',
			'url'      => '',
			'https'    => self::is_https( $server ),
			'mobile'   => false,
			'variant'  => array(),
			'tracking' => false,
		);

		$host = self::normalize_host( (string) ( $server['HTTP_HOST'] ?? '' ) );
		if ( null === $host ) {
			return self::bypass( $result, 'host' );
		}
		$result['host'] = $host;

		$uri = (string) ( $server['REQUEST_URI'] ?? '/' );
		$uri = '' === $uri ? '/' : $uri;
		if ( strlen( $uri ) > self::MAX_URI_LENGTH || '/' !== $uri[0] || preg_match( '/[\x00-\x1F\x7F]/', $uri ) ) {
			return self::bypass( $result, 'uri' );
		}
		$fragment = strpos( $uri, '#' );
		if ( false !== $fragment ) {
			$uri = substr( $uri, 0, $fragment );
		}
		$qpos  = strpos( $uri, '?' );
		$path  = false === $qpos ? $uri : substr( $uri, 0, $qpos );
		$query = false === $qpos ? '' : substr( $uri, $qpos + 1 );
		$path  = '' === $path ? '/' : $path;

		$match = self::match_site( $config, $path );
		if ( null === $match ) {
			return self::bypass( $result, 'site' );
		}
		list( $prefix, $site ) = $match;
		$result['prefix']      = $prefix;
		$result['site']        = $site;
		$result['site_key']    = self::site_key( $host, $prefix );

		if ( empty( $site['enabled'] ) || ! empty( $site['safe_mode'] ) ) {
			return self::bypass( $result, 'disabled' );
		}
		if ( ! in_array( $host, (array) ( $site['hosts'] ?? array() ), true ) ) {
			return self::bypass( $result, 'host_not_allowed' );
		}

		$method = strtoupper( (string) ( $server['REQUEST_METHOD'] ?? 'GET' ) );
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return self::bypass( $result, 'method' );
		}

		if ( self::is_special_path( $path, (string) ( $site['rest_prefix'] ?? 'wp-json' ) ) ) {
			return self::bypass( $result, 'path' );
		}

		// Query string: forbidden → bypass, tracking → ignored, content parameters → variant, unknown → bypass.
		$keep   = array_map( 'strval', (array) ( $site['keep_query'] ?? array() ) );
		$ignore = array_map( 'strtolower', array_map( 'strval', (array) ( $site['ignore_query'] ?? array() ) ) );
		$kept   = array();
		foreach ( self::parse_query( $query ) as $pair ) {
			list( $name, $value ) = $pair;
			$lower                = strtolower( $name );
			if ( in_array( $lower, self::FORBIDDEN_PARAMS, true ) ) {
				return self::bypass( $result, 'param' );
			}
			if ( in_array( $name, $keep, true ) ) {
				if ( strlen( $value ) > self::MAX_VALUE_LENGTH || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
					return self::bypass( $result, 'query' );
				}
				$kept[ $name ] = $value;
				continue;
			}
			if ( in_array( $lower, $ignore, true ) ) {
				// Served from the cache, but never stored: WordPress still sees the parameter
				// and may copy it into the page (pagination links, form fields).
				$result['tracking'] = true;
				continue;
			}
			return self::bypass( $result, 'query' );
		}

		$mobile_variant = ! empty( $site['mobile'] );
		$bypass_cookies = array_merge( self::BYPASS_COOKIES, (array) ( $site['bypass_cookies'] ?? array() ) );
		if ( ! $mobile_variant ) {
			$bypass_cookies[] = self::MOBILE_TOGGLE_COOKIE;
		}
		if ( self::cookie_matches( $bypass_cookies, $cookies ) ) {
			return self::bypass( $result, 'cookie' );
		}

		$excluded = (array) ( $site['exclude_urls'] ?? array() );
		if ( ! empty( $excluded ) && ( self::url_matches( $excluded, $path ) || ( '' !== $query && self::url_matches( $excluded, $path . '?' . $query ) ) ) ) {
			return self::bypass( $result, 'excluded' );
		}

		$segments = self::path_segments( $path );
		if ( null === $segments ) {
			return self::bypass( $result, 'segments' );
		}

		$vary = self::vary_values( (array) ( $site['vary_cookies'] ?? array() ), $cookies );
		if ( null === $vary ) {
			return self::bypass( $result, 'cookie' );
		}

		ksort( $kept, SORT_STRING );
		$mobile = $mobile_variant && self::is_mobile( $server );
		$slash  = '/' === substr( $path, -1 );

		$result['action']  = 'cache';
		$result['mobile']  = $mobile;
		$result['dir']     = self::dir_for( $host, $segments );
		$result['file']    = self::variant_file( $result['https'], $slash, $mobile, $kept, $vary );
		$result['url']     = ( $result['https'] ? 'https' : 'http' ) . '://' . $host . $path . ( empty( $kept ) ? '' : '?' . self::build_query( $kept ) );
		$result['variant'] = array(
			'https'  => $result['https'],
			'slash'  => $slash,
			'mobile' => $mobile,
			'query'  => $kept,
			'vary'   => array_keys( $vary ),
		);

		return $result;
	}

	/**
	 * Mark a decision as bypass.
	 *
	 * @param array<string,mixed> $result Decision.
	 * @param string              $reason Reason code.
	 * @return array<string,mixed>
	 */
	private static function bypass( array $result, string $reason ): array {
		$result['action'] = 'bypass';
		$result['reason'] = $reason;
		return $result;
	}

	/**
	 * Site configuration matching a path (longest prefix wins).
	 *
	 * @param array<string,mixed> $config Host configuration.
	 * @param string              $path   Request path.
	 * @return array{0:string,1:array<string,mixed>}|null
	 */
	public static function match_site( array $config, string $path ): ?array {
		if ( ! isset( $config['sites'] ) || ! is_array( $config['sites'] ) ) {
			return null;
		}

		$check    = strtolower( rtrim( $path, '/' ) . '/' );
		$best     = null;
		$best_len = -1;

		foreach ( $config['sites'] as $prefix => $site ) {
			if ( ! is_string( $prefix ) || ! is_array( $site ) ) {
				continue;
			}
			$normalized = self::normalize_prefix( $prefix );
			if ( 0 === strpos( $check, strtolower( $normalized ) ) && strlen( $normalized ) > $best_len ) {
				$best     = array( $normalized, $site );
				$best_len = strlen( $normalized );
			}
		}

		return $best;
	}

	/**
	 * Normalize a site path prefix to "/", "/blog/" …
	 *
	 * @param string $prefix Prefix.
	 */
	public static function normalize_prefix( string $prefix ): string {
		$trimmed = trim( $prefix, '/' );
		return '' === $trimmed ? '/' : '/' . $trimmed . '/';
	}

	/**
	 * Whether a path targets something that is never a cacheable page
	 * (admin, login, REST, feeds, sitemaps, PHP files, static files …).
	 *
	 * @param string $path        Request path (no query string).
	 * @param string $rest_prefix REST API URL prefix.
	 */
	public static function is_special_path( string $path, string $rest_prefix = 'wp-json' ): bool {
		$lower    = strtolower( rawurldecode( $path ) );
		$segments = array_values(
			array_filter(
				explode( '/', $lower ),
				static function ( $segment ) {
					return '' !== $segment;
				}
			)
		);

		$rest = strtolower( trim( $rest_prefix, '/' ) );
		if ( '' !== $rest && false !== strpos( '/' . implode( '/', $segments ) . '/', '/' . $rest . '/' ) ) {
			return true;
		}

		$count = count( $segments );
		foreach ( $segments as $index => $segment ) {
			if ( in_array( $segment, self::SPECIAL_SEGMENTS, true ) ) {
				return true;
			}
			if ( str_ends_with( $segment, '.php' ) ) {
				// PATH_INFO permalinks (/index.php/2024/01/post/) are regular pages.
				if ( 'index.php' === $segment && 0 === $index && $count > 1 ) {
					continue;
				}
				return true;
			}
		}

		// Files with an extension (robots.txt, sitemap.xml, favicon.ico, style.css …) are not pages.
		if ( $count > 0 && '/' !== substr( $path, -1 ) && preg_match( '/\.([a-z0-9]{1,8})$/', $segments[ $count - 1 ], $m ) && ! in_array( $m[1], array( 'html', 'htm' ), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Parse a raw query string into [ name, value ] pairs (names and values decoded).
	 *
	 * @param string $query Raw query string.
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function parse_query( string $query ): array {
		$pairs = array();
		if ( '' === $query ) {
			return $pairs;
		}
		foreach ( explode( '&', $query ) as $part ) {
			if ( '' === $part ) {
				continue;
			}
			$kv   = explode( '=', $part, 2 );
			$name = urldecode( $kv[0] );
			if ( '' === $name ) {
				continue;
			}
			$pairs[] = array( $name, isset( $kv[1] ) ? urldecode( $kv[1] ) : '' );
		}
		return $pairs;
	}

	/**
	 * Build a normalized query string from sorted parameters.
	 *
	 * @param array<string,string> $params Parameters.
	 */
	private static function build_query( array $params ): string {
		$parts = array();
		foreach ( $params as $name => $value ) {
			$parts[] = rawurlencode( (string) $name ) . '=' . rawurlencode( (string) $value );
		}
		return implode( '&', $parts );
	}

	/**
	 * Normalize a Host header: lowercase, validated host name or IP with optional port.
	 *
	 * @param string $host Host header.
	 */
	public static function normalize_host( string $host ): ?string {
		$host = strtolower( trim( $host ) );
		if ( '' === $host || strlen( $host ) > 255 ) {
			return null;
		}
		$label = '[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?';
		if ( ! preg_match( '/^(?:' . $label . '(?:\.' . $label . ')*|\[[0-9a-f:.]+\])(?::[0-9]{1,5})?$/', $host ) ) {
			return null;
		}
		return $host;
	}

	/**
	 * File-system safe key of a host ("localhost:8080" → "localhost+8080").
	 *
	 * @param string $host Normalized host.
	 */
	public static function host_key( string $host ): string {
		return (string) preg_replace( '/[^a-z0-9._-]/', '+', strtolower( $host ) );
	}

	/**
	 * Key of a site (host + path prefix) used for statistics files.
	 *
	 * @param string $host   Normalized host.
	 * @param string $prefix Site path prefix.
	 */
	public static function site_key( string $host, string $prefix ): string {
		$trimmed = trim( strtolower( $prefix ), '/' );
		return self::host_key( $host ) . ( '' === $trimmed ? '' : '~' . preg_replace( '/[^a-z0-9._-]/', '_', $trimmed ) );
	}

	/**
	 * Sanitized directory segments of a request path.
	 *
	 * Each segment is decoded, re-encoded canonically (lowercase percent
	 * escapes) and kept only when it consists of [a-z0-9._~%-]; anything else
	 * (uppercase, very long, hidden, "index…") is replaced by a hash so it can
	 * never escape the cache directory or collide with cache files.
	 * Returns null for paths that must not be cached ("..", encoded slashes,
	 * empty segments, too deep).
	 *
	 * @param string $path Request path (no query string).
	 * @return string[]|null
	 */
	public static function path_segments( string $path ): ?array {
		if ( '' === $path || '/' !== $path[0] || false !== strpos( $path, '//' ) || false !== strpos( $path, '\\' ) || false !== strpos( $path, "\0" ) ) {
			return null;
		}

		$segments = array();
		foreach ( explode( '/', trim( $path, '/' ) ) as $raw ) {
			if ( '' === $raw ) {
				continue;
			}
			$decoded = rawurldecode( $raw );
			if ( '.' === $decoded || '..' === $decoded || '' === $decoded || preg_match( '#[/\\\\\x00-\x1F\x7F]#', $decoded ) ) {
				return null;
			}
			$encoded = (string) preg_replace_callback(
				'/%[0-9A-F]{2}/',
				static function ( $m ) {
					return strtolower( $m[0] );
				},
				rawurlencode( $decoded )
			);
			if ( strlen( $encoded ) > self::MAX_SEGMENT_LENGTH || preg_match( '/[^a-z0-9._~%-]/', $encoded ) || '.' === $encoded[0] || 0 === strpos( $encoded, 'index' ) ) {
				$encoded = '=' . substr( md5( $decoded ), 0, 20 );
			}
			$segments[] = $encoded;
			if ( count( $segments ) > self::MAX_DEPTH ) {
				return null;
			}
		}

		return $segments;
	}

	/**
	 * Directory of a page below pages/ ("example.com/blog/post/").
	 *
	 * @param string   $host     Normalized host.
	 * @param string[] $segments Sanitized segments.
	 */
	public static function dir_for( string $host, array $segments ): string {
		return self::host_key( $host ) . '/' . ( empty( $segments ) ? '' : implode( '/', $segments ) . '/' );
	}

	/**
	 * Variant file name inside a page directory.
	 *
	 * The plain "index.html" is HTTPS, trailing slash, desktop, no query parameters, no vary cookies.
	 *
	 * @param bool                 $https  HTTPS request.
	 * @param bool                 $slash  Path ends with a slash.
	 * @param bool                 $mobile Mobile variant.
	 * @param array<string,string> $query  Kept query parameters (sorted).
	 * @param array<string,string> $vary   Vary cookie values (sorted).
	 */
	public static function variant_file( bool $https, bool $slash, bool $mobile, array $query = array(), array $vary = array() ): string {
		$name = 'index';
		if ( ! $https ) {
			$name .= '-http';
		}
		if ( ! $slash ) {
			$name .= '-ns';
		}
		if ( $mobile ) {
			$name .= '-mobile';
		}
		if ( ! empty( $query ) || ! empty( $vary ) ) {
			ksort( $query, SORT_STRING );
			ksort( $vary, SORT_STRING );
			$name .= '-' . substr( md5( (string) json_encode( array( $query, $vary ) ) ), 0, 16 );
		}
		return $name . '.html';
	}

	/**
	 * Whether a file name is a cache variant file (index*.html).
	 *
	 * @param string $name File name.
	 */
	public static function is_variant_file( string $name ): bool {
		return (bool) preg_match( '/^index(?:-[a-z0-9-]+)?\.html$/', $name );
	}

	/**
	 * HTTPS detection identical to WordPress' is_ssl() (proxy headers are not trusted).
	 *
	 * @param array<string,mixed> $server $_SERVER-like array.
	 */
	public static function is_https( array $server ): bool {
		$https = strtolower( (string) ( $server['HTTPS'] ?? '' ) );
		if ( 'on' === $https || '1' === $https ) {
			return true;
		}
		return '443' === (string) ( $server['SERVER_PORT'] ?? '' );
	}

	/**
	 * Mobile detection identical to WordPress' wp_is_mobile().
	 *
	 * @param array<string,mixed> $server $_SERVER-like array.
	 */
	public static function is_mobile( array $server ): bool {
		if ( isset( $server['HTTP_SEC_CH_UA_MOBILE'] ) ) {
			return '?1' === (string) $server['HTTP_SEC_CH_UA_MOBILE'];
		}
		$agent = (string) ( $server['HTTP_USER_AGENT'] ?? '' );
		if ( '' === $agent ) {
			return false;
		}
		foreach ( array( 'Mobile', 'Android', 'Silk/', 'Kindle', 'BlackBerry', 'Opera Mini', 'Opera Mobi' ) as $needle ) {
			if ( false !== strpos( $agent, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a cookie name matches a pattern (prefix, or wildcard with "*").
	 * PHP turns "." and " " in cookie names into "_", so patterns are normalized the same way.
	 *
	 * @param string $pattern Pattern.
	 * @param string $name    Cookie name.
	 */
	public static function cookie_name_matches( string $pattern, string $name ): bool {
		$pattern = strtolower( str_replace( array( '.', ' ' ), '_', trim( $pattern ) ) );
		$name    = strtolower( str_replace( array( '.', ' ' ), '_', $name ) );
		if ( '' === $pattern || '' === $name ) {
			return false;
		}
		if ( false !== strpos( $pattern, '*' ) ) {
			return (bool) preg_match( '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '/', $name );
		}
		return 0 === strpos( $name, $pattern );
	}

	/**
	 * Whether any cookie matches one of the patterns.
	 *
	 * @param string[]            $patterns Patterns.
	 * @param array<string,mixed> $cookies  Cookies (name => value).
	 */
	public static function cookie_matches( array $patterns, array $cookies ): bool {
		foreach ( array_keys( $cookies ) as $name ) {
			foreach ( $patterns as $pattern ) {
				if ( self::cookie_name_matches( (string) $pattern, (string) $name ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Values of the vary cookies present in the request (null when a value is unsafe).
	 *
	 * @param string[]            $names   Vary cookie names.
	 * @param array<string,mixed> $cookies Cookies.
	 * @return array<string,string>|null
	 */
	public static function vary_values( array $names, array $cookies ): ?array {
		$normalized = array();
		foreach ( $cookies as $name => $value ) {
			$normalized[ strtolower( str_replace( array( '.', ' ' ), '_', (string) $name ) ) ] = $value;
		}

		$values = array();
		foreach ( $names as $name ) {
			$key = strtolower( str_replace( array( '.', ' ' ), '_', trim( (string) $name ) ) );
			if ( '' === $key || ! isset( $normalized[ $key ] ) ) {
				continue;
			}
			$value = $normalized[ $key ];
			if ( ! is_scalar( $value ) ) {
				return null;
			}
			$value = (string) $value;
			if ( '' === $value ) {
				continue;
			}
			// Unusual values would let anyone create unlimited cache variants.
			if ( strlen( $value ) > 64 || ! preg_match( '/^[A-Za-z0-9_.,:|-]+$/', $value ) ) {
				return null;
			}
			$values[ $key ] = $value;
		}
		ksort( $values, SORT_STRING );
		return $values;
	}

	/**
	 * URL pattern matching with the same semantics as Context::url_matches():
	 * plain substrings (case-insensitive), wildcards ("/landing/*") or regular
	 * expressions wrapped in "#…#". Percent-encoded and decoded forms are both tested.
	 *
	 * @param string[] $patterns Patterns.
	 * @param string   $subject  Path (optionally with query string).
	 */
	public static function url_matches( array $patterns, string $subject ): bool {
		$subjects = array_unique( array( $subject, rawurldecode( $subject ) ) );
		foreach ( $patterns as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			foreach ( $subjects as $haystack ) {
				if ( strlen( $pattern ) > 2 && '#' === $pattern[0] && '#' === substr( $pattern, -1 ) ) {
					if ( @preg_match( $pattern, $haystack ) ) {
						return true;
					}
					continue;
				}
				foreach ( array_unique( array( $pattern, rawurldecode( $pattern ) ) ) as $needle ) {
					if ( false !== strpos( $needle, '*' ) ) {
						if ( preg_match( '#' . str_replace( '\*', '.*', preg_quote( $needle, '#' ) ) . '#i', $haystack ) ) {
							return true;
						}
						continue;
					}
					if ( false !== stripos( $haystack, $needle ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Whether a header list contains a Set-Cookie that is not whitelisted.
	 *
	 * @param string[] $headers      Header lines ("Name: value").
	 * @param string[] $safe_cookies Cookie name patterns that may be set.
	 */
	public static function has_unsafe_set_cookie( array $headers, array $safe_cookies ): bool {
		foreach ( $headers as $line ) {
			$line = (string) $line;
			if ( 0 !== stripos( $line, 'set-cookie:' ) ) {
				continue;
			}
			$cookie = trim( substr( $line, 11 ) );
			$name   = trim( (string) strtok( $cookie, '=' ) );
			$safe   = false;
			foreach ( $safe_cookies as $pattern ) {
				if ( self::cookie_name_matches( (string) $pattern, $name ) ) {
					$safe = true;
					break;
				}
			}
			if ( ! $safe ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a response header may be stored and replayed from the cache.
	 *
	 * @param string $line Header line ("Name: value").
	 */
	public static function is_replayable_header( string $line ): bool {
		if ( strlen( $line ) > 2048 || ! preg_match( '/^([A-Za-z0-9!#$%&\'*+.^_`|~-]+):[ \t]*([^\r\n]*)$/', $line, $m ) ) {
			return false;
		}
		$name = strtolower( $m[1] );
		if ( 0 === strpos( $name, 'x-shso' ) ) {
			return false;
		}
		return ! in_array( $name, self::NON_REPLAYABLE_HEADERS, true );
	}

	// ---------------------------------------------------------------------
	// Storage format and lookup.
	// ---------------------------------------------------------------------

	/**
	 * Encode a cache entry: JSON metadata on the first line, then the body.
	 *
	 * @param array<string,mixed> $meta Metadata.
	 * @param string              $body HTML.
	 */
	public static function encode_entry( array $meta, string $body ): string {
		return (string) json_encode( $meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ) . "\n" . $body;
	}

	/**
	 * Decode a cache entry.
	 *
	 * @param string $contents File contents.
	 * @return array{meta:array<string,mixed>,body:string}|null
	 */
	public static function decode_entry( string $contents ): ?array {
		$newline = strpos( $contents, "\n" );
		if ( false === $newline ) {
			return null;
		}
		$meta = json_decode( substr( $contents, 0, $newline ), true );
		if ( ! is_array( $meta ) || self::FORMAT_VERSION !== (int) ( $meta['v'] ?? 0 ) ) {
			return null;
		}
		return array(
			'meta' => $meta,
			'body' => (string) substr( $contents, $newline + 1 ),
		);
	}

	/**
	 * Whether a cache entry expired.
	 *
	 * @param array<string,mixed> $meta     Metadata.
	 * @param int                 $lifespan Site lifespan in seconds (0 = only the entry's own expiry).
	 * @param int                 $now      Current time.
	 */
	public static function is_expired( array $meta, int $lifespan, int $now ): bool {
		$created = (int) ( $meta['created'] ?? 0 );
		if ( $created <= 0 || $created > $now + 300 ) {
			return true;
		}
		if ( $lifespan > 0 && $created + $lifespan <= $now ) {
			return true;
		}
		$expires = (int) ( $meta['expires'] ?? 0 );
		return $expires > 0 && $expires <= $now;
	}

	/**
	 * Find a fresh cache entry for a decision.
	 *
	 * @param string              $root     Cache root (trailing slash).
	 * @param array<string,mixed> $decision Decision.
	 * @param int                 $now      Current time.
	 * @return array{path:string,meta:array<string,mixed>,body:string}|null
	 */
	public static function lookup( string $root, array $decision, int $now ): ?array {
		if ( 'cache' !== ( $decision['action'] ?? '' ) || '' === (string) ( $decision['file'] ?? '' ) ) {
			return null;
		}
		$path = $root . 'pages/' . $decision['dir'] . $decision['file'];
		if ( ! is_file( $path ) ) {
			return null;
		}
		$contents = @file_get_contents( $path );
		if ( false === $contents ) {
			return null;
		}
		$entry = self::decode_entry( $contents );
		if ( null === $entry || self::is_expired( $entry['meta'], (int) ( $decision['site']['lifespan'] ?? 0 ), $now ) ) {
			return null;
		}
		$entry['path'] = $path;
		return $entry;
	}

	/**
	 * Load and cache the configuration of a host.
	 *
	 * @param string $root Cache root (trailing slash).
	 * @param string $host Normalized host.
	 * @return array<string,mixed>|null
	 */
	public static function load_config( string $root, string $host ): ?array {
		$file = $root . 'config/' . self::host_key( $host ) . '.json';
		if ( array_key_exists( $file, self::$configs ) ) {
			return self::$configs[ $file ];
		}

		$config = null;
		if ( is_file( $file ) ) {
			$raw  = @file_get_contents( $file );
			$data = false === $raw ? null : json_decode( $raw, true );
			if ( is_array( $data ) && isset( $data['sites'] ) && is_array( $data['sites'] ) ) {
				$config = $data;
			}
		}

		self::$configs[ $file ] = $config;
		return $config;
	}

	// ---------------------------------------------------------------------
	// Response.
	// ---------------------------------------------------------------------

	/**
	 * Headers and status for serving a cache entry (pure).
	 *
	 * @param array<string,mixed> $meta        Entry metadata.
	 * @param array<string,mixed> $decision    Decision.
	 * @param array<string,mixed> $server      $_SERVER-like array (conditional request headers).
	 * @param int                 $body_size   Size of the body that will be sent.
	 * @param bool                $gzip        Whether the precompressed body is sent.
	 * @param bool                $send_length Whether Content-Length may be sent.
	 * @return array{status:int,headers:string[],replay:string[]}
	 */
	public static function response( array $meta, array $decision, array $server, int $body_size, bool $gzip, bool $send_length ): array {
		$created = max( 1, (int) ( $meta['created'] ?? 1 ) );
		$etag    = 'W/"' . dechex( $created ) . '-' . dechex( (int) ( $meta['size'] ?? $body_size ) ) . ( $gzip ? '-gz' : '' ) . '"';
		$vary    = 'Accept-Encoding' . ( ! empty( $decision['site']['mobile'] ) ? ', User-Agent' : '' );

		$headers = array(
			self::HEADER . ': HIT',
			'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $created ) . ' GMT',
			'ETag: ' . $etag,
			'Vary: ' . $vary,
			'Cache-Control: max-age=0, must-revalidate',
		);

		if ( self::not_modified( $server, $etag, $created ) ) {
			return array(
				'status'  => 304,
				'headers' => $headers,
				'replay'  => array(),
			);
		}

		$type = (string) ( $meta['type'] ?? '' );
		if ( ! preg_match( '#^text/html(?:;\s*charset=[A-Za-z0-9._-]+)?$#i', $type ) ) {
			$type = 'text/html; charset=UTF-8';
		}
		array_unshift( $headers, 'Content-Type: ' . $type );

		if ( $gzip ) {
			$headers[] = 'Content-Encoding: gzip';
		}
		if ( $send_length ) {
			$headers[] = 'Content-Length: ' . $body_size;
		}

		$replay = array();
		foreach ( (array) ( $meta['headers'] ?? array() ) as $line ) {
			if ( is_string( $line ) && self::is_replayable_header( $line ) ) {
				$replay[] = $line;
			}
		}

		return array(
			'status'  => 200,
			'headers' => $headers,
			'replay'  => array_slice( $replay, 0, 30 ),
		);
	}

	/**
	 * Whether a conditional request can be answered with 304 Not Modified.
	 *
	 * @param array<string,mixed> $server        $_SERVER-like array.
	 * @param string              $etag          Current ETag.
	 * @param int                 $last_modified Last modification time.
	 */
	public static function not_modified( array $server, string $etag, int $last_modified ): bool {
		$if_none_match = trim( (string) ( $server['HTTP_IF_NONE_MATCH'] ?? '' ) );
		if ( '' !== $if_none_match ) {
			$current = preg_replace( '#^W/#i', '', $etag );
			foreach ( explode( ',', $if_none_match ) as $tag ) {
				$tag = trim( $tag );
				if ( '*' === $tag || preg_replace( '#^W/#i', '', $tag ) === $current ) {
					return true;
				}
			}
			return false;
		}

		$if_modified_since = trim( (string) ( $server['HTTP_IF_MODIFIED_SINCE'] ?? '' ) );
		if ( '' !== $if_modified_since ) {
			$time = strtotime( $if_modified_since );
			return false !== $time && $time >= $last_modified;
		}

		return false;
	}

	/**
	 * Whether the client accepts gzip.
	 *
	 * @param array<string,mixed> $server $_SERVER-like array.
	 */
	public static function accepts_gzip( array $server ): bool {
		$accept = strtolower( (string) ( $server['HTTP_ACCEPT_ENCODING'] ?? '' ) );
		if ( false === strpos( $accept, 'gzip' ) ) {
			return false;
		}
		return ! preg_match( '/gzip\s*;\s*q\s*=\s*0(?:\.0{0,3})?\s*(?:,|$)/', $accept );
	}

	/**
	 * Send a cache entry and end the request.
	 *
	 * @param array<string,mixed> $entry    Entry (meta, body, path).
	 * @param array<string,mixed> $decision Decision.
	 * @param array<string,mixed> $server   $_SERVER.
	 * @param string              $mode     dropin|fallback.
	 */
	private static function send( array $entry, array $decision, array $server, string $mode ): void {
		$meta    = $entry['meta'];
		$body    = $entry['body'];
		$zlib    = self::ini_enabled( 'zlib.output_compression' );
		$gz_size = (int) ( $meta['gz'] ?? 0 );
		$gzip    = false;

		if ( $gz_size > 0 && ! $zlib && ! empty( $decision['site']['gzip'] ) && self::accepts_gzip( $server ) ) {
			$compressed = @file_get_contents( $entry['path'] . '.gz' );
			if ( is_string( $compressed ) && strlen( $compressed ) === $gz_size ) {
				$body = $compressed;
				$gzip = true;
			}
		}

		$send_length = 'dropin' === $mode && ! $zlib && '' === (string) ini_get( 'output_handler' );
		$response    = self::response( $meta, $decision, $server, strlen( $body ), $gzip, $send_length );

		http_response_code( $response['status'] );
		foreach ( $response['headers'] as $line ) {
			header( $line, true );
		}
		foreach ( $response['replay'] as $line ) {
			header( $line, false );
		}

		if ( 304 === $response['status'] || 'HEAD' === strtoupper( (string) ( $server['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			return;
		}

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Cached HTML page generated by WordPress.
	}

	/**
	 * Whether a boolean-ish ini setting is on (numeric buffer sizes count as on).
	 *
	 * @param string $name Setting.
	 */
	private static function ini_enabled( string $name ): bool {
		$value = strtolower( trim( (string) ini_get( $name ) ) );
		if ( in_array( $value, array( 'on', 'yes', 'true' ), true ) ) {
			return true;
		}
		return is_numeric( $value ) && (int) $value > 0;
	}

	/**
	 * Send a header when still possible.
	 *
	 * @param string $line Header line.
	 */
	private static function send_header( string $line ): void {
		if ( ! headers_sent() ) {
			header( $line, true );
		}
	}

	// ---------------------------------------------------------------------
	// Statistics.
	// ---------------------------------------------------------------------

	/**
	 * Record one in SAMPLE_RATE decisions.
	 *
	 * @param string $root     Cache root.
	 * @param string $site_key Site key.
	 * @param string $type     hit|miss|bypass.
	 */
	private static function sample( string $root, string $site_key, string $type ): void {
		try {
			if ( '' === $site_key || 1 !== random_int( 1, self::SAMPLE_RATE ) ) {
				return;
			}
		} catch ( \Throwable $e ) {
			return;
		}
		self::record( $root, $site_key, $type );
	}

	/**
	 * Increment a daily counter (UTC day) under an exclusive lock.
	 *
	 * The stats directory is created by WordPress (protected); the delivery never creates directories.
	 *
	 * @param string   $root     Cache root (trailing slash).
	 * @param string   $site_key Site key.
	 * @param string   $type     hit|miss|bypass.
	 * @param int|null $time     Timestamp (tests).
	 */
	public static function record( string $root, string $site_key, string $type, ?int $time = null ): bool {
		if ( ! in_array( $type, array( 'hit', 'miss', 'bypass' ), true ) || ! preg_match( '/^[a-z0-9._+~-]+$/', $site_key ) ) {
			return false;
		}
		$dir = $root . 'stats/';
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$file   = $dir . $site_key . '-' . gmdate( 'Y-m-d', $time ?? time() ) . '.json';
		$handle = @fopen( $file, 'c+' );
		if ( false === $handle ) {
			return false;
		}

		$ok = false;
		if ( @flock( $handle, LOCK_EX ) ) {
			$raw           = stream_get_contents( $handle );
			$data          = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : array();
			$data          = is_array( $data ) ? $data : array();
			$data[ $type ] = (int) ( $data[ $type ] ?? 0 ) + 1;
			$encoded       = (string) json_encode( $data );
			$ok            = ftruncate( $handle, 0 ) && rewind( $handle ) && false !== fwrite( $handle, $encoded );
			fflush( $handle );
			flock( $handle, LOCK_UN );
		}
		fclose( $handle );

		return $ok;
	}
}
