<?php
/**
 * Rewrites image URLs in HTML to existing WebP (optionally AVIF) derivatives.
 *
 * Pure: the file existence check is injected, so it can be unit tested and
 * results are memoized per request. A URL is only rewritten when its
 * derivative file exists; originals are never touched.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\ImageOptimization;

use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Images\DerivativeMap;
use SH\SpeedOptimizer\Assets\Images\ImageUrls;
use SH\SpeedOptimizer\Assets\Images\MarkupRanges;
use SH\SpeedOptimizer\Assets\Tag;

defined( 'ABSPATH' ) || exit;

/**
 * WebP URL rewriter.
 */
final class WebpRewriter {

	/**
	 * Single-URL attributes of <img> (lazy loaders keep the real URL in data-*).
	 */
	private const URL_ATTRIBUTES = array( 'src', 'data-src', 'data-lazy-src', 'data-orig-src' );

	/**
	 * Srcset-like attributes.
	 */
	private const SRCSET_ATTRIBUTES = array( 'srcset', 'data-srcset', 'data-lazy-srcset' );

	/**
	 * Path mapper.
	 *
	 * @var DerivativeMap
	 */
	private DerivativeMap $map;

	/**
	 * File existence checker: function( string $path ): bool.
	 *
	 * @var callable
	 */
	private $exists;

	/**
	 * Exclusion patterns (class, id or URL fragments).
	 *
	 * @var string[]
	 */
	private array $exclude;

	/**
	 * Host of the site (root-relative URLs).
	 *
	 * @var string
	 */
	private string $home_host;

	/**
	 * Whether AVIF is delivered through <picture>.
	 *
	 * @var bool
	 */
	private bool $avif;

	/**
	 * Per-request memo: "format|url" => derivative URL or null.
	 *
	 * @var array<string,string|null>
	 */
	private array $memo = array();

	/**
	 * Constructor.
	 *
	 * @param DerivativeMap $map       Path mapper.
	 * @param callable      $exists    function( string $path ): bool.
	 * @param string[]      $exclude   Exclusion patterns.
	 * @param string        $home_host Site host.
	 * @param bool          $avif      Deliver AVIF through <picture> when available.
	 */
	public function __construct( DerivativeMap $map, callable $exists, array $exclude = array(), string $home_host = '', bool $avif = false ) {
		$this->map       = $map;
		$this->exists    = $exists;
		$this->exclude   = array_values( array_filter( array_map( 'strval', $exclude ) ) );
		$this->home_host = $home_host;
		$this->avif      = $avif;
	}

	/**
	 * Derivative URL for an image URL when the derivative exists.
	 *
	 * @param string $url    Original URL.
	 * @param string $format webp|avif.
	 */
	public function url( string $url, string $format = 'webp' ): ?string {
		$key = $format . '|' . $url;
		if ( array_key_exists( $key, $this->memo ) ) {
			return $this->memo[ $key ];
		}

		$result = null;
		$info   = $this->map->from_url( $url, $this->home_host );
		if ( null !== $info ) {
			$path = $this->map->derivative_path( $info['rel'], $format );
			if ( null !== $path && ( $this->exists )( $path ) ) {
				$result = $this->map->derivative_url( $url, $format, $this->home_host );
			}
		}

		$this->memo[ $key ] = $result;
		return $result;
	}

	/**
	 * Rewrite srcset candidates.
	 *
	 * @param string $srcset Decoded srcset.
	 * @param string $format webp|avif.
	 * @return array{0:string,1:int,2:int} New srcset, rewritten candidates, total candidates.
	 */
	public function srcset( string $srcset, string $format = 'webp' ): array {
		$candidates = ImageUrls::parse_srcset( $srcset );
		$rewritten  = 0;
		foreach ( $candidates as $i => $candidate ) {
			$new = $this->url( $candidate['url'], $format );
			if ( null !== $new ) {
				$candidates[ $i ]['url'] = $new;
				++$rewritten;
			}
		}
		return array( 0 === $rewritten ? $srcset : ImageUrls::build_srcset( $candidates ), $rewritten, count( $candidates ) );
	}

	/**
	 * Rewrite a document.
	 *
	 * @param HtmlDocument $doc Document.
	 * @return int Number of changed tags.
	 */
	public function transform( HtmlDocument $doc ): int {
		$pictures = $this->avif ? MarkupRanges::elements( $doc, 'picture' ) : array();

		$changed = $doc->replace_tags(
			array( 'img', 'source' ),
			function ( Tag $tag, array $info ) use ( $pictures ) {
				if ( 'img' === $tag->name ) {
					return $this->rewrite_img( $tag, MarkupRanges::inside( (int) $info['offset'], $pictures ) );
				}
				if ( 'source' === $tag->name ) {
					return $this->rewrite_source( $tag );
				}
				return null;
			}
		);

		return $changed + $this->rewrite_backgrounds( $doc );
	}

