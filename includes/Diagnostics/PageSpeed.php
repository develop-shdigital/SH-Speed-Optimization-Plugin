<?php
/**
 * Optional Google PageSpeed Insights integration.
 *
 * Only used when the administrator configured an API key. Results are stored
 * with their fetch date and strategy, and always labelled with their source.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * PageSpeed Insights client.
 */
final class PageSpeed {

	public const OPTION   = 'shso_psi_history';
	public const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	/**
	 * Run a test.
	 *
	 * @param string $url      Public URL of this site.
	 * @param string $strategy mobile|desktop.
	 * @param string $key      API key.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function run( string $url, string $strategy, string $key ) {
		if ( '' === $key ) {
			return new \WP_Error( 'shso_psi_key', __( 'No PageSpeed Insights API key is configured.', 'sh-speed-optimizer' ) );
		}
		if ( ! Loopback::is_own_url( $url ) ) {
			return new \WP_Error( 'shso_psi_url', __( 'Only pages of this site can be tested.', 'sh-speed-optimizer' ) );
		}

		$strategy = 'desktop' === $strategy ? 'desktop' : 'mobile';
		$request  = self::ENDPOINT . '?' . http_build_query(
			array(
				'url'      => $url,
				'strategy' => $strategy,
				'key'      => $key,
			)
		) . '&category=performance&category=accessibility&category=best-practices&category=seo';

		$response = wp_remote_get(
			$request,
			array(
				'timeout' => 90,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'shso_psi_http', __( 'PageSpeed Insights could not be reached.', 'sh-speed-optimizer' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) ) {
			$message = is_array( $body ) && ! empty( $body['error']['message'] ) ? (string) $body['error']['message'] : '';
			return new \WP_Error(
				'shso_psi_error',
				/* translators: %s: error message from Google */
				'' !== $message ? sprintf( __( 'PageSpeed Insights returned an error: %s', 'sh-speed-optimizer' ), wp_strip_all_tags( $message ) ) : __( 'PageSpeed Insights returned an error.', 'sh-speed-optimizer' )
			);
		}

		$result = self::parse( $body, $strategy, $url );
		self::store( $result );
		return $result;
	}

	/**
	 * Parse an API response.
	 *
	 * @param array<string,mixed> $body     Response.
	 * @param string              $strategy Strategy.
	 * @param string              $url      URL.
	 * @return array<string,mixed>
	 */
	public static function parse( array $body, string $strategy, string $url ): array {
		$categories = array();
		foreach ( array( 'performance', 'accessibility', 'best-practices', 'seo' ) as $category ) {
			$score                   = $body['lighthouseResult']['categories'][ $category ]['score'] ?? null;
			$categories[ $category ] = null === $score ? null : (int) round( 100 * (float) $score );
		}

		$experience = $body['loadingExperience'] ?? array();
		$scope      = 'page';
		if ( empty( $experience['metrics'] ) && ! empty( $body['originLoadingExperience']['metrics'] ) ) {
			$experience = $body['originLoadingExperience'];
			$scope      = 'origin';
		}

		$field = null;
		if ( ! empty( $experience['metrics'] ) ) {
			$m     = $experience['metrics'];
			$field = array(
				'lcp'  => isset( $m['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] ) ? (float) $m['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] : null,
				'inp'  => isset( $m['INTERACTION_TO_NEXT_PAINT']['percentile'] ) ? (float) $m['INTERACTION_TO_NEXT_PAINT']['percentile'] : null,
				'cls'  => isset( $m['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] ) ? (float) $m['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] / 100 : null,
				'ttfb' => isset( $m['EXPERIMENTAL_TIME_TO_FIRST_BYTE']['percentile'] ) ? (float) $m['EXPERIMENTAL_TIME_TO_FIRST_BYTE']['percentile'] : null,
				'fcp'  => isset( $m['FIRST_CONTENTFUL_PAINT_MS']['percentile'] ) ? (float) $m['FIRST_CONTENTFUL_PAINT_MS']['percentile'] : null,
			);
		}

		$audits = $body['lighthouseResult']['audits'] ?? array();
		$lab    = array(
			'lcp'  => isset( $audits['largest-contentful-paint']['numericValue'] ) ? (float) $audits['largest-contentful-paint']['numericValue'] : null,
			'cls'  => isset( $audits['cumulative-layout-shift']['numericValue'] ) ? (float) $audits['cumulative-layout-shift']['numericValue'] : null,
			'fcp'  => isset( $audits['first-contentful-paint']['numericValue'] ) ? (float) $audits['first-contentful-paint']['numericValue'] : null,
			'ttfb' => isset( $audits['server-response-time']['numericValue'] ) ? (float) $audits['server-response-time']['numericValue'] : null,
			'tbt'  => isset( $audits['total-blocking-time']['numericValue'] ) ? (float) $audits['total-blocking-time']['numericValue'] : null,
		);

		return array(
			'url'         => $url,
			'strategy'    => $strategy,
			'fetched_at'  => time(),
			'categories'  => $categories,
			'field'       => $field,
			'field_scope' => $scope,
			'lab'         => $lab,
		);
	}

	/**
	 * Store a result (history of 20).
	 *
	 * @param array<string,mixed> $result Result.
	 */
	private static function store( array $result ): void {
		$history   = self::history();
		$history[] = $result;
		update_option( self::OPTION, array_slice( $history, -20 ), false );
	}

	/**
	 * History (oldest first).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function history(): array {
		$history = get_option( self::OPTION, array() );
		return is_array( $history ) ? array_values( $history ) : array();
	}

	/**
	 * Latest result.
	 *
	 * @param string|null $strategy Filter by strategy.
	 * @return array<string,mixed>|null
	 */
	public static function latest( ?string $strategy = null ): ?array {
		foreach ( array_reverse( self::history() ) as $result ) {
			if ( null === $strategy || $strategy === ( $result['strategy'] ?? '' ) ) {
				return $result;
			}
		}
		return null;
	}

	/**
	 * Before/after comparison around the last optimization run: the latest
	 * measurement before it and the latest after it (same strategy).
	 *
	 * @param int $optimized_at Timestamp of the last optimization run.
	 * @return array<int,array<string,mixed>>|null
	 */
	public static function before_after( int $optimized_at ): ?array {
		if ( $optimized_at <= 0 ) {
			return null;
		}

		$latest = self::latest();
		if ( null === $latest || (int) $latest['fetched_at'] < $optimized_at ) {
			return null;
		}

		$before = null;
		foreach ( array_reverse( self::history() ) as $result ) {
			if ( (int) $result['fetched_at'] < $optimized_at && $result['strategy'] === $latest['strategy'] && $result['url'] === $latest['url'] ) {
				$before = $result;
				break;
			}
		}
		if ( null === $before ) {
			return null;
		}

		$rows   = array();
		$labels = array(
			'lcp'  => __( 'Largest Contentful Paint (lab)', 'sh-speed-optimizer' ),
			'cls'  => __( 'Cumulative Layout Shift (lab)', 'sh-speed-optimizer' ),
			'fcp'  => __( 'First Contentful Paint (lab)', 'sh-speed-optimizer' ),
			'tbt'  => __( 'Total Blocking Time (lab)', 'sh-speed-optimizer' ),
			'ttfb' => __( 'Server response time (lab)', 'sh-speed-optimizer' ),
		);
		$source = sprintf(
			/* translators: %s: mobile or desktop */
			__( 'Google PageSpeed Insights (Lighthouse, %s)', 'sh-speed-optimizer' ),
			'desktop' === $latest['strategy'] ? __( 'desktop', 'sh-speed-optimizer' ) : __( 'mobile', 'sh-speed-optimizer' )
		);

		$rows[] = array(
			'metric'        => 'performance',
			'label'         => __( 'PageSpeed performance score', 'sh-speed-optimizer' ),
			'before'        => $before['categories']['performance'] ?? null,
			'after'         => $latest['categories']['performance'] ?? null,
			'before_source' => $source,
			'after_source'  => $source,
			'before_date'   => \SH\SpeedOptimizer\Rollback\SnapshotManager::human_time( (int) $before['fetched_at'] ),
			'after_date'    => \SH\SpeedOptimizer\Rollback\SnapshotManager::human_time( (int) $latest['fetched_at'] ),
		);

		foreach ( $labels as $metric => $label ) {
			$b = $before['lab'][ $metric ] ?? null;
			$a = $latest['lab'][ $metric ] ?? null;
			if ( null === $b && null === $a ) {
				continue;
			}
			$format = static function ( $value ) use ( $metric ) {
				if ( null === $value ) {
					return null;
				}
				return 'cls' === $metric || 'tbt' === $metric ? ( 'cls' === $metric ? number_format_i18n( (float) $value, 2 ) : Metrics::format( 'inp', (float) $value )['display'] ) : Metrics::format( $metric, (float) $value )['display'];
			};
			$rows[]  = array(
				'metric'        => $metric,
				'label'         => $label,
				'before'        => $format( $b ),
				'after'         => $format( $a ),
				'before_source' => $source,
				'after_source'  => $source,
				'before_date'   => \SH\SpeedOptimizer\Rollback\SnapshotManager::human_time( (int) $before['fetched_at'] ),
				'after_date'    => \SH\SpeedOptimizer\Rollback\SnapshotManager::human_time( (int) $latest['fetched_at'] ),
			);
		}

		return $rows;
	}
}
