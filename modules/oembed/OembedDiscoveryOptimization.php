<?php
/**
 * Remove the oEmbed discovery links from the page head.
 *
 * The links only help other websites embed your posts as preview cards. The
 * oEmbed REST endpoint stays available, and embedding other sites' content
 * (YouTube, other WordPress sites …) in your own posts keeps working.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\Oembed;

use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Discovery links for oEmbed.
 */
class OembedDiscoveryOptimization extends AbstractOptimization {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'disable_oembed_discovery';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Remove embed discovery links', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Removes two header links that other websites use to show your posts as preview cards. Embedding videos and other content in your own posts keeps working.', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::CLEANUP;
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
	public function safe_mode_compatible(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param AssessmentContext $context Scan data.
	 */
	public function assess( AssessmentContext $context ): Assessment {
		if ( ! $this->discovery_hooked() ) {
			return Assessment::not_applicable( __( 'The embed discovery links are already removed on this site.', 'sh-speed-optimizer' ) );
		}

		$assessment = Assessment::make( true, 99, Assessment::BENEFIT_LOW );
		$assessment->note( __( 'Links to your posts on other WordPress sites appear as plain links instead of preview cards.', 'sh-speed-optimizer' ) );

		return $this->finalize( $assessment, $context, 'embeds' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Runtime $runtime Runtime.
	 */
	public function register_runtime( Runtime $runtime ): void {
		if ( ! $this->plugin->context()->is_frontend_request() ) {
			return;
		}

		add_action(
			'wp',
			function () use ( $runtime ) {
				if ( $runtime->is_active_on_page( $this->id() ) ) {
					remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
				}
			},
			1
		);
	}

	/**
	 * Whether WordPress still prints the discovery links.
	 */
	protected function discovery_hooked(): bool {
		return false !== has_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	}
}
