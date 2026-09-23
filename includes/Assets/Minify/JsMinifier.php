<?php
/**
 * Conservative JavaScript minifier.
 *
 * Correctness over size. The input is tokenized properly (strings, template
 * literals with nested `${ … }`, regular expression literals vs. division,
 * comments, hashbang) and only the following changes are made:
 *
 *  - comments are removed, except license comments (`/*!`, `@license`, `@preserve`)
 *    and IE conditional compilation (`@cc_on`),
 *  - runs of spaces/tabs collapse to one space, and spaces are removed where no
 *    two tokens can merge (`a = b` → `a=b`, but `a + +b`, `a - -b`, `a / /re/` keep theirs),
 *  - newlines are kept (so automatic semicolon insertion never changes); blank lines,
 *    indentation and trailing spaces are removed.
 *
 * String, template and regex contents are copied byte for byte. If tokenizing
 * fails, finds an unterminated construct or an HTML-like comment (`<!--`, `-->`),
 * the input is returned unchanged.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets\Minify;

defined( 'ABSPATH' ) || exit;

/**
 * JavaScript minifier (pure, no WordPress dependency).
 */
final class JsMinifier {

	private const T_WORD    = 'w';
	private const T_PUNCT   = 'p';
	private const T_STRING  = 's';
	private const T_TEMPLATE = 't';
	private const T_REGEX   = 'r';
	private const T_SPACE   = ' ';
	private const T_NEWLINE = 'n';
	private const T_COMMENT = 'c';

	/**
	 * Keywords after which a slash starts a regular expression.
	 */
	private const REGEX_KEYWORDS = array( 'return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void', 'throw', 'case', 'do', 'else', 'yield', 'await' );

	/**
	 * Keywords whose parenthesised condition may be followed by a regex statement.
	 */
	private const PAREN_KEYWORDS = array( 'if', 'while', 'for', 'with' );

	/**
	 * Characters that end a word run.
	 */
	private const WORD_STOP = " \t\x0B\x0C\n\r\"'`/{}()[];,.:?!~+-*%&|^<>=@";

	/**
	 * Minify a script. Returns the input unchanged when it cannot be minified safely.
	 *
	 * @param string $js Script.
	 */
	public static function minify( string $js ): string {
		if ( '' === trim( $js ) || self::is_minified( $js ) ) {
			return $js;
		}

		try {
			$tokens = self::tokenize( $js );
		} catch ( \Throwable $e ) {
			return $js;
		}
		if ( null === $tokens ) {
			return $js;
		}

		$out = self::emit( $tokens );
		return '' === trim( $out ) ? $js : $out;
	}

	/**
	 * Whether a script looks already minified (average line length above 300 characters).
	 *
	 * @param string $js Script.
	 */
	public static function is_minified( string $js ): bool {
		$length = strlen( $js );
		if ( $length < 300 ) {
			return false;
		}
		return $length / ( substr_count( $js, "\n" ) + 1 ) > 300;
	}

