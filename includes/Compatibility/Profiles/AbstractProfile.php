<?php
/**
 * Helpers shared by compatibility profiles.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\ProfileInterface;
use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Base profile.
 */
abstract class AbstractProfile implements ProfileInterface {

	/**
	 * Environment lookups.
	 *
	 * @var Environment
	 */
	protected Environment $env;

	/**
	 * Constructor.
	 *
	 * @param Environment|null $env Environment (tests inject a fake).
	 */
	public function __construct( ?Environment $env = null ) {
		$this->env = $env ?? new Environment();
	}

	/**
	 * Whether any of the constants is defined.
	 *
	 * @param string ...$constants Constants.
	 */
	protected function any_defined( string ...$constants ): bool {
		foreach ( $constants as $constant ) {
			if ( $this->env->defined( $constant ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether any of the plugins is active.
	 *
	 * @param string ...$slugs Plugin slugs.
	 */
	protected function any_plugin( string ...$slugs ): bool {
		foreach ( $slugs as $slug ) {
			if ( $this->env->plugin_active( $slug ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the parent theme is one of the given directory names (case-insensitive).
	 *
	 * @param string ...$templates Theme directory names.
	 */
	protected function theme_is( string ...$templates ): bool {
		$current = strtolower( $this->env->template() );
		foreach ( $templates as $template ) {
			if ( strtolower( $template ) === $current ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Protect scripts from deferring and/or delaying.
	 *
	 * @param Rules    $rules   Rules.
	 * @param string[] $needles Handles / URL fragments.
	 * @param bool     $defer   Never defer.
	 * @param bool     $delay   Never delay.
	 */
	protected function protect_scripts( Rules $rules, array $needles, bool $defer = true, bool $delay = true ): void {
		if ( $defer ) {
			$rules->add( 'js_no_defer', $needles );
		}
		if ( $delay ) {
			$rules->add( 'js_no_delay', $needles );
		}
	}

	/**
	 * Cache exclusion patterns for a page and its translations.
	 *
	 * With pretty permalinks ending in "/" a readable substring ("/cart/") is used; otherwise a
	 * regular expression that also matches the URL without trailing slash. Plain permalinks
	 * match the page_id query argument.
	 *
	 * @param int $page_id Page id.
	 * @return string[]
	 */
	protected function page_patterns( int $page_id ): array {
		if ( $page_id <= 0 ) {
			return array();
		}
		if ( ! $this->env->permalinks_enabled() ) {
			$front = 'page' === $this->env->option( 'show_on_front', '' ) ? (int) $this->env->option( 'page_on_front', 0 ) : 0;
			return $page_id === $front ? array() : array( '#[?&]page_id=' . $page_id . '(?:&|$)#' );
		}
		$patterns = array();
		foreach ( $this->env->page_uris( $page_id ) as $uri ) {
			$patterns[] = self::path_pattern( $uri, $this->env->trailing_slash() );
		}
		return $patterns;
	}

	/**
	 * Exclusion pattern for a URL path segment sequence ("shop/cart").
	 *
	 * @param string $uri            Page URI without slashes at the ends.
	 * @param bool   $trailing_slash Whether URLs end with a slash.
	 */
	public static function path_pattern( string $uri, bool $trailing_slash ): string {
		$uri = trim( $uri, '/' );
		if ( $trailing_slash ) {
			return '/' . $uri . '/';
		}
		return '#/' . preg_quote( $uri, '#' ) . '(?:/|\?|$)#';
	}
}
