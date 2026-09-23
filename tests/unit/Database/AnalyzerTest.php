<?php
/**
 * Tests for the database analyzer and selection criteria (fake $wpdb).
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Database;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Database\Analyzer;
use SH\SpeedOptimizer\Database\Criteria;
use SH\SpeedOptimizer\Database\Engine;

final class AnalyzerTest extends TestCase {

	private const NOW = 1790000000;

	protected function setUp(): void {
		shso_test_reset();
	}

	private function analyzer( \SHSO_Test_Fake_WPDB $wpdb, bool $sqlite = false, ?array $taxonomies = array( 'category', 'post_tag' ) ): Analyzer {
		return new Analyzer( $wpdb, new Engine( $wpdb, $sqlite ), self::NOW, $taxonomies, array( 'attachment', 'shop_order' ) );
	}

	public function test_autoload_uses_all_autoload_values_and_guesses_sources(): void {
		$wpdb = new \SHSO_Test_Fake_WPDB();
		$wpdb->on(
			'/SELECT COUNT\(\*\) AS cnt, COALESCE\(SUM\(LENGTH\(option_value\)\), 0\) AS bytes FROM wp_options/',
			array(
				'cnt'   => '812',
				'bytes' => '1363148',
			)
		);
		$wpdb->on(
			'/SELECT option_name, LENGTH\(option_value\) AS bytes FROM wp_options/',
			array(
				array(
					'option_name' => 'elementor_data_cache',
					'bytes'       => '400000',
				),
				array(
					'option_name' => '_transient_feed_abc',
					'bytes'       => '90000',
				),
				array(
					'option_name' => 'totally_unknown',
					'bytes'       => '100',
				),
			)
		);

		$autoload = $this->analyzer( $wpdb )->autoload();

		$this->assertSame( 1363148, $autoload['total_bytes'] );
		$this->assertSame( 812, $autoload['count'] );
		$this->assertSame( 'Elementor', $autoload['top'][0]['source'] );
		$this->assertSame( 'Transients (temporary cache)', $autoload['top'][1]['source'] );
		$this->assertNull( $autoload['top'][2]['source'] );

		$sql = implode( "\n", $wpdb->sql() );
		$this->assertStringContainsString( "autoload IN ('yes', 'on', 'auto-on', 'auto')", $sql );
		$this->assertStringContainsString( 'ORDER BY bytes DESC LIMIT 10', $sql );
	}

	public function test_autoload_unavailable_when_query_fails(): void {
		$this->assertNull( $this->analyzer( new \SHSO_Test_Fake_WPDB() )->autoload() );
	}

	public function test_revision_statistics(): void {
		$wpdb = new \SHSO_Test_Fake_WPDB();
		$wpdb->on( "/post_type = 'revision' AND post_name LIKE/", '4' );
		$wpdb->on( "/^SELECT COUNT\(\*\) FROM wp_posts WHERE post_type = 'revision'$/", '1240' );
		$wpdb->on(
			'/GROUP BY post_parent HAVING COUNT\(\*\) > 5\) shso_rev/',
			array(
				'parents' => '38',
				'revs'    => '1100',
			)
		);

		$stats = $this->analyzer( $wpdb )->revisions( 5 );

		$this->assertSame( 1240, $stats['total'] );
		$this->assertSame( 4, $stats['autosaves'] );
		$this->assertSame( 38, $stats['posts_over_keep'] );
		$this->assertSame( 1100 - 38 * 5, $stats['removable'] );
		$this->assertStringContainsString( "post_name NOT LIKE '%-autosave-v%'", implode( "\n", $wpdb->sql() ), 'Autosaves are never counted as removable.' );
	}

	public function test_revision_statistics_fall_back_without_derived_tables(): void {
		$wpdb = new \SHSO_Test_Fake_WPDB();
		$wpdb->on( "/^SELECT COUNT\(\*\) FROM wp_posts WHERE post_type = 'revision'$/", '30' );
		$wpdb->on( '/shso_rev/', null ); // Derived table unsupported.
		$wpdb->on(
			'/^SELECT post_parent, COUNT\(\*\) AS c FROM wp_posts .* GROUP BY post_parent HAVING COUNT\(\*\) > 5$/',
			array(
				array(
					'post_parent' => '1',
					'c'           => '12',
				),
				array(
					'post_parent' => '2',
					'c'           => '6',
				),
			)
		);

		$stats = $this->analyzer( $wpdb )->revisions( 5 );
		$this->assertSame( 2, $stats['posts_over_keep'] );
		$this->assertSame( 7 + 1, $stats['removable'] );
	}

	public function test_failed_count_is_null_not_zero(): void {
		$wpdb = new \SHSO_Test_Fake_WPDB();
		$this->assertNull( $this->analyzer( $wpdb )->count_item( 'spam_comments' ) );
		$this->assertNull( $this->analyzer( $wpdb )->revisions( 5 ) );
	}

	public function test_item_queries(): void {
		$wpdb = new \SHSO_Test_Fake_WPDB();
		$wpdb->on( '/SELECT COUNT/', '3' );
		$analyzer = $this->analyzer( $wpdb );

		$this->assertSame( 3, $analyzer->count_item( 'auto_drafts' ) );
		$this->assertSame( 3, $analyzer->count_item( 'trashed_posts' ) );
		$this->assertSame( 3, $analyzer->count_item( 'spam_comments' ) );
		$this->assertSame( 3, $analyzer->count_item( 'expired_transients' ) );
		$this->assertSame( 3, $analyzer->count_item( 'orphaned_postmeta' ) );
		$this->assertSame( 3, $analyzer->count_item( 'orphaned_termmeta' ) );
		$this->assertNull( $analyzer->count_item( 'users' ) );

		$sql = $wpdb->sql();
		$this->assertStringContainsString( "post_status = 'auto-draft' AND post_date < '" . gmdate( 'Y-m-d H:i:s', self::NOW - Criteria::AUTO_DRAFT_MIN_AGE ) . "'", $sql[0] );
		$this->assertStringContainsString( "post_status = 'trash' AND post_type NOT IN ('attachment', 'shop_order')", $sql[1] );
		$this->assertStringContainsString( "comment_approved = 'spam'", $sql[2] );
		$this->assertStringContainsString( "(option_name LIKE '\\\\_transient\\\\_timeout\\\\_%' OR option_name LIKE '\\\\_site\\\\_transient\\\\_timeout\\\\_%') AND option_value < " . self::NOW, $sql[3] );
		$this->assertStringContainsString( 'FROM wp_postmeta m LEFT JOIN wp_posts o ON o.ID = m.post_id WHERE o.ID IS NULL', $sql[4] );
		$this->assertStringContainsString( 'FROM wp_termmeta m LEFT JOIN wp_terms o ON o.term_id = m.term_id WHERE o.term_id IS NULL', $sql[5] );
	}

	public function test_orphaned_relationships_only_for_post_taxonomies(): void {
		$wpdb = new \SHSO_Test_Fake_WPDB();
		$wpdb->on( '/FROM wp_term_relationships/', '9' );

		$this->assertSame( 9, $this->analyzer( $wpdb )->count_orphaned_relationships() );
		$this->assertStringContainsString( "tt.taxonomy IN ('category', 'post_tag')", $wpdb->sql()[0] );

		$this->assertSame( 0, $this->analyzer( new \SHSO_Test_Fake_WPDB(), false, array() )->count_orphaned_relationships() );
	}

	public function test_sqlite_tables_unavailable_without_mysql_statements(): void {
		$wpdb   = new \SHSO_Test_Fake_WPDB();
		$tables = $this->analyzer( $wpdb, true )->tables();

		$this->assertFalse( $tables['available'] );
		$this->assertNull( $tables['total_bytes'] );
		$this->assertNull( $tables['overhead_bytes'] );
		foreach ( $wpdb->sql() as $sql ) {
			$this->assertStringNotContainsString( 'SHOW TABLE STATUS', $sql );
		}
	}

	public function test_mysql_table_status(): void {
		$wpdb = new \SHSO_Test_Fake_WPDB();
		$wpdb->on(
			'/SHOW TABLE STATUS LIKE/',
			array(
				array(
					'Name'         => 'wp_posts',
					'Engine'       => 'InnoDB',
					'Rows'         => '100',
					'Data_length'  => '1000',
					'Index_length' => '200',
					'Data_free'    => '4194304',
				),
				array(
					'Name'         => 'wp_postmeta',
					'Engine'       => 'MyISAM',
					'Rows'         => '900',
					'Data_length'  => '5000',
					'Index_length' => '800',
					'Data_free'    => '300',
				),
			)
		);

		$analyzer = $this->analyzer( $wpdb );
		$tables   = $analyzer->tables();
		$this->assertTrue( $tables['available'] );
		$this->assertSame( 7000, $tables['total_bytes'] );
		$this->assertSame( 300, $tables['overhead_bytes'], 'InnoDB free space is not counted as overhead.' );
		$this->assertSame( 'wp_postmeta', $tables['largest'][0]['name'] );
		$this->assertSame(
			array(
				'InnoDB' => 1,
				'MyISAM' => 1,
			),
			$tables['engines']
		);
		$this->assertStringContainsString( "SHOW TABLE STATUS LIKE 'wp\\\\_%'", $wpdb->sql()[0] );

		$engine = new Engine( $wpdb, false );
		$this->assertTrue( $engine->supports_transactions( array( 'wp_posts' ) ) );
		$this->assertFalse( $engine->supports_transactions( array( 'wp_posts', 'wp_postmeta' ) ), 'MyISAM tables get no transactions.' );
		$this->assertFalse( $engine->supports_transactions( array( 'wp_unknown' ) ) );
		$this->assertFalse( ( new Engine( $wpdb, true ) )->supports_transactions( array( 'wp_posts' ) ) );
	}

	public function test_parse_table_status_excludes_other_network_sites_on_main_site(): void {
		$rows = array(
			array(
				'Name'   => 'wp_posts',
				'Engine' => 'InnoDB',
			),
			array(
				'Name'   => 'wp_2_posts',
				'Engine' => 'InnoDB',
			),
			array(
				'Name'   => 'other_posts',
				'Engine' => 'InnoDB',
			),
		);
		$this->assertSame( array( 'wp_posts' ), array_keys( Engine::parse_table_status( $rows, 'wp_', true ) ) );
		$this->assertSame( array( 'wp_posts', 'wp_2_posts' ), array_keys( Engine::parse_table_status( $rows, 'wp_', false ) ) );
	}

	public function test_analyze_shape(): void {
		$wpdb     = new \SHSO_Test_Fake_WPDB();
		$analysis = $this->analyzer( $wpdb, true )->analyze();

		foreach ( array( 'revisions', 'auto_drafts', 'trashed_posts', 'spam_comments', 'trashed_comments', 'expired_transients', 'orphaned_postmeta', 'orphaned_commentmeta', 'orphaned_termmeta', 'orphaned_relationships', 'autoload', 'tables', 'analyzed_at' ) as $key ) {
			$this->assertArrayHasKey( $key, $analysis );
		}
		$this->assertSame( self::NOW, $analysis['analyzed_at'] );
		$this->assertSame( 'sqlite', $analysis['engine'] );
	}

	public function test_option_source_guess(): void {
		$this->assertSame( 'Yoast SEO', Analyzer::guess_option_source( 'wpseo_titles' ) );
		$this->assertSame( 'Rank Math', Analyzer::guess_option_source( 'rank_math_options_general' ) );
		$this->assertSame( 'WooCommerce', Analyzer::guess_option_source( 'woocommerce_permalinks' ) );
		$this->assertSame( 'Transients (temporary cache)', Analyzer::guess_option_source( '_site_transient_update_plugins' ) );
		$this->assertSame( 'WordPress (user roles)', Analyzer::guess_option_source( 'wp_user_roles' ) );
		$this->assertSame( 'WordPress (scheduled tasks)', Analyzer::guess_option_source( 'cron' ) );
		$this->assertNull( Analyzer::guess_option_source( 'blogname_custom' ) );
		$this->assertSame(
			'Long',
			Analyzer::guess_option_source(
				'abc_def_x',
				array(
					'abc_'     => 'Short',
					'abc_def_' => 'Long',
				)
			)
		);
	}

	public function test_source_map_is_filterable(): void {
		add_filter(
			'shso_db_option_source_map',
			static function ( array $map ): array {
				$map['acme_'] = 'Acme Tools';
				return $map;
			}
		);
		$this->assertSame( 'Acme Tools', Analyzer::guess_option_source( 'acme_settings' ) );
	}

	public function test_autoload_values_always_include_legacy_yes(): void {
		$this->assertSame( array( 'yes', 'on', 'auto-on', 'auto' ), Analyzer::autoload_values() );
	}

	public function test_excluded_post_types_filter_and_sanitizing(): void {
		add_filter(
			'shso_db_clean_excluded_post_types',
			static function ( array $types ): array {
				$types[] = 'Product Order!';
				$types[] = 'attachment';
				return $types;
			}
		);
		$types = Criteria::excluded_post_types();
		$this->assertContains( 'attachment', $types );
		$this->assertContains( 'shop_order', $types );
		$this->assertContains( 'productorder', $types );
		$this->assertSame( count( $types ), count( array_unique( $types ) ) );
	}
}
