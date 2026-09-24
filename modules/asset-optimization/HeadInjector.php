<?php
/**
 * Insert markup early in <head> without pushing the charset declaration
 * beyond the first 1024 bytes.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\AssetOptimization;

use SH\SpeedOptimizer\Assets\HtmlDocument;

defined( 'ABSPATH' ) || exit;

/**
 * Early head insertion helper.
 */
final class HeadInjector {

	/**
	 * Insert markup right after <head> — or after the charset / content-type / CSP meta tags
	 * when they come before any script, style or link element.
	 *
	 * @param HtmlDocument $doc    Document.
	 * @param string       $markup Markup.
	 * @return bool Whether it was inserted.
	 */
	public static function insert_early( HtmlDocument $doc, string $markup ): bool {
		$done = false;
		$doc->with_mask(
			array(),
			static function ( string $masked ) use ( $markup, &$done ) {
				if ( ! preg_match( '#<head\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i', $masked, $m, PREG_OFFSET_CAPTURE ) ) {
					return $masked;
				}
				$start = (int) $m[0][1] + strlen( $m[0][0] );
				$end   = stripos( $masked, '</head>', $start );
				$end   = false === $end ? strlen( $masked ) : $end;

				// Scripts/styles/comments are masked with \x1A markers; links stay visible.
				$limit = $end;
				foreach ( array( "\x1A", '<link', '<LINK' ) as $needle ) {
					$pos = strpos( $masked, $needle, $start );
					if ( false !== $pos && $pos < $limit ) {
						$limit = $pos;
					}
				}

				$position = $start;
				if ( preg_match_all( '#<meta\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i', substr( $masked, $start, $limit - $start ), $metas, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $metas[0] as $meta ) {
						if ( preg_match( '#\bcharset\s*=|http-equiv\s*=\s*["\']?\s*(content-type|content-security-policy)#i', $meta[0] ) ) {
							$position = max( $position, $start + (int) $meta[1] + strlen( $meta[0] ) );
						}
					}
				}

				$done = true;
				return substr( $masked, 0, $position ) . $markup . substr( $masked, $position );
			}
		);
		return $done;
	}
}
