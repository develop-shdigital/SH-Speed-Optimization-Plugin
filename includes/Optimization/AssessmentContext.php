<?php
/**
 * Input for optimization detection logic.
 *
 * `pages` holds one server-side page analysis per sampled URL (see
 * {@see \SH\SpeedOptimizer\Diagnostics\PageAnalyzer} for the shape):
 *
 *   url, template, status, ttfb_ms, generation_ms, html_bytes,
 *   scripts[]      => handle|null, src, local, in_head, async, defer, module, bytes|null, source{type,slug,name},
 *                     deps[], inline_after (bool), minified (bool)
 *   styles[]       => handle|null, href, local, media, in_head, bytes|null, source{…}, minified (bool)
 *   inline         => scripts (int), script_bytes (int), styles (int), style_bytes (int), globals[] (JS globals referenced)
 *   images         => count, lazy, eager, missing_dimensions, missing_alt, fetchpriority_high, local, external
 *   iframes[]      => src, kind (youtube|vimeo|google_maps|other), lazy, position (index among media)
 *   third_party[]  => id, name, category (analytics|ads|social|video|maps|chat|captcha|payment|consent|ab_testing|other),
 *                     src|null, inline (bool), blocking (bool)
 *   fonts          => google[] (url, families{family:[weights]}, display), adobe (bool), preloads (int),
 *                     font_faces (int, inline @font-face count), font_display_missing (int)
 *   queries        => count, time_ms, slow[], repeated[], components[]  (only when analysis succeeded)
 *   markers        => counts used by the verifier
 *
 * `browser` holds data measured in the administrator's browser, keyed by
 * template key (lcp{url,selector,type}, above_fold_images[], fonts_used[],
 * oversized_images[], unused_css{}, errors[], cls, lcp_ms, fcp_ms).
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Optimization;

use SH\SpeedOptimizer\Compatibility\Rules;
use SH\SpeedOptimizer\Core\Settings;
use SH\SpeedOptimizer\Detection\SiteProfile;

defined( 'ABSPATH' ) || exit;

/**
 * Assessment input bundle.
 */
final class AssessmentContext {

	/**
	 * Site profile.
	 *
	 * @var SiteProfile
	 */
	public SiteProfile $profile;

	/**
	 * Compatibility rules.
	 *
	 * @var Rules
	 */
	public Rules $rules;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	public Settings $settings;

	/**
	 * Server-side page analyses keyed by URL.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $pages;

	/**
	 * Browser analyses keyed by template key.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $browser;

	/**
	 * Constructor.
	 *
	 * @param SiteProfile                       $profile  Profile.
	 * @param Rules                             $rules    Rules.
	 * @param Settings                          $settings Settings.
	 * @param array<string,array<string,mixed>> $pages    Page analyses.
	 * @param array<string,array<string,mixed>> $browser  Browser analyses.
	 */
	public function __construct( SiteProfile $profile, Rules $rules, Settings $settings, array $pages = array(), array $browser = array() ) {
		$this->profile  = $profile;
		$this->rules    = $rules;
		$this->settings = $settings;
		$this->pages    = $pages;
		$this->browser  = $browser;
	}

	/**
	 * Successfully analysed pages.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function pages(): array {
		return array_filter(
			$this->pages,
			static function ( $page ) {
				return is_array( $page ) && 200 === (int) ( $page['status'] ?? 0 );
			}
		);
	}

	/**
	 * Flattened list of an array key across pages (e.g. "scripts", "iframes", "third_party").
	 *
	 * @param string $key Key.
	 * @return array<int,array<string,mixed>>
	 */
	public function collect( string $key ): array {
		$items = array();
		foreach ( $this->pages() as $url => $page ) {
			foreach ( (array) ( $page[ $key ] ?? array() ) as $item ) {
				if ( is_array( $item ) ) {
					$item['_page'] = $url;
					$items[]       = $item;
				}
			}
		}
		return $items;
	}

	/**
	 * Sum of a numeric value across pages, e.g. sum( 'images.missing_dimensions' ).
	 *
	 * @param string $path Dot path inside a page analysis.
	 */
	public function sum( string $path ): int {
		$total = 0;
		foreach ( $this->pages() as $page ) {
			$value = $page;
			foreach ( explode( '.', $path ) as $segment ) {
				$value = is_array( $value ) && isset( $value[ $segment ] ) ? $value[ $segment ] : 0;
			}
			$total += (int) $value;
		}
		return $total;
	}

	/**
	 * Whether any browser measurements exist.
	 */
	public function has_browser_data(): bool {
		return ! empty( $this->browser );
	}
}
