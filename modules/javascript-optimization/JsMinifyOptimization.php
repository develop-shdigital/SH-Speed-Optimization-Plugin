<?php
/**
 * Minified copies of local scripts.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\JavascriptOptimization;

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
 * JavaScript minification.
 */
final class JsMinifyOptimization extends AbstractOptimization {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'js_minify';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Minify JavaScript files', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Serves smaller copies of your theme and plugin scripts, without comments and unnecessary spaces. Kept only after a test in your browser shows no errors. Your original files are never changed.', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::JAVASCRIPT;
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
	 */
	public function assess( AssessmentContext $context ): Assessment {
		$scripts = $context->collect( 'scripts' );
		if ( empty( $scripts ) ) {
			return $this->finalize( Assessment::not_applicable( __( 'No scripts were found on the scanned pages.', 'sh-speed-optimizer' ) ), $context, 'minify_js' );
		}

		$excluded = Exclusions::from_settings( $context->rules, 'js_no_minify', $context->settings->all(), 'exclude_js' );
		$stats    = PageAssets::unminified( $scripts, 'src', $excluded );

		if ( 0 === $stats['count'] ) {
			return $this->finalize( Assessment::not_applicable( __( 'All scripts of this site are already minified (or excluded).', 'sh-speed-optimizer' ) ), $context, 'minify_js' );
		}

		$assessment       = Assessment::make( true, 85, PageAssets::minify_benefit( $stats['bytes'], $stats['count'] ) );
		$assessment->data = $stats;
		$assessment->note(
			sprintf(
				/* translators: 1: number of files, 2: total size such as "120 KB" */
				_n( '%1$d script (%2$s) is not minified yet.', '%1$d scripts (%2$s) are not minified yet.', $stats['count'], 'sh-speed-optimizer' ),
				$stats['count'],
				PageAssets::size( $stats['bytes'] )
			)
		);
		$assessment->note( __( 'Scripts that load files relative to their own location are never copied, and every copy is checked for errors in a real browser first.', 'sh-speed-optimizer' ) );

		return $this->finalize( $assessment, $context, 'minify_js' );
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
		$copies = AssetCopies::instance();
		$copies->clear_queue( 'js' );
		$copies->garbage_collect();
	}

	/**
	 * {@inheritDoc}
	 */
	public function details(): array {
		$stats = AssetCopies::instance()->stats();
		return array(
			'copies' => $stats['js'],
			'queued' => $stats['queued'],
			'label'  => sprintf(
				/* translators: %d: number of files */
				_n( '%d optimized script copy', '%d optimized script copies', $stats['js'], 'sh-speed-optimizer' ),
				$stats['js']
			),
		);
	}
}
