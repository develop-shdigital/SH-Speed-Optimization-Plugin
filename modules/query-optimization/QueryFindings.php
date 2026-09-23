<?php
/**
 * Plain-language findings from query reports of several pages.
 *
 * Pure: takes reports ({@see QueryReport::build()}) keyed by page and a map
 * of component => display name. Report only — nothing is ever changed.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\QueryOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Query findings.
 */
final class QueryFindings {

	/**
	 * Per component and page: warning above these.
	 */
	public const COMPONENT_WARNING_MS      = 300.0;
	public const COMPONENT_WARNING_QUERIES = 100;

	/**
	 * Per component and page: notice above these.
	 */
	public const COMPONENT_NOTICE_MS      = 100.0;
	public const COMPONENT_NOTICE_QUERIES = 50;

	/**
	 * Per page totals.
	 */
	public const PAGE_WARNING_MS      = 1000.0;
	public const PAGE_WARNING_QUERIES = 500;
	public const PAGE_NOTICE_MS       = 500.0;
	public const PAGE_NOTICE_QUERIES  = 200;

	/**
	 * Repeated query thresholds: info, notice, warning.
	 */
	public const REPEAT_INFO    = 10;
	public const REPEAT_NOTICE  = 25;
	public const REPEAT_WARNING = 100;

	/**
	 * Slow query thresholds.
	 */
	public const SLOW_WARNING_COUNT = 5;
	public const SLOW_WARNING_MS    = 500.0;

	/**
	 * Build findings.
	 *
	 * @param array<string|int,array<string,mixed>> $reports Page key => report (a report may carry a "label").
	 * @param array<string,string>                  $names   Component ("plugin:slug") or plugin slug => display name.
	 * @return array<int,array<string,mixed>>
	 */
	public static function build( array $reports, array $names = array() ): array {
		$pages = array();
		foreach ( $reports as $key => $report ) {
			if ( is_array( $report ) && ! empty( $report['available'] ) && (int) ( $report['count'] ?? 0 ) > 0 ) {
				$pages[] = array(
					'label'  => self::page_label( $key, $report ),
					'report' => $report,
				);
			}
		}

		if ( empty( $pages ) ) {
			if ( empty( $reports ) ) {
				return array();
			}
			return array(
				self::finding(
					'db_query_analysis_unavailable',
					'database',
					'info',
					__( 'Database query details are not available.', 'sh-speed-optimizer' ),
					__( 'The server did not record database queries during the analysis, for example because query logging is switched off in wp-config.php (SAVEQUERIES).', 'sh-speed-optimizer' ),
					'',
					array()
				),
			);
		}

		$findings = array_merge(
			self::component_findings( $pages, $names ),
			array_filter(
				array(
					self::load_finding( $pages ),
					self::slow_finding( $pages, $names ),
					self::repeated_finding( $pages, $names ),
					self::meta_finding( $pages, $names ),
				)
			)
		);

		return array_values( $findings );
	}

	/**
	 * Display name of a component.
	 *
	 * @param string               $component Component.
	 * @param array<string,string> $names     Names.
	 */
	public static function component_name( string $component, array $names = array() ): string {
		if ( ! empty( $names[ $component ] ) ) {
			$name = (string) $names[ $component ];
			return 0 === strpos( $component, 'theme:' )
				/* translators: %s: theme name */
				? sprintf( __( 'The %s theme', 'sh-speed-optimizer' ), $name )
				: $name;
		}

		$parts = explode( ':', $component, 2 );
		$type  = $parts[0];
		$slug  = $parts[1] ?? '';

		switch ( $type ) {
			case 'plugin':
			case 'mu-plugin':
				return ! empty( $names[ $slug ] ) ? (string) $names[ $slug ] : self::humanize( $slug );
			case 'theme':
				/* translators: %s: theme name */
				return sprintf( __( 'The %s theme', 'sh-speed-optimizer' ), ! empty( $names[ $slug ] ) ? (string) $names[ $slug ] : self::humanize( $slug ) );
			case 'core':
				return __( 'WordPress itself', 'sh-speed-optimizer' );
		}
		return __( 'Unidentified code', 'sh-speed-optimizer' );
	}

