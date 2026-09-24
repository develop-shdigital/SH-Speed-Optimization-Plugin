<?php
/**
 * Aggregates `$wpdb->queries` into a compact, value-free report.
 *
 * Pure: takes the query log array (as recorded by WordPress with
 * SAVEQUERIES) and returns counts, timings and the most notable query
 * shapes. Only normalized SQL is stored — never literal values.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\QueryOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Query report builder.
 */
final class QueryReport {

	/**
	 * Queries slower than this (ms) are reported as slow.
	 */
	public const SLOW_MS = 50.0;

	/**
	 * Normalized queries executed at least this often are reported as repeated.
	 */
	public const REPEAT_MIN = 5;

	public const MAX_SLOW     = 10;
	public const MAX_REPEATED = 10;
	public const MAX_META     = 5;

	/**
	 * Key under which the collector stores the component in the query's custom data.
	 */
	public const DATA_KEY = 'shso_component';

	/**
	 * Report for a page without query data.
	 *
	 * @return array<string,mixed>
	 */
	public static function unavailable(): array {
		return array(
			'available'      => false,
			'count'          => 0,
			'time_ms'        => 0.0,
			'slow'           => array(),
			'repeated'       => array(),
			'components'     => array(),
			'expensive_meta' => array(),
		);
	}

	/**
	 * Build the report.
	 *
	 * Each query entry: [ 0 => sql, 1 => seconds, 2 => callstack, 3 => start, 4 => custom data ].
	 *
	 * @param array<int,mixed> $queries Query log.
	 * @return array<string,mixed>
	 */
	public static function build( array $queries ): array {
		$count      = 0;
		$total      = 0.0;
		$groups     = array();
		$components = array();
		$slow       = array();
		$meta       = array();

		foreach ( $queries as $query ) {
			if ( ! is_array( $query ) || ! isset( $query[0] ) || ! is_string( $query[0] ) ) {
				continue;
			}

			$raw       = $query[0];
			$ms        = max( 0.0, (float) ( $query[1] ?? 0 ) * 1000 );
			$component = self::component_of( $query );
			$full      = QueryNormalizer::full( $raw );
			$key       = QueryNormalizer::key( $full );

			++$count;
			$total += $ms;

			if ( ! isset( $components[ $component ] ) ) {
				$components[ $component ] = array(
					'component' => $component,
					'count'     => 0,
					'ms'        => 0.0,
				);
			}
			++$components[ $component ]['count'];
			$components[ $component ]['ms'] += $ms;

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'sql'        => QueryNormalizer::truncate( $full ),
					'count'      => 0,
					'ms'         => 0.0,
					'components' => array(),
				);
			}
			++$groups[ $key ]['count'];
			$groups[ $key ]['ms']                      += $ms;
			$groups[ $key ]['components'][ $component ] = ( $groups[ $key ]['components'][ $component ] ?? 0 ) + 1;

			if ( $ms > self::SLOW_MS && ( ! isset( $slow[ $key ] ) || $slow[ $key ]['ms'] < $ms ) ) {
				$slow[ $key ] = array(
					'sql'       => $groups[ $key ]['sql'],
					'ms'        => round( $ms, 2 ),
					'component' => $component,
				);
			}

			$reason = self::meta_reason( $raw );
			if ( null !== $reason && ( ! isset( $meta[ $key ] ) || $meta[ $key ]['ms'] < $ms ) ) {
				$meta[ $key ] = array(
					'sql'       => $groups[ $key ]['sql'],
					'ms'        => round( $ms, 2 ),
					'component' => $component,
					'reason'    => $reason,
				);
			}
		}

		if ( 0 === $count ) {
			return self::unavailable();
		}

		$by_ms = static fn( array $a, array $b ): int => $b['ms'] <=> $a['ms'];

		$slow = array_values( $slow );
		usort( $slow, $by_ms );

		$meta = array_values( $meta );
		usort( $meta, $by_ms );

		$repeated = array();
		foreach ( $groups as $group ) {
			if ( $group['count'] < self::REPEAT_MIN ) {
				continue;
			}
			arsort( $group['components'] );
			$repeated[] = array(
				'sql'       => $group['sql'],
				'count'     => $group['count'],
				'ms'        => round( $group['ms'], 2 ),
				'component' => (string) array_key_first( $group['components'] ),
			);
		}
		usort(
			$repeated,
			static function ( array $a, array $b ): int {
				$order = $b['count'] <=> $a['count'];
				return 0 !== $order ? $order : $b['ms'] <=> $a['ms'];
			}
		);

		$components = array_values( $components );
		foreach ( $components as &$entry ) {
			$entry['ms'] = round( $entry['ms'], 2 );
		}
		unset( $entry );
		usort(
			$components,
			static function ( array $a, array $b ): int {
				$order = $b['ms'] <=> $a['ms'];
				return 0 !== $order ? $order : $b['count'] <=> $a['count'];
			}
		);

		return array(
			'available'      => true,
			'count'          => $count,
			'time_ms'        => round( $total, 1 ),
			'slow'           => array_slice( $slow, 0, self::MAX_SLOW ),
			'repeated'       => array_slice( $repeated, 0, self::MAX_REPEATED ),
			'components'     => $components,
			'expensive_meta' => array_slice( $meta, 0, self::MAX_META ),
		);
	}

	/**
	 * Why a query is an expensive custom field query (null when it is not).
	 *
	 * Checked on the raw SQL; only the reason code is kept.
	 *
	 * @param string $sql Raw SQL.
	 */
	public static function meta_reason( string $sql ): ?string {
		$sql = substr( $sql, 0, QueryNormalizer::MAX_INPUT );
		if ( preg_match_all( '/\bJOIN\s+`?\w*postmeta`?/i', $sql ) >= 2 ) {
			return 'multiple_meta_joins';
		}
		if ( preg_match( '/\bmeta_value`?\s+(?:NOT\s+)?LIKE\s+([\'"])%/i', $sql ) ) {
			return 'meta_value_like';
		}
		return null;
	}

	/**
	 * Component recorded for a query ("unknown" when none was attached).
	 *
	 * @param array<int,mixed> $query Query entry.
	 */
	private static function component_of( array $query ): string {
		$data = $query[4] ?? null;
		if ( is_array( $data ) && is_string( $data[ self::DATA_KEY ] ?? null ) && preg_match( '/^(?:core|unknown|(?:plugin|mu-plugin|theme):[A-Za-z0-9._\-]{1,100})\z/', $data[ self::DATA_KEY ] ) ) {
			return $data[ self::DATA_KEY ];
		}
		return 'unknown';
	}
}
