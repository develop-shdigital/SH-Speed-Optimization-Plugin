<?php
/**
 * Short-lived signed tokens.
 *
 * Used to let the plugin (loopback requests) and the administrator's browser
 * (verification iframes) request a page in a specific optimization mode
 * without exposing that ability to visitors.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Security;

defined( 'ABSPATH' ) || exit;

/**
 * HMAC-SHA256 token signer.
 */
final class Signer {

	public const SECRET_OPTION = 'shso_secret';

	/**
	 * Sign a payload.
	 *
	 * @param array<string,mixed> $payload Payload (small, JSON serialisable).
	 * @param int                 $ttl     Lifetime in seconds.
	 */
	public static function sign( array $payload, int $ttl = 600 ): string {
		$payload['e'] = time() + max( 30, $ttl );
		$payload['n'] = bin2hex( random_bytes( 6 ) );

		$body = self::b64( (string) wp_json_encode( $payload ) );
		return $body . '.' . self::b64( hash_hmac( 'sha256', $body, self::key(), true ) );
	}

	/**
	 * Verify a token and return its payload.
	 *
	 * @param string $token Token.
	 * @return array<string,mixed>|null
	 */
	public static function verify( string $token ): ?array {
		if ( strlen( $token ) > 4096 || 1 !== substr_count( $token, '.' ) ) {
			return null;
		}

		list( $body, $signature ) = explode( '.', $token, 2 );
		$expected                 = self::b64( hash_hmac( 'sha256', $body, self::key(), true ) );

		if ( ! hash_equals( $expected, $signature ) ) {
			return null;
		}

		$json = base64_decode( strtr( $body, '-_', '+/' ), true );
		if ( false === $json ) {
			return null;
		}

		$payload = json_decode( $json, true );
		if ( ! is_array( $payload ) || empty( $payload['e'] ) || (int) $payload['e'] < time() ) {
			return null;
		}

		return $payload;
	}

	/**
	 * Create the per-site secret if it does not exist yet.
	 */
	public static function ensure_secret(): string {
		$secret = get_option( self::SECRET_OPTION );
		if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
			$secret = bin2hex( random_bytes( 32 ) );
			update_option( self::SECRET_OPTION, $secret, false );
		}
		return $secret;
	}

	/**
	 * Signing key: a per-site random secret combined with the WordPress salts.
	 */
	private static function key(): string {
		return hash( 'sha256', self::ensure_secret() . self::salt() );
	}

	/**
	 * Salt from wp-config.php constants.
	 *
	 * wp_salt() is a pluggable function that does not exist yet when tokens are
	 * verified (during plugin loading), so the constants are read directly.
	 * The random per-site secret alone already makes tokens unforgeable; the
	 * salt only adds protection if the database leaks.
	 */
	private static function salt(): string {
		$salt = '';
		foreach ( array( 'NONCE_KEY', 'NONCE_SALT', 'AUTH_KEY' ) as $constant ) {
			if ( defined( $constant ) && 'put your unique phrase here' !== constant( $constant ) ) {
				$salt .= (string) constant( $constant );
			}
		}
		return $salt;
	}

	/**
	 * URL safe base64.
	 *
	 * @param string $data Raw data.
	 */
	private static function b64( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
