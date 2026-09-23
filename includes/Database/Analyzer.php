<?php
/**
 * Database analysis: counts and sizes.
 *
 * Every number is a COUNT/SUM computed by the database; rows are never
 * loaded into PHP. A failing query yields null ("unavailable") instead of a
 * guessed value, and MySQL-only statements are skipped on SQLite.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Database analyzer.
 */
final class Analyzer {

	/**
	 * Autoload values that mean "load on every request" (WordPress 6.6+ plus legacy "yes").
	 */
	public const AUTOLOAD_VALUES = array( 'yes', 'on', 'auto-on', 'auto' );

	/**
	 * Number of largest autoloaded options / tables reported.
	 */
	public const TOP = 10;

	/**
	 * Database object.
	 *
	 * @var object
	 */
	private object $wpdb;

	/**
	 * Engine helper.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * Current timestamp.
	 *
	 * @var int
	 */
	private int $now;

	/**
	 * Taxonomies registered only for post types (null = detect).
	 *
	 * @var string[]|null
	 */
	private ?array $post_taxonomies;

	/**
	 * Post types excluded from the trash cleanup (null = filtered defaults).
	 *
	 * @var string[]|null
	 */
	private ?array $excluded_post_types;

	/**
	 * Constructor.
	 *
	 * @param object        $wpdb                Database object.
	 * @param Engine        $engine              Engine helper.
	 * @param int|null      $now                 Current timestamp.
	 * @param string[]|null $post_taxonomies     Taxonomies for the relationship check (null = detect).
	 * @param string[]|null $excluded_post_types Protected post types (null = defaults).
	 */
	public function __construct( object $wpdb, Engine $engine, ?int $now = null, ?array $post_taxonomies = null, ?array $excluded_post_types = null ) {
		$this->wpdb                = $wpdb;
		$this->engine              = $engine;
		$this->now                 = $now ?? time();
		$this->post_taxonomies     = $post_taxonomies;
		$this->excluded_post_types = $excluded_post_types;
	}

	/**
	 * Full analysis.
	 *
	 * @param int $keep Revisions kept per post for the "removable" figure.
	 * @return array<string,mixed>
	 */
	public function analyze( int $keep = 5 ): array {
		return array(
			'revisions'              => $this->revisions( $keep ),
			'auto_drafts'            => $this->count_item( 'auto_drafts' ),
			'trashed_posts'          => $this->count_item( 'trashed_posts' ),
			'spam_comments'          => $this->count_item( 'spam_comments' ),
			'trashed_comments'       => $this->count_item( 'trashed_comments' ),
			'expired_transients'     => $this->count_item( 'expired_transients' ),
			'orphaned_postmeta'      => $this->count_item( 'orphaned_postmeta' ),
			'orphaned_commentmeta'   => $this->count_item( 'orphaned_commentmeta' ),
			'orphaned_termmeta'      => $this->count_item( 'orphaned_termmeta' ),
			'orphaned_relationships' => $this->count_orphaned_relationships(),
			'autoload'               => $this->autoload(),
			'tables'                 => $this->tables(),
			'engine'                 => $this->engine->is_sqlite() ? 'sqlite' : 'mysql',
			'analyzed_at'            => $this->now,
		);
	}

	/**
	 * Revision statistics.
	 *
	 * Autosaves hold unsaved editor changes; they are counted separately and
	 * never included in the removable figure.
	 *
	 * @param int $keep Revisions kept per post.
	 * @return array{total:int,autosaves:int|null,posts_over_keep:int|null,removable:int|null,keep:int}|null
	 */
	public function revisions( int $keep ): ?array {
		$posts = $this->wpdb->posts;
		$total = $this->count( "SELECT COUNT(*) FROM {$posts} WHERE post_type = %s", array( 'revision' ) );
		if ( null === $total ) {
			return null;
		}

		$autosaves = $this->count(
			"SELECT COUNT(*) FROM {$posts} WHERE post_type = %s AND post_name LIKE %s",
			array( 'revision', '%' . $this->engine->esc_like( Criteria::AUTOSAVE_PATTERN ) . '%' )
		);

		list( $where, $args ) = Criteria::revisions( $this->wpdb );
		$row                  = $this->row(
			"SELECT COUNT(*) AS parents, COALESCE(SUM(c), 0) AS revs FROM (SELECT COUNT(*) AS c FROM {$posts} WHERE {$where} GROUP BY post_parent HAVING COUNT(*) > %d) shso_rev",
			array_merge( $args, array( $keep ) )
		);

		$parents   = null;
		$removable = null;
		if ( is_array( $row ) ) {
			$parents   = max( 0, (int) ( $row['parents'] ?? 0 ) );
			$removable = max( 0, (int) ( $row['revs'] ?? 0 ) - $parents * $keep );
		}

		return array(
			'total'           => $total,
			'autosaves'       => $autosaves,
			'posts_over_keep' => $parents,
			'removable'       => $removable,
			'keep'            => $keep,
		);
	}

