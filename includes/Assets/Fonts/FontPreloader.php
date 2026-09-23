<?php
/**
 * Preloads measured above-the-fold fonts (pure).
 *
 * Candidates come from browser measurements shared by all URLs of a template,
 * so each one is verified against the current page: WOFF2 only; either a
 * local file that exists (checked by an injected callback) or a
 * fonts.gstatic.com file whose family is requested by a Google Fonts
 * stylesheet on this page. At most two preloads, never duplicates, never
 * icon fonts.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets\Fonts;

use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Images\ImageUrls;
use SH\SpeedOptimizer\Assets\Tag;

defined( 'ABSPATH' ) || exit;

/**
 * Font preloader.
 */
final class FontPreloader {

	/**
	 * Maximum preloads per page.
	 */
	public const MAX = 2;

	/**
	 * Candidates: [ url, type, family, weight, style ].
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $candidates;

	/**
	 * Site host.
	 *
	 * @var string
	 */
	private string $home_host;

	/**
	 * Local existence check: function( string $url ): bool.
	 *
	 * @var callable
	 */
	private $local_exists;

	/**
	 * Constructor.
	 *
	 * @param array<int,mixed> $candidates   Page data fonts_preload.
	 * @param string           $home_host    Site host.
	 * @param callable         $local_exists function( string $url ): bool — local file exists.
	 */
	public function __construct( array $candidates, string $home_host, callable $local_exists ) {
		$this->candidates   = array_values( array_filter( $candidates, 'is_array' ) );
		$this->home_host    = strtolower( $home_host );
		$this->local_exists = $local_exists;
	}

	/**
	 * URLs to preload for a document.
	 *
	 * @param HtmlDocument $doc Document.
	 * @return string[]
	 */
	public function plan( HtmlDocument $doc ): array {
		$existing = $this->existing_preloads( $doc );
		$families = null;
		$plan     = array();

		foreach ( $this->candidates as $candidate ) {
			if ( count( $plan ) >= self::MAX ) {
				break;
			}
			$url    = trim( (string) ( $candidate['url'] ?? '' ) );
			$type   = strtolower( trim( (string) ( $candidate['type'] ?? '' ) ) );
			$family = trim( (string) ( $candidate['family'] ?? '' ), " \t\n\r\0\x0B\"'" );

			if ( ! preg_match( '#^https?://#i', $url ) || false !== strpbrk( $url, "\"'<> \t\r\n" ) ) {
				continue;
			}
			if ( ( '' !== $type && 'font/woff2' !== $type ) || 'woff2' !== ImageUrls::extension( $url ) ) {
				continue;
			}
			if ( FontFaceTransformer::is_icon_font( $family, $url ) ) {
				continue;
			}

			$canonical = ImageUrls::canonical( $url, $this->home_host );
			if ( '' === $canonical || isset( $existing[ $canonical ] ) || isset( $plan[ $canonical ] ) ) {
				continue;
			}

			$host = ImageUrls::host( $url );
			if ( $host === $this->home_host ) {
				if ( ! ( $this->local_exists )( $url ) ) {
					continue;
				}
			} elseif ( GoogleFonts::FONT_HOST === $host ) {
				$families = $families ?? self::google_families( $doc );
				if ( empty( $families ) || ( '' !== $family && ! isset( $families[ strtolower( $family ) ] ) ) ) {
					continue;
				}
			} else {
				continue;
			}

			$plan[ $canonical ] = $url;
		}

		return array_values( $plan );
	}

	/**
	 * Insert the preloads.
	 *
	 * @param HtmlDocument $doc    Document.
	 * @param callable     $insert function( HtmlDocument $doc, string $markup ): bool — insertion helper.
	 * @return int Preloads added.
	 */
	public function transform( HtmlDocument $doc, callable $insert ): int {
		$plan = $this->plan( $doc );
		if ( empty( $plan ) ) {
			return 0;
		}
		$markup = '';
		foreach ( $plan as $url ) {
			$markup .= Tag::create(
				'link',
				array(
					'rel'         => 'preload',
					'as'          => 'font',
					'type'        => 'font/woff2',
					'href'        => $url,
					'crossorigin' => null,
				)
			)->to_html();
		}
		return $insert( $doc, $markup ) ? count( $plan ) : 0;
	}

	/**
	 * Canonical URLs of existing font preloads.
	 *
	 * @param HtmlDocument $doc Document.
	 * @return array<string,bool>
	 */
	private function existing_preloads( HtmlDocument $doc ): array {
		$existing = array();
		if ( ! $doc->contains( 'preload' ) ) {
			return $existing;
		}
		$doc->replace_tags(
			'link',
			function ( Tag $tag ) use ( &$existing ) {
				$rel = (array) preg_split( '/\s+/', strtolower( trim( (string) $tag->get( 'rel' ) ) ) );
				if ( 'link' === $tag->name && in_array( 'preload', $rel, true ) ) {
					$canonical = ImageUrls::canonical( (string) $tag->get( 'href' ), $this->home_host );
					if ( '' !== $canonical ) {
						$existing[ $canonical ] = true;
					}
				}
				return null;
			}
		);
		return $existing;
	}

	/**
	 * Lower-case family names requested by Google Fonts stylesheets on the page (links and @import).
	 *
	 * @param HtmlDocument $doc Document.
	 * @return array<string,bool>
	 */
	public static function google_families( HtmlDocument $doc ): array {
		$families = array();
		if ( ! preg_match_all( '#(?:https?:)?//fonts\.googleapis\.com/css2?\?[^"\'\s)<>]+#i', $doc->html(), $m ) ) {
			return $families;
		}
		foreach ( array_unique( $m[0] ) as $raw ) {
			$url = html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			foreach ( array_keys( GoogleFonts::parse( $url )['families'] ) as $family ) {
				$families[ strtolower( $family ) ] = true;
			}
		}
		return $families;
	}
}
