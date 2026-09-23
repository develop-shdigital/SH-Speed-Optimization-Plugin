<?php
/**
 * Managed hosting provider detection.
 *
 * Signals are constants, environment variables, must-use plugins and paths
 * that the providers install. Some providers (Rocket.net, Liquid Web,
 * DreamPress, Nexcess) publish few stable markers, so their detection is a
 * best-effort heuristic.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Detection;

defined( 'ABSPATH' ) || exit;

/**
 * Hosting detection (pure over a fact lookup).
 */
final class HostingDetector {

	/**
	 * Detect the hosting provider.
	 *
	 * @param callable $lookup Fact lookup (see Facts).
	 * @return array{provider:?string,provider_name:?string,page_cache:bool}
	 */
	public static function detect( callable $lookup ): array {
		$const  = static fn( string $name ): bool => null !== $lookup( 'const', $name );
		$env    = static fn( string $name ): bool => null !== $lookup( 'env', $name ) && '' !== (string) $lookup( 'env', $name );
		$mu     = static fn( string $name ): bool => ! empty( $lookup( 'mu', $name ) );
		$plugin = static fn( string $name ): bool => ! empty( $lookup( 'plugin', $name ) );
		$class  = static fn( string $name ): bool => ! empty( $lookup( 'class', $name ) );
		$prefix = static fn( string $name ): bool => ! empty( $lookup( 'const_prefix', $name ) );
		$path   = strtolower( str_replace( '\\', '/', (string) $lookup( 'path', 'abspath' ) ) );
		$option = static fn( string $name ) => $lookup( 'option', $name );

		// Providers with full-page caching on by default.
		if ( $const( 'WPE_APIKEY' ) || $const( 'WPE_ISP' ) || $class( 'WpeCommon' ) || $mu( 'wpengine-common' ) ) {
			return self::result( 'wpengine', 'WP Engine', true );
		}
		if ( $const( 'KINSTAMU_VERSION' ) || $const( 'KINSTA_CACHE_ZONE' ) || $mu( 'kinsta-mu-plugins' ) ) {
			return self::result( 'kinsta', 'Kinsta', true );
		}
		if ( $env( 'PANTHEON_ENVIRONMENT' ) || $const( 'PANTHEON_ENVIRONMENT' ) ) {
			return self::result( 'pantheon', 'Pantheon', true );
		}
		if ( $const( 'IS_WPCOM' ) || $const( 'WPCOMSH_VERSION' ) || $const( 'IS_ATOMIC' ) || $const( 'ATOMIC_SITE_ID' ) ) {
			return self::result( 'wpcom', 'WordPress.com', true );
		}
		if ( $const( 'IS_PRESSABLE' ) ) {
			return self::result( 'pressable', 'Pressable', true );
		}
		if ( $const( 'FLYWHEEL_CONFIG_DIR' ) || $const( 'FLYWHEEL_PLUGIN_DIR' ) ) {
			return self::result( 'flywheel', 'Flywheel', true );
		}
		if ( $const( 'GD_SYSTEM_PLUGIN_DIR' ) || $prefix( 'WPAAS_' ) || $class( 'WPaaS\\Plugin' ) || $mu( 'gd-system-plugin' ) ) {
			return self::result( 'godaddy', 'GoDaddy Managed WordPress', true );
		}
		if ( $prefix( 'SERVEBOLT_' ) || $env( 'SERVEBOLT_ENVIRONMENT' ) || false !== strpos( $path, '/kunder/' ) ) {
			return self::result( 'servebolt', 'Servebolt', true );
		}
		if ( $mu( 'rocketnet' ) || $mu( 'rocket-net' ) || ( $const( 'CDN_SITE_ID' ) && $const( 'CDN_SITE_TOKEN' ) ) ) {
			return self::result( 'rocketnet', 'Rocket.net', true );
		}

		// Providers whose page cache depends on a setting or plugin.
		if ( $plugin( 'sg-cachepress' ) || false !== strpos( $path, '/home/customer/' ) ) {
			$dynamic = PluginCatalog::to_flag( $option( 'siteground_optimizer_enable_cache' ) );
			return self::result( 'siteground', 'SiteGround', true === $dynamic );
		}
		if ( $mu( 'nexcess-mapps' ) || $prefix( 'NEXCESS_MAPPS' ) ) {
			return self::result( 'nexcess', 'Nexcess', $plugin( 'cache-enabler' ) || $plugin( 'nexcess-mapps-page-cache' ) );
		}
		if ( $mu( 'endurance-page-cache' ) || $plugin( 'bluehost-wordpress-plugin' ) || $mu( 'bluehost' ) ) {
			$enabled = false;
			if ( $mu( 'endurance-page-cache' ) ) {
				$level   = $option( 'endurance_cache_level' );
				$enabled = null === $level ? true : (int) $level > 0; // The drop-in defaults to level 2.
			}
			return self::result( 'bluehost', 'Bluehost', $enabled );
		}

		// Providers detected for information only.
		if ( false !== strpos( $path, 'cloudwaysapps' ) || $env( 'cw_allowed_ip' ) || $mu( 'cloudways' ) ) {
			return self::result( 'cloudways', 'Cloudways', false );
		}
		if ( $mu( 'hostinger' ) || $plugin( 'hostinger' ) || $plugin( 'hostinger-tools-plugin' ) ) {
			return self::result( 'hostinger', 'Hostinger', false );
		}
		if ( $mu( 'liquidweb' ) || $mu( 'liquid-web' ) ) {
			return self::result( 'liquidweb', 'Liquid Web', false );
		}
		if ( $mu( 'dreampress' ) || $mu( 'dreamhost' ) || $plugin( 'dreamhost-panel-login' ) ) {
			return self::result( 'dreampress', 'DreamPress', false );
		}

		return array(
			'provider'      => null,
			'provider_name' => null,
			'page_cache'    => false,
		);
	}

	/**
	 * Result helper.
	 *
	 * @param string $id         Provider id.
	 * @param string $name       Display name.
	 * @param bool   $page_cache Full-page caching active by default.
	 * @return array{provider:string,provider_name:string,page_cache:bool}
	 */
	private static function result( string $id, string $name, bool $page_cache ): array {
		return array(
			'provider'      => $id,
			'provider_name' => $name,
			'page_cache'    => $page_cache,
		);
	}
}
