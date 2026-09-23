<?php
/**
 * Page cache.
 *
 * Stores the finished HTML of anonymous page views and serves it to the next
 * visitors, either from the `advanced-cache.php` drop-in (before WordPress
 * loads) or, when WP_CACHE is off, from the plugin itself at `plugins_loaded`.
 * Logged-in users, carts, checkouts, account pages, previews, personal
 * content and anything unusual are never cached.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\PageCache;

use SH\SpeedOptimizer\Cache\CacheManager;
use SH\SpeedOptimizer\Cache\Capture;
use SH\SpeedOptimizer\Cache\Delivery;
use SH\SpeedOptimizer\Cache\Dropin;
use SH\SpeedOptimizer\Cache\Preloader;
use SH\SpeedOptimizer\Cache\Purger;
use SH\SpeedOptimizer\Core\Scheduler;
use SH\SpeedOptimizer\Diagnostics\Loopback;
use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Page cache optimization.
 */
final class PageCacheOptimization extends AbstractOptimization {

	/**
	 * Pages slower than this (ms) benefit a lot.
	 */
	private const SLOW_MS = 300;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'page_cache';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Page cache', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Keeps a ready-made copy of each page so visitors get it instantly instead of waiting for WordPress to build it. Logged-in users, carts, checkouts and personal pages are never cached.', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::CACHE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function risk(): string {
		return Risk::LOW;
	}

