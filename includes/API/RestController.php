<?php
/**
 * REST API (namespace shso/v1).
 *
 * Every administrative route requires the manage capability; cookie
 * authentication additionally requires the `wp_rest` nonce (handled by
 * WordPress core). The only public route is the optional, rate-limited
 * real-user metrics beacon.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\API;

use SH\SpeedOptimizer\Core\Context;
use SH\SpeedOptimizer\Core\Plugin;
use SH\SpeedOptimizer\Core\Settings;
use SH\SpeedOptimizer\Diagnostics\FindingsBuilder;
use SH\SpeedOptimizer\Diagnostics\HealthScore;
use SH\SpeedOptimizer\Diagnostics\Loopback;
use SH\SpeedOptimizer\Diagnostics\PageSpeed;
use SH\SpeedOptimizer\Diagnostics\Rum;
use SH\SpeedOptimizer\Diagnostics\Scanner;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Decision;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Rollback\SnapshotManager;
use SH\SpeedOptimizer\Security\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller.
 */
final class RestController {

	/**
	 * Largest critical CSS accepted from the browser (the stored limit is lower).
	 */
	private const MAX_CRITICAL_CSS_BYTES = 131072;

	public const NAMESPACE = 'shso/v1';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$admin = array( $this, 'can_manage' );

		$this->route( '/status', WP_REST_Server::READABLE, 'status', $admin );

		$this->route(
			'/jobs',
			WP_REST_Server::CREATABLE,
			'start_job',
			$admin,
			array(
				'type' => array(
					'type'     => 'string',
					'required' => true,
					'enum'     => array( 'scan', 'optimize', 'db_clean' ),
				),
				'args' => array(
					'type'    => 'object',
					'default' => array(),
				),
			)
		);
		$this->route( '/jobs/current', WP_REST_Server::READABLE, 'current_job', $admin );
		$this->route( '/jobs/current/step', WP_REST_Server::CREATABLE, 'step_job', $admin );
		$this->route(
			'/jobs/current/browser',
			WP_REST_Server::CREATABLE,
			'browser_results',
			$admin,
			array(
				'job_id'  => array(
					'type'     => 'string',
					'required' => true,
				),
				'results' => array(
					'type'     => 'object',
					'required' => true,
				),
			)
		);
		$this->route( '/jobs/current/cancel', WP_REST_Server::CREATABLE, 'cancel_job', $admin );

		$this->route( '/optimizations', WP_REST_Server::READABLE, 'optimizations', $admin );
		$this->route(
			'/optimizations/(?P<id>[a-z0-9_]+)',
			WP_REST_Server::CREATABLE,
			'set_optimization',
			$admin,
			array(
				'mode' => array(
					'type'     => 'string',
					'required' => true,
					'enum'     => array( 'on', 'off', 'auto' ),
				),
			)
		);

		$this->route( '/history', WP_REST_Server::READABLE, 'history', $admin );
		$this->route( '/undo', WP_REST_Server::CREATABLE, 'undo', $admin );
		$this->route( '/snapshots/(?P<id>\d+)/restore', WP_REST_Server::CREATABLE, 'restore_snapshot', $admin );

		$this->route(
			'/diagnostics',
			WP_REST_Server::READABLE,
			'diagnostics',
			$admin,
			array(
				'developer' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			)
		);

		$this->route( '/cache', WP_REST_Server::READABLE, 'cache', $admin );
		$this->route(
			'/cache/purge',
			WP_REST_Server::CREATABLE,
			'purge',
			array( $this, 'can_purge' ),
			array(
				'scope' => array(
					'type'    => 'string',
					'enum'    => array( 'all', 'url' ),
					'default' => 'all',
				),
				'url'   => array(
					'type'    => 'string',
					'default' => '',
				),
			)
		);
		$this->route( '/cache/preload', WP_REST_Server::CREATABLE, 'preload', $admin );
		$this->route( '/cache/enable-early-delivery', WP_REST_Server::CREATABLE, 'enable_early_delivery', $admin );

		$this->route( '/settings', WP_REST_Server::READABLE, 'get_settings', $admin );
		$this->route(
			'/settings',
			WP_REST_Server::CREATABLE,
			'save_settings',
			$admin,
			array(
				'settings'          => array(
					'type'     => 'object',
					'required' => true,
				),
				'clear_psi_api_key' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			)
		);
		$this->route(
			'/safe-mode',
			WP_REST_Server::CREATABLE,
			'safe_mode',
			$admin,
			array(
				'enabled' => array(
					'type'     => 'boolean',
					'required' => true,
				),
			)
		);

		$this->route( '/database', WP_REST_Server::READABLE, 'database', $admin );
		$this->route(
			'/database/restore',
			WP_REST_Server::CREATABLE,
			'database_restore',
			$admin,
			array(
				'id' => array(
					'type'     => 'string',
					'required' => true,
				),
			)
		);
		$this->route( '/database/backups/(?P<id>[A-Za-z0-9_\-]+)', WP_REST_Server::DELETABLE, 'database_delete_backup', $admin );

		$this->route(
			'/pagespeed',
			WP_REST_Server::CREATABLE,
			'pagespeed',
			$admin,
			array(
				'strategy' => array(
					'type'    => 'string',
					'enum'    => array( 'mobile', 'desktop' ),
					'default' => 'mobile',
				),
			)
		);

