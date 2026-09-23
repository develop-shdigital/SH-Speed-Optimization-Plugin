<?php
/**
 * Opt-in anonymous real-user Core Web Vitals sampling.
 *
 * A tiny inline script measures LCP, INP, CLS, FCP and TTFB on a random sample
 * of page views and sends them with sendBeacon. No cookies, no identifiers, no
 * IP addresses are stored (a salted, hourly-rotating hash is used only in a
 * short-lived transient for rate limiting).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Diagnostics;

use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Core\Context;
use SH\SpeedOptimizer\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Real-user monitoring.
 */
final class Rum {

	public const PER_IP_HOURLY = 30;
	public const DAILY_CAP     = 20000;

	/**
	 * Register the frontend script (only when the setting is on).
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function register( Plugin $plugin ): void {
		if ( ! $plugin->settings()->get( 'rum' ) || Context::is_emergency_safe_mode() || $plugin->context()->is_verification() ) {
			return;
		}

		$plugin->runtime()->html()->add(
			'rum',
			static function ( HtmlDocument $doc ) {
				$file = SHSO_DIR . 'assets/js/rum.js';
				if ( ! is_readable( $file ) ) {
					return;
				}
				/**
				 * Filters the share of page views that send Core Web Vitals (0–1).
				 *
				 * @param float $rate Default 0.1 (10%).
				 */
				$rate   = max( 0.0, min( 1.0, (float) apply_filters( 'shso_rum_sample_rate', 0.1 ) ) );
				$config = array(
					'url'  => esc_url_raw( rest_url( 'shso/v1/rum' ) ),
					'rate' => $rate,
					't'    => Context::template_key(),
				);
				$code   = str_ireplace( '</script', '<\/script', (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$doc->insert_before_body_end( '<script id="shso-rum">window.__shsoRum=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP ) . ';' . $code . '</script>' );
			},
			95
		);
	}

	/**
	 * Rate limit check for an incoming beacon.
	 */
	public static function allow(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$hash = substr( hash( 'sha256', $ip . '|' . gmdate( 'YmdH' ) . '|' . wp_salt( 'nonce' ) ), 0, 16 );
		$key  = 'shso_rum_' . $hash;

		$count = (int) get_transient( $key );
		if ( $count >= self::PER_IP_HOURLY ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		$daily = get_option( 'shso_rum_daily', array() );
		$today = gmdate( 'Y-m-d' );
		if ( ! is_array( $daily ) || ( $daily['date'] ?? '' ) !== $today ) {
			$daily = array(
				'date'  => $today,
				'count' => 0,
			);
		}
		/**
		 * Filters the maximum number of real-user beacons stored per day.
		 *
		 * @param int $cap Default 20000.
		 */
		if ( (int) $daily['count'] >= (int) apply_filters( 'shso_rum_daily_cap', self::DAILY_CAP ) ) {
			return false;
		}
		++$daily['count'];
		update_option( 'shso_rum_daily', $daily, false );

		return true;
	}
}
