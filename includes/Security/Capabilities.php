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
	 * Whether the current user may purge the page cache (editors publish content, so they may purge).
	 */
	public static function can_purge(): bool {
		return self::can_manage() || current_user_can( 'edit_others_posts' );
	}
}
