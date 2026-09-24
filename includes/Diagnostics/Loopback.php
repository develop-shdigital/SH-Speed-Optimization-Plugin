<?php
/**
 * Requests to the site itself (scans and verification).
 *
 * Uses cURL directly when available to measure time-to-first-byte precisely
 * and to bypass proxies for loopback traffic; falls back to the WordPress
 * HTTP API otherwise.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Diagnostics;

use SH\SpeedOptimizer\Core\Context;
use SH\SpeedOptimizer\Security\Signer;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Uses the core filter https_local_ssl_verify like WordPress' own loopbacks.

/**
 * Loopback HTTP client.
 */
final class Loopback {

	public const USER_AGENT = 'SH Speed Optimizer/1.0 (+loopback; WordPress)';

	/**
	 * Fetch a URL of this site.
	 *
	 * Args:
	 *  - token (array|null): verification payload to sign and append as ?shso_verify=… (m, o, a, p, j).
	 *  - cookies (array<string,string>): cookies to send.
	 *  - method (GET|HEAD).
	 *  - timeout (int seconds, default 30).
	 *  - headers (array<string,string>).
	 *  - follow (bool, default true): follow up to 3 redirects on the same host.
	 *  - max_bytes (int, default 3 MB): truncate larger bodies.
	 *
	 * @param string              $url  Absolute URL on this site.
	 * @param array<string,mixed> $args Arguments.
	 * @return array{ok:bool,status:int,headers:array<string,string>,body:string,time_ms:int,ttfb_ms:int,error:string,url:string,token_nonce:string}
	 */
	public static function get( string $url, array $args = array() ): array {
		$args = array_merge(
			array(
				'token'     => null,
				'cookies'   => array(),
				'method'    => 'GET',
				'timeout'   => 30,
				'headers'   => array(),
				'follow'    => true,
				'max_bytes' => 3 * 1024 * 1024,
			),
			$args
		);

		$result = array(
			'ok'          => false,
			'status'      => 0,
			'headers'     => array(),
			'body'        => '',
			'time_ms'     => 0,
			'ttfb_ms'     => 0,
			'error'       => '',
			'url'         => $url,
			'token_nonce' => '',
		);

		if ( ! self::is_own_url( $url ) ) {
			$result['error'] = 'Refusing to request a URL outside this site.';
			return $result;
		}

		if ( is_array( $args['token'] ) ) {
			$token                 = Signer::sign( $args['token'], 900 );
			$payload               = Signer::verify( $token );
			$result['token_nonce'] = (string) ( $payload['n'] ?? '' );
			$url                   = add_query_arg( Context::VERIFY_PARAM, $token, $url );
		}

		// Unique query argument defeats intermediate caches for verification requests.
		if ( is_array( $args['token'] ) ) {
			$url = add_query_arg( 'shso_nc', wp_generate_password( 6, false ), $url );
		}

		$headers = array_merge(
			array(
				'Accept'          => 'text/html,application/xhtml+xml',
				'Accept-Encoding' => 'identity',
				'Cache-Control'   => 'no-cache',
			),
			(array) $args['headers']
		);

		$result['url']  = $url;
		$use_curl       = function_exists( 'curl_init' ) && apply_filters( 'shso_loopback_use_curl', true );
		$follow         = (bool) $args['follow'];
		$args['follow'] = false; // Redirects are followed here, so every hop is checked.

		for ( $hop = 0; ; $hop++ ) {
			$response = $use_curl ? self::curl( $url, $args, $headers, $result ) : self::wp_http( $url, $args, $headers, $result );
			$location = (string) ( $response['headers']['location'] ?? '' );
			if ( ! $follow || $hop >= 3 || $response['status'] < 300 || $response['status'] > 399 || '' === $location ) {
				return $response;
			}
			$next = self::resolve_redirect( $url, $location );
			if ( null === $next || ! self::is_own_url( $next ) ) {
				return $response; // Never follow a redirect away from this site.
			}
			$url           = $next;
			$result['url'] = $url;
			if ( 303 === $response['status'] ) {
				$args['method'] = 'GET';
			}
		}
	}

	/**
	 * Absolute URL of a redirect target (http/https only), or null.
	 *
	 * @param string $base     URL that was requested.
	 * @param string $location Location header.
	 */
	public static function resolve_redirect( string $base, string $location ): ?string {
		$location = trim( $location );
		if ( '' === $location || preg_match( '/[\x00-\x1F\x7F]/', $location ) ) {
			return null;
		}
		$parts = wp_parse_url( $base );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		if ( 0 === strpos( $location, '//' ) ) {
			$location = $parts['scheme'] . ':' . $location;
		} elseif ( 0 === strpos( $location, '/' ) ) {
			$location = $origin . $location;
		} elseif ( ! preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $location ) ) {
			$dir      = isset( $parts['path'] ) ? preg_replace( '#/[^/]*$#', '/', $parts['path'] ) : '/';
			$location = $origin . $dir . $location;
		}

