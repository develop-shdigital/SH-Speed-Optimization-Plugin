<?php
/**
 * Cache drop-ins (advanced-cache.php, object-cache.php) and the WP_CACHE constant.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Detection;

defined( 'ABSPATH' ) || exit;

/**
 * Cache layer detection.
 */
final class CacheDetector {

	/**
	 * Owner slug => [ display name, lowercase content signatures ]. Checked in order.
	 */
	private const ADVANCED_CACHE_OWNERS = array(
		'wp-rocket'                => array( 'WP Rocket', array( 'wp rocket', 'wp_rocket' ) ),
		'w3-total-cache'           => array( 'W3 Total Cache', array( 'w3 total cache', 'w3tc' ) ),
		'wp-super-cache'           => array( 'WP Super Cache', array( 'wp super cache', 'wp-cache-phase1', 'wpcachehome' ) ),
		'flying-press'             => array( 'FlyingPress', array( 'flyingpress', 'flying-press', 'flying_press' ) ),
		'nitropack'                => array( 'NitroPack', array( 'nitropack' ) ),
		'wp-optimize'              => array( 'WP-Optimize', array( 'wp-optimize', 'wpo_cache', 'wpo-cache' ) ),
		'wp-fastest-cache'         => array( 'WP Fastest Cache', array( 'wp fastest cache', 'wpfastestcache' ) ),
		'cache-enabler'            => array( 'Cache Enabler', array( 'cache enabler', 'cache_enabler' ) ),
		'comet-cache'              => array( 'Comet Cache', array( 'comet cache', 'comet_cache' ) ),
		'hummingbird-performance'  => array( 'Hummingbird', array( 'hummingbird', 'wphb' ) ),
		'breeze'                   => array( 'Breeze', array( 'breeze' ) ),
		'swift-performance'        => array( 'Swift Performance', array( 'swift performance', 'swift_performance', 'swift-performance' ) ),
		'powered-cache'            => array( 'Powered Cache', array( 'powered cache', 'powered_cache' ) ),
		'wp-cloudflare-page-cache' => array( 'Super Page Cache', array( 'swcfpc', 'super page cache' ) ),
		'jetpack-boost'            => array( 'Jetpack Boost', array( 'jetpack boost', 'jetpack-boost', 'boost_cache' ) ),
		'tenweb-speed-optimizer'   => array( '10Web Booster', array( 'tenweb', '10web' ) ),
		'sg-cachepress'            => array( 'SiteGround Optimizer', array( 'siteground', 'sg-cachepress', 'sg_cachepress' ) ),
		'litespeed-cache'          => array( 'LiteSpeed Cache', array( 'litespeed' ) ),
		'endurance-page-cache'     => array( 'Endurance Page Cache', array( 'endurance' ) ),
		'batcache'                 => array( 'Batcache', array( 'batcache' ) ),
	);

	/**
	 * Object cache drop-in names by signature (checked in order).
	 */
	private const OBJECT_CACHE_SIGNATURES = array(
		'object cache pro'    => 'Object Cache Pro',
		'rediscachepro'       => 'Object Cache Pro',
		'redis object cache'  => 'Redis Object Cache',
		'wp_redis_version'    => 'Redis Object Cache',
		'w3 total cache'      => 'W3 Total Cache',
		'litespeed'           => 'LiteSpeed Cache',
		'docket cache'        => 'Docket Cache',
		'sqlite object cache' => 'SQLite Object Cache',
		'wp redis'            => 'WP Redis',
		'apcu'                => 'APCu',
		'memcached'           => 'Memcached',
		'memcache'            => 'Memcache',
		'redis'               => 'Redis',
	);

	/**
	 * Markers identifying this plugin's own advanced-cache.php.
	 */
	private const OWN_MARKERS = array( 'sh speed optimizer', 'shso_' );

	/**
	 * Identify the owner of an advanced-cache.php drop-in from its content.
	 *
	 * @param string $content File content (the first few kilobytes are enough).
	 * @return array{ours:bool,owner:?string,owner_name:?string}
	 */
	public static function advanced_cache_owner( string $content ): array {
		$lower = strtolower( $content );

		foreach ( self::OWN_MARKERS as $marker ) {
			if ( false !== strpos( $lower, $marker ) ) {
				return array(
					'ours'       => true,
					'owner'      => 'sh-speed-optimizer',
					'owner_name' => 'SH Speed Optimizer',
				);
			}
		}

		foreach ( self::ADVANCED_CACHE_OWNERS as $slug => $definition ) {
			foreach ( $definition[1] as $signature ) {
				if ( false !== strpos( $lower, $signature ) ) {
					return array(
						'ours'       => false,
						'owner'      => $slug,
						'owner_name' => $definition[0],
					);
				}
			}
		}

		return array(
			'ours'       => false,
			'owner'      => null,
			'owner_name' => null,
		);
	}

	/**
	 * Name of an object-cache.php drop-in from its content (header first, then signatures).
	 *
	 * @param string $content File content.
	 */
	public static function object_cache_name( string $content ): ?string {
		if ( preg_match( '/^[ \t\/*#@]*Plugin Name:\s*(.+)$/mi', $content, $m ) ) {
			$name = trim( preg_replace( '/\s*\*\/\s*$/', '', $m[1] ) );
			if ( '' !== $name ) {
				return substr( $name, 0, 100 );
			}
		}

		$lower = strtolower( $content );
		foreach ( self::OBJECT_CACHE_SIGNATURES as $signature => $name ) {
			if ( false !== strpos( $lower, $signature ) ) {
				return $name;
			}
		}

		return '' === trim( $content ) ? null : __( 'Unknown object cache', 'sh-speed-optimizer' );
	}

	/**
	 * Collect the cache section.
	 *
	 * @return array<string,mixed>
	 */
	public static function collect(): array {
		$dir = defined( 'WP_CONTENT_DIR' ) ? rtrim( (string) WP_CONTENT_DIR, '/\\' ) : '';

		$advanced_file = $dir . '/advanced-cache.php';
		$advanced      = array(
			'exists'     => '' !== $dir && is_file( $advanced_file ),
			'ours'       => false,
			'owner'      => null,
			'owner_name' => null,
		);
		if ( $advanced['exists'] ) {
			$advanced = array_merge( $advanced, self::advanced_cache_owner( self::read_head( $advanced_file, 65536 ) ) );
		}

		$object_file = $dir . '/object-cache.php';
		$object      = array(
			'exists' => '' !== $dir && is_file( $object_file ),
			'name'   => null,
			'active' => function_exists( 'wp_using_ext_object_cache' ) && (bool) wp_using_ext_object_cache(),
		);
		if ( $object['exists'] ) {
			$object['name'] = self::object_cache_name( self::read_head( $object_file, 65536 ) );
		}

		return array(
			'advanced_cache'      => $advanced,
			'object_cache_dropin' => $object,
			'wp_cache_constant'   => defined( 'WP_CACHE' ) && WP_CACHE,
		);
	}

	/**
	 * Read the beginning of a local file.
	 *
	 * @param string $file  File.
	 * @param int    $bytes Maximum bytes.
	 */
	private static function read_head( string $file, int $bytes ): string {
		if ( ! is_readable( $file ) ) {
			return '';
		}
		$content = @file_get_contents( $file, false, null, 0, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Local drop-in file.
		return is_string( $content ) ? $content : '';
	}
}