	/**
	 * Plain-language label of a page key.
	 *
	 * @param string|int          $key    Page key (template key, URL or label).
	 * @param array<string,mixed> $report Report.
	 */
	public static function page_label( $key, array $report = array() ): string {
		if ( ! empty( $report['label'] ) && is_string( $report['label'] ) ) {
			return $report['label'];
		}
		$key   = (string) $key;
		$known = array(
			'front_page'      => __( 'your homepage', 'sh-speed-optimizer' ),
			'home'            => __( 'your blog page', 'sh-speed-optimizer' ),
			'page'            => __( 'a page', 'sh-speed-optimizer' ),
			'single-post'     => __( 'a blog post', 'sh-speed-optimizer' ),
			'single-product'  => __( 'a product page', 'sh-speed-optimizer' ),
			'archive-product' => __( 'your shop page', 'sh-speed-optimizer' ),
			'category'        => __( 'a category page', 'sh-speed-optimizer' ),
			'tag'             => __( 'a tag page', 'sh-speed-optimizer' ),
			'search'          => __( 'the search results page', 'sh-speed-optimizer' ),
			'404'             => __( 'the "page not found" page', 'sh-speed-optimizer' ),
			'archive'         => __( 'an archive page', 'sh-speed-optimizer' ),
		);
		if ( isset( $known[ $key ] ) ) {
			return $known[ $key ];
		}
		if ( preg_match( '#^https?://#i', $key ) ) {
			$path = (string) wp_parse_url( $key, PHP_URL_PATH );
			/* translators: %s: URL path such as /shop/ */
			return ( '' === $path || '/' === $path ) ? $known['front_page'] : sprintf( __( 'the page %s', 'sh-speed-optimizer' ), $path );
		}
		if ( 0 === strpos( $key, 'single-' ) ) {
			/* translators: %s: content type such as "event" */
			return sprintf( __( 'a %s page', 'sh-speed-optimizer' ), str_replace( array( '-', '_' ), ' ', substr( $key, 7 ) ) );
		}
		if ( 0 === strpos( $key, 'archive-' ) || 0 === strpos( $key, 'tax-' ) ) {
			/* translators: %s: content type such as "event" */
			return sprintf( __( 'the %s archive', 'sh-speed-optimizer' ), str_replace( array( '-', '_' ), ' ', substr( $key, strpos( $key, '-' ) + 1 ) ) );
		}
		return $known['page'];
	}

	/**
	 * "240 ms" or "1.2 s".
	 *
	 * @param float $ms Milliseconds.
	 */
	public static function format_ms( float $ms ): string {
		if ( $ms >= 500 ) {
			$seconds = round( $ms / 1000, 1 );
			/* translators: %s: seconds */
			return sprintf( __( '%s s', 'sh-speed-optimizer' ), function_exists( 'number_format_i18n' ) ? number_format_i18n( $seconds, 1 ) : number_format( $seconds, 1 ) );
		}
		/* translators: %s: milliseconds */
		return sprintf( __( '%s ms', 'sh-speed-optimizer' ), function_exists( 'number_format_i18n' ) ? number_format_i18n( round( $ms ) ) : number_format( round( $ms ) ) );
	}

