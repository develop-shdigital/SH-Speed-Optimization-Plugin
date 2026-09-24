<?php
/**
 * Native lazy loading for iframes (videos, maps, embeds).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\LazyLoading;

use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Adds loading="lazy" to embedded frames further down the page.
 */
final class LazyIframesOptimization extends AbstractOptimization {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'lazy_load_iframes';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Lazy-load embedded videos and maps', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Embedded content such as videos, maps and widgets further down the page loads only when visitors scroll near it. Content at the top of the page loads right away.', 'sh-speed-optimizer' );
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
		return Risk::LEVEL_SMART;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param AssessmentContext $context Scan data.
	 */
	public function assess( AssessmentContext $context ): Assessment {
		$eager = array_filter(
			$context->collect( 'iframes' ),
			static function ( $iframe ) {
				return empty( $iframe['lazy'] );
			}
		);

		if ( empty( $eager ) ) {
			return $this->finalize(
				Assessment::not_applicable( __( 'No embedded frames without lazy loading were found on the analysed pages.', 'sh-speed-optimizer' ) ),
				$context,
				'lazy_load'
			);
		}

		$heavy = 0;
		foreach ( $eager as $iframe ) {
			if ( in_array( (string) ( $iframe['kind'] ?? '' ), array( 'youtube', 'vimeo', 'google_maps' ), true ) ) {
				++$heavy;
			}
		}

		$assessment       = Assessment::make( true, 85, $heavy > 0 ? Assessment::BENEFIT_MEDIUM : Assessment::BENEFIT_LOW );
		$assessment->data = array(
			'iframes_without_loading' => count( $eager ),
			'video_or_map'            => $heavy,
		);
		$assessment->note(
			sprintf(
				/* translators: %d: number of embedded frames */
				_n( '%d embedded frame loads right away on the analysed pages.', '%d embedded frames load right away on the analysed pages.', count( $eager ), 'sh-speed-optimizer' ),
				count( $eager )
			)
		);

		return $this->finalize( $assessment, $context, 'lazy_load' );
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
				if ( ! $doc->contains( '<iframe' ) ) {
					return;
				}
				if ( LazyLoadingPolicy::iframes_disabled() ) {
					return;
				}
				$data   = $runtime->page_data();
				$loader = new IframeLazyLoader(
					array_map( 'strval', (array) $runtime->rules()->get( 'lazy_exclude' ) ),
					! empty( $data['video_in_viewport'] ) || ! empty( $data['map_in_viewport'] )
				);
				$loader->transform( $doc );
			},
			20
		);
	}
}
