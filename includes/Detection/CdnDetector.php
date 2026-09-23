<?php
/**
 * CDN, server-side cache and compression detection from HTTP response headers.
 *
 * Pure functions: callers pass lowercase-keyed header arrays (as returned by
 * Diagnostics\Loopback). Headers of this plugin (x-shso-*) are ignored so the
 * plugin's own page cache is never mistaken for a hosting cache.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Detection;

defined( 'ABSPATH' ) || exit;

/**
 * Header analysis.
 */
final class CdnDetector {

	/**
	 * CDN display names.
	 */
	public const CDN_NAMES = array(
		'cloudflare' => 'Cloudflare',
		'quic_cloud' => 'QUIC.cloud',
		'bunny'      => 'Bunny CDN',
		'cloudfront' => 'Amazon CloudFront',
		'fastly'     => 'Fastly',
		'akamai'     => 'Akamai',
		'sucuri'     => 'Sucuri',
		'keycdn'     => 'KeyCDN',
		'stackpath'  => 'StackPath',
		'azure'      => 'Azure CDN',
		'generic'    => 'CDN',
	);

	/**
	 * Server cache display names.
	 */
	public const SERVER_CACHE_NAMES = array(
		'litespeed' => 'LiteSpeed Cache',
		'varnish'   => 'Varnish',
		'nginx'     => 'Nginx cache',
		'generic'   => 'Server cache',
	);

