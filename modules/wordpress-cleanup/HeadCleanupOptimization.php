<?php
/**
 * Remove unnecessary tags from the page head.
 *
 * Removes the generator tag (also from feeds), the RSD and Windows Live Writer
 * links, the shortlink tag and header, and — only when comments are closed
 * site-wide by default — the link to the site-wide comments feed. Canonical
 * URLs, REST API discovery links, resource hints and the main feed links are
 * never touched: plugins and headless setups rely on them.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\WordpressCleanup;

use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Head cleanup.
 */
class HeadCleanupOptimization extends AbstractOptimization {

	/**
	 * Head actions removed: [ hook, callback, priority ].
	 */
	public const REMOVED = array(
		array( 'wp_head', 'wp_generator', 10 ),
		array( 'wp_head', 'rsd_link', 10 ),
		array( 'wp_head', 'wlwmanifest_link', 10 ),
		array( 'wp_head', 'wp_shortlink_wp_head', 10 ),
		array( 'template_redirect', 'wp_shortlink_header', 11 ),
	);

	/**
	 * Head output that must never be removed.
	 */
	public const PROTECTED = array( 'rel_canonical', 'rest_output_link_wp_head', 'wp_resource_hints', 'feed_links' );

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'head_cleanup';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Clean up the page head', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Removes tags from the page header that visitors and search engines do not need, such as the WordPress version and links for old blogging tools.', 'sh-speed-optimizer' );
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
		$hooked = 0;
		foreach ( self::REMOVED as $entry ) {
			if ( $this->hooked( $entry[0], $entry[1] ) ) {
				++$hooked;
			}
		}

		if ( 0 === $hooked ) {
			return Assessment::not_applicable( __( 'These header tags are already removed on this site.', 'sh-speed-optimizer' ) );
		}

		$assessment                    = Assessment::make( true, 98, Assessment::BENEFIT_LOW );
		$assessment->data['removable'] = $hooked;
		$assessment->note( __( 'Only informational tags are removed; canonical, REST API and feed links stay.', 'sh-speed-optimizer' ) );

		return $this->finalize( $assessment, $context, 'head_cleanup' );
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
				if ( ! $runtime->is_active_on_page( $this->id() ) ) {
					return;
				}
				foreach ( self::REMOVED as $entry ) {
					remove_action( $entry[0], $entry[1], $entry[2] );
				}
				add_filter( 'the_generator', '__return_empty_string' );
				if ( self::hide_comments_feed_link( (string) get_option( 'default_comment_status', 'open' ) ) ) {
					add_filter( 'feed_links_show_comments_feed', '__return_false' );
				}
			},
			1
		);
	}

	/**
	 * Whether the site-wide comments feed link is removed: only when new posts do not accept comments.
	 *
	 * @param string $default_comment_status Option `default_comment_status` (open|closed).
	 */
	public static function hide_comments_feed_link( string $default_comment_status ): bool {
		return 'closed' === $default_comment_status;
	}

	/**
	 * Whether a callback is still hooked.
	 *
	 * @param string $hook     Hook.
	 * @param string $callback Callback.
	 */
	protected function hooked( string $hook, string $callback ): bool {
		return false !== has_action( $hook, $callback );
	}
}
