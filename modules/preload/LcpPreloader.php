<?php
/**
 * Prioritizes the measured Largest Contentful Paint image.
 *
 * Pure transformer. Acts only on a high-confidence browser measurement and
 * only when the measured image is actually present in the current document
 * (page data is shared by all URLs of a template): the matching <img> gets
 * fetchpriority="high" (and loses loading="lazy"), and one preload is added
 * to <head>. Never more than one preload; nothing is done when another image
 * already has fetchpriority="high".
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\Preload;

use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Images\ImageUrls;
use SH\SpeedOptimizer\Assets\Images\MarkupRanges;
use SH\SpeedOptimizer\Assets\Tag;

defined( 'ABSPATH' ) || exit;

/**
 * LCP preloader.
 */
final class LcpPreloader {

	/**
	 * Minimum measurement confidence.
	 */
	public const MIN_CONFIDENCE = 80;

	/**
	 * Measurement: url, type, selector, confidence.
	 *
	 * @var array<string,mixed>
	 */
	private array $lcp;

	/**
	 * Canonical LCP URL.
	 *
	 * @var string
	 */
	private string $canonical = '';

	/**
	 * Site host.
	 *
	 * @var string
	 */
	private string $home_host;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $lcp       Page data "lcp" entry.
	 * @param string              $home_host Site host.
	 */
	public function __construct( array $lcp, string $home_host = '' ) {
		$this->lcp       = $lcp;
		$this->home_host = $home_host;
		if ( is_string( $lcp['url'] ?? null ) && preg_match( '#^(?:https?:)?//#i', trim( $lcp['url'] ) ) ) {
			$this->canonical = ImageUrls::canonical( $lcp['url'], $home_host );
		}
	}

	/**
	 * Whether the measurement qualifies.
	 */
	public function is_usable(): bool {
		return '' !== $this->canonical
			&& in_array( (string) ( $this->lcp['type'] ?? '' ), array( 'img', 'background' ), true )
			&& (int) ( $this->lcp['confidence'] ?? 0 ) >= self::MIN_CONFIDENCE;
	}

	/**
	 * Transform a document.
	 *
	 * @param HtmlDocument $doc Document.
	 * @return bool Whether anything changed.
	 */
	public function transform( HtmlDocument $doc ): bool {
		if ( ! $this->is_usable() ) {
			return false;
		}
		if ( 'background' === $this->lcp['type'] ) {
			return $this->preload_background( $doc );
		}
		return $this->prioritize_image( $doc );
	}

	/**
	 * Image LCP.
	 *
	 * @param HtmlDocument $doc Document.
	 */
	private function prioritize_image( HtmlDocument $doc ): bool {
		$pictures = $doc->contains( '<picture' ) ? $this->matching_pictures( $doc ) : array();

		$match      = null;
		$other_high = false;
		$doc->replace_tags(
			'img',
			function ( Tag $tag, array $info ) use ( &$match, &$other_high, $pictures ) {
				if ( 'img' !== $tag->name || $info['in_head'] ) {
					return null;
				}
				$in_picture = MarkupRanges::inside( (int) $info['offset'], $pictures );
				$is_match   = $in_picture || $this->matches( $tag );
				$is_high    = 'high' === strtolower( trim( (string) $tag->get( 'fetchpriority' ) ) );
				if ( $is_match && null === $match ) {
					$match = array(
						'index'      => (int) $info['index'],
						'tag'        => $tag,
						'in_picture' => $in_picture,
					);
				} elseif ( $is_high && ! $is_match ) {
					$other_high = true;
				}
				return null;
			}
		);

		if ( null === $match || $other_high ) {
			return false;
		}

		/** Matched image tag. @var Tag $tag */
		$tag     = $match['tag'];
		$js_lazy = self::uses_js_lazy_loader( $tag );
		$changed = false;
		$target  = $match['index'];

		if ( ! $js_lazy ) {
			$changed = $doc->replace_tags(
				'img',
				static function ( Tag $img, array $info ) use ( $target ) {
					if ( 'img' !== $img->name || (int) $info['index'] !== $target ) {
						return null;
					}
					$img->set( 'fetchpriority', 'high' );
					if ( 'lazy' === strtolower( trim( (string) $img->get( 'loading' ) ) ) ) {
						$img->remove( 'loading' );
						$sizes = $img->get( 'sizes' );
						if ( null !== $sizes && preg_match( '/^\s*auto\s*,\s*/i', $sizes ) ) {
							$img->set( 'sizes', (string) preg_replace( '/^\s*auto\s*,\s*/i', '', $sizes ) );
						}
					}
					return $img;
				}
			) > 0;
		}

		// A <picture> source depends on media/type conditions: a preload could fetch the wrong file.
		if ( $match['in_picture'] ) {
			return $changed;
		}

		$src    = self::first_attribute( $tag, array( 'data-lazy-src', 'data-src', 'src' ), $js_lazy );
		$srcset = self::first_attribute( $tag, array( 'data-lazy-srcset', 'data-srcset', 'srcset' ), $js_lazy );
		$sizes  = trim( (string) preg_replace( '/^\s*auto\s*,\s*/i', '', (string) $tag->get( 'sizes' ) ) );

		if ( '' === $src || 0 === stripos( $src, 'data:' ) ) {
			if ( '' === $srcset ) {
				return $changed;
			}
			$src = '';
		}

		if ( $this->already_preloaded( $doc, ImageUrls::candidates( $src, $srcset ) ) ) {
			return $changed;
		}

		$link = Tag::create(
			'link',
			array(
				'rel' => 'preload',
				'as'  => 'image',
			)
		);
		if ( '' !== $src ) {
			$link->set( 'href', $src );
		}
		$link->set( 'fetchpriority', 'high' );
		if ( '' !== $srcset ) {
			$link->set( 'imagesrcset', $srcset );
			if ( '' !== $sizes && 'auto' !== strtolower( $sizes ) ) {
				$link->set( 'imagesizes', $sizes );
			}
		}

		return Hints::insert_early( $doc, $link->to_html() ) || $changed;
	}

