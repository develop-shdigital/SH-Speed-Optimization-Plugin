<?php
/**
 * Stop loading jQuery Migrate on the frontend.
 *
 * The jQuery Migrate script restores jQuery APIs that were removed years ago. Themes and
 * plugins that still use them break without it, and that cannot be detected
 * reliably from the server — so this optimization is experimental, requires a
 * browser check and is never applied automatically. Admin screens, editors
 * and page builders always keep jQuery Migrate.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Modules\WordpressCleanup;

use SH\SpeedOptimizer\Optimization\AbstractOptimization;
use SH\SpeedOptimizer\Optimization\Assessment;
use SH\SpeedOptimizer\Optimization\AssessmentContext;
use SH\SpeedOptimizer\Optimization\Category;
use SH\SpeedOptimizer\Optimization\OptimizationInterface;
use SH\SpeedOptimizer\Optimization\Risk;
use SH\SpeedOptimizer\Optimization\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Removal of jQuery Migrate.
 */
class JqueryMigrateOptimization extends AbstractOptimization {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'remove_jquery_migrate';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return __( 'Remove jQuery Migrate', 'sh-speed-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Stops loading a compatibility script for outdated jQuery code on your pages. Older themes and plugins may stop working without it, so test your site carefully.', 'sh-speed-optimizer' );
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
		return array( OptimizationInterface::REQ_BROWSER );
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_enabled(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param AssessmentContext $context Scan data.
	 */
	public function assess( AssessmentContext $context ): Assessment {
		$pages = $context->pages();
		if ( ! empty( $pages ) ) {
			$loaded = false;
			foreach ( $context->collect( 'scripts' ) as $script ) {
				if ( 'jquery-migrate' === ( $script['handle'] ?? '' ) || false !== strpos( (string) ( $script['src'] ?? '' ), 'jquery-migrate' ) ) {
					$loaded = true;
					break;
				}
			}
			if ( ! $loaded ) {
				return Assessment::not_applicable( __( 'jQuery Migrate is not loaded on your pages.', 'sh-speed-optimizer' ) );
			}
		}

		$assessment = Assessment::make( true, 60, Assessment::BENEFIT_LOW );
		$assessment->note( __( 'Code that still needs jQuery Migrate cannot be detected reliably; only a test in the browser can confirm it is safe.', 'sh-speed-optimizer' ) );

		foreach ( $context->browser as $data ) {
			$messages = wp_json_encode( array( $data['errors'] ?? array(), $data['warnings'] ?? array(), $data['console'] ?? array() ) );
			if ( is_string( $messages ) && false !== stripos( $messages, 'JQMIGRATE' ) ) {
				$assessment->penalize( 40, __( 'The browser reported code that relies on jQuery Migrate.', 'sh-speed-optimizer' ) );
				break;
			}
		}

		$builders = array_diff( array_keys( (array) $context->profile->get( 'builders', array() ) ), array( 'gutenberg', 'classic_editor' ) );
		if ( ! empty( $builders ) ) {
			$assessment->penalize( 15, __( 'Page builders often include older jQuery plugins.', 'sh-speed-optimizer' ) );
		}

		return $this->finalize( $assessment, $context, 'jquery_migrate' );
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

		// Dependencies are resolved when scripts are printed, so changing them here is early enough.
		add_action(
			'wp_enqueue_scripts',
			function () use ( $runtime ) {
				if ( ! $runtime->is_active_on_page( $this->id() ) || $this->plugin->context()->is_editor_preview() ) {
					return;
				}
				if ( ! $this->plugin->context()->is_verification() && is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
					return; // Editors see the unmodified site, like every other optimization.
				}
				$scripts = wp_scripts();
				if ( isset( $scripts->registered['jquery'] ) ) {
					$scripts->registered['jquery']->deps = self::strip_migrate( (array) $scripts->registered['jquery']->deps );
				}
			},
			-1000
		);
	}

	/**
	 * Remove jquery-migrate from a dependency list.
	 *
	 * @param string[] $deps Dependencies.
	 * @return string[]
	 */
	public static function strip_migrate( array $deps ): array {
		return array_values( array_diff( $deps, array( 'jquery-migrate' ) ) );
	}
}
