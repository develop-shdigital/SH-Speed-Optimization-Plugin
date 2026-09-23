<?php
/**
 * Plugin container and bootstrap.
 *
 * Services are created lazily so a visitor request only loads what the
 * active optimizations need; admin, REST, scanner and diagnostics code is
 * never loaded on the frontend.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Core;

use SH\SpeedOptimizer\Cache\CacheManager;
use SH\SpeedOptimizer\Compatibility\CompatibilityManager;
use SH\SpeedOptimizer\Core\Jobs\JobManager;
use SH\SpeedOptimizer\Database\DatabaseService;
use SH\SpeedOptimizer\Detection\Detector;
use SH\SpeedOptimizer\Diagnostics\Logger;
use SH\SpeedOptimizer\Diagnostics\Metrics;
use SH\SpeedOptimizer\Diagnostics\Scanner;
use SH\SpeedOptimizer\Optimization\DecisionEngine;
use SH\SpeedOptimizer\Optimization\Engine;
use SH\SpeedOptimizer\Optimization\Registry;
use SH\SpeedOptimizer\Optimization\Runtime;
use SH\SpeedOptimizer\Rollback\SnapshotManager;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Service instances.
	 *
	 * @var array<string,object>
	 */
	private array $services = array();

	/**
	 * Whether job handlers were registered.
	 *
	 * @var bool
	 */
	private bool $handlers_registered = false;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset the singleton (tests only).
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Wire WordPress hooks.
	 */
	public function boot(): void {
		$this->boot_verification_request();

		add_action( 'plugins_loaded', array( Installer::class, 'maybe_upgrade' ), 0 );
		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ), 1 );
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Background processing.
		add_action( Scheduler::JOB_HOOK, array( $this, 'on_job_tick' ) );
		add_action( Scheduler::HOURLY_HOOK, array( $this, 'on_hourly' ) );
		add_action( Scheduler::DAILY_HOOK, array( $this, 'on_daily' ) );

		// REST API (admin endpoints and optional real-user metrics).
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );

		// Admin bar (frontend and admin) for users who may purge the cache.
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 90 );
		add_action( 'admin_post_shso_bar_action', array( $this, 'admin_bar_action' ) );

		if ( is_admin() ) {
			\SH\SpeedOptimizer\Admin\HarnessAssets::register();
			( new \SH\SpeedOptimizer\Admin\Admin( $this ) )->register();
		}

		// Multisite lifecycle.
		add_action( 'wp_initialize_site', array( Installer::class, 'on_new_site' ), 200 );
		add_filter( 'wpmu_drop_tables', array( Installer::class, 'drop_site_tables' ), 10, 2 );

		// Settings affecting cached output purge the cache.
		add_action( 'shso_settings_updated', array( $this, 'on_settings_updated' ), 10, 2 );

		if ( Context::is_cli() && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'shso', \SH\SpeedOptimizer\Core\Cli::class );
		}
	}

	/**
	 * Signed verification/analysis requests render as an anonymous visitor
	 * and are never cached by the plugin, the browser or a CDN.
	 */
	private function boot_verification_request(): void {
		$verification = $this->context()->verification();
		if ( null === $verification ) {
			return;
		}

		add_filter( 'determine_current_user', '__return_zero', PHP_INT_MAX );
		add_filter( 'show_admin_bar', '__return_false', PHP_INT_MAX );
		add_action(
			'send_headers',
			static function () {
				nocache_headers();
				header( 'X-Robots-Tag: noindex, nofollow' );
			}
		);
		add_action(
			'init',
			static function () {
				if ( ! defined( 'DONOTCACHEPAGE' ) ) {
					define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard cross-plugin constant.
				}
			},
			0
		);

		if ( ! empty( $verification['a'] ) ) {
			\SH\SpeedOptimizer\Diagnostics\PageAnalyzer::start_capture( $this, $verification );
		}
		if ( ! empty( $verification['p'] ) ) {
			\SH\SpeedOptimizer\Diagnostics\BrowserProbe::attach( $this, $verification );
		}
	}

	/**
	 * All plugins are loaded: register compatibility integrations and boot the runtime.
	 */
	public function on_plugins_loaded(): void {
		if ( class_exists( '\SH\SpeedOptimizer\Modules\Compatibility\CompatibilityModule' ) ) {
			\SH\SpeedOptimizer\Modules\Compatibility\CompatibilityModule::register();
		}

		$this->runtime()->boot();

		if ( $this->context()->is_frontend_request() ) {
			\SH\SpeedOptimizer\Diagnostics\Rum::register( $this );
		}
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'sh-speed-optimizer', false, dirname( plugin_basename( SHSO_FILE ) ) . '/languages' );
	}

	/**
	 * Register job handlers (lazily, only when jobs are used).
	 */
	public function register_job_handlers(): void {
		if ( $this->handlers_registered ) {
			return;
		}
		$this->handlers_registered = true;

		$jobs = $this->jobs();
		$jobs->register( 'scan', $this->scanner() );
		$jobs->register( 'optimize', $this->engine() );
		$jobs->register( 'verify', $this->engine() );
		$jobs->register( 'db_clean', $this->database()->job_handler() );

		/**
		 * Register additional job handlers.
		 *
		 * @param JobManager $jobs   Job manager.
		 * @param Plugin     $plugin Plugin.
		 */
		do_action( 'shso_register_job_handlers', $jobs, $this );
	}

	/**
	 * Cron: advance the current job.
	 */
	public function on_job_tick(): void {
		$this->jobs()->cron_tick();
	}

	/**
	 * Cron: hourly maintenance.
	 */
	public function on_hourly(): void {
		$this->logger()->prune();
		$this->jobs()->run( 20.0 );
	}

	/**
	 * Cron: daily maintenance and health check.
	 */
	public function on_daily(): void {
		$this->metrics()->prune();
		try {
			$this->database()->daily_maintenance();
		} catch ( \Throwable $e ) {
			$this->logger()->error( 'Database maintenance failed.', array( 'error' => $e->getMessage() ), 'database' );
		}
		if ( ! Context::is_emergency_safe_mode() ) {
			$this->engine()->schedule_health_check();
		}
	}

	/**
	 * REST routes.
	 */
	public function register_rest(): void {
		( new \SH\SpeedOptimizer\API\RestController( $this ) )->register_routes();
	}

	/**
	 * Admin bar menu.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public function admin_bar( $bar ): void {
		( new \SH\SpeedOptimizer\Admin\AdminBar( $this ) )->render( $bar );
	}

	/**
	 * Admin bar action handler.
	 */
	public function admin_bar_action(): void {
		( new \SH\SpeedOptimizer\Admin\AdminBar( $this ) )->handle();
	}

	/**
	 * React to settings changes.
	 *
	 * @param array<string,mixed> $current  New settings.
	 * @param array<string,mixed> $previous Old settings.
	 */
	public function on_settings_updated( array $current, array $previous ): void {
		$watch = array( 'safe_mode', 'page_cache', 'cache_lifespan', 'cache_mobile', 'exclude_urls', 'exclude_css', 'exclude_js', 'exclude_cookies', 'overrides' );
		foreach ( $watch as $key ) {
			if ( ( $current[ $key ] ?? null ) !== ( $previous[ $key ] ?? null ) ) {
				$this->engine()->on_configuration_changed( 'settings:' . $key );
				break;
			}
		}
	}

	// ---------------------------------------------------------------------
	// Services.
	// ---------------------------------------------------------------------

	/**
	 * Lazy service factory.
	 *
	 * @param string   $id      Service id.
	 * @param callable $factory Factory.
	 * @return mixed
	 */
	private function service( string $id, callable $factory ) {
		if ( ! isset( $this->services[ $id ] ) ) {
			$this->services[ $id ] = $factory();
		}
		return $this->services[ $id ];
	}

	/**
	 * Settings.
	 */
	public function settings(): Settings {
		return $this->service( 'settings', static fn() => new Settings() );
	}

	/**
	 * Engine state.
	 */
	public function state(): State {
		return $this->service( 'state', static fn() => new State() );
	}

	/**
	 * Request context.
	 */
	public function context(): Context {
		return $this->service( 'context', fn() => new Context( $this->settings() ) );
	}

	/**
	 * Logger.
	 */
	public function logger(): Logger {
		return $this->service( 'logger', fn() => new Logger( $this->context()->is_debug() ) );
	}

	/**
	 * Filesystem.
	 */
	public function filesystem(): Filesystem {
		return $this->service( 'filesystem', static fn() => new Filesystem() );
	}

	/**
	 * Optimization registry.
	 */
	public function registry(): Registry {
		return $this->service( 'registry', fn() => new Registry( $this ) );
	}

	/**
	 * Request runtime.
	 */
	public function runtime(): Runtime {
		return $this->service( 'runtime', fn() => new Runtime( $this ) );
	}

	/**
	 * Decision engine.
	 */
	public function decisions(): DecisionEngine {
		return $this->service( 'decisions', fn() => new DecisionEngine( $this->settings(), $this->state() ) );
	}

	/**
	 * Compatibility manager.
	 */
	public function compatibility(): CompatibilityManager {
		return $this->service( 'compatibility', fn() => new CompatibilityManager( $this ) );
	}

	/**
	 * Environment detector.
	 */
	public function detector(): Detector {
		return $this->service( 'detector', fn() => new Detector( $this ) );
	}

	/**
	 * Job manager.
	 */
	public function jobs(): JobManager {
		return $this->service( 'jobs', fn() => new JobManager( $this ) );
	}

	/**
	 * Optimization engine.
	 */
	public function engine(): Engine {
		return $this->service( 'engine', fn() => new Engine( $this ) );
	}

	/**
	 * Performance scanner.
	 */
	public function scanner(): Scanner {
		return $this->service( 'scanner', fn() => new Scanner( $this ) );
	}

	/**
	 * Configuration snapshots.
	 */
	public function snapshots(): SnapshotManager {
		return $this->service( 'snapshots', fn() => new SnapshotManager( $this ) );
	}

	/**
	 * Page cache manager.
	 */
	public function cache(): CacheManager {
		return $this->service( 'cache', fn() => new CacheManager( $this ) );
	}

	/**
	 * Database diagnostics and cleanup.
	 */
	public function database(): DatabaseService {
		return $this->service( 'database', fn() => new DatabaseService( $this ) );
	}

	/**
	 * Metrics store.
	 */
	public function metrics(): Metrics {
		return $this->service( 'metrics', fn() => new Metrics( $this ) );
	}
}
