<?php
/**
 * Missing image dimensions (prevents layout shifts).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\ImageOptimization;

use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Images\DimensionResolver;
use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Adds width/height to local images whose size is known.
 */
final class ImageDimensionsOptimization extends AbstractOptimization {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'image_dimensions';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Add missing image sizes', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Tells the browser how big each image is before it arrives, so text and buttons no longer jump around while the page loads.', 'sh-speed-optimizer' );
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
	public function requirements(): array {
		return array( self::REQ_BROWSER );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param AssessmentContext $context Scan data.
	 */
	public function assess( AssessmentContext $context ): Assessment {
		$missing = $context->sum( 'images.missing_dimensions' );
		if ( $missing <= 0 ) {
			return $this->finalize(
				Assessment::not_applicable( __( 'All images on the analysed pages already declare their size.', 'sh-speed-optimizer' ) ),
				$context,
				'image_optimization'
			);
		}

		$assessment       = Assessment::make( true, 85, $missing >= 3 ? Assessment::BENEFIT_MEDIUM : Assessment::BENEFIT_LOW );
		$assessment->data = array( 'missing_dimensions' => $missing );
		$assessment->note(
			sprintf(
				/* translators: %d: number of images */
				_n( '%d image on the analysed pages has no size information, which can make the page jump while loading.', '%d images on the analysed pages have no size information, which can make the page jump while loading.', $missing, 'sh-speed-optimizer' ),
				$missing
			)
		);
		$assessment->note( __( 'Only images stored on this site are changed, and only when their exact size is known.', 'sh-speed-optimizer' ) );

		return $this->finalize( $assessment, $context, 'image_optimization' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function rollback(): void {
		delete_option( DimensionResolver::OPTION );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Runtime $runtime Runtime.
	 */
	public function register_runtime( Runtime $runtime ): void {
		$runtime->add_html_transform(
			$this->id(),
			static function ( HtmlDocument $doc ) {
				if ( ! $doc->contains( '<img' ) ) {
					return;
				}
				$resolver = DimensionResolver::from_wordpress();
				$filler   = new DimensionFiller(
					static function ( string $src, string $srcset, array $classes ) use ( $resolver ) {
						return $resolver->resolve( $src, $srcset, $classes );
					}
				);
				$filler->transform( $doc );
				$resolver->save();
			},
			18
		);
	}
}
