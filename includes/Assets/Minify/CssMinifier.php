<?php
/**
 * Conservative CSS minifier and url() rewriter.
 *
 * The minifier tokenizes the stylesheet (strings, comments, url() tokens and
 * escapes stay byte-exact) and then removes only whitespace that provably has
 * no meaning:
 *
 *  - comments are dropped, except license comments (`/*! … *\/`, `@license`,
 *    `@preserve`),
 *  - whitespace runs collapse to one space,
 *  - whitespace around `{`, `}`, `;` is removed and the last `;` of a block is dropped,
 *  - in selectors: whitespace around `,`, `>`, `+`, `~` (outside attribute brackets),
 *  - in declarations: whitespace around the property colon, around `,`, after `(`,
 *    before `)` and around `!important`,
 *  - in at-rule preludes: whitespace around `,`, after `(`, before `)` and after `:` inside parentheses.
 *
 * It never touches whitespace before `(` (`and (` / `not (` must stay), before a
 * selector colon (`a :hover` ≠ `a:hover`), around `+`/`-` in values (calc()) or
 * inside custom property values beyond collapsing. Invalid input (unterminated
 * strings/comments, unbalanced brackets) is returned unchanged.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets\Minify;

defined( 'ABSPATH' ) || exit;

/**
 * CSS minifier (pure, no WordPress dependency).
 */
final class CssMinifier {

	public const T_WS      = 'w';
	public const T_STRING  = 's';
	public const T_COMMENT = 'c';
	public const T_URL     = 'u';
	public const T_CHAR    = 'p';
	public const T_TEXT    = 't';

	/**
	 * Single characters with structural meaning.
	 */
	private const CHARS = '{}()[];:,>+~!';

	/**
	 * Characters that end a text run.
	 */
	private const RUN_STOP = " \t\n\r\f\"'\\{}()[];:,>+~!/";

	/**
	 * Whitespace characters in CSS.
	 */
	private const WS = " \t\n\r\f";

	/**
	 * Minify a stylesheet. Already minified input stays (practically) unchanged; the
	 * operation is idempotent.
	 *
	 * @param string $css Stylesheet.
	 */
	public static function minify( string $css ): string {
		if ( '' === trim( $css ) ) {
			return $css;
		}

		$bom = '';
		if ( 0 === strncmp( $css, "\xEF\xBB\xBF", 3 ) ) {
			$bom = "\xEF\xBB\xBF";
			$css = substr( $css, 3 );
		}

		$tokens = self::tokenize( $css );
		if ( null === $tokens || ! self::is_balanced( $tokens ) ) {
			return $bom . $css;
		}

		$tokens = self::drop_comments( $tokens );
		$result = self::minify_tokens( $tokens );

		return '' === $result ? $bom . $css : $bom . $result;
	}

	/**
	 * Whether a stylesheet looks already minified (average line length above 300 characters).
	 *
	 * @param string $css Stylesheet.
	 */
	public static function is_minified( string $css ): bool {
		$length = strlen( $css );
		if ( $length < 300 ) {
			return false;
		}
		return $length / ( substr_count( $css, "\n" ) + 1 ) > 300;
	}