	/**
	 * Tokenize a script.
	 *
	 * @param string $js Script.
	 * @return array<int,array{0:string,1:string}>|null Tokens or null on failure.
	 */
	private static function tokenize( string $js ): ?array {
		$tokens   = array();
		$len      = strlen( $js );
		$i        = 0;
		$regex_ok = true;
		$parens   = array(); // Stack: whether each "(" belongs to if/while/for/with.
		$last     = null;    // Last significant token.
		$adjacent = false;   // Whether the last significant token touches the current position.

		// Hashbang.
		if ( 0 === strncmp( $js, '#!', 2 ) ) {
			$end      = strcspn( $js, "\r\n" );
			$tokens[] = array( self::T_COMMENT, substr( $js, 0, $end ) );
			$i        = $end;
		}

		while ( $i < $len ) {
			$c = $js[ $i ];

			// Newlines.
			if ( "\n" === $c || "\r" === $c ) {
				$run      = strspn( $js, "\r\n \t\x0B\x0C", $i );
				$tokens[] = array( self::T_NEWLINE, "\n" );
				$i       += $run;
				$adjacent = false;
				continue;
			}

			// Spaces.
			if ( ' ' === $c || "\t" === $c || "\x0B" === $c || "\x0C" === $c ) {
				$i       += strspn( $js, " \t\x0B\x0C", $i );
				$tokens[] = array( self::T_SPACE, ' ' );
				$adjacent = false;
				continue;
			}

			$next = $i + 1 < $len ? $js[ $i + 1 ] : '';

			// Comments.
			if ( '/' === $c && '/' === $next ) {
				$end  = $i + strcspn( $js, "\r\n", $i );
				$text = substr( $js, $i, $end - $i );
				if ( self::is_kept_comment( $text ) ) {
					$tokens[] = array( self::T_COMMENT, $text );
				} else {
					$tokens[] = array( self::T_SPACE, ' ' );
				}
				$i        = $end;
				$adjacent = false;
				continue;
			}
			if ( '/' === $c && '*' === $next ) {
				$end = strpos( $js, '*/', $i + 2 );
				if ( false === $end ) {
					return null;
				}
				$text = substr( $js, $i, $end + 2 - $i );
				if ( self::is_kept_comment( $text ) ) {
					$tokens[] = array( self::T_COMMENT, $text );
				} elseif ( false !== strpbrk( $text, "\r\n" ) ) {
					$tokens[] = array( self::T_NEWLINE, "\n" ); // A multi-line comment is a line terminator for ASI.
				} else {
					$tokens[] = array( self::T_SPACE, ' ' );
				}
				$i        = $end + 2;
				$adjacent = false;
				continue;
			}

			// HTML-like comments change meaning between scripts and modules: do not touch such code.
			if ( ( '<' === $c && '!--' === substr( $js, $i + 1, 3 ) ) || ( '-' === $c && '->' === substr( $js, $i + 1, 2 ) ) ) {
				return null;
			}

			// Strings.
			if ( '"' === $c || "'" === $c ) {
				$end = self::scan_string( $js, $i );
				if ( null === $end ) {
					return null;
				}
				$tokens[] = array( self::T_STRING, substr( $js, $i, $end - $i ) );
				$i        = $end;
				$regex_ok = false;
				$last     = self::T_STRING;
				$adjacent = true;
				continue;
			}

			// Template literals.
			if ( '`' === $c ) {
				$end = self::scan_template( $js, $i );
				if ( null === $end ) {
					return null;
				}
				$tokens[] = array( self::T_TEMPLATE, substr( $js, $i, $end - $i ) );
				$i        = $end;
				$regex_ok = false;
				$last     = self::T_TEMPLATE;
				$adjacent = true;
				continue;
			}

			// Regular expression literals.
			if ( '/' === $c && $regex_ok ) {
				$end = self::scan_regex( $js, $i );
				if ( null === $end ) {
					return null;
				}
				$tokens[] = array( self::T_REGEX, substr( $js, $i, $end - $i ) );
				$i        = $end;
				$regex_ok = false;
				$last     = self::T_REGEX;
				$adjacent = true;
				continue;
			}

			// Punctuators (single characters; merging is prevented at output time).
			if ( false !== strpos( "{}()[];,.:?!~+-*/%&|^<>=@", $c ) ) {
				$tokens[] = array( self::T_PUNCT, $c );

				if ( '(' === $c ) {
					$count    = count( $tokens );
					$prev     = self::previous_significant( $tokens, $count - 2 );
					$parens[] = null !== $prev && self::T_WORD === $prev[0] && in_array( $prev[1], self::PAREN_KEYWORDS, true );
					$regex_ok = true;
				} elseif ( ')' === $c ) {
					$regex_ok = (bool) array_pop( $parens );
				} elseif ( ']' === $c ) {
					$regex_ok = false;
				} elseif ( '}' === $c ) {
					$regex_ok = true;
				} elseif ( ( '+' === $c || '-' === $c ) && $adjacent && self::T_PUNCT === $last && self::last_punct( $tokens ) === $c . $c ) {
					$regex_ok = false; // Postfix/prefix ++ or --: an operand or operator follows, never a regex.
				} else {
					$regex_ok = true;
				}
				$last     = self::T_PUNCT;
				$adjacent = true;
				++$i;
				continue;
			}

			// Words: identifiers, keywords, numbers, private names, escapes, non-ASCII.
			$end = $i + strcspn( $js, self::WORD_STOP, $i );
			if ( $end === $i ) {
				$end = $i + 1;
			}
			// A backslash escape (A) may contain characters of WORD_STOP only in "\u{…}" form; keep it simple.
			$word     = substr( $js, $i, $end - $i );
			$tokens[] = array( self::T_WORD, $word );
			$regex_ok = in_array( $word, self::REGEX_KEYWORDS, true );
			$last     = self::T_WORD;
			$adjacent = true;
			$i        = $end;
		}

		return $tokens;
	}

	/**
	 * Last two punctuator characters directly before the end of the token list (e.g. "++").
	 *
	 * @param array<int,array{0:string,1:string}> $tokens Tokens.
	 */
	private static function last_punct( array $tokens ): string {
		$count = count( $tokens );
		if ( $count < 2 || self::T_PUNCT !== $tokens[ $count - 2 ][0] ) {
			return '';
		}
		return $tokens[ $count - 2 ][1] . $tokens[ $count - 1 ][1];
	}

