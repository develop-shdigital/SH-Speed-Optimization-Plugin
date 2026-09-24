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
	 * @param Rules    $rules     Compatibility rules.
	 * @param string   $rule_list Rule list, e.g. "js_no_defer".
	 * @param string[] $patterns  User patterns (settings exclude_js / exclude_css).
	 * @return callable function( string $handle, string $url = '', string $code = '' ): bool
	 */
	public static function matcher( Rules $rules, string $rule_list, array $patterns ): callable {
		return static function ( string $handle, string $url = '', string $code = '' ) use ( $rules, $rule_list, $patterns ): bool {
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
			$source = null;
			if ( $rules->matches( $rule_list, $haystacks ) ) {
				$source = 'compatibility';
			} else {
				foreach ( $haystacks as $haystack ) {
					if ( Context::url_matches( $patterns, $haystack ) ) {
						$source = 'user';
						break;
					}
				}
			}
			if ( null === $source ) {
				return false;
			}

			/**
			 * Fires when a script or stylesheet is left untouched because of an exclusion.
			 *
			 * @param string $asset     Handle, or URL when there is no handle (inline code: '').
			 * @param string $rule_list Rule list that matched, e.g. "js_no_defer", "css_no_optimize".
			 * @param string $source    "compatibility" (a compatibility profile) or "user" (Settings → Exclusions).
			 */
			do_action( 'shso_asset_excluded', '' !== $handle ? $handle : $url, $rule_list, $source );
			return true;
		};
	}

	/**
	 * Matcher from an assessment context (for detection logic).
	 *
	 * @param Rules               $rules     Rules.
	 * @param string              $rule_list Rule list.
	 * @param array<string,mixed> $settings  Settings (all()).
	 * @param string              $key       Settings key (exclude_js / exclude_css).
	 */
	public static function from_settings( Rules $rules, string $rule_list, array $settings, string $key ): callable {
		return self::matcher( $rules, $rule_list, (array) ( $settings[ $key ] ?? array() ) );
	}
}