	/**
	 * Tokenize a stylesheet.
	 *
	 * @param string $css Stylesheet.
	 * @return array<int,array{0:string,1:string}>|null Tokens, or null for unterminated strings, comments or url().
	 */
	public static function tokenize( string $css ): ?array {
		$tokens = array();
		$len    = strlen( $css );
		$i      = 0;

		while ( $i < $len ) {
			$c = $css[ $i ];

			// Whitespace.
			if ( false !== strpos( self::WS, $c ) ) {
				$run      = strspn( $css, self::WS, $i );
				$tokens[] = array( self::T_WS, substr( $css, $i, $run ) );
				$i       += $run;
				continue;
			}

			// Comment.
			if ( '/' === $c && $i + 1 < $len && '*' === $css[ $i + 1 ] ) {
				$end = strpos( $css, '*/', $i + 2 );
				if ( false === $end ) {
					return null;
				}
				$tokens[] = array( self::T_COMMENT, substr( $css, $i, $end + 2 - $i ) );
				$i        = $end + 2;
				continue;
			}

			// String.
			if ( '"' === $c || "'" === $c ) {
				$j = $i + 1;
				while ( $j < $len ) {
					$ch = $css[ $j ];
					if ( '\\' === $ch ) {
						$j += ( $j + 2 < $len && "\r" === $css[ $j + 1 ] && "\n" === $css[ $j + 2 ] ) ? 3 : 2;
						continue;
					}
					if ( $ch === $c ) {
						break;
					}
					if ( "\n" === $ch || "\r" === $ch || "\f" === $ch ) {
						return null; // Bad string.
					}
					++$j;
				}
				if ( $j >= $len ) {
					return null;
				}
				$tokens[] = array( self::T_STRING, substr( $css, $i, $j + 1 - $i ) );
				$i        = $j + 1;
				continue;
			}

			// Escape sequence (part of an identifier).
			if ( '\\' === $c ) {
				$tokens[] = array( self::T_TEXT, self::read_escape( $css, $i ) );
				$i       += strlen( $tokens[ count( $tokens ) - 1 ][1] );
				continue;
			}

			// Unquoted url( … ) token.
			if ( ( 'u' === $c || 'U' === $c ) && 0 === strncasecmp( substr( $css, $i, 4 ), 'url(', 4 ) && ( 0 === $i || ! self::is_ident_char( $css[ $i - 1 ] ) ) ) {
				$j = $i + 4;
				$j = $j + strspn( $css, self::WS, $j );
				if ( $j < $len && '"' !== $css[ $j ] && "'" !== $css[ $j ] ) {
					$k = $j;
					while ( $k < $len ) {
						$ch = $css[ $k ];
						if ( '\\' === $ch ) {
							$k += 2;
							continue;
						}
						if ( ')' === $ch ) {
							break;
						}
						if ( '"' === $ch || "'" === $ch || '(' === $ch ) {
							return null; // Bad url.
						}
						++$k;
					}
					if ( $k >= $len ) {
						return null;
					}
					$tokens[] = array( self::T_URL, substr( $css, $i, $k + 1 - $i ) );
					$i        = $k + 1;
					continue;
				}
			}

			// Structural character.
			if ( false !== strpos( self::CHARS, $c ) ) {
				$tokens[] = array( self::T_CHAR, $c );
				++$i;
				continue;
			}

			// Text run (identifiers, numbers, hashes, operators such as * / = …).
			$j = $i;
			while ( $j < $len ) {
				$j += strcspn( $css, self::RUN_STOP, $j );
				if ( $j < $len && '/' === $css[ $j ] && ( $j + 1 >= $len || '*' !== $css[ $j + 1 ] ) ) {
					++$j; // A slash that does not start a comment belongs to the run.
					continue;
				}
				break;
			}
			if ( $j === $i ) {
				// Lone "/" followed by "*" is handled above; anything else: consume one byte.
				$j = $i + 1;
			}
			$tokens[] = array( self::T_TEXT, substr( $css, $i, $j - $i ) );
			$i        = $j;
		}

		return $tokens;
	}

	/**
	 * Rewrite relative url() and @import references so the stylesheet keeps working from
	 * another directory. data:, #fragment, absolute, protocol-relative and root-relative
	 * references are left alone.
	 *
	 * @param string $css           Stylesheet.
	 * @param string $base_url      Absolute URL of the original stylesheet.
	 * @param bool   $root_relative Emit root-relative paths ("/wp-content/…") instead of absolute URLs.
	 * @return string|null Rewritten stylesheet, or null when it cannot be parsed safely.
	 */
	public static function rewrite_urls( string $css, string $base_url, bool $root_relative = false ): ?string {
		if ( false === stripos( $css, 'url(' ) && false === stripos( $css, '@import' ) && false === stripos( $css, 'image-set(' ) ) {
			return $css;
		}

		$tokens = self::tokenize( $css );
		if ( null === $tokens ) {
			return null;
		}

		$out       = '';
		$functions = array();
		$previous  = null; // Last non-whitespace, non-comment token.
		$count     = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			list( $type, $text ) = $tokens[ $i ];

			if ( self::T_URL === $type ) {
				$out .= self::rewrite_url_token( $text, $base_url, $root_relative );
			} elseif ( self::T_STRING === $type ) {
				$function = end( $functions );
				$is_ref   = in_array( $function, array( 'url', 'image-set', '-webkit-image-set' ), true )
					|| ( null !== $previous && self::T_TEXT === $previous[0] && 0 === strcasecmp( $previous[1], '@import' ) );
				$out     .= $is_ref ? self::rewrite_string_token( $text, $base_url, $root_relative ) : $text;
			} elseif ( self::T_CHAR === $type && '(' === $text ) {
				$functions[] = ( null !== $previous && self::T_TEXT === $previous[0] && self::T_TEXT === ( $tokens[ $i - 1 ][0] ?? '' ) ) ? strtolower( $previous[1] ) : '';
				$out        .= $text;
			} elseif ( self::T_CHAR === $type && ')' === $text ) {
				array_pop( $functions );
				$out .= $text;
			} else {
				$out .= $text;
			}

			if ( self::T_WS !== $type && self::T_COMMENT !== $type ) {
				$previous = $tokens[ $i ];
			}
		}

