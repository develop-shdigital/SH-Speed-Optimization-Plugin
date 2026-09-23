<?php
/**
 * Minified copies of local stylesheets.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\CssOptimization;

use SH\SpeedOptimizer\Assets\AssetCopies;
use SH\SpeedOptimizer\Modules\AssetOptimization\AssetPipeline;
use SH\SpeedOptimizer\Modules\AssetOptimization\Exclusions;
use SH\SpeedOptimizer\Modules\AssetOptimization\PageAssets;
use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * CSS minification ("basic minification").
 */
final class CssMinifyOptimization extends AbstractOptimization {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'css_minify';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Minify CSS files', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Serves smaller copies of your theme and plugin stylesheets, without comments and unnecessary spaces. Your original files are never changed.', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::CSS;
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
		$styles = $context->collect( 'styles' );
		if ( empty( $styles ) ) {
			return $this->finalize( Assessment::not_applicable( __( 'No stylesheets were found on the scanned pages.', 'sh-speed-optimizer' ) ), $context, 'minify_css' );
		}

		$excluded = Exclusions::from_settings( $context->rules, 'css_no_optimize', $context->settings->all(), 'exclude_css' );
		$stats    = PageAssets::unminified( $styles, 'href', $excluded );

		if ( 0 === $stats['count'] ) {
			return $this->finalize( Assessment::not_applicable( __( 'All stylesheets of this site are already minified (or excluded).', 'sh-speed-optimizer' ) ), $context, 'minify_css' );
		}

		$assessment       = Assessment::make( true, 90, PageAssets::minify_benefit( $stats['bytes'], $stats['count'] ) );
		$assessment->data = $stats;
		$assessment->note(
			sprintf(
				/* translators: 1: number of files, 2: total size such as "120 KB" */
				_n( '%1$d stylesheet (%2$s) is not minified yet.', '%1$d stylesheets (%2$s) are not minified yet.', $stats['count'], 'sh-speed-optimizer' ),
				$stats['count'],
				PageAssets::size( $stats['bytes'] )
			)
		);
		$assessment->note( __( 'Minified copies are created next to the originals; files that cannot be minified safely keep loading unchanged.', 'sh-speed-optimizer' ) );

		return $this->finalize( $assessment, $context, 'minify_css' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_runtime( Runtime $runtime ): void {
		AssetPipeline::register( $runtime );
	}

	/**
	 * {@inheritDoc}
	 */
	public function rollback(): void {
		// Copies stay until no cached page can reference them any more (garbage collection);
		// the rewriter stops using them immediately.
		$copies = AssetCopies::instance();
		$copies->clear_queue( 'css' );
		$copies->garbage_collect();
	}

	/**
	 * {@inheritDoc}
	 */
	public function details(): array {
		$stats = AssetCopies::instance()->stats();
		return array(
			'copies' => $stats['css'],
			'queued' => $stats['queued'],
			'label'  => sprintf(
				/* translators: %d: number of files */
				_n( '%d optimized stylesheet copy', '%d optimized stylesheet copies', $stats['css'], 'sh-speed-optimizer' ),
				$stats['css']
			),
		);
	}
}
