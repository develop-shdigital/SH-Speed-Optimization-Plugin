<?php
/**
 * Adds missing width/height attributes to local images.
 *
 * Pure transformer: the size lookup is injected. Images are skipped when
 * either dimension attribute exists, when an inline style sets width or
 * height, for SVGs, for images inside art-directed <picture> elements
 * (sources with media queries may have other aspect ratios) and whenever the
 * size is unknown (external images).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\ImageOptimization;

use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Images\ImageUrls;
use SH\SpeedOptimizer\Assets\Images\MarkupRanges;
use SH\SpeedOptimizer\Assets\Tag;

defined( 'ABSPATH' ) || exit;

/**
 * Dimension filler.
 */
final class DimensionFiller {

	/**
	 * Size lookup: function( string $src, string $srcset, string[] $classes ): array{0:int,1:int}|null.
	 *
	 * @var callable
	 */
	private $resolve;

	/**
	 * Constructor.
	 *
	 * @param callable $resolve Size lookup.
	 */
	public function __construct( callable $resolve ) {
		$this->resolve = $resolve;
	}

	/**
	 * Transform a document.
	 *
	 * @param HtmlDocument $doc Document.
	 * @return int Changed images.
	 */
	public function transform( HtmlDocument $doc ): int {
		$art_directed = array();
		if ( $doc->contains( '<picture' ) ) {
			foreach ( MarkupRanges::elements( $doc, 'picture' ) as $range ) {
				if ( preg_match( '#<source\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*\smedia\s*=#i', $range[2] ) ) {
					$art_directed[] = $range;
				}
			}
		}

		return $doc->replace_tags(
			'img',
			function ( Tag $tag, array $info ) use ( $art_directed ) {
				if ( 'img' !== $tag->name || $info['in_head'] || MarkupRanges::inside( (int) $info['offset'], $art_directed ) ) {
					return null;
				}
				if ( ! self::needs_dimensions( $tag ) ) {
					return null;
				}
				$size = ( $this->resolve )( (string) $tag->get( 'src' ), (string) $tag->get( 'srcset' ), $tag->classes() );
				if ( ! is_array( $size ) || (int) $size[0] <= 0 || (int) $size[1] <= 0 ) {
					return null;
				}
				$tag->set( 'width', (string) (int) $size[0] );
				$tag->set( 'height', (string) (int) $size[1] );
				return $tag;
			}
		);
	}

	/**
	 * Whether an image is a candidate at all (before the size lookup).
	 *
	 * @param Tag $tag Image tag.
	 */
	public static function needs_dimensions( Tag $tag ): bool {
		if ( $tag->has( 'width' ) || $tag->has( 'height' ) ) {
			return false;
		}
		$src = trim( (string) $tag->get( 'src' ) );
		if ( '' === $src || 0 === stripos( $src, 'data:' ) || ImageUrls::is_svg( $src ) ) {
			return false;
		}
		// Another lazy loader swaps src later: the current src is only a placeholder.
		foreach ( array( 'data-src', 'data-lazy-src', 'data-srcset', 'data-lazy-srcset', 'data-original' ) as $attribute ) {
			if ( $tag->has( $attribute ) ) {
				return false;
			}
		}
		if ( $tag->has_class( 'lazyload' ) || $tag->has_class( 'lazy' ) ) {
			return false;
		}
		$style = (string) $tag->get( 'style' );
		if ( '' !== $style && preg_match( '/(?:^|[;{\s])(?:width|height)\s*:/i', $style ) ) {
			return false;
		}
		return true;
	}
}
