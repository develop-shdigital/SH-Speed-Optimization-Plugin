<?php
/**
 * Capability checks.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Central place for permission decisions.
 */
final class Capabilities {

	/**
	 * Capability required to manage the plugin on a site.
	 */
	public static function manage_cap(): string {
		/**
		 * Filters the capability required to manage SH Speed Optimizer.
		 *
		 * @param string $capability Default `manage_options`.
		 */
		return (string) apply_filters( 'shso_manage_capability', 'manage_options' );
	}

	/**
	 * Whether the current user may manage the plugin.
	 */
	public static function can_manage(): bool {
		return current_user_can( self::manage_cap() );
	}

	/**
	 * Whether the current user may manage network defaults.
	 */
	public static function can_manage_network(): bool {
		return is_multisite() && current_user_can( 'manage_network_options' );
	}

	/**
	 * Why files shared by the whole server (wp-config.php, advanced-cache.php,
	 * .htaccess) may not be changed right now, or null when they may.
	 *
	 * On multisite these files affect every site, so only a network
	 * administrator may request the change. DISALLOW_FILE_MODS blocks it everywhere.
	 *
	 * @param bool $check_user Whether to check the current user (false for background tasks).
	 */
	public static function server_files_blocked_reason( bool $check_user = true ): ?string {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return __( 'File changes are disabled on this site (DISALLOW_FILE_MODS), so SH Speed does not change server files.', 'sh-speed-optimizer' );
		}
		if ( $check_user && is_multisite() && ! current_user_can( 'manage_network_options' ) ) {
			return __( 'This changes files shared by every site in the network. Please ask your network administrator.', 'sh-speed-optimizer' );
		}
		return null;
	}

	/**
	 * Whether the current user may purge the page cache (editors publish content, so they may purge).
	 */
	public static function can_purge(): bool {
		return self::can_manage() || current_user_can( 'edit_others_posts' );
	}
}
