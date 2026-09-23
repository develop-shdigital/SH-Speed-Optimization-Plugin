<?php
/**
 * Preconnect hints for important third-party origins.
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
 * Preconnect.
 */
final class PreconnectOptimization extends AbstractOptimization {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'preconnect';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Connect early to external services', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Lets the browser open connections to services your pages need right away (such as Google Fonts) a little earlier, which saves waiting time.', 'sh-speed-optimizer' );
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
		return Risk::SAFE;
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
		$google = 0;
		$others = array();
		foreach ( $context->pages() as $page ) {
			$google += count( (array) ( $page['fonts']['google'] ?? array() ) );
			foreach ( (array) ( $page['styles'] ?? array() ) as $style ) {
				if ( empty( $style['local'] ) && ! empty( $style['in_head'] ) && ! empty( $style['href'] ) ) {
					$others[ Hints::host( (string) $style['href'] ) ] = true;
				}
			}
			foreach ( (array) ( $page['scripts'] ?? array() ) as $script ) {
				if ( empty( $script['local'] ) && ! empty( $script['in_head'] ) && empty( $script['async'] ) && empty( $script['defer'] ) && empty( $script['module'] ) && ! empty( $script['src'] ) ) {
					$others[ Hints::host( (string) $script['src'] ) ] = true;
				}
			}
		}
		unset( $others[''] );

		if ( 0 === $google && empty( $others ) ) {
			return $this->finalize(
				Assessment::not_applicable( __( 'Your pages do not load render-blocking files from external services.', 'sh-speed-optimizer' ) ),
				$context
			);
		}

		$assessment       = Assessment::make( true, 95, $google > 0 ? Assessment::BENEFIT_MEDIUM : Assessment::BENEFIT_LOW );
		$assessment->data = array(
			'google_fonts' => $google,
			'origins'      => array_keys( $others ),
		);
		$assessment->note( __( 'At most three connections are opened early, and only to services used at the top of the page.', 'sh-speed-optimizer' ) );

		return $this->finalize( $assessment, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_runtime( Runtime $runtime ): void {
		$runtime->add_html_transform(
			$this->id(),
			static function ( HtmlDocument $doc ) {
				$uploads = wp_upload_dir( null, false );
				$hosts   = array(
					(string) wp_parse_url( home_url(), PHP_URL_HOST ),
					(string) wp_parse_url( site_url(), PHP_URL_HOST ),
					(string) wp_parse_url( content_url(), PHP_URL_HOST ),
					(string) wp_parse_url( (string) $uploads['baseurl'], PHP_URL_HOST ),
				);
				( new PreconnectHints( $hosts ) )->transform( $doc );
			},
			90
		);
	}
}
