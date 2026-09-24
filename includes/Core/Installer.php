<?php
/**
 * Activation, deactivation and upgrades.
 *
 * Activation never enables optimizations: the administrator starts with the
 * onboarding scan ("Analyze My Site").
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Installer.
 */
final class Installer {

	public const DB_VERSION_OPTION = 'shso_db_version';

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Network activation.
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 200,
				)
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install_site();
				restore_current_blog();
			}
			// Larger networks finish lazily on each site's first admin visit (maybe_upgrade()).
		} else {
			self::install_site();
			set_transient( 'shso_activation_redirect', 1, 60 );
		}

		Scheduler::schedule_recurring();
	}

	/**
	 * Deactivation hook: undo every side effect outside the database so the
	 * site behaves exactly as before; settings are kept for reactivation.
	 *
	 * @param bool $network_wide Network deactivation.
	 */
	public static function deactivate( $network_wide = false ): void {
		unset( $network_wide );
		$plugin = Plugin::instance();

		try {
			$plugin->engine()->remove_side_effects();
		} catch ( \Throwable $e ) {
			unset( $e ); // Deactivation must never fail.
		}

		Scheduler::unschedule_all();
		delete_option( Jobs\JobManager::LOCK_OPTION );
	}

	/**
	 * Install/upgrade the current site.
	 */
	public static function install_site(): void {
		self::create_tables();

		if ( false === get_option( State::OPTION ) ) {
			add_option( State::OPTION, State::blank(), '', true );
		}

		// Generate the signing secret now so it is never created during a visitor request.
		\SH\SpeedOptimizer\Security\Signer::ensure_secret();

		$fs = new Filesystem();
		$fs->cache_dir( '', true );

		update_option( self::DB_VERSION_OPTION, SHSO_DB_VERSION, true );

		try {
			// Restore side effects of optimizations that were active before a deactivation.
			Plugin::instance()->engine()->reapply_side_effects();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Run upgrades when the stored schema version is older than the code (cheap check on every request).
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) < SHSO_DB_VERSION ) {
			self::install_site();
		}
	}

	/**
	 * Create/upgrade custom tables with dbDelta().
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$log     = $wpdb->prefix . 'shso_log';
		$metrics = $wpdb->prefix . 'shso_metrics';

		dbDelta(
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				type varchar(10) NOT NULL DEFAULT 'event',
				level varchar(10) NOT NULL DEFAULT 'info',
				event varchar(40) NOT NULL DEFAULT '',
				optimization_id varchar(64) NOT NULL DEFAULT '',
				message varchar(500) NOT NULL DEFAULT '',
				context longtext NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY type_created (type,created_at),
				KEY optimization_id (optimization_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$metrics} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				recorded_at datetime NOT NULL,
				source varchar(20) NOT NULL DEFAULT '',
				metric varchar(40) NOT NULL DEFAULT '',
				value double NOT NULL DEFAULT 0,
				context varchar(191) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY metric_time (metric,recorded_at),
				KEY source_metric (source,metric)
			) {$charset};"
		);
	}

	/**
	 * New site in a network.
	 *
	 * @param \WP_Site $site Site.
	 */
	public static function on_new_site( $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active_for_network( plugin_basename( SHSO_FILE ) ) ) {
			switch_to_blog( (int) $site->blog_id );
			self::install_site();
			restore_current_blog();
		}
	}

	/**
	 * Tables to drop when a site is deleted.
	 *
	 * @param string[] $tables Tables.
	 * @param int      $site_id Site id.
	 * @return string[]
	 */
	public static function drop_site_tables( array $tables, int $site_id ): array {
		global $wpdb;
		$prefix   = $wpdb->get_blog_prefix( $site_id );
		$tables[] = $prefix . 'shso_log';
		$tables[] = $prefix . 'shso_metrics';
		return $tables;
	}
}
