<?php
/**
 * Loopback-based detection: reachability, response time, CDN, server cache,
 * compression and browser caching of static files.
 *
 * Every step is failure tolerant: a timeout or error marks the value as
 * unknown (null / "unknown") and never throws.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Detection;

defined( 'ABSPATH' ) || exit;

/**
 * Remote detection (the HTTP client is injected so the logic is testable).
 */
final class RemoteDetector {

	/**
	 * Run the remote checks.
	 *
	 * Options: ours (bool) — this plugin's own advanced-cache.php is installed; generic
	 * cache signals (X-Cache/Age) are then not attributed to the host. now (int) — time.
	 *
	 * @param callable            $fetch      function( string $url, array $args ): array (Loopback::get() shape).
	 * @param string              $home       Home URL.
	 * @param string[]            $asset_urls Static files to check for browser caching (first = primary).
	 * @param array<string,mixed> $options    Options.
	 * @return array{loopback:array<string,mixed>,cdn:?string,cdn_name:?string,server_cache:?string,server_cache_name:?string,server_cache_hit:bool,edge_cache_hit:bool,page_cache:bool,page_cache_by:?string,compression:string,browser_cache:array<string,mixed>}
	 */
	public static function run( callable $fetch, string $home, array $asset_urls, array $options = array() ): array {
		$now  = (int) ( $options['now'] ?? time() );
		$ours = ! empty( $options['ours'] );

		$out = array(
			'loopback'          => array(
				'ok'      => false,
				'status'  => 0,
				'ttfb_ms' => null,
				'time_ms' => null,
				'error'   => '',
				'headers' => array(),
				'url'     => $home,
			),
			'cdn'               => null,
			'cdn_name'          => null,
			'server_cache'      => null,
			'server_cache_name' => null,
			'server_cache_hit'  => false,
			'edge_cache_hit'    => false,
			'page_cache'        => false,
			'page_cache_by'     => null,
			'compression'       => 'unknown',
			'browser_cache'     => array(
				'checked_url'   => null,
				'cache_control' => null,
				'expires'       => null,
				'etag'          => null,
				'max_age'       => null,
				'configured'    => null,
				'checked'       => array(),
			),
		);

		$page_args = array(
			'headers'   => array(
				'Accept-Encoding' => 'gzip, br',
				'Cache-Control'   => '',
			),
			'timeout'   => 20,
			'max_bytes' => 512 * 1024,
		);

		$first = self::fetch( $fetch, $home, $page_args );

		$out['loopback'] = array(
			'ok'      => $first['ok'] && $first['status'] >= 200 && $first['status'] < 400,
			'status'  => $first['status'],
			'ttfb_ms' => $first['status'] > 0 ? $first['ttfb_ms'] : null,
			'time_ms' => $first['status'] > 0 ? $first['time_ms'] : null,
			'error'   => '' !== $first['error'] ? $first['error'] : ( $first['status'] >= 400 ? sprintf( 'HTTP %d', $first['status'] ) : '' ),
			'headers' => CdnDetector::subset( $first['headers'] ),
			'url'     => $home,
		);

		if ( $first['status'] > 0 ) {
			$out['compression'] = CdnDetector::compression( $first['headers'] );
			$out['cdn']         = CdnDetector::cdn( $first['headers'] );
		}

		if ( $out['loopback']['ok'] ) {
			$second = self::fetch( $fetch, $home, $page_args );
			$second = $second['status'] >= 200 && $second['status'] < 400 ? $second : array( 'headers' => array() ) + $second;

			$cache = CdnDetector::server_cache( $first['headers'], $second['headers'] );
			if ( null !== $cache['type'] && ! ( $ours && $cache['generic_only'] ) ) {
				$out['server_cache']      = $cache['type'];
				$out['server_cache_name'] = CdnDetector::SERVER_CACHE_NAMES[ $cache['type'] ] ?? null;
				$out['server_cache_hit']  = $cache['hit'];
			}

			$out['edge_cache_hit'] = ! empty( $second['headers'] ) && CdnDetector::edge_cache_hit( $second['headers'] );
			if ( null === $out['cdn'] && ! empty( $second['headers'] ) ) {
				$out['cdn'] = CdnDetector::cdn( $second['headers'] );
			}

			if ( $out['server_cache_hit'] ) {
				$out['page_cache']    = true;
				$out['page_cache_by'] = $out['server_cache_name'];
			} elseif ( $out['edge_cache_hit'] ) {
				$out['page_cache']    = true;
				$out['page_cache_by'] = CdnDetector::CDN_NAMES[ (string) $out['cdn'] ] ?? null;
			}
		}

		$out['cdn_name'] = null !== $out['cdn'] ? ( CdnDetector::CDN_NAMES[ $out['cdn'] ] ?? null ) : null;

		$out['browser_cache'] = self::browser_cache( $fetch, $asset_urls, $now );

		return $out;
	}