		return $out;
	}

	/**
	 * Resolve a reference found in a stylesheet against the stylesheet URL.
	 *
	 * @param string $reference     Reference as written (without quotes).
	 * @param string $base_url      Absolute URL of the stylesheet.
	 * @param bool   $root_relative Return a root-relative path.
	 */
	public static function resolve_url( string $reference, string $base_url, bool $root_relative = false ): string {
		$ref = trim( $reference );
		if ( '' === $ref || '#' === $ref[0] || '/' === $ref[0] || false !== strpos( $ref, '\\' ) || preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $ref ) ) {
			return $reference;
		}

		$base = parse_url( $base_url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper, no WordPress dependency.
		if ( ! is_array( $base ) || empty( $base['host'] ) ) {
			return $reference;
		}

		$suffix = '';
		$cut    = strcspn( $ref, '?#' );
		if ( $cut < strlen( $ref ) ) {
			$suffix = substr( $ref, $cut );
			$ref    = substr( $ref, 0, $cut );
		}

		$base_path = $base['path'] ?? '/';
		$dir       = substr( $base_path, 0, (int) strrpos( $base_path, '/' ) + 1 );
		$path      = self::remove_dot_segments( ( '' === $dir ? '/' : $dir ) . $ref );

		if ( $root_relative ) {
			return $path . $suffix;
		}

		$origin = ( $base['scheme'] ?? 'https' ) . '://' . $base['host'] . ( isset( $base['port'] ) ? ':' . $base['port'] : '' );
		return $origin . $path . $suffix;
	}

	/**
	 * RFC 3986 dot segment removal.
	 *
	 * @param string $path Path starting with "/".
	 */
	private static function remove_dot_segments( string $path ): string {
		$segments = explode( '/', $path );
		$output   = array();
		$last     = count( $segments ) - 1;

		foreach ( $segments as $index => $segment ) {
			if ( '..' === $segment ) {
				if ( count( $output ) > 1 ) {
					array_pop( $output );
				}
				if ( $index === $last ) {
					$output[] = '';
				}
				continue;
			}
			if ( '.' === $segment ) {
				if ( $index === $last ) {
					$output[] = '';
				}
				continue;
			}
			$output[] = $segment;
		}

		$result = implode( '/', $output );
		return '' === $result || '/' !== $result[0] ? '/' . $result : $result;
	}

	/**
	 * Rewrite an unquoted url( … ) token.
	 *
	 * @param string $token         Token text.
	 * @param string $base_url      Stylesheet URL.
	 * @param bool   $root_relative Root-relative output.
	 */
	private static function rewrite_url_token( string $token, string $base_url, bool $root_relative ): string {
		$inner = trim( substr( $token, 4, -1 ), self::WS );
		if ( '' === $inner || false !== strpos( $inner, '\\' ) ) {
			return $token;
		}
		$resolved = self::resolve_url( $inner, $base_url, $root_relative );
		if ( $resolved === $inner ) {
			return $token;
		}
		if ( preg_match( '/[\s"\'()]/', $resolved ) ) {
			return 'url("' . str_replace( '"', '%22', $resolved ) . '")';
		}
		return substr( $token, 0, 4 ) . $resolved . ')';
	}

	/**
	 * Rewrite a quoted reference.
	 *
	 * @param string $token         String token including quotes.
	 * @param string $base_url      Stylesheet URL.
	 * @param bool   $root_relative Root-relative output.
	 */
	private static function rewrite_string_token( string $token, string $base_url, bool $root_relative ): string {
		$quote = $token[0];
		$inner = substr( $token, 1, -1 );
		if ( '' === $inner || false !== strpos( $inner, '\\' ) ) {
			return $token;
		}
		$resolved = self::resolve_url( $inner, $base_url, $root_relative );
		if ( $resolved === $inner || false !== strpos( $resolved, $quote ) ) {
			return $token;
		}
		return $quote . $resolved . $quote;
	}

	/**
	 * Read an escape sequence starting at a backslash.
	 *
	 * @param string $css Stylesheet.
	 * @param int    $i   Offset of the backslash.
	 */
	private static function read_escape( string $css, int $i ): string {
		$len = strlen( $css );
		if ( $i + 1 >= $len ) {
			return '\\';
		}
		$next = $css[ $i + 1 ];
		if ( ctype_xdigit( $next ) ) {
			$j = $i + 1;
			$k = 0;
			while ( $j < $len && $k < 6 && ctype_xdigit( $css[ $j ] ) ) {
				++$j;
				++$k;
			}
			// A single whitespace after a hex escape belongs to the escape.
			if ( $j < $len && false !== strpos( self::WS, $css[ $j ] ) ) {
				$j += ( "\r" === $css[ $j ] && $j + 1 < $len && "\n" === $css[ $j + 1 ] ) ? 2 : 1;
			}
			return substr( $css, $i, $j - $i );
		}
		if ( "\n" === $next || "\r" === $next || "\f" === $next ) {
			return '\\'; // Invalid escape: the backslash stands alone.
		}
		// Escaped character, including a complete multi-byte UTF-8 sequence.
		$j = $i + 2;
		if ( ord( $next ) >= 0xC0 ) {
			while ( $j < $len && ( ord( $css[ $j ] ) & 0xC0 ) === 0x80 ) {
				++$j;
			}
		}
		return substr( $css, $i, $j - $i );
	}

	/**
	 * Whether a byte can be part of an identifier.
	 *
	 * @param string $c Byte.
	 */
	private static function is_ident_char( string $c ): bool {
		return ctype_alnum( $c ) || '-' === $c || '_' === $c || '\\' === $c || ord( $c ) >= 0x80;
	}

	/**
	 * Whether brackets are balanced.
	 *
	 * @param array<int,array{0:string,1:string}> $tokens Tokens.
	 */
	private static function is_balanced( array $tokens ): bool {
		$stack = array();
		$pairs = array(
			'}' => '{',
			')' => '(',
			']' => '[',
		);
		foreach ( $tokens as $token ) {
			if ( self::T_CHAR !== $token[0] ) {
				continue;
			}
			$c = $token[1];
			if ( '{' === $c || '(' === $c || '[' === $c ) {
				$stack[] = $c;
			} elseif ( isset( $pairs[ $c ] ) ) {
				if ( array_pop( $stack ) !== $pairs[ $c ] ) {
					return false;
				}
			}
		}
		return empty( $stack );
	}

	/**
	 * Drop comments that are not license comments.
	 *
	 * A dropped comment directly between two non-structural characters (e.g. `1px/**\/2px`)
	 * is replaced by an empty comment so the tokens do not merge.
	 *
	 * @param array<int,array{0:string,1:string}> $tokens Tokens.
	 * @return array<int,array{0:string,1:string}>
	 */
	private static function drop_comments( array $tokens ): array {
		$out   = array();
		$count = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( self::T_URL === $token[0] ) {
				// Whitespace padding inside url( … ) is not part of the URL.
				$out[] = array( self::T_URL, substr( $token[1], 0, 4 ) . trim( substr( $token[1], 4, -1 ), self::WS ) . ')' );
				continue;
			}
			if ( self::T_COMMENT !== $token[0] ) {
				$out[] = $token;
				continue;
			}
			if ( self::is_license_comment( $token[1] ) ) {
				$out[] = $token;
				continue;
			}

			$previous = empty( $out ) ? null : $out[ count( $out ) - 1 ];
			// Skip following dropped comments to find the next real token.
			$j = $i + 1;
			while ( $j < $count && self::T_COMMENT === $tokens[ $j ][0] && ! self::is_license_comment( $tokens[ $j ][1] ) ) {
				++$j;
			}
			$next = $tokens[ $j ] ?? null;

			if ( null !== $previous && null !== $next && self::would_merge( $previous, $next ) ) {
				$out[] = array( self::T_COMMENT, '/**/' );
			}
			$i = $j - 1;
		}

		return $out;
	}

	/**
	 * Whether two tokens would merge into a different token when nothing separates them
	 * (e.g. `1px` + `2px`, `1` + `.5`, `/` + `*`).
	 *
	 * @param array{0:string,1:string} $previous Previous token.
	 * @param array{0:string,1:string} $next     Next token.
	 */
	private static function would_merge( array $previous, array $next ): bool {
		if ( self::T_TEXT !== $previous[0] || self::T_TEXT !== $next[0] ) {
			return false;
		}
		$a = substr( $previous[1], -1 );
		$b = $next[1][0];
		if ( '/' === $a && '*' === $b ) {
			return true;
		}
		if ( self::is_ident_char( $a ) && self::is_ident_char( $b ) ) {
			return true;
		}
		return ctype_digit( $a ) && ( '.' === $b || '%' === $b );
	}

	/**
	 * Whether a comment must be kept.
	 *
	 * @param string $comment Comment including delimiters.
	 */
	private static function is_license_comment( string $comment ): bool {
		return 0 === strncmp( $comment, '/*!', 3 ) || false !== stripos( $comment, '@license' ) || false !== stripos( $comment, '@preserve' );
	}

	/**
	 * Structural pass: split into preludes and declarations and minify each.
	 *
	 * @param array<int,array{0:string,1:string}> $tokens Tokens.
	 */
	private static function minify_tokens( array $tokens ): string {
		$out       = '';
		$semicolon = false; // A statement-terminating ";" waiting to be written (dropped before "}").
		$count     = count( $tokens );
		$depth     = 0;
		$i         = 0;

		$emit = static function ( string $text ) use ( &$out, &$semicolon ): void {
			if ( $semicolon ) {
				$out      .= ';';
				$semicolon = false;
			}
			$out .= $text;
		};

		while ( $i < $count ) {
			list( $type, $text ) = $tokens[ $i ];

			if ( self::T_WS === $type ) {
				++$i;
				continue;
			}
			if ( self::T_COMMENT === $type ) {
				$emit( $text );
				++$i;
				continue;
			}
			if ( self::T_CHAR === $type && '}' === $text ) {
				$semicolon = false;
				$out      .= '}';
				$depth     = max( 0, $depth - 1 );
				++$i;
				continue;
			}
			if ( self::T_CHAR === $type && ';' === $text ) {
				// Empty statement: harmless inside blocks, but meaningful error recovery at top level.
				if ( 0 === $depth ) {
					$emit( ';' );
				} elseif ( ! $semicolon && '{' !== substr( $out, -1 ) && '' !== $out ) {
					$semicolon = true;
				}
				++$i;
				continue;
			}

			// Find the end of this statement.
			$j      = $i;
			$parens = 0;
			for ( ; $j < $count; $j++ ) {
				if ( self::T_CHAR !== $tokens[ $j ][0] ) {
					continue;
				}
				$c = $tokens[ $j ][1];
				if ( '(' === $c || '[' === $c ) {
					++$parens;
				} elseif ( ')' === $c || ']' === $c ) {
					--$parens;
				} elseif ( $parens <= 0 && ( '{' === $c || ';' === $c || '}' === $c ) ) {
					break;
				}
			}

			$segment    = array_slice( $tokens, $i, $j - $i );
			$terminator = $j < $count ? $tokens[ $j ][1] : '';
			$is_at      = self::T_TEXT === $type && '@' === $text[0];
			$minified   = $is_at ? self::minify_at_prelude( $segment ) : ( '{' === $terminator ? self::minify_selector( $segment ) : self::minify_declaration( $segment ) );

			if ( '{' === $terminator ) {
				$emit( $minified . '{' );
				++$depth;
				$i = $j + 1;
			} elseif ( ';' === $terminator ) {
				$emit( $minified );
				if ( 0 === $depth ) {
					$out .= ';';
				} else {
					$semicolon = true;
				}
				$i = $j + 1;
			} else {
				$emit( $minified );
				$i = $j;
			}
		}

		if ( $semicolon ) {
			$out .= ';';
		}

		return $out;
	}

	/**
	 * Trim whitespace tokens at both ends.
	 *
	 * @param array<int,array{0:string,1:string}> $tokens Tokens.
	 * @return array<int,array{0:string,1:string}>
	 */
	private static function trim_tokens( array $tokens ): array {
		while ( ! empty( $tokens ) && self::T_WS === $tokens[0][0] ) {
			array_shift( $tokens );
		}
		$last = count( $tokens ) - 1;
		while ( $last >= 0 && self::T_WS === $tokens[ $last ][0] ) {
			array_pop( $tokens );
			--$last;
		}
		return $tokens;
	}

	/**
	 * Join tokens, deciding for every whitespace run whether it may be removed.
	 *
	 * @param array<int,array{0:string,1:string}> $tokens    Tokens.
	 * @param callable                            $removable function( string $prev_last_char, string $next_first_char, int $parens, int $brackets ): bool.
	 */
	private static function join( array $tokens, callable $removable ): string {
		$out      = '';
		$pending  = false;
		$parens   = 0;
		$brackets = 0;

		foreach ( self::trim_tokens( $tokens ) as $token ) {
			list( $type, $text ) = $token;
			if ( self::T_WS === $type ) {
				$pending = true;
				continue;
			}
			if ( $pending && '' !== $out ) {
				if ( ! $removable( substr( $out, -1 ), $text[0], $parens, $brackets ) ) {
					$out .= ' ';
				}
			}
			$pending = false;
			$out    .= $text;

			if ( self::T_CHAR === $type ) {
				if ( '(' === $text ) {
					++$parens;
				} elseif ( ')' === $text ) {
					--$parens;
				} elseif ( '[' === $text ) {
					++$brackets;
				} elseif ( ']' === $text ) {
					--$brackets;
				}
			}
		}

		return $out;
	}

	/**
	 * Minify a selector prelude.
	 *
	 * @param array<int,array{0:string,1:string}> $tokens Tokens.
	 */
	private static function minify_selector( array $tokens ): string {
		return self::join(
			$tokens,
			static function ( string $prev, string $next, int $parens, int $brackets ): bool {
				if ( $brackets > 0 ) {
					return false;
				}
				return false !== strpos( ',>+~(', $prev ) || false !== strpos( ',>+~)', $next );
			}
		);
	}

	/**
	 * Minify an at-rule prelude (@media, @supports, @import …).
	 *
	 * @param array<int,array{0:string,1:string}> $tokens Tokens.
	 */
	private static function minify_at_prelude( array $tokens ): string {
		return self::join(
			$tokens,
			static function ( string $prev, string $next, int $parens ): bool {
				if ( ',' === $prev || '(' === $prev || ',' === $next || ')' === $next ) {
					return true;
				}
				return $parens > 0 && ':' === $prev;
			}
		);
	}

	/**
	 * Minify a declaration ("property: value").
	 *
	 * @param array<int,array{0:string,1:string}> $tokens Tokens.
	 */
	private static function minify_declaration( array $tokens ): string {
		while ( ! empty( $tokens ) && self::T_WS === $tokens[0][0] ) {
			array_shift( $tokens );
		}
		$colon  = null;
		$parens = 0;

		foreach ( $tokens as $index => $token ) {
			if ( self::T_CHAR !== $token[0] ) {
				continue;
			}
			if ( '(' === $token[1] || '[' === $token[1] ) {
				++$parens;
			} elseif ( ')' === $token[1] || ']' === $token[1] ) {
				--$parens;
			} elseif ( ':' === $token[1] && 0 === $parens ) {
				$colon = $index;
				break;
			}
		}

		if ( null === $colon ) {
			// Not a declaration (garbage or an unusual construct): only collapse whitespace.
			return self::join(
				$tokens,
				static function (): bool {
					return false;
				}
			);
		}

		$property = self::join(
			array_slice( $tokens, 0, $colon ),
			static function (): bool {
				return false;
			}
		);
		$raw      = array_slice( $tokens, $colon + 1 );
		$value    = self::trim_tokens( $raw );

		if ( 0 === strncmp( $property, '--', 2 ) ) {
			// Custom property: whitespace is part of the value; only collapse it. A value that
			// consists of whitespace only stays a single space ("--x: ;" is valid, "--x:;" is not everywhere).
			$joined = self::join(
				$value,
				static function (): bool {
					return false;
				}
			);
			return $property . ':' . ( '' === $joined && ! empty( $raw ) ? ' ' : $joined );
		}

		return $property . ':' . self::join(
			$value,
			static function ( string $prev, string $next ): bool {
				return false !== strpos( ',(!', $prev ) || false !== strpos( ',)!', $next );
			}
		);
	}
}
