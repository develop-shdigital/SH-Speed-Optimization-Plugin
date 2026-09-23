<?php
/**
 * Metrics store and Core Web Vitals summary.
 *
 * Sources, never mixed and always labelled:
 *  - rum:     anonymous real-user measurements (opt-in) — field data.
 *  - psi:     Google PageSpeed Insights / CrUX (optional API key) — field + lab data.
 *  - browser: measured in the administrator's browser during scans — lab data.
 *  - scan:    server-side measurements (TTFB, generation time).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Diagnostics;

use SH\SpeedOptimizer\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Metrics service.
 */
final class Metrics {

	/**
	 * Core Web Vitals thresholds: [ good upper bound, poor lower bound ].
	 */
	public const THRESHOLDS = array(
		'lcp'  => array( 2500, 4000 ),
		'inp'  => array( 200, 500 ),
		'cls'  => array( 0.1, 0.25 ),
		'ttfb' => array( 800, 1800 ),
		'fcp'  => array( 1800, 3000 ),
	);

	/**
	 * Accepted value ranges for incoming measurements.
	 */
	private const RANGES = array(
		'lcp'  => array( 0, 120000 ),
		'inp'  => array( 0, 60000 ),
		'cls'  => array( 0, 20 ),
		'ttfb' => array( 0, 120000 ),
		'fcp'  => array( 0, 120000 ),
	);