	/**
	 * One finding per plugin/theme that is heavy on at least one page.
	 *
	 * @param array<int,array{label:string,report:array<string,mixed>}> $pages Pages.
	 * @param array<string,string>                                      $names Names.
	 * @return array<int,array<string,mixed>>
	 */
	private static function component_findings( array $pages, array $names ): array {
		$by_component = array();
		foreach ( $pages as $page ) {
			foreach ( (array) ( $page['report']['components'] ?? array() ) as $entry ) {
				$component = (string) ( $entry['component'] ?? '' );
				if ( ! preg_match( '/^(?:plugin|mu-plugin|theme):/', $component ) ) {
					continue; // Core and unidentified code are not actionable.
				}
				$by_component[ $component ][] = array(
					'page'  => $page['label'],
					'count' => (int) ( $entry['count'] ?? 0 ),
					'ms'    => (float) ( $entry['ms'] ?? 0 ),
				);
			}
		}

		$findings = array();
		foreach ( $by_component as $component => $entries ) {
			usort(
				$entries,
				static function ( array $a, array $b ): int {
					$order = $b['ms'] <=> $a['ms'];
					return 0 !== $order ? $order : $b['count'] <=> $a['count'];
				}
			);
			$severity = null;
			foreach ( $entries as $entry ) {
				$level = self::component_severity( $entry['count'], $entry['ms'] );
				if ( 'warning' === $level ) {
					$severity = 'warning';
					break;
				}
				if ( 'notice' === $level ) {
					$severity = 'notice';
				}
			}
			if ( null === $severity ) {
				continue;
			}

			$worst  = $entries[0];
			$name   = self::component_name( $component, $names );
			$theme  = 0 === strpos( $component, 'theme:' );
			$others = count( $entries ) - 1;

			$description = __( 'Database queries are requests for stored data while a page is being built. Many or slow queries delay every page view that is not served from the cache.', 'sh-speed-optimizer' );
			if ( $others > 0 ) {
				$description .= ' ' . sprintf(
					/* translators: %d: number of other pages */
					_n( 'It also ran queries on %d other page that was checked.', 'It also ran queries on %d other pages that were checked.', $others, 'sh-speed-optimizer' ),
					$others
				);
			}

			$findings[] = self::finding(
				'queries_' . preg_replace( '/[^a-z0-9_]+/', '_', strtolower( $component ) ),
				'plugins',
				$severity,
				sprintf(
					/* translators: 1: plugin or theme name, 2: number of queries, 3: page such as "your homepage", 4: time such as "0.9 s" */
					_n( '%1$s ran %2$s database query on %3$s (%4$s).', '%1$s ran %2$s database queries on %3$s (%4$s).', $worst['count'], 'sh-speed-optimizer' ),
					$name,
					self::number( $worst['count'] ),
					$worst['page'],
					self::format_ms( $worst['ms'] )
				),
				$description,
				$theme
					? __( 'Page caching hides this for most visitors. Check the theme settings for features you do not use, or ask the theme developer about the load it causes. SH Speed Optimizer never changes theme code.', 'sh-speed-optimizer' )
					: __( 'Page caching hides this for most visitors. Check the plugin settings for features you do not use, or ask the plugin developer about the load it causes. SH Speed Optimizer never changes plugin code.', 'sh-speed-optimizer' ),
				array(
					'component' => $component,
					'name'      => $name,
					'worst'     => $worst,
					'pages'     => $entries,
				)
			);
		}

		usort(
			$findings,
			static function ( array $a, array $b ): int {
				$order = ( 'warning' === $b['severity'] ) <=> ( 'warning' === $a['severity'] );
				return 0 !== $order ? $order : $b['data']['worst']['ms'] <=> $a['data']['worst']['ms'];
			}
		);

		return $findings;
	}

	/**
	 * Severity of one component on one page (null = fine).
	 *
	 * @param int   $count Queries.
	 * @param float $ms    Milliseconds.
	 */
	public static function component_severity( int $count, float $ms ): ?string {
		if ( $ms > self::COMPONENT_WARNING_MS || $count > self::COMPONENT_WARNING_QUERIES ) {
			return 'warning';
		}
		if ( $ms > self::COMPONENT_NOTICE_MS || $count > self::COMPONENT_NOTICE_QUERIES ) {
			return 'notice';
		}
		return null;
	}

	/**
	 * Overall query load (worst page), or a "good" finding.
	 *
	 * @param array<int,array{label:string,report:array<string,mixed>}> $pages Pages.
	 * @return array<string,mixed>
	 */
	private static function load_finding( array $pages ): array {
		$worst = null;
		$total = 0;
		$time  = 0.0;
		foreach ( $pages as $page ) {
			$count  = (int) $page['report']['count'];
			$ms     = (float) ( $page['report']['time_ms'] ?? 0 );
			$total += $count;
			$time  += $ms;
			if ( null === $worst || $ms > $worst['ms'] || ( $ms === $worst['ms'] && $count > $worst['count'] ) ) {
				$worst = array(
					'page'  => $page['label'],
					'count' => $count,
					'ms'    => $ms,
				);
			}
		}

		$severity = 'good';
		if ( $worst['ms'] > self::PAGE_WARNING_MS || $worst['count'] > self::PAGE_WARNING_QUERIES ) {
			$severity = 'warning';
		} elseif ( $worst['ms'] > self::PAGE_NOTICE_MS || $worst['count'] > self::PAGE_NOTICE_QUERIES ) {
			$severity = 'notice';
		}

		$average_count = (int) round( $total / count( $pages ) );
		$average_ms    = $time / count( $pages );
		$data          = array(
			'pages'         => count( $pages ),
			'average_count' => $average_count,
			'average_ms'    => round( $average_ms, 1 ),
			'worst'         => $worst,
		);

		if ( 'good' === $severity ) {
			return self::finding(
				'db_query_load',
				'database',
				'good',
				__( 'Database queries are fast on the pages that were checked.', 'sh-speed-optimizer' ),
				sprintf(
					/* translators: 1: number of queries, 2: time */
					_n( 'On average a page needs %1$s database query taking %2$s in total.', 'On average a page needs %1$s database queries taking %2$s in total.', $average_count, 'sh-speed-optimizer' ),
					self::number( $average_count ),
					self::format_ms( $average_ms )
				),
				'',
				$data
			);
		}

		return self::finding(
			'db_query_load',
			'database',
			$severity,
			sprintf(
				/* translators: 1: page such as "your homepage", 2: number of queries, 3: time */
				_n( 'Building %1$s takes %2$s database query (%3$s).', 'Building %1$s takes %2$s database queries (%3$s).', $worst['count'], 'sh-speed-optimizer' ),
				$worst['page'],
				self::number( $worst['count'] ),
				self::format_ms( $worst['ms'] )
			),
			__( 'Every page view that is not served from the cache waits for these queries before the browser receives anything.', 'sh-speed-optimizer' ),
			__( 'Keep page caching enabled. The findings about individual plugins show where most of the queries come from.', 'sh-speed-optimizer' ),
			$data
		);
	}

