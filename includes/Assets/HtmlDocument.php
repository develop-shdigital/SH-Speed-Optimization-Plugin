<?php
/**
 * Regex-safe HTML document wrapper for output transformations.
 *
 * Transformers must never touch markup inside comments, <script>, <style>,
 * <noscript>, <textarea> or <template> by accident. This class masks those
 * regions before searching for tags and restores them afterwards.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * HTML document.
 */
final class HtmlDocument {

	public const REGION_COMMENT  = 'comment';
	public const REGION_SCRIPT   = 'script';
	public const REGION_STYLE    = 'style';
	public const REGION_NOSCRIPT = 'noscript';
	public const REGION_TEXTAREA = 'textarea';
	public const REGION_TEMPLATE = 'template';

	private const REGION_PATTERN = '#<!--.*?-->|<(script|style|noscript|textarea|template)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>.*?</\1\s*>#is';

	private const MARK = "\x1A";

	/**
	 * Current markup.
	 *
	 * @var string
	 */
	private string $html;

	/**
	 * Constructor.
	 *
	 * @param string $html Markup.
	 */
	public function __construct( string $html ) {
		$this->html = $html;
	}

	/**
	 * Current markup.
	 */
	public function html(): string {
		return $this->html;
	}

	/**
	 * Replace the markup.
	 *
	 * @param string $html Markup.
	 */
	public function set_html( string $html ): void {
		$this->html = $html;
	}

	/**
	 * Case-insensitive substring check on the raw markup.
	 *
	 * @param string $needle Needle.
	 */
	public function contains( string $needle ): bool {
		return false !== stripos( $this->html, $needle );
	}

	/**
	 * Whether the document looks like a complete HTML page.
	 */
	public function is_html_page(): bool {
		$start = substr( $this->html, 0, 2048 );
		return ( false !== stripos( $start, '<html' ) || false !== stripos( $start, '<!doctype html' ) ) && false !== stripos( $this->html, '</head>' );
	}

	/**
	 * Run a callback on a masked copy of the document.
	 *
	 * @param string[] $keep     Region types NOT to mask (e.g. [ REGION_SCRIPT ] when processing scripts).
	 * @param callable $callback function( string $masked ): string — returns the modified masked markup.
	 */
	public function with_mask( array $keep, callable $callback ): void {
		$store  = array();
		$masked = preg_replace_callback(
			self::REGION_PATTERN,
			static function ( $m ) use ( &$store, $keep ) {
				$type = isset( $m[1] ) && '' !== $m[1] ? strtolower( $m[1] ) : self::REGION_COMMENT;
				if ( in_array( $type, $keep, true ) ) {
					return $m[0];
				}
				$store[] = $m[0];
				return self::MARK . ( count( $store ) - 1 ) . self::MARK;
			},
			$this->html
		);

		if ( null === $masked ) {
			return; // PCRE failure (backtrack limit): leave the document untouched.
		}

		$result = (string) $callback( $masked );

		if ( ! empty( $store ) ) {
			$result = (string) preg_replace_callback(
				'#' . self::MARK . '(\d+)' . self::MARK . '#',
				static function ( $m ) use ( $store ) {
					return $store[ (int) $m[1] ] ?? '';
				},
				$result
			);
		}

		$this->html = $result;
	}

	/**
	 * Replace start tags (void or not) such as img, link, source, input.
	 *
	 * Callback: function( Tag $tag, array $info ): Tag|string|null
	 *   - return null to leave the tag unchanged,
	 *   - return the (modified) Tag,
	 *   - return a string to replace the tag markup entirely.
	 * $info: index (0-based occurrence), in_head (bool), offset (position in the masked document).
	 *
	 * @param string|string[] $names    Tag name(s).
	 * @param callable        $callback Callback.
	 * @return int Number of changed tags.
	 */
	public function replace_tags( $names, callable $callback ): int {
		$names   = array_map( 'preg_quote', (array) $names );
		$changed = 0;

		$this->with_mask(
			array(),
			function ( string $masked ) use ( $names, $callback, &$changed ) {
				$head_end = self::find_head_end( $masked );
				$index    = 0;
				$pattern  = '#<(' . implode( '|', $names ) . ')\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i';

				$result = preg_replace_callback(
					$pattern,
					static function ( $m ) use ( $callback, &$changed, &$index, $head_end ) {
						$offset = (int) $m[0][1];
						$tag    = Tag::parse( $m[0][0] );
						if ( null === $tag ) {
							return $m[0][0];
						}
						$result = $callback(
							$tag,
							array(
								'index'   => $index++,
								'in_head' => $offset < $head_end,
								'offset'  => $offset,
							)
						);
						if ( null === $result ) {
							return $m[0][0];
						}
						$html = $result instanceof Tag ? $result->to_html() : (string) $result;
						if ( $html !== $m[0][0] ) {
							++$changed;
						}
						return $html;
					},
					$masked,
					-1,
					$count,
					PREG_OFFSET_CAPTURE
				);

				return null === $result ? $masked : $result;
			}
		);

		return $changed;
	}

