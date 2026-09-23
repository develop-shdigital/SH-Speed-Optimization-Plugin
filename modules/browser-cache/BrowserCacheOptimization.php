<?php
/**
 * Browser caching for static files (.htaccess on Apache/LiteSpeed).
 *
 * Requires the administrator's permission to change server configuration
 * (`allow_server_config`). Servers configured elsewhere (nginx, IIS) are
 * never touched; the dashboard shows a snippet instead.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\BrowserCache;

use SH\SpeedOptimizer\Diagnostics\Loopback;
use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Browser cache optimization.
 */
final class BrowserCacheOptimization extends AbstractOptimization {

	/**
	 * A max-age of at least one week counts as "already configured".
	 */
	private const CONFIGURED_MAX_AGE = 604800;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'browser_cache';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Browser caching', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Lets browsers keep images, styles, scripts and fonts for up to a year, so returning visitors load pages faster. Web pages themselves are never cached by the browser.', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::CACHE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function risk(): string {
		return Risk::LOW;
	}

	/**
	 * {@inheritDoc}
	 */
	public function level(): string {
		return Risk::LEVEL_SAFE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function requirements(): array {
		return array( self::REQ_SERVER_CONFIG );
	}

	/**
	 * {@inheritDoc}
	 */
	public function safe_mode_compatible(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param AssessmentContext $context Scan data.
	 */
	public function assess( AssessmentContext $context ): Assessment {
		$server = strtolower( (string) $context->profile->get( 'server.software', '' ) );
		if ( '' === $server || 'unknown' === $server ) {
			$server = HtaccessRules::server();
		}

		if ( ! in_array( $server, array( 'apache', 'litespeed' ), true ) ) {
			$assessment                        = Assessment::not_applicable(
				'nginx' === $server
					? __( 'Your server (nginx) is not configured through .htaccess files, so the plugin does not change it. The dashboard shows a ready-made configuration your host can add.', 'sh-speed-optimizer' )
					: __( 'Your server is not configured through .htaccess files, so the plugin does not change it. Ask your host to enable browser caching for static files.', 'sh-speed-optimizer' )
			);
			$assessment->data['server']        = $server;
			$assessment->data['nginx_snippet'] = 'nginx' === $server;
			return $this->finalize( $assessment, $context, 'browser_cache' );
		}

		$installed = HtaccessRules::is_installed();
		if ( ! $installed ) {
			$configured = $context->profile->get( 'browser_cache.configured' );
			if ( null === $configured ) {
				$configured = $this->probe_configured();
			}
			if ( true === $configured ) {
				$assessment                 = Assessment::not_applicable( __( 'Your server already tells browsers to keep static files, so nothing needs to change.', 'sh-speed-optimizer' ) );
				$assessment->data['server'] = $server;
				return $this->finalize( $assessment, $context, 'browser_cache' );
			}
		}

		$assessment                    = Assessment::make( true, 90, Assessment::BENEFIT_MEDIUM );
		$assessment->data['server']    = $server;
		$assessment->data['installed'] = $installed;
		$assessment->note( __( 'Returning visitors will load images, styles, scripts and fonts from their browser instead of downloading them again.', 'sh-speed-optimizer' ) );

		$writable = $context->profile->get( 'server.htaccess_writable' );
		if ( false === $writable || ( null === $writable && ! HtaccessRules::is_writable() ) ) {
			$assessment->blocked = __( 'The .htaccess file cannot be changed by WordPress.', 'sh-speed-optimizer' );
		}

		if ( 'litespeed' === $server && 'openlitespeed' === strtolower( (string) $context->profile->get( 'server.software_raw', '' ) ) ) {
			$assessment->penalize( 20, __( 'OpenLiteSpeed only partly reads .htaccess files; the result is checked after the change.', 'sh-speed-optimizer' ) );
		}

		return $this->finalize( $assessment, $context, 'browser_cache' );
	}

	/**
	 * Fallback detection when the scan did not check caching headers:
	 * true when a core stylesheet is already cached for at least a week, null when unknown.
	 */
	private function probe_configured(): ?bool {
		$response = Loopback::get(
			HtaccessRules::test_asset_url(),
			array(
				'method'  => 'HEAD',
				'timeout' => 15,
			)
		);
		if ( ! $response['ok'] || $response['status'] < 200 || $response['status'] >= 400 ) {
			return null;
		}
		$max_age = HtaccessRules::max_age_from_headers( $response['headers'] );
		return null !== $max_age && $max_age >= self::CONFIGURED_MAX_AGE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply() {
		if ( ! $this->plugin->settings()->get( 'allow_server_config', false ) ) {
			return new \WP_Error( 'shso_browser_cache_permission', __( 'Browser caching needs your permission to add rules to the server configuration (.htaccess).', 'sh-speed-optimizer' ) );
		}
		return HtaccessRules::install();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $baseline  Baseline snapshot (home page status is compared when present).
	 * @param array<string,mixed> $candidate Candidate snapshot (unused).
	 */
	public function verify( array $baseline, array $candidate ): ?string {
		$home = Loopback::get( home_url( '/' ), array( 'timeout' => 20 ) );
		if ( ! $home['ok'] || $home['status'] < 200 || $home['status'] >= 400 ) {
			return __( 'Your site did not respond normally after the browser caching rules were added.', 'sh-speed-optimizer' );
		}
		$expected = (int) ( $baseline['status'] ?? 0 );
		if ( $expected >= 200 && $expected < 300 && ( (int) $home['status'] < 200 || (int) $home['status'] >= 300 ) ) {
			return __( 'Your home page answered differently after the browser caching rules were added.', 'sh-speed-optimizer' );
		}

		$asset   = Loopback::get(
			HtaccessRules::test_asset_url(),
			array(
				'method'  => 'HEAD',
				'timeout' => 20,
			)
		);
		$max_age = HtaccessRules::max_age_from_headers( $asset['headers'] );
		if ( $asset['status'] < 200 || $asset['status'] >= 400 || null === $max_age || $max_age <= 0 ) {
			return __( 'The browser caching rules have no effect on this server (static files are probably delivered by another layer, such as nginx), so they were removed.', 'sh-speed-optimizer' );
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function rollback(): void {
		HtaccessRules::remove();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Runtime $runtime Runtime.
	 */
	public function register_runtime( Runtime $runtime ): void {
		// Server configuration only: nothing runs inside WordPress.
	}

	/**
	 * {@inheritDoc}
	 */
	public function details(): array {
		$server = HtaccessRules::server();
		return array(
			'server'          => $server,
			'rules_installed' => HtaccessRules::is_installed(),
			'nginx_snippet'   => 'nginx' === $server ? HtaccessRules::nginx_snippet() : null,
		);
	}
}