	/**
	 * Previous significant token at or before an index.
	 *
	 * @param array<int,array{0:string,1:string}> $tokens Tokens.
	 * @param int                                 $index  Start index.
	 * @return array{0:string,1:string}|null
	 */
	private static function previous_significant( array $tokens, int $index ): ?array {
		for ( ; $index >= 0; $index-- ) {
			$type = $tokens[ $index ][0];
			if ( self::T_SPACE !== $type && self::T_NEWLINE !== $type && self::T_COMMENT !== $type ) {
				return $tokens[ $index ];
			}
		}
		return null;
	}

	/**
	 * Scan a string literal.
	 *
	 * @param string $js    Script.
	 * @param int    $start Offset of the opening quote.
	 * @return int|null Offset after the closing quote.
	 */
	private static function scan_string( string $js, int $start ): ?int {
		$quote = $js[ $start ];
		$len   = strlen( $js );
		$i     = $start + 1;
		$stop  = $quote . "\\\r\n";

		while ( $i < $len ) {
			$i += strcspn( $js, $stop, $i );
			if ( $i >= $len ) {
				return null;
			}
			$c = $js[ $i ];
			if ( '\\' === $c ) {
				$i += ( $i + 2 < $len && "\r" === $js[ $i + 1 ] && "\n" === $js[ $i + 2 ] ) ? 3 : 2;
				continue;
			}
			if ( $c === $quote ) {
				return $i + 1;
			}
			return null; // Raw line break inside a string.
		}
		return null;
	}

	/**
	 * Scan a template literal including nested substitutions.
	 *
	 * @param string $js    Script.
	 * @param int    $start Offset of the opening backtick.
	 * @return int|null Offset after the closing backtick.
	 */
	private static function scan_template( string $js, int $start ): ?int {
		$len = strlen( $js );
		$i   = $start + 1;

		while ( $i < $len ) {
			$i += strcspn( $js, '`\\$', $i );
			if ( $i >= $len ) {
				return null;
			}
			$c = $js[ $i ];
			if ( '\\' === $c ) {
				$i += 2;
				continue;
			}
			if ( '`' === $c ) {
				return $i + 1;
			}
			if ( '$' === $c && $i + 1 < $len && '{' === $js[ $i + 1 ] ) {
				$end = self::scan_expression( $js, $i + 2 );
				if ( null === $end ) {
					return null;
				}
				$i = $end;
				continue;
			}
			++$i;
		}
		return null;
	}

	/**
	 * Scan a template substitution up to (and including) its closing brace.
	 *
	 * @param string $js    Script.
	 * @param int    $start Offset after "${".
	 * @return int|null Offset after the closing "}".
	 */
	private static function scan_expression( string $js, int $start ): ?int {
		$len      = strlen( $js );
		$i        = $start;
		$depth    = 0;
		$regex_ok = true;

		while ( $i < $len ) {
			$c    = $js[ $i ];
			$next = $i + 1 < $len ? $js[ $i + 1 ] : '';

			if ( ' ' === $c || "\t" === $c || "\n" === $c || "\r" === $c ) {
				++$i;
				continue;
			}
			if ( '/' === $c && '/' === $next ) {
				$i += strcspn( $js, "\r\n", $i );
				continue;
			}
			if ( '/' === $c && '*' === $next ) {
				$end = strpos( $js, '*/', $i + 2 );
				if ( false === $end ) {
					return null;
				}
				$i = $end + 2;
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$end = self::scan_string( $js, $i );
				if ( null === $end ) {
					return null;
				}
				$i        = $end;
				$regex_ok = false;
				continue;
			}
			if ( '`' === $c ) {
				$end = self::scan_template( $js, $i );
				if ( null === $end ) {
					return null;
				}
				$i        = $end;
				$regex_ok = false;
				continue;
			}
			if ( '/' === $c && $regex_ok ) {
				$end = self::scan_regex( $js, $i );
				if ( null === $end ) {
					return null;
				}
				$i        = $end;
				$regex_ok = false;
				continue;
			}
			if ( '{' === $c ) {
				++$depth;
				$regex_ok = true;
				++$i;
				continue;
			}
			if ( '}' === $c ) {
				if ( 0 === $depth ) {
					return $i + 1;
				}
				--$depth;
				$regex_ok = true;
				++$i;
				continue;
			}
			if ( false !== strpos( "()[];,.:?!~+-*/%&|^<>=@", $c ) ) {
				$regex_ok = ! in_array( $c, array( ')', ']' ), true );
				++$i;
				continue;
			}
			$end      = $i + max( 1, strcspn( $js, self::WORD_STOP, $i ) );
			$regex_ok = in_array( substr( $js, $i, $end - $i ), self::REGEX_KEYWORDS, true );
			$i        = $end;
		}
		return null;
	}

