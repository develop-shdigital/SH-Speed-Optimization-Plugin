<?php
/**
 * Shared helpers for resource hints and preloads.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\Preload;

use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Tag;

defined( 'ABSPATH' ) || exit;

/**
 * Hint helpers (pure).
 */
final class Hints {

	/**
	 * Insert markup early in <head>: right after <meta charset> when present (the charset
	 * declaration must stay within the first bytes), otherwise right after <head>.
	 *
	 * @param HtmlDocument $doc    Document.
	 * @param string       $markup Markup.
	 */
	public static function insert_early( HtmlDocument $doc, string $markup ): bool {
		$done = false;
		$doc->with_mask(
			array(),
			static function ( string $masked ) use ( $markup, &$done ) {
				$head_end = stripos( $masked, '</head>' );
				if ( false === $head_end ) {
					return $masked;
				}
				$head = substr( $masked, 0, $head_end );
				if ( preg_match( '#<meta\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*\bcharset\s*=(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i', $head, $m, PREG_OFFSET_CAPTURE )
					|| preg_match( '#<head\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i', $head, $m, PREG_OFFSET_CAPTURE ) ) {
					$pos  = (int) $m[0][1] + strlen( $m[0][0] );
					$done = true;
					return substr( $masked, 0, $pos ) . $markup . substr( $masked, $pos );
				}
				return $masked;
			}
		);
		return $done ? true : $doc->insert_in_head( $markup );
	}

	/**
	 * Origin ("https://host[:port]") of an absolute or protocol-relative URL; null for relative URLs.
	 *
	 * @param string $url URL.
	 */
	public static function origin( string $url ): ?string {
		$url = trim( $url );
		if ( ! preg_match( '#^(https?:)?//([a-z0-9.\-]+)(:\d+)?(?:[/?\#]|$)#i', $url, $m ) ) {
			return null;
		}
		$scheme = '' !== $m[1] ? strtolower( rtrim( $m[1], ':' ) ) : 'https';
		return $scheme . '://' . strtolower( $m[2] ) . ( $m[3] ?? '' );
	}

	/**
	 * Host of an origin or URL, lower case ('' when relative).
	 *
	 * @param string $url URL.
	 */
	public static function host( string $url ): string {
		$origin = self::origin( $url );
		return null === $origin ? '' : (string) preg_replace( '#^https?://|:\d+$#', '', $origin );
	}

	/**
	 * Visit every <link> tag (read-only) and return the parsed tags with their info.
	 *
	 * @param HtmlDocument $doc Document.
	 * @return array<int,array{0:Tag,1:array<string,mixed>}>
	 */
	public static function links( HtmlDocument $doc ): array {
		$links = array();
		$doc->replace_tags(
			'link',
			static function ( Tag $tag, array $info ) use ( &$links ) {
				if ( 'link' === $tag->name ) {
					$links[] = array( $tag, $info );
				}
				return null;
			}
		);
		return $links;
	}

	/**
	 * Whether a link's rel list contains a token.
	 *
	 * @param Tag    $tag   Link tag.
	 * @param string $token Token.
	 */
	public static function rel_has( Tag $tag, string $token ): bool {
		$rels = preg_split( '/\s+/', strtolower( trim( (string) $tag->get( 'rel' ) ) ) );
		return in_array( strtolower( $token ), (array) $rels, true );
	}
}