	/**
	 * Slow queries across pages.
	 *
	 * @param array<int,array{label:string,report:array<string,mixed>}> $pages Pages.
	 * @param array<string,string>                                      $names Names.
	 * @return array<string,mixed>|null
	 */
	private static function slow_finding( array $pages, array $names ): ?array {
		$slow = array();
		foreach ( $pages as $page ) {
			foreach ( (array) ( $page['report']['slow'] ?? array() ) as $query ) {
				$sql = (string) ( $query['sql'] ?? '' );
				$ms  = (float) ( $query['ms'] ?? 0 );
				if ( ! isset( $slow[ $sql ] ) || $slow[ $sql ]['ms'] < $ms ) {
					$slow[ $sql ] = array(
						'sql'       => $sql,
						'ms'        => $ms,
						'component' => (string) ( $query['component'] ?? 'unknown' ),
						'name'      => self::component_name( (string) ( $query['component'] ?? 'unknown' ), $names ),
						'page'      => $page['label'],
					);
				}
			}
		}
		if ( empty( $slow ) ) {
			return null;
		}

		$slow = array_values( $slow );
		usort( $slow, static fn( array $a, array $b ): int => $b['ms'] <=> $a['ms'] );
		$count   = count( $slow );
		$slowest = $slow[0];

		return self::finding(
			'db_slow_queries',
			'database',
			( $count >= self::SLOW_WARNING_COUNT || $slowest['ms'] >= self::SLOW_WARNING_MS ) ? 'warning' : 'notice',
			sprintf(
				/* translators: %s: number of queries */
				_n( '%s slow database query was found.', '%s slow database queries were found.', $count, 'sh-speed-optimizer' ),
				self::number( $count )
			),
			sprintf(
				/* translators: 1: time, 2: plugin/theme name, 3: page */
				__( 'Queries that take longer than 50 ms slow down building the page. The slowest took %1$s and came from %2$s on %3$s.', 'sh-speed-optimizer' ),
				self::format_ms( $slowest['ms'] ),
				$slowest['name'],
				$slowest['page']
			),
			__( 'Page caching avoids these queries for most visitors. If the same plugin appears repeatedly, check its settings or contact its developer.', 'sh-speed-optimizer' ),
			array( 'queries' => array_slice( $slow, 0, 10 ) )
		);
	}

	/**
	 * Most repeated query across pages.
	 *
	 * @param array<int,array{label:string,report:array<string,mixed>}> $pages Pages.
	 * @param array<string,string>                                      $names Names.
	 * @return array<string,mixed>|null
	 */
	private static function repeated_finding( array $pages, array $names ): ?array {
		$top = null;
		$all = array();
		foreach ( $pages as $page ) {
			foreach ( (array) ( $page['report']['repeated'] ?? array() ) as $query ) {
				$entry = array(
					'sql'       => (string) ( $query['sql'] ?? '' ),
					'count'     => (int) ( $query['count'] ?? 0 ),
					'ms'        => (float) ( $query['ms'] ?? 0 ),
					'component' => (string) ( $query['component'] ?? 'unknown' ),
					'page'      => $page['label'],
				);
				$all[] = $entry;
				if ( null === $top || $entry['count'] > $top['count'] ) {
					$top = $entry;
				}
			}
		}
		if ( null === $top || $top['count'] < self::REPEAT_INFO ) {
			return null;
		}

		$severity = 'info';
		if ( $top['count'] >= self::REPEAT_WARNING ) {
			$severity = 'warning';
		} elseif ( $top['count'] >= self::REPEAT_NOTICE ) {
			$severity = 'notice';
		}
		$name = self::component_name( $top['component'], $names );

		usort( $all, static fn( array $a, array $b ): int => $b['count'] <=> $a['count'] );

		return self::finding(
			'db_repeated_queries',
			'database',
			$severity,
			sprintf(
				/* translators: 1: number of times, 2: page */
				__( 'The same database query ran %1$s times on %2$s.', 'sh-speed-optimizer' ),
				self::number( $top['count'] ),
				$top['page']
			),
			sprintf(
				/* translators: %s: plugin or theme name */
				__( 'It came from %s. Running the same query again and again usually means a result is not reused, which wastes time on every uncached page view.', 'sh-speed-optimizer' ),
				$name
			),
			sprintf(
				/* translators: %s: plugin or theme name */
				__( 'Page caching hides this for most visitors. If it keeps happening, let the developer of %s know.', 'sh-speed-optimizer' ),
				$name
			),
			array(
				'top'     => $top + array( 'name' => $name ),
				'queries' => array_slice( $all, 0, 10 ),
			)
		);
	}

