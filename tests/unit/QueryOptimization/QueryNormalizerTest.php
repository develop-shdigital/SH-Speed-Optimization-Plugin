<?php
/**
 * Tests for the SQL normalizer.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\QueryOptimization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Modules\QueryOptimization\QueryNormalizer;

final class QueryNormalizerTest extends TestCase {

	public function test_strings_and_numbers_become_placeholders(): void {
		$this->assertSame(
			'SELECT * FROM wp_posts WHERE ID = ? AND post_status = ? AND post_type = ?',
			QueryNormalizer::normalize( "SELECT * FROM wp_posts WHERE ID = 42 AND post_status = 'publish' AND post_type = \"page\"" )
		);
	}

	public function test_escaped_and_doubled_quotes_stay_inside_the_literal(): void {
		$this->assertSame(
			'SELECT ?, ? FROM t WHERE a = ?',
			QueryNormalizer::normalize( "SELECT 'It\\'s', 'John''s' FROM t WHERE a = 'x'" )
		);
	}

	public function test_identifiers_with_digits_are_kept(): void {
		$sql = 'SELECT t1.ID FROM wp_2_posts t1 JOIN `wp_3_postmeta` m2 ON m2.post_id = t1.ID WHERE t1.ID = 7';
		$this->assertSame(
			'SELECT t1.ID FROM wp_2_posts t1 JOIN `wp_3_postmeta` m2 ON m2.post_id = t1.ID WHERE t1.ID = ?',
			QueryNormalizer::normalize( $sql )
		);
	}

	public function test_in_lists_and_multi_row_values_collapse(): void {
		$this->assertSame(
			'SELECT * FROM t WHERE id IN (?) AND x IN (?)',
			QueryNormalizer::normalize( "SELECT * FROM t WHERE id IN (1, 2, 3, 4) AND x IN ('a','b')" )
		);
		$this->assertSame(
			'INSERT INTO t (a, b) VALUES (?)',
			QueryNormalizer::normalize( "INSERT INTO t (a, b) VALUES (1, 'x'), (2, 'y'),(3,'z')" )
		);
		// Different list lengths produce the same shape.
		$this->assertSame(
			QueryNormalizer::full( 'SELECT * FROM t WHERE id IN (1,2)' ),
			QueryNormalizer::full( 'SELECT * FROM t WHERE id IN (5,6,7,8,9)' )
		);
	}

	public function test_hex_bit_introducers_and_signed_numbers(): void {
		$this->assertSame(
			'SELECT ?, ?, ?, ?, ?, ?, ? FROM t WHERE a IN (?)',
			QueryNormalizer::normalize( "SELECT 0x4A6F686E, X'4A6F', b'101', N'name', _utf8mb4'text', 1.5e3, .5 FROM t WHERE a IN (-1, +2)" )
		);
	}

	public function test_comments_are_removed(): void {
		$sql = "SELECT a /* user 12345 */ FROM t -- email bob@example.com\nWHERE b = 1 # trailing 999";
		$this->assertSame( 'SELECT a FROM t WHERE b = ?', QueryNormalizer::normalize( $sql ) );
	}

	public function test_unterminated_literal_is_replaced_entirely(): void {
		$this->assertSame( 'SELECT * FROM t WHERE a = ?', QueryNormalizer::normalize( "SELECT * FROM t WHERE a = 'secret 123 and more" ) );
	}

	public function test_long_queries_are_truncated_to_300_characters(): void {
		$sql = 'SELECT ' . implode( ', ', array_map( static fn( $i ) => 'column_' . $i, range( 1, 200 ) ) ) . ' FROM t';
		$out = QueryNormalizer::normalize( $sql );
		$this->assertLessThanOrEqual( 300, strlen( $out ) );
		$this->assertStringEndsWith( '…', $out );
	}

	public function test_input_cut_inside_a_literal_does_not_leak(): void {
		$secret = str_repeat( 'x', QueryNormalizer::MAX_INPUT ) . 'SECRET-TAIL';
		$out    = QueryNormalizer::full( "UPDATE t SET v = '" . $secret . "' WHERE id = 1" );
		$this->assertSame( 'UPDATE t SET v = ?', $out );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function sensitive_queries(): array {
		return array(
			'email'        => array( "SELECT * FROM wp_users WHERE user_email = 'jane.doe@example.com'", 'jane.doe@example.com' ),
			'phone'        => array( 'SELECT * FROM wp_usermeta WHERE meta_value = 41791234567', '41791234567' ),
			'like'         => array( "SELECT * FROM wp_posts WHERE post_title LIKE '%Private Name%'", 'Private Name' ),
			'double'       => array( 'SELECT * FROM t WHERE name = "Max Muster"', 'Max Muster' ),
			'escaped'      => array( "SELECT * FROM t WHERE note = 'a\\'Street 5\\'b'", 'Street 5' ),
			'insert'       => array( "INSERT INTO wp_comments (comment_author_email) VALUES ('me@private.test')", 'me@private.test' ),
			'hex'          => array( 'SELECT * FROM t WHERE token = 0xDEADBEEF', 'DEADBEEF' ),
			'comment'      => array( "SELECT 1 /* order 55512 by alice */ FROM t", 'alice' ),
			'binary-token' => array( "SELECT * FROM t WHERE k = X'CAFEBABE'", 'CAFEBABE' ),
		);
	}

	#[DataProvider( 'sensitive_queries' )]
	public function test_never_leaks_literals( string $sql, string $secret ): void {
		$this->assertStringNotContainsString( $secret, QueryNormalizer::normalize( $sql ) );
		$this->assertStringNotContainsString( $secret, QueryNormalizer::full( $sql ) );
	}

	public function test_key_is_stable_for_the_same_shape(): void {
		$this->assertSame(
			QueryNormalizer::key( QueryNormalizer::full( "SELECT * FROM t WHERE a = 'x'" ) ),
			QueryNormalizer::key( QueryNormalizer::full( "SELECT  *  FROM t WHERE a = 'yyy'" ) )
		);
	}
}
