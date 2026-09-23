<?php
/**
 * Builds speculation rules for prefetching same-origin pages.
 *
 * Pure. Exclusions are expressed twice where it matters: as URL patterns
 * (href_matches) and as CSS selectors on the link element (selector_matches),
 * so a URL with a query string such as "?add-to-cart=1" is never prefetched
 * even if a browser interprets URL patterns differently.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\Preload;

defined( 'ABSPATH' ) || exit;

/**
 * Speculation rules builder.
 */
final class SpeculationRules {

	/**
	 * Build the rules.
	 *
	 * @param string   $home_path      Path of the home URL ("/" or "/blog/").
	 * @param string   $site_path      Path of the WordPress (site) URL.
	 * @param string[] $excluded_paths Extra excluded paths (cart, checkout, account pages …).
	 * @param string   $eagerness      conservative|moderate|eager.
	 * @return array<string,mixed>
	 */
	public static function build( string $home_path = '/', string $site_path = '/', array $excluded_paths = array(), string $eagerness = 'moderate' ): array {
		$home = self::dir( $home_path );
		$site = self::dir( $site_path );

		$patterns = array(
			$site . 'wp-admin/*',
			$site . 'wp-login.php*',
			$site . 'wp-*.php*',
			$home . 'wp-content/*',
			$home . 'wp-json/*',
			$home . '*logout*',
			$home . '*add-to-cart*',
			$home . '*.pdf',
			$home . '*.zip',
			$home . '*\\?(.+)',
		);
		if ( $site !== $home ) {
			$patterns[] = $site . 'wp-content/*';
		}

		foreach ( $excluded_paths as $path ) {
			$path = self::dir( (string) $path );
			if ( '/' !== $path && '' !== $path ) {
				$patterns[] = $path . '*';
			}
		}

		$selectors = array(
			'a[rel~="nofollow"]',
			'[data-no-prefetch]',
			'[data-no-prefetch] a',
			'a[href*="?"]',
			'a[href*="add-to-cart"]',
			'a[href*="logout"]',
			'a[href*="/wp-admin"]',
			'a[href*="wp-login.php"]',
			'a[href$=".pdf"]',
			'a[href$=".zip"]',
			'a[download]',
		);

		return array(
			'prefetch' => array(
				array(
					'source'    => 'document',
					'where'     => array(
						'and' => array(
							array( 'href_matches' => $home . '*' ),
							array( 'not' => array( 'href_matches' => array_values( array_unique( $patterns ) ) ) ),
							array( 'not' => array( 'selector_matches' => implode( ', ', $selectors ) ) ),
						),
					),
					'eagerness' => in_array( $eagerness, array( 'conservative', 'moderate', 'eager' ), true ) ? $eagerness : 'moderate',
				),
			),
		);
	}

	/**
	 * Script element for rules.
	 *
	 * @param array<string,mixed> $rules Rules.
	 */
	public static function to_script( array $rules ): string {
		$json = json_encode( $rules, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure helper; flags prevent breaking out of the script element.
		return false === $json ? '' : '<script type="speculationrules">' . $json . '</script>';
	}

	/**
	 * Normalize a path to "/…/" form.
	 *
	 * @param string $path Path or URL.
	 */
	public static function dir( string $path ): string {
		$path = trim( $path );
		if ( preg_match( '#^(?:https?:)?//[^/]*(.*)$#i', $path, $m ) ) {
			$path = $m[1];
		}
		$path = (string) preg_replace( '#[?\#].*$#', '', $path );
		$path = '/' . trim( $path, '/' );
		return '/' === $path ? '/' : $path . '/';
	}
}
