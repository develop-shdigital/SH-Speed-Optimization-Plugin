<?php
/**
 * Delays the site's own scripts until the visitor interacts (experimental).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\JavascriptOptimization;

use SH\SpeedOptimizer\Assets\AssetRewriter;
use SH\SpeedOptimizer\Assets\AssetSource;
use SH\SpeedOptimizer\Assets\DelayEngine;
use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Modules\AssetOptimization\Exclusions;
use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Delay all (first-party) JavaScript.
 */
final class JsDelayOptimization extends AbstractOptimization {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'js_delay_all';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Delay JavaScript until interaction (experimental)', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Loads your site\'s own scripts only after the visitor scrolls, clicks, taps or types. This can speed up the first load a lot, but sliders, menus or forms may only start working after the first interaction — test your site carefully.', 'sh-speed-optimizer' );
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
		return Risk::HIGH;
	}

	/**
	 * {@inheritDoc}
	 */
	public function level(): string {
		return Risk::LEVEL_EXPERIMENTAL;
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
	public function default_enabled(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function assess( AssessmentContext $context ): Assessment {
		$local = array();
		$bytes = 0;
		foreach ( $context->collect( 'scripts' ) as $script ) {
			$src    = trim( (string) ( $script['src'] ?? '' ) );
			$handle = (string) ( $script['handle'] ?? '' );
			if ( '' === $src || empty( $script['local'] ) || in_array( $handle, array( 'jquery', 'jquery-core', 'jquery-migrate' ), true ) || false !== stripos( $src, '/wp-includes/js/jquery/' ) ) {
				continue;
			}
			$key = (string) preg_replace( '/[?#].*$/s', '', $src );
			if ( ! isset( $local[ $key ] ) ) {
				$local[ $key ] = true;
				$bytes        += (int) ( $script['bytes'] ?? 0 );
			}
		}

		$count = count( $local );
		if ( 0 === $count ) {
			return $this->finalize( Assessment::not_applicable( __( 'Your pages load no scripts of their own that could be delayed.', 'sh-speed-optimizer' ) ), $context, 'delay_js' );
		}

		if ( $count >= 8 || $bytes > 300 * 1024 ) {
			$benefit = Assessment::BENEFIT_HIGH;
		} elseif ( $count >= 3 ) {
			$benefit = Assessment::BENEFIT_MEDIUM;
		} else {
			$benefit = Assessment::BENEFIT_LOW;
		}

		$assessment       = Assessment::make( true, 50, $benefit );
		$assessment->data = array(
			'local_scripts' => $count,
			'local_bytes'   => $bytes,
		);
		$assessment->note( __( 'Experimental: interactive elements may only work after the first scroll, click or key press. Never enabled automatically.', 'sh-speed-optimizer' ) );

		return $this->finalize( $assessment, $context, 'delay_js' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_runtime( Runtime $runtime ): void {
		if ( ! $this->plugin->context()->is_frontend_request() ) {
			return;
		}
		ScriptRecorder::hook();
		$runtime->add_html_transform( $this->id(), array( $this, 'transform' ), 55 );
		DelayEngine::register_loader( $runtime );
	}

	/**
	 * HTML transform.
	 *
	 * @param HtmlDocument $doc Document.
	 */
	public function transform( HtmlDocument $doc ): void {
		$rules    = $this->plugin->runtime()->rules();
		$excluded = Exclusions::matcher( $rules, 'js_no_delay', (array) $this->plugin->settings()->get( 'exclude_js', array() ) );

		DelayEngine::delay_first_party(
			$doc,
			ScriptRecorder::scripts(),
			$excluded,
			static function ( string $src ): bool {
				return AssetSource::is_local_url( AssetRewriter::original_url( $src ) );
			},
			(array) $rules->get( 'inline_globals' )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function details(): array {
		return array(
			'timeout_seconds' => DelayEngine::timeout_all() / 1000,
		);
	}
}
