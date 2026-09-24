<?php
/**
 * Tests for database findings (thresholds and wording).
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Database;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Database\Findings;

final class FindingsTest extends TestCase {

	/**
	 * Analysis of a tidy site.
	 *
	 * @return array<string,mixed>
	 */
	private static function tidy_analysis(): array {
		return array(
			'revisions'              => array(
				'total'           => 3,
				'autosaves'       => 0,
				'posts_over_keep' => 0,
				'removable'       => 0,
				'keep'            => 5,
			),
			'auto_drafts'            => 0,
			'trashed_posts'          => 0,
			'spam_comments'          => 0,
			'trashed_comments'       => 0,
			'expired_transients'     => 0,
			'orphaned_postmeta'      => 0,
			'orphaned_commentmeta'   => 0,
			'orphaned_termmeta'      => 0,
			'orphaned_relationships' => 0,
			'autoload'               => array(
				'total_bytes' => 300 * 1024,
				'count'       => 420,
				'top'         => array(),
			),
			'tables'                 => array(
				'available'      => false,
				'count'          => null,
				'total_bytes'    => null,
				'overhead_bytes' => null,
				'engines'        => array(),
				'largest'        => array(),
			),
			'engine'                 => 'sqlite',
			'analyzed_at'            => 1700000000,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $findings Findings.
	 * @return array<string,array<string,mixed>>
	 */
	private static function by_id( array $findings ): array {
		$map = array();
		foreach ( $findings as $finding ) {
			$map[ $finding['id'] ] = $finding;
		}
		return $map;
	}

	public function test_finding_shape(): void {
		foreach ( Findings::build( self::tidy_analysis() ) as $finding ) {
			$this->assertSame( array( 'id', 'category', 'severity', 'title', 'description', 'recommendation', 'optimization', 'data', 'source' ), array_keys( $finding ) );
			$this->assertSame( 'database', $finding['category'] );
			$this->assertNull( $finding['optimization'] );
			$this->assertSame( 'scan', $finding['source'] );
			$this->assertContains( $finding['severity'], array( 'good', 'info', 'notice', 'warning', 'critical' ) );
		}
	}

	public function test_tidy_database_is_good(): void {
		$findings = self::by_id( Findings::build( self::tidy_analysis() ) );

		$this->assertSame( 'good', $findings['db_tidy']['severity'] );
		$this->assertSame( 'Your database is tidy.', $findings['db_tidy']['title'] );
		$this->assertSame( 'good', $findings['db_autoload']['severity'] );
		$this->assertArrayNotHasKey( 'db_revisions', $findings );
		$this->assertArrayNotHasKey( 'db_size', $findings, 'Unavailable table sizes produce no finding.' );
	}

	public function test_revisions_wording_and_severity(): void {
		$analysis              = self::tidy_analysis();
		$analysis['revisions'] = array(
			'total'           => 1240,
			'autosaves'       => 2,
			'posts_over_keep' => 38,
			'removable'       => 1050,
			'keep'            => 5,
		);
		$findings              = self::by_id( Findings::build( $analysis ) );

		$finding = $findings['db_revisions'];
		$this->assertSame( 'notice', $finding['severity'] );
		$this->assertSame( 'Your database stores 1,240 old post revisions.', $finding['title'] );
		$this->assertSame( 'Clean up old revisions (the 5 most recent per post are kept). A backup is created first.', $finding['recommendation'] );
		$this->assertStringContainsString( '38 posts have more than 5 saved versions', $finding['description'] );
		$this->assertStringContainsString( '1,050 old copies can be removed', $finding['description'] );
		$this->assertSame( array( 'revisions' ), $finding['data']['clean_items'] );
		$this->assertArrayNotHasKey( 'db_tidy', $findings, 'A notice means the database is not tidy.' );
	}

	public function test_count_thresholds(): void {
		$this->assertSame( 'info', Findings::severity_for_count( 499, Findings::THRESHOLDS['revisions'] ) );
		$this->assertSame( 'notice', Findings::severity_for_count( 500, Findings::THRESHOLDS['revisions'] ) );
		$this->assertSame( 'warning', Findings::severity_for_count( 5000, Findings::THRESHOLDS['revisions'] ) );
		$this->assertSame( 'notice', Findings::severity_for_count( 999999, Findings::THRESHOLDS['trashed_posts'] ), 'Trash never becomes a warning.' );
	}

	public function test_small_leftovers_are_info_and_still_tidy(): void {
		$analysis                       = self::tidy_analysis();
		$analysis['spam_comments']      = 12;
		$analysis['expired_transients'] = 30;
		$findings                       = self::by_id( Findings::build( $analysis ) );

		$this->assertSame( 'info', $findings['db_spam_comments']['severity'] );
		$this->assertSame( '12 spam comments are stored.', $findings['db_spam_comments']['title'] );
		$this->assertSame( 'info', $findings['db_expired_transients']['severity'] );
		$this->assertSame( 'good', $findings['db_tidy']['severity'] );
		$this->assertStringContainsString( '42 entries', $findings['db_tidy']['description'] );
	}

	public function test_autoload_warning_names_largest_contributors(): void {
		$analysis             = self::tidy_analysis();
		$analysis['autoload'] = array(
			'total_bytes' => (int) round( 1.3 * 1024 * 1024 ),
			'count'       => 900,
			'top'         => array(
				array(
					'name'   => 'elementor_pro_license',
					'bytes'  => 430080,
					'source' => 'Elementor',
				),
				array(
					'name'   => 'mystery_option',
					'bytes'  => 102400,
					'source' => null,
				),
			),
		);
		$finding              = self::by_id( Findings::build( $analysis ) )['db_autoload'];

		$this->assertSame( 'warning', $finding['severity'] );
		$this->assertSame( 'Every page load reads 1.3 MB of settings.', $finding['title'] );
		$this->assertStringContainsString( 'elementor_pro_license (Elementor, 420 KB)', $finding['description'] );
		$this->assertStringContainsString( 'mystery_option (100 KB)', $finding['description'] );
	}

	public function test_autoload_thresholds(): void {
		$this->assertSame( 'good', Findings::autoload_severity( 800 * 1024 ) );
		$this->assertSame( 'warning', Findings::autoload_severity( 800 * 1024 + 1 ) );
		$this->assertSame( 'warning', Findings::autoload_severity( 2 * 1024 * 1024 ) );
		$this->assertSame( 'critical', Findings::autoload_severity( 2 * 1024 * 1024 + 1 ) );
	}

	public function test_unavailable_values_produce_no_findings(): void {
		$analysis = array(
			'revisions'          => null,
			'auto_drafts'        => null,
			'trashed_posts'      => null,
			'spam_comments'      => null,
			'expired_transients' => null,
			'autoload'           => null,
			'tables'             => array( 'available' => false ),
		);
		$this->assertSame( array(), Findings::build( $analysis ) );
	}

	public function test_orphaned_meta_is_combined(): void {
		$analysis                         = self::tidy_analysis();
		$analysis['orphaned_postmeta']    = 120;
		$analysis['orphaned_commentmeta'] = 4;
		$finding                          = self::by_id( Findings::build( $analysis ) )['db_orphaned_meta'];

		$this->assertSame( '124 stored details belong to content that no longer exists.', $finding['title'] );
		$this->assertStringContainsString( '120 for posts, 4 for comments', $finding['description'] );
		$this->assertSame( array( 'orphaned_postmeta', 'orphaned_commentmeta' ), $finding['data']['clean_items'] );
	}

	public function test_relationship_candidates_are_report_only(): void {
		$analysis                           = self::tidy_analysis();
		$analysis['orphaned_relationships'] = 7;
		$finding                            = self::by_id( Findings::build( $analysis ) )['db_orphaned_relationships'];

		$this->assertSame( 'info', $finding['severity'] );
		$this->assertSame( array(), $finding['data']['clean_items'] );
		$this->assertSame( 7, $finding['data']['candidates'] );
	}

	public function test_table_findings(): void {
		$analysis           = self::tidy_analysis();
		$analysis['tables'] = array(
			'available'      => true,
			'count'          => 14,
			'total_bytes'    => 245 * 1024 * 1024,
			'overhead_bytes' => 60 * 1024 * 1024,
			'engines'        => array(
				'InnoDB' => 12,
				'MyISAM' => 2,
			),
			'largest'        => array(
				array(
					'name'  => 'wp_postmeta',
					'bytes' => 120 * 1024 * 1024,
					'rows'  => 1000,
				),
			),
		);
		$findings           = self::by_id( Findings::build( $analysis ) );

		$this->assertSame( 'Your database uses 245 MB.', $findings['db_size']['title'] );
		$this->assertStringContainsString( 'wp_postmeta (120 MB)', $findings['db_size']['description'] );
		$this->assertSame( 'notice', $findings['db_overhead']['severity'] );
		$this->assertSame( '2 database tables use the older MyISAM format.', $findings['db_myisam']['title'] );
	}

	public function test_format_bytes(): void {
		$this->assertSame( '0 B', Findings::format_bytes( 0 ) );
		$this->assertSame( '820 KB', Findings::format_bytes( 820 * 1024 ) );
		$this->assertSame( '1.3 MB', Findings::format_bytes( (int) ( 1.3 * 1024 * 1024 ) ) );
		$this->assertSame( '245 MB', Findings::format_bytes( 245 * 1024 * 1024 ) );
		$this->assertSame( '2.0 GB', Findings::format_bytes( 2 * 1024 * 1024 * 1024 ) );
	}
}
