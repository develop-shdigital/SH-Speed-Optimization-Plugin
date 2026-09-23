<?php
/**
 * Click-to-load placeholders for embedded Google Maps (map facades).
 *
 * The Google Maps JavaScript API used by plugins (maps.googleapis.com/maps/api/js)
 * is never touched; only embed iframes are replaced.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\ThirdPartyOptimization;

use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Assets\Images\MarkupRanges;
use SH\SpeedOptimizer\Assets\Tag;
use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Map facades.
 */
final class MapFacadeOptimization extends AbstractOptimization {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'map_facade';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Load maps only when needed', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Shows a simple placeholder with a "Load map" button instead of loading the full Google Map right away. The interactive map appears as soon as a visitor asks for it.', 'sh-speed-optimizer' );
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
	public function expected_changes(): array {
		return array( 'iframes' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param AssessmentContext $context Scan data.
	 */
	public function assess( AssessmentContext $context ): Assessment {
		$maps = array_filter(
			$context->collect( 'iframes' ),
			static function ( $iframe ) {
				return 'google_maps' === (string) ( $iframe['kind'] ?? '' );
			}
		);

		$api = 0;
		foreach ( $context->collect( 'third_party' ) as $item ) {
			if ( 'maps' === ( $item['category'] ?? '' ) && false !== stripos( (string) ( $item['src'] ?? '' ), 'maps.googleapis.com/maps/api/js' ) ) {
				++$api;
			}
		}

		if ( empty( $maps ) ) {
			$assessment = Assessment::not_applicable( __( 'No embedded Google Maps were found on the analysed pages.', 'sh-speed-optimizer' ) );
			if ( $api > 0 ) {
				$assessment->note( __( 'A plugin loads the Google Maps script; it is left unchanged because the plugin controls it.', 'sh-speed-optimizer' ) );
			}
			return $this->finalize( $assessment, $context );
		}

		$assessment       = Assessment::make( true, 75, Assessment::BENEFIT_MEDIUM );
		$assessment->data = array(
			'maps'           => count( $maps ),
			'maps_api_pages' => $api,
		);
		$assessment->note(
			sprintf(
				/* translators: %d: number of maps */
				_n( '%d embedded map loads the full Google Maps application even if nobody uses it.', '%d embedded maps load the full Google Maps application even if nobody uses them.', count( $maps ), 'sh-speed-optimizer' ),
				count( $maps )
			)
		);

		return $this->finalize( $assessment, $context );
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
				if ( ! $doc->contains( '<iframe' ) || ! $doc->contains( 'google.' ) ) {
					return;
				}
				// Measured as visible without scrolling on this template: keep the real map.
				if ( ! empty( $runtime->page_data()['map_in_viewport'] ) ) {
					return;
				}
				$count = self::transform( $doc, array_map( 'strval', (array) $runtime->rules()->get( 'facade_exclude' ) ) );
				if ( $count > 0 ) {
					$base = plugin_dir_url( SHSO_FILE ) . 'assets/';
					Facades::inject_assets( $doc, $base . 'css/facades.css?ver=' . SHSO_VERSION, $base . 'js/facades.min.js?ver=' . SHSO_VERSION );
				}
			},
			60
		);
	}

	/**
	 * Replace eligible Google Maps embeds.
	 *
	 * @param HtmlDocument $doc     Document.
	 * @param string[]     $exclude facade_exclude fragments.
	 * @return int Replaced maps.
	 */
	public static function transform( HtmlDocument $doc, array $exclude = array() ): int {
		$heading = MarkupRanges::first_heading( $doc );
		$index   = 0;

		return $doc->replace_elements(
			'iframe',
			static function ( Tag $frame, string $inner, array $info ) use ( $exclude, $heading, &$index ) {
				if ( $info['in_head'] ) {
					return null;
				}
				$first = 0 === $index++;
				$src   = trim( (string) $frame->get( 'src' ) );
				if ( '' === $src || ! Facades::is_google_maps_embed( $src ) ) {
					return null;
				}
				// The first frame above the first heading is likely hero content.
				if ( $first && (int) $info['offset'] < $heading ) {
					return null;
				}
				if ( Facades::excluded( $frame, $frame->to_html(), $exclude ) ) {
					return null;
				}
				return Facades::map_markup( $frame, Facades::map_query( $src ) );
			}
		);
	}
}
