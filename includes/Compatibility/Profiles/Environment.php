<?php
/**
 * Cheap WordPress lookups used by compatibility profiles.
 *
 * Profiles run on frontend requests, so everything here is limited to
 * constants, loaded classes/functions and autoloaded options. The class is
 * the test seam: unit tests pass a subclass returning fixed values.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Detection\PluginDetector;

defined( 'ABSPATH' ) || exit;

/**
 * Profile environment.
 */
class Environment {

	/**
	 * Active plugin slugs (lowercase) => true.
	 *
	 * @var array<string,bool>|null
	 */
	private ?array $active = null;

	/**
	 * Memoized page path lookups.
	 *
	 * @var array<int,string[]>
	 */
	private array $paths = array();

	/**
	 * Whether a constant is defined.
	 *
	 * @param string $name Constant.
	 */
	public function defined( string $name ): bool {
		return defined( $name );
	}

	/**
	 * Whether a class is already loaded (no autoloading on frontend requests).
	 *
	 * @param string $name Class.
	 */
	public function class_exists( string $name ): bool {
		return class_exists( $name, false );
	}

	/**
	 * Whether a function exists.
	 *
	 * @param string $name Function.
	 */
	public function function_exists( string $name ): bool {
		return function_exists( $name );
	}

	/**
	 * Option value.
	 *
	 * @param string $name     Option.
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	public function option( string $name, $fallback = false ) {
		return function_exists( 'get_option' ) ? get_option( $name, $fallback ) : $fallback;
	}

	/**
	 * Whether a plugin with this slug (directory name) is active on the site or network.
	 *
	 * @param string $slug Slug.
	 */
	public function plugin_active( string $slug ): bool {
		if ( null === $this->active ) {
			$this->active = array();
			$files        = (array) $this->option( 'active_plugins', array() );
			if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ) {
				$files = array_merge( $files, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
			}
			foreach ( $files as $file ) {
				if ( is_string( $file ) ) {
					$this->active[ strtolower( PluginDetector::slug_from_file( $file ) ) ] = true;
				}
			}
		}
		return isset( $this->active[ strtolower( $slug ) ] );
	}

	/**
	 * Parent theme directory name.
	 */
	public function template(): string {
		return function_exists( 'get_template' ) ? (string) get_template() : (string) $this->option( 'template', '' );
	}

	/**
	 * Active (child) theme directory name.
	 */
	public function stylesheet(): string {
		return function_exists( 'get_stylesheet' ) ? (string) get_stylesheet() : (string) $this->option( 'stylesheet', '' );
	}

	/**
	 * Whether pretty permalinks are used.
	 */
	public function permalinks_enabled(): bool {
		return '' !== (string) $this->option( 'permalink_structure', '' );
	}

	/**
	 * Whether pretty permalinks end with a slash.
	 */
	public function trailing_slash(): bool {
		return '/' === substr( (string) $this->option( 'permalink_structure', '' ), -1 );
	}

	/**
	 * Path of the home page, e.g. "/" or "/blog/".
	 */
	public function home_path(): string {
		$path = function_exists( 'home_url' ) ? (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) : '/';
		return '' === $path ? '/' : trailingslashit( $path );
	}

	/**
	 * Page URIs ("parent/child") of a page and its translations (WPML, Polylang).
	 * The front page is never returned, so an exclusion can never match the whole site.
	 *
	 * @param int $page_id Page id.
	 * @return string[]
	 */
	public function page_uris( int $page_id ): array {
		if ( $page_id <= 0 ) {
			return array();
		}
		if ( isset( $this->paths[ $page_id ] ) ) {
			return $this->paths[ $page_id ];
		}
		if ( ! function_exists( 'get_post' ) || ! function_exists( 'get_page_uri' ) ) {
			return array();
		}

		$front = 'page' === $this->option( 'show_on_front', '' ) ? (int) $this->option( 'page_on_front', 0 ) : 0;
		$uris  = array();

		foreach ( array_unique( array_merge( array( $page_id ), $this->translation_ids( $page_id ) ) ) as $id ) {
			if ( $id <= 0 || $id === $front ) {
				continue;
			}
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || 'trash' === $post->post_status ) {
				continue;
			}
			$uri = trim( (string) get_page_uri( $post ), '/' );
			if ( '' !== $uri ) {
				$uris[] = $uri;
			}
		}

		$uris = array_values( array_unique( $uris ) );

		// Multilingual plugins finish loading after `plugins_loaded`; remember results only once they are ready.
		if ( function_exists( 'did_action' ) && did_action( 'after_setup_theme' ) ) {
			$this->paths[ $page_id ] = $uris;
		}
		return $uris;
	}

	/**
	 * Ids of a page's translations.
	 *
	 * @param int $page_id Page id.
	 * @return int[]
	 */
	protected function translation_ids( int $page_id ): array {
		$ids = array();

		if ( function_exists( 'pll_get_post_translations' ) ) {
			$translations = pll_get_post_translations( $page_id );
			if ( is_array( $translations ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $translations ) );
			}
		}

		if ( $this->defined( 'ICL_SITEPRESS_VERSION' ) && function_exists( 'apply_filters' ) ) {
			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API filters.
			$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
			if ( is_array( $languages ) ) {
				foreach ( array_keys( $languages ) as $code ) {
					$translated = apply_filters( 'wpml_object_id', $page_id, 'page', false, (string) $code );
					if ( is_numeric( $translated ) ) {
						$ids[] = (int) $translated;
					}
				}
			}
			// phpcs:enable
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}
}
