<?php
/**
 * Lookup seam for environment facts.
 *
 * Detection logic never calls WordPress or PHP globals directly; it asks a
 * lookup callable `function( string $type, string $name ): mixed` instead.
 * The WordPress-backed implementation lives here, and tests use
 * {@see Facts::from_array()} so the detection rules stay testable without
 * WordPress.
 *
 * Supported types:
 *   const        constant value, or null when undefined
 *   const_prefix bool: a user constant starting with the name exists
 *   env          environment variable ($_SERVER / getenv / $_ENV), or null
 *   server       $_SERVER value, or null
 *   option       option value, or null when missing
 *   global       $GLOBALS value, or null
 *   class        bool: class exists (autoloading allowed; scan context only)
 *   function     bool: function exists
 *   plugin       bool: an active plugin with this slug exists (case-insensitive)
 *   mu           bool: a must-use plugin file or directory name contains the name
 *   path         string: "abspath" or "content_dir"
 *   server_software  detected web server id (litespeed, apache …)
 *   w3tc         bool|null: W3 Total Cache configuration flag
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Detection;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress-backed fact lookup.
 */
final class Facts {

	/**
	 * Active plugin slugs (lowercase) => true.
	 *
	 * @var array<string,bool>
	 */
	private array $plugins;

	/**
	 * Lowercase must-use plugin file/directory names.
	 *
	 * @var string[]|null
	 */
	private ?array $mu = null;

	/**
	 * Detected server software id.
	 *
	 * @var string
	 */
	private string $server_software;

	/**
	 * Parsed W3 Total Cache configuration (null = not loaded yet, false = unavailable).
	 *
	 * @var array<string,mixed>|false|null
	 */
	private $w3tc = null;

	/**
	 * Constructor.
	 *
	 * @param string[] $plugin_slugs    Active plugin slugs.
	 * @param string   $server_software Detected server software id.
	 */
	public function __construct( array $plugin_slugs = array(), string $server_software = 'unknown' ) {
		$this->plugins = array();
		foreach ( $plugin_slugs as $slug ) {
			$this->plugins[ strtolower( (string) $slug ) ] = true;
		}
		$this->server_software = $server_software;
	}

	/**
	 * Lookup backed by an array: keys are "type:name" (e.g. "const:WPE_APIKEY", "option:autoptimize_js").
	 *
	 * @param array<string,mixed> $map Facts.
	 */
	public static function from_array( array $map ): callable {
		return static function ( string $type, string $name ) use ( $map ) {
			$key = $type . ':' . $name;
			if ( array_key_exists( $key, $map ) ) {
				return $map[ $key ];
			}
			if ( 'plugin' === $type ) {
				return array_key_exists( 'plugin:' . strtolower( $name ), $map ) ? $map[ 'plugin:' . strtolower( $name ) ] : null;
			}
			return null;
		};
	}

	/**
	 * Resolve a fact.
	 *
	 * @param string $type Fact type.
	 * @param string $name Fact name.
	 * @return mixed
	 */
	public function __invoke( string $type, string $name ) {
		switch ( $type ) {
			case 'const':
				return defined( $name ) ? constant( $name ) : null;

			case 'const_prefix':
				return $this->has_constant_prefix( $name );

			case 'env':
				return $this->env( $name );

			case 'server':
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Compared against fixed strings only.
				return isset( $_SERVER[ $name ] ) && is_scalar( $_SERVER[ $name ] ) ? (string) $_SERVER[ $name ] : null;

			case 'option':
				return function_exists( 'get_option' ) ? get_option( $name, null ) : null;

			case 'global':
				return $GLOBALS[ $name ] ?? null;

			case 'class':
				return class_exists( $name );

			case 'function':
				return function_exists( $name );

			case 'plugin':
				return isset( $this->plugins[ strtolower( $name ) ] );

			case 'mu':
				return $this->has_mu( $name );

			case 'path':
				if ( 'content_dir' === $name ) {
					return defined( 'WP_CONTENT_DIR' ) ? (string) WP_CONTENT_DIR : '';
				}
				return (string) ABSPATH;

			case 'server_software':
				return $this->server_software;

			case 'w3tc':
				return $this->w3tc_flag( $name );
		}

		return null;
	}

	/**
	 * Environment variable from $_SERVER, getenv() or $_ENV.
	 *
	 * @param string $name Variable name.
	 */
	private function env( string $name ): ?string {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Presence/fixed-string checks only.
		if ( isset( $_SERVER[ $name ] ) && is_scalar( $_SERVER[ $name ] ) ) {
			return (string) $_SERVER[ $name ];
		}
		// phpcs:enable
		$value = function_exists( 'getenv' ) ? getenv( $name ) : false;
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( isset( $_ENV[ $name ] ) && is_scalar( $_ENV[ $name ] ) ) {
			return (string) $_ENV[ $name ];
		}
		return null;
	}

	/**
	 * Whether a user-defined constant starts with a prefix.
	 *
	 * @param string $prefix Prefix.
	 */
	private function has_constant_prefix( string $prefix ): bool {
		$constants = get_defined_constants( true );
		foreach ( array_keys( $constants['user'] ?? array() ) as $constant ) {
			if ( 0 === strpos( (string) $constant, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a must-use plugin file or directory name contains a needle.
	 *
	 * @param string $needle Needle (case-insensitive).
	 */
	private function has_mu( string $needle ): bool {
		if ( null === $this->mu ) {
			$this->mu = array();
			$dir      = defined( 'WPMU_PLUGIN_DIR' ) ? (string) WPMU_PLUGIN_DIR : '';
			if ( '' !== $dir && is_dir( $dir ) ) {
				$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable directories are simply skipped.
				foreach ( (array) $entries as $entry ) {
					$entry = (string) $entry;
					if ( '' !== $entry && '.' !== $entry[0] ) {
						$this->mu[] = strtolower( $entry );
					}
				}
			}
		}
		$needle = strtolower( $needle );
		foreach ( $this->mu as $entry ) {
			if ( false !== strpos( $entry, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * W3 Total Cache configuration flag (e.g. "pgcache.enabled").
	 *
	 * @param string $key Configuration key.
	 */
	private function w3tc_flag( string $key ): ?bool {
		if ( class_exists( '\W3TC\Dispatcher' ) ) {
			try {
				$config = \W3TC\Dispatcher::config();
				if ( is_object( $config ) && method_exists( $config, 'get_boolean' ) ) {
					return (bool) $config->get_boolean( $key );
				}
			} catch ( \Throwable $e ) {
				unset( $e ); // Fall back to the configuration file below.
			}
		}

		if ( null === $this->w3tc ) {
			$this->w3tc = false;
			$file       = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' ) . '/w3tc-config/master.php';
			if ( is_readable( $file ) ) {
				$raw = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local configuration file.
				$pos = strpos( $raw, '{' );
				if ( false !== $pos ) {
					$data = json_decode( substr( $raw, $pos ), true );
					if ( is_array( $data ) ) {
						$this->w3tc = $data;
					}
				}
			}
		}

		if ( is_array( $this->w3tc ) && array_key_exists( $key, $this->w3tc ) ) {
			return (bool) $this->w3tc[ $key ];
		}
		return null;
	}
}
