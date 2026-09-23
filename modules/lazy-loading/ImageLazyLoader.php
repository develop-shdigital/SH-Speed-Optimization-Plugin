<?php
/**
 * Native lazy loading decisions and transformation for <img>.
 *
 * Pure: page data, exclusions and the site host are passed in. Images that
 * are likely visible when the page opens are never lazy loaded: the measured
 * LCP and above-the-fold images, images with fetchpriority="high", logos,
 * header images, the first image of the body and (by position) the first
 * content images. Images handled by another lazy loader, tracking pixels and
 * images that already declare a loading attribute are left alone.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\LazyLoading;

use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Images\ImageUrls;
use SH\SpeedOptimizer\Assets\Images\MarkupRanges;
use SH\SpeedOptimizer\Assets\Tag;

defined( 'ABSPATH' ) || exit;

/**
 * Image lazy loader.
 */
final class ImageLazyLoader {

	/**
	 * Content images protected by position when no browser measurement exists.
	 */
	public const DEFAULT_PROTECTED = 3;

	public const ACTION_LAZY  = 'lazy';
	public const ACTION_EAGER = 'eager';
	public const ACTION_SKIP  = 'skip';

	/**
	 * Attributes used by JavaScript lazy loaders.
	 */
	public const OTHER_LOADER_ATTRIBUTES = array( 'data-src', 'data-lazy-src', 'data-srcset', 'data-lazy-srcset', 'data-original', 'data-bg', 'data-lazyload' );

	/**
	 * Classes used by JavaScript lazy loaders.
	 */
	public const OTHER_LOADER_CLASSES = array( 'lazyload', 'lazy', 'lazyloaded', 'lazyloading', 'lazy-load', 'lazy-hidden', 'rocket-lazyload', 'perfmatters-lazy', 'litespeed-lazy' );

	/**
	 * Exclusion patterns.
	 *
	 * @var string[]
	 */
	private array $exclude;

	/**
	 * Canonical LCP image URL ('' = unknown).
	 *
	 * @var string
	 */
	private string $lcp = '';

	/**
	 * Canonical above-the-fold image URLs.
	 *
	 * @var array<string,bool>
	 */
	private array $above = array();

	/**
	 * Content images protected by position.
	 *
	 * @var int
	 */
	private int $protect_first;

	/**
	 * Site host.
	 *
	 * @var string
	 */
	private string $home_host;

	/**
	 * Constructor.
	 *
	 * @param string[]            $exclude   Exclusion patterns (rules lazy_exclude).
	 * @param array<string,mixed> $page_data Browser measurements for the template.
	 * @param string              $home_host Site host.
	 */
	public function __construct( array $exclude, array $page_data, string $home_host = '' ) {
		$this->exclude   = array_values( array_filter( array_map( 'strval', $exclude ) ) );
		$this->home_host = $home_host;

		$lcp = is_array( $page_data['lcp'] ?? null ) ? $page_data['lcp'] : array();
		if ( in_array( (string) ( $lcp['type'] ?? 'img' ), array( 'img', '' ), true ) && is_string( $lcp['url'] ?? null ) ) {
			$this->lcp = ImageUrls::canonical( $lcp['url'], $home_host );
		}
		foreach ( (array) ( $page_data['above_fold_images'] ?? array() ) as $url ) {
			if ( is_string( $url ) ) {
				$canonical = ImageUrls::canonical( $url, $home_host );
				if ( '' !== $canonical ) {
					$this->above[ $canonical ] = true;
				}
			}
		}

		$this->protect_first = self::protected_count( $page_data );
	}

	/**
	 * How many content images are protected by position.
	 *
	 * Without measurements the first three. With measurements as many as were measured above the
	 * fold (at most three): other pages of the same template usually share the layout but not the
	 * image URLs, so the position rule generalizes the measurement.
	 *
	 * @param array<string,mixed> $page_data Page data.
	 */
	public static function protected_count( array $page_data ): int {
		if ( ! isset( $page_data['above_fold_images'] ) && empty( $page_data['lcp'] ) ) {
			return self::DEFAULT_PROTECTED;
		}
		$count = is_array( $page_data['above_fold_images'] ?? null ) ? count( $page_data['above_fold_images'] ) : 0;
		if ( 'img' === ( $page_data['lcp']['type'] ?? '' ) ) {
			$count = max( 1, $count );
		}
		return min( self::DEFAULT_PROTECTED, $count );
	}

	/**
	 * Transform a document.
	 *
	 * @param HtmlDocument $doc Document.
	 * @return int Changed images.
	 */
	public function transform( HtmlDocument $doc ): int {
		$body_start = MarkupRanges::body_start( $doc );
		$headers    = $doc->contains( '<header' ) ? MarkupRanges::elements( $doc, 'header' ) : array();
		$first_seen = false;
		$content    = 0;

		return $doc->replace_tags(
			'img',
			function ( Tag $tag, array $info ) use ( $body_start, $headers, &$first_seen, &$content ) {
				if ( 'img' !== $tag->name || $info['in_head'] || (int) $info['offset'] < $body_start ) {
					return null;
				}

				$position = array(
					'first'     => ! $first_seen,
					'in_header' => MarkupRanges::inside( (int) $info['offset'], $headers ),
					'index'     => 0,
				);
				$first_seen = true;

				if ( ! $position['first'] && ! $position['in_header'] && ! self::is_pixel( $tag ) && ! self::is_logo( $tag ) ) {
					$position['index'] = ++$content;
				}

				$action = $this->decide( $tag, $position );
				if ( self::ACTION_LAZY === $action[0] ) {
					$tag->set( 'loading', 'lazy' );
					if ( ! $tag->has( 'decoding' ) ) {
						$tag->set( 'decoding', 'async' );
					}
					return $tag;
				}
				if ( self::ACTION_EAGER === $action[0] ) {
					$tag->remove( 'loading' );
					$sizes = $tag->get( 'sizes' );
					if ( null !== $sizes && preg_match( '/^\s*auto\s*,\s*/i', $sizes ) ) {
						$tag->set( 'sizes', (string) preg_replace( '/^\s*auto\s*,\s*/i', '', $sizes ) );
					}
					return $tag;
				}
				return null;
			}
		);
	}

