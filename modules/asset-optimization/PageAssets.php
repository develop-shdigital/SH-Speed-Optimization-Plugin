<?php
/**
 * Helpers for reading asset data from scanned page analyses.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\AssetOptimization;

use SH\SpeedOptimizer\Optimization\Assessment;

defined( 'ABSPATH' ) || exit;

/**
 * Page asset statistics.
 */
final class PageAssets {

	/**
	 * Unique local, not yet minified assets (by URL without query string).
	 *
	 * @param array<int,array<string,mixed>> $items    Items from AssessmentContext::collect( 'styles'|'scripts' ).
	 * @param string                         $url_key  "href" for styles, "src" for scripts.
	 * @param callable                       $excluded function( string $handle, string $url ): bool.
	 * @return array{count:int,bytes:int,unknown_size:int}
	 */
	public static function unminified( array $items, string $url_key, callable $excluded ): array {
		$seen    = array();
		$unknown = 0;
		foreach ( $items as $item ) {
			if ( empty( $item['local'] ) || ! empty( $item['minified'] ) ) {
				continue;
			}
			$url = trim( (string) ( $item[ $url_key ] ?? '' ) );
			if ( '' === $url ) {
				continue;
			}
			$key = (string) preg_replace( '/[?#].*$/s', '', $url );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			if ( $excluded( (string) ( $item['handle'] ?? '' ), $url ) ) {
				continue;
			}
			if ( ! isset( $item['bytes'] ) || null === $item['bytes'] ) {
				++$unknown;
			}
			$seen[ $key ] = (int) ( $item['bytes'] ?? 0 );
		}
		return array(
			'count'        => count( $seen ),
			'bytes'        => array_sum( $seen ),
			'unknown_size' => $unknown,
		);
	}

	/**
	 * Benefit of minifying files of a given total size.
	 *
	 * @param int $bytes Total bytes of unminified files.
	 * @param int $count Number of files.
	 */
	public static function minify_benefit( int $bytes, int $count ): string {
		if ( $bytes > 150 * 1024 ) {
			return Assessment::BENEFIT_HIGH;
		}
		if ( $bytes > 30 * 1024 ) {
			return Assessment::BENEFIT_MEDIUM;
		}
		return $count > 0 ? Assessment::BENEFIT_LOW : Assessment::BENEFIT_NONE;
	}

	/**
	 * Human readable size.
	 *
	 * @param int $bytes Bytes.
	 */
	public static function size( int $bytes ): string {
		return $bytes >= 1048576 ? round( $bytes / 1048576, 1 ) . ' MB' : max( 1, (int) round( $bytes / 1024 ) ) . ' KB';
	}
}
