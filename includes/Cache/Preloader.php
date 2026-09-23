<?php
/**
 * Background cache preloading.
 *
 * After the cache was cleared (or first enabled) the most visited entry
 * points are requested in the background so real visitors get cached pages:
 * home, menu items, the posts page, the latest posts/pages and the most
 * popular WooCommerce products. Five URLs per run, sequentially, with a pause
 * between runs; it stops and retries later when the site answers with errors.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Cache;

use SH\SpeedOptimizer\Core\Context;
use SH\SpeedOptimizer\Core\Plugin;
use SH\SpeedOptimizer\Core\Scheduler;
use SH\SpeedOptimizer\Diagnostics\Loopback;

defined( 'ABSPATH' ) || exit;

/**
 * Preloader.
 */
final class Preloader {

	public const HOOK        = 'shso_preload_batch';
	public const OPTION      = 'shso_preload';
	public const LOCK        = 'shso_preload_lock';
	public const BATCH_SIZE  = 5;
	public const INTERVAL    = 15;
	public const RETRY_DELAY = 300;
	public const MAX_RETRIES = 5;
	public const MAX_QUEUE   = 500;

	public const USER_AGENT        = 'Mozilla/5.0 (compatible; SH Speed Optimizer Preloader)';
	public const MOBILE_USER_AGENT = 'Mozilla/5.0 (Linux; Android 14; Mobile) SH Speed Optimizer Preloader';

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Cache manager.
	 *
	 * @var CacheManager
	 */
	private CacheManager $cache;

	/**
	 * Constructor.
	 *
	 * @param Plugin       $plugin Plugin.
	 * @param CacheManager $cache  Cache manager.
	 */
	public function __construct( Plugin $plugin, CacheManager $cache ) {
		$this->plugin = $plugin;
		$this->cache  = $cache;
	}

	/**
	 * Register the background hook.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Whether preloading may run.
	 */
	public function enabled(): bool {
		$settings = $this->plugin->settings();
		return ! Context::is_emergency_safe_mode()
			&& (bool) $settings->get( 'preload', true )
			&& (int) $settings->get( 'preload_limit', 50 ) > 0
			&& (bool) $settings->get( 'page_cache', true )
			&& $this->plugin->state()->is_active( 'page_cache' );
	}

	/**
	 * Start a full preload (the URL list is built in the background).
	 */
	public function schedule(): void {
		if ( ! $this->enabled() ) {
			return;
		}
		$state            = $this->state();
		$state['build']   = true;
		$state['queue']   = array();
		$state['done']    = 0;
		$state['running'] = true;
		$state['retries'] = 0;
		$this->save( $state );
		Scheduler::async( self::HOOK, array(), 10 );
	}

	/**
	 * Add URLs to the queue (e.g. after a post was purged).
	 *
	 * @param string[] $urls URLs.
	 */
	public function enqueue( array $urls ): void {
		if ( ! $this->enabled() || empty( $urls ) ) {
			return;
		}
		$state = $this->state();
		if ( ! $state['running'] ) {
			$state['done'] = 0;
		}
		$state['queue']   = array_slice( array_values( array_unique( array_merge( $state['queue'], array_map( 'strval', $urls ) ) ) ), 0, self::MAX_QUEUE );
		$state['running'] = true;
		$this->save( $state );
		Scheduler::async( self::HOOK, array(), self::INTERVAL );
	}

	/**
	 * Process one batch (background).
	 */
	public function run(): void {
		if ( ! $this->enabled() ) {
			$this->clear();
			return;
		}
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );

