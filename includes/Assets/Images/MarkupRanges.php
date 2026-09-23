<?php
/**
 * Offsets of structural regions in a masked document.
 *
 * {@see HtmlDocument::replace_tags()} reports each tag's offset in the masked
 * document. Masking is deterministic, so offsets computed here with
 * {@see HtmlDocument::with_mask()} (without changing anything) line up with
 * those offsets as long as the document is not modified in between.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets\Images;

use SH\SpeedOptimizer\Assets\HtmlDocument;

defined( 'ABSPATH' ) || exit;

/**
 * Region helpers.
 */
final class MarkupRanges {

	/**
	 * Start/end offsets of every element of a type (non-nested matching; nested elements of the
	 * same type end at the first closing tag, which is fine for header/picture/figure).
	 *
	 * @param HtmlDocument $doc     Document.
	 * @param string       $element Element name.
	 * @return array<int,array{0:int,1:int,2:string}> [ start, end, outer markup ].
	 */
	public static function elements( HtmlDocument $doc, string $element ): array {
		$ranges  = array();
		$element = preg_quote( strtolower( $element ), '#' );
		$doc->with_mask(
			array(),
			static function ( string $masked ) use ( $element, &$ranges ) {
				if ( preg_match_all( '#<' . $element . '\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>.*?</' . $element . '\s*>#is', $masked, $m, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $m[0] as $match ) {
						$ranges[] = array( (int) $match[1], (int) $match[1] + strlen( $match[0] ), $match[0] );
					}
				}
				return $masked;
			}
		);
		return $ranges;
	}

	/**
	 * Offset of the first match of a pattern in the masked document, or null.
	 *
	 * @param HtmlDocument $doc     Document.
	 * @param string       $pattern Regular expression.
	 */
	public static function first( HtmlDocument $doc, string $pattern ): ?int {
		$offset = null;
		$doc->with_mask(
			array(),
			static function ( string $masked ) use ( $pattern, &$offset ) {
				if ( preg_match( $pattern, $masked, $m, PREG_OFFSET_CAPTURE ) ) {
					$offset = (int) $m[0][1];
				}
				return $masked;
			}
		);
		return $offset;
	}

	/**
	 * Offset where the body starts (after "<body …>"), or 0.
	 *
	 * @param HtmlDocument $doc Document.
	 */
	public static function body_start( HtmlDocument $doc ): int {
		return (int) self::first( $doc, '#<body\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i' );
	}

	/**
	 * Offset of the first <h1> or <h2>, or PHP_INT_MAX when there is none.
	 *
	 * @param HtmlDocument $doc Document.
	 */
	public static function first_heading( HtmlDocument $doc ): int {
		$offset = self::first( $doc, '#<h[12]\b#i' );
		return null === $offset ? PHP_INT_MAX : $offset;
	}

	/**
	 * Whether an offset lies inside one of the ranges.
	 *
	 * @param int                     $offset Offset.
	 * @param array<int,array<mixed>> $ranges Ranges from {@see elements()}.
	 */
	public static function inside( int $offset, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( $offset >= $range[0] && $offset < $range[1] ) {
				return true;
			}
		}
		return false;
	}
}
