<?php
/**
 * Plugin Name:       SH Speed Optimizer
 * Plugin URI:        https://shdigital.ch/
 * Description:       Safety-first automatic performance optimization. Scans your site, applies only the optimizations that are safe for it, verifies the result and rolls back anything that causes problems.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            SH Digital
 * Author URI:        https://shdigital.ch/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sh-speed-optimizer
 * Domain Path:       /languages
 *
 * @package SH\SpeedOptimizer
 */

// This file must stay parseable by old PHP versions so the version check below can run.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'SHSO_VERSION' ) ) {
	// Another copy of the plugin is already loaded.
	return;
}

define( 'SHSO_VERSION', '1.0.0' );
define( 'SHSO_DB_VERSION', 1 );
define( 'SHSO_FILE', __FILE__ );
define( 'SHSO_DIR', __DIR__ . '/' );
define( 'SHSO_MIN_PHP', '8.1' );
define( 'SHSO_MIN_WP', '6.2' );

if ( version_compare( PHP_VERSION, SHSO_MIN_PHP, '<' ) || version_compare( $GLOBALS['wp_version'], SHSO_MIN_WP, '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: required WordPress version */
						__( 'SH Speed Optimizer requires PHP %1$s and WordPress %2$s or newer. The plugin is inactive until the server is updated.', 'sh-speed-optimizer' ),
						SHSO_MIN_PHP,
						SHSO_MIN_WP
					)
				)
			);
		}
	);
	return;
}

require_once SHSO_DIR . 'includes/Autoloader.php';
\SH\SpeedOptimizer\Autoloader::register();

register_activation_hook( __FILE__, array( '\SH\SpeedOptimizer\Core\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\SH\SpeedOptimizer\Core\Installer', 'deactivate' ) );

\SH\SpeedOptimizer\Core\Plugin::instance()->boot();
