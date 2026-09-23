<?php
/**
 * Page cache delivery configuration.
 *
 * One JSON file per host (cache_root/config/<host>.json) holds a `sites` map
 * of path prefix → site configuration, so a subdirectory multisite network
 * shares one drop-in while every site keeps its own rules. The builders here
 * are pure (no WordPress calls) so they can be unit tested; CacheManager
 * gathers the inputs from WordPress.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Configuration builder.
 */
final class Config {

	/**
	 * Config file format version.
	 */
	public const VERSION = 1;

	/**
	 * Marketing/tracking parameters that never change the page content.
	 * They are ignored for the cache key (the page is still served from the cache).
	 */
	public const TRACKING_PARAMS = array(
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_term',
		'utm_content',
		'utm_id',
		'utm_expid',
		'utm_source_platform',
		'utm_creative_format',
		'utm_marketing_tactic',
		'mtm_source',
		'mtm_medium',
		'mtm_campaign',
		'mtm_keyword',
		'mtm_cid',
		'mtm_content',
		'pk_source',
		'pk_medium',
		'pk_campaign',
		'pk_keyword',
		'pk_cid',
		'pk_content',
		'fbclid',
		'fb_action_ids',
		'fb_action_types',
		'fb_source',
		'gclid',
		'gclsrc',
		'gbraid',
		'wbraid',
		'dclid',
		'msclkid',
		'twclid',
		'ttclid',
		'li_fat_id',
		'yclid',
		'igshid',
		'srsltid',
		'campaignid',
		'adgroupid',
		'adid',
		'_ga',
		'_gl',
		'_ke',
		'_kx',
		'mc_cid',
		'mc_eid',
		'_hsenc',
		'_hsmi',
		'mkt_tok',
		'sscid',
		'_bta_tid',
		'_bta_c',
		'trk_contact',
		'trk_msg',
		'trk_module',
		'trk_sid',
		'mkwid',
		'pcrid',
		'ef_id',
		's_kwcid',
		'dm_i',
		'epik',
		'cn-reloaded',
		'usqp',
	);

	/**
	 * Query parameters that select content when plain permalinks are used (?p=123).
	 */
	public const PLAIN_PERMALINK_PARAMS = array( 'p', 'page_id', 'cat', 'tag', 'm', 'author', 'paged', 'cpage', 'post_type', 'name', 'pagename', 'year', 'monthnum', 'day', 'attachment_id', 'product', 'product_cat', 'product_tag' );

	/**
	 * Maximum entries per list.
	 */
	private const MAX_LIST = 300;

	/**
	 * Build a normalized site configuration.
	 *
	 * Input keys: enabled, safe_mode, lifespan (seconds), exclude_urls[], bypass_cookies[],
	 * vary_cookies[], safe_cookies[], ignore_query[], keep_query[], mobile, hosts[], gzip, rest_prefix.
	 *
	 * @param array<string,mixed> $in Raw input.
	 * @return array<string,mixed>
	 */
	public static function build_site( array $in ): array {
		$lifespan = (int) ( $in['lifespan'] ?? 10 * 3600 );

		return array(
			'enabled'        => ! empty( $in['enabled'] ),
			'safe_mode'      => ! empty( $in['safe_mode'] ),
			'lifespan'       => max( 60, min( 720 * 3600, $lifespan ) ),
			'exclude_urls'   => self::patterns( (array) ( $in['exclude_urls'] ?? array() ) ),
			'bypass_cookies' => self::cookies( (array) ( $in['bypass_cookies'] ?? array() ) ),
			'vary_cookies'   => self::cookies( (array) ( $in['vary_cookies'] ?? array() ), false ),
			'safe_cookies'   => self::cookies( (array) ( $in['safe_cookies'] ?? array() ) ),
			'ignore_query'   => self::params( (array) ( $in['ignore_query'] ?? array() ), true ),
			'keep_query'     => self::params( (array) ( $in['keep_query'] ?? array() ), false ),
			'mobile'         => ! empty( $in['mobile'] ),
			'hosts'          => self::hosts( (array) ( $in['hosts'] ?? array() ) ),
			'gzip'           => ! empty( $in['gzip'] ),
			'rest_prefix'    => self::rest_prefix( (string) ( $in['rest_prefix'] ?? 'wp-json' ) ),
		);
	}

	/**
	 * Put a site configuration into a host file structure.
	 *
	 * @param array<string,mixed> $file   Existing file data (may be empty).
	 * @param string              $prefix Site path prefix.
	 * @param array<string,mixed> $site   Site configuration.
	 * @param int                 $now    Timestamp.
	 * @return array<string,mixed>
	 */
	public static function merge_site( array $file, string $prefix, array $site, int $now ): array {
		$sites = isset( $file['sites'] ) && is_array( $file['sites'] ) ? $file['sites'] : array();

		$sites[ Delivery::normalize_prefix( $prefix ) ] = $site;
		uksort(
			$sites,
			static function ( $a, $b ) {
				$by_length = strlen( (string) $b ) <=> strlen( (string) $a );
				return 0 !== $by_length ? $by_length : strcmp( (string) $a, (string) $b );
			}
		);

		return array(
			'version'   => self::VERSION,
			'generated' => $now,
			'sites'     => $sites,
		);
	}

