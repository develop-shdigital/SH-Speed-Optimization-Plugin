<?php
/**
 * Page cache manager: purging, configuration, status and statistics.
 *
 * Everything here affects the current site only (host + path prefix), so a
 * multisite network never loses the cache of other sites.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Cache;

use SH\SpeedOptimizer\Compatibility\Rules;
use SH\SpeedOptimizer\Core\Context;
use SH\SpeedOptimizer\Core\Filesystem;
use SH\SpeedOptimizer\Core\Plugin;
use SH\SpeedOptimizer\Core\Scheduler;
use SH\SpeedOptimizer\Detection\SiteProfile;
use SH\SpeedOptimizer\Modules\BrowserCache\HtaccessRules;

defined( 'ABSPATH' ) || exit;

/**
 * Cache manager service (`Plugin::cache()`).
 */
final class CacheManager {

	/**
	 * Background hook regenerating the delivery configuration.
	 */
	public const CONFIG_HOOK = 'shso_cache_write_config';

	/**
	 * Transient caching the (bounded) disk usage walk.
	 */
	public const USAGE_TRANSIENT = 'shso_cache_usage';

	/**
	 * Settings whose change makes cached pages outdated.
	 */
	private const PURGE_SETTINGS = array( 'safe_mode', 'page_cache', 'cache_mobile', 'exclude_urls', 'exclude_cookies', 'exclude_css', 'exclude_js' );

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Site configuration (per request).
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $site = null;

	/**
	 * Storage.
	 *
	 * @var Storage|null
	 */
	private ?Storage $storage = null;

	/**
	 * Preloader.
	 *
	 * @var Preloader|null
	 */
	private ?Preloader $preloader = null;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	// ---------------------------------------------------------------------
	// Purging.
	// ---------------------------------------------------------------------

	/**
	 * Delete all cached pages of the current site and schedule a preload.
	 *
	 * @param string $reason Why (logged in debug mode).
	 * @return int Deleted cache files.
	 */
	public function purge_all( string $reason = '' ): int {
		$this->mark_purged();
		$count = $this->delete_site_files();
		delete_transient( self::USAGE_TRANSIENT );

		$this->plugin->logger()->debug(
			'Page cache cleared.',
			array(
				'reason' => $reason,
				'files'  => $count,
			),
			'cache'
		);

		/**
		 * Fires after cached pages were deleted.
		 *
		 * @param string   $scope all|url|post|term.
		 * @param string[] $urls  Purged URLs (empty for "all").
		 */
		do_action( 'shso_cache_cleared', 'all', array() );

		$this->preloader()->schedule();

		return $count;
	}

	/**
	 * Delete one URL (all variants: HTTP/HTTPS, mobile, query and cookie variants, pagination).
	 *
	 * @param string $url URL of this site.
	 * @return int Deleted cache files.
	 */
	public function purge_url( string $url ): int {
		$this->mark_purged();
		$count = $this->delete_url( $url );

		/** This action is documented in includes/Cache/CacheManager.php */
		do_action( 'shso_cache_cleared', 'url', array( $url ) );

		return $count;
	}

	/**
	 * Delete a post and every page listing it (home, archives, terms, author, dates).
	 *
	 * @param int $post_id Post id.
	 * @return int Deleted cache files.
	 */
	public function purge_post( int $post_id ): int {
		return $this->purge_urls( $this->post_urls( $post_id ), 'post' );
	}

	/**
	 * Delete a term archive.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 * @return int Deleted cache files.
	 */
	public function purge_term( int $term_id, string $taxonomy ): int {
		return $this->purge_urls( $this->term_urls( $term_id, $taxonomy ), 'term' );
	}

