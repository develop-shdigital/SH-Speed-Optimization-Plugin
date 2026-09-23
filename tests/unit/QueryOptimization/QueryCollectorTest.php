<?php
/**
 * Tests for query attribution, report aggregation and findings.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\QueryOptimization;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Modules\QueryOptimization\QueryCollector;
use SH\SpeedOptimizer\Modules\QueryOptimization\QueryFindings;
use SH\SpeedOptimizer\Modules\QueryOptimization\QueryReport;

final class QueryCollectorTest extends TestCase {

	private const ROOTS = array(
		'plugin'    => array( '/var/www/wp-content/plugins' ),
		'mu-plugin' => array( '/var/www/wp-content/mu-plugins/' ),
		'theme'     => array( '/var/www/wp-content/themes/' ),
		'ignore'    => array(
			'/var/www/wp-includes/class-wpdb.php',
			'/var/www/wp-content/db.php',
			'/var/www/wp-content/plugins/sh-speed-optimizer/',
			'/var/www/wp-content/plugins/sqlite-database-integration/',
		),
	);

	// ------------------------------------------------------------------
	// Attribution.
	// ------------------------------------------------------------------

	public function test_first_plugin_frame_wins_over_core_frames(): void {
		$files = array(
			'/var/www/wp-includes/class-wpdb.php',
			'/var/www/wp-includes/option.php',
			'/var/www/wp-content/plugins/woocommerce/includes/class-wc-cart.php',
			'/var/www/wp-content/themes/astra/functions.php',
			'/var/www/index.php',
		);
		$this->assertSame( 'plugin:woocommerce', QueryCollector::component_for_files( $files, self::ROOTS ) );
	}

	public function test_theme_mu_plugin_and_single_file_plugin(): void {
		$this->assertSame( 'theme:astra', QueryCollector::component_for_files( array( '/var/www/wp-includes/query.php', '/var/www/wp-content/themes/astra/template-parts/loop.php' ), self::ROOTS ) );
		$this->assertSame( 'mu-plugin:host-tools', QueryCollector::component_for_files( array( '/var/www/wp-content/mu-plugins/host-tools.php' ), self::ROOTS ) );
		$this->assertSame( 'mu-plugin:loader', QueryCollector::component_for_files( array( '/var/www/wp-content/mu-plugins/loader/main.php' ), self::ROOTS ) );
		$this->assertSame( 'plugin:hello', QueryCollector::component_for_files( array( '/var/www/wp-content/plugins/hello.php' ), self::ROOTS ) );
	}

	public function test_core_when_no_extension_is_involved(): void {
		$files = array( '/var/www/wp-includes/class-wpdb.php', '/var/www/wp-includes/post.php', '/var/www/wp-settings.php' );
		$this->assertSame( 'core', QueryCollector::component_for_files( $files, self::ROOTS ) );
		$this->assertSame( 'core', QueryCollector::component_for_files( array(), self::ROOTS ) );
	}

	public function test_ignored_frames_are_skipped(): void {
		$files = array(
			'/var/www/wp-content/db.php',
			'/var/www/wp-content/plugins/sqlite-database-integration/wp-includes/sqlite/class-wp-sqlite-db.php',
			'/var/www/wp-content/plugins/sh-speed-optimizer/includes/Core/Plugin.php',
			'/var/www/wp-content/plugins/contact-form-7/includes/controller.php',
		);
		$this->assertSame( 'plugin:contact-form-7', QueryCollector::component_for_files( $files, self::ROOTS ) );

		// Only ignored frames and core: core.
		$this->assertSame( 'core', QueryCollector::component_for_files( array_slice( $files, 0, 3 ), self::ROOTS ) );
	}

	public function test_similar_plugin_directory_names_are_not_confused(): void {
		$files = array( '/var/www/wp-content/plugins/sh-speed-optimizer-pro/main.php' );
		$this->assertSame( 'plugin:sh-speed-optimizer-pro', QueryCollector::component_for_files( $files, self::ROOTS ) );
	}

	public function test_windows_paths(): void {
		$roots = array(
			'plugin' => array( 'C:\\sites\\wp\\wp-content\\plugins' ),
			'ignore' => array(),
		);
		$this->assertSame( 'plugin:elementor', QueryCollector::component_for_files( array( 'C:\\sites\\wp\\wp-content\\plugins\\elementor\\core\\base.php' ), $roots ) );
	}

	public function test_attach_never_throws_and_keeps_existing_data(): void {
		$data = QueryCollector::attach( array( 'other' => 1 ) );
		$this->assertSame( 1, $data['other'] );
		$this->assertArrayHasKey( QueryReport::DATA_KEY, $data );
		$this->assertIsArray( QueryCollector::attach( 'not-an-array' ) );
	}

	// ------------------------------------------------------------------
	// Report aggregation.
	// ------------------------------------------------------------------

	/**
	 * Query log entry like `$wpdb->queries`.
	 *
	 * @return array<int,mixed>
	 */
	private static function q( string $sql, float $ms, string $component ): array {
		return array( $sql, $ms / 1000, 'require, wp, WP_Query->get_posts', microtime( true ), array( QueryReport::DATA_KEY => $component ) );
	}

	/**
	 * @return array<int,array<int,mixed>>
	 */
	private static function sample_log(): array {
		$log = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$log[] = self::q( "SELECT option_value FROM wp_options WHERE option_name = 'secret_option_{$i}' LIMIT 1", 0.4, 'plugin:woocommerce' );
		}
		$log[] = self::q( "SELECT * FROM wp_posts WHERE post_author = 'alice@example.com'", 120.0, 'theme:astra' );
		$log[] = self::q( "SELECT * FROM wp_posts WHERE post_author = 'bob@example.com'", 80.0, 'theme:astra' );
		$log[] = self::q( 'SELECT SQL_CALC_FOUND_ROWS * FROM wp_posts INNER JOIN wp_postmeta ON ( wp_posts.ID = wp_postmeta.post_id ) INNER JOIN wp_postmeta AS mt1 ON ( wp_posts.ID = mt1.post_id ) WHERE 1=1', 60.0, 'plugin:acf' );
		$log[] = self::q( "SELECT post_id FROM wp_postmeta WHERE meta_value LIKE '%john@example.com%'", 10.0, 'plugin:acf' );
		$log[] = self::q( 'SELECT 1', 1.0, 'core' );
		$log[] = array( 'SELECT 2', 0.002, '' ); // No custom data: unknown.
		return $log;
	}

	public function test_report_counts_and_time(): void {
		$report = QueryReport::build( self::sample_log() );

		$this->assertTrue( $report['available'] );
		$this->assertSame( 18, $report['count'] );
		$this->assertEqualsWithDelta( 12 * 0.4 + 120 + 80 + 60 + 10 + 1 + 2, $report['time_ms'], 0.2 );
	}

	public function test_slow_queries_are_deduplicated_sorted_and_normalized(): void {
		$report = QueryReport::build( self::sample_log() );

		$this->assertCount( 2, $report['slow'] ); // 120 and 80 ms share one shape; 60 ms is a second shape.
		$this->assertSame( 120.0, $report['slow'][0]['ms'] );
		$this->assertSame( 'theme:astra', $report['slow'][0]['component'] );
		$this->assertSame( 'SELECT * FROM wp_posts WHERE post_author = ?', $report['slow'][0]['sql'] );
		$this->assertSame( 60.0, $report['slow'][1]['ms'] );
	}

	public function test_repeated_queries(): void {
		$report = QueryReport::build( self::sample_log() );

		$this->assertCount( 1, $report['repeated'] );
		$this->assertSame( 12, $report['repeated'][0]['count'] );
		$this->assertSame( 'plugin:woocommerce', $report['repeated'][0]['component'] );
		$this->assertSame( 'SELECT option_value FROM wp_options WHERE option_name = ? LIMIT ?', $report['repeated'][0]['sql'] );
	}

	public function test_components_sorted_by_time(): void {
		$report     = QueryReport::build( self::sample_log() );
		$components = array_column( $report['components'], 'component' );

		$this->assertSame( array( 'theme:astra', 'plugin:acf', 'plugin:woocommerce', 'unknown', 'core' ), $components );
		$this->assertSame( 12, $report['components'][2]['count'] );
	}

	public function test_expensive_meta_detection(): void {
		$report  = QueryReport::build( self::sample_log() );
		$reasons = array_column( $report['expensive_meta'], 'reason' );

		$this->assertSame( array( 'multiple_meta_joins', 'meta_value_like' ), $reasons );
		$this->assertSame( 'multiple_meta_joins', QueryReport::meta_reason( 'SELECT * FROM a JOIN wp_postmeta m1 ON 1 LEFT JOIN `wp_2_postmeta` m2 ON 1' ) );
		$this->assertNull( QueryReport::meta_reason( "SELECT * FROM wp_postmeta WHERE meta_value LIKE 'abc%'" ) );
		$this->assertNull( QueryReport::meta_reason( 'SELECT * FROM a JOIN wp_postmeta m1 ON 1' ) );
	}

	public function test_report_never_contains_literal_values(): void {
		$json = (string) json_encode( QueryReport::build( self::sample_log() ) );

		foreach ( array( 'alice@example.com', 'bob@example.com', 'john@example.com', 'secret_option_' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}
	}

	public function test_invalid_component_data_becomes_unknown(): void {
		$report = QueryReport::build( array( array( 'SELECT 1', 0.001, '', 0, array( QueryReport::DATA_KEY => '../../evil' ) ) ) );
		$this->assertSame( 'unknown', $report['components'][0]['component'] );
	}

	public function test_empty_log_is_unavailable(): void {
		$this->assertFalse( QueryReport::build( array() )['available'] );
		$this->assertFalse( QueryReport::build( array( 'garbage', array( 1 ) ) )['available'] );
	}

	// ------------------------------------------------------------------
	// Findings.
	// ------------------------------------------------------------------

	/**
	 * @param array<int,array{0:string,1:int,2:float}> $components Component, count, ms.
	 * @return array<string,mixed>
	 */
	private static function report( array $components, int $count = 0, float $ms = 0.0 ): array {
		$entries = array();
		foreach ( $components as $c ) {
			$entries[] = array(
				'component' => $c[0],
				'count'     => $c[1],
				'ms'        => $c[2],
			);
		}
		return array(
			'available'      => true,
			'count'          => $count > 0 ? $count : array_sum( array_column( $entries, 'count' ) ),
			'time_ms'        => $ms > 0 ? $ms : array_sum( array_column( $entries, 'ms' ) ),
			'slow'           => array(),
			'repeated'       => array(),
			'components'     => $entries,
			'expensive_meta' => array(),
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

	public function test_plugin_over_query_threshold_is_a_warning_with_plain_wording(): void {
		$findings = self::by_id(
			QueryFindings::build(
				array( 'front_page' => self::report( array( array( 'plugin:woocommerce', 180, 900.0 ), array( 'core', 20, 10.0 ) ) ) ),
				array( 'plugin:woocommerce' => 'WooCommerce' )
			)
		);

		$this->assertArrayHasKey( 'queries_plugin_woocommerce', $findings );
		$finding = $findings['queries_plugin_woocommerce'];
		$this->assertSame( 'warning', $finding['severity'] );
		$this->assertSame( 'plugins', $finding['category'] );
		$this->assertSame( 'WooCommerce ran 180 database queries on your homepage (0.9 s).', $finding['title'] );
		$this->assertNull( $finding['optimization'] );
		$this->assertSame( 'scan', $finding['source'] );
		$this->assertArrayNotHasKey( 'queries_core', $findings, 'Core is not reported as a component.' );
	}

	public function test_component_severity_thresholds(): void {
		$this->assertSame( 'warning', QueryFindings::component_severity( 101, 10.0 ) );
		$this->assertSame( 'warning', QueryFindings::component_severity( 5, 301.0 ) );
		$this->assertSame( 'notice', QueryFindings::component_severity( 51, 10.0 ) );
		$this->assertSame( 'notice', QueryFindings::component_severity( 5, 101.0 ) );
		$this->assertNull( QueryFindings::component_severity( 50, 100.0 ) );
	}

	public function test_light_components_produce_no_finding_and_load_is_good(): void {
		$findings = self::by_id( QueryFindings::build( array( 'front_page' => self::report( array( array( 'plugin:akismet', 3, 2.0 ) ) ) ) ) );

		$this->assertArrayNotHasKey( 'queries_plugin_akismet', $findings );
		$this->assertSame( 'good', $findings['db_query_load']['severity'] );
	}

	public function test_names_resolve_for_themes_slugs_and_unknown_plugins(): void {
		$this->assertSame( 'The Astra theme', QueryFindings::component_name( 'theme:astra', array( 'theme:astra' => 'Astra' ) ) );
		$this->assertSame( 'The Kadence theme', QueryFindings::component_name( 'theme:kadence' ) );
		$this->assertSame( 'Yoast SEO', QueryFindings::component_name( 'plugin:wordpress-seo', array( 'wordpress-seo' => 'Yoast SEO' ) ) );
		$this->assertSame( 'My Custom Plugin', QueryFindings::component_name( 'plugin:my-custom_plugin' ) );
		$this->assertSame( 'WordPress itself', QueryFindings::component_name( 'core' ) );
		$this->assertSame( 'Unidentified code', QueryFindings::component_name( 'unknown' ) );
	}

	public function test_theme_component_is_worded_as_theme(): void {
		$findings = self::by_id( QueryFindings::build( array( 'https://example.test/shop/' => self::report( array( array( 'theme:astra', 20, 450.0 ) ) ) ), array( 'theme:astra' => 'Astra' ) ) );

		$this->assertSame( 'The Astra theme ran 20 database queries on the page /shop/ (450 ms).', $findings['queries_theme_astra']['title'] );
		$this->assertStringContainsString( 'theme', $findings['queries_theme_astra']['recommendation'] );
	}

	public function test_page_totals_warning(): void {
		$findings = self::by_id( QueryFindings::build( array( 'single-post' => self::report( array( array( 'core', 600, 300.0 ) ) ) ) ) );

		$this->assertSame( 'warning', $findings['db_query_load']['severity'] );
		$this->assertSame( 'Building a blog post takes 600 database queries (300 ms).', $findings['db_query_load']['title'] );
	}

	public function test_slow_repeated_and_meta_findings(): void {
		$report = QueryReport::build( self::sample_log() );

		$report['repeated'][0]['count'] = 30;

		$findings = self::by_id( QueryFindings::build( array( 'front_page' => $report ), array( 'plugin:woocommerce' => 'WooCommerce' ) ) );

		$this->assertSame( 'notice', $findings['db_slow_queries']['severity'] );
		$this->assertSame( '2 slow database queries were found.', $findings['db_slow_queries']['title'] );
		$this->assertStringContainsString( 'The Astra theme', $findings['db_slow_queries']['description'] );

		$this->assertSame( 'notice', $findings['db_repeated_queries']['severity'] );
		$this->assertSame( 'The same database query ran 30 times on your homepage.', $findings['db_repeated_queries']['title'] );
		$this->assertStringContainsString( 'WooCommerce', $findings['db_repeated_queries']['description'] );

		$this->assertSame( 'notice', $findings['db_expensive_meta_queries']['severity'] );
		$this->assertStringContainsString( 'Acf', $findings['db_expensive_meta_queries']['description'] );
	}

	public function test_many_slow_queries_are_a_warning(): void {
		$slow = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$slow[] = array(
				'sql'       => 'SELECT ' . $i,
				'ms'        => 60.0,
				'component' => 'core',
			);
		}
		$report         = self::report( array( array( 'core', 10, 300.0 ) ) );
		$report['slow'] = $slow;

		$findings = self::by_id( QueryFindings::build( array( 'front_page' => $report ) ) );
		$this->assertSame( 'warning', $findings['db_slow_queries']['severity'] );
	}

	public function test_unavailable_reports(): void {
		$this->assertSame( array(), QueryFindings::build( array() ) );

		$findings = QueryFindings::build( array( 'front_page' => QueryReport::unavailable() ) );
		$this->assertCount( 1, $findings );
		$this->assertSame( 'db_query_analysis_unavailable', $findings[0]['id'] );
		$this->assertSame( 'info', $findings[0]['severity'] );
	}

	public function test_collector_findings_accept_a_name_map(): void {
		$findings = self::by_id( QueryCollector::findings( array( 'front_page' => self::report( array( array( 'plugin:elementor', 120, 100.0 ) ) ) ), array( 'plugin:elementor' => 'Elementor' ) ) );
		$this->assertStringStartsWith( 'Elementor ran 120 database queries', $findings['queries_plugin_elementor']['title'] );
	}

	public function test_page_labels_and_time_format(): void {
		$this->assertSame( 'your homepage', QueryFindings::page_label( 'front_page' ) );
		$this->assertSame( 'your homepage', QueryFindings::page_label( 'https://example.test/' ) );
		$this->assertSame( 'a event page', QueryFindings::page_label( 'single-event' ) );
		$this->assertSame( 'Checkout', QueryFindings::page_label( 'x', array( 'label' => 'Checkout' ) ) );
		$this->assertSame( '240 ms', QueryFindings::format_ms( 240.4 ) );
		$this->assertSame( '1.3 s', QueryFindings::format_ms( 1260.0 ) );
	}
}
