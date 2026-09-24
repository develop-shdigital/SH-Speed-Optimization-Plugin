<?php
/**
 * Speculative prefetching of same-site pages (Speculation Rules API).
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
 * Speculative prefetch.
 */
final class SpeculativePrefetchOptimization extends AbstractOptimization {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'speculative_prefetch';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Prefetch pages before visitors click', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'When a visitor hovers over or starts to tap a link to another page of your site, supporting browsers download that page in advance so it opens almost instantly. Cart, checkout, account and login pages are never prefetched.', 'sh-speed-optimizer' );
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
		return Risk::LEVEL_EXPERIMENTAL;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param AssessmentContext $context Scan data.
	 */
	public function assess( AssessmentContext $context ): Assessment {
		if ( self::core_handles() ) {
			$assessment             = Assessment::not_applicable( __( 'WordPress already prefetches pages.', 'sh-speed-optimizer' ) );
			$assessment->handled_by = 'WordPress';
			return $this->finalize( $assessment, $context );
		}

		$assessment = Assessment::make( true, 70, Assessment::BENEFIT_MEDIUM );
		$assessment->note( __( 'Prefetching adds some extra requests to your server when visitors hover over links.', 'sh-speed-optimizer' ) );
		if ( $context->profile->has_feature( 'woocommerce' ) || $context->profile->has_feature( 'edd' ) ) {
			$assessment->note( __( 'Shop pages such as cart and checkout are excluded automatically.', 'sh-speed-optimizer' ) );
		}

		return $this->finalize( $assessment, $context, 'preload' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Runtime $runtime Runtime.
	 */
	public function register_runtime( Runtime $runtime ): void {
		if ( self::core_handles() ) {
			return;
		}
		$runtime->add_html_transform(
			$this->id(),
			static function ( HtmlDocument $doc ) {
				// Logged-in visitors bypass the page cache: prefetching would only add server load.
				if ( $doc->contains( 'speculationrules' ) || is_user_logged_in() ) {
					return;
				}
				$script = SpeculationRules::to_script(
					SpeculationRules::build(
						(string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ),
						(string) wp_parse_url( site_url( '/' ), PHP_URL_PATH ),
						self::excluded_paths()
					)
				);
				if ( '' !== $script ) {
					$doc->insert_before_body_end( $script );
				}
			},
			90
		);
	}

	/**
	 * Whether WordPress core outputs speculation rules itself (WordPress 6.8+).
	 */
	public static function core_handles(): bool {
		return function_exists( 'wp_get_speculation_rules_configuration' );
	}

	/**
	 * Shop/account paths that are never prefetched.
	 *
	 * @return string[]
	 */
	private static function excluded_paths(): array {
		$urls = array();
		if ( function_exists( 'wc_get_cart_url' ) ) {
			$urls[] = (string) wc_get_cart_url();
		}
		if ( function_exists( 'wc_get_checkout_url' ) ) {
			$urls[] = (string) wc_get_checkout_url();
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$urls[] = (string) wc_get_page_permalink( 'myaccount' );
		}
		if ( function_exists( 'edd_get_checkout_uri' ) ) {
			$urls[] = (string) edd_get_checkout_uri();
		}

		$paths = array();
		foreach ( $urls as $url ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( '' !== $path && '/' !== $path ) {
				$paths[] = $path;
			}
		}

		/**
		 * Filters the URL paths that are never prefetched (each path and everything below it).
		 *
		 * @param string[] $paths Paths such as "/cart/".
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'shso_prefetch_excluded_paths', $paths ) ) ) );
	}
}
