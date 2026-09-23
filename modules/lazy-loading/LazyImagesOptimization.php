<?php
/**
 * Native lazy loading for images.
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
 * Adds loading="lazy" to images that are not visible when the page opens.
 */
final class LazyImagesOptimization extends AbstractOptimization {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'lazy_load_images';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Lazy-load images', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Images further down the page load only when visitors scroll near them, so the visible part of the page appears sooner. Your logo and the images at the top of the page always load right away.', 'sh-speed-optimizer' );
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
	 */
	public function assess( AssessmentContext $context ): Assessment {
		$pages = $context->pages();
		if ( empty( $pages ) ) {
			$assessment = Assessment::make( true, 85, Assessment::BENEFIT_LOW );
			$assessment->note( __( 'No pages were analysed yet, so the benefit is an estimate.', 'sh-speed-optimizer' ) );
			return $this->finalize( $assessment, $context, 'lazy_load' );
		}

		$missing   = 0;
		$max_below = 0;
		foreach ( $pages as $page ) {
			$images     = (array) ( $page['images'] ?? array() );
			$without    = max( 0, (int) ( $images['count'] ?? 0 ) - (int) ( $images['lazy'] ?? 0 ) - (int) ( $images['eager'] ?? 0 ) );
			$missing   += $without;
			$max_below  = max( $max_below, $without - ImageLazyLoader::DEFAULT_PROTECTED );
		}

		if ( 0 === $missing ) {
			return $this->finalize(
				Assessment::not_applicable( __( 'All images on the analysed pages already load lazily or are marked as important.', 'sh-speed-optimizer' ) ),
				$context,
				'lazy_load'
			);
		}

		$assessment       = Assessment::make( true, 90, $max_below >= 10 ? Assessment::BENEFIT_MEDIUM : Assessment::BENEFIT_LOW );
		$assessment->data = array(
			'images_without_loading' => $missing,
			'below_fold_estimate'    => max( 0, $max_below ),
		);
		$assessment->note(
			sprintf(
				/* translators: %d: number of images */
				_n( '%d image on the analysed pages loads right away even if visitors never scroll to it.', '%d images on the analysed pages load right away even if visitors never scroll to them.', $missing, 'sh-speed-optimizer' ),
				$missing
			)
		);

		return $this->finalize( $assessment, $context, 'lazy_load' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_runtime( Runtime $runtime ): void {
		$runtime->add_html_transform(
			$this->id(),
			static function ( HtmlDocument $doc ) use ( $runtime ) {
				if ( ! $doc->contains( '<img' ) ) {
					return;
				}
				// Respect sites (or plugins) that turned native lazy loading off on purpose.
				if ( function_exists( 'wp_lazy_loading_enabled' ) && ! wp_lazy_loading_enabled( 'img', 'shso_lazy_load' ) ) {
					return;
				}
				$loader = new ImageLazyLoader(
					array_map( 'strval', (array) $runtime->rules()->get( 'lazy_exclude' ) ),
					$runtime->page_data(),
					(string) wp_parse_url( home_url(), PHP_URL_HOST )
				);
				$loader->transform( $doc );
			},
			20
		);
	}
}
