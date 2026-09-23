<?php
/**
 * Replaces Google Fonts stylesheets with their localized copies (pure).
 *
 * Only entries whose download succeeded and whose local stylesheet still
 * exists are used; everything else keeps the original link. After a
 * replacement, preconnect/dns-prefetch hints to Google Fonts hosts are removed
 * when nothing else on the page references those hosts.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets\Fonts;

use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Tag;

defined( 'ABSPATH' ) || exit;

/**
 * Local fonts rewriter.
 */
final class LocalFontsRewriter {

	/**
	 * Mapping (entry id => entry).
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $mapping;

	/**
	 * Public URL of the fonts directory (trailing slash).
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Existence check for a local stylesheet: function( string $relative ): bool.
	 *
	 * @var callable
	 */
	private $exists;

	/**
	 * Stylesheet URLs without a mapping entry (to be remembered).
	 *
	 * @var string[]
	 */
	public array $unknown = array();

	/**
	 * Entry ids whose local files disappeared.
	 *
	 * @var string[]
	 */
	public array $missing = array();

	/**
	 * Constructor.
	 *
	 * @param array<string,array<string,mixed>> $mapping  Mapping.
	 * @param string                            $base_url Fonts directory URL.
	 * @param callable                          $exists   function( string $relative ): bool.
	 */
	public function __construct( array $mapping, string $base_url, callable $exists ) {
		$this->mapping  = $mapping;
		$this->base_url = rtrim( $base_url, '/' ) . '/';
		$this->exists   = $exists;
	}

	/**
	 * Local stylesheet URL for a Google Fonts URL, or null.
	 *
	 * @param string $url Google Fonts URL.
	 */
	public function local_url( string $url ): ?string {
		$id    = FontLocalizer::id( $url );
		$entry = $this->mapping[ $id ] ?? null;
		if ( null === $entry ) {
			if ( ! in_array( $url, $this->unknown, true ) ) {
				$this->unknown[] = $url;
			}
			return null;
		}
		$css = (string) ( $entry['css'] ?? '' );
		if ( FontLocalizer::STATUS_OK !== ( $entry['status'] ?? '' ) || ! preg_match( '#^[a-f0-9]{12}/fonts\.css$#', $css ) ) {
			return null;
		}
		if ( ! ( $this->exists )( $css ) ) {
			$this->missing[] = $id;
			return null;
		}
		return $this->base_url . $css . '?ver=' . (int) ( $entry['updated'] ?? 0 );
	}

	/**
	 * Transform a document.
	 *
	 * @param HtmlDocument $doc Document.
	 * @return int Replaced references.
	 */
	public function transform( HtmlDocument $doc ): int {
		if ( ! $doc->contains( 'fonts.googleapis.com' ) ) {
			return 0;
		}

		$replaced = $doc->replace_tags(
			'link',
			function ( Tag $tag ) {
				$href = $tag->get( 'href' );
				if ( 'link' !== $tag->name || null === $href || ! GoogleFonts::is_css_url( $href ) ) {
					return null;
				}
				$is_style = self::rel_has( $tag, 'stylesheet' ) || ( self::rel_has( $tag, 'preload' ) && 'style' === strtolower( (string) $tag->get( 'as' ) ) );
				if ( ! $is_style ) {
					return null;
				}
				$local = $this->local_url( $href );
				if ( null === $local ) {
					return null;
				}
				$tag->set( 'href', $local );
				$tag->remove( 'crossorigin' );
				return $tag;
			}
		);

		$replaced += $doc->replace_styles(
			function ( Tag $tag, string $css ) {
				if ( false === stripos( $css, 'fonts.googleapis.com' ) ) {
					return null;
				}
				$new = (string) preg_replace_callback(
					'#(@import\s+(?:url\(\s*)?)(["\']?)((?:https?:)?//fonts\.googleapis\.com/[^"\'\s);]+)(\2)#i',
					function ( $m ) {
						$local = $this->local_url( $m[3] );
						return null === $local ? $m[0] : $m[1] . $m[2] . $local . $m[4];
					},
					$css
				);
				return $new === $css ? null : $tag->to_html() . $new . '</style>';
			}
		);

		if ( $replaced > 0 ) {
			$this->remove_unused_hints( $doc );
		}

		return $replaced;
	}

	/**
	 * Remove preconnect/dns-prefetch hints to Google Fonts hosts that nothing references anymore.
	 *
	 * @param HtmlDocument $doc Document.
	 */
	private function remove_unused_hints( HtmlDocument $doc ): void {
		$pattern = '#<link\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*\brel\s*=\s*["\']?[^"\'>]*(?:preconnect|dns-prefetch)(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i';
		$rest    = (string) preg_replace( $pattern, '', $doc->html() );

		$unused = array();
		foreach ( array( GoogleFonts::CSS_HOST, GoogleFonts::FONT_HOST ) as $host ) {
			if ( false === stripos( $rest, $host ) ) {
				$unused[] = $host;
			}
		}
		if ( empty( $unused ) ) {
			return;
		}

		$doc->replace_tags(
			'link',
			static function ( Tag $tag ) use ( $unused ) {
				if ( 'link' !== $tag->name || ! ( self::rel_has( $tag, 'preconnect' ) || self::rel_has( $tag, 'dns-prefetch' ) ) ) {
					return null;
				}
				$href = strtolower( (string) $tag->get( 'href' ) );
				foreach ( $unused as $host ) {
					if ( preg_match( '#^(?:https?:)?//' . preg_quote( $host, '#' ) . '(?:[:/]|$)#', $href ) ) {
						return '';
					}
				}
				return null;
			}
		);
	}

	/**
	 * Whether a link's rel list contains a token.
	 *
	 * @param Tag    $tag   Link tag.
	 * @param string $token Token.
	 */
	private static function rel_has( Tag $tag, string $token ): bool {
		return in_array( $token, (array) preg_split( '/\s+/', strtolower( trim( (string) $tag->get( 'rel' ) ) ) ), true );
	}
}
