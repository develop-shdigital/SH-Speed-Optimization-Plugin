<?php
/**
 * Preload the fonts used by above-the-fold text (measured in the browser).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\FontOptimization;

use SH\SpeedOptimizer\Assets\Fonts\FontPreloader;
use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Images\ImageUrls;
use SH\SpeedOptimizer\Modules\Preload\Hints;
use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Font preload.
 */
final class FontPreloadOptimization extends AbstractOptimization {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'font_preload';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Load important fonts earlier', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Starts downloading the one or two fonts used at the top of your pages immediately, so your text appears in the right font sooner. Based on measurements taken in your browser.', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::FONTS;
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
	 */
	public function requirements(): array {
		return array( self::REQ_BROWSER );
	}

	/**
	 * {@inheritDoc}
	 */
	public function assess( AssessmentContext $context ): Assessment {
		$templates = array();
		foreach ( $context->browser as $template => $data ) {
			foreach ( (array) ( $data['fonts_preload'] ?? array() ) as $font ) {
				if ( is_array( $font ) && 'woff2' === ImageUrls::extension( (string) ( $font['url'] ?? '' ) ) ) {
					$templates[] = (string) $template;
					break;
				}
			}
		}

		if ( empty( $templates ) ) {
			return $this->finalize(
				Assessment::not_applicable( __( 'Needs a measurement in your browser (run Optimize My Site from the dashboard).', 'sh-speed-optimizer' ) ),
				$context,
				'font_optimization'
			);
		}

		$assessment       = Assessment::make( true, 80, Assessment::BENEFIT_MEDIUM );
		$assessment->data = array( 'templates' => $templates );
		$assessment->note( __( 'At most two fonts per page are loaded earlier, and only fonts that were measured at the top of the page.', 'sh-speed-optimizer' ) );

		return $this->finalize( $assessment, $context, 'font_optimization' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_runtime( Runtime $runtime ): void {
		$runtime->add_html_transform(
			$this->id(),
			static function ( HtmlDocument $doc ) use ( $runtime ) {
				$data = $runtime->page_data();
				if ( empty( $data['fonts_preload'] ) || ! is_array( $data['fonts_preload'] ) ) {
					return;
				}
				$host      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
				$preloader = new FontPreloader( $data['fonts_preload'], $host, self::local_checker( $host ) );
				$preloader->transform( $doc, array( Hints::class, 'insert_early' ) );
			},
			90
		);
	}

	/**
	 * Checks that a same-host font URL maps to an existing file inside wp-content.
	 *
	 * @param string $host Site host.
	 */
	private static function local_checker( string $host ): callable {
		return static function ( string $url ) use ( $host ): bool {
			$content = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
			$path    = ImageUrls::local_path( $url, array( trailingslashit( content_url() ) => $content ), $host );
			if ( null === $path ) {
				return false;
			}
			$real = realpath( $path );
			$root = realpath( WP_CONTENT_DIR );
			return false !== $real && false !== $root && is_file( $real )
				&& 0 === strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $root ) ) );
		};
	}
}
