<?php
/**
 * Active plugins (regular, network-activated and must-use).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Detection;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin detection.
 */
final class PluginDetector {

	/**
	 * Plugin slug from a plugin file: the directory name, or the file name without ".php"
	 * for single-file plugins. Accepts relative ("akismet/akismet.php") and absolute paths.
	 *
	 * @param string $file Plugin file.
	 */
	public static function slug_from_file( string $file ): string {
		$file     = str_replace( '\\', '/', trim( $file ) );
		$absolute = '' !== $file && ( '/' === $file[0] || preg_match( '#^[A-Za-z]:/#', $file ) );

		if ( $absolute ) {
			foreach ( array( '/mu-plugins/', '/plugins/' ) as $marker ) {
				$pos = strrpos( $file, $marker );
				if ( false !== $pos ) {
					$file = substr( $file, $pos + strlen( $marker ) );
					break;
				}
			}
		}

		$file = trim( $file, '/' );
		if ( '' === $file ) {
			return '';
		}

		$slash = strpos( $file, '/' );
		if ( false !== $slash ) {
			return substr( $file, 0, $slash );
		}

		return (string) preg_replace( '/\.php$/i', '', $file );
	}

	/**
	 * Build the plugins section from raw WordPress data (pure).
	 *
	 * @param string[]                            $active  Active plugin files (site).
	 * @param string[]                            $network Network-activated plugin files.
	 * @param array<string,array<string,string>>  $headers get_plugins() result (file => headers).
	 * @param array<string,array<string,string>>  $mu      get_mu_plugins() result (file => headers).
	 * @return array<string,array<string,mixed>> slug => [ name, version, file, network, mu ]
	 */
	public static function build( array $active, array $network, array $headers, array $mu ): array {
		$plugins = array();

		foreach ( array_unique( array_merge( $active, $network ) ) as $file ) {
			if ( ! is_string( $file ) || ! preg_match( '/\.php$/i', $file ) ) {
				continue;
			}
			$slug = self::slug_from_file( $file );
			if ( '' === $slug || isset( $plugins[ $slug ] ) ) {
				continue;
			}
			$data             = $headers[ $file ] ?? array();
			$plugins[ $slug ] = array(
				'name'    => '' !== (string) ( $data['Name'] ?? '' ) ? (string) $data['Name'] : $slug,
				'version' => (string) ( $data['Version'] ?? '' ),
				'file'    => $file,
				'network' => in_array( $file, $network, true ),
				'mu'      => false,
			);
		}

		foreach ( $mu as $file => $data ) {
			$slug = self::slug_from_file( (string) $file );
			if ( '' === $slug || isset( $plugins[ $slug ] ) ) {
				continue;
			}
			$plugins[ $slug ] = array(
				'name'    => '' !== (string) ( $data['Name'] ?? '' ) ? (string) $data['Name'] : $slug,
				'version' => (string) ( $data['Version'] ?? '' ),
				'file'    => (string) $file,
				'network' => false,
				'mu'      => true,
			);
		}

		return $plugins;
	}

	/**
	 * Collect the plugins section.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function collect(): array {
		$active  = (array) get_option( 'active_plugins', array() );
		$network = array();
		if ( is_multisite() ) {
			$network = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
		}

		if ( ( ! function_exists( 'get_plugins' ) || ! function_exists( 'get_mu_plugins' ) ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$headers = array();
		$mu      = array();
		try {
			$headers = function_exists( 'get_plugins' ) ? (array) get_plugins() : array();
			$mu      = function_exists( 'get_mu_plugins' ) ? (array) get_mu_plugins() : array();
		} catch ( \Throwable $e ) {
			unset( $e ); // Names and versions are optional.
		}

		return self::build( $active, array_map( 'strval', $network ), $headers, $mu );
	}
}
