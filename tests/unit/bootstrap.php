<?php
/**
 * Unit test bootstrap.
 *
 * Unit tests run without WordPress. A small set of WordPress functions is
 * stubbed with faithful, minimal implementations (tests/unit/stubs/*.php).
 * Integration behaviour is covered by the Playwright E2E suite (tests/e2e).
 *
 * @package SH\SpeedOptimizer\Tests
 */

define( 'ABSPATH', sys_get_temp_dir() . '/shso-tests/wordpress/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/shso-tests/wordpress/wp-content' );
define( 'SHSO_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'SHSO_FILE', SHSO_DIR . 'sh-speed-optimizer.php' );
define( 'SHSO_VERSION', 'test' );
define( 'SHSO_DB_VERSION', 1 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );

if ( ! is_dir( WP_CONTENT_DIR ) ) {
	mkdir( WP_CONTENT_DIR, 0777, true );
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Core WordPress stubs first; area-specific stub files only add missing functions.
require_once __DIR__ . '/stubs/wordpress.php';
foreach ( glob( __DIR__ . '/stubs/*.php' ) as $stub ) {
	require_once $stub;
}

require_once SHSO_DIR . 'includes/Autoloader.php';
\SH\SpeedOptimizer\Autoloader::register();
