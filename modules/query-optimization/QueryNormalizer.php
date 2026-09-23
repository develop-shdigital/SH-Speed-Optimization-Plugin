<?php
/**
 * SQL normalizer.
 *
 * Replaces every literal (strings, numbers, hex values) with `?`, removes
 * comments, collapses IN/VALUES lists and whitespace. The result identifies
 * the *shape* of a query and never contains values, which may hold personal
 * data (e-mail addresses, names, order details …).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\QueryOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Query normalizer.
 */
final class QueryNormalizer {

	/**
	 * Maximum length of a stored normalized query.
	 */
	public const MAX_LENGTH = 300;

	/**
	 * Only this many bytes of a query are inspected. A literal cut off at
	 * this point is unterminated and therefore still replaced entirely.
	 */
	public const MAX_INPUT = 8192;

	/**
	 * Strings, quoted identifiers and comments (possessive, no backtracking).
	 */
	private const TOKENS = <<<'RE'
~'(?:[^'\\]++|\\.|'')*+'?|"(?:[^"\\]++|\\.|"")*+"?|`[^`]*+`?|/\*.*?(?:\*/|\z)|--(?=\s|\z)[^\n]*|\#[^\n]*~s
RE;

	/**
	 * Normalized and truncated query.
	 *
	 * @param string $sql        Raw SQL.
	 * @param int    $max_length Maximum length.
	 */
	public static function normalize( string $sql, int $max_length = self::MAX_LENGTH ): string {
		return self::truncate( self::full( $sql ), $max_length );
	}

	/**
	 * Normalized query without truncation (used for grouping).
	 *
	 * @param string $sql Raw SQL.
	 */
	public static function full( string $sql ): string {
		if ( strlen( $sql ) > self::MAX_INPUT ) {
			$sql = substr( $sql, 0, self::MAX_INPUT );
		}

		$sql = (string) preg_replace_callback(
			self::TOKENS,
			static function ( array $token ): string {
				$first = $token[0][0];
				if ( "'" === $first || '"' === $first ) {
					return '?';
				}
				if ( '`' === $first ) {
					return $token[0]; // Quoted identifier: table or column name.
				}
				return ' '; // Comment.
			},
			$sql
		);

		$replacements = array(
			// Hexadecimal literals.
			'/\b0x[0-9a-f]+\b/i'                  => '?',
			// Hex, bit and national strings and charset introducers (their quoted part is already a placeholder).
			'/(?<![\w$])(?:[xbn]|_[a-z0-9]+)\?/i' => '?',
			// Numbers (not parts of identifiers such as wp_2_posts or t1).
			'/(?<![\w$`.])(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?(?![\w$`])/i' => '?',
			// Signed literals.
			'/(?<=[\s(,=<>])[-+]\s*\?/'           => '?',
			// Whitespace.
			'/\s+/'                               => ' ',
			// Lists of placeholders, e.g. in IN and VALUES clauses.
			'/\(\s*\?(?:\s*,\s*\?)*\s*\)/'        => '(?)',
			// Several rows of placeholders in one INSERT.
			'/\(\?\)(?:\s*,\s*\(\?\))+/'          => '(?)',
		);

		foreach ( $replacements as $pattern => $replacement ) {
			$sql = (string) preg_replace( $pattern, $replacement, $sql );
		}

		return trim( $sql );
	}

	/**
	 * Stable key for a normalized query.
	 *
	 * @param string $normalized Normalized SQL ({@see full()}).
	 */
	public static function key( string $normalized ): string {
		return md5( $normalized );
	}

	/**
	 * Truncate to a maximum length (UTF-8 safe), marking the cut with "…".
	 *
	 * @param string $sql        SQL.
	 * @param int    $max_length Maximum length in bytes.
	 */
	public static function truncate( string $sql, int $max_length = self::MAX_LENGTH ): string {
		if ( strlen( $sql ) <= $max_length ) {
			return $sql;
		}
		$cut = function_exists( 'mb_strcut' ) ? mb_strcut( $sql, 0, max( 1, $max_length - 3 ), 'UTF-8' ) : substr( $sql, 0, max( 1, $max_length - 3 ) );
		return rtrim( $cut ) . '…';
	}
}
