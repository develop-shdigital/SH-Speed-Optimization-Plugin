<?php
/**
 * Delays third-party analytics, marketing, chat and social scripts until the
 * first interaction (or an idle timeout). Tracking is never removed.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\ThirdPartyOptimization;

use SH\SpeedOptimizer\Assets\DelayEngine;
use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\ThirdPartyCatalog;
use SH\SpeedOptimizer\Modules\AssetOptimization\Exclusions;
use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Delay third-party scripts.
 */
final class ThirdPartyDelayOptimization extends AbstractOptimization {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'js_delay_third_party';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Delay third-party tracking and chat scripts', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Loads analytics, advertising pixels, chat widgets and social scripts after the visitor\'s first interaction, or after a few seconds. Tracking still happens. Cookie banners, payments, CAPTCHAs, maps and A/B tests are never delayed.', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::THIRD_PARTY;
	}

	/**
	 * {@inheritDoc}
	 */
	public function risk(): string {
		return Risk::MODERATE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function level(): string {
		return Risk::LEVEL_SMART;
	}

	/**
	 * {@inheritDoc}
	 */
	public function requirements(): array {
		return array( self::REQ_BROWSER );
	}

	/**
	 * {@inheritDoc}
	 */
	public function assess( AssessmentContext $context ): Assessment {
		$excluded = Exclusions::from_settings( $context->rules, 'js_no_delay', $context->settings->all(), 'exclude_js' );
		$per_page = array();
		$names    = array();

		foreach ( $context->pages() as $url => $page ) {
			$found = array();

			foreach ( (array) ( $page['third_party'] ?? array() ) as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$src   = (string) ( $item['src'] ?? '' );
				$entry = ! empty( $item['id'] ) ? ThirdPartyCatalog::get( (string) $item['id'] ) : null;
				if ( null === $entry && '' !== $src ) {
					$entry = ThirdPartyCatalog::match_url( $src );
				}
				if ( null !== $entry && ! empty( $entry['delayable'] ) && ! $excluded( '', $src ) ) {
					$found[ $entry['id'] ] = $entry['name'];
				}
			}

			// Fallback when the analysis did not classify third parties: external scripts.
			if ( empty( $page['third_party'] ) ) {
				foreach ( (array) ( $page['scripts'] ?? array() ) as $script ) {
					$src = (string) ( $script['src'] ?? '' );
					if ( '' === $src || ! empty( $script['local'] ) ) {
						continue;
					}
					$entry = ThirdPartyCatalog::match_url( $src );
					if ( null !== $entry && ! empty( $entry['delayable'] ) && ! $excluded( (string) ( $script['handle'] ?? '' ), $src ) ) {
						$found[ $entry['id'] ] = $entry['name'];
					}
				}
			}

			$per_page[ $url ] = count( $found );
			$names           += $found;
		}

		$max = empty( $per_page ) ? 0 : max( $per_page );
		if ( 0 === $max ) {
			return $this->finalize( Assessment::not_applicable( __( 'No third-party scripts that can safely wait were found.', 'sh-speed-optimizer' ) ), $context, 'delay_js' );
		}

		$assessment       = Assessment::make( true, 80, $max >= 3 ? Assessment::BENEFIT_HIGH : Assessment::BENEFIT_MEDIUM );
		$assessment->data = array(
			'delayable_per_page_max' => $max,
			'services'               => array_values( $names ),
		);
		$assessment->note(
			sprintf(
				/* translators: %s: comma separated list of services such as "Google Analytics, Hotjar" */
				__( 'Can wait until the first interaction: %s.', 'sh-speed-optimizer' ),
				implode( ', ', array_values( $names ) )
			)
		);
		$assessment->note(
			sprintf(
				/* translators: %d: seconds */
				__( 'Visitors who do not interact are still tracked: delayed scripts start %d seconds after the page loaded.', 'sh-speed-optimizer' ),
				(int) round( DelayEngine::timeout() / 1000 )
			)
		);
		if ( $context->profile->has_feature( 'consent' ) ) {
			$assessment->note( __( 'Your cookie consent banner is never delayed: only known tracking, chat and social scripts wait.', 'sh-speed-optimizer' ) );
		}

		return $this->finalize( $assessment, $context, 'delay_js' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_runtime( Runtime $runtime ): void {
		if ( ! $this->plugin->context()->is_frontend_request() ) {
			return;
		}
		$runtime->add_html_transform( $this->id(), array( $this, 'transform' ), 60 );
		DelayEngine::register_loader( $runtime );
	}

	/**
	 * HTML transform.
	 *
	 * @param HtmlDocument $doc Document.
	 */
	public function transform( HtmlDocument $doc ): void {
		$excluded = Exclusions::matcher( $this->plugin->runtime()->rules(), 'js_no_delay', (array) $this->plugin->settings()->get( 'exclude_js', array() ) );
		DelayEngine::delay_third_party( $doc, $excluded );
	}

	/**
	 * {@inheritDoc}
	 */
	public function details(): array {
		return array(
			'timeout_seconds' => DelayEngine::timeout() / 1000,
		);
	}
}
