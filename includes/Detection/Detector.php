<?php
/**
 * Environment detector: builds the site profile.
 *
 * `quick()` never makes HTTP requests (cheap enough for admin screens);
 * `detect()` adds loopback checks (scans only).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Detection;

use SH\SpeedOptimizer\Core\Plugin;
use SH\SpeedOptimizer\Diagnostics\Loopback;

defined( 'ABSPATH' ) || exit;

/**
 * Detector service.
 */
final class Detector {

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Memoized quick profile.
	 *
	 * @var SiteProfile|null
	 */
	private ?SiteProfile $quick = null;

	/**
	 * Memoized full profile.
	 *
	 * @var SiteProfile|null
	 */
	private ?SiteProfile $full = null;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Cheap detection without HTTP requests (memoized per request).
	 */
	public function quick(): SiteProfile {
		if ( null !== $this->quick ) {
			return $this->quick;
		}

		$profile = new SiteProfile();

		$server = $this->step( 'server', array( ServerDetector::class, 'collect' ), array() );
		$profile->merge( 'server', $server );

		$plugins = $this->step( 'plugins', array( PluginDetector::class, 'collect' ), array() );
		$profile->set( 'plugins', $plugins );

		$lookup = new Facts( array_keys( $plugins ), (string) ( $server['software'] ?? 'unknown' ) );

		$profile->merge(
			'wp',
			$this->step(
				'wp',
				static function () use ( $lookup ) {
					return EnvironmentDetector::collect( $lookup );
				},
				array()
			)
		);

		$theme = $this->step( 'theme', array( ThemeDetector::class, 'collect' ), array() );
		$profile->set( 'theme', $theme );

		$profile->set(
			'builders',
			$this->step(
				'builders',
				static function () use ( $plugins, $theme, $lookup ) {
					return FeatureDetector::builders( $plugins, $theme, $lookup, self::block_editor_for_posts() );
				},
				array()
			)
		);

		$profile->set(
			'features',
			$this->step(
				'features',
				static function () use ( $plugins, $lookup ) {
					return FeatureDetector::features( array_keys( $plugins ), $lookup );
				},
				array()
			)
		);

		$cache = $this->step( 'cache', array( CacheDetector::class, 'collect' ), array() );
		$profile->set( 'cache', $cache );
		if ( ! empty( $cache['object_cache_dropin']['name'] ) ) {
			$profile->set( 'wp.object_cache_type', $cache['object_cache_dropin']['name'] );
		}

		$profile->set(
			'conflicts',
			$this->step(
				'conflicts',
				static function () use ( $plugins, $lookup, $cache ) {
					return self::conflicts( array_keys( $plugins ), $lookup, $cache );
				},
				array()
			)
		);

		$hosting = $this->step(
			'hosting',
			static function () use ( $lookup ) {
				return HostingDetector::detect( $lookup );
			},
			array(
				'provider'      => null,
				'provider_name' => null,
				'page_cache'    => false,
			)
		);
		$hosting['cdn']          = null;
		$hosting['server_cache'] = null;
		$profile->set( 'hosting', $hosting );

		/**
		 * Filters the quick site profile (no remote checks) after detection.
		 *
		 * @param SiteProfile $profile Profile.
		 */
		$filtered    = apply_filters( 'shso_site_profile_quick', $profile );
		$this->quick = $filtered instanceof SiteProfile ? $filtered : $profile;

		return $this->quick;
	}

	/**
	 * Full detection: quick() plus loopback checks when $remote is true.
	 *
	 * @param bool $remote Run loopback requests.
	 */
	public function detect( bool $remote = true ): SiteProfile {
		if ( ! $remote ) {
			return new SiteProfile( $this->quick()->to_array() );
		}
		if ( null !== $this->full ) {
			return $this->full;
		}

		$profile = new SiteProfile( $this->quick()->to_array() );

		try {
			$remote_data = RemoteDetector::run(
				array( Loopback::class, 'get' ),
				home_url( '/' ),
				array( includes_url( 'css/dashicons.min.css' ), includes_url( 'images/w-logo-blue.png' ) ),
				array( 'ours' => (bool) $profile->get( 'cache.advanced_cache.ours', false ) )
			);
			self::merge_remote( $profile, $remote_data );
		} catch ( \Throwable $e ) {
			$profile->merge(
				'loopback',
				array(
					'ok'    => false,
					'error' => $e->getMessage(),
				)
			);
			$this->log_error( 'remote', $e );
		}

		/**
		 * Filters the full site profile after detection.
		 *
		 * @param SiteProfile $profile Profile.
		 */
		$filtered   = apply_filters( 'shso_site_profile', $profile );
		$this->full = $filtered instanceof SiteProfile ? $filtered : $profile;

		return $this->full;
	}