		try {
			$this->run_batch();
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/**
	 * Batch implementation.
	 */
	private function run_batch(): void {
		$state = $this->state();
		$limit = (int) $this->plugin->settings()->get( 'preload_limit', 50 );

		if ( ! empty( $state['build'] ) ) {
			$state['queue'] = array_merge( $this->collect(), $state['queue'] );
			$state['build'] = false;
		}

		$state['queue'] = self::filter_urls( $state['queue'], $this->cache->hosts(), array( $this->cache, 'is_cacheable_url' ), max( 0, $limit - (int) $state['done'] ) );
		$batch          = array_splice( $state['queue'], 0, self::BATCH_SIZE );
		$mobile         = ! empty( $this->cache->site_config()['mobile'] );

		foreach ( $batch as $index => $url ) {
			$response = Loopback::get(
				$url,
				array(
					'timeout' => 30,
					'headers' => array( 'User-Agent' => self::USER_AGENT ),
				)
			);

			if ( ! $response['ok'] || $response['status'] >= 500 ) {
				// The site is under stress or unreachable: stop now and retry later.
				array_splice( $state['queue'], 0, 0, array_slice( $batch, $index ) );
				$state['retries']  = (int) $state['retries'] + 1;
				$state['last_run'] = time();
				if ( $state['retries'] > self::MAX_RETRIES ) {
					$state['queue']   = array();
					$state['running'] = false;
				}
				$this->save( $state );
				if ( $state['running'] ) {
					Scheduler::async( self::HOOK, array(), self::RETRY_DELAY );
				}
				$this->plugin->logger()->debug(
					'Preloading paused: the site answered with an error.',
					array(
						'status' => (int) $response['status'],
						'url'    => $url,
					),
					'cache'
				);
				return;
			}

			if ( $mobile ) {
				Loopback::get(
					$url,
					array(
						'timeout' => 30,
						'headers' => array( 'User-Agent' => self::MOBILE_USER_AGENT ),
					)
				);
			}

			$state['done'] = (int) $state['done'] + 1;
		}

		$state['retries']  = 0;
		$state['last_run'] = time();
		$state['running']  = ! empty( $state['queue'] );
		$this->save( $state );

		if ( $state['running'] ) {
			Scheduler::async( self::HOOK, array(), self::INTERVAL );
		}
	}

	/**
	 * Candidate URLs.
	 *
	 * @return string[]
	 */
	private function collect(): array {
		$urls = array( home_url( '/' ) );

		foreach ( (array) get_nav_menu_locations() as $menu_id ) {
			$items = $menu_id ? wp_get_nav_menu_items( (int) $menu_id ) : array();
			foreach ( (array) $items as $item ) {
				if ( is_object( $item ) && ! empty( $item->url ) ) {
					$urls[] = (string) $item->url;
				}
			}
		}

		$posts_page = (int) get_option( 'page_for_posts' );
		if ( $posts_page > 0 && 'page' === get_option( 'show_on_front' ) ) {
			$urls[] = (string) get_permalink( $posts_page );
		}

		$recent = get_posts(
			array(
				'post_type'        => array( 'page', 'post' ),
				'post_status'      => 'publish',
				'numberposts'      => 10,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'has_password'     => false,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		foreach ( (array) $recent as $id ) {
			$urls[] = (string) get_permalink( (int) $id );
		}

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$urls[]   = (string) wc_get_page_permalink( 'shop' );
			$products = get_posts(
				array(
					'post_type'        => 'product',
					'post_status'      => 'publish',
					'numberposts'      => 10,
					'meta_key'         => 'total_sales', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Background job, 10 rows.
					'orderby'          => array(
						'meta_value_num' => 'DESC',
						'date'           => 'DESC',
					),
					'has_password'     => false,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			);
			foreach ( (array) $products as $id ) {
				$urls[] = (string) get_permalink( (int) $id );
			}
		}

		/**
		 * Filters the URLs the preloader warms after the cache was cleared.
		 *
		 * Only cacheable URLs of this site without query strings are kept, capped by the preload limit.
		 *
		 * @param string[] $urls URLs.
		 */
		return array_map( 'strval', (array) apply_filters( 'shso_preload_urls', $urls ) );
	}

	/**
	 * Keep only preloadable URLs (pure).
	 *
	 * @param array<int,mixed> $urls         Candidate URLs.
	 * @param string[]         $hosts        Allowed hosts.
	 * @param callable         $is_cacheable function( string $url ): bool.
	 * @param int              $limit        Maximum URLs.
	 * @return string[]
	 */
	public static function filter_urls( array $urls, array $hosts, callable $is_cacheable, int $limit ): array {
		$out  = array();
		$seen = array();

		foreach ( $urls as $url ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			if ( ! is_string( $url ) ) {
				continue;
			}
			$url      = trim( $url );
			$fragment = strpos( $url, '#' );
			if ( false !== $fragment ) {
				$url = substr( $url, 0, $fragment );
			}
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
				continue;
			}
			if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
				continue;
			}
			$host = strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
			if ( ! in_array( $host, $hosts, true ) ) {
				continue;
			}
			$path = isset( $parts['path'] ) && '' !== $parts['path'] ? (string) $parts['path'] : '/';
			if ( preg_match( '#(wp-login\.php|/wp-admin|logout|/feed/?$|/cart/?$|/checkout/?$|/my-account/?)#i', $path ) ) {
				continue;
			}
			$normalized = strtolower( (string) $parts['scheme'] ) . '://' . $host . $path;
			if ( isset( $seen[ $normalized ] ) ) {
				continue;
			}
			$seen[ $normalized ] = true;
			if ( ! $is_cacheable( $normalized ) ) {
				continue;
			}
			$out[] = $normalized;
		}

		return $out;
	}

	/**
	 * Progress for the dashboard.
	 *
	 * @return array{queued:int,done:int,running:bool,last_run:int}
	 */
	public function progress(): array {
		$state = $this->state();
		return array(
			'queued'   => count( $state['queue'] ),
			'done'     => (int) $state['done'],
			'running'  => (bool) $state['running'],
			'last_run' => (int) $state['last_run'],
		);
	}

	/**
	 * Stop and forget the queue.
	 */
	public function clear(): void {
		delete_option( self::OPTION );
		wp_clear_scheduled_hook( self::HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), Scheduler::GROUP );
		}
	}

	/**
	 * Stored state.
	 *
	 * @return array{queue:string[],done:int,running:bool,last_run:int,retries:int,build:bool}
	 */
	private function state(): array {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'queue'    => array_values( array_filter( (array) ( $raw['queue'] ?? array() ), 'is_string' ) ),
			'done'     => (int) ( $raw['done'] ?? 0 ),
			'running'  => ! empty( $raw['running'] ),
			'last_run' => (int) ( $raw['last_run'] ?? 0 ),
			'retries'  => (int) ( $raw['retries'] ?? 0 ),
			'build'    => ! empty( $raw['build'] ),
		);
	}

	/**
	 * Persist state (not autoloaded).
	 *
	 * @param array<string,mixed> $state State.
	 */
	private function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}
}