	/**
	 * Normalize headers: lowercase names, string values, own headers removed.
	 *
	 * @param array<string,mixed> $headers Headers.
	 * @return array<string,string>
	 */
	public static function normalize( array $headers ): array {
		$out = array();
		foreach ( $headers as $name => $value ) {
			$name = strtolower( trim( (string) $name ) );
			if ( '' === $name || 0 === strpos( $name, 'x-shso' ) ) {
				continue;
			}
			$out[ $name ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : trim( (string) $value );
		}
		return $out;
	}

	/**
	 * Detect the CDN in front of the site.
	 *
	 * @param array<string,mixed> $headers Response headers.
	 */
	public static function cdn( array $headers ): ?string {
		$h      = self::normalize( $headers );
		$server = strtolower( $h['server'] ?? '' );
		$via    = strtolower( $h['via'] ?? '' );

		if ( isset( $h['cf-ray'] ) || isset( $h['cf-cache-status'] ) || false !== strpos( $server, 'cloudflare' ) ) {
			return 'cloudflare';
		}
		if ( isset( $h['x-qc-pop'] ) || isset( $h['x-qc-cache'] ) ) {
			return 'quic_cloud';
		}
		if ( isset( $h['cdn-pullzone'] ) || isset( $h['cdn-requestid'] ) || false !== strpos( $server, 'bunnycdn' ) ) {
			return 'bunny';
		}
		if ( isset( $h['x-amz-cf-id'] ) || isset( $h['x-amz-cf-pop'] ) || false !== strpos( $via, 'cloudfront' ) ) {
			return 'cloudfront';
		}
		if ( isset( $h['x-sucuri-id'] ) || isset( $h['x-sucuri-cache'] ) || false !== strpos( $server, 'sucuri' ) ) {
			return 'sucuri';
		}
		if ( self::has_prefix( $h, 'x-akamai' ) || isset( $h['akamai-grn'] ) || false !== strpos( $server, 'akamaighost' ) ) {
			return 'akamai';
		}
		if ( self::has_prefix( $h, 'fastly-' ) || isset( $h['x-fastly-request-id'] )
			|| ( isset( $h['x-served-by'], $h['x-cache'] ) && preg_match( '/\bcache-[a-z0-9]+-[a-z]{3}\b/i', $h['x-served-by'] ) ) ) {
			return 'fastly';
		}
		if ( false !== strpos( $server, 'keycdn' ) ) {
			return 'keycdn';
		}
		if ( isset( $h['x-hw'] ) ) {
			return 'stackpath';
		}
		if ( isset( $h['x-azure-ref'] ) || isset( $h['x-msedge-ref'] ) ) {
			return 'azure';
		}
		if ( isset( $h['x-cdn'] ) || isset( $h['x-edge-location'] ) || preg_match( '/\bcdn\b/', $via ) ) {
			return 'generic';
		}
		return null;
	}

	/**
	 * Detect a server-side page cache from one or two consecutive responses to the same URL.
	 *
	 * @param array<string,mixed> $first  First response headers.
	 * @param array<string,mixed> $second Second response headers (may be empty).
	 * @return array{type:?string,hit:bool,generic_only:bool}
	 */
	public static function server_cache( array $first, array $second = array() ): array {
		$type = null;
		$hit  = false;

		foreach ( array( self::normalize( $first ), self::normalize( $second ) ) as $h ) {
			if ( empty( $h ) ) {
				continue;
			}
			$via     = strtolower( $h['via'] ?? '' );
			$x_cache = strtolower( $h['x-cache'] ?? '' );

			$detected = null;
			if ( isset( $h['x-litespeed-cache'] ) || isset( $h['x-lsadc-cache'] ) ) {
				$detected = 'litespeed';
				$hit      = $hit || false !== strpos( strtolower( $h['x-litespeed-cache'] ?? ( $h['x-lsadc-cache'] ?? '' ) ), 'hit' );
			} elseif ( isset( $h['x-varnish'] ) || false !== strpos( $via, 'varnish' ) || false !== strpos( $x_cache, 'varnish' ) ) {
				$detected = 'varnish';
				// "x-varnish: 123 456" (two ids) means the object came from cache.
				$hit = $hit || ( isset( $h['x-varnish'] ) && preg_match( '/^\s*\d+\s+\d+\s*$/', $h['x-varnish'] ) ) || false !== strpos( $x_cache, 'hit' );
			} elseif ( isset( $h['x-kinsta-cache'] ) ) {
				$detected = 'nginx';
				$hit      = $hit || false !== strpos( strtolower( $h['x-kinsta-cache'] ), 'hit' );
			} elseif ( isset( $h['x-fastcgi-cache'] ) || isset( $h['x-nginx-cache'] ) || isset( $h['x-proxy-cache'] ) || isset( $h['x-sg-cache'] ) ) {
				$detected = 'nginx';
				$value    = strtolower( $h['x-fastcgi-cache'] ?? ( $h['x-nginx-cache'] ?? ( $h['x-proxy-cache'] ?? ( $h['x-sg-cache'] ?? '' ) ) ) );
				$hit      = $hit || false !== strpos( $value, 'hit' );
			} elseif ( self::has_prefix( $h, 'x-wpe-' ) || isset( $h['wpe-backend'] ) || isset( $h['x-pass-why'] ) ) {
				$detected = 'varnish'; // WP Engine's page cache.
				$hit      = $hit || false !== strpos( $x_cache, 'hit' );
			} elseif ( isset( $h['x-pantheon-styx-hostname'] ) || isset( $h['x-styx-req-id'] ) ) {
				$detected = 'varnish'; // Pantheon's edge cache.
				$hit      = $hit || false !== strpos( $x_cache, 'hit' );
			}

			if ( null !== $detected && null === $type ) {
				$type = $detected;
			}
		}

		$generic_only = false;
		if ( null === $type ) {
			$last = self::normalize( empty( $second ) ? $first : $second );
			$cdn  = self::cdn( $last );
			$flag = strtolower( ( $last['x-cache-status'] ?? '' ) . ' ' . ( $last['x-cache-enabled'] ?? '' ) );
			// CloudFront, Fastly and Akamai report their own edge hits in x-cache.
			if ( ! in_array( $cdn, array( 'cloudfront', 'fastly', 'akamai' ), true ) ) {
				$flag .= ' ' . strtolower( $last['x-cache'] ?? '' );
			}
			if ( preg_match( '/\bhit\b/', $flag ) || ( ! empty( $second ) && (int) ( $last['age'] ?? 0 ) > 0 && null === $cdn ) ) {
				$type         = 'generic';
				$hit          = true;
				$generic_only = true;
			}
		}

		return array(
			'type'         => $type,
			'hit'          => $hit,
			'generic_only' => $generic_only,
		);
	}

	/**
	 * Whether the CDN served the HTML page from its cache (edge page caching, e.g. Cloudflare APO).
	 *
	 * @param array<string,mixed> $headers Response headers (second request).
	 */
	public static function edge_cache_hit( array $headers ): bool {
		$h = self::normalize( $headers );
		foreach ( array( 'cf-cache-status', 'x-qc-cache', 'cdn-cache', 'x-sucuri-cache' ) as $name ) {
			if ( isset( $h[ $name ] ) && preg_match( '/\bhit\b/i', $h[ $name ] ) ) {
				return true;
			}
		}
		$cdn = self::cdn( $h );
		if ( in_array( $cdn, array( 'cloudfront', 'fastly', 'akamai' ), true ) && preg_match( '/\bhit\b/i', $h['x-cache'] ?? '' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Response compression from a response to a request that sent "Accept-Encoding: gzip, br".
	 *
	 * @param array<string,mixed> $headers Response headers.
	 * @return string br|gzip|zstd|deflate|none
	 */
	public static function compression( array $headers ): string {
		$h        = self::normalize( $headers );
		$encoding = strtolower( $h['content-encoding'] ?? '' );
		if ( false !== strpos( $encoding, 'br' ) ) {
			return 'br';
		}
		if ( false !== strpos( $encoding, 'zstd' ) ) {
			return 'zstd';
		}
		if ( false !== strpos( $encoding, 'gzip' ) ) {
			return 'gzip';
		}
		if ( false !== strpos( $encoding, 'deflate' ) ) {
			return 'deflate';
		}
		return 'none';
	}

	/**
	 * Headers worth keeping in the site profile (no cookies or other personal data).
	 *
	 * @param array<string,mixed> $headers Headers.
	 * @return array<string,string>
	 */
	public static function subset( array $headers ): array {
		$keep = array( 'server', 'via', 'age', 'vary', 'content-type', 'content-encoding', 'cache-control', 'expires', 'x-powered-by', 'alt-svc', 'x-served-by', 'x-varnish', 'cf-ray', 'x-amz-cf-pop' );
		$out  = array();
		foreach ( self::normalize( $headers ) as $name => $value ) {
			if ( in_array( $name, $keep, true ) || false !== strpos( $name, 'cache' ) ) {
				$out[ $name ] = substr( $value, 0, 200 );
			}
		}
		return $out;
	}

	/**
	 * Whether any header name starts with a prefix.
	 *
	 * @param array<string,string> $headers Normalized headers.
	 * @param string               $prefix  Prefix.
	 */
	private static function has_prefix( array $headers, string $prefix ): bool {
		foreach ( array_keys( $headers ) as $name ) {
			if ( 0 === strpos( (string) $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
