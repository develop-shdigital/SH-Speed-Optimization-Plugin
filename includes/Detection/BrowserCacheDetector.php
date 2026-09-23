<?php
/**
 * Browser caching headers of static files.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Detection;

defined( 'ABSPATH' ) || exit;

/**
 * Parses Cache-Control / Expires / ETag of a static file response.
 */
final class BrowserCacheDetector {

	/**
	 * Minimum lifetime that counts as "browser caching configured".
	 */
	public const MIN_LIFETIME = 604800; // 7 days.

	/**
	 * Parse caching headers.
	 *
	 * max-age wins over Expires (as in HTTP); s-maxage only applies to shared caches and is
	 * ignored; no-store and no-cache mean the browser does not reuse the file without asking.
	 *
	 * @param array<string,mixed> $headers Response headers (any case).
	 * @param int                 $now     Current Unix time (used when the response has no Date header).
	 * @return array{cache_control:string,expires:string,etag:bool,last_modified:bool,max_age:?int,expires_in:?int,configured:bool}
	 */
	public static function parse( array $headers, int $now ): array {
		$h = array();
		foreach ( $headers as $name => $value ) {
			$h[ strtolower( trim( (string) $name ) ) ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : trim( (string) $value );
		}

		$cache_control = $h['cache-control'] ?? '';
		$directives    = self::directives( $cache_control );

		$max_age = null;
		if ( isset( $directives['max-age'] ) && preg_match( '/^\d+$/', $directives['max-age'] ) ) {
			$max_age = (int) $directives['max-age'];
		}

		$base = $now;
		if ( ! empty( $h['date'] ) ) {
			$date = strtotime( $h['date'] );
			if ( false !== $date ) {
				$base = $date;
			}
		}

		$expires_in = null;
		if ( isset( $h['expires'] ) ) {
			$expires    = strtotime( $h['expires'] );
			$expires_in = false === $expires ? 0 : $expires - $base; // Invalid dates ("0", "-1") mean "already expired".
		}

		$no_reuse = isset( $directives['no-store'] ) || isset( $directives['no-cache'] );

		if ( $no_reuse ) {
			$configured = false;
		} elseif ( null !== $max_age ) {
			$configured = $max_age >= self::MIN_LIFETIME;
		} else {
			$configured = null !== $expires_in && $expires_in >= self::MIN_LIFETIME;
		}

		return array(
			'cache_control' => substr( $cache_control, 0, 200 ),
			'expires'       => substr( $h['expires'] ?? '', 0, 100 ),
			'etag'          => isset( $h['etag'] ) && '' !== $h['etag'],
			'last_modified' => isset( $h['last-modified'] ) && '' !== $h['last-modified'],
			'max_age'       => $max_age,
			'expires_in'    => $expires_in,
			'configured'    => $configured,
		);
	}

	/**
	 * Cache-Control directives (lowercase name => value, '' for valueless directives).
	 *
	 * @param string $header Header value.
	 * @return array<string,string>
	 */
	public static function directives( string $header ): array {
		$out = array();
		foreach ( explode( ',', $header ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$pieces = explode( '=', $part, 2 );
			$name   = strtolower( trim( $pieces[0] ) );
			$value  = isset( $pieces[1] ) ? trim( $pieces[1], " \t\"" ) : '';
			if ( '' !== $name && ! isset( $out[ $name ] ) ) {
				$out[ $name ] = $value;
			}
		}
		return $out;
	}
}
