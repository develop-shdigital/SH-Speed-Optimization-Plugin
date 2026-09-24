<?php
/**
 * Server-side verification.
 *
 * Compares a baseline render (no optimizations) with a candidate render
 * (with the optimizations under test) of the same URL and reports serious
 * regressions: errors, incomplete pages, missing functional elements
 * (forms, buttons, navigation) and missing generated files.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Diagnostics;

use SH\SpeedOptimizer\Core\Filesystem;

defined( 'ABSPATH' ) || exit;

/**
 * Verifier.
 */
final class Verifier {

	/**
	 * Functional markers whose count must never drop.
	 */
	public const STRICT_MARKERS = array( 'forms', 'inputs', 'buttons', 'selects', 'textareas', 'nav', 'h1' );

	/**
	 * Markers that may vary slightly between renders (random content, dates).
	 */
	public const LOOSE_MARKERS = array( 'links', 'images', 'iframes' );

	/**
	 * Build a comparable snapshot from a Loopback response.
	 *
	 * @param array<string,mixed> $response Loopback::get() result.
	 * @return array<string,mixed>
	 */
	public static function snapshot( array $response ): array {
		$body = (string) ( $response['body'] ?? '' );

		return array(
			'ok'        => ! empty( $response['ok'] ),
			'status'    => (int) ( $response['status'] ?? 0 ),
			'time_ms'   => (int) ( $response['time_ms'] ?? 0 ),
			'ttfb_ms'   => (int) ( $response['ttfb_ms'] ?? 0 ),
			'bytes'     => strlen( $body ),
			'error'     => (string) ( $response['error'] ?? '' ),
			'complete'  => false !== stripos( $body, '</html>' ),
			'fatal'     => self::has_fatal_error( $body ),
			'title'     => self::title( $body ),
			'markers'   => self::markers( $body ),
			'generated' => self::generated_assets( $body ),
		);
	}

	/**
	 * Compare baseline and candidate snapshots.
	 *
	 * @param array<string,mixed> $baseline         Baseline snapshot.
	 * @param array<string,mixed> $candidate        Candidate snapshot.
	 * @param string[]            $expected_changes Marker names allowed to change (e.g. "iframes" for facades).
	 * @return array{ok:bool,failures:string[],warnings:string[]}
	 */
	public static function compare( array $baseline, array $candidate, array $expected_changes = array() ): array {
		$failures = array();
		$warnings = array();

		if ( empty( $candidate['ok'] ) || (int) $candidate['status'] >= 500 ) {
			$failures[] = sprintf(
				/* translators: %d: HTTP status code */
				__( 'The optimized page returned an error (HTTP %d).', 'sh-speed-optimizer' ),
				(int) $candidate['status']
			);
			return array(
				'ok'       => false,
				'failures' => $failures,
				'warnings' => $warnings,
			);
		}

		if ( (int) $candidate['status'] !== (int) $baseline['status'] ) {
			$failures[] = sprintf(
				/* translators: 1: status without optimization, 2: status with optimization */
				__( 'The page status changed from %1$d to %2$d.', 'sh-speed-optimizer' ),
				(int) $baseline['status'],
				(int) $candidate['status']
			);
		}

		if ( ! empty( $candidate['fatal'] ) && empty( $baseline['fatal'] ) ) {
			$failures[] = __( 'The optimized page shows a PHP error.', 'sh-speed-optimizer' );
		}

		if ( ! empty( $baseline['complete'] ) && empty( $candidate['complete'] ) ) {
			$failures[] = __( 'The optimized page is incomplete.', 'sh-speed-optimizer' );
		}

		if ( (int) $baseline['bytes'] > 2000 && (int) $candidate['bytes'] < 0.5 * (int) $baseline['bytes'] ) {
			$failures[] = __( 'A large part of the page content is missing after optimization.', 'sh-speed-optimizer' );
		}

		$labels = self::marker_labels();
		$base   = (array) ( $baseline['markers'] ?? array() );
		$cand   = (array) ( $candidate['markers'] ?? array() );

		foreach ( self::STRICT_MARKERS as $marker ) {
			$before = (int) ( $base[ $marker ] ?? 0 );
			$after  = (int) ( $cand[ $marker ] ?? 0 );
			if ( $after < $before && ! in_array( $marker, $expected_changes, true ) ) {
				$failures[] = sprintf(
					/* translators: 1: number, 2: element type such as "forms" */
					__( '%1$d %2$s disappeared from the page.', 'sh-speed-optimizer' ),
					$before - $after,
					$labels[ $marker ] ?? $marker
				);
			}
		}

		foreach ( self::LOOSE_MARKERS as $marker ) {
			$before = (int) ( $base[ $marker ] ?? 0 );
			$after  = (int) ( $cand[ $marker ] ?? 0 );
			if ( $after >= $before || in_array( $marker, $expected_changes, true ) ) {
				continue;
			}
			$tolerance = max( 2, (int) ceil( $before * 0.1 ) );
			if ( $before - $after > $tolerance ) {
				$failures[] = sprintf(
					/* translators: 1: number, 2: element type such as "links" */
					__( '%1$d %2$s disappeared from the page.', 'sh-speed-optimizer' ),
					$before - $after,
					$labels[ $marker ] ?? $marker
				);
			}
		}

		if ( in_array( 'iframes', $expected_changes, true ) ) {
			$removed = (int) ( $base['iframes'] ?? 0 ) - (int) ( $cand['iframes'] ?? 0 );
			$added   = (int) ( $cand['facades'] ?? 0 ) - (int) ( $base['facades'] ?? 0 );
			if ( $removed > 0 && $added < $removed ) {
				$failures[] = __( 'Embedded content disappeared instead of being replaced by a click-to-load preview.', 'sh-speed-optimizer' );
			}
		}

		foreach ( (array) ( $candidate['generated'] ?? array() ) as $asset ) {
			if ( empty( $asset['exists'] ) ) {
				$failures[] = __( 'An optimized file referenced by the page could not be found.', 'sh-speed-optimizer' );
				break;
			}
		}

		$base_time = max( 1, (int) $baseline['time_ms'] );
		$cand_time = (int) $candidate['time_ms'];
		if ( $cand_time > max( 3 * $base_time, $base_time + 2000 ) ) {
			$warnings[] = sprintf(
				/* translators: 1: milliseconds with optimization, 2: milliseconds without */
				__( 'The optimized page took %1$d ms to generate instead of %2$d ms.', 'sh-speed-optimizer' ),
				$cand_time,
				$base_time
			);
		}

		return array(
			'ok'       => empty( $failures ),
			'failures' => array_values( array_unique( $failures ) ),
			'warnings' => $warnings,
		);
	}