	/**
	 * Rewrite an <img>.
	 *
	 * @param Tag  $tag        Tag.
	 * @param bool $in_picture Inside a <picture> element.
	 * @return Tag|string|null
	 */
	private function rewrite_img( Tag $tag, bool $in_picture ) {
		if ( $this->excluded( $tag ) ) {
			return null;
		}

		$original_src    = (string) $tag->get( 'src' );
		$original_srcset = (string) $tag->get( 'srcset' );
		$dirty           = false;

		foreach ( self::URL_ATTRIBUTES as $attribute ) {
			$value = $tag->get( $attribute );
			if ( null === $value || '' === $value ) {
				continue;
			}
			$new = $this->url( $value );
			if ( null !== $new ) {
				$tag->set( $attribute, $new );
				$dirty = true;
			}
		}

		foreach ( self::SRCSET_ATTRIBUTES as $attribute ) {
			$value = $tag->get( $attribute );
			if ( null === $value || '' === $value ) {
				continue;
			}
			list( $new, $count ) = $this->srcset( $value );
			if ( $count > 0 ) {
				$tag->set( $attribute, $new );
				$dirty = true;
			}
		}

		if ( ! $dirty ) {
			return null;
		}

		if ( $this->avif && ! $in_picture ) {
			$avif = $this->avif_source( $original_src, $original_srcset, $tag->get( 'sizes' ) );
			if ( null !== $avif ) {
				return '<picture class="shso-picture">' . $avif . $tag->to_html() . '</picture>';
			}
		}

		return $tag;
	}

	/**
	 * <source type="image/avif"> for an image when every candidate has an AVIF derivative.
	 *
	 * @param string      $src    Original src.
	 * @param string      $srcset Original srcset.
	 * @param string|null $sizes  sizes attribute.
	 */
	private function avif_source( string $src, string $srcset, ?string $sizes ): ?string {
		if ( '' !== $srcset ) {
			list( $new, $count, $total ) = $this->srcset( $srcset, 'avif' );
			if ( 0 === $total || $count !== $total ) {
				return null;
			}
		} else {
			$new = null === $this->url( $src, 'avif' ) ? '' : (string) $this->url( $src, 'avif' );
			if ( '' === $new ) {
				return null;
			}
		}

		$source = Tag::create(
			'source',
			array(
				'type'   => 'image/avif',
				'srcset' => $new,
			)
		);
		if ( null !== $sizes && '' !== $sizes ) {
			$source->set( 'sizes', $sizes );
		}
		return $source->to_html();
	}

	/**
	 * Rewrite a <source> (only picture sources: srcset, never video/audio src).
	 *
	 * @param Tag $tag Tag.
	 */
	private function rewrite_source( Tag $tag ): ?Tag {
		if ( $this->excluded( $tag ) ) {
			return null;
		}

		$type        = strtolower( trim( (string) $tag->get( 'type' ) ) );
		$strict_type = in_array( $type, array( 'image/jpeg', 'image/jpg', 'image/png' ), true );
		if ( '' !== $type && ! $strict_type ) {
			return null; // Already modern (image/webp, image/avif) or not an image source.
		}

		$dirty = false;
		foreach ( array( 'srcset', 'data-srcset' ) as $attribute ) {
			$value = $tag->get( $attribute );
			if ( null === $value || '' === $value ) {
				continue;
			}
			list( $new, $count, $total ) = $this->srcset( $value );
			// A typed JPEG/PNG source must switch completely or not at all.
			if ( $count > 0 && ( ! $strict_type || $count === $total ) ) {
				$tag->set( $attribute, $new );
				$dirty = true;
			}
		}

		if ( $dirty && $strict_type ) {
			$tag->set( 'type', 'image/webp' );
		}

		return $dirty ? $tag : null;
	}

	/**
	 * Rewrite url() references in inline style attributes.
	 *
	 * @param HtmlDocument $doc Document.
	 */
	private function rewrite_backgrounds( HtmlDocument $doc ): int {
		if ( ! $doc->contains( 'url(' ) ) {
			return 0;
		}

		$changed = 0;
		$doc->with_mask(
			array(),
			function ( string $masked ) use ( &$changed ) {
				$result = preg_replace_callback(
					'#<[a-zA-Z][a-zA-Z0-9\-]*\s(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#',
					function ( $m ) use ( &$changed ) {
						if ( false === stripos( $m[0], 'url(' ) || false === stripos( $m[0], 'style' ) ) {
							return $m[0];
						}
						$tag = Tag::parse( $m[0] );
						if ( null === $tag || ! $tag->has( 'style' ) || $this->excluded( $tag ) ) {
							return $m[0];
						}
						$style = (string) $tag->get( 'style' );
						$new   = $this->rewrite_css_urls( $style );
						if ( $new === $style ) {
							return $m[0];
						}
						$tag->set( 'style', $new );
						++$changed;
						return $tag->to_html();
					},
					$masked
				);
				return null === $result ? $masked : $result;
			}
		);

		return $changed;
	}

	/**
	 * Rewrite url(...) references inside a CSS declaration list.
	 *
	 * @param string $css CSS.
	 */
	public function rewrite_css_urls( string $css ): string {
		return (string) preg_replace_callback(
			'/url\(\s*(["\']?)([^"\'()\s]+)\1\s*\)/i',
			function ( $m ) {
				$new = $this->url( $m[2] );
				return null === $new ? $m[0] : 'url(' . $m[1] . $new . $m[1] . ')';
			},
			$css
		);
	}

	/**
	 * Whether a tag is excluded by class, id, URL or attribute name.
	 *
	 * @param Tag $tag Tag.
	 */
	private function excluded( Tag $tag ): bool {
		if ( empty( $this->exclude ) ) {
			return false;
		}
		$haystacks = array(
			(string) $tag->get( 'class' ),
			(string) $tag->get( 'id' ),
			(string) $tag->get( 'src' ),
			(string) $tag->get( 'srcset' ),
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
}
