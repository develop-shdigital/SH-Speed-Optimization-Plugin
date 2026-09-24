<?php
/**
 * Compares browser probe results of a baseline and a candidate render.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Browser evaluator.
 */
final class BrowserEvaluator {

	/**
	 * Whether a probe result is usable.
	 *
	 * @param mixed $result Result.
	 */
	public static function usable( $result ): bool {
		return is_array( $result ) && empty( $result['timeout'] ) && isset( $result['dom'] ) && is_array( $result['dom'] );
	}

	/**
	 * Compare.
	 *
	 * @param array<string,mixed> $baseline  Baseline probe result.
	 * @param array<string,mixed> $candidate Candidate probe result.
	 * @param string[]            $expected  Expected changes (e.g. "iframes").
	 * @return array{ok:bool,unavailable:bool,failures:string[],warnings:string[]}
	 */
	public static function compare( $baseline, $candidate, array $expected = array() ): array {
		$out = array(
			'ok'          => true,
			'unavailable' => false,
			'failures'    => array(),
			'warnings'    => array(),
		);

		if ( ! self::usable( $baseline ) ) {
			$out['ok']          = false;
			$out['unavailable'] = true;
			$out['warnings'][]  = __( 'The page could not be tested in the browser (it may block being displayed in a frame).', 'sh-speed-optimizer' );
			return $out;
		}

		if ( ! self::usable( $candidate ) ) {
			$out['ok']         = false;
			$out['failures'][] = __( 'The optimized page did not finish loading in the browser test.', 'sh-speed-optimizer' );
			return $out;
		}

		// New JavaScript errors.
		$base_errors = self::error_signatures( (array) ( $baseline['errors'] ?? array() ) );
		$new_errors  = array();
		foreach ( (array) ( $candidate['errors'] ?? array() ) as $error ) {
			if ( ! is_array( $error ) || ! empty( $error['probe'] ) ) {
				continue;
			}
			$signature = self::signature( $error );
			if ( '' !== $signature && ! isset( $base_errors[ $signature ] ) ) {
				$new_errors[ $signature ] = (string) ( $error['msg'] ?? '' );
			}
		}
		if ( ! empty( $new_errors ) ) {
			$out['failures'][] = sprintf(
				/* translators: 1: number of errors, 2: first error message */
				_n( '%1$d new JavaScript error: %2$s', '%1$d new JavaScript errors, e.g. %2$s', count( $new_errors ), 'sh-speed-optimizer' ),
				count( $new_errors ),
				mb_substr( (string) reset( $new_errors ), 0, 160 )
			);
		}

		// New failed resources (same-origin only — third-party network noise is not ours).
		$base_failed = array();
		foreach ( (array) ( $baseline['resource_errors'] ?? array() ) as $failed ) {
			$base_failed[ self::strip_query( (string) ( $failed['url'] ?? '' ) ) ] = true;
		}
		$origin = self::origin( (string) ( $baseline['url'] ?? '' ) );
		foreach ( (array) ( $candidate['resource_errors'] ?? array() ) as $failed ) {
			$url = (string) ( $failed['url'] ?? '' );
			if ( '' === $url || isset( $base_failed[ self::strip_query( $url ) ] ) ) {
				continue;
			}
			if ( '' !== $origin && self::origin( $url ) === $origin ) {
				$out['failures'][] = sprintf(
					/* translators: %s: file URL */
					__( 'A file failed to load: %s', 'sh-speed-optimizer' ),
					mb_substr( $url, 0, 160 )
				);
				break;
			}
		}

		$bd = (array) $baseline['dom'];
		$cd = (array) $candidate['dom'];

		// Functional elements.
		foreach ( array(
			'forms'   => __( 'forms', 'sh-speed-optimizer' ),
			'inputs'  => __( 'form fields', 'sh-speed-optimizer' ),
			'buttons' => __( 'buttons', 'sh-speed-optimizer' ),
		) as $key => $label ) {
			$before = (int) ( $bd[ $key ] ?? 0 );
			$after  = (int) ( $cd[ $key ] ?? 0 );
			if ( $after < $before ) {
				$out['failures'][] = sprintf(
					/* translators: 1: number, 2: element type */
					__( '%1$d %2$s are missing in the browser.', 'sh-speed-optimizer' ),
					$before - $after,
					$label
				);
			}
		}

		// Visible structure.
		$visible_before = (int) ( $bd['visible'] ?? 0 );
		$visible_after  = (int) ( $cd['visible'] ?? 0 );
		if ( $visible_before > 50 && $visible_after < 0.9 * $visible_before ) {
			$out['failures'][] = __( 'Parts of the page are no longer visible.', 'sh-speed-optimizer' );
		}

		$height_before = (int) ( $bd['height'] ?? 0 );
		$height_after  = (int) ( $cd['height'] ?? 0 );
		if ( $height_before > 400 && abs( $height_after - $height_before ) > 0.25 * $height_before ) {
			$out['failures'][] = __( 'The page layout changed significantly.', 'sh-speed-optimizer' );
		}

		foreach ( (array) ( $bd['markers'] ?? array() ) as $selector => $marker ) {
			$before = (int) ( $marker['visible'] ?? 0 );
			$after  = (int) ( $cd['markers'][ $selector ]['visible'] ?? 0 );
			if ( $before > 0 && 0 === $after && ! ( 'iframe' === $selector && in_array( 'iframes', $expected, true ) ) ) {
				$out['failures'][] = sprintf(
					/* translators: %s: CSS selector of a page area, e.g. "nav" */
					__( 'A page area (%s) is no longer visible.', 'sh-speed-optimizer' ),
					$selector
				);
			}
		}

		// Distorted images.
		$base_distorted = array();
		foreach ( (array) ( $baseline['images']['distorted'] ?? array() ) as $img ) {
			$base_distorted[ self::strip_query( (string) ( $img['src'] ?? '' ) ) ] = true;
		}
		foreach ( (array) ( $candidate['images']['distorted'] ?? array() ) as $img ) {
			if ( ! isset( $base_distorted[ self::strip_query( (string) ( $img['src'] ?? '' ) ) ] ) ) {
				$out['failures'][] = __( 'An image is displayed stretched or squashed.', 'sh-speed-optimizer' );
				break;
			}
		}

		// Layout stability.
		$cls_before = (float) ( $baseline['cls'] ?? 0 );
		$cls_after  = (float) ( $candidate['cls'] ?? 0 );
		if ( $cls_after > 0.1 && $cls_after - $cls_before > 0.1 ) {
			$out['failures'][] = sprintf(
				/* translators: 1: layout shift before, 2: layout shift after */
				__( 'The page moves around more while loading (layout shift %1$s → %2$s).', 'sh-speed-optimizer' ),
				number_format_i18n( $cls_before, 2 ),
				number_format_i18n( $cls_after, 2 )
			);
		}

		// Informational: slower LCP.
		$lcp_before = (int) ( $baseline['lcp']['ms'] ?? 0 );
		$lcp_after  = (int) ( $candidate['lcp']['ms'] ?? 0 );
		if ( $lcp_before > 0 && $lcp_after > $lcp_before + 1000 && $lcp_after > 1.5 * $lcp_before ) {
			$out['warnings'][] = __( 'The main content appeared later in the browser test.', 'sh-speed-optimizer' );
		}

		$out['failures'] = array_values( array_unique( $out['failures'] ) );
		$out['ok']       = empty( $out['failures'] );

		return $out;
	}

	/**
	 * Error signature set.
	 *
	 * @param array<int,mixed> $errors Errors.
	 * @return array<string,bool>
	 */
	private static function error_signatures( array $errors ): array {
		$set = array();
		foreach ( $errors as $error ) {
			if ( is_array( $error ) ) {
				$set[ self::signature( $error ) ] = true;
			}
		}
		return $set;
	}

	/**
	 * Normalized error signature: the message without volatile parts. The file
	 * name is deliberately ignored because optimized copies have different names.
	 *
	 * @param array<string,mixed> $error Error.
	 */
	private static function signature( array $error ): string {
		$msg = strtolower( trim( (string) ( $error['msg'] ?? '' ) ) );
		$msg = (string) preg_replace( '/\d+/', '#', $msg );
		return (string) preg_replace( '/\s+/', ' ', $msg );
	}

	/**
	 * URL without query/fragment.
	 *
	 * @param string $url URL.
	 */
	private static function strip_query( string $url ): string {
		return (string) preg_replace( '/[?#].*$/', '', $url );
	}

	/**
	 * Origin of a URL.
	 *
	 * @param string $url URL.
	 */
	private static function origin( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		return strtolower( ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
	}
}