	/**
	 * Count functional markers in HTML (outside comments and scripts).
	 *
	 * @param string $html Markup.
	 * @return array<string,int>
	 */
	public static function markers( string $html ): array {
		$clean = (string) preg_replace( '#<!--.*?-->|<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>|<noscript\b[^>]*>.*?</noscript>|<template\b[^>]*>.*?</template>#is', '', $html );

		$count = static function ( string $pattern ) use ( $clean ): int {
			return (int) preg_match_all( $pattern, $clean );
		};

		return array(
			'forms'     => $count( '#<form\b#i' ),
			'inputs'    => $count( '#<input\b(?![^>]*type=["\']?hidden)#i' ),
			'buttons'   => $count( '#<button\b#i' ),
			'selects'   => $count( '#<select\b#i' ),
			'textareas' => $count( '#<textarea\b#i' ),
			'nav'       => $count( '#<nav\b#i' ),
			'h1'        => $count( '#<h1\b#i' ),
			'links'     => $count( '#<a\s[^>]*href=#i' ),
			'images'    => $count( '#<img\b#i' ),
			'iframes'   => $count( '#<iframe\b#i' ),
			'facades'   => $count( '#class=["\'][^"\']*\bshso-facade\b#i' ),
			'scripts'   => (int) preg_match_all( '#<script\b[^>]*\bsrc=#i', $html ),
			'styles'    => (int) preg_match_all( '#<link\b[^>]*rel=["\']?stylesheet#i', $html ),
			'elementor' => $count( '#class=["\'][^"\']*\belementor-widget\b#i' ),
			'woo'       => $count( '#class=["\'][^"\']*\b(woocommerce|wc-block)[\w-]*#i' ),
			'blocks'    => $count( '#class=["\'][^"\']*\bwp-block-#i' ),
		);
	}

	/**
	 * Translated marker labels.
	 *
	 * @return array<string,string>
	 */
	public static function marker_labels(): array {
		return array(
			'forms'     => __( 'forms', 'sh-speed-optimizer' ),
			'inputs'    => __( 'form fields', 'sh-speed-optimizer' ),
			'buttons'   => __( 'buttons', 'sh-speed-optimizer' ),
			'selects'   => __( 'drop-down fields', 'sh-speed-optimizer' ),
			'textareas' => __( 'text areas', 'sh-speed-optimizer' ),
			'nav'       => __( 'navigation menus', 'sh-speed-optimizer' ),
			'h1'        => __( 'main headings', 'sh-speed-optimizer' ),
			'links'     => __( 'links', 'sh-speed-optimizer' ),
			'images'    => __( 'images', 'sh-speed-optimizer' ),
			'iframes'   => __( 'embedded frames', 'sh-speed-optimizer' ),
		);
	}

	/**
	 * Whether the body shows a PHP fatal error or the WordPress critical error screen.
	 *
	 * @param string $html Markup.
	 */
	public static function has_fatal_error( string $html ): bool {
		return (bool) preg_match( '#(<b>(Fatal|Parse) error</b>|PHP (Fatal|Parse) error|Uncaught (Error|Exception)|There has been a critical error on this website)#i', $html );
	}

	/**
	 * Page title.
	 *
	 * @param string $html Markup.
	 */
	private static function title( string $html ): string {
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
			return mb_substr( trim( wp_strip_all_tags( $m[1] ) ), 0, 200 );
		}
		return '';
	}

	/**
	 * Generated (cache directory) assets referenced by the page and whether they exist.
	 *
	 * @param string $html Markup.
	 * @return array<int,array{url:string,exists:bool}>
	 */
	public static function generated_assets( string $html ): array {
		$base = Filesystem::cache_url();
		$root = Filesystem::cache_root();
		$out  = array();

		$base_path = (string) wp_parse_url( $base, PHP_URL_PATH );
		if ( '' === $base_path || ! preg_match_all( '#(?:src|href)=["\']([^"\']+)["\']#i', $html, $m ) ) {
			return $out;
		}

		foreach ( array_unique( $m[1] ) as $url ) {
			$url  = html_entity_decode( $url, ENT_QUOTES );
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( 0 !== strpos( $path, trailingslashit( $base_path ) ) ) {
				continue;
			}
			$relative = substr( $path, strlen( trailingslashit( $base_path ) ) );
			if ( preg_match( '#(^|/)\.\.(/|$)#', $relative ) ) {
				continue;
			}
			$file  = $root . $relative;
			$out[] = array(
				'url'    => $url,
				'exists' => is_file( $file ) && filesize( $file ) > 0,
			);
			if ( count( $out ) >= 100 ) {
				break;
			}
		}

		return $out;
	}
}
