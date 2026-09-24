<?php
/**
 * Uninstall: remove every trace of SH Speed Optimizer.
 *
 * Runs only when the plugin is deleted from the Plugins screen. Removes the
 * cache drop-in and server rules the plugin added, generated files, options,
 * transients, post meta, scheduled events and custom tables — on every site
 * of a network.
 *
 * @package SH\SpeedOptimizer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'SHSO_DIR' ) ) {
	define( 'SHSO_DIR', __DIR__ . '/' );
}
if ( ! defined( 'SHSO_FILE' ) ) {
	define( 'SHSO_FILE', __DIR__ . '/sh-speed-optimizer.php' );
}
if ( ! defined( 'SHSO_VERSION' ) ) {
	define( 'SHSO_VERSION', 'uninstall' );
}
if ( ! defined( 'SHSO_DB_VERSION' ) ) {
	define( 'SHSO_DB_VERSION', 1 );
}

require_once __DIR__ . '/includes/Autoloader.php';
\SH\SpeedOptimizer\Autoloader::register();

/**
 * Remove data of the current site.
 */
function shso_uninstall_site(): void {
	global $wpdb;

	// Scheduled events.
	foreach ( array( 'shso_cron_hourly', 'shso_cron_daily', 'shso_job_tick', 'shso_preload_batch', 'shso_generate_assets', 'shso_webp_batch', 'shso_localize_fonts', 'shso_facade_thumbs', 'shso_cache_write_config' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook );
		}
	}

	// Options and transients.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'shso_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_shso_' ) . '%', $wpdb->esc_like( '_transient_timeout_shso_' ) . '%' ) );

	// Post meta written by image optimization.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_shso_' ) . '%' ) );

	// Tables.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}shso_log" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}shso_metrics" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
	// phpcs:enable

	// Generated files in uploads (WebP derivatives, database backups).
	$fs = new \SH\SpeedOptimizer\Core\Filesystem();
	$fs->delete_tree( \SH\SpeedOptimizer\Core\Filesystem::uploads_root( false ), true );
	shso_uninstall_remove_root( \SH\SpeedOptimizer\Core\Filesystem::uploads_root( false ) );

	wp_cache_flush();
}

// Server-level side effects (shared by all sites).
try {
	if ( class_exists( '\SH\SpeedOptimizer\Modules\BrowserCache\HtaccessRules' ) ) {
		\SH\SpeedOptimizer\Modules\BrowserCache\HtaccessRules::remove();
	}
	if ( class_exists( '\SH\SpeedOptimizer\Cache\Dropin' ) ) {
		\SH\SpeedOptimizer\Cache\Dropin::uninstall();
		\SH\SpeedOptimizer\Cache\Dropin::disable_wp_cache_constant();
	}
} catch ( \Throwable $e ) {
	unset( $e ); // Uninstall must continue.
}

if ( is_multisite() ) {
	$shso_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $shso_sites as $shso_site_id ) {
		switch_to_blog( (int) $shso_site_id );
		shso_uninstall_site();
		restore_current_blog();
	}
	delete_site_option( 'shso_network_settings' );
} else {
	shso_uninstall_site();
}

// Shared cache directory (page cache, optimized assets, fonts, config).
$shso_fs = new \SH\SpeedOptimizer\Core\Filesystem();
$shso_fs->delete_tree( \SH\SpeedOptimizer\Core\Filesystem::cache_root(), true );

/**
 * Remove a plugin root directory after its contents were deleted (delete_tree() keeps roots and their protection files).
 *
 * @param string $dir Root directory.
 */
function shso_uninstall_remove_root( string $dir ): void {
	$dir = rtrim( $dir, '/' );
	if ( ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}
	foreach ( array( '.htaccess', 'index.html', 'web.config' ) as $file ) {
		if ( is_file( $dir . '/' . $file ) ) {
			@unlink( $dir . '/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

shso_uninstall_remove_root( \SH\SpeedOptimizer\Core\Filesystem::cache_root() );