		$scheme = strtolower( (string) wp_parse_url( $location, PHP_URL_SCHEME ) );
		return in_array( $scheme, array( 'http', 'https' ), true ) ? $location : null;
	}

	/**
	 * Build a URL of this site from a path.
	 *
	 * @param string $path Path.
	 */
	public static function url( string $path = '/' ): string {
		return home_url( $path );
	}

	/**
	 * Whether a URL belongs to this site (same host as home or site URL; on
	 * multisite also a path of this site, not of another site on the same host).
	 *
	 * @param string $url URL.
	 */
	public static function is_own_url( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}
		$hosts = array(
			strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
		);
		if ( ! in_array( $host, $hosts, true ) ) {
			return false;
		}
		if ( is_multisite() && function_exists( 'get_site_by_path' ) ) {
			$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
			$owner = get_site_by_path( $host, '' === $path ? '/' : $path );
			return is_object( $owner ) && (int) get_current_blog_id() === (int) $owner->blog_id;
		}
		return true;
	}

	/**
	 * Request via cURL.
	 *
	 * @param string               $url     URL.
	 * @param array<string,mixed>  $args    Args.
	 * @param array<string,string> $headers Headers.
	 * @param array<string,mixed>  $result  Result template.
	 * @return array<string,mixed>
	 */
	private static function curl( string $url, array $args, array $headers, array $result ): array {
		$response_headers = array();
		$body             = '';
		$max              = (int) $args['max_bytes'];

		$ch           = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
		$header_lines = array();
		foreach ( $headers as $name => $value ) {
			$header_lines[] = $name . ': ' . $value;
		}
		if ( ! empty( $args['cookies'] ) ) {
			$pairs = array();
			foreach ( (array) $args['cookies'] as $name => $value ) {
				$pairs[] = rawurlencode( (string) $name ) . '=' . rawurlencode( (string) $value );
			}
			$header_lines[] = 'Cookie: ' . implode( '; ', $pairs );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt_array,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_error,WordPress.WP.AlternativeFunctions.curl_curl_close
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_NOBODY         => 'HEAD' === $args['method'],
				CURLOPT_FOLLOWLOCATION => false, // Followed by get() after checking the target.
				CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
				CURLOPT_TIMEOUT        => (int) $args['timeout'],
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_USERAGENT      => self::USER_AGENT,
				CURLOPT_HTTPHEADER     => $header_lines,
				CURLOPT_PROXY          => '',
				CURLOPT_NOPROXY        => '*',
				// Same trade-off as WordPress core loopbacks: the target is this very site.
				CURLOPT_SSL_VERIFYPEER => (bool) apply_filters( 'https_local_ssl_verify', false ),
				CURLOPT_SSL_VERIFYHOST => apply_filters( 'https_local_ssl_verify', false ) ? 2 : 0,
				CURLOPT_HEADERFUNCTION => static function ( $handle, $line ) use ( &$response_headers ) {
					$trim = trim( $line );
					if ( 0 === stripos( $trim, 'HTTP/' ) ) {
						$response_headers = array(); // New response after a redirect.
					} elseif ( false !== strpos( $trim, ':' ) ) {
						list( $name, $value ) = explode( ':', $trim, 2 );
						$name                 = strtolower( trim( $name ) );
						$response_headers[ $name ] = isset( $response_headers[ $name ] ) ? $response_headers[ $name ] . ', ' . trim( $value ) : trim( $value );
					}
					return strlen( $line );
				},
				CURLOPT_WRITEFUNCTION  => static function ( $handle, $chunk ) use ( &$body, $max ) {
					if ( strlen( $body ) < $max ) {
						$body .= $chunk;
					}
					return strlen( $chunk );
				},
			)
		);

		$ok    = curl_exec( $ch );
		$info  = curl_getinfo( $ch );
		$error = curl_error( $ch );
		curl_close( $ch );
		// phpcs:enable

		$result['status']  = (int) ( $info['http_code'] ?? 0 );
		$result['headers'] = $response_headers;
		$result['body']    = $body;
		$result['time_ms'] = (int) round( 1000 * (float) ( $info['total_time'] ?? 0 ) );
		$result['ttfb_ms'] = (int) round( 1000 * ( (float) ( $info['starttransfer_time'] ?? 0 ) - (float) ( $info['pretransfer_time'] ?? 0 ) ) );
		$result['error']   = false === $ok ? $error : '';
		$result['ok']      = false !== $ok && $result['status'] > 0;

		return $result;
	}

	/**
	 * Request via the WordPress HTTP API.
	 *
	 * @param string               $url     URL.
	 * @param array<string,mixed>  $args    Args.
	 * @param array<string,string> $headers Headers.
	 * @param array<string,mixed>  $result  Result template.
	 * @return array<string,mixed>
	 */
	private static function wp_http( string $url, array $args, array $headers, array $result ): array {
		$cookies = array();
		foreach ( (array) $args['cookies'] as $name => $value ) {
			$cookies[] = new \WP_Http_Cookie(
				array(
					'name'  => (string) $name,
					'value' => (string) $value,
				)
			);
		}

		$start    = microtime( true );
		$response = wp_remote_request(
			$url,
			array(
				'method'              => $args['method'],
				'timeout'             => (int) $args['timeout'],
				'redirection'         => 0, // Followed by get() after checking the target.
				'reject_unsafe_urls'  => true,
				'user-agent'          => self::USER_AGENT,
				'headers'             => $headers,
				'cookies'             => $cookies,
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
				'limit_response_size' => (int) $args['max_bytes'],
			)
		);
		$elapsed  = (int) round( 1000 * ( microtime( true ) - $start ) );

		if ( is_wp_error( $response ) ) {
			$result['error']   = $response->get_error_message();
			$result['time_ms'] = $elapsed;
			return $result;
		}

		$headers_out = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers_out[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		$result['ok']      = true;
		$result['status']  = (int) wp_remote_retrieve_response_code( $response );
		$result['headers'] = $headers_out;
		$result['body']    = (string) wp_remote_retrieve_body( $response );
		$result['time_ms'] = $elapsed;
		$result['ttfb_ms'] = $elapsed; // Upper bound; the HTTP API does not expose TTFB.

		return $result;
	}
}
