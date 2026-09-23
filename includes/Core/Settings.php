<?php
/**
 * User settings.
 *
 * Intentionally small: the engine decides almost everything automatically.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Settings repository (option `shso_settings`, merged over network defaults on multisite).
 */
final class Settings {

	public const OPTION         = 'shso_settings';
	public const NETWORK_OPTION = 'shso_network_settings';

	/**
	 * Cached merged settings.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Factory defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			// General.
			'auto_optimize'          => true,
			'safe_mode'              => false,
			// Cache.
			'page_cache'             => true,
			'cache_lifespan'         => 10, // Hours. Kept below the default nonce lifetime.
			'cache_mobile'           => 'auto', // auto|on|off.
			'preload'                => true,
			'preload_limit'          => 50,
			// Optimization.
			'safe_optimizations'     => true,
			'advanced_optimizations' => false,
			'overrides'              => array(), // optimization_id => on|off.
			// Exclusions (one entry per line in the UI).
			'exclude_urls'           => array(),
			'exclude_css'            => array(),
			'exclude_js'             => array(),
			'exclude_cookies'        => array(),
			// Permissions for changes outside WordPress' own data.
			'allow_server_config'    => false, // Write browser cache rules to .htaccess.
			'localize_fonts'         => false, // Download Google Fonts to this server.
			// Measurement.
			'psi_api_key'            => '',
			'rum'                    => false, // Anonymous real-user Core Web Vitals.
			// Developer.
			'debug'                  => false,
		);
	}

	/**
	 * Keys a site administrator may not change when the network administrator locked them.
	 *
	 * @return string[]
	 */
	public static function network_lockable(): array {
		return array( 'auto_optimize', 'safe_mode', 'page_cache', 'advanced_optimizations', 'allow_server_config' );
	}

	/**
	 * All settings.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$defaults = self::defaults();
		$network  = $this->network_defaults();
		$site     = get_option( self::OPTION, array() );
		$site     = is_array( $site ) ? $site : array();

		$merged = array_merge( $defaults, $network['defaults'], $site );

		foreach ( $network['locked'] as $key ) {
			if ( array_key_exists( $key, $network['defaults'] ) ) {
				$merged[ $key ] = $network['defaults'][ $key ];
			}
		}

		$this->cache = self::sanitize( $merged );
		return $this->cache;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	public function get( string $key, $fallback = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Update several settings at once.
	 *
	 * @param array<string,mixed> $changes Changes.
	 * @return array<string,mixed> Previous settings.
	 */
	public function update( array $changes ): array {
		$previous = $this->all();
		$site     = get_option( self::OPTION, array() );
		$site     = is_array( $site ) ? $site : array();

		foreach ( $changes as $key => $value ) {
			if ( array_key_exists( $key, self::defaults() ) ) {
				$site[ $key ] = $value;
			}
		}

		$site = self::sanitize( array_merge( self::defaults(), $site ) );
		update_option( self::OPTION, $site, true );
		$this->cache = null;

		/**
		 * Fires after SH Speed Optimizer settings changed.
		 *
		 * @param array $current  New settings.
		 * @param array $previous Previous settings.
		 */
		do_action( 'shso_settings_updated', $this->all(), $previous );

		return $previous;
	}

	/**
	 * Replace the stored site settings entirely (used by snapshot restore).
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	public function replace( array $settings ): void {
		update_option( self::OPTION, self::sanitize( array_merge( self::defaults(), $settings ) ), true );
		$this->cache = null;
	}

	/**
	 * Network level defaults.
	 *
	 * @return array{defaults: array<string,mixed>, locked: string[]}
	 */
	public function network_defaults(): array {
		if ( ! is_multisite() ) {
			return array(
				'defaults' => array(),
				'locked'   => array(),
			);
		}

		$raw = get_site_option( self::NETWORK_OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();

		$defaults = isset( $raw['defaults'] ) && is_array( $raw['defaults'] ) ? array_intersect_key( $raw['defaults'], self::defaults() ) : array();
		$locked   = isset( $raw['locked'] ) && is_array( $raw['locked'] ) ? array_values( array_intersect( $raw['locked'], self::network_lockable() ) ) : array();

		return array(
			'defaults' => $defaults,
			'locked'   => $locked,
		);
	}

	/**
	 * Save network defaults.
	 *
	 * @param array<string,mixed> $defaults Defaults.
	 * @param string[]            $locked   Locked keys.
	 */
	public function update_network_defaults( array $defaults, array $locked ): void {
		$clean = self::sanitize( array_merge( self::defaults(), array_intersect_key( $defaults, self::defaults() ) ) );
		update_site_option(
			self::NETWORK_OPTION,
			array(
				'defaults' => array_intersect_key( $clean, $defaults ),
				'locked'   => array_values( array_intersect( $locked, self::network_lockable() ) ),
			)
		);
		$this->cache = null;
	}

	/**
	 * Whether a key is locked by the network administrator.
	 *
	 * @param string $key Key.
	 */
	public function is_locked( string $key ): bool {
		return in_array( $key, $this->network_defaults()['locked'], true );
	}

	/**
	 * Flush the in-memory cache.
	 */
	public function flush(): void {
		$this->cache = null;
	}

	/**
	 * Strictly sanitize a settings array.
	 *
	 * @param array<string,mixed> $input Raw settings.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input ): array {
		$defaults = self::defaults();
		$out      = array();

		foreach ( $defaults as $key => $default ) {
			$value = array_key_exists( $key, $input ) ? $input[ $key ] : $default;

			switch ( $key ) {
				case 'cache_lifespan':
					$out[ $key ] = max( 1, min( 720, (int) $value ) );
					break;
				case 'preload_limit':
					$out[ $key ] = max( 0, min( 500, (int) $value ) );
					break;
				case 'cache_mobile':
					$out[ $key ] = in_array( $value, array( 'auto', 'on', 'off' ), true ) ? $value : 'auto';
					break;
				case 'psi_api_key':
					$out[ $key ] = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value );
					break;
				case 'overrides':
					$out[ $key ] = array();
					if ( is_array( $value ) ) {
						foreach ( $value as $id => $state ) {
							$id = sanitize_key( (string) $id );
							if ( '' !== $id && in_array( $state, array( 'on', 'off' ), true ) ) {
								$out[ $key ][ $id ] = $state;
							}
						}
					}
					break;
				case 'exclude_urls':
				case 'exclude_css':
				case 'exclude_js':
				case 'exclude_cookies':
					$out[ $key ] = self::sanitize_lines( $value, 'exclude_cookies' === $key );
					break;
				default:
					$out[ $key ] = is_bool( $default ) ? (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN ) : $value;
			}
		}

		return $out;
	}

	/**
	 * Normalize a list of exclusion patterns.
	 *
	 * @param mixed $value      String (newline separated) or array.
	 * @param bool  $is_cookie  Whether entries are cookie names.
	 * @return string[]
	 */
	private static function sanitize_lines( $value, bool $is_cookie ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n/', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$lines = array();
		foreach ( $value as $line ) {
			$line = trim( wp_strip_all_tags( (string) $line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( $is_cookie ) {
				$line = preg_replace( '/[^A-Za-z0-9_\-\.\*]/', '', $line );
			} else {
				$line = preg_replace( '/[\x00-\x1F\x7F"<>`]/', '', $line );
			}
			if ( '' !== $line && strlen( $line ) <= 300 ) {
				$lines[] = $line;
			}
		}

		return array_slice( array_values( array_unique( $lines ) ), 0, 200 );
	}
}
