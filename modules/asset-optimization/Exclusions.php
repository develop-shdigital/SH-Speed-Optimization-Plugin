<?php
/**
 * Exclusion matching shared by the CSS/JS optimizations.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\AssetOptimization;

use SH\SpeedOptimizer\Compatibility\Rules;
use SH\SpeedOptimizer\Core\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Exclusion helper.
 */
final class Exclusions {

	/**
	 * Build a matcher combining a compatibility rule list (case-insensitive substrings) with
	 * the user's exclusion patterns (substrings, "*" wildcards or "#regex#").
	 *
	 * @param Rules    $rules    Compatibility rules.
	 * @param string   $list     Rule list, e.g. "js_no_defer".
	 * @param string[] $patterns User patterns (settings exclude_js / exclude_css).
	 * @return callable function( string $handle, string $url = '', string $code = '' ): bool
	 */
	public static function matcher( Rules $rules, string $list, array $patterns ): callable {
		return static function ( string $handle, string $url = '', string $code = '' ) use ( $rules, $list, $patterns ): bool {
			$haystacks = array_values(
				array_filter(
					array( $handle, $url, $code ),
					static function ( $value ) {
						return '' !== $value;
					}
				)
			);
			if ( empty( $haystacks ) ) {
				return false;
			}
			if ( $rules->matches( $list, $haystacks ) ) {
				return true;
			}
			foreach ( $haystacks as $haystack ) {
				if ( Context::url_matches( $patterns, $haystack ) ) {
					return true;
				}
			}
			return false;
		};
	}

	/**
	 * Matcher from an assessment context (for detection logic).
	 *
	 * @param Rules               $rules    Rules.
	 * @param string              $list     Rule list.
	 * @param array<string,mixed> $settings Settings (all()).
	 * @param string              $key      Settings key (exclude_js / exclude_css).
	 */
	public static function from_settings( Rules $rules, string $list, array $settings, string $key ): callable {
		return self::matcher( $rules, $list, (array) ( $settings[ $key ] ?? array() ) );
	}
}