	/**
	 * Replace whole elements with content, e.g. iframe, video, picture, audio.
	 *
	 * Callback: function( Tag $open, string $inner, array $info ): string|null (null = unchanged).
	 *
	 * @param string   $name     Element name.
	 * @param callable $callback Callback.
	 * @return int Number of changed elements.
	 */
	public function replace_elements( string $name, callable $callback ): int {
		$name    = preg_quote( strtolower( $name ), '#' );
		$changed = 0;

		$this->with_mask(
			array(),
			function ( string $masked ) use ( $name, $callback, &$changed ) {
				$head_end = self::find_head_end( $masked );
				$index    = 0;
				$pattern  = '#(<' . $name . '\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>)(.*?)</' . $name . '\s*>#is';

				$result = preg_replace_callback(
					$pattern,
					static function ( $m ) use ( $callback, &$changed, &$index, $head_end ) {
						$offset = (int) $m[0][1];
						$tag    = Tag::parse( $m[1][0] );
						if ( null === $tag ) {
							return $m[0][0];
						}
						$result = $callback(
							$tag,
							$m[2][0],
							array(
								'index'   => $index++,
								'in_head' => $offset < $head_end,
								'offset'  => $offset,
							)
						);
						if ( null === $result || $result === $m[0][0] ) {
							return $m[0][0];
						}
						++$changed;
						return (string) $result;
					},
					$masked,
					-1,
					$count,
					PREG_OFFSET_CAPTURE
				);

				return null === $result ? $masked : $result;
			}
		);

		return $changed;
	}

	/**
	 * Visit every <script> element outside comments/noscript/template/textarea.
	 *
	 * Callback: function( Tag $open, string $code, array $info ): string|null
	 * Return null to keep the element, or replacement markup (may be '' to remove,
	 * but never remove scripts silently — only move/rewrite them).
	 *
	 * @param callable $callback Callback.
	 * @return int Number of changed scripts.
	 */
	public function replace_scripts( callable $callback ): int {
		return $this->replace_kept_elements( 'script', self::REGION_SCRIPT, $callback );
	}

	/**
	 * Visit every <style> element outside comments/noscript/template/textarea.
	 *
	 * Callback: function( Tag $open, string $css, array $info ): string|null
	 *
	 * @param callable $callback Callback.
	 * @return int Number of changed style elements.
	 */
	public function replace_styles( callable $callback ): int {
		return $this->replace_kept_elements( 'style', self::REGION_STYLE, $callback );
	}

	/**
	 * Insert markup into <head>.
	 *
	 * @param string $markup   Markup.
	 * @param bool   $at_start Insert right after <head> (true) or before </head> (false).
	 */
	public function insert_in_head( string $markup, bool $at_start = false ): bool {
		$done = false;
		$this->with_mask(
			array(),
			static function ( string $masked ) use ( $markup, $at_start, &$done ) {
				if ( $at_start ) {
					if ( preg_match( '#<head\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i', $masked, $m, PREG_OFFSET_CAPTURE ) ) {
						$pos  = (int) $m[0][1] + strlen( $m[0][0] );
						$done = true;
						return substr( $masked, 0, $pos ) . $markup . substr( $masked, $pos );
					}
					return $masked;
				}
				$pos = self::find_head_end( $masked );
				if ( $pos < strlen( $masked ) ) {
					$done = true;
					return substr( $masked, 0, $pos ) . $markup . substr( $masked, $pos );
				}
				return $masked;
			}
		);
		return $done;
	}

	/**
	 * Insert markup right before </body>.
	 *
	 * @param string $markup Markup.
	 */
	public function insert_before_body_end( string $markup ): bool {
		$done = false;
		$this->with_mask(
			array(),
			static function ( string $masked ) use ( $markup, &$done ) {
				$pos = strripos( $masked, '</body>' );
				if ( false === $pos ) {
					return $masked;
				}
				$done = true;
				return substr( $masked, 0, $pos ) . $markup . substr( $masked, $pos );
			}
		);
		return $done;
	}

	/**
	 * Offset of </head> in a (masked) document, or its length when missing.
	 *
	 * @param string $masked Masked markup.
	 */
	private static function find_head_end( string $masked ): int {
		$pos = stripos( $masked, '</head>' );
		return false === $pos ? strlen( $masked ) : $pos;
	}

	/**
	 * Shared implementation for script/style visits.
	 *
	 * @param string   $name     Element name.
	 * @param string   $region   Region type left unmasked.
	 * @param callable $callback Callback.
	 */
	private function replace_kept_elements( string $name, string $region, callable $callback ): int {
		$changed = 0;

		$this->with_mask(
			array( $region ),
			function ( string $masked ) use ( $name, $callback, &$changed ) {
				$head_end = self::find_head_end( $masked );
				$index    = 0;
				$pattern  = '#(<' . $name . '\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>)(.*?)</' . $name . '\s*>#is';

				$result = preg_replace_callback(
					$pattern,
					static function ( $m ) use ( $callback, &$changed, &$index, $head_end ) {
						$offset = (int) $m[0][1];
						$tag    = Tag::parse( $m[1][0] );
						if ( null === $tag ) {
							return $m[0][0];
						}
						$result = $callback(
							$tag,
							$m[2][0],
							array(
								'index'   => $index++,
								'in_head' => $offset < $head_end,
								'offset'  => $offset,
							)
						);
						if ( null === $result || $result === $m[0][0] ) {
							return $m[0][0];
						}
						++$changed;
						return (string) $result;
					},
					$masked,
					-1,
					$count,
					PREG_OFFSET_CAPTURE
				);

				return null === $result ? $masked : $result;
			}
		);

		return $changed;
	}
}
