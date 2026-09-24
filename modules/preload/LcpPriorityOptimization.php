<?php
/**
 * Prioritize the Largest Contentful Paint image (fetchpriority + one preload).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\Preload;

use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * LCP image priority.
 */
final class LcpPriorityOptimization extends AbstractOptimization {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'lcp_priority';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Load the main image first', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Tells the browser to download the largest image at the top of your pages before anything else, so the main content appears sooner. Based on measurements taken in your browser.', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::IMAGES;
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
	 *
	 * @param AssessmentContext $context Scan data.
	 */
	public function assess( AssessmentContext $context ): Assessment {
		$templates = array();
		foreach ( $context->browser as $template => $data ) {
			if ( ( new LcpPreloader( self::measured_lcp( (string) $template, is_array( $data ) ? $data : array() ) ) )->is_usable() ) {
				$templates[] = (string) $template;
			}
		}

		if ( empty( $templates ) ) {
			return $this->finalize(
				Assessment::not_applicable( __( 'Needs a measurement in your browser (run Optimize My Site from the dashboard).', 'sh-speed-optimizer' ) ),
				$context
			);
		}

		$assessment       = Assessment::make( true, 90, Assessment::BENEFIT_HIGH );
		$assessment->data = array( 'templates' => $templates );
		$assessment->note(
			sprintf(
				/* translators: %d: number of page types */
				_n( 'The main image was measured reliably on %d type of page.', 'The main image was measured reliably on %d types of pages.', count( $templates ), 'sh-speed-optimizer' ),
				count( $templates )
			)
		);

		return $this->finalize( $assessment, $context );
	}

	/**
	 * LCP measurement of a template: the derived page data entry (with its confidence) when the
	 * scanner stored one, otherwise the raw browser result (an image LCP inside the viewport
	 * counts as confidence 85, like the scanner's own rule).
	 *
	 * @param string              $template Template key.
	 * @param array<string,mixed> $data     Browser result of the template.
	 * @return array<string,mixed>
	 */
	public static function measured_lcp( string $template, array $data ): array {
		$page_data = get_option( Runtime::PAGE_DATA_OPTION, array() );
		$stored    = is_array( $page_data ) && is_array( $page_data[ $template ]['lcp'] ?? null ) ? $page_data[ $template ]['lcp'] : array();
		if ( isset( $stored['confidence'] ) ) {
			return $stored;
		}
		$lcp = is_array( $data['lcp'] ?? null ) ? $data['lcp'] : array();
		if ( ! isset( $lcp['confidence'] ) ) {
			$lcp['confidence'] = 'img' === ( $lcp['type'] ?? '' ) && ! empty( $lcp['in_viewport'] ) ? 85 : 0;
		}
		return $lcp;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Runtime $runtime Runtime.
	 */
	public function register_runtime( Runtime $runtime ): void {
		$runtime->add_html_transform(
			$this->id(),
			static function ( HtmlDocument $doc ) use ( $runtime ) {
				$data = $runtime->page_data();
				if ( empty( $data['lcp'] ) || ! is_array( $data['lcp'] ) ) {
					return;
				}
				( new LcpPreloader( $data['lcp'], (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) )->transform( $doc );
			},
			90
		);
	}
}
