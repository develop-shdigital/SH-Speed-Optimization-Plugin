<?php
/**
 * Remove WordPress' emoji detection script and styles from the frontend.
 *
 * Every modern browser renders emoji natively; the script (about 5 KB inline
 * plus a DNS lookup) only helps very old browsers. The admin area is left
 * untouched.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\Emojis;

use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Disable emojis.
 */
class DisableEmojisOptimization extends AbstractOptimization {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'disable_emojis';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Remove emoji script', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Stops loading the WordPress emoji script and styles on your pages. Browsers show emoji on their own, so visitors see no difference.', 'sh-speed-optimizer' );
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
		if ( ! $this->emoji_script_hooked() ) {
			return Assessment::not_applicable( __( 'The emoji script is already disabled on this site.', 'sh-speed-optimizer' ) );
		}

		$assessment = Assessment::make( true, 99, Assessment::BENEFIT_LOW );
		$assessment->note( __( 'Removes one inline script, one inline style and a DNS lookup from every page.', 'sh-speed-optimizer' ) );

		return $this->finalize( $assessment, $context, 'emojis' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Runtime $runtime Runtime.
	 */
	public function register_runtime( Runtime $runtime ): void {
		// Feeds and e-mails: keep native emoji characters instead of <img> tags pointing to s.w.org.
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

		if ( ! $this->plugin->context()->is_frontend_request() ) {
			return;
		}

		add_action(
			'wp',
			function () use ( $runtime ) {
				if ( ! $runtime->is_active_on_page( $this->id() ) ) {
					return;
				}
				add_filter( 'emoji_svg_url', '__return_false' );
				add_filter( 'wp_resource_hints', array( self::class, 'filter_resource_hints' ), 10, 2 );
				add_filter( 'tiny_mce_plugins', array( self::class, 'filter_tinymce_plugins' ) );
				remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
				remove_action( 'embed_head', 'print_emoji_detection_script' );
				remove_action( 'wp_print_styles', 'print_emoji_styles' );
				remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
				remove_action( 'enqueue_embed_scripts', 'wp_enqueue_emoji_styles' );
			},
			1
		);
	}

	/**
	 * Remove the emoji CDN from resource hints.
	 *
	 * @param array<int,string|array<string,string>> $urls          Hint URLs (strings or attribute arrays).
	 * @param string                                 $relation_type Relation (dns-prefetch, preconnect …).
	 * @return array<int,string|array<string,string>>
	 */
	public static function filter_resource_hints( $urls, $relation_type = '' ): array {
		if ( ! is_array( $urls ) ) {
			return array();
		}
		if ( 'dns-prefetch' !== $relation_type && 'preconnect' !== $relation_type ) {
			return $urls;
		}
		$out = array();
		foreach ( $urls as $url ) {
			$href = is_array( $url ) ? (string) ( $url['href'] ?? '' ) : (string) $url;
			if ( false !== strpos( $href, 's.w.org/images/core/emoji' ) ) {
				continue;
			}
			$out[] = $url;
		}
		return $out;
	}

	/**
	 * Remove the emoji plugin from frontend editors (bbPress, comment editors …).
	 *
	 * @param mixed $plugins TinyMCE plugins.
	 * @return string[]
	 */
	public static function filter_tinymce_plugins( $plugins ): array {
		return is_array( $plugins ) ? array_values( array_diff( $plugins, array( 'wpemoji' ) ) ) : array();
	}

	/**
	 * Whether WordPress still prints the emoji script (another plugin may have removed it already).
	 */
	protected function emoji_script_hooked(): bool {
		return false !== has_action( 'wp_head', 'print_emoji_detection_script' );
	}
}