	/**
	 * Forget memoized results (e.g. after plugins were activated in the same request).
	 */
	public function reset(): void {
		$this->quick = null;
		$this->full  = null;
	}

	/**
	 * Merge remote results into a profile (pure).
	 *
	 * @param SiteProfile         $profile Profile.
	 * @param array<string,mixed> $remote  RemoteDetector::run() result.
	 */
	public static function merge_remote( SiteProfile $profile, array $remote ): void {
		$profile->set( 'loopback', (array) ( $remote['loopback'] ?? array() ) );

		$profile->set( 'hosting.cdn', $remote['cdn'] ?? null );
		$profile->set( 'hosting.cdn_name', $remote['cdn_name'] ?? null );
		$profile->set( 'hosting.server_cache', $remote['server_cache'] ?? null );
		$profile->set( 'hosting.server_cache_name', $remote['server_cache_name'] ?? null );
		$profile->set( 'hosting.server_cache_hit', ! empty( $remote['server_cache_hit'] ) );
		$profile->set( 'hosting.edge_cache_hit', ! empty( $remote['edge_cache_hit'] ) );

		if ( ! empty( $remote['page_cache'] ) && ! $profile->get( 'hosting.page_cache' ) ) {
			// Pages are measurably served from a cache in front of WordPress.
			$profile->set( 'hosting.page_cache', true );
			$profile->set( 'hosting.page_cache_by', $remote['page_cache_by'] ?? null );
			if ( empty( $profile->get( 'hosting.provider_name' ) ) && ! empty( $remote['edge_cache_hit'] ) && ! empty( $remote['page_cache_by'] ) ) {
				$profile->set( 'hosting.provider_name', (string) $remote['page_cache_by'] );
			}
		}

		$compression = (string) ( $remote['compression'] ?? 'unknown' );
		$profile->set( 'server.compression', $compression );

		$profile->set( 'browser_cache', (array) ( $remote['browser_cache'] ?? array() ) );
	}

	/**
	 * Conflicts from active plugins plus a foreign advanced-cache.php drop-in (pure).
	 *
	 * @param string[]            $slugs  Active plugin slugs.
	 * @param callable            $lookup Fact lookup.
	 * @param array<string,mixed> $cache  Cache section.
	 * @return array<string,array{name:string,features:string[]}>
	 */
	public static function conflicts( array $slugs, callable $lookup, array $cache ): array {
		$conflicts = PluginCatalog::conflicts( $slugs, $lookup );

		$advanced = (array) ( $cache['advanced_cache'] ?? array() );
		if ( ! empty( $advanced['exists'] ) && empty( $advanced['ours'] ) && ! empty( $cache['wp_cache_constant'] ) ) {
			$owner = (string) ( $advanced['owner'] ?? '' );
			$known = '' !== $owner && isset( $conflicts[ $owner ] ) && in_array( 'page_cache', $conflicts[ $owner ]['features'], true );
			if ( ! $known ) {
				$conflicts['advanced-cache'] = array(
					'name'     => '' !== $owner
						/* translators: %s: name of a caching plugin */
						? sprintf( __( '%s (page cache drop-in)', 'sh-speed-optimizer' ), (string) $advanced['owner_name'] )
						: __( 'An existing page cache (advanced-cache.php)', 'sh-speed-optimizer' ),
					'features' => array( 'page_cache' ),
				);
			}
		}

		return $conflicts;
	}

	/**
	 * Whether the block editor edits posts (null when it cannot be determined).
	 */
	private static function block_editor_for_posts(): ?bool {
		try {
			if ( function_exists( 'use_block_editor_for_post_type' ) ) {
				return (bool) use_block_editor_for_post_type( 'post' );
			}
			/** This filter is documented in wp-admin/includes/post.php */
			return (bool) apply_filters( 'use_block_editor_for_post_type', true, 'post' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Run one detection step; failures are logged and replaced by a fallback.
	 *
	 * @param string   $name     Step name.
	 * @param callable $step     Step.
	 * @param array    $fallback Fallback value.
	 * @return array<string,mixed>
	 */
	private function step( string $name, callable $step, array $fallback ): array {
		try {
			$result = $step();
			return is_array( $result ) ? $result : $fallback;
		} catch ( \Throwable $e ) {
			$this->log_error( $name, $e );
			return $fallback;
		}
	}

	/**
	 * Log a detection failure.
	 *
	 * @param string     $step Step.
	 * @param \Throwable $e    Error.
	 */
	private function log_error( string $step, \Throwable $e ): void {
		try {
			$this->plugin->logger()->error(
				'Environment detection step failed.',
				array(
					'step'  => $step,
					'error' => $e->getMessage(),
				),
				'detection'
			);
		} catch ( \Throwable $ignored ) {
			unset( $ignored );
		}
	}
}
