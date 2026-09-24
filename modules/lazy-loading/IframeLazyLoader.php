<?php
/**
 * Native lazy loading for <iframe>.
 *
 * Pure transformer. The first iframe stays eager when it appears before the
 * first h1/h2 (likely hero content) or when measurements say a video or map
 * is visible without scrolling. Hidden and tiny iframes (payment, captcha,
 * tracking frames) are never lazy loaded: some browsers would never load
 * them.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\LazyLoading;

use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Images\MarkupRanges;
use SH\SpeedOptimizer\Assets\Tag;

defined( 'ABSPATH' ) || exit;

/**
 * Iframe lazy loader.
 */
final class IframeLazyLoader {

	/**
	 * Exclusion patterns.
	 *
	 * @var string[]
	 */
	private array $exclude;

	/**
	 * Whether a video/map is visible without scrolling on this template.
	 *
	 * @var bool
	 */
	private bool $media_in_viewport;

	/**
	 * Constructor.
	 *
	 * @param string[] $exclude           Exclusion patterns (rules lazy_exclude).
	 * @param bool     $media_in_viewport Page data video_in_viewport || map_in_viewport.
	 */
	public function __construct( array $exclude, bool $media_in_viewport = false ) {
		$this->exclude           = array_values( array_filter( array_map( 'strval', $exclude ) ) );
		$this->media_in_viewport = $media_in_viewport;
	}

	/**
	 * Transform a document.
	 *
	 * @param HtmlDocument $doc Document.
	 * @return int Changed iframes.
	 */
	public function transform( HtmlDocument $doc ): int {
		if ( ! $doc->contains( '<iframe' ) ) {
			return 0;
		}
		$heading = MarkupRanges::first_heading( $doc );
		$index   = 0;

		return $doc->replace_tags(
			'iframe',
			function ( Tag $tag, array $info ) use ( $heading, &$index ) {
				if ( 'iframe' !== $tag->name || $info['in_head'] ) {
					return null;
				}
				$first = 0 === $index++;
				if ( ! $this->should_lazy_load( $tag, $first, (int) $info['offset'] < $heading ) ) {
					return null;
				}
				return $tag->set( 'loading', 'lazy' );
			}
		);
	}

	/**
	 * Decision for one iframe.
	 *
	 * @param Tag  $tag            Iframe tag.
	 * @param bool $first          First iframe of the body.
	 * @param bool $before_heading Appears before the first h1/h2.
	 */
	public function should_lazy_load( Tag $tag, bool $first, bool $before_heading ): bool {
		if ( $tag->has( 'loading' ) ) {
			return false;
		}
		if ( $first && ( $before_heading || $this->media_in_viewport ) ) {
			return false;
		}

		$src = trim( (string) $tag->get( 'src' ) );
		if ( '' === $src || preg_match( '#^(about|javascript|data):#i', $src ) ) {
			return false;
		}
		foreach ( array( 'data-src', 'data-lazy-src', 'data-rocket-lazy-src' ) as $attribute ) {
			if ( $tag->has( $attribute ) ) {
				return false;
			}
		}
		if ( $tag->has_class( 'lazyload' ) || $tag->has_class( 'lazy' ) ) {
			return false;
		}
		if ( self::is_hidden( $tag ) ) {
			return false;
		}

		$haystacks = array( $src, (string) $tag->get( 'class' ), (string) $tag->get( 'id' ), implode( ' ', array_keys( $tag->attributes() ) ) );
		foreach ( $this->exclude as $needle ) {
			foreach ( $haystacks as $haystack ) {
				if ( '' !== $haystack && false !== stripos( $haystack, $needle ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Hidden or tiny iframe (≤ 4 px, display:none, visibility:hidden, hidden attribute).
	 *
	 * @param Tag $tag Iframe tag.
	 */
	public static function is_hidden( Tag $tag ): bool {
		if ( $tag->has( 'hidden' ) || 'true' === strtolower( (string) $tag->get( 'aria-hidden' ) ) ) {
			return true;
		}
		foreach ( array( 'width', 'height' ) as $attribute ) {
			$value = $tag->get( $attribute );
			if ( null !== $value && preg_match( '/^\s*(\d+)(?:px)?\s*$/i', $value, $m ) && (int) $m[1] <= 4 ) {
				return true;
			}
		}
		$style = strtolower( (string) $tag->get( 'style' ) );
		return '' !== $style && (bool) preg_match( '/display\s*:\s*none|visibility\s*:\s*hidden|(?:^|[;\s])(?:width|height)\s*:\s*[0-4](?:px)?\s*(?:;|$)/', $style );
	}
}