	public const RUM_MIN_SAMPLES = 20;
	public const RUM_DAYS        = 28;

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'shso_metrics';
	}

	/**
	 * Record a value.
	 *
	 * @param string $source  rum|psi|browser|scan.
	 * @param string $metric  Metric key.
	 * @param float  $value   Value.
	 * @param string $context Template key or path.
	 */
	public function record( string $source, string $metric, float $value, string $context = '' ): void {
		global $wpdb;

		if ( (int) get_option( 'shso_db_version', 0 ) < 1 || ! is_finite( $value ) ) {
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'recorded_at' => gmdate( 'Y-m-d H:i:s' ),
				'source'      => substr( sanitize_key( $source ), 0, 20 ),
				'metric'      => substr( sanitize_key( $metric ), 0, 40 ),
				'value'       => $value,
				'context'     => substr( sanitize_text_field( $context ), 0, 191 ),
			),
			array( '%s', '%s', '%s', '%f', '%s' )
		);
	}

	/**
	 * Delete old rows (daily cron).
	 */
	public function prune(): void {
		global $wpdb;
		if ( (int) get_option( 'shso_db_version', 0 ) < 1 ) {
			return;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE recorded_at < %s", gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );

		// Keep RUM bounded even on busy sites.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$threshold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source = 'rum' ORDER BY id DESC LIMIT 1 OFFSET %d", 50000 ) );
		if ( $threshold > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE source = 'rum' AND id <= %d", $threshold ) );
		}
	}

	/**
	 * 75th percentile of a metric from a source within a time window.
	 *
	 * @param string $source Source.
	 * @param string $metric Metric.
	 * @param int    $days   Window.
	 * @return array{value:float|null,samples:int}
	 */
	public function p75( string $source, string $metric, int $days ): array {
		global $wpdb;

		if ( (int) get_option( 'shso_db_version', 0 ) < 1 ) {
			return array(
				'value'   => null,
				'samples' => 0,
			);
		}

		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source = %s AND metric = %s AND recorded_at >= %s", $source, $metric, $since ) );
		if ( 0 === $count ) {
			return array(
				'value'   => null,
				'samples' => 0,
			);
		}

		$offset = (int) floor( 0.75 * ( $count - 1 ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$table} WHERE source = %s AND metric = %s AND recorded_at >= %s ORDER BY value ASC LIMIT 1 OFFSET %d", $source, $metric, $since, $offset ) );

		return array(
			'value'   => null === $value ? null : (float) $value,
			'samples' => $count,
		);
	}

	/**
	 * Latest value of a metric from a source.
	 *
	 * @param string $source Source.
	 * @param string $metric Metric.
	 * @param string $context Optional context filter.
	 * @return array{value:float|null,at:int|null}
	 */
	public function latest( string $source, string $metric, string $context = '' ): array {
		global $wpdb;

		if ( (int) get_option( 'shso_db_version', 0 ) < 1 ) {
			return array(
				'value' => null,
				'at'    => null,
			);
		}

		$table = self::table();
		if ( '' !== $context ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT value, recorded_at FROM {$table} WHERE source = %s AND metric = %s AND context = %s ORDER BY id DESC LIMIT 1", $source, $metric, $context ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT value, recorded_at FROM {$table} WHERE source = %s AND metric = %s ORDER BY id DESC LIMIT 1", $source, $metric ), ARRAY_A );
		}

		return array(
			'value' => $row ? (float) $row['value'] : null,
			'at'    => $row ? (int) strtotime( $row['recorded_at'] . ' UTC' ) : null,
		);
	}

	/**
	 * Core Web Vitals card data. Field data only from real users (RUM) or CrUX
	 * (PageSpeed Insights); otherwise explicitly "unavailable". Lab data from the
	 * administrator's browser is returned separately.
	 *
	 * @return array<string,mixed>
	 */
	public function core_web_vitals(): array {
		$metrics = array_keys( self::THRESHOLDS );
		$empty   = array();
		foreach ( $metrics as $metric ) {
			$empty[ $metric ] = self::format( $metric, null );
		}

		$out = array(
			'source'          => 'none',
			'source_label'    => __( 'Field data unavailable', 'sh-speed-optimizer' ),
			'field_available' => false,
			'metrics'         => $empty,
			'lab'             => null,
		);

		// 1. Real-user monitoring.
		if ( $this->plugin->settings()->get( 'rum' ) ) {
			$field   = array();
			$samples = 0;
			foreach ( $metrics as $metric ) {
				$p75              = $this->p75( 'rum', $metric, self::RUM_DAYS );
				$field[ $metric ] = self::format( $metric, $p75['samples'] >= self::RUM_MIN_SAMPLES ? $p75['value'] : null );
				$samples          = max( $samples, $p75['samples'] );
			}
			if ( $samples >= self::RUM_MIN_SAMPLES ) {
				$out['source']          = 'rum';
				$out['field_available'] = true;
				$out['metrics']         = $field;
				$out['source_label']    = sprintf(
					/* translators: 1: number of page views, 2: days */
					__( 'Real visitors (75th percentile of %1$s sampled page views, last %2$d days)', 'sh-speed-optimizer' ),
					number_format_i18n( $samples ),
					self::RUM_DAYS
				);
			}
		}

		// 2. PageSpeed Insights field data (CrUX).
		if ( ! $out['field_available'] ) {
			$psi = PageSpeed::latest();
			if ( is_array( $psi ) && ! empty( $psi['field'] ) ) {
				$field = array();
				foreach ( $metrics as $metric ) {
					$field[ $metric ] = self::format( $metric, isset( $psi['field'][ $metric ] ) ? (float) $psi['field'][ $metric ] : null );
				}
				$out['source']          = 'psi';
				$out['field_available'] = true;
				$out['metrics']         = $field;
				$out['source_label']    = sprintf(
					/* translators: %s: date */
					__( 'Chrome UX Report via PageSpeed Insights (real Chrome users, fetched %s)', 'sh-speed-optimizer' ),
					\SH\SpeedOptimizer\Rollback\SnapshotManager::human_time( (int) $psi['fetched_at'] )
				);
			}
		}

		// Lab data measured in the administrator's browser.
		$lab_lcp = $this->latest( 'browser', 'lcp', 'front_page' );
		$lab_cls = $this->latest( 'browser', 'cls', 'front_page' );
		$lab_fcp = $this->latest( 'browser', 'fcp', 'front_page' );
		$ttfb    = $this->latest( 'scan', 'ttfb', 'front_page' );
		if ( null !== $lab_lcp['value'] || null !== $ttfb['value'] ) {
			$out['lab'] = array(
				'source_label' => __( 'Lab data: measured in your browser and on your server during the last scan (not real visitors)', 'sh-speed-optimizer' ),
				'metrics'      => array(
					'lcp'  => self::format( 'lcp', $lab_lcp['value'] ),
					'inp'  => self::format( 'inp', null ),
					'cls'  => self::format( 'cls', $lab_cls['value'] ),
					'ttfb' => self::format( 'ttfb', $ttfb['value'] ),
					'fcp'  => self::format( 'fcp', $lab_fcp['value'] ),
				),
			);
		}

		return $out;
	}

	/**
	 * Format a metric value with status.
	 *
	 * @param string     $metric Metric.
	 * @param float|null $value  Value.
	 * @return array{value:float|null,display:string|null,status:string}
	 */
	public static function format( string $metric, ?float $value ): array {
		if ( null === $value ) {
			return array(
				'value'   => null,
				'display' => null,
				'status'  => 'unknown',
			);
		}

		if ( 'cls' === $metric ) {
			$display = number_format_i18n( $value, 2 );
		} elseif ( $value >= 1000 ) {
			/* translators: %s: seconds */
			$display = sprintf( __( '%s s', 'sh-speed-optimizer' ), number_format_i18n( $value / 1000, 1 ) );
		} else {
			/* translators: %d: milliseconds */
			$display = sprintf( __( '%d ms', 'sh-speed-optimizer' ), (int) round( $value ) );
		}

		list( $good, $poor ) = self::THRESHOLDS[ $metric ] ?? array( PHP_INT_MAX, PHP_INT_MAX );
		if ( $value <= $good ) {
			$status = 'good';
		} elseif ( $value <= $poor ) {
			$status = 'needs-improvement';
		} else {
			$status = 'poor';
		}

		return array(
			'value'   => 'cls' === $metric ? round( $value, 3 ) : round( $value ),
			'display' => $display,
			'status'  => $status,
		);
	}

	/**
	 * Accept a real-user beacon (public endpoint, validated and rate limited by the caller).
	 *
	 * @param array<string,mixed> $values   Metric => value.
	 * @param string              $template Template key.
	 * @return int Number of stored values.
	 */
	public function ingest_rum( array $values, string $template ): int {
		$stored   = 0;
		$template = substr( sanitize_key( $template ), 0, 60 );
		foreach ( self::RANGES as $metric => $range ) {
			if ( ! isset( $values[ $metric ] ) || ! is_numeric( $values[ $metric ] ) ) {
				continue;
			}
			$value = (float) $values[ $metric ];
			if ( $value < $range[0] || $value > $range[1] ) {
				continue;
			}
			$this->record( 'rum', $metric, $value, $template );
			++$stored;
		}
		return $stored;
	}
}