	/**
	 * Scan a regular expression literal including flags.
	 *
	 * @param string $js    Script.
	 * @param int    $start Offset of the opening slash.
	 * @return int|null Offset after the flags.
	 */
	private static function scan_regex( string $js, int $start ): ?int {
		$len      = strlen( $js );
		$i        = $start + 1;
		$in_class = false;

		if ( $i < $len && ( '/' === $js[ $i ] || '*' === $js[ $i ] ) ) {
			return null; // Would be a comment.
		}

		while ( $i < $len ) {
			$c = $js[ $i ];
			if ( "\n" === $c || "\r" === $c ) {
				return null;
			}
			if ( '\\' === $c ) {
				if ( $i + 1 >= $len || "\n" === $js[ $i + 1 ] || "\r" === $js[ $i + 1 ] ) {
					return null;
				}
				$i += 2;
				continue;
			}
			if ( $in_class ) {
				if ( ']' === $c ) {
					$in_class = false;
				}
			} elseif ( '[' === $c ) {
				$in_class = true;
			} elseif ( '/' === $c ) {
				++$i;
				while ( $i < $len && ( ctype_alnum( $js[ $i ] ) || '_' === $js[ $i ] || '$' === $js[ $i ] ) ) {
					++$i;
				}
				return $i;
			}
			++$i;
		}
		return null;
	}

	/**
	 * Whether a comment must be kept.
	 *
	 * @param string $comment Comment including delimiters.
	 */
	private static function is_kept_comment( string $comment ): bool {
		return 0 === strncmp( $comment, '/*!', 3 )
			|| false !== stripos( $comment, '@license' )
			|| false !== stripos( $comment, '@preserve' )
			|| false !== strpos( $comment, '@cc_on' );
	}

	/**
	 * Build the output.
	 *
	 * @param array<int,array{0:string,1:string}> $tokens Tokens.
	 */
	private static function emit( array $tokens ): string {
		$out     = '';
		$space   = false;
		$newline = false;
		$prev    = null;

		foreach ( $tokens as $token ) {
			list( $type, $text ) = $token;

			if ( self::T_SPACE === $type ) {
				$space = true;
				continue;
			}
			if ( self::T_NEWLINE === $type ) {
				$newline = true;
				continue;
			}

			if ( null !== $prev ) {
				if ( $newline ) {
					$out .= "\n";
				} elseif ( self::T_COMMENT === $type || self::T_COMMENT === $prev[0] ) {
					$out .= $space ? ' ' : '';
				} elseif ( $space && self::needs_space( $prev, $token ) ) {
					$out .= ' ';
				}
			}

			$out    .= $text;
			$prev    = $token;
			$space   = false;
			$newline = false;
		}

		if ( $newline ) {
			$out .= "\n";
		}

		return $out;
	}

	/**
	 * Whether the space between two tokens must be kept.
	 *
	 * @param array{0:string,1:string} $prev Previous token.
	 * @param array{0:string,1:string} $next Next token.
	 */
	private static function needs_space( array $prev, array $next ): bool {
		$a = substr( $prev[1], -1 );
		$b = $next[1][0];

		if ( self::is_word_char( $a ) && self::is_word_char( $b ) ) {
			return true;
		}
		if ( self::T_REGEX === $prev[0] && self::is_word_char( $b ) ) {
			return true; // Would become flags.
		}
		if ( self::T_WORD === $prev[0] && self::T_REGEX === $next[0] ) {
			return true;
		}
		if ( ( '+' === $a || '-' === $a ) && $a === $b ) {
			return true; // a + +b, a - -b, a++ +b.
		}
		if ( ( '/' === $a && ( '/' === $b || '*' === $b ) ) || ( '*' === $a && '/' === $b ) ) {
			return true; // Would start or end a comment.
		}
		if ( ( '<' === $a && '!' === $b ) || ( '-' === $a && '>' === $b ) ) {
			return true; // Would form an HTML-like comment.
		}
		if ( '?' === $a && '.' === $b ) {
			return true; // Keep "? .5" away from optional chaining.
		}
		if ( self::T_WORD === $prev[0] && ctype_digit( $prev[1][0] ) && '.' === $b ) {
			return true; // "1 .toString()".
		}
		return false;
	}

	/**
	 * Whether a byte can be part of an identifier or number.
	 *
	 * @param string $c Byte.
	 */
	private static function is_word_char( string $c ): bool {
		return '' !== $c && ( ctype_alnum( $c ) || '_' === $c || '$' === $c || '\\' === $c || '#' === $c || ord( $c ) >= 0x80 );
	}
}