	/**
	 * Decide what to do with an image.
	 *
	 * @param Tag                                           $tag      Image tag.
	 * @param array{first:bool,in_header:bool,index:int}    $position Position: first image of the body, inside <header>, content index (1-based, 0 = not a content image).
	 * @return array{0:string,1:string} Action and reason.
	 */
	public function decide( Tag $tag, array $position ): array {
		$candidates = ImageUrls::candidates( $tag->get( 'src' ), $tag->get( 'srcset' ) );
		$loading    = $tag->get( 'loading' );

		if ( '' !== $this->lcp && $this->matches( $candidates, array( $this->lcp => true ) ) ) {
			if ( null !== $loading && 'lazy' === strtolower( trim( $loading ) ) ) {
				return array( self::ACTION_EAGER, 'lcp' );
			}
			return array( self::ACTION_SKIP, 'lcp' );
		}
		if ( null !== $loading ) {
			return array( self::ACTION_SKIP, 'has-loading' );
		}
		if ( self::is_pixel( $tag ) ) {
			return array( self::ACTION_SKIP, 'pixel' );
		}
		if ( self::uses_other_loader( $tag ) ) {
			return array( self::ACTION_SKIP, 'other-loader' );
		}
		$src = trim( (string) $tag->get( 'src' ) );
		if ( '' === $src || 0 === stripos( $src, 'data:' ) ) {
			return array( self::ACTION_SKIP, 'no-src' );
		}
		if ( 'high' === strtolower( trim( (string) $tag->get( 'fetchpriority' ) ) ) ) {
			return array( self::ACTION_SKIP, 'fetchpriority' );
		}
		if ( ! empty( $this->above ) && $this->matches( $candidates, $this->above ) ) {
			return array( self::ACTION_SKIP, 'above-fold' );
		}
		if ( $this->excluded( $tag ) ) {
			return array( self::ACTION_SKIP, 'excluded' );
		}
		if ( self::is_logo( $tag ) ) {
			return array( self::ACTION_SKIP, 'logo' );
		}
		if ( ! empty( $position['in_header'] ) ) {
			return array( self::ACTION_SKIP, 'header' );
		}
		if ( ! empty( $position['first'] ) ) {
			return array( self::ACTION_SKIP, 'first' );
		}
		if ( (int) ( $position['index'] ?? 0 ) <= $this->protect_first ) {
			return array( self::ACTION_SKIP, 'position' );
		}
		return array( self::ACTION_LAZY, '' );
	}

	/**
	 * Whether any candidate URL is in a canonical set.
	 *
	 * @param string[]           $candidates Candidate URLs.
	 * @param array<string,bool> $set        Canonical URLs.
	 */
	private function matches( array $candidates, array $set ): bool {
		foreach ( $candidates as $url ) {
			$canonical = ImageUrls::canonical( $url, $this->home_host );
			if ( '' !== $canonical && isset( $set[ $canonical ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the image matches a lazy-load exclusion (class, id, src or attribute name).
	 *
	 * @param Tag $tag Image tag.
	 */
	private function excluded( Tag $tag ): bool {
		if ( empty( $this->exclude ) ) {
			return false;
		}
		$haystacks = array(
			(string) $tag->get( 'class' ),
			(string) $tag->get( 'id' ),
			(string) $tag->get( 'src' ),
			implode( ' ', array_keys( $tag->attributes() ) ),
		);
		foreach ( $this->exclude as $needle ) {
			foreach ( $haystacks as $haystack ) {
				if ( '' !== $haystack && false !== stripos( $haystack, $needle ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether the image looks like a logo.
	 *
	 * @param Tag $tag Image tag.
	 */
	public static function is_logo( Tag $tag ): bool {
		foreach ( array( 'class', 'id', 'alt', 'src' ) as $attribute ) {
			if ( false !== stripos( (string) $tag->get( $attribute ), 'logo' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the image is a tracking pixel (both known dimensions ≤ 2 px).
	 *
	 * @param Tag $tag Image tag.
	 */
	public static function is_pixel( Tag $tag ): bool {
		$width  = $tag->get( 'width' );
		$height = $tag->get( 'height' );
		$w      = null !== $width && preg_match( '/^\s*(\d+)(?:px)?\s*$/i', $width, $m ) ? (int) $m[1] : null;
		$h      = null !== $height && preg_match( '/^\s*(\d+)(?:px)?\s*$/i', $height, $n ) ? (int) $n[1] : null;
		if ( null === $w && null === $h ) {
			return false;
		}
		return ( null === $w || $w <= 2 ) && ( null === $h || $h <= 2 );
	}

	/**
	 * Whether a JavaScript lazy loader already handles the image.
	 *
	 * @param Tag $tag Image tag.
	 */
	public static function uses_other_loader( Tag $tag ): bool {
		foreach ( self::OTHER_LOADER_ATTRIBUTES as $attribute ) {
			if ( $tag->has( $attribute ) ) {
				return true;
			}
		}
		foreach ( self::OTHER_LOADER_CLASSES as $class ) {
			if ( $tag->has_class( $class ) ) {
				return true;
			}
		}
		return false;
	}
}