		// Public, optional real-user metrics beacon.
		register_rest_route(
			self::NAMESPACE,
			'/rum',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rum' ),
				'permission_callback' => '__return_true', // Public by design: anonymous, validated, rate limited, opt-in.
			)
		);
	}

	/**
	 * Register a route.
	 *
	 * @param string              $path       Path.
	 * @param string              $methods    Methods.
	 * @param string              $callback   Method name.
	 * @param callable            $permission Permission callback.
	 * @param array<string,mixed> $args       Args schema.
	 */
	private function route( string $path, string $methods, string $callback, callable $permission, array $args = array() ): void {
		register_rest_route(
			self::NAMESPACE,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => array( $this, $callback ),
				'permission_callback' => $permission,
				'args'                => $args,
			)
		);
	}

	/**
	 * Permission: manage the plugin.
	 */
	public function can_manage(): bool {
		return Capabilities::can_manage();
	}

	/**
	 * Permission: purge the cache.
	 */
	public function can_purge(): bool {
		return Capabilities::can_purge();
	}

	// ------------------------------------------------------------------
	// Status.
	// ------------------------------------------------------------------

	/**
	 * GET /status.
	 */
	public function status(): WP_REST_Response {
		return rest_ensure_response( $this->build_status() );
	}

	/**
	 * Dashboard status payload.
	 *
	 * @return array<string,mixed>
	 */
	public function build_status(): array {
		$scan     = Scanner::last();
		$state    = $this->plugin->state()->all();
		$registry = $this->plugin->registry();
		$health   = $scan['health'] ?? HealthScore::calculate( array() );

		$ready = array();
		foreach ( (array) ( $scan['ready'] ?? array() ) as $item ) {
			if ( ! $this->plugin->state()->is_active( (string) $item['id'] ) && null !== $registry->get( (string) $item['id'] ) ) {
				$ready[] = $item;
			}
		}

		$active = array();
		foreach ( array_keys( (array) $state['active'] ) as $id ) {
			$optimization = $registry->get( (string) $id );
			if ( null !== $optimization ) {
				$active[] = array(
					'id'       => $optimization->id(),
					'name'     => $optimization->name(),
					'category' => $optimization->category(),
				);
			}
		}

		$conflicts = array();
		foreach ( (array) ( $scan['profile']['conflicts'] ?? array() ) as $conflict ) {
			$conflicts[] = array(
				'name'     => (string) ( $conflict['name'] ?? '' ),
				'features' => array_values( (array) ( $conflict['features'] ?? array() ) ),
			);
		}

		$server  = (string) ( $scan['profile']['server']['software'] ?? '' );
		$notices = array();
		if ( Context::is_emergency_safe_mode() ) {
			$notices[] = array(
				'type' => 'error',
				'text' => __( 'Emergency Safe Mode is active (SHSO_SAFE_MODE in wp-config.php). All optimizations are bypassed.', 'sh-speed-optimizer' ),
			);
		}

		$job = $this->plugin->jobs()->current();

		return array(
			'health'              => $health,
			'onboarding'          => (string) $state['onboarding'],
			'safe_mode'           => (bool) $this->plugin->settings()->get( 'safe_mode' ),
			'emergency_safe_mode' => Context::is_emergency_safe_mode(),
			'auto_optimize'       => (bool) $this->plugin->settings()->get( 'auto_optimize' ),
			'last_scan'           => array(
				'at'    => $state['last_scan_at'] ? (int) $state['last_scan_at'] : null,
				'human' => $state['last_scan_at'] ? SnapshotManager::human_time( (int) $state['last_scan_at'] ) : null,
			),
			'last_optimize'       => array(
				'at'    => $state['last_optimize_at'] ? (int) $state['last_optimize_at'] : null,
				'human' => $state['last_optimize_at'] ? SnapshotManager::human_time( (int) $state['last_optimize_at'] ) : null,
			),
			'ready'               => array(
				'count' => count( $ready ),
				'items' => $ready,
			),
			'active'              => array(
				'count' => count( $active ),
				'items' => $active,
			),
			'cwv'                 => $this->plugin->metrics()->core_web_vitals(),
			'cache'               => $this->cache_summary(),
			'conflicts'           => $conflicts,
			'permissions'         => array(
				'server_config_available' => in_array( $server, array( 'apache', 'litespeed', 'openlitespeed' ), true ) && ! empty( $scan['profile']['server']['htaccess_writable'] ) && empty( $scan['profile']['browser_cache']['configured'] ),
				'server_config_allowed'   => (bool) $this->plugin->settings()->get( 'allow_server_config' ),
			),
			'job'                 => null !== $job && ( $job->is_active() || time() - (int) $job->to_array()['updated'] < 120 ) ? $job->to_public() : null,
			'issues'              => array( 'count' => (int) ( $health['improvements'] ?? 0 ) ),
			'notices'             => $notices,
		);
	}

	/**
	 * Compact cache indicators for the dashboard.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function cache_summary(): array {
		$status = array();
		try {
			$status = $this->plugin->cache()->status();
		} catch ( \Throwable $e ) {
			$status = array();
		}

		$page = (array) ( $status['page_cache'] ?? array() );
		if ( ! empty( $page['handled_by'] ) ) {
			$page_out = array(
				'state'  => 'external',
				'label'  => __( 'Handled elsewhere', 'sh-speed-optimizer' ),
				/* translators: %s: plugin or host name */
				'detail' => sprintf( __( 'By %s', 'sh-speed-optimizer' ), (string) $page['handled_by'] ),
			);
		} elseif ( ! empty( $page['active'] ) ) {
			$page_out = array(
				'state'  => 'fallback' === ( $page['mode'] ?? '' ) ? 'fallback' : 'active',
				'label'  => __( 'Active', 'sh-speed-optimizer' ),
				'detail' => 'fallback' === ( $page['mode'] ?? '' ) ? __( 'Standard delivery', 'sh-speed-optimizer' ) : __( 'Fast delivery', 'sh-speed-optimizer' ),
			);
		} else {
			$page_out = array(
				'state'  => 'inactive',
				'label'  => __( 'Off', 'sh-speed-optimizer' ),
				'detail' => '',
			);
		}

		$browser = (array) ( $status['browser_cache'] ?? array() );
		if ( ! empty( $browser['active'] ) || ! empty( $browser['configured_by_server'] ) ) {
			$browser_out = array(
				'state'  => 'active',
				'label'  => __( 'Active', 'sh-speed-optimizer' ),
				'detail' => ! empty( $browser['configured_by_server'] ) ? __( 'Configured by your server', 'sh-speed-optimizer' ) : __( 'Rules added by SH Speed', 'sh-speed-optimizer' ),
			);
		} elseif ( null === ( $browser['configured_by_server'] ?? null ) && empty( $browser ) ) {
			$browser_out = array(
				'state'  => 'unknown',
				'label'  => __( 'Unknown', 'sh-speed-optimizer' ),
				'detail' => __( 'Run a scan', 'sh-speed-optimizer' ),
			);
		} else {
			$browser_out = array(
				'state'  => 'inactive',
				'label'  => __( 'Not configured', 'sh-speed-optimizer' ),
				'detail' => '',
			);
		}

		$object     = (array) ( $status['object_cache'] ?? array() );
		$object_out = ! empty( $object['active'] ) ? array(
			'state'  => 'active',
			'label'  => __( 'Active', 'sh-speed-optimizer' ),
			'detail' => (string) ( $object['type'] ?? $object['dropin'] ?? '' ),
		) : array(
			'state'  => 'inactive',
			'label'  => __( 'Not available', 'sh-speed-optimizer' ),
			'detail' => '',
		);

		return array(
			'page'    => $page_out,
			'browser' => $browser_out,
			'object'  => $object_out,
		);
	}

	// ------------------------------------------------------------------
	// Jobs.
	// ------------------------------------------------------------------

	/**
	 * POST /jobs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_job( WP_REST_Request $request ) {
		$type = (string) $request['type'];
		$raw  = (array) $request['args'];
		$args = array( 'browser' => ! empty( $raw['browser'] ) );

		if ( Context::is_emergency_safe_mode() && 'db_clean' !== $type ) {
			return new WP_Error( 'shso_emergency', __( 'Emergency Safe Mode is active. Remove SHSO_SAFE_MODE from wp-config.php first.', 'sh-speed-optimizer' ), array( 'status' => 409 ) );
		}

		if ( 'db_clean' === $type ) {
			$args = array(
				'items'          => array_values( array_map( 'sanitize_key', (array) ( $raw['items'] ?? array() ) ) ),
				'keep_revisions' => max( 0, min( 50, (int) ( $raw['keep_revisions'] ?? 5 ) ) ),
			);
			if ( empty( $args['items'] ) ) {
				return new WP_Error( 'shso_nothing_selected', __( 'Select at least one item to clean.', 'sh-speed-optimizer' ), array( 'status' => 400 ) );
			}
		}

		$job = $this->plugin->jobs()->start( $type, $args );
		if ( is_wp_error( $job ) ) {
			$job->add_data( array( 'status' => 409 ) );
			return $job;
		}

		return rest_ensure_response( array( 'job' => $job->to_public() ) );
	}

	/**
	 * GET /jobs/current.
	 */
	public function current_job(): WP_REST_Response {
		$job = $this->plugin->jobs()->current();
		return rest_ensure_response( array( 'job' => null === $job ? null : $job->to_public() ) );
	}

	/**
	 * POST /jobs/current/step.
	 */
	public function step_job(): WP_REST_Response {
		$job = $this->plugin->jobs()->run( 8.0 );
		return rest_ensure_response( array( 'job' => null === $job ? null : $job->to_public() ) );
	}

	/**
	 * POST /jobs/current/browser.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function browser_results( WP_REST_Request $request ) {
		$results = (array) $request['results'];
		$encoded = (string) wp_json_encode( $results );
		if ( strlen( $encoded ) > 2 * MB_IN_BYTES ) {
			return new WP_Error( 'shso_too_large', __( 'The browser results are too large.', 'sh-speed-optimizer' ), array( 'status' => 413 ) );
		}

		$job = $this->plugin->jobs()->receive_browser( sanitize_text_field( (string) $request['job_id'] ), self::clean_probe_results( $results ) );
		if ( is_wp_error( $job ) ) {
			$job->add_data( array( 'status' => 409 ) );
			return $job;
		}
		return rest_ensure_response( array( 'job' => $job->to_public() ) );
	}

	/**
	 * POST /jobs/current/cancel.
	 */
	public function cancel_job(): WP_REST_Response {
		$job = $this->plugin->jobs()->cancel();
		return rest_ensure_response( array( 'job' => null === $job ? null : $job->to_public() ) );
	}

	/**
	 * Sanitize browser probe results: keep the known structure, cast scalars, bound sizes.
	 *
	 * @param array<string,mixed> $results Raw results keyed by plan key.
	 * @return array<string,mixed>
	 */
	public static function clean_probe_results( array $results ): array {
		if ( ! empty( $results['_unavailable'] ) ) {
			return array( '_unavailable' => true );
		}

		$clean = array();
		foreach ( array_slice( $results, 0, 40, true ) as $key => $result ) {
			$key = preg_replace( '/[^a-z0-9:_\-]/i', '', (string) $key );
			if ( '' === $key || ! is_array( $result ) ) {
				continue;
			}
			// Critical CSS must reach CriticalCssOptimization::store() intact (it sanitizes the
			// CSS itself); sanitize_text_field() and the 600 character cap would corrupt it.
			$css = null;
			if ( isset( $result['critical_css'] ) && is_array( $result['critical_css'] ) && isset( $result['critical_css']['css'] ) && is_string( $result['critical_css']['css'] ) ) {
				$css = $result['critical_css']['css'];
				unset( $result['critical_css']['css'] );
			}
			$clean[ $key ] = self::clean_value( $result, 0 );
			if ( null !== $css && is_array( $clean[ $key ]['critical_css'] ?? null ) ) {
				$clean[ $key ]['critical_css']['css'] = strlen( $css ) <= self::MAX_CRITICAL_CSS_BYTES ? str_replace( "\0", '', $css ) : '';
			}
		}
		return $clean;
	}

	/**
	 * Recursively clean a probe value.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @return mixed
	 */
	private static function clean_value( $value, int $depth ) {
		if ( $depth > 5 ) {
			return null;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( array_slice( $value, 0, 200, true ) as $k => $v ) {
				$k         = is_int( $k ) ? $k : substr( preg_replace( '/[^A-Za-z0-9_\-\.\[\]=":#> ]/', '', (string) $k ), 0, 100 );
				$out[ $k ] = self::clean_value( $v, $depth + 1 );
			}
			return $out;
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return $value + 0;
		}
		return mb_substr( sanitize_text_field( (string) $value ), 0, 600 );
	}

	// ------------------------------------------------------------------
	// Optimizations.
	// ------------------------------------------------------------------

	/**
	 * GET /optimizations.
	 */
	public function optimizations(): WP_REST_Response {
		$scan      = Scanner::last();
		$decisions = (array) ( $scan['decisions'] ?? array() );
		$state     = $this->plugin->state()->all();
		$overrides = (array) $this->plugin->settings()->get( 'overrides', array() );
		$advanced  = (bool) $this->plugin->settings()->get( 'advanced_optimizations' );
		$groups    = array();

		foreach ( $this->plugin->registry()->by_category() as $category => $optimizations ) {
			$items  = array();
			$counts = array(
				'active'    => 0,
				'attention' => 0,
				'handled'   => 0,
				'total'     => 0,
			);
			foreach ( $optimizations as $id => $optimization ) {
				$experimental = Risk::LEVEL_EXPERIMENTAL === $optimization->level();
				if ( $experimental && ! $advanced && ! isset( $state['active'][ $id ] ) ) {
					continue;
				}

				$entry    = $decisions[ $id ] ?? null;
				$decision = $entry['decision'] ?? null;
				$assess   = $entry['assessment'] ?? null;

				if ( isset( $state['active'][ $id ] ) ) {
					$item_state = 'active';
				} elseif ( isset( $state['disabled'][ $id ] ) ) {
					$item_state = 'disabled';
				} elseif ( ! empty( $assess['handled_by'] ) ) {
					$item_state = 'handled';
				} elseif ( is_array( $assess ) && empty( $assess['applicable'] ) ) {
					$item_state = 'not_applicable';
				} else {
					$item_state = 'inactive';
				}

				if ( isset( $state['disabled'][ $id ] ) && is_array( $decision ) ) {
					$decision['summary'] = sprintf(
						/* translators: %s: reason */
						__( 'Rolled back: %s', 'sh-speed-optimizer' ),
						(string) $state['disabled'][ $id ]['reason']
					);
				}

				++$counts['total'];
				if ( 'active' === $item_state ) {
					++$counts['active'];
				} elseif ( 'handled' === $item_state ) {
					++$counts['handled'];
				} elseif ( 'inactive' === $item_state && is_array( $decision ) && in_array( $decision['benefit'] ?? '', array( 'medium', 'high' ), true ) && Decision::SKIP !== ( $decision['action'] ?? '' ) ) {
					++$counts['attention'];
				}

				$details = array();
				try {
					$details = 'active' === $item_state ? $optimization->details() : array();
				} catch ( \Throwable $e ) {
					$details = array();
				}

				$items[] = array(
					'id'              => $id,
					'name'            => $optimization->name(),
					'description'     => $optimization->description(),
					'category'        => $category,
					'risk'            => $optimization->risk(),
					'risk_label'      => Risk::label( $optimization->risk() ),
					'level'           => $optimization->level(),
					'level_label'     => Risk::level_label( $optimization->level() ),
					'reversible'      => $optimization->is_reversible(),
					'state'           => $item_state,
					'source'          => $state['active'][ $id ]['source'] ?? null,
					'verified'        => $state['active'][ $id ]['verified'] ?? null,
					'since'           => isset( $state['active'][ $id ]['since'] ) ? (int) $state['active'][ $id ]['since'] : null,
					'override'        => $overrides[ $id ] ?? null,
					'decision'        => $decision,
					'handled_by'      => $assess['handled_by'] ?? null,
					'details'         => $details,
					'requirements'    => $optimization->requirements(),
					'experimental'    => $experimental,
					'page_exclusions' => $this->plugin->state()->page_exclusions( $id ),
				);
			}

			if ( empty( $items ) ) {
				continue;
			}

			if ( $counts['active'] > 0 && 0 === $counts['attention'] ) {
				$status = 'optimized';
				$label  = __( 'Optimized', 'sh-speed-optimizer' );
			} elseif ( $counts['attention'] > 0 ) {
				$status = 'attention';
				$label  = __( 'Needs attention', 'sh-speed-optimizer' );
			} elseif ( $counts['handled'] > 0 && 0 === $counts['active'] ) {
				$status = 'handled';
				$label  = __( 'Handled by another plugin', 'sh-speed-optimizer' );
			} elseif ( $counts['active'] > 0 ) {
				$status = 'partial';
				$label  = __( 'Safe optimizations active', 'sh-speed-optimizer' );
			} else {
				$status = 'none';
				$label  = empty( $scan ) ? __( 'Not analyzed yet', 'sh-speed-optimizer' ) : __( 'Nothing to do', 'sh-speed-optimizer' );
			}

			$groups[] = array(
				'id'           => $category,
				'label'        => Category::label( $category ),
				'status'       => $status,
				'status_label' => $label,
				/* translators: 1: active count, 2: total count */
				'summary'      => sprintf( __( '%1$d of %2$d optimizations active', 'sh-speed-optimizer' ), $counts['active'], $counts['total'] ),
				'items'        => $items,
			);
		}

		return rest_ensure_response(
			array(
				'advanced'   => $advanced,
				'categories' => $groups,
			)
		);
	}

	/**
	 * POST /optimizations/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_optimization( WP_REST_Request $request ) {
		$id   = sanitize_key( (string) $request['id'] );
		$mode = (string) $request['mode'];

		if ( ! $this->plugin->registry()->has( $id ) ) {
			return new WP_Error( 'shso_unknown', __( 'Unknown optimization.', 'sh-speed-optimizer' ), array( 'status' => 404 ) );
		}

		$engine = $this->plugin->engine();

		if ( 'off' === $mode ) {
			$result = $engine->deactivate_manual( $id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return rest_ensure_response(
				array(
					'ok'      => true,
					'message' => __( 'Turned off.', 'sh-speed-optimizer' ),
				)
			);
		}

		if ( 'auto' === $mode ) {
			$engine->set_auto( $id );
			return rest_ensure_response(
				array(
					'ok'      => true,
					'message' => __( 'Back to automatic. It will be considered in the next optimization run.', 'sh-speed-optimizer' ),
				)
			);
		}

		if ( Context::is_emergency_safe_mode() ) {
			return new WP_Error( 'shso_emergency', __( 'Emergency Safe Mode is active.', 'sh-speed-optimizer' ), array( 'status' => 409 ) );
		}

		$optimization = $this->plugin->registry()->get( $id );
		if ( null !== $optimization && Risk::LEVEL_EXPERIMENTAL === $optimization->level() && ! $this->plugin->settings()->get( 'advanced_optimizations' ) ) {
			return new WP_Error( 'shso_experimental', __( 'Enable Advanced Optimizations in Settings to use experimental optimizations.', 'sh-speed-optimizer' ), array( 'status' => 403 ) );
		}

		$engine->set_override( $id, 'on' );
		$this->plugin->state()->clear_disabled( $id );

		if ( $this->plugin->state()->is_active( $id ) ) {
			return rest_ensure_response(
				array(
					'ok'      => true,
					'message' => __( 'Already active.', 'sh-speed-optimizer' ),
				)
			);
		}

		$job = $this->plugin->jobs()->start(
			'optimize',
			array(
				'only'    => array( $id ),
				'manual'  => true,
				'browser' => true,
			)
		);
		if ( is_wp_error( $job ) ) {
			$job->add_data( array( 'status' => 409 ) );
			return $job;
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'message' => __( 'Testing the optimization before it is kept…', 'sh-speed-optimizer' ),
				'job'     => $job->to_public(),
			)
		);
	}

	// ------------------------------------------------------------------
	// History & snapshots.
	// ------------------------------------------------------------------

	/**
	 * GET /history.
	 */
	public function history(): WP_REST_Response {
		$entries = array();
		foreach ( $this->plugin->logger()->entries( 'event', 150 ) as $row ) {
			$time      = (int) strtotime( (string) $row['created_at'] . ' UTC' );
			$entries[] = array(
				'id'              => (int) $row['id'],
				'time'            => $time,
				'day_label'       => SnapshotManager::day_label( $time ),
				'time_label'      => wp_date( (string) get_option( 'time_format', 'g:i a' ), $time ),
				'event'           => (string) $row['event'],
				'message'         => (string) $row['message'],
				'optimization_id' => (string) $row['optimization_id'],
				'level'           => (string) $row['level'],
			);
		}

		return rest_ensure_response(
			array(
				'entries'   => $entries,
				'snapshots' => $this->plugin->snapshots()->public_list(),
			)
		);
	}

	/**
	 * POST /undo.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function undo() {
		$result = $this->plugin->snapshots()->undo();
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 409 ) );
			return $result;
		}
		$this->plugin->engine()->refresh_report();
		return rest_ensure_response(
			array(
				'ok'      => true,
				'message' => __( 'The last change was undone.', 'sh-speed-optimizer' ),
			)
		);
	}

	/**
	 * POST /snapshots/{id}/restore.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore_snapshot( WP_REST_Request $request ) {
		$result = $this->plugin->snapshots()->restore( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 404 ) );
			return $result;
		}
		$this->plugin->engine()->refresh_report();
		return rest_ensure_response(
			array(
				'ok'      => true,
				'message' => __( 'The earlier configuration was restored.', 'sh-speed-optimizer' ),
			)
		);
	}

	// ------------------------------------------------------------------
	// Diagnostics.
	// ------------------------------------------------------------------

	/**
	 * GET /diagnostics.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function diagnostics( WP_REST_Request $request ): WP_REST_Response {
		$scan     = Scanner::last();
		$findings = (array) ( $scan['findings'] ?? array() );

		$pages = array();
		foreach ( (array) ( $scan['pages'] ?? array() ) as $url => $page ) {
			$pages[] = array(
				'url'           => (string) $url,
				'template'      => (string) ( $page['template'] ?? '' ),
				'status'        => (int) ( $page['status'] ?? 0 ),
				'ttfb_ms'       => isset( $page['ttfb_ms'] ) ? (int) $page['ttfb_ms'] : null,
				'generation_ms' => isset( $page['generation_ms'] ) ? (int) $page['generation_ms'] : null,
				'html_bytes'    => isset( $page['html_bytes'] ) ? (int) $page['html_bytes'] : null,
				'requests'      => (array) ( $page['requests'] ?? array() ),
				'bytes'         => (array) ( $page['bytes'] ?? array() ),
			);
		}

		$key    = (string) $this->plugin->settings()->get( 'psi_api_key', '' );
		$latest = PageSpeed::latest();
		if ( null !== $latest ) {
			$latest['human'] = SnapshotManager::human_time( (int) $latest['fetched_at'] );
			foreach ( array( 'field', 'lab' ) as $group ) {
				if ( ! is_array( $latest[ $group ] ?? null ) ) {
					continue;
				}
				foreach ( $latest[ $group ] as $metric => $value ) {
					$format                      = 'tbt' === $metric ? 'inp' : (string) $metric;
					$latest[ $group ][ $metric ] = \SH\SpeedOptimizer\Diagnostics\Metrics::format( $format, null === $value ? null : (float) $value );
				}
			}
		}
		$pagespeed = array(
			'configured'   => '' !== $key,
			'latest'       => $latest,
			'before_after' => PageSpeed::before_after( (int) $this->plugin->state()->get( 'last_optimize_at' ) ),
		);

		$response = array(
			'health'    => $scan['health'] ?? HealthScore::calculate( array() ),
			'report'    => FindingsBuilder::report( $findings ),
			'findings'  => $findings,
			'scan'      => array(
				'at'    => isset( $scan['at'] ) ? (int) $scan['at'] : null,
				'human' => isset( $scan['at'] ) ? SnapshotManager::human_time( (int) $scan['at'] ) : null,
				'pages' => $pages,
			),
			'pagespeed' => $pagespeed,
		);

		if ( $request['developer'] ) {
			$response['developer'] = $this->developer_diagnostics( $scan );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Developer diagnostics (admin only).
	 *
	 * @param array<string,mixed> $scan Last scan.
	 * @return array<string,mixed>
	 */
	private function developer_diagnostics( array $scan ): array {
		$assets  = array();
		$queries = array();
		foreach ( (array) ( $scan['pages'] ?? array() ) as $url => $page ) {
			$assets[ $url ]  = array(
				'scripts' => (array) ( $page['scripts'] ?? array() ),
				'styles'  => (array) ( $page['styles'] ?? array() ),
			);
			$queries[ $url ] = $page['queries'] ?? null;
		}

		$cache = array();
		try {
			$cache = $this->plugin->cache()->status();
		} catch ( \Throwable $e ) {
			$cache = array( 'error' => $e->getMessage() );
		}

		return array(
			'environment'   => (array) ( $scan['profile'] ?? array() ),
			'compatibility' => $this->plugin->compatibility()->diagnostics(),
			'decisions'     => (array) ( $scan['decisions'] ?? array() ),
			'assets'        => $assets,
			'queries'       => $queries,
			'cache'         => $cache,
			'state'         => $this->plugin->state()->all(),
			'page_data'     => get_option( \SH\SpeedOptimizer\Optimization\Runtime::PAGE_DATA_OPTION, array() ),
			'log'           => $this->plugin->logger()->is_debug() ? $this->plugin->logger()->entries( 'debug', 200 ) : array(),
			'constants'     => array(
				'SHSO_SAFE_MODE' => Context::is_emergency_safe_mode(),
				'SHSO_DEBUG'     => defined( 'SHSO_DEBUG' ) && SHSO_DEBUG,
				'WP_CACHE'       => defined( 'WP_CACHE' ) && WP_CACHE,
			),
		);
	}

	// ------------------------------------------------------------------
	// Cache.
	// ------------------------------------------------------------------

	/**
	 * GET /cache.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function cache() {
		try {
			$status = $this->plugin->cache()->status();
			$stats  = $this->plugin->cache()->stats();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'shso_cache', $e->getMessage(), array( 'status' => 500 ) );
		}

		$scan   = Scanner::last();
		$server = (string) ( $scan['profile']['server']['software'] ?? '' );

		$status['browser_cache']['can_apply']     = in_array( $server, array( 'apache', 'litespeed', 'openlitespeed' ), true ) && empty( $status['browser_cache']['configured_by_server'] ) && empty( $status['browser_cache']['active'] );
		$status['browser_cache']['nginx_snippet'] = 'nginx' === $server && class_exists( '\SH\SpeedOptimizer\Modules\BrowserCache\HtaccessRules' ) ? \SH\SpeedOptimizer\Modules\BrowserCache\HtaccessRules::nginx_snippet() : null;
		$status['browser_cache']['server']        = $server;

		if ( empty( $status['object_cache']['active'] ) ) {
			$status['object_cache']['recommendation'] = extension_loaded( 'redis' )
				? __( 'The Redis PHP extension is installed. If your host provides a Redis server, an object cache plugin can use it to reduce database load.', 'sh-speed-optimizer' )
				: __( 'Ask your host whether Redis or Memcached is available. An object cache mainly helps logged-in users, carts and the dashboard.', 'sh-speed-optimizer' );
		} else {
			$status['object_cache']['recommendation'] = '';
		}

		$status['stats'] = $stats;
		return rest_ensure_response( $status );
	}

	/**
	 * POST /cache/purge.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function purge( WP_REST_Request $request ) {
		$cache = $this->plugin->cache();

		if ( 'url' === $request['scope'] ) {
			$url = esc_url_raw( (string) $request['url'] );
			if ( '' === $url && str_starts_with( (string) $request['url'], '/' ) ) {
				$url = home_url( (string) $request['url'] );
			}
			if ( '' === $url || ! Loopback::is_own_url( $url ) ) {
				return new WP_Error( 'shso_bad_url', __( 'Please enter an address of this site.', 'sh-speed-optimizer' ), array( 'status' => 400 ) );
			}
			$count = $cache->purge_url( $url );
		} else {
			if ( ! Capabilities::can_manage() && ! current_user_can( 'edit_others_posts' ) ) {
				return new WP_Error( 'shso_forbidden', __( 'You are not allowed to do this.', 'sh-speed-optimizer' ), array( 'status' => 403 ) );
			}
			$count = $cache->purge_all( 'manual' );
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'purged'  => $count,
				/* translators: %d: number of files */
				'message' => sprintf( _n( 'Cache cleared (%d file removed).', 'Cache cleared (%d files removed).', $count, 'sh-speed-optimizer' ), $count ),
			)
		);
	}

	/**
	 * POST /cache/preload.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function preload() {
		if ( ! class_exists( '\SH\SpeedOptimizer\Cache\Preloader' ) ) {
			return new WP_Error( 'shso_unavailable', __( 'Preloading is not available.', 'sh-speed-optimizer' ), array( 'status' => 500 ) );
		}
		$preloader = new \SH\SpeedOptimizer\Cache\Preloader( $this->plugin );
		$preloader->schedule( true );
		return rest_ensure_response(
			array(
				'ok'      => true,
				'message' => __( 'Important pages will be cached in the background.', 'sh-speed-optimizer' ),
			)
		);
	}

	/**
	 * POST /cache/enable-early-delivery.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function enable_early_delivery() {
		if ( ! class_exists( '\SH\SpeedOptimizer\Cache\Dropin' ) ) {
			return new WP_Error( 'shso_unavailable', __( 'Not available.', 'sh-speed-optimizer' ), array( 'status' => 500 ) );
		}
		$blocked = Capabilities::server_files_blocked_reason();
		if ( null !== $blocked ) {
			return new WP_Error( 'shso_server_files', $blocked, array( 'status' => 403 ) );
		}
		if ( ! $this->plugin->state()->is_active( 'page_cache' ) ) {
			return new WP_Error( 'shso_cache_off', __( 'Page caching is not active.', 'sh-speed-optimizer' ), array( 'status' => 409 ) );
		}

		$this->plugin->snapshots()->create( __( 'Before enabling faster cache delivery', 'sh-speed-optimizer' ), 'manual' );

		if ( ! ( defined( 'WP_CACHE' ) && WP_CACHE ) ) {
			$result = \SH\SpeedOptimizer\Cache\Dropin::enable_wp_cache_constant();
			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'status' => 409 ) );
				return $result;
			}
		}
		$result = \SH\SpeedOptimizer\Cache\Dropin::install();
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 409 ) );
			return $result;
		}

		$this->plugin->cache()->write_config();
		$this->plugin->logger()->event( 'manual_on', __( 'Faster cache delivery enabled (WP_CACHE and advanced-cache.php).', 'sh-speed-optimizer' ), 'page_cache' );

		// Make sure the site still loads.
		$check = Loopback::get( home_url( '/' ) );
		if ( ! $check['ok'] || $check['status'] >= 500 ) {
			\SH\SpeedOptimizer\Cache\Dropin::uninstall();
			\SH\SpeedOptimizer\Cache\Dropin::disable_wp_cache_constant();
			return new WP_Error( 'shso_early_failed', __( 'Your site did not respond correctly afterwards, so the change was reverted.', 'sh-speed-optimizer' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'message' => __( 'Faster cache delivery is enabled.', 'sh-speed-optimizer' ),
			)
		);
	}

	// ------------------------------------------------------------------
	// Settings.
	// ------------------------------------------------------------------

	/**
	 * GET /settings.
	 */
	public function get_settings(): WP_REST_Response {
		return rest_ensure_response( $this->settings_payload() );
	}

	/**
	 * Settings payload (API key never returned).
	 *
	 * @return array<string,mixed>
	 */
	private function settings_payload(): array {
		$settings = $this->plugin->settings()->all();
		$has_key  = '' !== (string) $settings['psi_api_key'];
		unset( $settings['psi_api_key'], $settings['overrides'] );

		foreach ( array( 'exclude_urls', 'exclude_css', 'exclude_js', 'exclude_cookies' ) as $key ) {
			$settings[ $key ] = implode( "\n", (array) $settings[ $key ] );
		}

		$defaults = Settings::defaults();
		unset( $defaults['psi_api_key'], $defaults['overrides'] );

		$scan = Scanner::last();

		return array(
			'settings' => $settings,
			'locked'   => $this->plugin->settings()->network_defaults()['locked'],
			'defaults' => $defaults,
			'meta'     => array(
				'psi_api_key_set' => $has_key,
				'server'          => (string) ( $scan['profile']['server']['software'] ?? '' ),
				'network'         => is_multisite(),
				// Why server files may not be changed by this user ('' when they may).
				'server_files'    => (string) Capabilities::server_files_blocked_reason(),
			),
		);
	}

	/**
	 * POST /settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_settings( WP_REST_Request $request ) {
		$input   = (array) $request['settings'];
		$allowed = array_keys( Settings::defaults() );
		$locked  = $this->plugin->settings()->network_defaults()['locked'];
		$changes = array();

		foreach ( $input as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) || in_array( $key, $locked, true ) || 'overrides' === $key ) {
				continue;
			}
			if ( 'psi_api_key' === $key && '' === (string) $value ) {
				continue; // Empty means "unchanged"; use clear_psi_api_key to remove.
			}
			if ( 'allow_server_config' === $key && ! empty( $value ) && empty( $this->plugin->settings()->get( 'allow_server_config' ) ) ) {
				$blocked = Capabilities::server_files_blocked_reason();
				if ( null !== $blocked ) {
					return new WP_Error( 'shso_server_files', $blocked, array( 'status' => 403 ) );
				}
			}
			$changes[ $key ] = $value;
		}
		if ( $request['clear_psi_api_key'] ) {
			$changes['psi_api_key'] = '';
		}

		if ( ! empty( $changes ) ) {
			$previous = $this->plugin->settings()->all();
			$this->plugin->settings()->update( $changes );
			$this->plugin->logger()->debug( 'Settings updated.', array( 'keys' => array_keys( $changes ) ), 'settings' );

			// Turning page caching off removes it right away (the user's explicit wish).
			if ( ! empty( $previous['page_cache'] ) && empty( $this->plugin->settings()->get( 'page_cache' ) ) && $this->plugin->state()->is_active( 'page_cache' ) ) {
				$this->plugin->engine()->rollback( 'page_cache', __( 'Page cache turned off in Settings.', 'sh-speed-optimizer' ), 'manual_off', false, null );
			}
		}

		return rest_ensure_response( $this->settings_payload() );
	}

	/**
	 * POST /safe-mode.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function safe_mode( WP_REST_Request $request ) {
		if ( $this->plugin->settings()->is_locked( 'safe_mode' ) ) {
			return new WP_Error( 'shso_locked', __( 'This setting is managed by your network administrator.', 'sh-speed-optimizer' ), array( 'status' => 403 ) );
		}
		$enabled = (bool) $request['enabled'];
		$this->plugin->settings()->update( array( 'safe_mode' => $enabled ) );
		$this->plugin->logger()->event( $enabled ? 'safe_mode_on' : 'safe_mode_off', $enabled ? __( 'Safe Mode turned on.', 'sh-speed-optimizer' ) : __( 'Safe Mode turned off.', 'sh-speed-optimizer' ) );

		return rest_ensure_response(
			array(
				'ok'        => true,
				'safe_mode' => $enabled,
			)
		);
	}

	// ------------------------------------------------------------------
	// Database.
	// ------------------------------------------------------------------

	/**
	 * GET /database.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function database() {
		try {
			$service  = $this->plugin->database();
			$analysis = $service->analyze();
			$backups  = $service->backups();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'shso_db', __( 'The database could not be analyzed.', 'sh-speed-optimizer' ), array( 'status' => 500 ) );
		}

		foreach ( $backups as &$backup ) {
			$backup['created_label'] = SnapshotManager::human_time( (int) ( $backup['created'] ?? 0 ) );
		}

		return rest_ensure_response(
			array(
				'analysis' => $analysis,
				'findings' => $service->findings( $analysis ),
				'backups'  => $backups,
			)
		);
	}

	/**
	 * POST /database/restore.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function database_restore( WP_REST_Request $request ) {
		$result = $this->plugin->database()->restore( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $request['id'] ) );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}
		return rest_ensure_response(
			array(
				'ok'       => true,
				'message'  => __( 'The backup was restored.', 'sh-speed-optimizer' ),
				'restored' => $result,
			)
		);
	}

	/**
	 * DELETE /database/backups/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function database_delete_backup( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( array( 'ok' => $this->plugin->database()->delete_backup( (string) $request['id'] ) ) );
	}

	// ------------------------------------------------------------------
	// PageSpeed & RUM.
	// ------------------------------------------------------------------

	/**
	 * POST /pagespeed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pagespeed( WP_REST_Request $request ) {
		$result = PageSpeed::run( home_url( '/' ), (string) $request['strategy'], (string) $this->plugin->settings()->get( 'psi_api_key', '' ) );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 502 ) );
			return $result;
		}

		if ( is_array( $result['field'] ) ) {
			foreach ( $result['field'] as $metric => $value ) {
				if ( null !== $value ) {
					$this->plugin->metrics()->record( 'psi', (string) $metric, (float) $value, 'front_page' );
				}
			}
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'message' => __( 'PageSpeed Insights test finished.', 'sh-speed-optimizer' ),
				'result'  => $result,
			)
		);
	}

	/**
	 * POST /rum (public).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function rum( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->plugin->settings()->get( 'rum' ) ) {
			return new WP_REST_Response( null, 404 );
		}

		$body = (string) $request->get_body();
		if ( strlen( $body ) > 2048 || ! Rum::allow() ) {
			return new WP_REST_Response( null, 204 );
		}

		$data = json_decode( $body, true );
		if ( is_array( $data ) && isset( $data['m'] ) && is_array( $data['m'] ) ) {
			$this->plugin->metrics()->ingest_rum( $data['m'], (string) ( $data['t'] ?? '' ) );
		}

		return new WP_REST_Response( null, 204 );
	}
}