	/**
	 * Background LCP: preload only, and only when the URL appears in the document markup.
	 *
	 * @param HtmlDocument $doc Document.
	 */
	private function preload_background( HtmlDocument $doc ): bool {
		$found = null;
		if ( preg_match_all( '/url\(\s*(?:&quot;|&#039;|["\'])?([^"\'()\s&]+(?:&amp;[^"\'()\s&]+)*)/i', $doc->html(), $m ) ) {
			foreach ( $m[1] as $raw ) {
				$url = html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( ImageUrls::canonical( $url, $this->home_host ) === $this->canonical && preg_match( '#^(?:https?:)?//|^/#i', $url ) ) {
					$found = $url;
					break;
				}
			}
		}
		if ( null === $found || $this->already_preloaded( $doc, array( $found ) ) ) {
			return false;
		}

		$link = Tag::create(
			'link',
			array(
				'rel'           => 'preload',
				'as'            => 'image',
				'href'          => $found,
				'fetchpriority' => 'high',
			)
		);
		return Hints::insert_early( $doc, $link->to_html() );
	}

	/**
	 * Whether an image tag references the LCP URL.
	 *
	 * @param Tag $tag Image tag.
	 */
	private function matches( Tag $tag ): bool {
		$urls = array_merge(
			ImageUrls::candidates( $tag->get( 'src' ), $tag->get( 'srcset' ) ),
			ImageUrls::candidates( $tag->get( 'data-src' ), $tag->get( 'data-srcset' ) ),
			ImageUrls::candidates( $tag->get( 'data-lazy-src' ), $tag->get( 'data-lazy-srcset' ) )
		);
		foreach ( $urls as $url ) {
			if ( ImageUrls::canonical( $url, $this->home_host ) === $this->canonical ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * <picture> elements with a <source> candidate matching the LCP URL.
	 *
	 * @param HtmlDocument $doc Document.
	 * @return array<int,array<mixed>>
	 */
	private function matching_pictures( HtmlDocument $doc ): array {
		$out = array();
		foreach ( MarkupRanges::elements( $doc, 'picture' ) as $range ) {
			if ( ! preg_match_all( '#<source\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i', $range[2], $m ) ) {
				continue;
			}
			foreach ( $m[0] as $markup ) {
				$source = Tag::parse( $markup );
				if ( null === $source ) {
					continue;
				}
				foreach ( ImageUrls::candidates( null, $source->get( 'srcset' ) ) as $url ) {
					if ( ImageUrls::canonical( $url, $this->home_host ) === $this->canonical ) {
						$out[] = $range;
						continue 3;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Whether an image preload for one of the URLs exists already.
	 *
	 * @param HtmlDocument $doc  Document.
	 * @param string[]     $urls Candidate URLs.
	 */
	private function already_preloaded( HtmlDocument $doc, array $urls ): bool {
		if ( ! $doc->contains( 'preload' ) ) {
			return false;
		}
		$wanted = array();
		foreach ( $urls as $url ) {
			$canonical = ImageUrls::canonical( $url, $this->home_host );
			if ( '' !== $canonical ) {
				$wanted[ $canonical ] = true;
			}
		}
		$wanted[ $this->canonical ] = true;

		foreach ( Hints::links( $doc ) as $link ) {
			$tag = $link[0];
			if ( ! Hints::rel_has( $tag, 'preload' ) || 'image' !== strtolower( (string) $tag->get( 'as' ) ) ) {
				continue;
			}
			foreach ( ImageUrls::candidates( $tag->get( 'href' ), $tag->get( 'imagesrcset' ) ) as $url ) {
				if ( isset( $wanted[ ImageUrls::canonical( $url, $this->home_host ) ] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * First non-empty attribute (data-* ones only for JavaScript lazy loaded images).
	 *
	 * @param Tag      $tag        Tag.
	 * @param string[] $attributes Attributes in order.
	 * @param bool     $js_lazy    Whether data-* attributes hold the real URLs.
	 */
	private static function first_attribute( Tag $tag, array $attributes, bool $js_lazy ): string {
		foreach ( $attributes as $attribute ) {
			if ( ! $js_lazy && 0 === strpos( $attribute, 'data-' ) ) {
				continue;
			}
			$value = trim( (string) $tag->get( $attribute ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Whether a JavaScript lazy loader controls the image (real URL in data-* attributes).
	 *
	 * @param Tag $tag Image tag.
	 */
	private static function uses_js_lazy_loader( Tag $tag ): bool {
		return $tag->has( 'data-src' ) || $tag->has( 'data-lazy-src' ) || $tag->has( 'data-srcset' ) || $tag->has( 'data-lazy-srcset' );
	}
}