	/**
	 * Delete several URLs, fire one event and re-warm them.
	 *
	 * @param string[] $urls  URLs.
	 * @param string   $scope url|post|term.
	 * @return int Deleted cache files.
	 */
	public function purge_urls( array $urls, string $scope = 'url' ): int {
		$urls  = array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) );
		$count = 0;
		if ( ! empty( $urls ) ) {
			$this->mark_purged();
		}
		foreach ( $urls as $url ) {
			$count += $this->delete_url( $url );
		}

		/** This action is documented in includes/Cache/CacheManager.php */
		do_action( 'shso_cache_cleared', $scope, $urls );

		$this->preloader()->enqueue( array_slice( $urls, 0, 10 ) );

		return $count;
	}

	/**
	 * Remember when this site was last purged (written before deleting, so a page
	 * that started rendering before the purge is never stored afterwards).
	 */
	private function mark_purged(): void {
		$prefix = $this->site_prefix();
		$fs     = $this->plugin->filesystem();
		$dir    = $fs->cache_dir( 'config' );
		foreach ( $this->hosts() as $host ) {
			$fs->write( $dir . Delivery::site_key( $host, $prefix ) . '.purged.txt', sprintf( '%.6F', microtime( true ) ) );
		}
	}

	/**
	 * Whether the site of a request was purged at or after a time (the render may be outdated).
	 *
	 * @param string $site_key Site key of the request.
	 * @param float  $time     Request start (Unix time with microseconds).
	 */
	public function purged_since( string $site_key, float $time ): bool {
		if ( ! preg_match( '/^[a-z0-9._+~-]+$/', $site_key ) ) {
			return false;
		}
		$marker = Filesystem::cache_root() . 'config/' . $site_key . '.purged.txt';
		if ( ! is_file( $marker ) ) {
			return false;
		}
		$purged = @file_get_contents( $marker ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Tiny local marker file.
		return is_string( $purged ) && (float) $purged >= $time;
	}

	/**
	 * URLs showing a post.
	 *
	 * @param \WP_Post|int $post Post.
	 * @return string[]
	 */
	public function post_urls( $post ): array {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		$urls = array( (string) get_permalink( $post ), home_url( '/' ) );

		if ( 'post' === $post->post_type && 'page' === get_option( 'show_on_front' ) ) {
			$posts_page = (int) get_option( 'page_for_posts' );
			if ( $posts_page > 0 ) {
				$urls[] = (string) get_permalink( $posts_page );
			}
		}

		$archive = get_post_type_archive_link( $post->post_type );
		if ( is_string( $archive ) ) {
			$urls[] = $archive;
		}

		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( empty( $taxonomy->public ) ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy->name );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$ids = array_merge( array( (int) $term->term_id ), array_map( 'intval', get_ancestors( (int) $term->term_id, $taxonomy->name, 'taxonomy' ) ) );
				foreach ( $ids as $term_id ) {
					$link = get_term_link( $term_id, $taxonomy->name );
					if ( is_string( $link ) ) {
						$urls[] = $link;
					}
				}
			}
		}

		if ( post_type_supports( $post->post_type, 'author' ) && (int) $post->post_author > 0 ) {
			$urls[] = get_author_posts_url( (int) $post->post_author );
		}

		if ( 'post' === $post->post_type ) {
			$year   = (int) mysql2date( 'Y', $post->post_date );
			$month  = (int) mysql2date( 'n', $post->post_date );
			$day    = (int) mysql2date( 'j', $post->post_date );
			$urls[] = get_year_link( $year );
			$urls[] = get_month_link( $year, $month );
			$urls[] = get_day_link( $year, $month, $day );
		}

		if ( 'product' === $post->post_type && function_exists( 'wc_get_page_permalink' ) ) {
			$urls[] = (string) wc_get_page_permalink( 'shop' );
		}

		/**
		 * Filters the URLs purged when a post changes.
		 *
		 * @param string[] $urls URLs.
		 * @param \WP_Post $post Post.
		 */
		$urls = (array) apply_filters( 'shso_cache_post_urls', $urls, $post );

		return array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) );
	}

	/**
	 * URLs of a term archive.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 * @return string[]
	 */
	public function term_urls( int $term_id, string $taxonomy ): array {
		$link = get_term_link( $term_id, $taxonomy );
		return is_string( $link ) ? array( $link ) : array();
	}

	/**
	 * Delete the variants of one URL without firing events.
	 *
	 * @param string $url URL.
	 */
	private function delete_url( string $url ): int {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return 0;
		}
		$hosts = $this->hosts();
		$host  = Delivery::normalize_host( $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) );
		if ( null === $host || ! in_array( $host, $hosts, true ) ) {
			return 0;
		}
		$segments = Delivery::path_segments( isset( $parts['path'] ) && '' !== $parts['path'] ? (string) $parts['path'] : '/' );
		if ( null === $segments ) {
			return 0;
		}

		$count = 0;
		foreach ( $hosts as $allowed ) {
			$count += $this->storage()->purge_page( Delivery::dir_for( $allowed, $segments ) );
		}
		return $count;
	}

	/**
	 * Delete every cached file of the current site (other sites of a network are kept).
	 */
	private function delete_site_files(): int {
		$segments = Delivery::path_segments( $this->site_prefix() ) ?? array();
		$count    = 0;
		foreach ( $this->hosts() as $host ) {
			$count += $this->storage()->purge_tree( Delivery::dir_for( $host, $segments ), $this->protected_dirs( $host ) );
		}
		return $count;
	}

	/**
	 * Directories of other sites nested below this site's path (subdirectory multisite).
	 *
	 * @param string $host Host.
	 * @return string[]
	 */
	private function protected_dirs( string $host ): array {
		$prefix   = $this->site_prefix();
		$prefixes = Config::nested_prefixes( $this->read_config_file( $this->config_file( $host ) ), $prefix );

		if ( is_multisite() && function_exists( 'get_sites' ) && ! ( function_exists( 'is_subdomain_install' ) && is_subdomain_install() ) ) {
			$sites = get_sites(
				array(
					'domain' => (string) preg_replace( '/:\d+$/', '', $host ),
					'number' => 1000,
				)
			);
			foreach ( (array) $sites as $site ) {
				$other = Delivery::normalize_prefix( strtolower( (string) $site->path ) );
				if ( $other !== $prefix && 0 === strpos( $other, $prefix ) ) {
					$prefixes[] = $other;
				}
			}
		}

		$dirs = array();
		foreach ( array_unique( $prefixes ) as $nested ) {
			$segments = Delivery::path_segments( $nested );
			if ( null !== $segments ) {
				$dirs[] = Delivery::dir_for( $host, $segments );
			}
		}
		return $dirs;
	}

	// ---------------------------------------------------------------------
	// Status and statistics.
	// ---------------------------------------------------------------------

	/**
	 * Cache status for the dashboard.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function status(): array {
		$mode  = $this->mode();
		$usage = $this->usage();

		return array(
			'page_cache'    => array(
				'active'            => in_array( $mode, array( 'dropin', 'fallback' ), true ),
				'mode'              => $mode,
				'handled_by'        => $this->handled_by(),
				'files'             => $usage['files'],
				'bytes'             => $usage['bytes'],
				'wp_cache_constant' => Dropin::wp_cache_enabled(),
				'dropin'            => Dropin::owner(),
			),
			'browser_cache' => $this->browser_cache_status(),
			'object_cache'  => $this->object_cache_status(),
			'preload'       => $this->preloader()->progress(),
		);
	}

	/**
	 * Sampled hit/miss/bypass counts per UTC day.
	 *
	 * @param int $days Days (1–14).
	 * @return array<string,mixed> days, hit_rate (fraction or null when fewer than 50 samples), sampled, sample_rate, samples.
	 */
	public function stats( int $days = 7 ): array {
		$prefix = $this->site_prefix();
		$keys   = array();
		foreach ( $this->hosts() as $host ) {
			$keys[] = Delivery::site_key( $host, $prefix );
		}
		return Stats::summarize( Stats::read( Filesystem::cache_root(), $keys, $days, time() ) );
	}

	/**
	 * Delivery mode: dropin|fallback (we serve), external (another cache is in charge) or off.
	 */
	public function mode(): string {
		$handled = $this->handled_by();
		$owner   = Dropin::owner();
		$active  = $this->plugin->state()->is_active( 'page_cache' )
			&& (bool) $this->plugin->settings()->get( 'page_cache', true )
			&& ! Context::is_emergency_safe_mode()
			&& ! ( defined( 'SHSO_DISABLE_CACHE' ) && SHSO_DISABLE_CACHE );

		if ( null !== $owner && Dropin::OWNER_SELF !== $owner && Dropin::wp_cache_enabled() ) {
			return 'external';
		}
		if ( ! $active ) {
			return null !== $handled ? 'external' : 'off';
		}
		return Dropin::wp_cache_enabled() && Dropin::OWNER_SELF === $owner ? 'dropin' : 'fallback';
	}

	/**
	 * Another plugin or the host that provides page caching, if any.
	 */
	public function handled_by(): ?string {
		$owner = Dropin::owner();
		if ( null !== $owner && Dropin::OWNER_SELF !== $owner && Dropin::wp_cache_enabled() ) {
			return 'unknown' === $owner ? __( 'Another page cache (advanced-cache.php)', 'sh-speed-optimizer' ) : $owner;
		}
		$profile = $this->profile();
		if ( null !== $profile ) {
			$providers = $profile->provided_by( 'page_cache' );
			if ( ! empty( $providers ) ) {
				return implode( ', ', $providers );
			}
		}
		return null;
	}

	/**
	 * Cached pages and bytes of this site (bounded walk, cached for 5 minutes).
	 *
	 * @return array{files:int,bytes:int}
	 */
	public function usage(): array {
		$cached = get_transient( self::USAGE_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['files'], $cached['bytes'] ) ) {
			return array(
				'files' => (int) $cached['files'],
				'bytes' => (int) $cached['bytes'],
			);
		}

		$segments = Delivery::path_segments( $this->site_prefix() ) ?? array();
		$usage    = array(
			'files' => 0,
			'bytes' => 0,
		);
		foreach ( $this->hosts() as $host ) {
			$part            = $this->storage()->usage( Delivery::dir_for( $host, $segments ), $this->protected_dirs( $host ) );
			$usage['files'] += $part['files'];
			$usage['bytes'] += $part['bytes'];
		}

		set_transient( self::USAGE_TRANSIENT, $usage, 5 * MINUTE_IN_SECONDS );
		return $usage;
	}

	/**
	 * Why the last verification loopback was not stored (reason code or null).
	 */
	public function last_skip_reason(): ?string {
		$reason = get_transient( Capture::LAST_SKIP_TRANSIENT );
		return is_string( $reason ) && '' !== $reason ? $reason : null;
	}

	/**
	 * Browser cache status.
	 *
	 * @return array<string,mixed>
	 */
	private function browser_cache_status(): array {
		$profile    = $this->profile();
		$configured = null !== $profile ? $profile->get( 'browser_cache.configured' ) : null;

		return array(
			'active'               => $this->plugin->state()->is_active( 'browser_cache' ),
			'configured_by_server' => null === $configured ? null : (bool) $configured,
			'rules_installed'      => HtaccessRules::is_installed(),
			'server'               => HtaccessRules::server(),
		);
	}

	/**
	 * Object cache status.
	 *
	 * @return array{active:bool,dropin:string|null,type:string|null}
	 */
	private function object_cache_status(): array {
		$file     = WP_CONTENT_DIR . '/object-cache.php';
		$dropin   = null;
		$type     = null;
		$contents = is_file( $file ) ? @file_get_contents( $file, false, null, 0, 16384 ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Read the drop-in header.
		if ( is_string( $contents ) ) {
			$dropin = self::object_cache_name( $contents );
			$type   = self::object_cache_type( $contents );
		}

		return array(
			'active' => function_exists( 'wp_using_ext_object_cache' ) && (bool) wp_using_ext_object_cache(),
			'dropin' => $dropin,
			'type'   => $type,
		);
	}

	/**
	 * Name of an object cache drop-in (pure).
	 *
	 * @param string $contents File contents.
	 */
	public static function object_cache_name( string $contents ): string {
		if ( preg_match( '/^[\s\*#@\/]*Plugin Name:\s*(.+)$/mi', $contents, $m ) ) {
			return trim( wp_strip_all_tags( $m[1] ) );
		}
		$known = array(
			'Object Cache Pro'   => 'Object Cache Pro',
			'Redis Object Cache' => 'Redis Object Cache',
			'LiteSpeed'          => 'LiteSpeed Cache',
			'W3 Total Cache'     => 'W3 Total Cache',
			'Memcached'          => 'Memcached Object Cache',
			'APCu'               => 'APCu Object Cache',
		);
		foreach ( $known as $needle => $name ) {
			if ( false !== stripos( $contents, $needle ) ) {
				return $name;
			}
		}
		return 'object-cache.php';
	}

	/**
	 * Backend of an object cache drop-in (pure): redis|memcached|apcu|unknown.
	 *
	 * @param string $contents File contents.
	 */
	public static function object_cache_type( string $contents ): string {
		if ( false !== stripos( $contents, 'redis' ) || false !== stripos( $contents, 'relay' ) ) {
			return 'redis';
		}
		if ( false !== stripos( $contents, 'memcache' ) ) {
			return 'memcached';
		}
		if ( false !== stripos( $contents, 'apcu' ) ) {
			return 'apcu';
		}
		return 'unknown';
	}

	/**
	 * Site profile of the last scan, if any.
	 */
	private function profile(): ?SiteProfile {
		$scan = get_option( 'shso_scan' );
		if ( is_array( $scan ) && isset( $scan['profile'] ) && is_array( $scan['profile'] ) ) {
			return new SiteProfile( $scan['profile'] );
		}
		return null;
	}

	// ---------------------------------------------------------------------
	// Configuration.
	// ---------------------------------------------------------------------

	/**
	 * (Re)generate the delivery configuration of the current site.
	 */
	public function write_config(): bool {
		$this->site = null;
		$site       = $this->site_config();
		$fs         = $this->plugin->filesystem();

		$fs->cache_dir( 'config' );
		$fs->cache_dir( 'pages' );
		$fs->cache_dir( 'stats' );

		$ok = ! empty( $site['hosts'] );
		foreach ( $site['hosts'] as $host ) {
			$file = $this->config_file( $host );
			$data = Config::merge_site( $this->read_config_file( $file ), $this->site_prefix(), $site, time() );
			$ok   = $fs->write( $file, (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ) && $ok;
		}

		Delivery::flush_configs();

		if ( ! $ok ) {
			$this->plugin->logger()->error( 'The page cache configuration could not be written.', array(), 'cache' );
		}
		return $ok;
	}

	/**
	 * Regenerate the configuration in a fresh request (plugins that were just
	 * (de)activated are only loaded, and their compatibility rules only known, there).
	 */
	public function schedule_config_write(): void {
		Scheduler::async( self::CONFIG_HOOK, array(), 5 );
	}

	/**
	 * Remove the current site from the delivery configuration.
	 */
	public function remove_config(): void {
		$fs = $this->plugin->filesystem();
		foreach ( $this->hosts() as $host ) {
			$file = $this->config_file( $host );
			if ( ! is_file( $file ) ) {
				continue;
			}
			$data = Config::remove_site( $this->read_config_file( $file ), $this->site_prefix() );
			if ( empty( $data['sites'] ) ) {
				$fs->delete( $file );
			} else {
				$fs->write( $file, (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );
			}
		}
		Delivery::flush_configs();
	}

	/**
	 * Whether the delivery configuration of this site exists.
	 */
	public function config_exists(): bool {
		$hosts = $this->hosts();
		return ! empty( $hosts ) && is_file( $this->config_file( $hosts[0] ) );
	}

	/**
	 * React to settings changes: rewrite the configuration and purge when cached pages are outdated.
	 *
	 * @param array<string,mixed> $current  New settings.
	 * @param array<string,mixed> $previous Previous settings.
	 */
	public function on_settings_updated( $current, $previous ): void {
		$current  = is_array( $current ) ? $current : array();
		$previous = is_array( $previous ) ? $previous : array();

		$this->write_config();

		foreach ( self::PURGE_SETTINGS as $key ) {
			if ( ( $current[ $key ] ?? null ) !== ( $previous[ $key ] ?? null ) ) {
				$this->purge_all( 'settings:' . $key );
				return;
			}
		}
	}

	/**
	 * A network site was deleted: forget its configuration and cached pages.
	 *
	 * @param \WP_Site $site Deleted site.
	 */
	public function forget_site( $site ): void {
		if ( ! is_object( $site ) || empty( $site->domain ) ) {
			return;
		}
		$host   = Delivery::normalize_host( (string) $site->domain );
		$prefix = Delivery::normalize_prefix( strtolower( (string) ( $site->path ?? '/' ) ) );
		if ( null === $host ) {
			return;
		}

		$file = $this->config_file( $host );
		if ( is_file( $file ) ) {
			$data = Config::remove_site( $this->read_config_file( $file ), $prefix );
			if ( empty( $data['sites'] ) ) {
				$this->plugin->filesystem()->delete( $file );
			} else {
				$this->plugin->filesystem()->write( $file, (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );
			}
		}

		$segments = Delivery::path_segments( $prefix );
		if ( '/' !== $prefix && null !== $segments ) {
			$this->storage()->purge_tree( Delivery::dir_for( $host, $segments ) );
		}
	}

	/**
	 * Undo everything the page cache created for this site (rollback/deactivation).
	 */
	public function deactivate(): void {
		$this->preloader()->clear();
		$this->remove_config();
		$this->delete_site_files();
		if ( ! $this->any_site_enabled() ) {
			Dropin::uninstall();
		}
		wp_clear_scheduled_hook( self::CONFIG_HOOK );
		delete_transient( self::USAGE_TRANSIENT );

		/** This action is documented in includes/Cache/CacheManager.php */
		do_action( 'shso_cache_cleared', 'all', array() );
	}

	/**
	 * Whether any site (of any host) still has the page cache enabled in its configuration.
	 */
	private function any_site_enabled(): bool {
		$dir = Filesystem::cache_root() . 'config/';
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( ! is_string( $name ) || ! str_ends_with( $name, '.json' ) ) {
				continue;
			}
			$data = $this->read_config_file( $dir . $name );
			foreach ( (array) ( $data['sites'] ?? array() ) as $site ) {
				if ( is_array( $site ) && ! empty( $site['enabled'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Delete expired pages and old statistics (hourly cron), and keep the configuration current.
	 *
	 * @return int Deleted files.
	 */
	public function gc(): int {
		$lifespan = (int) $this->site_config()['lifespan'];
		$segments = Delivery::path_segments( $this->site_prefix() ) ?? array();
		$now      = time();
		$deleted  = 0;

		foreach ( $this->hosts() as $host ) {
			$deleted += $this->storage()->gc( Delivery::dir_for( $host, $segments ), $lifespan, $now, $this->protected_dirs( $host ) );
		}
		$deleted += Stats::prune( $this->plugin->filesystem(), Filesystem::cache_root(), $now );

		if ( $deleted > 0 ) {
			delete_transient( self::USAGE_TRANSIENT );
		}

		$this->write_config();

		return $deleted;
	}

	/**
	 * Whether a URL may be cached (same rules as the delivery, for an anonymous visitor).
	 *
	 * @param string $url URL.
	 */
	public function is_cacheable_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$https  = 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$server = array(
			'HTTP_HOST'      => $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ),
			'REQUEST_URI'    => ( isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ),
			'REQUEST_METHOD' => 'GET',
			'HTTPS'          => $https ? 'on' : '',
			'SERVER_PORT'    => $https ? '443' : '80',
		);
		$config = array( 'sites' => array( $this->site_prefix() => $this->site_config() ) );

		return 'cache' === Delivery::decide( $server, array(), $config )['action'];
	}

	/**
	 * Site configuration built from settings, compatibility rules and the active plugins.
	 *
	 * @return array<string,mixed>
	 */
	public function site_config(): array {
		if ( null !== $this->site ) {
			return $this->site;
		}

		$settings = $this->plugin->settings();
		$rules    = $this->rules();

		$exclude = array_merge(
			(array) $settings->get( 'exclude_urls', array() ),
			(array) $rules->get( 'cache_exclude_urls' ),
			$this->commerce_exclusions()
		);
		/**
		 * Filters the URL patterns that are never cached (substring, "*" wildcard or "#regex#").
		 *
		 * @param string[] $patterns Patterns.
		 */
		$exclude = (array) apply_filters( 'shso_cache_excluded_urls', $exclude );

		/**
		 * Filters the cookie name prefixes that bypass the page cache.
		 * The built-in list (logged in, cart, password, commenter …) always applies and cannot be removed.
		 *
		 * @param string[] $cookies Cookie prefixes ("*" wildcards allowed).
		 */
		$cookies = (array) apply_filters(
			'shso_cache_bypass_cookies',
			array_merge( Delivery::BYPASS_COOKIES, (array) $settings->get( 'exclude_cookies', array() ), (array) $rules->get( 'cache_exclude_cookies' ) )
		);

		/**
		 * Filters the query parameters ignored for caching (tracking parameters).
		 * Unknown parameters bypass the cache.
		 *
		 * @param string[] $params Parameter names.
		 */
		$ignore = (array) apply_filters( 'shso_cache_ignored_query_params', array_merge( Config::TRACKING_PARAMS, (array) $rules->get( 'cache_query_ignore' ) ) );

		$keep = (array) $rules->get( 'cache_query_keep' );
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$keep = array_merge( $keep, Config::PLAIN_PERMALINK_PARAMS );
		}
		if ( function_exists( 'WC' ) && 'geolocation_ajax' === get_option( 'woocommerce_default_customer_address' ) ) {
			$keep[] = 'v'; // WooCommerce adds ?v=<location hash> so each location gets its own copy.
		}

		/** This filter is documented in includes/Cache/Capture.php */
		$lifespan = (int) apply_filters( 'shso_cache_lifespan', (int) $settings->get( 'cache_lifespan', 10 ) * HOUR_IN_SECONDS, '' );

		$this->site = Config::build_site(
			array(
				'enabled'        => (bool) $settings->get( 'page_cache', true ) && ! Context::is_emergency_safe_mode(),
				'safe_mode'      => Context::is_emergency_safe_mode(),
				'lifespan'       => $lifespan,
				'exclude_urls'   => $exclude,
				'bypass_cookies' => array_values( array_diff( $cookies, Delivery::BYPASS_COOKIES ) ),
				'vary_cookies'   => (array) $rules->get( 'cache_vary_cookies' ),
				'safe_cookies'   => (array) $rules->get( 'cache_safe_cookies' ),
				'ignore_query'   => $ignore,
				'keep_query'     => $keep,
				'mobile'         => $this->mobile_variant(),
				'hosts'          => $this->hosts(),
				'gzip'           => function_exists( 'gzencode' ),
				'rest_prefix'    => function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json',
			)
		);

		return $this->site;
	}

	/**
	 * Allowed hosts of this site (home and site URL, with port).
	 *
	 * @return string[]
	 */
	public function hosts(): array {
		return Config::hosts_from_urls( array( home_url( '/' ), site_url( '/' ) ) );
	}

	/**
	 * Path prefix of this site ("/" or "/blog/").
	 */
	public function site_prefix(): string {
		return Config::prefix_from_url( home_url( '/' ) );
	}

	/**
	 * Storage.
	 */
	public function storage(): Storage {
		if ( null === $this->storage ) {
			$this->storage = new Storage( $this->plugin->filesystem() );
		}
		return $this->storage;
	}

	/**
	 * Preloader.
	 */
	public function preloader(): Preloader {
		if ( null === $this->preloader ) {
			$this->preloader = new Preloader( $this->plugin, $this );
		}
		return $this->preloader;
	}

	// ---------------------------------------------------------------------
	// Runtime delivery (fallback mode).
	// ---------------------------------------------------------------------

	/**
	 * Serve from the cache when the drop-in is not active (exits on a hit) and
	 * return the decision of this request for the capture, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public function deliver(): ?array {
		$context = $this->plugin->context();
		if ( Context::is_emergency_safe_mode() || ( defined( 'SHSO_DISABLE_CACHE' ) && SHSO_DISABLE_CACHE ) ) {
			return null;
		}
		if ( ! $context->is_frontend_request() || $context->is_verification() || ! (bool) $this->plugin->settings()->get( 'page_cache', true ) ) {
			return null;
		}

		if ( defined( 'SHSO_ADVANCED_CACHE' ) ) {
			// The drop-in already decided before WordPress loaded.
			$decision = Delivery::last_decision();
			if ( null === $decision && ! $this->config_exists() ) {
				$this->schedule_config_write();
			}
			return $decision;
		}

		if ( Dropin::wp_cache_enabled() && file_exists( Dropin::path() ) && ! Dropin::is_ours() ) {
			return null; // Another page cache is in charge.
		}

		if ( ! $this->config_exists() ) {
			$this->schedule_config_write();
			return null;
		}

		return Delivery::serve( Filesystem::cache_root(), 'fallback' );
	}

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------

	/**
	 * Configuration file of a host.
	 *
	 * @param string $host Host.
	 */
	private function config_file( string $host ): string {
		return Filesystem::cache_root() . 'config/' . Delivery::host_key( $host ) . '.json';
	}

	/**
	 * Read a configuration file.
	 *
	 * @param string $file File.
	 * @return array<string,mixed>
	 */
	private function read_config_file( string $file ): array {
		if ( ! is_file( $file ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local config file.
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Compatibility rules (empty rules when the compatibility layer is unavailable).
	 */
	private function rules(): Rules {
		try {
			return $this->plugin->compatibility()->rules();
		} catch ( \Throwable $e ) {
			unset( $e );
			return new Rules();
		}
	}

	/**
	 * Whether a separate cache is kept for mobile devices.
	 */
	private function mobile_variant(): bool {
		$setting = (string) $this->plugin->settings()->get( 'cache_mobile', 'auto' );
		if ( 'on' === $setting ) {
			return true;
		}
		if ( 'off' === $setting ) {
			return false;
		}

		// Only plugins that serve different HTML to phones need it; responsive themes do not.
		$auto = defined( 'WPTOUCH_VERSION' )
			|| class_exists( 'WPtouchPlugin', false )
			|| class_exists( 'WPtouchPro', false )
			|| ( class_exists( 'Jetpack', false ) && method_exists( 'Jetpack', 'is_module_active' ) && \Jetpack::is_module_active( 'minileven' ) )
			|| ( function_exists( 'ampforwp_get_setting' ) && ampforwp_get_setting( 'amp-mobile-redirection' ) );

		/**
		 * Filters whether a separate cache is kept for mobile devices when the setting is "auto".
		 *
		 * @param bool $mobile Whether a plugin serving different HTML to mobile devices was detected.
		 */
		return (bool) apply_filters( 'shso_cache_mobile_variant', $auto );
	}

	/**
	 * Cart, checkout, account and download pages of shop plugins (including translations and custom slugs).
	 *
	 * @return string[]
	 */
	private function commerce_exclusions(): array {
		$patterns = array();

		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$id = (int) wc_get_page_id( $page );
				if ( $id > 0 ) {
					foreach ( $this->translated_ids( $id ) as $translated ) {
						$patterns[] = $this->page_pattern( $translated );
					}
				}
			}
			$patterns[] = '/wc-api/';

			// Prices depend on the visitor's country: shop and product pages are never cached.
			if ( 'geolocation' === get_option( 'woocommerce_default_customer_address' ) ) {
				$shop = (int) wc_get_page_id( 'shop' );
				if ( $shop > 0 ) {
					foreach ( $this->translated_ids( $shop ) as $translated ) {
						$patterns[] = $this->page_pattern( $translated );
					}
				}
				$structure = function_exists( 'wc_get_permalink_structure' ) ? (array) wc_get_permalink_structure() : array();
				foreach ( array( 'product_base', 'category_base', 'tag_base' ) as $key ) {
					$base = trim( (string) preg_replace( '#%[^%/]+%#', '', (string) ( $structure[ $key ] ?? '' ) ), '/' );
					if ( '' !== $base ) {
						$patterns[] = '/' . $base . '/';
					}
				}
			}
		}

		if ( function_exists( 'edd_get_option' ) ) {
			foreach ( array( 'purchase_page', 'success_page', 'failure_page', 'purchase_history_page', 'login_redirect_page' ) as $key ) {
				$id = (int) edd_get_option( $key, 0 );
				if ( $id > 0 ) {
					$patterns[] = $this->page_pattern( $id );
				}
			}
		}

		return array_values(
			array_unique(
				array_filter(
					$patterns,
					static function ( $pattern ) {
						return '' !== $pattern && '/' !== $pattern;
					}
				)
			)
		);
	}

	/**
	 * Exclusion pattern of a page: its path, or its query string with plain permalinks.
	 *
	 * @param int $page_id Page id.
	 */
	private function page_pattern( int $page_id ): string {
		$url = (string) get_permalink( $page_id );
		if ( '' === $url ) {
			return '';
		}
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( ( '' === $path || '/' === $path || $this->site_prefix() === trailingslashit( $path ) ) && '' !== $query ) {
			return '?' . $query;
		}
		return $this->site_prefix() === trailingslashit( $path ) ? '' : $path;
	}

	/**
	 * A page id plus its translations (WPML, Polylang).
	 *
	 * @param int $page_id Page id.
	 * @return int[]
	 */
	private function translated_ids( int $page_id ): array {
		$ids = array( $page_id );

		if ( function_exists( 'pll_get_post_translations' ) ) {
			$ids = array_merge( $ids, array_values( (array) pll_get_post_translations( $page_id ) ) );
		}

		if ( has_filter( 'wpml_object_id' ) ) {
			$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
			foreach ( array_keys( (array) $languages ) as $code ) {
				$translated = apply_filters( 'wpml_object_id', $page_id, 'page', false, (string) $code ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
				if ( $translated ) {
					$ids[] = (int) $translated;
				}
			}
		}

		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}
}