	/**
	 * Expensive custom field queries.
	 *
	 * @param array<int,array{label:string,report:array<string,mixed>}> $pages Pages.
	 * @param array<string,string>                                      $names Names.
	 * @return array<string,mixed>|null
	 */
	private static function meta_finding( array $pages, array $names ): ?array {
		$queries = array();
		$sources = array();
		foreach ( $pages as $page ) {
			foreach ( (array) ( $page['report']['expensive_meta'] ?? array() ) as $query ) {
				$sql = (string) ( $query['sql'] ?? '' );
				if ( isset( $queries[ $sql ] ) ) {
					continue;
				}
				$component       = (string) ( $query['component'] ?? 'unknown' );
				$name            = self::component_name( $component, $names );
				$sources[]       = $name;
				$queries[ $sql ] = array(
					'sql'       => $sql,
					'ms'        => (float) ( $query['ms'] ?? 0 ),
					'component' => $component,
					'name'      => $name,
					'reason'    => (string) ( $query['reason'] ?? '' ),
					'page'      => $page['label'],
				);
			}
		}
		if ( empty( $queries ) ) {
			return null;
		}

		$count = count( $queries );
		return self::finding(
			'db_expensive_meta_queries',
			'database',
			'notice',
			sprintf(
				/* translators: %s: number of queries */
				_n( '%s database query searches custom fields in a slow way.', '%s database queries search custom fields in a slow way.', $count, 'sh-speed-optimizer' ),
				self::number( $count )
			),
			sprintf(
				/* translators: %s: list of plugin/theme names */
				__( 'Searching inside custom field values, or combining several custom fields in one query, cannot use the database indexes and gets slower as your site grows. Found in queries from: %s.', 'sh-speed-optimizer' ),
				implode( ', ', array_unique( $sources ) )
			),
			__( 'If this comes from a filter or search feature, check whether the plugin offers an indexed or cached search option.', 'sh-speed-optimizer' ),
			array( 'queries' => array_values( $queries ) )
		);
	}

	/**
	 * Localized integer.
	 *
	 * @param int $number Number.
	 */
	private static function number( int $number ): string {
		return function_exists( 'number_format_i18n' ) ? (string) number_format_i18n( $number ) : number_format( $number );
	}

	/**
	 * "woo-commerce_x" → "Woo Commerce X".
	 *
	 * @param string $slug Slug.
	 */
	private static function humanize( string $slug ): string {
		$slug = trim( str_replace( array( '-', '_', '.' ), ' ', $slug ) );
		return '' === $slug ? __( 'Unidentified code', 'sh-speed-optimizer' ) : ucwords( $slug );
	}

	/**
	 * Finding array.
	 *
	 * @param string              $id             Id.
	 * @param string              $category       database|plugins.
	 * @param string              $severity       Severity.
	 * @param string              $title          Title.
	 * @param string              $description    Description.
	 * @param string              $recommendation Recommendation.
	 * @param array<string,mixed> $data           Data.
	 * @return array<string,mixed>
	 */
	private static function finding( string $id, string $category, string $severity, string $title, string $description, string $recommendation, array $data ): array {
		return array(
			'id'             => $id,
			'category'       => $category,
			'severity'       => $severity,
			'title'          => $title,
			'description'    => $description,
			'recommendation' => $recommendation,
			'optimization'   => null,
			'data'           => $data,
			'source'         => 'scan',
		);
	}
}