	/**
	 * {@inheritDoc}
	 */
	public function level(): string {
		return Risk::LEVEL_SAFE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function requirements(): array {
		return array( self::REQ_LOOPBACK );
	}

	/**
	 * The cache stores whatever WordPress renders; toggling Safe Mode purges it.
	 */
	public function safe_mode_compatible(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param AssessmentContext $context Scan data.
	 */
	public function assess( AssessmentContext $context ): Assessment {
		if ( ! $context->settings->get( 'page_cache', true ) ) {
			$assessment          = Assessment::make( false, 100, Assessment::BENEFIT_NONE );
			$assessment->blocked = __( 'Page cache is turned off in Settings.', 'sh-speed-optimizer' );
			$assessment->note( $assessment->blocked );
			return $assessment;
		}

		$assessment = Assessment::make( true, 90, Assessment::BENEFIT_MEDIUM );

		// Another advanced-cache.php (never overwritten).
		$owner    = $this->foreign_dropin_owner( $context );
		$wp_cache = (bool) $context->profile->get( 'cache.wp_cache_constant', Dropin::wp_cache_enabled() );
		if ( null !== $owner ) {
			if ( $wp_cache ) {
				$assessment->handled_by = 'unknown' === $owner ? __( 'Another page cache (advanced-cache.php)', 'sh-speed-optimizer' ) : $owner;
			} else {
				$assessment->note(
					sprintf(
						/* translators: %s: name of another cache plugin */
						__( 'An inactive advanced-cache.php file from %s exists. It is left untouched; the cache is served by the plugin itself.', 'sh-speed-optimizer' ),
						'unknown' === $owner ? __( 'another plugin', 'sh-speed-optimizer' ) : $owner
					)
				);
			}
		}
		$assessment->data['dropin_owner']      = $owner;
		$assessment->data['wp_cache_constant'] = $wp_cache;

		// Benefit from the measured generation time.
		$times = array();
		foreach ( $context->pages() as $page ) {
			$time = (int) ( $page['generation_ms'] ?? 0 );
			$time = $time > 0 ? $time : (int) ( $page['ttfb_ms'] ?? 0 );
			if ( $time > 0 ) {
				$times[] = $time;
			}
		}
		if ( ! empty( $times ) ) {
			$average                               = (int) round( array_sum( $times ) / count( $times ) );
			$assessment->data['avg_generation_ms'] = $average;
			$assessment->benefit                   = $average > self::SLOW_MS ? Assessment::BENEFIT_HIGH : Assessment::BENEFIT_MEDIUM;
			$assessment->note(
				sprintf(
					/* translators: %d: milliseconds */
					__( 'Your pages take about %d ms to build; cached pages are delivered in a few milliseconds.', 'sh-speed-optimizer' ),
					$average
				)
			);
		} else {
			$assessment->note( __( 'Page build times were not measured yet; the benefit is estimated.', 'sh-speed-optimizer' ) );
		}

		// Compatibility adjustments.
		if ( $context->profile->has_feature( 'woocommerce' ) ) {
			$address = (string) get_option( 'woocommerce_default_customer_address', '' );
			if ( 'geolocation' === $address ) {
				$assessment->penalize( 10, __( 'WooCommerce shows prices based on the visitor\'s country, so shop and product pages are never cached.', 'sh-speed-optimizer' ) );
			} elseif ( 'geolocation_ajax' === $address ) {
				$assessment->note( __( 'WooCommerce geolocation with page caching support detected: each location gets its own cached copy.', 'sh-speed-optimizer' ) );
			}
			$assessment->note( __( 'Cart, checkout and account pages are never cached.', 'sh-speed-optimizer' ) );
		}
		if ( $context->profile->has_feature( 'membership' ) || $context->profile->has_feature( 'lms' ) ) {
			$assessment->penalize( 5, __( 'Membership or course plugin detected: pages for logged-in members are never cached, so fewer pages benefit.', 'sh-speed-optimizer' ) );
		}
		if ( $context->profile->get( 'features.multilingual' ) && ! empty( $context->rules->get( 'cache_vary_cookies' ) ) ) {
			$assessment->penalize( 5, __( 'Your multilingual plugin remembers the language in a cookie; a separate copy is kept for each language.', 'sh-speed-optimizer' ) );
		}
		if ( false === $context->profile->get( 'wp.permalinks' ) ) {
			$assessment->note( __( 'Plain permalinks (?p=123) are in use; pages are cached per address.', 'sh-speed-optimizer' ) );
		}

		return $this->finalize( $assessment, $context, 'page_cache' );
	}

	/**
	 * Owner of an advanced-cache.php that is not ours, or null.
	 *
	 * @param AssessmentContext $context Context.
	 */
	private function foreign_dropin_owner( AssessmentContext $context ): ?string {
		$advanced = $context->profile->get( 'cache.advanced_cache' );
		if ( is_array( $advanced ) && array_key_exists( 'exists', $advanced ) ) {
			if ( empty( $advanced['exists'] ) || ! empty( $advanced['ours'] ) ) {
				return null;
			}
			$owner = (string) ( $advanced['owner'] ?? '' );
			return '' === $owner ? 'unknown' : $owner;
		}
		$owner = Dropin::owner();
		return null === $owner || Dropin::OWNER_SELF === $owner ? null : $owner;
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply() {
		if ( ! $this->plugin->settings()->get( 'page_cache', true ) ) {
			return new \WP_Error( 'shso_cache_disabled', __( 'Page cache is turned off in Settings.', 'sh-speed-optimizer' ) );
		}

		$cache   = $this->plugin->cache();
		$owner   = Dropin::owner();
		$foreign = null !== $owner && Dropin::OWNER_SELF !== $owner;

		if ( $foreign && Dropin::wp_cache_enabled() ) {
			return new \WP_Error(
				'shso_cache_foreign_dropin',
				sprintf(
					/* translators: %s: name of another cache plugin */
					__( '%s already handles page caching on this site, so SH Speed Optimizer does not add a second page cache.', 'sh-speed-optimizer' ),
					'unknown' === $owner ? __( 'Another page cache', 'sh-speed-optimizer' ) : $owner
				)
			);
		}

		if ( ! $cache->write_config() ) {
			return new \WP_Error( 'shso_cache_config', __( 'The page cache could not save its settings file. Please check that the wp-content/cache folder is writable.', 'sh-speed-optimizer' ) );
		}

		if ( Dropin::wp_cache_enabled() && ! $foreign ) {
			$installed = Dropin::install();
			if ( is_wp_error( $installed ) ) {
				// Not fatal: pages are served by the plugin itself (fallback mode).
				$this->plugin->logger()->debug( 'Cache drop-in not installed; using fallback delivery.', array( 'error' => $installed->get_error_message() ), 'cache' );
			}
		}

		$cache->preloader()->schedule( true );

		return true;
	}

	/**
	 * Own loopback checks (the generic snapshots use signed requests that always bypass the cache).
	 *
	 * @param array<string,mixed> $baseline  Ignored.
	 * @param array<string,mixed> $candidate Ignored.
	 */
	public function verify( array $baseline, array $candidate ): ?string {
		$cache = $this->plugin->cache();
		$home  = home_url( '/' );

		try {
			return $this->run_checks( $cache, $home );
		} finally {
			$cache->purge_url( $home );
			if ( function_exists( 'wc_get_cart_url' ) ) {
				$cache->purge_url( (string) wc_get_cart_url() );
			}
		}
	}

	/**
	 * Verification checks.
	 *
	 * @param CacheManager $cache Cache manager.
	 * @param string       $home  Home URL.
	 */
	private function run_checks( CacheManager $cache, string $home ): ?string {
		// Pages are only captured while the optimization is active for regular requests.
		$capturing = $this->plugin->state()->is_active( $this->id() ) && $cache->is_cacheable_url( $home );

		if ( $capturing ) {
			$cache->purge_url( $home );
			$first = Loopback::get( $home );
			if ( ! $first['ok'] || $first['status'] < 200 || $first['status'] >= 400 ) {
				return __( 'The home page could not be loaded to test the page cache.', 'sh-speed-optimizer' );
			}
			$second = Loopback::get( $home );
			if ( 'HIT' !== self::cache_header( $second ) ) {
				$reason = $cache->last_skip_reason();
				return null === $reason
					? __( 'The home page could not be served from the cache.', 'sh-speed-optimizer' )
					: sprintf(
						/* translators: %s: plain-language reason */
						__( 'The home page could not be served from the cache: %s', 'sh-speed-optimizer' ),
						self::reason_label( $reason )
					);
			}
			$length_first  = strlen( (string) $first['body'] );
			$length_second = strlen( (string) $second['body'] );
			if ( $length_first > 0 && abs( $length_second - $length_first ) > 0.1 * $length_first ) {
				return __( 'The cached home page differs from the original page.', 'sh-speed-optimizer' );
			}
		} else {
			$this->plugin->logger()->debug( 'Page cache verification: capture not active for loopback requests; only the safety checks ran.', array(), 'cache' );
		}

		// Logged-in visitors must never get a cached page.
		$logged_in = Loopback::get( $home, array( 'cookies' => array( 'wordpress_logged_in_test' => '1' ) ) );
		if ( 'HIT' === self::cache_header( $logged_in ) ) {
			return __( 'A logged-in visitor was served a cached page.', 'sh-speed-optimizer' );
		}

		// The cart is personal.
		if ( function_exists( 'wc_get_cart_url' ) ) {
			$cart = (string) wc_get_cart_url();
			if ( '' !== $cart ) {
				Loopback::get( $cart );
				if ( 'HIT' === self::cache_header( Loopback::get( $cart ) ) ) {
					return __( 'The shopping cart page was served from the cache.', 'sh-speed-optimizer' );
				}
			}
		}

		// REST API responses are never cached.
		if ( function_exists( 'rest_url' ) ) {
			$rest = (string) rest_url();
			Loopback::get( $rest );
			if ( 'HIT' === self::cache_header( Loopback::get( $rest ) ) ) {
				return __( 'A REST API response was served from the page cache.', 'sh-speed-optimizer' );
			}
		}

		return null;
	}

	/**
	 * Value of the X-SHSO-Cache header of a loopback response.
	 *
	 * @param array<string,mixed> $response Loopback response.
	 */
	private static function cache_header( array $response ): string {
		$headers = array_change_key_case( (array) ( $response['headers'] ?? array() ), CASE_LOWER );
		return strtoupper( trim( (string) ( $headers[ strtolower( Delivery::HEADER ) ] ?? '' ) ) );
	}

	/**
	 * Plain-language label of a capture skip reason.
	 *
	 * @param string $reason Reason code.
	 */
	public static function reason_label( string $reason ): string {
		$labels = array(
			'set_cookie'     => __( 'a plugin sets a cookie on every page view.', 'sh-speed-optimizer' ),
			'cache_control'  => __( 'a plugin tells browsers and caches not to store the page.', 'sh-speed-optimizer' ),
			'donotcachepage' => __( 'a plugin asked for the page not to be cached.', 'sh-speed-optimizer' ),
			'status'         => __( 'the page did not load normally.', 'sh-speed-optimizer' ),
			'incomplete'     => __( 'the page is not a complete HTML document.', 'sh-speed-optimizer' ),
			'write_failed'   => __( 'the cache folder is not writable.', 'sh-speed-optimizer' ),
			'filter'         => __( 'custom code excluded it.', 'sh-speed-optimizer' ),
		);
		return $labels[ $reason ] ?? __( 'the page is not cacheable.', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function rollback(): void {
		$this->plugin->cache()->deactivate();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Runtime $runtime Runtime.
	 */
	public function register_runtime( Runtime $runtime ): void {
		$cache = $this->plugin->cache();

		// Automatic purging and preloading (every context).
		( new Purger( $cache ) )->register();
		$cache->preloader()->register();

		// Background maintenance.
		add_action( Scheduler::HOURLY_HOOK, array( $cache, 'gc' ) );
		add_action( CacheManager::CONFIG_HOOK, array( $cache, 'write_config' ) );
		add_filter( 'shso_cron_hooks', array( $this, 'cron_hooks' ) );

		// Keep the delivery configuration in sync with settings, plugins, theme and permalinks.
		add_action( 'shso_settings_updated', array( $cache, 'on_settings_updated' ), 20, 2 );
		foreach ( array( 'activated_plugin', 'deactivated_plugin', 'switch_theme', 'update_option_permalink_structure', 'update_option_home', 'update_option_siteurl', 'update_option_woocommerce_default_customer_address', 'update_option_woocommerce_permalinks' ) as $hook ) {
			add_action( $hook, array( $cache, 'schedule_config_write' ) );
		}
		add_action( 'wp_delete_site', array( $cache, 'forget_site' ) );

		// Delivery (fallback mode serves here and exits on a hit) and capture.
		$decision = $cache->deliver();
		if ( is_array( $decision ) && 'cache' === ( $decision['action'] ?? '' ) ) {
			( new Capture( $this->plugin, $cache, $decision ) )->start();
		}
	}

	/**
	 * Register our background hooks so deactivation clears them.
	 *
	 * @param string[] $hooks Hooks.
	 * @return string[]
	 */
	public function cron_hooks( $hooks ): array {
		$hooks   = (array) $hooks;
		$hooks[] = Preloader::HOOK;
		$hooks[] = CacheManager::CONFIG_HOOK;
		return $hooks;
	}

	/**
	 * {@inheritDoc}
	 */
	public function details(): array {
		$cache  = $this->plugin->cache();
		$status = $cache->status()['page_cache'];
		$stats  = $cache->stats( 7 );

		$labels = array(
			'dropin'   => __( 'Fast delivery (before WordPress loads)', 'sh-speed-optimizer' ),
			'fallback' => __( 'Delivered by the plugin (WP_CACHE is off)', 'sh-speed-optimizer' ),
			'external' => __( 'Handled by another cache', 'sh-speed-optimizer' ),
			'off'      => __( 'Off', 'sh-speed-optimizer' ),
		);

		return array(
			'mode'              => $status['mode'],
			'mode_label'        => $labels[ $status['mode'] ] ?? $status['mode'],
			'files'             => $status['files'],
			'bytes'             => $status['bytes'],
			'size'              => size_format( (int) $status['bytes'] ),
			'hit_rate'          => $stats['hit_rate'],
			'samples'           => $stats['samples'],
			'handled_by'        => $status['handled_by'],
			'wp_cache_constant' => $status['wp_cache_constant'],
			'dropin'            => $status['dropin'],
			'can_enable_dropin' => 'fallback' === $status['mode'] && ! Dropin::wp_cache_enabled() && ( null === $status['dropin'] || Dropin::OWNER_SELF === $status['dropin'] ),
			'preload'           => $cache->preloader()->progress(),
		);
	}
}