	/**
	 * Number of rows a cleanup item would remove (null = unavailable).
	 *
	 * @param string $item Cleanup item.
	 * @param int    $keep Revisions kept per post.
	 */
	public function count_item( string $item, int $keep = 5 ): ?int {
		$wpdb = $this->wpdb;

		switch ( $item ) {
			case 'revisions':
				$stats = $this->revisions( $keep );
				return null === $stats ? null : $stats['removable'];

			case 'auto_drafts':
				list( $where, $args ) = Criteria::auto_drafts( $this->now );
				return $this->count( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$where}", $args );

			case 'trashed_posts':
				list( $where, $args ) = Criteria::trashed_posts( $this->excluded_post_types ?? Criteria::excluded_post_types() );
				return $this->count( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$where}", $args );

			case 'spam_comments':
			case 'trashed_comments':
				list( $where, $args ) = Criteria::comments( 'spam_comments' === $item ? 'spam' : 'trash' );
				return $this->count( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE {$where}", $args );

			case 'expired_transients':
				$multisite            = function_exists( 'is_multisite' ) && is_multisite();
				list( $where, $args ) = Criteria::transient_timeouts( $wpdb, $this->now, ! $multisite );
				$count                = $this->count( "SELECT COUNT(*) FROM {$wpdb->options} WHERE {$where}", $args );
				if ( null !== $count && $multisite && self::manages_network_data() && ! empty( $wpdb->sitemeta ) ) {
					list( $where, $args ) = Criteria::site_transient_timeouts( $wpdb, $this->now, self::network_id() );
					$count               += (int) $this->count( "SELECT COUNT(*) FROM {$wpdb->sitemeta} WHERE {$where}", $args );
				}
				return $count;

			case 'orphaned_postmeta':
			case 'orphaned_commentmeta':
			case 'orphaned_termmeta':
				$definition = Criteria::orphaned_meta( $wpdb, substr( $item, 9, -4 ) );
				if ( null === $definition ) {
					return null;
				}
				return $this->count(
					"SELECT COUNT(*) FROM {$definition['meta']} m LEFT JOIN {$definition['object']} o ON o.{$definition['object_pk']} = m.{$definition['meta_fk']} WHERE o.{$definition['object_pk']} IS NULL AND m.meta_id > %d",
					array( 0 )
				);
		}

		return null;
	}

	/**
	 * Term relationships whose object is not an existing post ("candidates").
	 *
	 * Only taxonomies that are registered exclusively for post types are
	 * checked: link categories and taxonomies of users or other objects
	 * legitimately point to non-post ids.
	 */
	public function count_orphaned_relationships(): ?int {
		$taxonomies = $this->post_taxonomies();
		if ( null === $taxonomies ) {
			return null;
		}
		if ( empty( $taxonomies ) ) {
			return 0;
		}

		$wpdb = $this->wpdb;
		$in   = Criteria::placeholders( count( $taxonomies ), '%s' );
		return $this->count(
			"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.ID IS NULL AND tt.taxonomy IN ({$in})",
			$taxonomies
		);
	}

	/**
	 * Autoloaded options: total size, count and the largest entries.
	 *
	 * @return array{total_bytes:int,count:int,top:array<int,array{name:string,bytes:int,source:string|null}>}|null
	 */
	public function autoload(): ?array {
		$options = $this->wpdb->options;
		$values  = self::autoload_values();
		$in      = Criteria::placeholders( count( $values ), '%s' );

		$row = $this->row(
			"SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(option_value)), 0) AS bytes FROM {$options} WHERE autoload IN ({$in})",
			$values
		);
		if ( ! is_array( $row ) ) {
			return null;
		}

		$rows = $this->results(
			"SELECT option_name, LENGTH(option_value) AS bytes FROM {$options} WHERE autoload IN ({$in}) ORDER BY bytes DESC LIMIT %d",
			array_merge( $values, array( self::TOP ) )
		);

		$top = array();
		foreach ( (array) $rows as $option ) {
			$name  = substr( (string) ( $option['option_name'] ?? '' ), 0, 191 );
			$top[] = array(
				'name'   => $name,
				'bytes'  => (int) ( $option['bytes'] ?? 0 ),
				'source' => self::guess_option_source( $name ),
			);
		}

		return array(
			'total_bytes' => max( 0, (int) ( $row['bytes'] ?? 0 ) ),
			'count'       => max( 0, (int) ( $row['cnt'] ?? 0 ) ),
			'top'         => $top,
		);
	}

	/**
	 * Table sizes (null values when unavailable, e.g. on SQLite).
	 *
	 * @return array<string,mixed>
	 */
	public function tables(): array {
		$status = $this->engine->table_status();
		if ( null === $status ) {
			return array(
				'available'      => false,
				'count'          => null,
				'total_bytes'    => null,
				'overhead_bytes' => null,
				'engines'        => array(),
				'largest'        => array(),
			);
		}
		return self::summarize_tables( $status );
	}

	/**
	 * Summarize table status rows.
	 *
	 * Overhead only counts tables whose free space belongs to the table
	 * itself (MyISAM/Aria). InnoDB reports free space of a shared tablespace,
	 * which would be counted once per table and grossly overstate it.
	 *
	 * @param array<string,array{engine:string,rows:int,data:int,index:int,free:int}> $status Table status.
	 * @return array<string,mixed>
	 */
	public static function summarize_tables( array $status ): array {
		$total    = 0;
		$overhead = 0;
		$engines  = array();
		$sizes    = array();

		foreach ( $status as $name => $table ) {
			$bytes  = (int) $table['data'] + (int) $table['index'];
			$total += $bytes;
			$engine = '' === (string) $table['engine'] ? 'unknown' : (string) $table['engine'];

			$engines[ $engine ] = ( $engines[ $engine ] ?? 0 ) + 1;
			if ( 'innodb' !== strtolower( $engine ) ) {
				$overhead += max( 0, (int) $table['free'] );
			}

			$sizes[] = array(
				'name'   => (string) $name,
				'bytes'  => $bytes,
				'rows'   => (int) $table['rows'],
				'engine' => $engine,
			);
		}

		usort(
			$sizes,
			static function ( array $a, array $b ): int {
				$order = $b['bytes'] <=> $a['bytes'];
				return 0 !== $order ? $order : strcmp( $a['name'], $b['name'] );
			}
		);
		arsort( $engines );

		return array(
			'available'      => true,
			'count'          => count( $status ),
			'total_bytes'    => $total,
			'overhead_bytes' => $overhead,
			'engines'        => $engines,
			'largest'        => array_slice( $sizes, 0, self::TOP ),
		);
	}

	/**
	 * Light-weight size figures used before/after a cleanup.
	 *
	 * @return array{tables_bytes:int|null,autoload_bytes:int|null}
	 */
	public function sizes(): array {
		$tables   = $this->tables();
		$autoload = $this->autoload();
		return array(
			'tables_bytes'   => $tables['total_bytes'],
			'autoload_bytes' => null === $autoload ? null : $autoload['total_bytes'],
		);
	}

	/**
	 * Autoload values WordPress loads on every request.
	 *
	 * @return string[]
	 */
	public static function autoload_values(): array {
		$values = function_exists( 'wp_autoload_values_to_autoload' ) ? (array) wp_autoload_values_to_autoload() : self::AUTOLOAD_VALUES;
		$values = array_intersect( self::AUTOLOAD_VALUES, array_map( 'strval', $values ) );
		return array_values( array_unique( array_merge( array( 'yes' ), $values ) ) );
	}

	/**
	 * Best-effort guess of the plugin/theme that owns an option, from its name.
	 *
	 * @param string                    $name Option name.
	 * @param array<string,string>|null $map  Prefix => label map (null = defaults).
	 */
	public static function guess_option_source( string $name, ?array $map = null ): ?string {
		$name  = strtolower( $name );
		$exact = array(
			'cron'             => __( 'WordPress (scheduled tasks)', 'sh-speed-optimizer' ),
			'rewrite_rules'    => __( 'WordPress (permalinks)', 'sh-speed-optimizer' ),
			'active_plugins'   => __( 'WordPress (active plugins)', 'sh-speed-optimizer' ),
			'sidebars_widgets' => __( 'WordPress (widgets)', 'sh-speed-optimizer' ),
			'recently_edited'  => __( 'WordPress', 'sh-speed-optimizer' ),
		);
		if ( isset( $exact[ $name ] ) ) {
			return $exact[ $name ];
		}
		if ( preg_match( '/^[a-z0-9_]*user_roles$/', $name ) ) {
			return __( 'WordPress (user roles)', 'sh-speed-optimizer' );
		}

		$map  = $map ?? self::source_map();
		$best = null;
		$len  = 0;
		foreach ( $map as $prefix => $label ) {
			$prefix = strtolower( (string) $prefix );
			if ( '' !== $prefix && strlen( $prefix ) > $len && 0 === strncmp( $name, $prefix, strlen( $prefix ) ) ) {
				$best = (string) $label;
				$len  = strlen( $prefix );
			}
		}
		return $best;
	}

	/**
	 * Option name prefix => plugin/theme name.
	 *
	 * @return array<string,string>
	 */
	public static function source_map(): array {
		$transients = __( 'Transients (temporary cache)', 'sh-speed-optimizer' );
		$map        = array(
			'_transient_'      => $transients,
			'_site_transient_' => $transients,
			'theme_mods_'      => __( 'Theme settings', 'sh-speed-optimizer' ),
			'widget_'          => __( 'WordPress (widgets)', 'sh-speed-optimizer' ),
			'elementor'        => 'Elementor',
			'_elementor'       => 'Elementor',
			'wpseo'            => 'Yoast SEO',
			'yoast'            => 'Yoast SEO',
			'_yoast'           => 'Yoast SEO',
			'rank_math'        => 'Rank Math',
			'rank-math'        => 'Rank Math',
			'aioseo'           => 'All in One SEO',
			'seopress'         => 'SEOPress',
			'autodescription'  => 'The SEO Framework',
			'woocommerce'      => 'WooCommerce',
			'wc_'              => 'WooCommerce',
			'_wc_'             => 'WooCommerce',
			'wcpay'            => 'WooPayments',
			'action_scheduler' => 'Action Scheduler',
			'jetpack'          => 'Jetpack',
			'wordfence'        => 'Wordfence',
			'itsec'            => 'Solid Security',
			'wpcf7'            => 'Contact Form 7',
			'gform'            => 'Gravity Forms',
			'rg_gforms'        => 'Gravity Forms',
			'wpforms'          => 'WPForms',
			'ninja_forms'      => 'Ninja Forms',
			'frm_'             => 'Formidable Forms',
			'acf'              => 'Advanced Custom Fields',
			'options_'         => 'Advanced Custom Fields',
			'et_'              => 'Divi',
			'fusion'           => 'Avada',
			'avada'            => 'Avada',
			'bricks_'          => 'Bricks',
			'oxygen'           => 'Oxygen',
			'fl_builder'       => 'Beaver Builder',
			'_fl_builder'      => 'Beaver Builder',
			'wpb_js'           => 'WPBakery',
			'vc_'              => 'WPBakery',
			'litespeed'        => 'LiteSpeed Cache',
			'wp_rocket'        => 'WP Rocket',
			'rocket_'          => 'WP Rocket',
			'w3tc'             => 'W3 Total Cache',
			'wpsupercache'     => 'WP Super Cache',
			'autoptimize'      => 'Autoptimize',
			'perfmatters'      => 'Perfmatters',
			'wp-optimize'      => 'WP-Optimize',
			'wpo_'             => 'WP-Optimize',
			'mailpoet'         => 'MailPoet',
			'wp_mail_smtp'     => 'WP Mail SMTP',
			'edd_'             => 'Easy Digital Downloads',
			'icl_'             => 'WPML',
			'wpml'             => 'WPML',
			'polylang'         => 'Polylang',
			'pll_'             => 'Polylang',
			'updraft'          => 'UpdraftPlus',
			'ai1wm'            => 'All-in-One WP Migration',
			'duplicator'       => 'Duplicator',
			'wpmdb'            => 'WP Migrate',
			'sucuri'           => 'Sucuri',
			'monsterinsights'  => 'MonsterInsights',
			'googlesitekit'    => 'Site Kit by Google',
			'tribe_'           => 'The Events Calendar',
			'learndash'        => 'LearnDash',
			'sfwd'             => 'LearnDash',
			'pmpro'            => 'Paid Memberships Pro',
			'mepr'             => 'MemberPress',
			'redirection'      => 'Redirection',
			'wp-smush'         => 'Smush',
			'smush'            => 'Smush',
			'ewww'             => 'EWWW Image Optimizer',
			'imagify'          => 'Imagify',
			'complianz'        => 'Complianz',
			'cmplz'            => 'Complianz',
			'astra'            => 'Astra',
			'generate_'        => 'GeneratePress',
			'kadence'          => 'Kadence',
			'ocean'            => 'OceanWP',
			'uag'              => 'Spectra',
			'spectra'          => 'Spectra',
			'give_'            => 'GiveWP',
			'yith'             => 'YITH',
			'wpdiscuz'         => 'wpDiscuz',
			'bp-'              => 'BuddyPress',
			'bbp_'             => 'bbPress',
			'shso_'            => 'SH Speed Optimizer',
		);

		/**
		 * Filters the option name prefix => owner map used to name the largest autoloaded settings.
		 *
		 * @param array<string,string> $map Prefix => plugin/theme name.
		 */
		return (array) apply_filters( 'shso_db_option_source_map', $map );
	}

	/**
	 * Whether this site may clean network-wide data (main site of the network).
	 */
	public static function manages_network_data(): bool {
		return ! function_exists( 'is_multisite' ) || ! is_multisite() || ( function_exists( 'is_main_site' ) && is_main_site() );
	}

	/**
	 * Current network id.
	 */
	public static function network_id(): int {
		return function_exists( 'get_current_network_id' ) ? (int) get_current_network_id() : 1;
	}

	/**
	 * Taxonomies registered exclusively for post types.
	 *
	 * @return string[]|null
	 */
	private function post_taxonomies(): ?array {
		if ( null !== $this->post_taxonomies ) {
			return $this->post_taxonomies;
		}
		if ( ! function_exists( 'get_taxonomies' ) || ! function_exists( 'post_type_exists' ) ) {
			return null;
		}

		$result = array();
		foreach ( (array) get_taxonomies( array(), 'objects' ) as $name => $taxonomy ) {
			$types = array_filter( (array) ( $taxonomy->object_type ?? array() ) );
			if ( 'link_category' === $name || empty( $types ) ) {
				continue;
			}
			foreach ( $types as $type ) {
				if ( ! post_type_exists( (string) $type ) ) {
					continue 2;
				}
			}
			$result[] = (string) $name;
		}

		$this->post_taxonomies = $result;
		return $result;
	}

	/**
	 * Run a COUNT query (null on failure).
	 *
	 * @param string           $sql  SQL template.
	 * @param array<int,mixed> $args Arguments.
	 */
	private function count( string $sql, array $args ): ?int {
		$wpdb  = $this->wpdb;
		$value = $this->engine->quiet(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Table names come from $wpdb, values are prepared.
			static fn() => $wpdb->get_var( $wpdb->prepare( $sql, $args ) )
		);
		if ( null === $value || false === $value || ! is_numeric( $value ) ) {
			return null;
		}
		return max( 0, (int) $value );
	}

	/**
	 * Fetch one row (null on failure).
	 *
	 * @param string           $sql  SQL template.
	 * @param array<int,mixed> $args Arguments.
	 * @return array<string,mixed>|null
	 */
	private function row( string $sql, array $args ): ?array {
		$wpdb = $this->wpdb;
		$row  = $this->engine->quiet(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Table names come from $wpdb, values are prepared.
			static fn() => $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A )
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Fetch rows (empty array on failure).
	 *
	 * @param string           $sql  SQL template.
	 * @param array<int,mixed> $args Arguments.
	 * @return array<int,array<string,mixed>>
	 */
	private function results( string $sql, array $args ): array {
		$wpdb = $this->wpdb;
		$rows = $this->engine->quiet(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Table names come from $wpdb, values are prepared.
			static fn() => $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A )
		);
		return is_array( $rows ) ? $rows : array();
	}
}
