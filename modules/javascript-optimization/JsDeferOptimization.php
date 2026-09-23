<?php
/**
 * Adds `defer` to scripts that provably do not need to block rendering.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\JavascriptOptimization;

use SH\SpeedOptimizer\Assets\AssetRewriter;
use SH\SpeedOptimizer\Assets\AssetSource;
use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\ScriptGraph;
use SH\SpeedOptimizer\Assets\Tag;
use SH\SpeedOptimizer\Modules\AssetOptimization\Exclusions;
use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Defer JavaScript (dependency-aware).
 */
final class JsDeferOptimization extends AbstractOptimization {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'js_defer';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Defer non-critical JavaScript', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Lets the page appear before scripts that are not needed for the first view have loaded. Only scripts that nothing else depends on at load time are deferred.', 'sh-speed-optimizer' );
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
		$blocking = array();
		$bytes    = 0;
		foreach ( $context->collect( 'scripts' ) as $script ) {
			$src = trim( (string) ( $script['src'] ?? '' ) );
			if ( '' === $src || empty( $script['in_head'] ) || ! empty( $script['async'] ) || ! empty( $script['defer'] ) || ! empty( $script['module'] ) ) {
				continue;
			}
			$key = (string) preg_replace( '/[?#].*$/s', '', $src );
			if ( isset( $blocking[ $key ] ) ) {
				continue;
			}
			$blocking[ $key ] = true;
			$bytes           += (int) ( $script['bytes'] ?? 0 );
		}

		$count = count( $blocking );
		if ( 0 === $count ) {
			return $this->finalize( Assessment::not_applicable( __( 'No render-blocking scripts were found in the page head.', 'sh-speed-optimizer' ) ), $context, 'defer_js' );
		}

		if ( $count >= 5 || $bytes > 100 * 1024 ) {
			$benefit = Assessment::BENEFIT_HIGH;
		} elseif ( $count >= 2 ) {
			$benefit = Assessment::BENEFIT_MEDIUM;
		} else {
			$benefit = Assessment::BENEFIT_LOW;
		}

		$assessment       = Assessment::make( true, 80, $benefit );
		$assessment->data = array(
			'blocking_scripts' => $count,
			'blocking_bytes'   => $bytes,
		);
		$assessment->note(
			sprintf(
				/* translators: %d: number of scripts */
				_n( '%d script blocks rendering in the page head.', '%d scripts block rendering in the page head.', $count, 'sh-speed-optimizer' ),
				$count
			)
		);
		$assessment->note( __( 'Scripts are only deferred when every script and inline snippet that uses them can wait too.', 'sh-speed-optimizer' ) );

		return $this->finalize( $assessment, $context, 'defer_js' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_runtime( Runtime $runtime ): void {
		if ( ! $this->plugin->context()->is_frontend_request() ) {
			return;
		}
		ScriptRecorder::hook();
		$runtime->add_html_transform( $this->id(), array( $this, 'transform' ), 50 );
	}

	/**
	 * HTML transform: add `defer` to the scripts the dependency analysis allows.
	 *
	 * @param HtmlDocument $doc Document.
	 */
	public function transform( HtmlDocument $doc ): void {
		$rules    = $this->plugin->runtime()->rules();
		$excluded = Exclusions::matcher( $rules, 'js_no_defer', (array) $this->plugin->settings()->get( 'exclude_js', array() ) );

		self::apply_defer(
			$doc,
			ScriptRecorder::scripts(),
			array(
				'is_excluded'    => static function ( string $handle, string $src ) use ( $excluded ): bool {
					return $excluded( $handle, AssetRewriter::original_url( $src ) );
				},
				'inline_globals' => (array) $rules->get( 'inline_globals' ),
				'is_local'       => static function ( string $src ): bool {
					return AssetSource::is_local_url( AssetRewriter::original_url( $src ) );
				},
			)
		);
	}

	/**
	 * Add `defer` to deferrable scripts (pure; used by transform() and tests).
	 *
	 * @param HtmlDocument                      $doc     Document.
	 * @param array<string,array<string,mixed>> $scripts WordPress data (ScriptGraph::capture()).
	 * @param array<string,mixed>               $options ScriptGraph options.
	 * @return int Number of deferred scripts.
	 */
	public static function apply_defer( HtmlDocument $doc, array $scripts, array $options ): int {
		$graph   = new ScriptGraph( $scripts, ScriptGraph::tags_from_document( $doc ), $options );
		$indexes = array_flip( $graph->deferrable_tags() );
		if ( empty( $indexes ) ) {
			return 0;
		}
		return $doc->replace_scripts(
			static function ( Tag $tag, string $code, array $info ) use ( $indexes ) {
				if ( ! isset( $indexes[ (int) $info['index'] ] ) || $tag->has( 'defer' ) ) {
					return null;
				}
				$tag->set( 'defer', null );
				return $tag->to_html() . $code . '</script>';
			}
		);
	}
}
