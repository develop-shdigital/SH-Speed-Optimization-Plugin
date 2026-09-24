<?php
/**
 * Skip the WordPress embed script where no WordPress embed is shown.
 *
 * Before WordPress 6.4, wp-embed.js was loaded on every page although it is
 * only needed to size embedded posts from other WordPress sites. On single
 * posts and pages whose content contains no such embed, the script is
 * dequeued just before the footer scripts are printed. Archives keep it
 * (their content is not inspected). WordPress 6.4+ already loads the script
 * only when needed, so the optimization is not applicable there.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\Embeds;

use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Embed script.
 */
class EmbedScriptOptimization extends AbstractOptimization {

	/**
	 * First WordPress version that loads wp-embed only when an embed is present.
	 */
	public const CORE_HANDLES_SINCE = '6.4';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'disable_embeds_script';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Load the embed script only when needed', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Stops loading the small WordPress embed script on posts and pages that do not show embedded posts from other WordPress sites.', 'sh-speed-optimizer' );
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
		$version = (string) $context->profile->get( 'wp.version', '' );
		if ( '' === $version ) {
			$version = (string) get_bloginfo( 'version' );
		}

		if ( self::core_handles_it( $version ) ) {
			return Assessment::not_applicable( __( 'WordPress already loads the embed script only when it is needed.', 'sh-speed-optimizer' ) );
		}

		$assessment = Assessment::make( true, 97, Assessment::BENEFIT_LOW );
		$assessment->note( __( 'The script is kept on archives and on any page whose content might contain an embedded post.', 'sh-speed-optimizer' ) );

		return $this->finalize( $assessment, $context, 'embeds' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Runtime $runtime Runtime.
	 */
	public function register_runtime( Runtime $runtime ): void {
		if ( ! $this->plugin->context()->is_frontend_request() || self::core_handles_it( (string) get_bloginfo( 'version' ) ) ) {
			return;
		}

		add_action(
			'wp_footer',
			function () use ( $runtime ) {
				if ( ! is_singular() || ! $runtime->is_active_on_page( $this->id() ) ) {
					return;
				}
				$post = get_queried_object();
				if ( ! $post instanceof \WP_Post ) {
					return;
				}
				$content = (string) $post->post_content;
				if ( ! self::content_may_embed( $content ) && ! ( function_exists( 'has_block' ) && has_block( 'core/embed', $post ) ) ) {
					wp_dequeue_script( 'wp-embed' );
				}
			},
			1
		);
	}

	/**
	 * Whether this WordPress version already loads wp-embed only when needed.
	 *
	 * @param string $version WordPress version.
	 */
	public static function core_handles_it( string $version ): bool {
		return '' !== $version && version_compare( $version, self::CORE_HANDLES_SINCE, '>=' );
	}

	/**
	 * Whether post content might produce an embedded WordPress post (conservative: any doubt means yes).
	 *
	 * Bare URLs on their own line and [embed] shortcodes become embeds when the content is rendered, and
	 * the target could be another WordPress site.
	 *
	 * @param string $content Raw post content.
	 */
	public static function content_may_embed( string $content ): bool {
		if ( '' === trim( $content ) ) {
			return false;
		}
		foreach ( array( 'wp-embedded-content', 'wp:embed', 'wp-block-embed', '[embed', 'oembed' ) as $marker ) {
			if ( false !== stripos( $content, $marker ) ) {
				return true;
			}
		}
		// A URL alone on a line (or in its own paragraph) is auto-embedded by WordPress.
		return (bool) preg_match( '#^\s*(?:<p>\s*)?https?://[^\s<>"]+\s*(?:</p>)?\s*$#im', $content );
	}
}