	/**
	 * Check browser caching of static files.
	 *
	 * @param callable $fetch      Fetcher.
	 * @param string[] $asset_urls URLs.
	 * @param int      $now        Time.
	 * @return array<string,mixed>
	 */
	private static function browser_cache( callable $fetch, array $asset_urls, int $now ): array {
		$result = array(
			'checked_url'   => null,
			'cache_control' => null,
			'expires'       => null,
			'etag'          => null,
			'max_age'       => null,
			'configured'    => null,
			'checked'       => array(),
		);

		$all_configured = null;

		foreach ( array_values( $asset_urls ) as $index => $url ) {
			$url = (string) $url;
			if ( '' === $url ) {
				continue;
			}

			$response = self::fetch(
				$fetch,
				$url,
				array(
					'method'  => 'HEAD',
					'timeout' => 10,
				)
			);
			if ( ! $response['ok'] || $response['status'] >= 400 ) {
				if ( 0 === $response['status'] && 0 === $index && '' !== $response['error'] ) {
					// The server is unreachable; further checks would only time out as well.
					$result['error'] = $response['error'];
					break;
				}
				// Some servers refuse HEAD; retry with a small GET.
				$response = self::fetch(
					$fetch,
					$url,
					array(
						'timeout'   => 10,
						'max_bytes' => 65536,
					)
				);
			}

			if ( ! $response['ok'] || $response['status'] < 200 || $response['status'] >= 400 ) {
				$result['checked'][ $url ] = array(
					'status' => $response['status'],
					'error'  => '' !== $response['error'] ? $response['error'] : sprintf( 'HTTP %d', $response['status'] ),
				);
				continue;
			}

			$parsed                    = BrowserCacheDetector::parse( $response['headers'], $now );
			$result['checked'][ $url ] = array_merge( array( 'status' => $response['status'] ), $parsed );

			if ( null === $result['checked_url'] ) {
				$result['checked_url']   = $url;
				$result['cache_control'] = $parsed['cache_control'];
				$result['expires']       = $parsed['expires'];
				$result['etag']          = $parsed['etag'];
				$result['max_age']       = $parsed['max_age'];
			}

			$all_configured = ( null === $all_configured ? true : $all_configured ) && $parsed['configured'];
		}

		$result['configured'] = $all_configured;
		return $result;
	}

	/**
	 * Call the fetcher and normalize its result; never throws.
	 *
	 * @param callable            $fetch Fetcher.
	 * @param string              $url   URL.
	 * @param array<string,mixed> $args  Args.
	 * @return array{ok:bool,status:int,headers:array<string,string>,ttfb_ms:int,time_ms:int,error:string}
	 */
	private static function fetch( callable $fetch, string $url, array $args ): array {
		try {
			$response = $fetch( $url, $args );
		} catch ( \Throwable $e ) {
			$response = array( 'error' => $e->getMessage() );
		}
		$response = is_array( $response ) ? $response : array();

		$headers = array();
		foreach ( (array) ( $response['headers'] ?? array() ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
		}

		return array(
			'ok'      => ! empty( $response['ok'] ),
			'status'  => (int) ( $response['status'] ?? 0 ),
			'headers' => $headers,
			'ttfb_ms' => (int) ( $response['ttfb_ms'] ?? 0 ),
			'time_ms' => (int) ( $response['time_ms'] ?? 0 ),
			'error'   => substr( (string) ( $response['error'] ?? '' ), 0, 300 ),
		);
	}
}