	/**
	 * Remove a site configuration from a host file structure.
	 *
	 * @param array<string,mixed> $file   File data.
	 * @param string              $prefix Site path prefix.
	 * @return array<string,mixed>
	 */
	public static function remove_site( array $file, string $prefix ): array {
		$sites = isset( $file['sites'] ) && is_array( $file['sites'] ) ? $file['sites'] : array();
		unset( $sites[ Delivery::normalize_prefix( $prefix ) ] );
		$file['sites'] = $sites;
		return $file;
	}

	/**
	 * Path prefixes of other sites nested below a prefix (their pages must survive a purge of the parent site).
	 *
	 * @param array<string,mixed> $file   File data.
	 * @param string              $prefix Site prefix.
	 * @return string[]
	 */
	public static function nested_prefixes( array $file, string $prefix ): array {
		$prefix = Delivery::normalize_prefix( $prefix );
		$nested = array();
		foreach ( array_keys( isset( $file['sites'] ) && is_array( $file['sites'] ) ? $file['sites'] : array() ) as $other ) {
			$other = Delivery::normalize_prefix( (string) $other );
			if ( $other !== $prefix && 0 === strpos( $other, $prefix ) ) {
				$nested[] = $other;
			}
		}
		return $nested;
	}

	/**
	 * Site path prefix from the home URL ("https://example.com/blog" → "/blog/").
	 *
	 * @param string $home_url Home URL.
	 */
	public static function prefix_from_url( string $home_url ): string {
		$path = (string) parse_url( $home_url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper, no WordPress dependency.
		return Delivery::normalize_prefix( strtolower( $path ) );
	}

	/**
	 * Allowed hosts (with port) from a list of URLs.
	 *
	 * @param string[] $urls URLs (home, site URL …).
	 * @return string[]
	 */
	public static function hosts_from_urls( array $urls ): array {
		$hosts = array();
		foreach ( $urls as $url ) {
			$parts = parse_url( (string) $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper.
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				continue;
			}
			$hosts[] = strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		}
		return self::hosts( $hosts );
	}

	/**
	 * Validated host list.
	 *
	 * @param array<int,mixed> $hosts Hosts.
	 * @return string[]
	 */
	public static function hosts( array $hosts ): array {
		$out = array();
		foreach ( $hosts as $host ) {
			$normalized = Delivery::normalize_host( (string) $host );
			if ( null !== $normalized && ! in_array( $normalized, $out, true ) ) {
				$out[] = $normalized;
			}
		}
		return $out;
	}

	/**
	 * URL patterns (see Context::url_matches()). The site root ("/") is never
	 * used as an exclusion because it would silently disable the whole cache.
	 *
	 * @param array<int,mixed> $patterns Patterns.
	 * @return string[]
	 */
	public static function patterns( array $patterns ): array {
		$out = array();
		foreach ( $patterns as $pattern ) {
			if ( ! is_scalar( $pattern ) ) {
				continue;
			}
			$pattern = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $pattern ) );
			if ( '' === $pattern || '/' === $pattern || '*' === $pattern || strlen( $pattern ) > 300 || in_array( $pattern, $out, true ) ) {
				continue;
			}
			$out[] = $pattern;
		}
		return array_slice( $out, 0, self::MAX_LIST );
	}

	/**
	 * Cookie names or prefixes.
	 *
	 * @param array<int,mixed> $cookies  Names.
	 * @param bool             $wildcard Whether "*" is allowed.
	 * @return string[]
	 */
	public static function cookies( array $cookies, bool $wildcard = true ): array {
		$out     = array();
		$pattern = $wildcard ? '/[^A-Za-z0-9_\-\.\*]/' : '/[^A-Za-z0-9_\-\.]/';
		foreach ( $cookies as $cookie ) {
			if ( ! is_scalar( $cookie ) ) {
				continue;
			}
			$cookie = (string) preg_replace( $pattern, '', trim( (string) $cookie ) );
			if ( '' === $cookie || '*' === $cookie || strlen( $cookie ) > 100 || in_array( $cookie, $out, true ) ) {
				continue;
			}
			$out[] = $cookie;
		}
		return array_slice( $out, 0, self::MAX_LIST );
	}

	/**
	 * Query parameter names.
	 *
	 * @param array<int,mixed> $params    Names.
	 * @param bool             $lowercase Lowercase them (ignored parameters are matched case-insensitively).
	 * @return string[]
	 */
	public static function params( array $params, bool $lowercase ): array {
		$out = array();
		foreach ( $params as $param ) {
			if ( ! is_scalar( $param ) ) {
				continue;
			}
			$param = trim( (string) $param );
			$param = $lowercase ? strtolower( $param ) : $param;
			if ( '' === $param || strlen( $param ) > 100 || preg_match( '/[^A-Za-z0-9_\-\.\[\]]/', $param ) || in_array( $param, $out, true ) ) {
				continue;
			}
			if ( in_array( strtolower( $param ), Delivery::FORBIDDEN_PARAMS, true ) ) {
				continue;
			}
			$out[] = $param;
		}
		return array_slice( $out, 0, self::MAX_LIST );
	}

	/**
	 * REST prefix (single or multiple path segments, no slashes around).
	 *
	 * @param string $prefix Prefix.
	 */
	public static function rest_prefix( string $prefix ): string {
		$prefix = trim( strtolower( $prefix ), '/' );
		$prefix = (string) preg_replace( '#[^a-z0-9_\-/]#', '', $prefix );
		return '' === $prefix ? 'wp-json' : $prefix;
	}
}
