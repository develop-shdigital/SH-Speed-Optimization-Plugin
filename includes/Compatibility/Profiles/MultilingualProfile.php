<?php
/**
 * Multilingual plugins (WPML, Polylang, TranslatePress, Weglot, GTranslate).
 *
 * Language-specific URLs (directories, subdomains) need no rules. Two setups
 * do: WPML's "language as a parameter" (?lang=de) makes the parameter part of
 * the cache key, and a browser-language redirect on the home page must never
 * be served from cache (visitors would be sent to the language of whoever
 * filled the cache). The TranslatePress editor is detected as an editor
 * preview by the compatibility module.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Compatibility\Profiles;

use SH\SpeedOptimizer\Compatibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Multilingual profile.
 */
final class MultilingualProfile extends AbstractProfile {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'multilingual';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Multilingual plugins', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function applies(): bool {
		return $this->wpml() || $this->polylang()
			|| $this->any_defined( 'TRP_PLUGIN_VERSION', 'WEGLOT_VERSION' )
			|| $this->any_plugin( 'translatepress-multilingual', 'weglot', 'gtranslate' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Rules $rules Rules.
	 */
	public function register( Rules $rules ): void {
		$home_redirect = false;

		if ( $this->wpml() ) {
			$settings = $this->env->option( 'icl_sitepress_settings', array() );
			$settings = is_array( $settings ) ? $settings : array();
			// 1 = directories, 2 = domains, 3 = ?lang= parameter.
			if ( 3 === (int) ( $settings['language_negotiation_type'] ?? 0 ) ) {
				$rules->add( 'cache_query_keep', array( 'lang' ) );
			}
			if ( ! empty( $settings['automatic_redirect'] ) ) {
				$home_redirect = true;
			}
		}

		if ( $this->polylang() ) {
			$settings = $this->env->option( 'polylang', array() );
			if ( is_array( $settings ) && ! empty( $settings['browser'] ) ) {
				$home_redirect = true;
			}
		}

		if ( $home_redirect ) {
			$rules->add( 'cache_exclude_urls', array( self::home_pattern( $this->env->home_path() ) ) );
			$rules->penalize( 'page_cache', 5, __( 'The home page redirects visitors by browser language, so it is not cached.', 'sh-speed-optimizer' ) );
		}
	}

	/**
	 * Regular expression matching only the home page (as a path or a full URL, with or without query).
	 *
	 * @param string $home_path Home path, e.g. "/" or "/blog/".
	 */
	public static function home_pattern( string $home_path ): string {
		$path = trim( $home_path, '/' );
		$path = '' === $path ? '' : preg_quote( $path, '#' ) . '/?';
		return '#^(?:https?://[^/]+)?/' . $path . '(?:\?.*)?$#';
	}

	/**
	 * Whether WPML is active.
	 */
	private function wpml(): bool {
		return $this->any_defined( 'ICL_SITEPRESS_VERSION' ) || $this->any_plugin( 'sitepress-multilingual-cms' );
	}

	/**
	 * Whether Polylang is active.
	 */
	private function polylang(): bool {
		return $this->any_defined( 'POLYLANG_VERSION' ) || $this->any_plugin( 'polylang', 'polylang-pro' );
	}
}
